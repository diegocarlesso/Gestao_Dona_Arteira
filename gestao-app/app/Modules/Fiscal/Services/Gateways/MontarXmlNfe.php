<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Services\Gateways;

use App\Modules\Fiscal\DTO\DestinatarioNfe;
use App\Modules\Fiscal\DTO\EmitenteNfe;
use App\Modules\Fiscal\Exceptions\EmissaoInvalida;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Sales\DTO\OrderInvoiceItem;
use App\Modules\Sales\DTO\OrderInvoiceSnapshot;
use App\Modules\Sales\Enums\OrderChannel;
use DateTimeImmutable;
use DateTimeZone;
use DOMException;
use NFePHP\Common\Keys;
use NFePHP\Common\TimeZoneByUF;
use NFePHP\Common\UFList;
use NFePHP\NFe\Make;
use RuntimeException;
use stdClass;

/**
 * Monta o XML da NF-e (modelo 55, layout 4.00) — docs/14 §3.
 *
 * Separada do `SpedNfeGateway` de propósito: **esta metade não precisa de
 * certificado**. Montagem é dado entrando em ordem; assinatura e SOAP são
 * infraestrutura. Juntas numa classe só, nada da emissão seria testável
 * antes de o `.pfx` existir — e o `.pfx` é a peça que mais demora a
 * chegar. Separadas, o XML inteiro se valida contra o XSD oficial que a
 * própria `sped-nfe` distribui, no CI, sem segredo nenhum.
 *
 * Recebe tudo pronto e **não** vai buscar nada: emitente e destinatário
 * chegam como DTO já validado, o perfil fiscal já foi resolvido, o número
 * já foi reservado. Se falta alguma coisa, quem falha é quem chama — aqui
 * dentro não há decisão fiscal a tomar.
 */
class MontarXmlNfe
{
    /** Identificação do emissor no XML (`verProc`) — não é dado fiscal. */
    private const VERSAO_EMISSOR = 'DonaArteiraERP 1.0';

    /** Layout em uso. `Tools` só conhece esta versão (PL_009_V4). */
    private const VERSAO_LAYOUT = '4.00';

    private const MODELO_NFE = '55';

    /** Emissão normal — contingência (SVC) é chaveamento manual, docs/14 §3. */
    private const TP_EMIS_NORMAL = 1;

    /**
     * Razão social obrigatória do destinatário em homologação (BR-605).
     *
     * Não é enfeite: a SEFAZ rejeita (610) a nota de teste que não traga
     * exatamente este texto. É também a única proteção real contra alguém
     * mandar o nome de um cliente de verdade para o ambiente de teste.
     */
    public const DEST_HOMOLOGACAO = 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';

