<?php

declare(strict_types=1);

use App\Modules\Catalog\Services\ProductLookupService;
use App\Modules\Fiscal\DTO\DestinatarioNfe;
use App\Modules\Fiscal\DTO\EmitenteNfe;
use App\Modules\Fiscal\Exceptions\EmissaoInvalida;
use App\Modules\Fiscal\Models\FiscalSeries;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\TaxProfile;
use App\Modules\Fiscal\Services\Gateways\CanalSefaz;
use App\Modules\Fiscal\Services\Gateways\MontarXmlNfe;
use App\Modules\Fiscal\Services\Gateways\SpedNfeGateway;
use App\Modules\Sales\DTO\OrderInvoiceAddress;
use App\Modules\Sales\DTO\OrderInvoiceItem;
use App\Modules\Sales\DTO\OrderInvoiceSnapshot;
use App\Modules\Sales\Models\Order;
use App\Modules\Sales\Services\BuildOrderInvoiceSnapshot;
use NFePHP\Common\Validator;

/*
|--------------------------------------------------------------------------
| Emissão real (sped-nfe) — docs/14 §3, ADR-0025
|--------------------------------------------------------------------------
|
| **O que estes testes provam, e o que não provam.**
|
| Provam: que o XML montado a partir de um pedido do ERP é uma NF-e 4.00
| válida perante o XSD oficial que a própria `sped-nfe` distribui; que os
| valores fecham (o rateio de frete/desconto não perde centavo); que a
| regra de homologação da BR-605 é aplicada; e que cada dado fiscal
| ausente vira uma pendência nomeada em vez de um valor inventado.
|
| Provam também, com um certificado **autoassinado gerado no próprio
| teste**, que o XML assina e que o resultado assinado passa no XSD — que
| é a mesma validação que a `Tools::signNFe()` roda antes de transmitir.
| Um autoassinado não vale nada perante a SEFAZ, mas para exercitar
| assinatura XMLDSig e schema ele serve exatamente como o de verdade.
|
| **Não provam** nada sobre a SEFAZ: `sefazEnviaLote` e a leitura do
| `retEnviNFe` estão escritos e revisados, mas só se verificam com o `.pfx`
| real, na bateria de homologação que a BR-605 exige. Nenhum teste aqui
| deve dar a impressão contrária — é por isso que não existe dublê de
| `Tools` devolvendo um `retEnviNFe` autorizado de mentira: um verde falso
| nesse ponto seria pior que a ausência de teste.
|
*/

/**
 * O XSD oficial do layout 4.00, distribuído com a própria biblioteca —
 * `PL_010_V1.30`, o mesmo pacote de `CanalSefaz::SCHEMAS` (ADR-0027).
 */
function xsdDaNfe(): string
{
    return base_path('vendor/nfephp-org/sped-nfe/schemes/PL_010_V1.30/nfe_v4.00.xsd');
}

/**
 * Um A1 autoassinado, gerado na hora e apontado pelo `.env` de teste.
 *
 * Não substitui o certificado de verdade para nada que envolva a SEFAZ —
 * a cadeia ICP-Brasil não existe aqui. Substitui, sim, para o que este
 * teste precisa: uma chave privada com que assinar e um `Certificate` que
 * a `Tools` aceite. O arquivo morre com o processo (`sys_get_temp_dir`) e
 * nunca encosta no repositório.
 */
