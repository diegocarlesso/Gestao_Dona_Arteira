<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um lote de importação — docs/17-Migracao §2, princípio 5.
 *
 * @property int $id
 * @property string $source
 * @property string $entity
 * @property string $status
 * @property bool $dry_run
 * @property int $fetched
 * @property int $stored
 * @property int $failed
 * @property int $last_page
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
class ImportBatch extends Model
{
    protected $table = 'import_batches';

    /** @var list<string> */
    protected $fillable = [
        'source', 'entity', 'status', 'dry_run',
        'fetched', 'stored', 'failed', 'last_page',
        'error', 'started_at', 'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function concluir(): void
    {
        $this->forceFill(['status' => 'completed', 'finished_at' => now()])->save();
    }

    public function falhar(string $motivo): void
    {
        // O lote guarda o motivo mesmo tendo falhado no meio: `last_page`
        // diz de onde retomar, e sem a mensagem ninguém sabe se vale a
        // pena retomar ou se o problema é a credencial.
        $this->forceFill([
            'status' => 'failed',
            'error' => mb_substr($motivo, 0, 2000),
            'finished_at' => now(),
        ])->save();
    }
}
