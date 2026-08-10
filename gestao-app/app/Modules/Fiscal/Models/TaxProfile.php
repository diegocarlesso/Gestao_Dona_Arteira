<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Models;

use Database\Factories\Fiscal\TaxProfileFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um perfil fiscal da matriz — docs/13 §3.
 *
 * Auditado: mudar o CFOP de um cenário muda o que se declara ao fisco em
 * todas as notas seguintes. "Quem alterou isto, e quando" é a primeira
 * pergunta de qualquer conferência com o contador — e ela só tem resposta
 * se a trilha existir desde o primeiro cadastro.
 *
 * @property int $id
 * @property string $public_id
 * @property string $operation_type
 * @property string $destination
 * @property string $customer_type
 * @property string $cfop
 * @property string $csosn
 * @property string|null $notes
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 */
class TaxProfile extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<TaxProfileFactory> */
    use HasFactory;

    /**
     * Tipos de operação (docs/13 §3). Constantes, e não enum, porque a
     * lista é do contador e vai crescer com a operação (remessa para
     * conserto, bonificação, brinde…) — cada acréscimo seria um deploy se
     * o vocabulário morasse no código.
     */
    public const OPERACAO_VENDA = 'venda';

    public const OPERACAO_DEVOLUCAO = 'devolucao';

    public const DESTINO_DENTRO_UF = 'dentro_uf';

    public const DESTINO_FORA_UF = 'fora_uf';

    public const CLIENTE_PF = 'pf';

    public const CLIENTE_PJ = 'pj';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'operation_type',
        'destination',
        'customer_type',
        'cfop',
        'csosn',
        'notes',
        'valid_from',
        'valid_to',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $perfil): void {
            $perfil->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Perfis vigentes numa data — BR-606, doc 13 §4 (versionamento).
     *
     * `valid_to` nulo significa "vale até segunda ordem", que é o estado
     * normal: só quando a reforma tributária (ou o contador) mudar a regra
     * é que o perfil antigo ganha data de fim e um novo entra em seguida.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVigenteEm(Builder $query, Carbon $data): Builder
    {
        return $query
            ->whereDate('valid_from', '<=', $data)
            ->where(fn (Builder $q) => $q
                ->whereNull('valid_to')
                ->orWhereDate('valid_to', '>=', $data));
    }
}
