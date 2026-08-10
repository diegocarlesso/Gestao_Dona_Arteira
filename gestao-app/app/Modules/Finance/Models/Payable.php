<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use Brick\Money\Money;
use Database\Factories\Finance\PayableFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um título a pagar — BR-501, docs/12-Financeiro §3.
 *
 * Auditado pelo mesmo motivo do `Order`: mexe com dinheiro, e "quem mudou
 * o valor/o vencimento deste título" é pergunta que o dono vai fazer. A
 * trilha de auditoria é também o que sustenta a regra de que estorno se
 * faz por contrapartida e nunca por `DELETE` (BR-504, Gate 04).
 *
 * `amount` é **string**, não float: o cast para float perderia centavos e
 * violaria o ADR-0013. Para aritmética, `bcadd`/`bcsub`/`bccomp` ou o
 * `money()` abaixo.
 *
 * @property int $id
 * @property string $public_id
 * @property int $category_id
 * @property string|null $origin_type
 * @property int|null $origin_id
 * @property string $supplier_name
 * @property string $amount
 * @property Carbon|null $due_date
 * @property string $status
 */
class Payable extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<PayableFactory> */
    use HasFactory;

    /**
     * O único estado desta fatia. Vira enum no Gate 04, quando a baixa
     * (BR-502) trouxer os estados que faltam — hoje um enum de um caso só
     * fingiria uma máquina de estados que não existe.
     */
    public const STATUS_OPEN = 'open';

    /**
     * Explícito: o prefixo `finance_` é uma escolha de esquema (as tabelas
     * do módulo ficam agrupadas no banco), não algo que o Eloquent deduza
     * do nome da classe — sem isto ele procuraria `payables`.
     */
    protected $table = 'finance_payables';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'origin_type',
        'origin_id',
        'supplier_name',
        'amount',
        'due_date',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $titulo): void {
            $titulo->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<FinanceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'category_id');
    }

    public function money(): Money
    {
        return Money::of($this->amount, 'BRL');
    }

    public function aberto(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAbertos(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Os títulos de uma origem — "o que o pedido de compra 42 gerou".
     *
     * Existe aqui para quem chama não precisar montar o par
     * `origin_type`/`origin_id` na mão a cada consulta e acabar esquecendo
     * um dos dois (o que devolveria títulos de outra origem com o mesmo id).
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeDaOrigem(Builder $query, string $originType, int $originId): Builder
    {
        return $query->where('origin_type', $originType)->where('origin_id', $originId);
    }
}
