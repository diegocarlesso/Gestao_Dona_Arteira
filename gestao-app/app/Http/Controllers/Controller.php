<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base dos controllers do ERP.
 *
 * O Laravel 12 entrega esta classe vazia — `AuthorizesRequests` deixou
 * de vir por padrão, e sem ele `$this->authorize()` é método inexistente.
 * Num sistema cujo acesso é negado por padrão (BR-801), o trait é
 * infraestrutura obrigatória, não conveniência: todo controller autoriza
 * antes de agir (ADR-0015).
 */
abstract class Controller
{
    use AuthorizesRequests;
}
