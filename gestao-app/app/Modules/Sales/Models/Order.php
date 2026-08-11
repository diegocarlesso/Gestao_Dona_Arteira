<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Sales\Enums\OrderChannel;
use App\Modules\Sales\Enums\OrderStatus;
use Brick\Money\Money;
use Database\Factories\Sales\OrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um pedido — BR-303, docs/10-Vendas.
 *
 * Auditado: pedido move dinheiro e reserva estoque, e "quem confirmou,
 * quem cancelou" é pergunta que a operação faz. A trilha de estados
 * (`order_status_history`) registra as transições; a auditoria registra
 * as edições de campo.
 *
 * @property int $id
 * @property string $public_id
 * @property int $number
 * @property OrderChannel $channel
 * @property string|null $channel_order_ref
 * @property int|null $customer_id
 * @property OrderStatus $status
 * @property string $price_list
 * @property string $subtotal
 * @property string $discount
 * @property string $shipping
 * @property string $total
 * @property string|null $notes
 * @property string|null $customer_note
 * @property string|null $shipping_method
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $shipped_at
 * @property Carbon|null $delivered_at
 * @property string|null $tracking_code
 * @property string|null $carrier
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $nfe_status
 * @property Carbon|null $invoice_authorized_at
 * @property int|null $created_by
 */
class Order extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'channel',
        'channel_order_ref',
        'customer_id',
        'status',
        'price_list',
        'subtotal',
        'discount',
        'shipping',
        'total',
        'notes',
        'customer_note',
        'shipping_method',
        'confirmed_at',
        'shipped_at',
        'delivered_at',
        'tracking_code',
        'carrier',
        'cancelled_at',
        'cancel_reason',
        'nfe_status',
        'invoice_authorized_at',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => OrderChannel::class,
            'status' => OrderStatus::class,
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'invoice_authorized_at' => 'datetime',
        ];
    }

    /**
     * A nota fiscal deste pedido já está autorizada? — BR-309.
     *
     * String literal e não enum: o vocabulário é do módulo Fiscal
     * (`InvoiceStatus`), e importá-lo aqui furaria a fronteira do ADR-0020
     * justamente para economizar seis caracteres. O acoplamento fica no
     * valor combinado, que é o que atravessa o evento.
     */
    public function temNotaAutorizada(): bool
    {
        return $this->nfe_status === 'authorized';
    }

    /**
     * Este pedido exige NF-e antes de sair? — BR-309/BR-601.
     *
     * Só o canal do site neste corte, o mesmo filtro do gatilho de emissão
     * (ADR-0025 §2). Não é que a venda de balcão dispense nota: é que a
     * BR-601 ainda é hipótese bloqueada no contador, e travar a expedição do
     * balcão por uma regra não validada pararia a operação por causa de uma
     * suposição. Quando a regra for confirmada, muda-se este método — e o
     * filtro do listener — juntos.
     */
    public function exigeNotaFiscal(): bool
    {
        return $this->channel === OrderChannel::WooCommerce;
    }

    /**
     * Pode ser excluído (soft delete)? — BR-311.
     *
     * Só cancelado, e só quando nunca teve nota fiscal (nem pendente, nem
     * rejeitada): nota fiscal é documento legal, e um pedido com rastro
     * fiscal não pode sumir das listagens.
     */
    public function excluivel(): bool
    {
        return $this->status === OrderStatus::Cancelled && $this->nfe_status === null;
    }

    protected static function booted(): void
    {
        static::creating(function (self $pedido): void {
            $pedido->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function history(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * @return HasMany<OrderAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class);
    }

    public function money(): Money
    {
        return Money::of($this->total, 'BRL');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeComStatus(Builder $query, OrderStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }
}