    /**
     * @throws EmissaoInvalida
     */
    public function handle(
        Invoice $nota,
        OrderInvoiceSnapshot $pedido,
        EmitenteNfe $emitente,
        DestinatarioNfe $destinatario,
        string $cfop,
        string $csosn,
        string $naturezaDaOperacao,
        int $tpAmb,
    ): string {
        if ($nota->number === null || $nota->series === null) {
            throw EmissaoInvalida::notaSemNumeracao();
        }

        if (! $emitente->simplesNacional()) {
            throw EmissaoInvalida::regimeSemSuporte($emitente->crt);
        }

        $pisCst = $this->cstConfigurado('fiscal.tributos.pis_cst');
        $cofinsCst = $this->cstConfigurado('fiscal.tributos.cofins_cst');

        $make = new Make;

        $cUF = UFList::getCodeByUF($emitente->uf);
        $emissao = new DateTimeImmutable('now', new DateTimeZone(TimeZoneByUF::get($emitente->uf)));

        // O `cNF` é sorteado **antes** de qualquer tag porque ele entra na
        // chave de acesso e na `ide`. Deixar a lib sortear dentro do
        // `tagide` faria a chave calculada aqui divergir da que está no
        // XML — a nota sairia com Id e conteúdo incoerentes.
        $cNF = Keys::random((string) $nota->number);

        $chave = Keys::build(
            // `getCodeByUF` devolve inteiro; `Keys::build` monta a chave
            // formatando strings. A conversão é aqui, e não implícita.
            (string) $cUF,
            $emissao->format('y'),
            $emissao->format('m'),
            $emitente->cnpj,
            self::MODELO_NFE,
            $nota->series->series,
            (string) $nota->number,
            (string) self::TP_EMIS_NORMAL,
            $cNF,
        );

        $make->taginfNFe($this->std([
            'Id' => 'NFe'.$chave,
            'versao' => self::VERSAO_LAYOUT,
        ]));

        $make->tagide($this->std([
            'cUF' => $cUF,
            'cNF' => $cNF,
            'natOp' => $naturezaDaOperacao,
            'mod' => self::MODELO_NFE,
            'serie' => $nota->series->series,
            'nNF' => $nota->number,
            'dhEmi' => $emissao->format('Y-m-d\TH:i:sP'),
            'tpNF' => 1,                                  // saída
            'idDest' => $this->idDestino($emitente, $destinatario),
            'cMunFG' => $emitente->codigoMunicipio,
            'tpImp' => 1,                                 // DANFE retrato
            'tpEmis' => self::TP_EMIS_NORMAL,
            'cDV' => substr($chave, -1),
            'tpAmb' => $tpAmb,
            'finNFe' => 1,                                // NF-e normal
            // Consumidor final quando o destinatário não é contribuinte —
            // que é o caso de toda venda do site a pessoa física.
            'indFinal' => $destinatario->indIEDest === DestinatarioNfe::IE_NAO_CONTRIBUINTE ? 1 : 0,
            'indPres' => $this->indPresenca($pedido),
            'procEmi' => 0,                               // aplicativo do contribuinte
            'verProc' => self::VERSAO_EMISSOR,
        ]));

        $this->emitente($make, $emitente);
        $this->destinatario($make, $destinatario, $tpAmb);
        $this->itens($make, $pedido, $cfop, $csosn, $pisCst, $cofinsCst);

        // `total` não é informado: com o método de cálculo padrão (V2) a
        // própria lib soma vProd/vDesc/vFrete dos itens e monta o ICMSTot.
        // Informar à mão seria manter duas contas do mesmo dinheiro e
        // descobrir a divergência pela rejeição 531.

        // Frete por conta do remetente: a Dona Arteira cobra o frete no
        // pedido e contrata o transporte. Não é dado tributário — é como a
        // operação funciona (docs/10).
        $make->tagtransp($this->std(['modFrete' => 0]));

        $make->tagpag($this->std([]));
        $make->tagdetPag($this->std([
            // `99 = Outros` com a descrição: o snapshot do pedido não traz
            // a forma de pagamento (o Woo cobra antes de o pedido chegar
            // aqui), e chutar "cartão de crédito" seria inventar um fato.
            'tPag' => '99',
            'xPag' => 'Pagamento processado no canal de venda',
            'vPag' => $pedido->total,
        ]));

        $this->responsavelTecnico($make);

        // A lib acumula pendências em vez de estourar na primeira — mas
        // desiste com exceção quando falta uma tag estrutural. Os dois
        // caminhos viram a mesma pendência de cadastro: montar não é
        // transmitir, e nada disso melhora tentando de novo.
        try {
            $xml = $make->getXML();
        } catch (DOMException|RuntimeException $e) {
            throw EmissaoInvalida::xmlIncompleto([...$this->erros($make), $e->getMessage()]);
        }

        $erros = $this->erros($make);

        if ($erros !== []) {
            throw EmissaoInvalida::xmlIncompleto($erros);
        }

        return $xml;
    }

    /**
     * @return list<string>
     */
    private function erros(Make $make): array
    {
        /** @var list<string> $erros */
        $erros = array_values($make->getErrors());

        return $erros;
    }

    private function emitente(Make $make, EmitenteNfe $emitente): void
    {
        $make->tagEmit($this->std([
            'CNPJ' => $emitente->cnpj,
            'xNome' => $emitente->razaoSocial,
            'xFant' => $emitente->nomeFantasia,
            'IE' => $emitente->ie,
            'CRT' => $emitente->crt,
        ]));

        $make->tagenderEmit($this->std([
            'xLgr' => $emitente->logradouro,
            'nro' => $emitente->numero,
            'xBairro' => $emitente->bairro,
            'cMun' => $emitente->codigoMunicipio,
            'xMun' => $emitente->municipio,
            'UF' => $emitente->uf,
            'CEP' => $emitente->cep,
            'cPais' => '1058',
            'xPais' => 'BRASIL',
        ]));
    }

