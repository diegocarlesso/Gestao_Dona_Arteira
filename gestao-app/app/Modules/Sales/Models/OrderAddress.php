<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Database\Factories\Sales\OrderAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * O endereço de cobrança ou entrega de um pedido — BR-707.
 *
 * Separado de `CustomerAddress` de propósito: a entrega às vezes não é no
 * endereço do próprio comprador (presente para outra pessoa). Ver
 * docs/10-Vendas §2.0.2.
 *
 * @property int $id
 * @property string $public_id
 * @property int $order_id
 * @property string $type `'billing'` ou `'shipping'`
 * @property string $zip
 * @property string $street
 * @property string|null $number
 * @property string|null $complement
 * @property string|null $district
 * @property string $city
 * @property string $state
 * @property string|null $city_code Código IBGE do município (`cMun` da NF-e). Nulo = ainda não resolvido — ADR-0026.
 */
class OrderAddress extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<OrderAddressFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'type',
        'zip',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'city_code',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $endereco): void {
            $endereco->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $endereco): void {
            $endereco->zip = preg_replace('/\D/', '', $endereco->zip) ?? $endereco->zip;
            $endereco->state = strtoupper($endereco->state);
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * O município do IBGE, quando já resolvido — ADR-0026.
     *
     * Aponta por `city_code` → `ibge_code`, e não por id: é a mesma coluna
     * que a FK usa, então a relação não precisa de nenhuma junção a mais
     * do que o banco já garante. Nula enquanto o endereço estiver pendente.
     *
     * @return BelongsTo<IbgeMunicipality, $this>
     */
    public function municipality(): BelongsTo
    {
        return $this->belongsTo(IbgeMunicipality::class, 'city_code', 'ibge_code');
    }

    /**
     * Falta o código IBGE do município para este endereço sair numa NF-e?
     */
    public function semCodigoIbge(): bool
    {
        return $this->city_code === null;
    }

    /**
     * Uma linha legível — "Rua X, 123 — Bairro, Cidade/UF, CEP".
     */
    public function resumo(): string
    {
        $partes = array_filter([
            trim($this->street.($this->number !== null ? ", {$this->number}" : '')),
            $this->complement,
            $this->district,
            "{$this->city}/{$this->state}",
            $this->cepFormatado(),
        ]);

        return implode(' — ', $partes);
    }

    public function cepFormatado(): string
    {
        return preg_replace('/^(\d{5})(\d{3})$/', '$1-$2', $this->zip) ?? $this->zip;
    }
}
