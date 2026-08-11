<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Receivable;

/**
 * Registra um título a receber — BR-501/BR-503, docs/12-Financeiro §3.
 *
 * Espelho de `RegisterPayableService`: mesma validação de valor positivo,
 * mesma resolução de categoria com fallback padrão, mesmo acordo de não
 * abrir transação própria (quem chama já está dentro de uma).
 */
class RegisterReceivableService
{
    /**
     * A categoria em que caem as vendas quando ninguém escolhe outra.
     */
    public const CATEGORIA_PADRAO_VENDAS = 'Vendas';

    /**
     * @param  string  $originType  origem do título (ex.: `order`) — sem FK cross-módulo
     * @param  string  $customerName  snapshot do nome: renomear o cliente não reescreve o que já se deve
     * @param  string  $amount  valor em reais como string decimal (ADR-0013)
     * @param  string|null  $dueDate  vencimento `Y-m-d`; nulo = a combinar (fica fora do caixa projetado)
     * @param  int|null  $categoryId  nulo cai na categoria padrão de vendas
     *
     * @throws TituloInvalido
     */
    public function registrar(
        string $originType,
        int $originId,
        string $customerName,
        string $amount,
        ?string $dueDate = null,
        ?int $categoryId = null,
    ): Receivable {
        $valor = $this->dinheiro($amount);

        if (bccomp($valor, '0.00', 2) !== 1) {
            throw TituloInvalido::valorNaoPositivo($valor);
        }

        $categoria = $categoryId === null
            ? $this->categoriaPadrao()
            : $this->categoriaInformada($categoryId);

        return Receivable::query()->create([
            'category_id' => $categoria->id,
            'origin_type' => $originType,
            'origin_id' => $originId,
            'customer_name' => $customerName,
            'amount' => $valor,
            'due_date' => $dueDate,
            'status' => Receivable::STATUS_OPEN,
        ]);
    }

    /**
     * @throws TituloInvalido
     */
    private function categoriaPadrao(): FinanceCategory
    {
        $categoria = FinanceCategory::query()
            ->doTipo(FinanceCategoryType::Receivable)
            ->where('name', self::CATEGORIA_PADRAO_VENDAS)
            ->first();

        if ($categoria === null) {
            throw TituloInvalido::categoriaPadraoAusente(self::CATEGORIA_PADRAO_VENDAS);
        }

        return $categoria;
    }

    /**
     * @throws TituloInvalido
     */
    private function categoriaInformada(int $categoryId): FinanceCategory
    {
        $categoria = FinanceCategory::query()->find($categoryId);

        if ($categoria === null) {
            throw TituloInvalido::categoriaInexistente($categoryId);
        }

        if ($categoria->type !== FinanceCategoryType::Receivable) {
            throw TituloInvalido::categoriaDeOutroTipo(
                $categoria->name,
                FinanceCategoryType::Receivable->label(),
                $categoria->type->label(),
            );
        }

        return $categoria;
    }

    /**
     * Normaliza para duas casas — mesma função de `RegisterPayableService`.
     */
    private function dinheiro(string $valor): string
    {
        return bcadd($valor === '' ? '0' : $valor, '0', 2);
    }
}