    private function destinatario(Make $make, DestinatarioNfe $destinatario, int $tpAmb): void
    {
        $documento = $destinatario->pessoaFisica()
            ? ['CPF' => $destinatario->documento]
            : ['CNPJ' => $destinatario->documento];

        $make->tagdest($this->std([
            ...$documento,
            // Em homologação o nome é fixo por exigência da SEFAZ (BR-605),
            // e de quebra o nome do cliente real não vai para o ambiente
            // de teste.
            'xNome' => $tpAmb === 2 ? self::DEST_HOMOLOGACAO : $destinatario->nome,
            'indIEDest' => $destinatario->indIEDest,
            'IE' => $destinatario->indIEDest === DestinatarioNfe::IE_CONTRIBUINTE
                ? $destinatario->inscricaoEstadual
                : null,
            'email' => $destinatario->email,
        ]));

        $make->tagenderDest($this->std([
            'xLgr' => $destinatario->logradouro,
            'nro' => $destinatario->numero,
            'xCpl' => $destinatario->complemento,
            'xBairro' => $destinatario->bairro,
            'cMun' => $destinatario->codigoMunicipio,
            'xMun' => $destinatario->municipio,
            'UF' => $destinatario->uf,
            'CEP' => $destinatario->cep,
            'cPais' => '1058',
            'xPais' => 'BRASIL',
        ]));
    }

    /**
     * As linhas da nota, com o rateio de frete e desconto do pedido.
     *
     * A NF-e não tem campo de frete nem de desconto no total: os dois
     * existem **por item** e o total é a soma. Então o frete e o desconto
     * que o pedido guarda no cabeçalho precisam ser distribuídos, e o
     * resíduo dos centavos vai para a última linha — sem isso o `vNF`
     * calculado pela SEFAZ difere do total do pedido em um centavo, o que
     * vira rejeição 531 e não "quase certo".
     */
    private function itens(
        Make $make,
        OrderInvoiceSnapshot $pedido,
        string $cfop,
        string $csosn,
        string $pisCst,
        string $cofinsCst,
    ): void {
        $bases = array_map(
            static fn (OrderInvoiceItem $item): string => $item->lineTotal,
            $pedido->items,
        );

        $fretes = $this->ratear($pedido->shipping, $bases);
        $descontos = $this->ratear($pedido->discount, $bases);

        foreach ($pedido->items as $indice => $item) {
            $numero = $indice + 1;
            $bruto = bcmul($item->qty, $item->unitPrice, 2);

            $make->tagprod($this->std([
                'item' => $numero,
                'cProd' => $item->sku,
                // "SEM GTIN" é o que o layout exige quando o produto não
                // tem código de barras — o catálogo migrado do Woo quase
                // nunca tem (pasta 32).
                'cEAN' => $item->gtin ?? 'SEM GTIN',
                'xProd' => $item->name,
                'NCM' => $item->ncm,
                'CEST' => $item->cest,
                'CFOP' => $cfop,
                'uCom' => $item->unit,
                'qCom' => $item->qty,
                'vUnCom' => $item->unitPrice,
                'vProd' => $bruto,
                'cEANTrib' => $item->gtin ?? 'SEM GTIN',
                'uTrib' => $item->unit,
                'qTrib' => $item->qty,
                'vUnTrib' => $item->unitPrice,
                'vFrete' => $this->valorOpcional($fretes[$indice]),
                // O desconto da linha somado ao rateio do desconto do
                // cabeçalho: os dois são desconto do mesmo item.
                'vDesc' => $this->valorOpcional(bcadd($item->discount, $descontos[$indice], 2)),
                'indTot' => 1,
            ]));

            $make->tagimposto($this->std(['item' => $numero]));

            // CSOSN e não CST: o `EmitenteNfe` já recusou o que não é
            // Simples Nacional antes de chegar aqui.
            $make->tagICMSSN($this->std([
                'item' => $numero,
                'orig' => $item->origin,
                'CSOSN' => $csosn,
            ]));

            $make->tagPIS($this->std([
                'item' => $numero,
                'CST' => $pisCst,
                'vBC' => '0.00',
                'pPIS' => '0.00',
                'vPIS' => '0.00',
            ]));

            $make->tagCOFINS($this->std([
                'item' => $numero,
                'CST' => $cofinsCst,
                'vBC' => '0.00',
                'pCOFINS' => '0.00',
                'vCOFINS' => '0.00',
            ]));
        }
    }

