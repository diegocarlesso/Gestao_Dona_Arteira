<?php

declare(strict_types=1);

use App\Modules\Integrations\MercadoPago\Http\Controllers\MercadoPagoWebhookController;
use App\Modules\Integrations\WooCommerce\Http\Controllers\WooWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhooks de integração — docs/16 §4, ADR-0007
|--------------------------------------------------------------------------
|
| Fora do grupo `web` de propósito: quem posta aqui é servidor (o
| WooCommerce), não navegador. Logo sem CSRF e sem sessão — a autenticação
| é a assinatura HMAC conferida no controller (BR-701), não o cookie.
|
| Prefixo próprio para o endereço ser estável e fácil de configurar no
| painel do Woo.
|
*/

Route::post('webhooks/woocommerce/orders', WooWebhookController::class)
    ->name('integrations.woo.orders');

// Painel do Mercado Pago → Integrações → Webhooks. Mesmo raciocínio do
// webhook do Woo: sem CSRF/sessão, autenticado por `x-signature` (ADR-0018 §3.1).
Route::post('webhooks/mercado-pago', MercadoPagoWebhookController::class)
    ->name('integrations.mercadopago.webhook');
