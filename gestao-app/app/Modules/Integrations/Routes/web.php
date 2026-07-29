<?php

declare(strict_types=1);

use App\Modules\Integrations\WooCommerce\Http\Controllers\PanelController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Rotas do módulo Integrações (autenticadas)
|--------------------------------------------------------------------------
|
| O painel operacional. Mesma cadeia de middlewares dos demais módulos
| (ADR-0021). O webhook do site NÃO entra aqui — ele é público e assinado,
| e vive em webhooks.php, fora do grupo `web`.
|
*/

Route::middleware(['web', 'auth', 'conta.ativa', 'senha.trocada', '2fa.confirmado'])->group(function () {
    Route::get('integracoes', [PanelController::class, 'index'])->name('integrations.panel');
    Route::post('integracoes/eventos/{event}/reprocessar', [PanelController::class, 'reprocessar'])
        ->name('integrations.reprocessar');
});
