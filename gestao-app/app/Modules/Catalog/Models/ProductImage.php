<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma imagem do produto — ADR-0017, fase 1.
 *
 * Guarda **referência**, não arquivo: a mídia segue hospedada no
 * WordPress até o Gate 06. `source` é o que dirá, no dia da reavaliação,
 * quanto do catálogo ainda depende dele.
 *
 * @property int $id
 * @property int $product_id
 * @property string $url
 * @property string|null $thumbnail_url
 * @property string|null $alt
 * @property string $source
 * @property string|null $remote_id
 * @property int $position
 */
class ProductImage extends Model
{
    public const ORIGEM_WOO = 'woo';

    protected $table = 'product_images';

    /** @var list<string> */
    protected $fillable = [
        'product_id', 'url', 'thumbnail_url', 'alt',
        'source', 'remote_id', 'position',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * A menor versão disponível.
     *
     * Cai para a original quando a origem não ofereceu reduzida — melhor
     * uma imagem pesada que nenhuma.
     */
    public function paraPrevia(): string
    {
        return $this->thumbnail_url ?: $this->url;
    }
}
