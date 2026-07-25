<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Database\Factories\Catalog\PackageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Uma embalagem do catálogo — BR-004.
 *
 * Medidas da **caixa**, distintas das da peça (BR-005): a peça cabe
 * dentro, e é a caixa que viaja. É daqui que sai a cotação de frete.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $height_cm
 * @property string $width_cm
 * @property string $depth_cm
 * @property string $weight_g
 * @property bool $active
 */
class Package extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<PackageFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'height_cm',
        'width_cm',
        'depth_cm',
        'weight_g',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $embalagem): void {
            $embalagem->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'default_package_id');
    }
}
