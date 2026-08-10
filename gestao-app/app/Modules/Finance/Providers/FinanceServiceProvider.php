<?php

declare(strict_types=1);

namespace App\Modules\Finance\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Provider do módulo Financeiro.
 *
 * Nasce vazio de propósito. Nesta fatia (nota de escopo da pasta 12) o
 * Financeiro não tem tela, rota nem policy própria: quem mostra o título é
 * o Compras, na tela do pedido de compra. O provider existe desde já
 * porque a pasta 05 §2 pede um por módulo — e porque as rotas, as policies
 * (baixa, estorno) e o binding do gateway de cobrança do Gate 04 têm de
 * ter um lugar óbvio para chegar, em vez de vazarem para o `AppServiceProvider`.
 */
class FinanceServiceProvider extends ServiceProvider
{
    //
}
