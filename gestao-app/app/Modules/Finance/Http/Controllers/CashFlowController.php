<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Services\GetCashFlowQuery;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fluxo de caixa — projetado × realizado, docs/12-Financeiro §3.
 */
class CashFlowController extends Controller
{
    public function index(GetCashFlowQuery $fluxo): Response
    {
        $this->authorize('viewAny', Payable::class);

        return Inertia::render('finance/cash-flow/index', [
            'fluxo' => $fluxo->handle(),
        ]);
    }
}
