<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Uma rodada da reconciliação — ADR-0025, docs/16 §5.
 *
 * O heartbeat: uma linha por execução, com o que foi conferido. A situação
 * (`ok`/`falha`/`incompleta`) é **derivada** de `finished_at` + `error`, no
 * espírito do `situacao()` do WooWebhookEvent — não guardamos estado
 * redundante que poderia divergir do que de fato aconteceu.
 *
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int $window_days
 * @property int $orders_checked
 * @property int $orders_missing_imported
 * @property int $orders_divergent
 * @property int $findings_resolved
 * @property string|null $error
 */
class WooReconciliationRun extends Model
{
    protected $table = 'woo_reconciliation_runs';

    /** @var list<string> */
    protected $fillable = [
        'started_at',
        'finished_at',
        'window_days',
        'orders_checked',
        'orders_missing_imported',
        'orders_divergent',
        'findings_resolved',
        'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'window_days' => 'integer',
            'orders_checked' => 'integer',
            'orders_missing_imported' => 'integer',
            'orders_divergent' => 'integer',
            'findings_resolved' => 'integer',
        ];
    }

    public static function iniciar(int $windowDays): self
    {
        return static::query()->create([
            'started_at' => now(),
            'window_days' => $windowDays,
        ]);
    }

    /**
     * @param  array<string, int>  $counts  chaves: orders_checked, orders_missing_imported, orders_divergent, findings_resolved
     */
    public function concluir(array $counts): void
    {
        $this->forceFill([
            'finished_at' => now(),
            'error' => null,
            'orders_checked' => $counts['orders_checked'],
            'orders_missing_imported' => $counts['orders_missing_imported'],
            'orders_divergent' => $counts['orders_divergent'],
            'findings_resolved' => $counts['findings_resolved'],
        ])->save();
    }

    public function falhar(string $erro): void
    {
        // Sem apagar as contagens parciais: `finished_at` + `error` já contam
        // a história de "começou, foi até aqui, e quebrou".
        $this->forceFill([
            'finished_at' => now(),
            'error' => mb_substr($erro, 0, 1000),
        ])->save();
    }

    /** `ok` | `falha` | `incompleta` — derivada, nunca guardada. */
    public function situacao(): string
    {
        if ($this->finished_at === null) {
            return 'incompleta';
        }

        return $this->error !== null ? 'falha' : 'ok';
    }

    /**
     * Passou de ~26h desde o início da última rodada = o job diário não
     * rodou (o cron do Hostinger morreu em silêncio). É o alerta que a
     * ausência de rodada dispara no painel.
     */
    public function atrasada(): bool
    {
        return $this->started_at->lt(now()->subHours(26));
    }

    public static function ultima(): ?self
    {
        return static::query()->latest('started_at')->first();
    }
}
