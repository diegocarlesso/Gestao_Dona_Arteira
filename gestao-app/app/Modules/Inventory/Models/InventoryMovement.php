<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Enums\MovementType;
use Database\Factories\Inventory\InventoryMovementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Uma linha do ledger — BR-202, ADR-0008.
 *
 * **Imutável.** Não se edita nem se apaga: estorno é contra-movimento
 * apontando para o original. É a mesma disciplina de `ProductPrice`, e
 * pela mesma razão — o registro existe para explicar o presente, e um
 * registro que pode ser reescrito não explica nada.
 *
 * **Sem relação com `Product`.** O ADR-0020 proíbe um módulo referenciar
 * models de outro, e a proibição vale mesmo quando dói: o nome do produto
 * na tela de extrato vem de um Service do Catálogo, não de um `belongsTo`
 * daqui. A coluna `product_id` e a FK no banco continuam existindo — o
 * banco é um só (ADR-0002); o que não existe é o acoplamento em PHP.
 *
 * @property int $id
 * @property string $public_id
 * @property int $product_id
 * @property int $location_id
 * @property MovementType $type
 * @property string $qty
 * @property string|null $unit_cost
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $notes
 * @property Carbon $occurred_at
 * @property int|null $created_by
 */
class InventoryMovement extends Model
{
    /** @use HasFactory<InventoryMovementFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'location_id',
        'type',
        'qty',
        'unit_cost',
        'reference_type',
        'reference_id',
        'notes',
        'occurred_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $movimento): void {
            $movimento->public_id ??= (string) Str::ulid();
        });

        // O ledger é append-only (BR-202). Corrigir um lançamento errado é
        // lançar o contra-movimento — o erro fica visível, junto com quem
        // o cometeu e quem o corrigiu.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function entrada(): bool
    {
        return bccomp($this->qty, '0', 3) === 1;
    }

    /**
     * Movimentos de um produto num local, do mais recente ao mais antigo —
     * o extrato, que a pasta 09 §8 chama de tela de depuração número 1.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeExtrato(Builder $query, int $productId, int $locationId): Builder
    {
        return $query
            ->where('product_id', $productId)
            ->where('location_id', $locationId)
            ->orderByDesc('occurred_at')
            // Desempate por id: dois movimentos do mesmo instante — uma
            // transferência, por exemplo — sairiam em ordem arbitrária, e
            // o extrato precisa ser reproduzível para servir de prova.
            ->orderByDesc('id');
    }
}