function certificadoAutoassinado(): string
{
    $opcoes = [
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $chave = openssl_pkey_new($opcoes);
    expect($chave)->not->toBeFalse();

    $requisicao = openssl_csr_new([
        'countryName' => 'BR',
        'stateOrProvinceName' => 'RS',
        'organizationName' => 'DONA ARTEIRA TESTE',
        'commonName' => 'DONA ARTEIRA TESTE:12345678000195',
    ], $chave, $opcoes);

    $certificado = openssl_csr_sign($requisicao, null, $chave, 30, $opcoes);
    $pfx = '';
    openssl_pkcs12_export($certificado, $pfx, $chave, 'segredo-de-teste');

    $arquivo = tempnam(sys_get_temp_dir(), 'a1-teste-');
    file_put_contents($arquivo, $pfx);

    config()->set('fiscal.certificado.path', $arquivo);
    config()->set('fiscal.certificado.password', 'segredo-de-teste');

    return $arquivo;
}

/** O `.env` do emitente inteiro preenchido — nenhum destes tem default. */
function configurarEmitente(array $sobrescritas = []): void
{
    config()->set(array_merge([
        'fiscal.nfe.uf_emitente' => 'RS',
        'fiscal.nfe.environment' => 'homologacao',
        'fiscal.emitente.cnpj' => '12.345.678/0001-95',
        'fiscal.emitente.razao_social' => 'DONA ARTEIRA ARTESANATO LTDA',
        'fiscal.emitente.nome_fantasia' => 'Dona Arteira',
        'fiscal.emitente.ie' => '1234567890',
        'fiscal.emitente.crt' => '1',
        'fiscal.emitente.endereco.logradouro' => 'Rua das Flores',
        'fiscal.emitente.endereco.numero' => '100',
        'fiscal.emitente.endereco.bairro' => 'Centro',
        'fiscal.emitente.endereco.municipio' => 'Porto Alegre',
        'fiscal.emitente.endereco.codigo_municipio' => '4314902',
        'fiscal.emitente.endereco.cep' => '90010-000',
        'fiscal.tributos.pis_cst' => '49',
        'fiscal.tributos.cofins_cst' => '49',
    ], $sobrescritas));
}

/**
 * Um retrato de pedido pronto para faturar.
 *
 * Montado à mão, e não pelo `BuildOrderInvoiceSnapshot`: aqui o objeto de
 * teste é o XML, e depender do caminho inteiro de Vendas faria uma falha
 * de cadastro parecer uma falha de montagem.
 *
 * @param  list<OrderInvoiceItem>  $itens
 */
function retratoDoPedido(
    array $itens = [],
    string $shipping = '0.00',
    string $discount = '0.00',
    ?string $codigoIbge = '4314902',
    string $uf = 'RS',
    ?string $inscricaoEstadual = null,
    ?string $documento = '52998224725',
): OrderInvoiceSnapshot {
    $itens = $itens === [] ? [linhaDoPedido()] : $itens;

    $subtotal = array_reduce(
        $itens,
        static fn (string $soma, OrderInvoiceItem $i): string => bcadd($soma, $i->lineTotal, 2),
        '0.00',
    );

    return new OrderInvoiceSnapshot(
        orderId: 1,
        orderPublicId: '01JCXY0000000000000000000',
        orderNumber: 42,
        channel: 'woocommerce',
        customerId: 7,
        customerName: 'Maria da Silva',
        customerType: $inscricaoEstadual === null ? 'individual' : 'company',
        customerDocument: $documento,
        customerStateRegistration: $inscricaoEstadual,
        customerEmail: 'maria@example.com',
        shippingAddress: new OrderInvoiceAddress(
            zip: '91000-000',
            street: 'Avenida Ipiranga',
            number: '500',
            complement: 'apto 21',
            district: 'Azenha',
            city: 'Porto Alegre',
            cityCode: $codigoIbge,
            state: $uf,
        ),
        items: $itens,
        subtotal: $subtotal,
        discount: $discount,
        shipping: $shipping,
        total: bcsub(bcadd($subtotal, $shipping, 2), $discount, 2),
    );
}

function linhaDoPedido(
    string $qty = '2.000',
    string $unitPrice = '100.00',
    string $discount = '0.00',
    string $sku = 'DA-0001',
): OrderInvoiceItem {
    return new OrderInvoiceItem(
        productId: 1,
        sku: $sku,
        name: 'Coelho de gesso pintado a mao',
        unit: 'UN',
        qty: $qty,
        unitPrice: $unitPrice,
        discount: $discount,
        lineTotal: bcsub(bcmul($qty, $unitPrice, 2), $discount, 2),
        ncm: '39264000',
        cest: null,
        origin: '0',
        gtin: null,
    );
}

/** Uma nota já numerada, do jeito que o `BuildInvoiceFromOrder` a deixa. */
function notaNumerada(int $numero = 1): Invoice
{
    $pedido = Order::factory()->create();
    $serie = FiscalSeries::factory()->create();
    $perfil = TaxProfile::factory()->create();

    return Invoice::factory()->create([
        'order_id' => $pedido->id,
        'series_id' => $serie->id,
        'tax_profile_id' => $perfil->id,
        'number' => $numero,
        'total' => '200.00',
    ]);
}

function montarXml(Invoice $nota, OrderInvoiceSnapshot $pedido, int $tpAmb = 2, ?TaxProfile $perfil = null): string
{
    return app(MontarXmlNfe::class)->handle(
        nota: $nota,
        pedido: $pedido,
        emitente: EmitenteNfe::doConfig(),
        destinatario: DestinatarioNfe::doPedido(
            $pedido,
            (string) $pedido->shippingAddress?->cityCode,
        ),
        perfil: $perfil ?? TaxProfile::factory()->make(),
        naturezaDaOperacao: 'VENDA',
        tpAmb: $tpAmb,
    );
}

// -------------------------------------------------- montagem do XML

describe('montagem do XML', function () {
    beforeEach(fn () => configurarEmitente());

    it('produz uma NF-e 4.00 que, assinada, passa no XSD oficial da SEFAZ', function () {
        // O teste mais forte possível sem um A1 de verdade: é exatamente
        // esta validação que a SEFAZ repete antes de autorizar (rejeição
        // 215 — "falha no schema"). O XSD só aceita a nota **assinada**,
        // então a assinatura entra junto, com certificado autoassinado.
        $arquivo = certificadoAutoassinado();

        $xml = montarXml(notaNumerada(), retratoDoPedido());
        $assinado = app(CanalSefaz::class)->abrir(EmitenteNfe::doConfig(), 2)->signNFe($xml);

        expect(Validator::isValid($assinado, xsdDaNfe()))->toBeTrue()
            ->and($assinado)->toContain('<Signature')
            ->and($assinado)->toContain('<DigestValue>');

        unlink($arquivo);
    });

    it('escreve chave de acesso de 44 dígitos coerente com o Id', function () {
        $xml = montarXml(notaNumerada(numero: 7), retratoDoPedido());

        $nfe = new SimpleXMLElement($xml);
        $id = (string) $nfe->infNFe['Id'];
        $chave = substr($id, 3);

        expect($id)->toStartWith('NFe')
            ->and($chave)->toHaveLength(44)
            ->and($chave)->toMatch('/^\d{44}$/')
            // Posições 25–34 da chave são modelo, série e número.
            ->and(substr($chave, 20, 2))->toBe('55')
            ->and(substr($chave, 22, 3))->toBe('001')
            ->and(substr($chave, 25, 9))->toBe('000000007');
    });

    it('usa a razão social de homologação exigida pela BR-605', function () {
        $xml = montarXml(notaNumerada(), retratoDoPedido(), tpAmb: 2);
        $nfe = new SimpleXMLElement($xml);

        expect((string) $nfe->infNFe->ide->tpAmb)->toBe('2')
            ->and((string) $nfe->infNFe->dest->xNome)->toBe(MontarXmlNfe::DEST_HOMOLOGACAO)
            // O nome do cliente de verdade não vaza para o ambiente de teste.
            ->and($xml)->not->toContain('Maria da Silva');
    });

    it('leva o CFOP e o CSOSN do perfil fiscal para dentro do item', function () {
        $xml = montarXml(notaNumerada(), retratoDoPedido());
        $nfe = new SimpleXMLElement($xml);

        expect((string) $nfe->infNFe->det->prod->CFOP)->toBe('5101')
            ->and((string) $nfe->infNFe->det->imposto->ICMS->ICMSSN102->CSOSN)->toBe('102')
            ->and((string) $nfe->infNFe->det->prod->NCM)->toBe('39264000')
            // Sem código de barras no catálogo migrado do Woo (pasta 32).
            ->and((string) $nfe->infNFe->det->prod->cEAN)->toBe('SEM GTIN');
    });

    // -------------------------------------------------- grupo IBS/CBS (BR-608, ADR-0027)

    it('omite o grupo IBSCBS quando o perfil nao tem os 5 campos configurados', function () {
        // TaxProfile::factory() já nasce sem os campos de IBS/CBS (nulos
        // por padrão) — é o estado de hoje, com Simples Nacional isento
        // até 04/01/2027.
        $xml = montarXml(notaNumerada(), retratoDoPedido());
        $nfe = new SimpleXMLElement($xml);

        expect($xml)->not->toContain('<IBSCBS>')
            ->and(isset($nfe->infNFe->det->imposto->IBSCBS))->toBeFalse();
    });

    it('monta o grupo IBSCBS quando os 5 campos do perfil estao configurados, e o XML passa no XSD', function () {
        $arquivo = certificadoAutoassinado();

        $perfil = TaxProfile::factory()->make([
            'ibscbs_cst' => '000',
            'ibscbs_cclasstrib' => '000001',
            'ibs_uf_aliquota' => '0.1000',
            'ibs_mun_aliquota' => '0.0500',
            'cbs_aliquota' => '0.9000',
        ]);

        $xml = montarXml(notaNumerada(), retratoDoPedido(), perfil: $perfil);
        $assinado = app(CanalSefaz::class)->abrir(EmitenteNfe::doConfig(), 2)->signNFe($xml);
        $nfe = new SimpleXMLElement($xml);
        $ibscbs = $nfe->infNFe->det->imposto->IBSCBS;

        expect(Validator::isValid($assinado, xsdDaNfe()))->toBeTrue()
            ->and((string) $ibscbs->CST)->toBe('000')
            ->and((string) $ibscbs->cClassTrib)->toBe('000001')
            // vBC do item é 200.00 (2 × 100.00); 0,1% + 0,05% + 0,9% das
            // alíquotas de teste sobre essa base — bcmath, sem float.
            ->and((string) $ibscbs->gIBSCBS->gIBSUF->vIBSUF)->toBe('0.20')
            ->and((string) $ibscbs->gIBSCBS->gIBSMun->vIBSMun)->toBe('0.10')
            ->and((string) $ibscbs->gIBSCBS->gCBS->vCBS)->toBe('1.80')
            ->and((string) $ibscbs->gIBSCBS->vIBS)->toBe('0.30');
    });

    it('trata configuracao parcial de IBS/CBS como ausente — nunca um grupo pela metade', function () {
        // Só o CST, sem alíquota nem cClassTrib: exatamente o cadastro
        // incompleto que `ibscbsConfigurado()` existe para recusar.
        $perfil = TaxProfile::factory()->make(['ibscbs_cst' => '000']);

        $xml = montarXml(notaNumerada(), retratoDoPedido(), perfil: $perfil);

        expect($xml)->not->toContain('<IBSCBS>');
    });

    it('fecha o total da nota com o total do pedido mesmo com frete e desconto', function () {
        // Três itens e um frete que não divide certo: é aqui que um rateio
        // em float perderia o centavo e a SEFAZ devolveria rejeição 531.
        $pedido = retratoDoPedido(
            itens: [linhaDoPedido(qty: '1.000'), linhaDoPedido(qty: '1.000'), linhaDoPedido(qty: '1.000')],
            shipping: '10.00',
            discount: '7.77',
        );

        $arquivo = certificadoAutoassinado();

        $xml = montarXml(notaNumerada(), $pedido);
        $nfe = new SimpleXMLElement($xml);
        $total = $nfe->infNFe->total->ICMSTot;

        expect((string) $total->vNF)->toBe($pedido->total)
            ->and((string) $total->vProd)->toBe('300.00')
            ->and((string) $total->vFrete)->toBe('10.00')
            ->and((string) $total->vDesc)->toBe('7.77');

        $assinado = app(CanalSefaz::class)->abrir(EmitenteNfe::doConfig(), 2)->signNFe($xml);
        expect(Validator::isValid($assinado, xsdDaNfe()))->toBeTrue();

        unlink($arquivo);
    });

    it('marca operação interestadual quando a entrega sai do estado', function () {
        $xml = montarXml(notaNumerada(), retratoDoPedido(uf: 'SP'));
        $nfe = new SimpleXMLElement($xml);

        // idDest 2 tem de concordar com o CFOP 6xxx que o perfil traria —
        // divergir entre os dois é a rejeição que a matriz de perfis existe
        // para evitar (docs/13 §3).
        expect((string) $nfe->infNFe->ide->idDest)->toBe('2');
    });

    it('trata pessoa jurídica com inscrição estadual como contribuinte', function () {
        $xml = montarXml(
            notaNumerada(),
            retratoDoPedido(inscricaoEstadual: '9876543210', documento: '11222333000181'),
        );

        $nfe = new SimpleXMLElement($xml);

        expect((string) $nfe->infNFe->dest->indIEDest)->toBe('1')
            ->and((string) $nfe->infNFe->dest->IE)->toBe('9876543210')
            ->and((string) $nfe->infNFe->dest->CNPJ)->toBe('11222333000181');
    });
});

// -------------------------------------------------- pendências, não chutes

describe('dado fiscal ausente', function () {
    it('recusa a montagem dizendo QUAIS variáveis do emitente faltam', function () {
        // O estado inicial de qualquer ambiente: `.env` fiscal em branco,
        // porque a doc 13 está bloqueada no contador.
        expect(fn () => EmitenteNfe::doConfig())
            ->toThrow(EmissaoInvalida::class);

        try {
            EmitenteNfe::doConfig();
        } catch (EmissaoInvalida $e) {
            expect($e->getMessage())
                ->toContain('NFE_EMITENTE_CNPJ')
                ->toContain('NFE_EMITENTE_CRT')
                ->toContain('NFE_EMITENTE_COD_MUNICIPIO')
                ->toContain('NFE_UF_EMITENTE');
        }
    });

    it('recusa regime que o cadastro de perfis não sabe representar', function () {
        // CRT 3 (Regime Normal) precisaria de CST de ICMS e alíquotas;
        // `tax_profiles` só guarda CSOSN. Recusar é o certo — o contrário
        // seria emitir com tributação inventada.
        configurarEmitente(['fiscal.emitente.crt' => '3']);

        expect(fn () => montarXml(notaNumerada(), retratoDoPedido()))
            ->toThrow(EmissaoInvalida::class, 'CRT=3');
    });

    it('recusa a montagem sem os CST de PIS e COFINS', function () {
        configurarEmitente([
            'fiscal.tributos.pis_cst' => null,
            'fiscal.tributos.cofins_cst' => null,
        ]);

        expect(fn () => montarXml(notaNumerada(), retratoDoPedido()))
            ->toThrow(EmissaoInvalida::class, 'NFE_PIS_CST');
    });

    it('recusa a montagem de nota sem número reservado', function () {
        configurarEmitente();

        $nota = notaNumerada();
        $nota->number = null;

        expect(fn () => montarXml($nota, retratoDoPedido()))
            ->toThrow(EmissaoInvalida::class, 'sem número');
    });

    it('recusa PJ sem inscrição estadual em vez de adivinhar o indIEDest', function () {
        configurarEmitente();

        expect(fn () => montarXml(notaNumerada(), retratoDoPedido(documento: '11222333000181')))
            ->toThrow(EmissaoInvalida::class, 'inscrição estadual');
    });
});

// -------------------------------------------------- o gateway

describe('SpedNfeGateway', function () {
    /** O gateway com um retrato de pedido pronto, sem passar por Vendas. */
    function gatewayCom(OrderInvoiceSnapshot $pedido): SpedNfeGateway
    {
        $pedidos = new class(app(ProductLookupService::class)) extends BuildOrderInvoiceSnapshot
        {
            public ?OrderInvoiceSnapshot $retrato = null;

            public function handle(int $orderId): ?OrderInvoiceSnapshot
            {
                return $this->retrato;
            }
        };

        $pedidos->retrato = $pedido;

        return new SpedNfeGateway($pedidos, app(MontarXmlNfe::class), app(CanalSefaz::class));
    }

    it('recusa ambiente que não seja homologacao ou producao', function () {
        configurarEmitente(['fiscal.nfe.environment' => 'produção']);

        expect(fn () => gatewayCom(retratoDoPedido())->transmitir(notaNumerada()))
            ->toThrow(EmissaoInvalida::class, 'NFE_ENVIRONMENT');
    });

    it('recusa a transmissão sem certificado configurado', function () {
        configurarEmitente();
        config()->set('fiscal.certificado.path', null);
        config()->set('fiscal.certificado.password', null);

        expect(fn () => gatewayCom(retratoDoPedido())->transmitir(notaNumerada()))
            ->toThrow(EmissaoInvalida::class, 'NFE_CERT_PATH');
    });

    it('recusa a transmissão quando o arquivo do certificado não existe', function () {
        configurarEmitente();
        config()->set('fiscal.certificado.path', storage_path('nao-existe/certificado.pfx'));
        config()->set('fiscal.certificado.password', 'segredo');

        expect(fn () => gatewayCom(retratoDoPedido())->transmitir(notaNumerada()))
            ->toThrow(EmissaoInvalida::class, 'não foi encontrado');
    });

    it('reclama do emitente antes de olhar o pedido', function () {
        // Ordem importa: quem opera não deve corrigir o cadastro de um
        // cliente para só então descobrir que falta o CNPJ da empresa.
        config()->set('fiscal.nfe.environment', 'homologacao');

        expect(fn () => gatewayCom(retratoDoPedido())->transmitir(notaNumerada()))
            ->toThrow(EmissaoInvalida::class, 'dados do emitente');
    });

    it('para na falta do código IBGE do município de entrega', function () {
        // A lacuna real do cadastro hoje: `customer_addresses` não guarda o
        // código, e `enderDest/cMun` é obrigatório. Aproximar pelo nome da
        // cidade autorizaria nota com município errado — e ninguém veria.
        configurarEmitente();

        $certificado = tempnam(sys_get_temp_dir(), 'pfx');
        config()->set('fiscal.certificado.path', $certificado);
        config()->set('fiscal.certificado.password', 'segredo');

        expect(fn () => gatewayCom(retratoDoPedido(codigoIbge: null))->transmitir(notaNumerada()))
            ->toThrow(EmissaoInvalida::class, 'código IBGE');

        unlink($certificado);
    });
});
