<?php

declare(strict_types=1);

namespace App\Modules\Finance\Exceptions;

use DomainException;

class TituloInvalido extends DomainException
{
    public static function categoriaPadraoAusente(string $nome): self
    {
        // BR-503: título sem categoria é impossível. O Service não cria a
        // categoria por conta própria — inventar linha do plano gerencial
        // em tempo de execução é como o plano de contas de verdade vira
        // uma lista de nomes parecidos que ninguém consegue somar. Quem
        // define o plano é o seeder (e, no Gate 04, o cadastro).
        return new self(
            "A categoria financeira padrão \"{$nome}\" não existe. Rode o seeder de categorias do Financeiro (FinanceCategorySeeder)."
        );
    }

    public static function categoriaInexistente(int $categoryId): self
    {
        return new self("A categoria financeira {$categoryId} não existe.");
    }

    public static function categoriaDeOutroTipo(string $nome, string $tipoEsperado, string $tipoRecebido): self
    {
        // Classificar uma saída numa categoria de receita faria o fluxo de
        // caixa somar o que devia subtrair.
        return new self(
            "A categoria \"{$nome}\" é de {$tipoRecebido} e não pode classificar um título de {$tipoEsperado}."
        );
    }

    public static function valorNaoPositivo(string $valor): self
    {
        // Título de R$ 0,00 não é dívida, e título negativo é crédito
        // disfarçado — os dois entrariam mudos no aberto e no caixa
        // projetado. Devolução/abatimento de fornecedor é contrapartida
        // própria (BR-504), não um a pagar de sinal trocado.
        return new self(
            "Um título a pagar precisa de valor positivo; recebido R$ {$valor}."
        );
    }

    public static function tituloInexistente(string $tipo, int $id): self
    {
        return new self("O título {$tipo} {$id} não existe.");
    }

    public static function tipoDesconhecido(string $tipo): self
    {
        // 'payable'/'receivable' são os dois únicos valores do morphMap
        // (FinanceServiceProvider) — qualquer outro é bug de quem chama,
        // não dado do usuário, mas ainda assim vira mensagem nomeada em
        // vez de erro de SQL/morphMap sem contexto.
        return new self("Tipo de título desconhecido: \"{$tipo}\". Use \"payable\" ou \"receivable\".");
    }

    public static function baixaExcedeSaldo(string $valor, string $saldo): self
    {
        // BR-509: pagamento a maior não é baixa parcial de sinal trocado —
        // é decisão de negócio (registrar como receita de juros, devolver)
        // que este Service não toma sozinho. Recusar é mais seguro que
        // silenciosamente baixar o título inteiro e sumir com a diferença.
        return new self(
            "A baixa de R$ {$valor} excede o saldo aberto do título (R$ {$saldo})."
        );
    }

    public static function contaInexistente(int $accountId): self
    {
        return new self("A conta financeira {$accountId} não existe.");
    }

    public static function estornoDeEstorno(int $settlementId): self
    {
        // BR-504: contrapartida de contrapartida encadearia baixas
        // negativas indefinidamente sem nunca devolver o título ao estado
        // real — sempre estorna a baixa original.
        return new self("A baixa {$settlementId} já é um estorno; estorne a baixa original, não este.");
    }
}
