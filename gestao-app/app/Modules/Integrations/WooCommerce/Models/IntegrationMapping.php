<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ponte entre entidade local e id externo — BR-704.
 *
 * @property int $id
 * @property string $entity_type
 * @property int $local_id
 * @property string $remote_system
 * @property string $remote_id
 * @property string|null $checksum
 * @property Carbon|null $last_synced_at
 */
class IntegrationMapping extends Model
{
    public const SISTEMA_WOO = 'woocommerce';

    protected $table = 'integration_mappings';

    /** @var list<string> */
    protected $fillable = [
        'entity_type', 'local_id', 'remote_system', 'remote_id',
        'checksum', 'last_synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    /**
     * O id local já mapeado para este id externo, se houver.
     *
     * É esta consulta que torna a carga re-executável: encontrando
     * mapeamento, atualiza-se o registro existente em vez de criar um
     * segundo (BR-706).
     */
    public static function localDe(string $tipo, string|int $remoteId): ?int
    {
        return static::query()
            ->where('remote_system', self::SISTEMA_WOO)
            ->where('entity_type', $tipo)
            ->where('remote_id', (string) $remoteId)
            ->value('local_id');
    }

    /**
     * O id externo de um registro local, se mapeado — o caminho inverso do
     * `localDe`. É a pergunta de toda sincronização de **saída**: "qual é o
     * id deste pedido no Woo, para eu devolver o status?".
     */
    public static function remoteDe(string $tipo, int $localId): ?string
    {
        return static::query()
            ->where('remote_system', self::SISTEMA_WOO)
            ->where('entity_type', $tipo)
            ->where('local_id', $localId)
            ->value('remote_id');
    }

    public static function registrar(string $tipo, int $localId, string|int $remoteId): void
    {
        static::query()->updateOrCreate(
            [
                'remote_system' => self::SISTEMA_WOO,
                'entity_type' => $tipo,
                'remote_id' => (string) $remoteId,
            ],
            [
                'local_id' => $localId,
                'last_synced_at' => now(),
            ],
        );
    }
}
