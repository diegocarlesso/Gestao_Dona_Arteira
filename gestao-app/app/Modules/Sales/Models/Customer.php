<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Enums\CustomerType;
use App\Support\Documento;
use Database\Factories\Sales\CustomerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um cliente — BR-001, docs/10-Vendas.
 *
 * Auditado porque guarda dado pessoal (LGPD, pasta 25 §3): quem alterou o
 * documento ou o e-mail de alguém é pergunta que a lei permite fazer, e
 * ela só tem resposta se a trilha existir desde o primeiro cadastro.
 *
 * @property int $id
 * @property string $public_id
 * @property CustomerType $type
 * @property string $name
 * @property string|null $doc
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $state_registration
 * @property bool $is_wholesale
 * @property CustomerOrigin $origin
 * @property string|null $notes
 * @property Carbon|null $lgpd_consent_at
 *
 * Contagens que a listagem carrega por `withCount` para `pendencias()` não
 * consultar o banco uma vez por linha. Nulas quando ninguém as pediu.
 * @property-read int|null $addresses_count
 * @property-read int|null $enderecos_sem_ibge_count
 */
class Customer extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<CustomerFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'type',
        'name',
        'doc',
        'email',
        'phone',
        'state_registration',
        'is_wholesale',
        'origin',
        'notes',
        'lgpd_consent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CustomerType::class,
            'origin' => CustomerOrigin::class,
            'is_wholesale' => 'boolean',
            'lgpd_consent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $cliente): void {
            $cliente->public_id ??= (string) Str::ulid();
        });

        // Normaliza em qualquer caminho de escrita — cadastro manual,
        // importação do Woo, correção pela API. Deixar a limpeza para o
        // FormRequest funcionaria só no caminho que passa por ele, e a
        // migração não passa.
        static::saving(function (self $cliente): void {
            if ($cliente->doc !== null && $cliente->doc !== '') {
                $cliente->doc = preg_replace('/\D/', '', $cliente->doc);
            }

            if ($cliente->doc === '') {
                $cliente->doc = null;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * O documento com máscara, para leitura.
     */
    public function documentoFormatado(): ?string
    {
        if ($this->doc === null) {
            return null;
        }

        return Documento::valido($this->doc)
            ? Documento::de($this->doc)->formatado()
            // Documento inválido não é apagado nem escondido: a migração
            // do Woo pode trazer CPF que não fecha, e a pasta 17 decidiu
            // manter o cliente com pendência fiscal em vez de recusá-lo.
            : $this->doc;
    }

    /**
     * O que impede este cliente de receber uma nota fiscal.
     *
     * Não bloqueia o cadastro — bloqueia a emissão, e no momento em que a
     * falta importa. Exibido na listagem para que a lacuna seja resolvida
     * antes da venda, e não durante.
     *
     * @return list<string>
     */
    public function pendencias(): array
    {
        $pendencias = [];

        if ($this->doc === null) {
            $pendencias[] = 'sem CPF/CNPJ';
        } elseif (! Documento::valido($this->doc)) {
            $pendencias[] = 'CPF/CNPJ inválido';
        }

        // Usa a contagem já carregada quando a listagem a trouxe
        // (`withCount`) — sem isso, cada linha da tela dispara as suas
        // próprias consultas de pendência.
        $enderecos = $this->addresses_count ?? $this->addresses()->count();

        if ($enderecos === 0) {
            $pendencias[] = 'sem endereço';
        } elseif (($this->enderecos_sem_ibge_count ?? $this->addresses()->whereNull('city_code')->count()) > 0) {
            // ADR-0026: `enderDest/cMun` é obrigatório no layout 4.00, e a
            // emissão recusa em vez de aproximar pelo nome da cidade. A
            // falta aparece aqui, ao lado das outras, para ser resolvida
            // antes da venda — a resolução é escolher o município na tela.
            //
            // Só quando **já existe** endereço: quem não tem endereço
            // nenhum não tem, além disso, um município pendente; seria a
            // mesma lacuna contada duas vezes.
            $pendencias[] = 'sem código IBGE do município';
        }

        return $pendencias;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAtacadistas(Builder $query): Builder
    {
        return $query->where('is_wholesale', true);
    }

    /**
     * Clientes com pelo menos um endereço sem município resolvido.
     *
     * O filtro que dá seguimento ao backfill (ADR-0026): depois de
     * `erp:enderecos:resolver-ibge`, é por aqui que se acha quem sobrou
     * para escolher o município à mão.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSemCodigoIbge(Builder $query): Builder
    {
        return $query->whereHas('addresses', fn (Builder $q) => $q->whereNull('city_code'));
    }
}
