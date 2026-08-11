<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Services\Gateways;

use App\Modules\Fiscal\Contracts\NfeGatewayInterface;
use App\Modules\Fiscal\DTO\DestinatarioNfe;
use App\Modules\Fiscal\DTO\EmitenteNfe;
use App\Modules\Fiscal\DTO\NfeTransmissionResult;
use App\Modules\Fiscal\Exceptions\EmissaoInvalida;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\TaxProfile;
use App\Modules\Fiscal\Support\ConfigFiscal;
use App\Modules\Sales\DTO\OrderInvoiceAddress;
use App\Modules\Sales\DTO\OrderInvoiceSnapshot;
use App\Modules\Sales\Services\BuildOrderInvoiceSnapshot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;
use NFePHP\NFe\Tools;
use stdClass;

/**
 * A emissão de verdade: XML assinado com A1, SOAP na SEFAZ — docs/14 §3.
 *
 * **Esta classe ainda não é o bind ativo.** `FiscalServiceProvider`
 * continua entregando `NullNfeGateway`, e a troca é decisão humana, depois
 * de certificado instalado (pasta 25) e da bateria em homologação exigida
 * pela BR-605. Ela existe pronta para que a virada seja uma linha, e não
 * um projeto.
 *
 * Divisão de trabalho com o `MontarXmlNfe`: lá mora o XML (testável sem
 * certificado, validado contra o XSD no CI); aqui moram o certificado, o
 * SOAP e a **tradução do retorno**. A separação é o que permite ter a
 * emissão coberta por teste antes de o `.pfx` existir.
 *
 * ### Como as falhas se dividem (contrato do `NfeGatewayInterface`)
 *
 * - **Rejeição da SEFAZ** — a nota foi processada e recusada. Vira
 *   `NfeTransmissionResult::rejeitado()` com o código e o motivo, nunca
 *   exceção: corrige-se o cadastro e reemite com o **mesmo número**
 *   (BR-602).
 * - **Pendência de configuração** — falta certificado, CNPJ do emitente,
 *   CST de PIS/COFINS. Vira `EmissaoInvalida`, que o job registra no
 *   painel e **não** retenta: `.env` vazio não se preenche na quinta
 *   tentativa.
 * - **Falha de infraestrutura** — rede, SEFAZ fora, certificado ilegível.
 *   Vira exceção crua, e a fila tenta de novo com espera crescente.
 *
 * ### O que ainda impede a emissão real
 *
 * Além do certificado: o endereço do cliente não guarda o código IBGE do
 * município, que o layout exige em `enderDest/cMun`. Está registrado como
 * pendência explícita (`EmissaoInvalida::enderecoSemCodigoIbge`) em vez de
 * ser preenchido por aproximação — um `cMun` errado autoriza uma nota com
 * o município errado, que é pior que não autorizar nenhuma.
 */
class SpedNfeGateway implements NfeGatewayInterface
{
    /** Autorizada (100), fora do prazo (150), com alerta (120, NT 2026.002). */
    private const CSTAT_AUTORIZADA = ['100', '150', '120'];

    /** Lote processado — é aqui que o `protNFe` traz a resposta da nota. */
    private const CSTAT_LOTE_PROCESSADO = '104';

    /**
     * Respostas de "ainda não deu tempo": lote recebido, em processamento,
     * serviço paralisado. Nenhuma delas é rejeição, e marcar a nota como
     * rejeitada aqui faria o operador corrigir um cadastro que está certo.
     */
    private const CSTAT_PENDENTE = ['103', '105', '106', '108', '109'];

    /** Onde o XML autorizado é guardado (BR-603) — fora do webroot. */
    private const DISCO_GUARDA = 'local';

    public function __construct(
        private readonly BuildOrderInvoiceSnapshot $pedidos,
        private readonly MontarXmlNfe $montagem,
        private readonly CanalSefaz $canal,
    ) {}

