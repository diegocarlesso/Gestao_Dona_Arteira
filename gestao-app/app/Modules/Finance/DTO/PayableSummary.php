<?php

declare(strict_types=1);

namespace App\Modules\Finance\DTO;

/**
 * O que outro módulo precisa saber de um título para exibi-lo.
 *
 * Existe pelo mesmo motivo do `ProductSummary`: o ADR-0020 proíbe o
 * Compras referenciar `Finance\Models`, e a tela do pedido de compra
 * precisa mostrar "R$ 1.250,00, vence em 15/09, em aberto" ao lado do PC
 * confirmado. Devolver o model passaria no `arch()` — que verifica
 * namespace, não semântica — enquanto vazava o acoplamento inteiro.
 *
 * Deliberadamente magro: é o extrato do título, não o título. Baixa,
 * estorno e categoria são assunto do Financeiro, e quem precisar deles
 * pede outra coisa em vez de alargar isto até virar cópia do model.
 */
final readonly class PayableSummary
{
    public function __construct(
        public string $publicId,
        public string $supplierName,
        public string $amount,
        public ?string $dueDate,
        public string $status,
        public string $categoryName,
    ) {}
}
