<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePurchaseOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Fornecedor pelo `public_id` — as rotas e as telas nunca
            // expõem o id incremental (convenção da pasta 04).
            'supplier' => ['required', 'string', 'exists:suppliers,public_id'],

            'freight' => ['nullable', 'numeric', 'min:0'],
            'issued_at' => ['nullable', 'date'],
            'expected_delivery_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],

            // Ao menos um item: PC vazio não vira dívida nem compra —
            // a mesma exigência que o Service repete, porque expedir a
            // regra só pela tela deixaria o caminho por comando sem ela.
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.product' => ['required', 'string'],
            'itens.*.qty' => ['required', 'numeric', 'gt:0'],

            // Custo pode ser 0,00 numa linha (brinde, amostra) desde que o
            // PC inteiro some mais que zero — quem checa o total é o Service.
            'itens.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'supplier.required' => 'Escolha o fornecedor.',
            'supplier.exists' => 'Fornecedor não encontrado.',
            'itens.required' => 'Adicione ao menos um item ao pedido de compra.',
            'itens.min' => 'Adicione ao menos um item ao pedido de compra.',
            'itens.*.qty.gt' => 'A quantidade precisa ser maior que zero.',
        ];
    }
}
