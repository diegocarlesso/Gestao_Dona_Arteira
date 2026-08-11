<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cliente bruto do WooCommerce, como veio — docs/17-Migracao F2.
 *
 * Sem invariante nenhuma de propósito, igual ao `StgWooProduct`: o staging
 * existe para receber o dado como ele é, inclusive errado (CPF que não
 * fecha, endereço pela metade, nome vazio). Quem julga é a triagem (F3).
 *
 * @property int $id
 * @property int $woo_id
 * @property string|null $email
 * @property string|null $first_name
 * @property string|null $last_name
 * @property int|null $orders_count
 * @property Carbon|null $woo_modified_at
 * @property array<string, mixed> $payload
 * @property string $status_triagem
 * @property string|null $motivo_triagem
 * @property string|null $nome_proposto
 * @property string|null $doc_proposto
 * @property int|null $import_batch
 * @property string|null $error
 */
class StgWooCustomer extends Model
{
    protected $table = 'stg_woo_customers';

    /** @var list<string> */
    protected $fillable = [
        'woo_id', 'email', 'first_name', 'last_name', 'orders_count',
        'woo_modified_at', 'payload', 'import_batch', 'error',

        // Colunas da triagem. Presentes no `$fillable` desde o primeiro
        // dia porque a ausência delas no `StgWooProduct` custou uma carga
        // silenciosamente vazia: o `create()` do teste descartou os campos
        // e nenhum produto entrou.
        'status_triagem', 'motivo_triagem', 'nome_proposto', 'doc_proposto',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'orders_count' => 'integer',
            'woo_modified_at' => 'datetime',
        ];
    }
}