    public function transmitir(Invoice $invoice): NfeTransmissionResult
    {
        // A ordem é deliberada: primeiro o que impede **qualquer** nota
        // (ambiente, emitente, certificado), depois o que impede **esta**
        // nota (perfil, cadastro do cliente). Invertida, o operador
        // corrigiria o CPF de um cliente para só então descobrir que o
        // `.env` não tem o CNPJ da própria empresa.
        $tpAmb = $this->tpAmb();
        $emitente = EmitenteNfe::doConfig();
        $this->canal->conferir();

        $perfil = $invoice->taxProfile;

        if (! $perfil instanceof TaxProfile) {
            throw EmissaoInvalida::notaSemPerfilFiscal();
        }

        $pedido = $this->pedidos->handle($invoice->order_id);

        if ($pedido === null) {
            throw EmissaoInvalida::pedidoNaoEncontrado($invoice->order_id);
        }

        $destinatario = DestinatarioNfe::doPedido($pedido, $this->codigoIbgeDoDestino($pedido));

        $xml = $this->montagem->handle(
            nota: $invoice,
            pedido: $pedido,
            emitente: $emitente,
            destinatario: $destinatario,
            perfil: $perfil,
            // A natureza da operação é a descrição textual que acompanha o
            // CFOP. Sai do próprio perfil (`venda`, `devolucao`) em vez de
            // uma constante: quando o contador quiser um texto mais
            // específico, ele muda no cadastro, não no código.
            naturezaDaOperacao: mb_strtoupper($perfil->operation_type),
            tpAmb: $tpAmb,
        );

        $tools = $this->canal->abrir($emitente, $tpAmb);

        // Assinar é a fronteira: daqui para a frente, falha é falha de
        // infraestrutura e a fila deve tentar de novo (o número continua
        // reservado na nota — docs/14 §3).
        $assinado = $tools->signNFe($xml);

        $retorno = $tools->sefazEnviaLote(
            aXml: [$assinado],
            idLote: str_pad((string) $invoice->id, 15, '0', STR_PAD_LEFT),
            // Síncrono: uma nota por vez, resposta na mesma chamada. O
            // assíncrono exigiria um segundo job só para consultar recibo,
            // e o volume da Dona Arteira não justifica lote.
            indSinc: 1,
        );

        $this->registrarNoLog($invoice, $tools);

        return $this->interpretar($invoice, $assinado, $retorno, $tpAmb);
    }

    /**
     * Traduz o `retEnviNFe` para o vocabulário do ERP.
     *
     * Toda a lógica de "o que a SEFAZ quis dizer" mora aqui, e nada dela
     * atravessa a interface — quem chama recebe `authorized`, `rejected`
     * ou `pending` e mais nada (ADR-0009).
     */
    private function interpretar(Invoice $invoice, string $assinado, string $retorno, int $tpAmb): NfeTransmissionResult
    {
        $resposta = (new Standardize)->toStd($retorno);
        $cStat = $this->texto($resposta, 'cStat');
        $xMotivo = $this->texto($resposta, 'xMotivo') ?? 'sem motivo informado';

        if ($cStat !== null && in_array($cStat, self::CSTAT_PENDENTE, true)) {
            return NfeTransmissionResult::pendente(
                "SEFAZ ainda não concluiu o processamento ({$cStat}: {$xMotivo}) — "
                .'a nota segue com o número reservado e a emissão será tentada de novo.'
            );
        }

        if ($cStat !== self::CSTAT_LOTE_PROCESSADO) {
            // Rejeição no nível do lote (schema inválido, emitente não
            // habilitado, ambiente errado): a nota não passou, e o motivo
            // é o que o operador precisa ler.
            return NfeTransmissionResult::rejeitado("Rejeição {$cStat}: {$xMotivo}");
        }

        $protocolo = $this->protocolo($resposta);

        if ($protocolo === null) {
            return NfeTransmissionResult::pendente(
                "A SEFAZ respondeu {$cStat} ({$xMotivo}) sem protocolo da nota — "
                .'consulte a chave antes de reemitir, para não duplicar documento.'
            );
        }

        $status = $this->texto($protocolo, 'cStat');
        $motivo = $this->texto($protocolo, 'xMotivo') ?? 'sem motivo informado';

        if ($status === null || ! in_array($status, self::CSTAT_AUTORIZADA, true)) {
            return NfeTransmissionResult::rejeitado("Rejeição {$status}: {$motivo}");
        }

        $chave = $this->texto($protocolo, 'chNFe');
        $numeroDoProtocolo = $this->texto($protocolo, 'nProt');

        if ($chave === null || $numeroDoProtocolo === null) {
            return NfeTransmissionResult::pendente(
                "A SEFAZ autorizou ({$status}) mas não devolveu chave ou protocolo — "
                .'consulte a chave na SEFAZ antes de qualquer reemissão.'
            );
        }

        // **Guardar antes de responder.** Se o processo morrer depois desta
        // linha, a nota existe em disco e é recuperável; se morresse antes,
        // haveria uma NF-e autorizada na SEFAZ que o ERP não conhece —
        // exatamente a perda que a BR-603 existe para impedir.
        $caminho = $this->guardar($invoice, $assinado, $retorno, $chave, $tpAmb);

        return NfeTransmissionResult::autorizado($chave, $numeroDoProtocolo, $caminho);
    }

