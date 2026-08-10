<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Exceptions;

use DomainException;

/**
 * O que impede montar a nota — e o que fazer a respeito.
 *
 * Toda mensagem daqui é escrita para aparecer no painel fiscal e ser
 * **acionável por quem opera**, não por quem programou: "778: NCM inválido"
 * não diz nada; "o produto DA-0413 está sem NCM — preencha na ficha dele"
 * diz o que clicar. Rejeição de SEFAZ traduzida em ação é requisito da
 * pasta 14, não gentileza.
 *
 * Nenhuma destas condições se resolve tentando de novo: são pendências de
 * cadastro ou de configuração. Quem captura registra a pendência na nota e
 * **não** relança para a fila — retentar um NCM ausente cinco vezes só
 * enche o log.
 */
class EmissaoInvalida extends DomainException
{
    public static function pedidoNaoEncontrado(int $orderId): self
    {
        return new self("Pedido {$orderId} não existe mais — nada a faturar.");
    }

    public static function semItens(int $numeroPedido): self
    {
        return new self("O pedido #{$numeroPedido} não tem itens — não há o que faturar.");
    }

    public static function semCliente(int $numeroPedido): self
    {
        // Balcão vende para "Consumidor" sem cadastro (BR-001 exige o
        // documento no faturamento, não no pedido). Faturar, porém, exige
        // destinatário — é aqui que a lacuna deixa de ser aceitável.
        return new self("O pedido #{$numeroPedido} não tem cliente vinculado — a nota precisa de um destinatário.");
    }

    public static function clienteSemDocumento(string $nome): self
    {
        return new self("O cliente {$nome} está sem CPF/CNPJ — preencha o documento no cadastro antes de faturar (BR-001).");
    }

    public static function clienteSemEndereco(string $nome): self
    {
        return new self("O cliente {$nome} está sem endereço — cadastre um endereço antes de faturar.");
    }

    /**
     * @param  list<string>  $pendencias
     */
    public static function produtoSemDadosFiscais(string $identificacao, array $pendencias): self
    {
        // BR-606: NCM e origem são obrigatórios antes da primeira emissão
        // que inclua o produto. A carga do Woo não trouxe nenhum dos dois,
        // então esta é a mensagem que a operação mais vai ver no começo.
        $faltando = implode(', ', $pendencias);

        return new self("O produto {$identificacao} está {$faltando} — complete os dados fiscais na ficha do produto (BR-606).");
    }

    public static function ufEmitenteNaoConfigurada(): self
    {
        return new self(
            'A UF do emitente não está configurada (NFE_UF_EMITENTE) — sem ela não dá para '
            .'saber se a operação é dentro ou fora do estado, e o CFOP sairia errado.'
        );
    }

    public static function serieNaoConfigurada(string $serie, string $ambiente): self
    {
        return new self(
            "A série {$serie} não existe para o ambiente {$ambiente} — cadastre a série "
            .'em `fiscal_series` com o próximo número correto antes de emitir (BR-602).'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Pendências de configuração do emissor — docs/14 §3/§4
    |--------------------------------------------------------------------------
    |
    | Daqui para baixo são condições que o `SpedNfeGateway` encontra na hora
    | de montar/assinar/transmitir. Continuam valendo as mesmas duas regras
    | da classe: mensagem acionável e **não** relançar para a fila. Faltar
    | CNPJ no `.env` não vira verdade na quinta tentativa.
    |
    */

    /**
     * @param  list<string>  $variaveis  chaves de `.env` que estão vazias
     */
    public static function emitenteIncompleto(array $variaveis): self
    {
        $lista = implode(', ', $variaveis);

        return new self(
            "Faltam dados do emitente para montar a NF-e: {$lista}. Preencha no `.env` "
            .'(docs/13-Fiscal/01, itens A-01/A-02 — dependem da validação com o contador).'
        );
    }

    public static function certificadoNaoConfigurado(): self
    {
        return new self(
            'O certificado A1 não está configurado (NFE_CERT_PATH / NFE_CERT_PASSWORD) — '
            .'sem ele não há assinatura, e sem assinatura não há NF-e.'
        );
    }

    public static function certificadoNaoEncontrado(): self
    {
        // De propósito **sem** o caminho na mensagem: ela vai parar no
        // painel fiscal e no log, e a localização do `.pfx` é justamente o
        // que a pasta 25 manda não espalhar.
        return new self(
            'O arquivo do certificado A1 não foi encontrado no caminho de NFE_CERT_PATH — '
            .'confira o caminho (fora do webroot) e a permissão de leitura do usuário do PHP.'
        );
    }

    public static function ambienteInvalido(string $ambiente): self
    {
        return new self(
            "Ambiente fiscal `{$ambiente}` não existe — NFE_ENVIRONMENT aceita apenas "
            .'`homologacao` ou `producao`.'
        );
    }

    public static function regimeSemSuporte(int $crt): self
    {
        return new self(
            "O regime tributário CRT={$crt} não é emissível com o cadastro atual: "
            .'`tax_profiles` guarda CSOSN, que só vale para Simples Nacional (CRT 1, 2 ou 4). '
            .'Regime Normal exige CST de ICMS e alíquotas — pendente da doc 13.'
        );
    }

    public static function tributosFederaisNaoConfigurados(): self
    {
        return new self(
            'Os CST de PIS e COFINS não estão configurados (NFE_PIS_CST / NFE_COFINS_CST). '
            .'O layout exige os dois grupos em todo item — peça os códigos ao contador '
            .'(docs/13-Fiscal/01).'
        );
    }

    public static function notaSemNumeracao(): self
    {
        return new self(
            'A nota chegou à transmissão sem número ou sem série — só se transmite nota '
            .'numerada (BR-602). Reprocesse a emissão a partir do pedido.'
        );
    }

    public static function notaSemPerfilFiscal(): self
    {
        return new self(
            'A nota está sem perfil fiscal vinculado — sem ele não há CFOP nem CSOSN. '
            .'Cadastre o perfil da operação em `tax_profiles` (docs/13 §3) e reprocesse.'
        );
    }

    /**
     * O código IBGE do município do **destinatário**.
     *
     * Hoje `customer_addresses` não guarda esse código, e o schema o exige
     * (`enderDest/cMun`). A lacuna é de cadastro de Vendas, não da emissão
     * — daí a mensagem apontar para lá em vez de sugerir um valor.
     */
    public static function enderecoSemCodigoIbge(string $municipio, string $uf): self
    {
        return new self(
            "O endereço de entrega ({$municipio}/{$uf}) está sem o código IBGE do município, "
            .'e a NF-e exige esse código no destinatário. O cadastro de endereços ainda não '
            .'tem o campo — é pendência de Vendas (docs/10), não do fiscal.'
        );
    }

    public static function destinatarioSemInscricaoEstadual(string $nome): self
    {
        return new self(
            "O cliente {$nome} é pessoa jurídica e o pedido não informa a inscrição estadual — "
            .'sem ela não dá para dizer se é contribuinte de ICMS (`indIEDest`), e o valor '
            .'errado muda a tributação. Preencha a IE no cadastro do cliente.'
        );
    }

    /**
     * O que a própria `sped-nfe` reclamou ao montar o XML.
     *
     * @param  list<string>  $erros
     */
    public static function xmlIncompleto(array $erros): self
    {
        $lista = implode('; ', $erros);

        return new self("A NF-e não pôde ser montada: {$lista}");
    }
}
