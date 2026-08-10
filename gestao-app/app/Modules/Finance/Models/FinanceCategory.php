<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Finance\Enums\FinanceCategoryType;
use Database\Factories\Finance\FinanceCategoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Uma categoria gerencial — BR-503, docs/12-Financeiro §4.
 *
 * Árvore simples: a raiz é o grande grupo ("Custos"), a folha é o que o
 * dono lê no relatório ("Compras/Fornecedores"). Nesta fatia existe só a
 * folha semeada; o plano completo é Gate 04.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property FinanceCategoryType $type
 * @property int|null $parent_id
 */
class FinanceCategory extends Model
{
    /** @use HasFactory<FinanceCategoryFactory> */
    use HasFactory;

    /**
     * Explícito por simetria com `Payable`: as tabelas do módulo levam o
     * prefixo `finance_` por escolha de esquema, não por dedução do nome
     * da classe.
     */
    protected $table = 'finance_categories';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'type',
        'parent_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinanceCategoryType::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $categoria): void {
            $categoria->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<FinanceCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<FinanceCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Payable, $this>
     */
    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class, 'category_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDoTipo(Builder $query, FinanceCategoryType $tipo): Builder
    {
        return $query->where('type', $tipo->value);
    }
}
