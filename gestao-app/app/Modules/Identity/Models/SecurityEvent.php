<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\SecurityEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma entrada da trilha de segurança (pasta 26 §2.1).
 *
 * **Imutável por contrato** (pasta 26 §3): nenhuma rota ou serviço edita
 * ou apaga uma entrada; o expurgo é da rotina de retenção. O model
 * bloqueia `update` e `delete` no próprio boot em vez de confiar em
 * ninguém lembrar — trilha que pode ser editada não prova nada.
 *
 * Este model não é auditável: auditar a trilha geraria trilha da trilha,
 * sem fim.
 *
 * @property int $id
 * @property SecurityEventType $event
 * @property int|null $user_id
 * @property int|null $actor_id
 * @property string|null $identifier
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $context
 * @property Carbon $created_at
 */
class SecurityEvent extends Model
{
    protected $table = 'security_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event',
        'user_id',
        'actor_id',
        'identifier',
        'ip_address',
        'user_agent',
        'context',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => SecurityEventType::class,
            'context' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // A imutabilidade da §3 vira invariante do model, não recomendação
        // de documento. `updating`/`deleting` devolvendo false abortam a
        // operação silenciosamente do ponto de vista do Eloquent, o que é
        // suficiente: não há caminho legítimo que tente.
        static::updating(static fn (): bool => false);
        static::deleting(static fn (): bool => false);
    }

    /**
     * Sobre quem é o fato.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Quem causou o fato — pode não ser a mesma pessoa.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, SecurityEventType ...$types): Builder
    {
        return $query->whereIn(
            'event',
            array_map(static fn (SecurityEventType $t): string => $t->value, $types),
        );
    }
}
