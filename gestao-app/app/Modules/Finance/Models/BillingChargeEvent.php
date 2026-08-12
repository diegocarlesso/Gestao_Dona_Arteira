<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Database\Factories\Finance\BillingChargeEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um evento do provedor sobre uma cobrança — BR-507,
 * docs/12-Financeiro/01-cobranca-e-boletos.md §4.
 *
 * `provider_event_id` UNIQUE (na migration) é a idempotência inteira:
 * `ProcessarNotificacaoCobrancaService` tenta criar a linha antes de
 * baixar qualquer título, e um evento reentregue pelo provedor recusa na
 * constraint em vez de duplicar a baixa.
 *
 * @property int $id
 * @property int $charge_id
 * @property string $type
 * @property string|null $provider_event_id
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
class BillingChargeEvent extends Model
{
    /** @use HasFactory<BillingChargeEventFactory> */
    use HasFactory;

    protected $table = 'billing_charge_events';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'charge_id',
        'type',
        'provider_event_id',
        'payload',
        'processed_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BillingCharge, $this>
     */
    public function charge(): BelongsTo
    {
        return $this->belongsTo(BillingCharge::class, 'charge_id');
    }

    public function marcarProcessado(?string $erro = null): void
    {
        $this->forceFill([
            'processed_at' => now(),
            'error' => $erro,
        ])->save();
    }
}
