<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Enums\ProductKind;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro e edição de produto — docs/32-Catalogo.
 *
 * **`sku` não é aceito.** Ele é gerado pelo `GenerateSku` e imutável
 * (BR-002); um campo de formulário que o definisse contradiria a regra na
 * primeira tela.
 */
class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        // A Policy decide; o controller chama `authorize()` explicitamente.
        return true;
    }

    /**
     * `mixed` no valor, como nos demais FormRequests do projeto: as regras
     * misturam string, array e objeto — e o `Rule::enum()` implementa o
     * contrato legado `Rule`, não `ValidationRule`, então qualquer
     * anotação mais estreita descreveria algo que não é verdade.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'kind' => ['required', Rule::enum(ProductKind::class)],
            'unit' => ['required', 'string', 'max:8'],
            'color' => ['nullable', 'string', 'max:255'],

            // Medidas ficam opcionais de propósito: 46 dos 716 produtos do
            // legado não têm peso, e exigir aqui travaria a migração de
            // cadastros legítimos. A ausência é sinalizada na listagem
            // (pasta 32 §3.6), e a validação dura fica na venda, onde o
            // dado é de fato necessário para cotar frete.
            'height_cm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'width_cm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'depth_cm' => ['nullable', 'numeric', 'min:0', 'max:999999'],
            'weight_g' => ['nullable', 'numeric', 'min:0', 'max:9999999'],

            'ncm' => ['nullable', 'string', 'size:8'],
            'cest' => ['nullable', 'string', 'size:7'],
            'origin' => ['nullable', 'string', 'size:1'],
            'gtin' => ['nullable', 'string', 'max:14'],

            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'default_package_id' => ['nullable', 'integer', 'exists:packages,id'],

            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'drying_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'sell_on_woo' => ['boolean'],

            // Preços: strings, não floats. Dinheiro nunca trafega como
            // ponto flutuante (ADR-0013) — `decimal:2` recusa "12,5" e
            // valores com mais de duas casas antes de chegar ao Service.
            'preco_varejo' => ['nullable', 'decimal:0,2', 'min:0'],
            'preco_atacado' => ['nullable', 'decimal:0,2', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nome',
            'kind' => 'tipo',
            'unit' => 'unidade',
            'color' => 'cor/acabamento',
            'height_cm' => 'altura',
            'width_cm' => 'largura',
            'depth_cm' => 'profundidade',
            'weight_g' => 'peso',
            'product_category_id' => 'categoria',
            'default_package_id' => 'embalagem padrão',
            'min_stock' => 'estoque mínimo',
            'drying_days' => 'dias de secagem',
            'preco_varejo' => 'preço de varejo',
            'preco_atacado' => 'preço de atacado',
        ];
    }
}
