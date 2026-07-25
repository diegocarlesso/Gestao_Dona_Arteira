<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * Gera o próximo SKU — BR-002, ADR-0022.
 *
 * Formato `DA-0001`: prefixo fixo, contador com quatro dígitos, indo a
 * cinco depois de `DA-9999` sem quebrar os anteriores (a comparação é
 * textual e o prefixo não muda).
 *
 * **Sem significado embutido**, de propósito. Um SKU com a categoria
 * dentro passa a mentir no dia em que o produto muda de categoria — e a
 * BR-002 proíbe corrigi-lo. Com 30% do catálogo em kits/trios, que é a
 * fatia que mais se reorganiza, isso aconteceria já na migração.
 */
class GenerateSku
{
    public const PREFIXO = 'DA-';

    private const LARGURA_MINIMA = 4;

    /**
     * O próximo código livre.
     *
     * `lockForUpdate` porque dois cadastros simultâneos leriam o mesmo
     * máximo e tentariam gravar o mesmo SKU — o índice UNIQUE recusaria o
     * segundo, e o usuário veria um erro de banco por ter clicado ao mesmo
     * tempo que um colega. Deve ser chamado dentro de uma transação.
     */
    public function proximo(): string
    {
        $ultimo = Product::query()
            ->where('sku', 'like', self::PREFIXO.'%')
            ->lockForUpdate()
            ->orderByRaw('LENGTH(sku) DESC, sku DESC')
            ->value('sku');

        $numero = $ultimo === null
            ? 1
            : ((int) substr($ultimo, strlen(self::PREFIXO))) + 1;

        return self::PREFIXO.str_pad((string) $numero, self::LARGURA_MINIMA, '0', STR_PAD_LEFT);
    }

    /**
     * O mesmo, garantindo a transação — para quem chama de fora de uma.
     */
    public function proximoEmTransacao(): string
    {
        return DB::transaction(fn (): string => $this->proximo());
    }
}
