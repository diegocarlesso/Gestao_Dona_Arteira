<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveOrderRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Cliente é opcional (balcão "Consumidor"); `public_id`, resolvido
            // para o id interno no controller.
            'customer' => ['nullable', 'string', 'exists:customers,public_id'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'itens' => ['array'],
            'itens.*.product' => ['required', 'string', 'exists:products,public_id'],
            'itens.*.qty' => ['required', 'numeric', 'gt:0'],
            'itens.*.note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'itens.*.product.exists' => 'Produto não encontrado.',
            'itens.*.qty.gt' => 'A quantidade precisa ser maior que zero.',
        ];
    }
}
