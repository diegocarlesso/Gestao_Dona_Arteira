<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Modules\Finance\DTO\CobrancaEmitida;
use App\Modules\Finance\Enums\StatusCobranca;
use App\Modules\Finance\Enums\TipoCobranca;
use Database\Factories\Finance\BillingChargeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Uma cobrança (PIX com vencimento ou boleto) registrada num provedor —
 * BR-505…510, docs/12-Financeiro/01-cobranca-e-boletos.md §3/§4.
 *
 * Os métodos de transição de estado (`registrar`/`falhar`/`cancelar`/
 * `pagar`/`expirar`) são mutação de campo pura, sem orquestrar outra
 * tabela — mesmo nível do `WooWebhookEvent::marcarProcessado()`. Baixar o
 * título (`finance_receivables`) é responsabilidade de
 * `ProcessarNotificacaoCobrancaService`, não deste model.
 *
 * @property int $id
 * @property string $public_id
 * @property int $receivable_id
 * @property int|null $profile_id
 * @property string $provider
 * @property string|null $provider_charge_id
 * @property TipoCobranca $type
 * @property string $amount
 * @property Carbon|null $due_date
 * @property StatusCobranca $status
 * @property string|null $digitable_line
 * @property string|null $barcode
 * @property string|null $pix_payload
 * @property string|null $pdf_url
 * @property string|null $fee_amount
 * @property string|null $paid_amount
 * @property Carbon|null $paid_at
 * @property Carbon|null $cancelled_at
 * @property string|null $failure_reason
 * @property string|null $payer_name
 * @property string|null $payer_email
 * @property string|null $payer_document_type
 * @property string|null $payer_document_number
 */
class BillingCharge extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<BillingChargeFactory> */
    use HasFactory;

    protected $table = 'billing_charges';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'receivable_id',
        'profile_id',
        'provider',
        'provider_charge_id',
        'type',
        'amount',
        'due_date',
        'status',
        'digitable_line',
        'barcode',
        'pix_payload',
        'pdf_url',
        'fee_amount',
        'paid_amount',
        'paid_at',
        'cancelled_at',
        'failure_reason',
        'payer_name',
        'payer_email',
        'payer_document_type',
        'payer_document_number',
        'payer_address_zip',
        'payer_address_street',
        'payer_address_number',
        'payer_address_neighborhood',
        'payer_address_city',
        'payer_address_state',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => TipoCobranca::class,
            'status' => StatusCobranca::class,
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $cobranca): void {
            $cobranca->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Receivable, $this>
     */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class, 'receivable_id');
    }

    /**
     * @return BelongsTo<BillingProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(BillingProfile::class, 'profile_id');
    }

    /**
     * @return HasMany<BillingChargeEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(BillingChargeEvent::class, 'charge_id');
    }

    public function registrar(CobrancaEmitida $resultado): void
    {
        $this->forceFill([
            'provider_charge_id' => $resultado->providerChargeId,
            'status' => $resultado->status,
            'digitable_line' => $resultado->digitableLine,
            'barcode' => $resultado->barcode,
            'pix_payload' => $resultado->pixPayload,
            'pdf_url' => $resultado->pdfUrl,
        ])->save();
    }

    public function falhar(string $motivo): void
    {
        $this->forceFill([
            'status' => StatusCobranca::Falhou,
            'failure_reason' => mb_substr($motivo, 0, 500),
        ])->save();
    }

    public function cancelar(): void
    {
        $this->forceFill([
            'status' => StatusCobranca::Cancelada,
            'cancelled_at' => now(),
        ])->save();
    }

    public function expirar(): void
    {
        $this->forceFill(['status' => StatusCobranca::Vencida])->save();
    }

    public function pagar(string $paidAmount, ?string $feeAmount, Carbon $paidAt, bool $parcial): void
    {
        $this->forceFill([
            'status' => $parcial ? StatusCobranca::ParcialmentePaga : StatusCobranca::Paga,
            'paid_amount' => $paidAmount,
            'fee_amount' => $feeAmount,
            'paid_at' => $paidAt,
        ])->save();
    }
}
