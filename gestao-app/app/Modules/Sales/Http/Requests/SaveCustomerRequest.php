<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Requests;

use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Models\Customer;
use App\Support\Rules\DocumentoValido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCustomerRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $cliente = $this->route('customer');
        $id = $cliente instanceof Customer ? $cliente->id : null;

        return [
            'type' => ['required', Rule::enum(CustomerType::class)],
            'name' => ['required', 'string', 'max:255'],

            // Nulável (venda de balcão sem nota), válido quando informado
            // (BR-001), e único **entre os não nulos** — o `ignore` deixa
            // salvar o próprio cliente sem colidir consigo mesmo.
            'doc' => [
                'nullable', 'string', new DocumentoValido,
                Rule::unique('customers', 'doc')
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],

            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'state_registration' => ['nullable', 'string', 'max:32'],
            'is_wholesale' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lgpd_consent_at' => ['nullable', 'date'],

            'enderecos' => ['array'],
            'enderecos.*.public_id' => ['nullable', 'string'],
            'enderecos.*.label' => ['nullable', 'string', 'max:64'],
            'enderecos.*.zip' => ['required', 'string', 'max:9'],
            'enderecos.*.street' => ['required', 'string', 'max:255'],
            'enderecos.*.number' => ['nullable', 'string', 'max:32'],
            'enderecos.*.complement' => ['nullable', 'string', 'max:120'],
            'enderecos.*.district' => ['nullable', 'string', 'max:120'],
            'enderecos.*.city' => ['required', 'string', 'max:120'],
            'enderecos.*.state' => ['required', 'string', 'size:2'],

            // Código IBGE do município (ADR-0026). Nulável: o normal é vir
            // vazio e a resolução automática preencher — só chega valor
            // quando alguém escolheu o município na tela.
            //
            // O `exists` não é redundante com a FK: sem ele, um código
            // inexistente estouraria como `QueryException` (erro 500) em
            // vez de virar mensagem de formulário. A FK continua sendo a
            // garantia final; esta regra é a educada.
            'enderecos.*.city_code' => [
                'nullable', 'string', 'size:7',
                Rule::exists('ibge_municipalities', 'ibge_code'),
            ],
            'enderecos.*.is_default_shipping' => ['boolean'],
            'enderecos.*.is_default_billing' => ['boolean'],
        ];
    }

    /**
     * O documento chega mascarado da tela e a unicidade compara texto —
     * sem normalizar antes, "123.456.789-09" e "12345678909" seriam dois
     * clientes diferentes para o banco e o mesmo para a Receita.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('doc')) {
            $limpo = preg_replace('/\D/', '', (string) $this->input('doc')) ?? '';
            $this->merge(['doc' => $limpo === '' ? null : $limpo]);
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'doc.unique' => 'Já existe um cliente com este CPF/CNPJ.',
            'enderecos.*.zip.required' => 'Informe o CEP do endereço.',
            'enderecos.*.state.size' => 'A UF tem duas letras.',
            'enderecos.*.city_code.exists' => 'Município não encontrado na tabela do IBGE — escolha um da busca.',
            'enderecos.*.city_code.size' => 'O código do IBGE tem 7 dígitos.',
        ];
    }
}
