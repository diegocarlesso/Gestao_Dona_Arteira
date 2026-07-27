<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\LocationType;
use Database\Factories\Inventory\LocationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um local de estoque — docs/09-Estoque.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property LocationType $type
 * @property bool $is_default
 * @property bool $is_active
 */
class Location extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'is_default',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LocationType::class,
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $local): void {
            $local->public_id ??= (string) Str::ulid();
        });

        // Um padrão só. Sem isto, marcar o segundo local como padrão
        // deixaria dois marcados e a escolha voltaria a ser "o primeiro
        // que aparecer" — o problema que a coluna existe para resolver.
        static::saved(function (self $local): void {
            if ($local->is_default) {
                static::query()
                    ->where('id', '!=', $local->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * O local que a tela assume quando ninguém escolhe.
     *
     * Cai no primeiro ativo se nenhum estiver marcado — situação que o
     * seeder não produz, mas que um banco mexido à mão produz.
     */
    public static function padrao(): ?self
    {
        return static::query()->where('is_active', true)->where('is_default', true)->first()
            ?? static::query()->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
