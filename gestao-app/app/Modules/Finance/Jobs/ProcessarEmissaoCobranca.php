<?php

declare(strict_types=1);

namespace App\Modules\Finance\Jobs;

use App\Modules\Finance\Services\EmitirCobrancaService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Registra a cobrança no provedor, fora da requisição — ADR-0007, BR-705.
 * Mesmo desenho do `EmitNfeForOrder` (Fiscal): recebe o `public_id`, nunca
 * o Model, e deixa toda a lógica de negócio em `EmitirCobrancaService::
 * processar()`.
 */
class ProcessarEmissaoCobranca implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Cinco tentativas — mesmo perfil do `ProcessWooOrder`/`EmitNfeForOrder`. */
    public int $tries = 5;

    public function __construct(public string $chargePublicId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(EmitirCobrancaService $service): void
    {
        $service->processar($this->chargePublicId);
    }
}
