<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Enums\FinanceCategoryType;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\FinanceCategory;
use App\Modules\Finance\Models\Payable;

/**
 * Registra um título a pagar — BR-501/BR-503, docs/12-Financeiro §3.
 *
 * **A superfície pública do Financeiro nesta fatia** (ADR-0020): é por aqui
 * que o Compras transforma um pedido de compra confirmado em dívida. O
 * Financeiro nunca conhece `PurchaseOrder` nem `Supplier` — recebe
 * primitivos (a origem como par tipo/id, o nome do fornecedor como
 * snapshot) e devolve o título.
 *
 * **Não abre transação própria, de propósito.** Quem chama já está dentro
 * de uma `DB::transaction` (o Compras, ao confirmar o PC) e o título tem de
 * viver ou morrer junto com ela: um PC que falhou no meio não pode deixar
 * uma dívida órfã para trás. É o mesmo acordo de `ConfirmOrderService` com
 * `ReserveStockService::reservar()`, e vale nos dois sentidos — chamar este
 * método fora de transação também funciona, só não é atômico com nada.
 */
class RegisterPayableService
{
    /**
     * A categoria em que caem as compras de fornecedor quando ninguém
     * escolhe outra (BR-503: categoria por origem, sempre obrigatória).
     */
    public const CATEGORIA_PADRAO_COMPRAS = 'Compras/Fornecedores';

    /**
     * @param  string  $originType  origem do título (ex.: `purchase_order`) — sem FK cross-módulo
     * @param  string  $supplierName  snapshot do nome: renomear o fornecedor não reescreve o que já se deve
     * @param  string  $amount  valor em reais como string decimal (ADR-0013), ex.: `'1250.00'`
     * @param  string|null  $dueDate  vencimento `Y-m-d`; nulo = a combinar (fica fora do caixa projetado)
     * @param  int|null  $categoryId  nulo cai na categoria padrão de compras
     *
     * @throws TituloInvalido
     */
    public function registrar(
        string $originType,
        int $originId,
        string $supplierName,
        string $amount,
        ?string $dueDate = null,
        ?int $categoryId = null,
    ): Payable {
        $valor = $this->dinheiro($amount);

        if (bccomp($valor, '0.00', 2) !== 1) {
            throw TituloInvalido::valorNaoPositivo($valor);
        }

        $categoria = $categoryId === null
            ? $this->categoriaPadrao()
            : $this->categoriaInformada($categoryId);

        return Payable::query()->create([
            'category_id' => $categoria->id,
            'origin_type' => $originType,
            'origin_id' => $originId,
            'supplier_name' => $supplierName,
            'amount' => $valor,
            'due_date' => $dueDate,
            'status' => Payable::STATUS_OPEN,
        ]);
    }

    /**
     * @throws TituloInvalido
     */
    private function categoriaPadrao(): FinanceCategory
    {
        $categoria = FinanceCategory::query()
            ->doTipo(FinanceCategoryType::Payable)
            ->where('name', self::CATEGORIA_PADRAO_COMPRAS)
            ->first();

        if ($categoria === null) {
            throw TituloInvalido::categoriaPadraoAusente(self::CATEGORIA_PADRAO_COMPRAS);
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

        if ($categoria->type !== FinanceCategoryType::Payable) {
            throw TituloInvalido::categoriaDeOutroTipo(
                $categoria->name,
                FinanceCategoryType::Payable->label(),
                $categoria->type->label(),
            );
        }

        return $categoria;
    }

    /**
     * Normaliza para duas casas — o valor chega do Compras como total
     * calculado ("1250.5", "89") e o banco guarda DECIMAL(15,2). Mesma
     * função do `RegisterChannelOrderService`, pelo mesmo motivo: comparar
     * e somar dinheiro exige as casas fixas (ADR-0013).
     */
    private function dinheiro(string $valor): string
    {
        return bcadd($valor === '' ? '0' : $valor, '0', 2);
    }
}
