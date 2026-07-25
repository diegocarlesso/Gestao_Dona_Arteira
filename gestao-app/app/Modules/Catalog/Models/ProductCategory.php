<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\ProductCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Categoria de produto, em árvore — BR-007.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property int|null $parent_id
 */
class ProductCategory extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<ProductCategoryFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'parent_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $categoria): void {
            $categoria->public_id ??= (string) Str::ulid();
            $categoria->slug ??= Str::slug($categoria->name);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * "Decorativos › Budas" — o caminho legível, montado subindo a árvore.
     */
    public function caminho(): string
    {
        $nomes = [$this->name];
        $atual = $this->parent;

        // Guarda contra ciclo: a FK não impede que alguém torne uma
        // categoria ancestral de si mesma por UPDATE. Sem o limite, esta
        // função entraria em laço infinito e derrubaria a listagem.
        $limite = 10;

        while ($atual !== null && $limite-- > 0) {
            array_unshift($nomes, $atual->name);
            $atual = $atual->parent;
        }

        return implode(' › ', $nomes);
    }
}
