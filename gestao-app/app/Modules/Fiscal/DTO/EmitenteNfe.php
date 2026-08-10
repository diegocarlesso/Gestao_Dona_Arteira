<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\DTO;

use App\Modules\Fiscal\Exceptions\EmissaoInvalida;
use App\Modules\Fiscal\Support\ConfigFiscal;

/**
 * A empresa que assina a nota — grupos `emit`/`enderEmit` do layout 4.00.
 *
 * Existe para que a config vire um **tipo** antes de encostar na
 * `sped-nfe`: `config('fiscal.emitente.cnpj')` devolve `mixed` e pode vir
 * nulo, e a diferença entre "não configurado" e "configurado errado"
 * precisa aparecer numa mensagem para quem opera, não num
 * `TypeError` no meio da montagem do XML.
 *
 * Por isso o construtor só aceita o que já está completo, e
 * `doConfig()` é a única porta de entrada: ou devolve um emitente
 * inteiro, ou diz **quais variáveis do `.env`** faltam (docs/13-Fiscal/01,
 * itens A-01/A-02, bloqueados no contador).
 */
final readonly class EmitenteNfe
{
    /** CRT que emitem por CSOSN — o que `tax_profiles` guarda (docs/13 §3). */
    private const REGIMES_SIMPLES = [1, 2, 4];

    public function __construct(
        /** Só dígitos: o XML recusa pontuação. */
        public string $cnpj,
        public string $razaoSocial,
        public ?string $nomeFantasia,
        public string $ie,
        /** Código de Regime Tributário — 1/2=Simples, 3=Normal, 4=MEI. */
        public int $crt,
        public string $logradouro,
        public string $numero,
        public string $bairro,
        public string $municipio,
        /** Código IBGE de 7 dígitos — também vira o `cMunFG` da nota. */
        public string $codigoMunicipio,
        public string $uf,
        public string $cep,
    ) {}

    /**
     * Monta o emitente a partir de `config/fiscal.php`.
     *
     * A UF vem de `nfe.uf_emitente` e não de uma chave própria: é a mesma
     * que decide dentro/fora do estado no `BuildInvoiceFromOrder`, e duas
     * fontes para o mesmo fato é como se emite nota com endereço de um
     * estado e CFOP de outro.
     *
     * @throws EmissaoInvalida
     */
    public static function doConfig(): self
    {
        $campos = [
            'NFE_EMITENTE_CNPJ' => ConfigFiscal::digitos('fiscal.emitente.cnpj'),
            'NFE_EMITENTE_RAZAO_SOCIAL' => ConfigFiscal::texto('fiscal.emitente.razao_social'),
            'NFE_EMITENTE_IE' => ConfigFiscal::digitos('fiscal.emitente.ie'),
            'NFE_EMITENTE_CRT' => ConfigFiscal::texto('fiscal.emitente.crt'),
            'NFE_EMITENTE_LOGRADOURO' => ConfigFiscal::texto('fiscal.emitente.endereco.logradouro'),
            'NFE_EMITENTE_NUMERO' => ConfigFiscal::texto('fiscal.emitente.endereco.numero'),
            'NFE_EMITENTE_BAIRRO' => ConfigFiscal::texto('fiscal.emitente.endereco.bairro'),
            'NFE_EMITENTE_MUNICIPIO' => ConfigFiscal::texto('fiscal.emitente.endereco.municipio'),
            'NFE_EMITENTE_COD_MUNICIPIO' => ConfigFiscal::digitos('fiscal.emitente.endereco.codigo_municipio'),
            'NFE_EMITENTE_CEP' => ConfigFiscal::digitos('fiscal.emitente.endereco.cep'),
            'NFE_UF_EMITENTE' => ConfigFiscal::texto('fiscal.nfe.uf_emitente'),
        ];

        // A lista inteira do que falta, e não um `throw` no primeiro campo
        // vazio: quem preenche o `.env` pela primeira vez preenche tudo de
        // uma vez se souber tudo que falta. Descobrir um por vez, a cada
        // reprocesso da fila, seriam onze idas e voltas ao painel.
        $faltando = array_keys(array_filter(
            $campos,
            static fn (?string $valor): bool => $valor === null,
        ));

        if ($faltando !== []) {
            throw EmissaoInvalida::emitenteIncompleto($faltando);
        }

        return new self(
            cnpj: self::exigido($campos, 'NFE_EMITENTE_CNPJ'),
            razaoSocial: self::exigido($campos, 'NFE_EMITENTE_RAZAO_SOCIAL'),
            nomeFantasia: ConfigFiscal::texto('fiscal.emitente.nome_fantasia'),
            ie: self::exigido($campos, 'NFE_EMITENTE_IE'),
            crt: (int) self::exigido($campos, 'NFE_EMITENTE_CRT'),
            logradouro: self::exigido($campos, 'NFE_EMITENTE_LOGRADOURO'),
            numero: self::exigido($campos, 'NFE_EMITENTE_NUMERO'),
            bairro: self::exigido($campos, 'NFE_EMITENTE_BAIRRO'),
            municipio: self::exigido($campos, 'NFE_EMITENTE_MUNICIPIO'),
            codigoMunicipio: self::exigido($campos, 'NFE_EMITENTE_COD_MUNICIPIO'),
            uf: strtoupper(self::exigido($campos, 'NFE_UF_EMITENTE')),
            cep: self::exigido($campos, 'NFE_EMITENTE_CEP'),
        );
    }

    /**
     * Um campo que já se sabe presente — e que, ainda assim, se confere.
     *
     * A conferência acima devolve a lista de faltantes; esta devolve o
     * **valor**, com o tipo estreitado de `?string` para `string`. A
     * redundância é o preço de ter as duas coisas: a mensagem completa para
     * quem opera e o tipo honesto para quem lê o código depois.
     *
     * @param  array<string, string|null>  $campos
     *
     * @throws EmissaoInvalida
     */
    private static function exigido(array $campos, string $variavel): string
    {
        $valor = $campos[$variavel] ?? null;

        if ($valor === null) {
            throw EmissaoInvalida::emitenteIncompleto([$variavel]);
        }

        return $valor;
    }

    /**
     * Emite por CSOSN (Simples Nacional) ou por CST (Regime Normal)?
     *
     * `tax_profiles` só guarda CSOSN hoje, então a resposta `false` é
     * motivo para recusar a emissão — e não para preencher um CST qualquer.
     */
    public function simplesNacional(): bool
    {
        return in_array($this->crt, self::REGIMES_SIMPLES, true);
    }
}
