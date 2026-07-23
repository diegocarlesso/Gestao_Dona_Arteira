<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Conta admin inicial
    |--------------------------------------------------------------------------
    |
    | Usada apenas pelo AdminInicialSeeder, na primeira carga de um banco
    | vazio (docs/18-Usuarios/README.md §5). Sem ela não haveria por onde
    | entrar num ambiente recém-migrado.
    |
    | A senha fica FORA do código: ou vem de ADMIN_INICIAL_SENHA, ou é
    | sorteada e impressa uma única vez pelo seeder. Em qualquer caso a
    | conta nasce exigindo troca no primeiro acesso.
    |
    | Lido daqui e não de env() direto porque `config:cache` — padrão em
    | produção — faz env() devolver null fora dos arquivos de config.
    |
    */

    'admin_inicial' => [
        'nome' => env('ADMIN_INICIAL_NOME', 'Administrador'),
        'email' => env('ADMIN_INICIAL_EMAIL', 'admin@donaarteira.com.br'),
        'senha' => env('ADMIN_INICIAL_SENHA'),
    ],

];
