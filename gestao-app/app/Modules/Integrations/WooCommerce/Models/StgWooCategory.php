<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Categoria bruta do WooCommerce — docs/17-Migracao F2.
 *
 * @property int $id
 * @property int $woo_id
 * @property string|null $name
 * @property string|null $slug
 * @property int|null $parent_woo_id
 * @property array<string, mixed> $payload
 */
class StgWooCategory extends Model
{
    protected $table = 'stg_woo_categories';

    /** @var list<string> */
    protected $fillable = [
        'woo_id', 'name', 'slug', 'parent_woo_id', 'count',
        'payload', 'import_batch', 'status_triagem', 'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
