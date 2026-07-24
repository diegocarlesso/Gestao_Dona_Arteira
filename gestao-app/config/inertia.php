<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Onde vivem as páginas
    |--------------------------------------------------------------------------
    |
    | O padrão do pacote é `resources/js/Pages` — com P maiúsculo. O
    | starter kit do Laravel 12 usa `resources/js/pages`, minúsculo, e é
    | esse o caminho que o resolvedor de `app.tsx` enxerga.
    |
    | Corrigir aqui não é preciosismo: o `assertInertia` valida que o
    | arquivo da página existe, e a divergência só aparece em sistema de
    | arquivos que diferencia maiúsculas. No Windows do desenvolvimento
    | passa; no Linux do CI e da produção, não. Foi assim que apareceu —
    | três testes verdes localmente reprovaram no CI.
    |
    */

    'page_paths' => [
        resource_path('js/pages'),
    ],

    'page_extensions' => [
        'js',
        'jsx',
        'svelte',
        'ts',
        'tsx',
        'vue',
    ],

    'testing' => [

        /*
         | `true` (e não o `false` do pacote): se o controller renderizar
         | uma página que não existe, o teste precisa reprovar. Sem isso,
         | um erro de digitação no nome do componente passaria pela suíte
         | e só apareceria como tela em branco para o usuário.
         */
        'ensure_pages_exist' => true,

        'page_paths' => [
            resource_path('js/pages'),
        ],

        'page_extensions' => [
            'js',
            'jsx',
            'svelte',
            'ts',
            'tsx',
            'vue',
        ],

    ],

];
