<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um evento do Woo como chegou — ADR-0007, docs/16 §4.
 *
 * O bruto persistido antes de processar. Webhook e puxada gravam aqui; o
 * job lê `payload` e carimba `processed_at`/`error` ao terminar. Guardar o
 * cru é o que torna o reprocessamento possível sem nova chamada à API — e
 * a fonte do endereço de entrega para o corte 3.
 *
 * @property int $id
 * @property string $topic
 * @property string|null $remote_id
 * @property string|null $delivery_id
 * @property bool $signature_valid
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
class WooWebhookEvent extends Model
{
    protected $table = 'woo_webhook_events';

    /** @var list<string> */
    protected $fillable = [
        'topic',
        'remote_id',
        'delivery_id',
        'signature_valid',
        'payload',
        'received_at',
        'processed_at',
        'error',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function marcarProcessado(?string $motivo = null): void
    {
        $this->forceFill([
            'processed_at' => now(),
            'error' => $motivo,
        ])->save();
    }

    public function registrarErro(string $erro): void
    {
        // Sem `processed_at`: o que falhou continua pendente, para a fila
        // tentar de novo e o painel mostrar o que travou.
        $this->forceFill(['error' => mb_substr($erro, 0, 1000)])->save();
    }
}
