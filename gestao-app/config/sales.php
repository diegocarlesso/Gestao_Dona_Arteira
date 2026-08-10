<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Reserva de estoque na confirmação do pedido (BR-203)
    |--------------------------------------------------------------------------
    |
    | Confirmar um pedido reserva o disponível de cada item. Desligar isto
    | serve só para validar o fluxo de pedidos antes de existir contagem
    | física de verdade (ex.: testes no site pré-cutover) — o pedido
    | confirma sem checar nem mexer em saldo algum. Ninguém decide isso
    | por acidente: o padrão é `true` (a regra vale), e ligar `false` é
    | ação explícita no `.env`, igual a `WOO_ENABLED`/`NFE_ENABLED`.
    |
    | RELIGAR antes do cutover real com o cliente — com isto desligado,
    | o disponível publicado no site (BR-204) e a proteção contra oversell
    | não têm o que proteger.
    |
    */

    'require_stock_reservation' => (bool) env('SALES_REQUIRE_STOCK_RESERVATION', true),

];
