<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| O menu aponta para rota que existe
|--------------------------------------------------------------------------
|
| A sidebar guarda URLs em texto (`url: '/estoque/contagens'`), e texto
| não quebra quando a rota é renomeada ou removida: o item continua lá,
| bonito, levando a um 404. É o tipo de defeito que só aparece quando
| alguém clica — e ninguém clica no item que já sabe estar quebrado.
|
| O teste lê o arquivo do menu e confere cada URL contra a tabela de
| rotas de verdade. Não substitui o cuidado inverso — tela sem item de
| menu não existe para quem opera, e isso é revisão humana —, mas cobre
| a metade que é verificável.
|
*/

/**
 * @return list<string>
 */
function urlsDoMenu(): array
{
    $arquivo = resource_path('js/components/app-sidebar.tsx');

    expect(file_exists($arquivo))->toBeTrue();

    preg_match_all("/url:\s*'([^']+)'/", (string) file_get_contents($arquivo), $achados);

    return $achados[1];
}

it('leva a algum lugar em cada item do menu', function () {
    $urls = urlsDoMenu();

    // Se a extração devolver vazio, o formato do arquivo mudou e o teste
    // passaria sem conferir nada — falso verde é pior que falha.
    expect($urls)->not->toBeEmpty();

    $rotas = collect(Route::getRoutes()->getRoutesByMethod()['GET'] ?? [])
        ->keys()
        ->map(fn (string $uri): string => '/'.ltrim($uri, '/'))
        ->all();

    foreach ($urls as $url) {
        // `assertContains` do PHPUnit, e não `expect()->toContain()`: o
        // segundo argumento do Pest é mais um valor esperado, não a
        // mensagem — e sem a mensagem a falha diz "array não contém
        // string" sem dizer qual item do menu está quebrado.
        $this->assertContains($url, $rotas, "O menu aponta para {$url}, que não é rota GET registrada.");
    }
});