    /**
     * O grupo `infRespTec` (NT 2018.005), quando configurado.
     *
     * Opcional no XSD e exigido na prática pela maioria das UFs. Sai
     * inteiro do XML enquanto o `.env` estiver vazio — meio grupo
     * preenchido seria pior que nenhum, porque o XSD reprovaria.
     */
    private function responsavelTecnico(Make $make): void
    {
        $cnpj = $this->texto('fiscal.resp_tecnico.cnpj');
        $contato = $this->texto('fiscal.resp_tecnico.contato');
        $email = $this->texto('fiscal.resp_tecnico.email');
        $fone = $this->texto('fiscal.resp_tecnico.fone');

        if ($cnpj === null || $contato === null || $email === null || $fone === null) {
            return;
        }

        $make->taginfRespTec($this->std([
            'CNPJ' => preg_replace('/\D/', '', $cnpj),
            'xContato' => $contato,
            'email' => $email,
            'fone' => preg_replace('/\D/', '', $fone),
        ]));
    }

    /**
     * Reparte um valor do cabeçalho proporcionalmente entre as linhas.
     *
     * Em `bc*` e não em float, pela regra 6 do projeto — e porque um
     * rateio em ponto flutuante é justamente onde o centavo some. O
     * resíduo (a diferença entre o total e a soma das partes arredondadas)
     * é somado à última linha, de modo que a soma feche **exatamente**.
     *
     * @param  list<string>  $bases
     * @return list<string>
     */
    private function ratear(string $valor, array $bases): array
    {
        $quantidade = count($bases);

        if ($quantidade === 0) {
            return [];
        }

        if (bccomp($valor, '0.00', 2) === 0) {
            return array_fill(0, $quantidade, '0.00');
        }

        $totalDasBases = array_reduce(
            $bases,
            static fn (string $acumulado, string $base): string => bcadd($acumulado, $base, 2),
            '0.00',
        );

        // Bases somando zero (pedido 100% descontado): reparte igual, que
        // é a única divisão possível sem proporção.
        if (bccomp($totalDasBases, '0.00', 2) === 0) {
            $parte = bcdiv($valor, (string) $quantidade, 2);
            $partes = array_fill(0, $quantidade, $parte);
        } else {
            $partes = array_map(
                static fn (string $base): string => bcdiv(bcmul($valor, $base, 6), $totalDasBases, 2),
                $bases,
            );
        }

        $somaDasPartes = array_reduce(
            $partes,
            static fn (string $acumulado, string $parte): string => bcadd($acumulado, $parte, 2),
            '0.00',
        );

        $partes[$quantidade - 1] = bcadd(
            $partes[$quantidade - 1],
            bcsub($valor, $somaDasPartes, 2),
            2,
        );

        return array_values($partes);
    }

    /**
     * 1 = interna, 2 = interestadual, 3 = exterior.
     *
     * Mesma comparação de UF que decide o CFOP em `BuildInvoiceFromOrder`
     * — os dois têm de concordar, ou a nota sai com CFOP 6xxx e `idDest`
     * de operação interna.
     */
    private function idDestino(EmitenteNfe $emitente, DestinatarioNfe $destinatario): int
    {
        if ($destinatario->uf === 'EX') {
            return 3;
        }

        return $destinatario->uf === $emitente->uf ? 1 : 2;
    }

    /** 1 = presencial (balcão), 2 = pela internet (site). */
    private function indPresenca(OrderInvoiceSnapshot $pedido): int
    {
        return $pedido->channel === OrderChannel::WooCommerce->value ? 2 : 1;
    }

    /**
     * @throws EmissaoInvalida
     */
    private function cstConfigurado(string $chave): string
    {
        $cst = $this->texto($chave);

        if ($cst === null) {
            throw EmissaoInvalida::tributosFederaisNaoConfigurados();
        }

        return $cst;
    }

    private function texto(string $chave): ?string
    {
        $valor = config($chave);

        if (! is_string($valor) && ! is_int($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * Valor de tag opcional: zero vira **ausência**, não `0.00`.
     *
     * Não é preferência de estilo — o tipo `TDec_1302Opc` do XSD recusa
     * `0.00` explicitamente. Escrever "sem frete" como zero reprova o
     * schema antes mesmo de a nota sair daqui, e a maioria dos itens desta
     * operação não tem frete nem desconto próprio.
     */
    private function valorOpcional(string $valor): ?string
    {
        return bccomp($valor, '0.00', 2) > 0 ? $valor : null;
    }

    /**
     * A `sped-nfe` recebe `stdClass` em todas as tags.
     *
     * @param  array<string, scalar|null>  $campos
     */
    private function std(array $campos): stdClass
    {
        return (object) $campos;
    }
}
