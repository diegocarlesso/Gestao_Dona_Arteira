<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Http\Requests;

use App\Modules\Purchasing\Models\Supplier;
use App\Support\Rules\DocumentoValido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $fornecedor = $this->route('supplier');
        $id = $fornecedor instanceof Supplier ? $fornecedor->id : null;

        return [
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],

            // Obrigatório, diferente do cliente: o balcão vende sem CPF,
            // mas comprar de quem não se sabe quem é deixa a conta a pagar
            // sem dono e a nota de entrada sem emitente. `DocumentoValido`
            // aceita CPF também — fornecedor pessoa física existe no
            // artesanato (o marceneiro, o artesão que fornece a peça crua).
            'document' => [
                'required', 'string', new DocumentoValido,
                Rule::unique('suppliers', 'document')
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],

            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],

            // Prazos em dias. O teto de 365 não é burocracia: "3000" é erro
            // de digitação de "30", e ele viraria um vencimento em 2034.
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * O documento chega mascarado da tela e a unicidade compara texto —
     * sem normalizar antes, "12.345.678/0001-95" e "12345678000195" seriam
     * dois fornecedores para o banco e o mesmo para a Receita.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('document')) {
            $this->merge(['document' => preg_replace('/\D/', '', (string) $this->input('document')) ?? '']);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'legal_name.required' => 'Informe a razão social do fornecedor.',
            'document.required' => 'Informe o CNPJ (ou CPF) do fornecedor.',
            'document.unique' => 'Já existe um fornecedor com este CNPJ/CPF.',
        ];
    }
}
