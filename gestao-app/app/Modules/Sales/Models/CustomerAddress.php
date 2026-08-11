<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Database\Factories\Sales\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um endereço do cliente.
 *
 * @property int $id
 * @property string $public_id
 * @property int $customer_id
 * @property string|null $label
 * @property string $zip
 * @property string $street
 * @property string|null $number
 * @property string|null $complement
 * @property string|null $district
 * @property string $city
 * @property string $state
 * @property string|null $city_code Código IBGE do município (`cMun` da NF-e). Nulo = ainda não resolvido — ADR-0026.
 * @property bool $is_default_shipping
 * @property bool $is_default_billing
 */
class CustomerAddress extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'label',
        'zip',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'city_code',
        'is_default_shipping',
        'is_default_billing',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default_shipping' => 'boolean',
            'is_default_billing' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $endereco): void {
            $endereco->public_id ??= (string) Str::ulid();
        });

        static::saving(function (self $endereco): void {
            $endereco->zip = preg_replace('/\D/', '', $endereco->zip) ?? $endereco->zip;
            $endereco->state = strtoupper($endereco->state);
        });

        // Um padrão de cada tipo por cliente. Dois endereços marcados como
        // entrega padrão fariam a escolha voltar a ser "o primeiro que
        // aparecer" — e o pedido sairia para o endereço errado sem que
        // ninguém tivesse decidido isso.
        static::saved(function (self $endereco): void {
            foreach (['is_default_shipping', 'is_default_billing'] as $marca) {
                if ($endereco->{$marca}) {
                    static::query()
                        ->where('customer_id', $endereco->customer_id)
                        ->where('id', '!=', $endereco->id)
                        ->where($marca, true)
                        ->update([$marca => false]);
                }
            }
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
     *
     * Separado de `Customer::pendencias()` porque a pergunta é do endereço:
     * um cliente pode ter três endereços e só um deles pendente.
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
