<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Models;

use Database\Factories\Fiscal\FiscalSeriesFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Uma série de numeração de NF-e — BR-602, docs/14 §3.
 *
 * @property int $id
 * @property string $public_id
 * @property string $series
 * @property int $next_number
 * @property string $environment
 */
class FiscalSeries extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<FiscalSeriesFactory> */
    use HasFactory;

    /**
     * O Laravel pluralizaria `FiscalSeries` para `fiscal_series` por acaso
     * (a palavra já é plural), mas declarar evita que a próxima versão do
     * pluralizador decida outra coisa numa tabela que não pode ser
     * renomeada sem quebrar a sequência fiscal.
     *
     * @var string
     */
    protected $table = 'fiscal_series';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'series',
        'next_number',
        'environment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'next_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $serie): void {
            $serie->public_id ??= (string) Str::ulid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * Reserva e devolve o próximo número da série — BR-602.
     *
     * `lockForUpdate()` dentro de uma transação: duas emissões simultâneas
     * — e elas serão simultâneas, porque o gatilho é um job em fila e a
     * fila roda em paralelo — leriam o mesmo `next_number` sem o lock. O
     * resultado seria duas notas com o mesmo número: a segunda rejeitada
     * pela SEFAZ no melhor caso, ou duas autorizadas com número repetido no
     * pior, que é problema que só se resolve com o contador.
     *
     * Relê a linha do banco em vez de confiar no que está em memória: o
     * `$this` pode ter sido carregado antes de outra emissão incrementar o
     * contador, e o lock só vale para o que se lê depois de tomá-lo.
     *
     * Reservado é reservado: o número sai do contador aqui e **não volta**
     * se a transmissão falhar. É assim de propósito — a nota rejeitada é
     * corrigida e reemitida com o mesmo número (docs/14 §3), e o número
     * realmente abandonado se resolve por inutilização na SEFAZ, nunca
     * reciclando-o para outra nota.
     */
    public function proximoNumero(): int
    {
        return DB::transaction(function (): int {
            /** @var self $serie */
            $serie = static::query()->lockForUpdate()->findOrFail($this->id);

            $numero = $serie->next_number;

            $serie->next_number = $numero + 1;
            $serie->save();

            // Mantém a instância de quem chamou coerente com o banco.
            $this->next_number = $serie->next_number;

            return $numero;
        });
    }
}
