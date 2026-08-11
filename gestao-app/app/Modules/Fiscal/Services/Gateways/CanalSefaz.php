<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Services\Gateways;

use App\Modules\Fiscal\DTO\EmitenteNfe;
use App\Modules\Fiscal\Exceptions\EmissaoInvalida;
use App\Modules\Fiscal\Support\ConfigFiscal;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Tools;
use RuntimeException;

/**
 * O certificado A1 e a `Tools` configurada — docs/14 §4, pasta 25.
 *
 * Separado do `SpedNfeGateway` porque é a **única** peça da emissão que
 * exige o `.pfx`. Isolada, o resto (montagem, tradução do retorno) fica
 * testável sem segredo nenhum; e ela própria pode ser exercitada em teste
 * com um certificado autoassinado, o que cobre a assinatura e a validação
 * contra o XSD sem depender de comprar certificado para rodar o CI.
 *
 * Nada daqui vai para log: nem caminho, nem senha, nem conteúdo (pasta 25).
 */
class CanalSefaz
{
    /** A única versão de layout que a `Tools` conhece hoje. */
    private const VERSAO_LAYOUT = '4.00';

    /**
     * `PL_010_V1.30` — trocado em 2026-08-11 (era `PL_009_V4`), ADR-0027.
     * O pacote antigo é anterior à reforma tributária e não tem o grupo
     * IBSCBS no XSD; a troca é aditiva (confirmada rodando a suíte
     * inteira do Fiscal contra o schema novo).
     */
    private const SCHEMAS = 'PL_010_V1.30';

    /**
     * Pré-flight barato: o `.pfx` está configurado e legível?
     *
     * Existe separado de `abrir()` para rodar **antes** de montar o XML.
     * Descobrir que não há certificado depois de resolver perfil fiscal,
     * cliente e itens é o mesmo trabalho jogado fora a cada tentativa da
     * fila — e a pendência é a mesma desde o começo.
     *
     * @throws EmissaoInvalida
     */
    public function conferir(): void
    {
        $caminho = ConfigFiscal::texto('fiscal.certificado.path');
        $senha = ConfigFiscal::texto('fiscal.certificado.password');

        if ($caminho === null || $senha === null) {
            throw EmissaoInvalida::certificadoNaoConfigurado();
        }

        if (! is_readable($caminho)) {
            throw EmissaoInvalida::certificadoNaoEncontrado();
        }
    }

    /**
     * A `Tools` pronta para assinar e transmitir.
     *
     * @throws EmissaoInvalida
     */
    public function abrir(EmitenteNfe $emitente, int $tpAmb): Tools
    {
        $tools = new Tools(
            (string) json_encode([
                'tpAmb' => $tpAmb,
                'razaosocial' => $emitente->razaoSocial,
                'cnpj' => $emitente->cnpj,
                'siglaUF' => $emitente->uf,
                'schemes' => self::SCHEMAS,
                'versao' => self::VERSAO_LAYOUT,
            ]),
            $this->certificado(),
        );

        // Modelo 55 (NF-e). NFC-e (65) é outra conversa e outro ADR
        // (docs/14 §9) — deixar o default implícito seria abrir a porta
        // para emitir o modelo errado sem ninguém decidir.
        $tools->model(55);

        return $tools;
    }

    /**
     * @throws EmissaoInvalida
     */
    private function certificado(): Certificate
    {
        $this->conferir();

        $caminho = (string) ConfigFiscal::texto('fiscal.certificado.path');
        $senha = (string) ConfigFiscal::texto('fiscal.certificado.password');
        $conteudo = file_get_contents($caminho);

        if ($conteudo === false) {
            throw EmissaoInvalida::certificadoNaoEncontrado();
        }

        // `readPfx` estoura com senha errada ou arquivo corrompido, e a
        // exceção sobe crua: é falha de infraestrutura, não pendência de
        // cadastro, e a fila deve tentar de novo.
        $certificado = Certificate::readPfx($conteudo, $senha);

        // Vencido não assina nada, e a mensagem precisa dizer a data — é o
        // aviso que deveria ter chegado 30 dias antes (docs/14 §4).
        if ($certificado->isExpired()) {
            throw new RuntimeException(
                'O certificado A1 está vencido — nenhuma nota será assinada até a renovação '
                .'(validade até '.$certificado->getValidTo()->format('d/m/Y').').'
            );
        }

        return $certificado;
    }
}
