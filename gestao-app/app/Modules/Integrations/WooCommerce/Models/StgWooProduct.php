<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Produto bruto do WooCommerce, como veio — docs/17-Migracao F2.
 *
 * Sem invariante nenhuma de propósito: o staging existe para receber o
 * dado como ele é, inclusive errado. Quem julga é o saneamento (F3).
 *
 * @property int $id
 * @property int $woo_id
 * @property string|null $type
 * @property int|null $parent_woo_id
 * @property string|null $name
 * @property string|null $sku
 * @property array<string, mixed> $payload
 * @property int|null $import_batch
 * @property string $status_triagem
 */
class StgWooProduct extends Model
{
    protected $table = 'stg_woo_products';

    /** @var list<string> */
    protected $fillable = [
        'woo_id', 'type', 'parent_woo_id', 'name', 'sku', 'status',
        'regular_price', 'weight', 'woo_modified_at', 'payload',
        'import_batch', 'status_triagem', 'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'woo_modified_at' => 'datetime',
        ];
    }
}
