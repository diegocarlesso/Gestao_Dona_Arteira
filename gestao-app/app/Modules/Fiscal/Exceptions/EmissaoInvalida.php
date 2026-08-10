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
}