    /**
     * Grava o XML processado (`nfeProc` = NF-e + protocolo) — BR-603.
     *
     * Guarda o assinado sem protocolo como plano B: se a lib recusar juntar
     * os dois (digest divergente, retorno truncado), perder o documento
     * seria muito pior que guardar a versão incompleta e resolver depois.
     */
    private function guardar(Invoice $invoice, string $assinado, string $retorno, string $chave, int $tpAmb): string
    {
        $pasta = sprintf(
            'nfe/%s/%s/%s',
            $tpAmb === 1 ? 'producao' : 'homologacao',
            now()->format('Y'),
            now()->format('m'),
        );

        try {
            $conteudo = Complements::toAuthorize($assinado, $retorno);
            $caminho = "{$pasta}/{$chave}-procNFe.xml";
        } catch (\Throwable $e) {
            Log::warning('NF-e autorizada, mas o XML processado não pôde ser montado.', [
                'invoice_id' => $invoice->id,
                'chave' => $chave,
                'erro' => $e->getMessage(),
            ]);

            $conteudo = $assinado;
            $caminho = "{$pasta}/{$chave}-assinada-sem-protocolo.xml";
        }

        Storage::disk(self::DISCO_GUARDA)->put($caminho, $conteudo);

        return $caminho;
    }

    /**
     * O código IBGE do município de entrega.
     *
     * `OrderInvoiceAddress::$cityCode` existe no contrato mas hoje chega
     * sempre nulo: o cadastro de endereços de Vendas não guarda o código, e
     * ele não se deduz de cidade + UF sem a tabela do IBGE. Como
     * `enderDest/cMun` é obrigatório no layout, a emissão para aqui em vez
     * de aproximar — nota autorizada com município errado é pior que nota
     * não autorizada, porque ninguém percebe.
     *
     * @throws EmissaoInvalida
     */
    private function codigoIbgeDoDestino(OrderInvoiceSnapshot $pedido): string
    {
        $endereco = $pedido->shippingAddress;

        if (! $endereco instanceof OrderInvoiceAddress) {
            throw EmissaoInvalida::clienteSemEndereco(
                $pedido->customerName ?? "cliente do pedido #{$pedido->orderNumber}"
            );
        }

        $codigo = preg_replace('/\D/', '', (string) $endereco->cityCode) ?? '';

        if ($codigo === '') {
            throw EmissaoInvalida::enderecoSemCodigoIbge($endereco->city, $endereco->state);
        }

        return $codigo;
    }

    /**
     * `homologacao` → tpAmb 2, `producao` → tpAmb 1.
     *
     * Sem default silencioso: um valor desconhecido em `NFE_ENVIRONMENT`
     * que caísse em "produção" emitiria documento fiscal de verdade por
     * causa de um erro de digitação.
     *
     * @throws EmissaoInvalida
     */
    private function tpAmb(): int
    {
        $ambiente = ConfigFiscal::texto('fiscal.nfe.environment');

        return match ($ambiente) {
            'homologacao' => 2,
            'producao' => 1,
            default => throw EmissaoInvalida::ambienteInvalido((string) $ambiente),
        };
    }

    /**
     * Request e response do SOAP — docs/14 §7.
     *
     * O resumo (chave, status, motivo) vai sempre; o envelope inteiro vai
     * em `debug`, porque ele carrega nome, CPF e endereço do cliente e não
     * deve cair no log de produção sem alguém ter pedido. Certificado
     * nunca: o `X509Certificate` da assinatura é removido antes (pasta 25).
     */
    private function registrarNoLog(Invoice $invoice, Tools $tools): void
    {
        Log::info('NF-e transmitida à SEFAZ.', [
            'invoice_id' => $invoice->id,
            'order_id' => $invoice->order_id,
            'number' => $invoice->number,
            'environment' => config('fiscal.nfe.environment'),
        ]);

        Log::debug('NF-e — envelope SOAP.', [
            'invoice_id' => $invoice->id,
            'request' => $this->semCertificado($tools->lastRequest),
            'response' => $this->semCertificado($tools->lastResponse),
        ]);
    }

    private function semCertificado(string $xml): string
    {
        return (string) preg_replace(
            '#<X509Certificate>.*?</X509Certificate>#s',
            '<X509Certificate>[removido]</X509Certificate>',
            $xml,
        );
    }

    /**
     * O `protNFe` da resposta — objeto quando há uma nota, lista quando a
     * SEFAZ devolve mais de uma (não acontece com `indSinc=1`, mas o
     * `Standardize` muda o tipo e não custa cobrir).
     */
    private function protocolo(stdClass $resposta): ?stdClass
    {
        $protNFe = $resposta->protNFe ?? null;

        if (is_array($protNFe)) {
            $protNFe = $protNFe[0] ?? null;
        }

        if (! $protNFe instanceof stdClass) {
            return null;
        }

        $infProt = $protNFe->infProt ?? null;

        return $infProt instanceof stdClass ? $infProt : null;
    }

    private function texto(stdClass $objeto, string $campo): ?string
    {
        $valor = $objeto->{$campo} ?? null;

        if (! is_string($valor) && ! is_int($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
