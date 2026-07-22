<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fronteiras entre módulos — ADR-0020
|--------------------------------------------------------------------------
|
| A superfície pública de um módulo são seus Services/ e seus Events/.
| Nada mais. Estas regras existem para que a fronteira seja um FATO
| verificado no CI, e não uma intenção registrada em documento.
|
| As regras entre módulos são geradas a partir dos diretórios que
| existem em app/Modules/ — nenhum módulo novo precisa ser lembrado
| aqui. Enquanto não houver módulos, este arquivo roda só as regras
| globais abaixo.
|
*/

$modulos = array_map(
    'basename',
    glob(__DIR__.'/../../app/Modules/*', GLOB_ONLYDIR) ?: []
);

foreach ($modulos as $modulo) {
    $internosDosOutros = [];

    foreach ($modulos as $outro) {
        if ($outro === $modulo) {
            continue;
        }

        foreach (['Models', 'Repositories', 'Http', 'Jobs', 'Policies', 'Listeners'] as $interno) {
            $internosDosOutros[] = "App\\Modules\\{$outro}\\{$interno}";
        }
    }

    if ($internosDosOutros === []) {
        continue;
    }

    arch("módulo {$modulo} só enxerga Services e Events dos outros módulos")
        ->expect("App\\Modules\\{$modulo}")
        ->not->toUse($internosDosOutros);
}

/*
|--------------------------------------------------------------------------
| Núcleo compartilhado
|--------------------------------------------------------------------------
*/

arch('Support não depende de módulo algum')
    ->expect('App\Support')
    ->not->toUse('App\Modules');

/*
|--------------------------------------------------------------------------
| Integrações (ADR-0009, ADR-0018)
|--------------------------------------------------------------------------
|
| Integrations pode depender dos Services dos módulos; nenhum módulo
| depende de Integrations — fala-se com a interface, o adapter é
| injetado. A regra acima já cobre os internos; esta cobre o namespace
| inteiro, que é mais restritivo.
|
*/

foreach (array_diff($modulos, ['Integrations']) as $modulo) {
    arch("módulo {$modulo} não depende de Integrations")
        ->expect("App\\Modules\\{$modulo}")
        ->not->toUse('App\Modules\Integrations');
}

/*
|--------------------------------------------------------------------------
| Higiene geral
|--------------------------------------------------------------------------
*/

arch('nenhuma função de depuração sobrevive ao commit')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'ray', 'die', 'exit'])
    ->not->toBeUsed();

arch('todo o código de aplicação declara strict_types')
    ->expect('App')
    ->toUseStrictTypes();
