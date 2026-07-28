<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Integrações externas — docs/15-Integracoes
|--------------------------------------------------------------------------
|
| Uma chave por sistema, todas com `enabled` (princípio 7 da pasta 15:
| feature flag por integração, para ligar/desligar sem deploy).
|
| Credenciais vêm do `.env` e **nunca** do repositório (princípio 8).
|
*/

return [

    'woocommerce' => [
        // Desligada por padrão. Uma integração que nasce ligada é uma
        // integração que dispara contra produção no primeiro deploy de
        // quem só queria rodar os testes.
        'enabled' => (bool) env('WOO_ENABLED', false),

        // A raiz do site, sem `/wp-json`. O client monta o caminho.
        'url' => env('WOO_URL'),

        // Geradas em WooCommerce → Configurações → Avançado → REST API.
        // Leitura basta para a extração; a escrita só é necessária na
        // sincronização de volta (pasta 16, Gate 02).
        'key' => env('WOO_KEY'),
        'secret' => env('WOO_SECRET'),

        // Segredo do webhook (WooCommerce → Configurações → Avançado →
        // Webhooks → "Chave secreta"). **Não** é o `secret` da REST API:
        // um assina o corpo do POST que o site envia (HMAC-SHA256, BR-701),
        // o outro autentica as chamadas que o ERP faz. Sem ele, a entrada
        // por webhook recusa tudo — de propósito, um webhook sem assinatura
        // conferível é uma porta aberta para qualquer um criar pedido.
        'webhook_secret' => env('WOO_WEBHOOK_SECRET'),

        // A API do Woo aceita até 100; o inventário da pasta 31 conta 716
        // produtos, então são 8 páginas. Lotes pequenos e retomáveis por
        // causa dos limites do shared hosting (pasta 03).
        'per_page' => (int) env('WOO_PER_PAGE', 50),

        // Site em hospedagem compartilhada responde devagar quando o
        // catálogo é grande; 30 s evita cortar uma página quase pronta.
        'timeout' => (int) env('WOO_TIMEOUT', 30),

        // Trava contra endpoint que ignora `page` e devolve sempre a mesma
        // resposta — o laço pararia só ao estourar a memória. Com 716
        // produtos são ~15 páginas; mil é folga com fim.
        'max_pages' => (int) env('WOO_MAX_PAGES', 1000),
    ],

];
