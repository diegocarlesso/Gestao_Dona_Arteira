<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Queries\GetDashboardMetricsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(Request $request, GetDashboardMetricsQuery $query): Response
    {
        return Inertia::render('dashboard', [
            'metrics' => $query->execute(),
        ]);
    }
}
