<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use App\Modules\Integrations\WooCommerce\Enums\TipoDeDivergencia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um achado da reconciliação de pedidos — ADR-0025, docs/16 §5.
 *
 * A divergência de valor que não se auto-corrige (o pedido do ERP está
 * congelado, BR-304): vira sinal para a operação investigar. O registro é
 * **idempotente por (entity_type, remote_id, kind)** — a rodada de cada noite
 * faz upsert, incrementando `occurrences` em vez de empilhar linhas. Nunca
 * muta o pedido nem a âncora; só descreve a diferença.
 *
 * @property int $id
 * @property string $entity_type
 * @property string $remote_id
 * @property int|null $local_id
 * @property TipoDeDivergencia $kind
 * @property string|null $detail
 * @property string|null $baseline_checksum
 * @property string|null $remote_checksum
 * @property int $occurrences
 * @property Carbon $first_detected_at
 * @property Carbon $last_detected_at
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property string|null $resolution_note
 */
class WooReconciliationFinding extends Model
{
    protected $table = 'woo_reconciliation_findings';

    /** @var list<string> */
    protected $fillable = [
        'entity_type',
        'remote_id',
        'local_id',
        'kind',
        'detail',
        'baseline_checksum',
        'remote_checksum',
        'occurrences',
        'first_detected_at',
        'last_detected_at',
        'resolved_at',
        'resolved_by',
        'resolution_note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TipoDeDivergencia::class,
            'occurrences' => 'integer',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Registra a divergência de forma idempotente (ADR-0025).
     *
     * - inexistente → cria (1ª ocorrência);
     * - resolvido → **reabre** como ocorrência nova e limpa (a divergência
     *   voltou depois de aceita/corrigida);
     * - aberto → mesma divergência recorrente: `occurrences++` e atualiza o
     *   valor corrente e o carimbo.
     */
    public static function registrar(
        string $entityType,
        string $remoteId,
        ?int $localId,
        TipoDeDivergencia $kind,
        string $baseline,
        string $remote,
        string $detail,
    ): void {
        $achado = static::query()->firstOrNew([
            'entity_type' => $entityType,
            'remote_id' => $remoteId,
            'kind' => $kind->value,
        ]);

        // Novo ou reabertura de um resolvido: ocorrência limpa.
        if (! $achado->exists || $achado->resolved_at !== null) {
            $achado->forceFill([
                'entity_type' => $entityType,
                'remote_id' => $remoteId,
                'local_id' => $localId,
                'kind' => $kind->value,
                'detail' => $detail,
                'baseline_checksum' => $baseline,
                'remote_checksum' => $remote,
                'occurrences' => 1,
                'first_detected_at' => now(),
                'last_detected_at' => now(),
                'resolved_at' => null,
                'resolved_by' => null,
                'resolution_note' => null,
            ])->save();

            return;
        }

        // Aberto: recorrência.
        $achado->forceFill([
            'local_id' => $localId,
            'detail' => $detail,
            'remote_checksum' => $remote,
            'occurrences' => $achado->occurrences + 1,
            'last_detected_at' => now(),
        ])->save();
    }

    /**
     * Auto-resolve os achados abertos deste registro quando o valor converge
     * de novo com a âncora (a edição foi revertida). Sem ação humana.
     *
     * @return int quantos achados foram resolvidos (0 ou 1)
     */
    public static function autoResolver(string $entityType, string $remoteId): int
    {
        return static::query()
            ->where('entity_type', $entityType)
            ->where('remote_id', $remoteId)
            ->whereNull('resolved_at')
            ->update([
                'resolved_at' => now(),
                'resolved_by' => null,
                'resolution_note' => 'Convergiu: o total voltou ao valor congelado na importação.',
            ]);
    }

    /** Resolução manual pela operação (aceitar o novo valor como linha de base). */
    public function resolverManual(int $userId, string $nota): void
    {
        $this->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $userId,
            'resolution_note' => $nota,
        ])->save();
    }

    /**
     * Recorrente = reapareceu em mais de uma rodada e segue aberto. É o que a
     * §5 marca para investigação (bug de sync ou edição persistente).
     */
    public function recorrente(): bool
    {
        return $this->resolved_at === null && $this->occurrences > 1;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeAbertas(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }
}
