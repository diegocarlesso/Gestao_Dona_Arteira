<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Caminhos com maiúsculas erradas
|--------------------------------------------------------------------------
|
| O desenvolvimento roda em Windows, cujo sistema de arquivos NÃO
| diferencia maiúsculas de minúsculas. O CI e a produção rodam em Linux,
| que diferencia. Um caminho escrito com a caixa errada passa localmente
| e reprova — ou pior, quebra em produção sem ter reprovado em lugar
| nenhum.
|
| Aconteceu: `config/inertia.php` não estava publicado, e o padrão do
| pacote aponta para `resources/js/Pages` (P maiúsculo) enquanto o
| diretório real é `pages`. Três testes passaram no Windows e reprovaram
| no CI.
|
| Estes testes comparam os nomes das entradas do diretório LITERALMENTE,
| em vez de perguntar ao sistema de arquivos se o caminho existe — por
| isso funcionam nas duas plataformas.
|
*/

/**
 * O caminho existe com exatamente esta caixa?
 *
 * `is_dir()` responderia "sim" no Windows para qualquer variação de
 * maiúsculas. Comparar contra o que o `scandir` do diretório-pai devolve
 * é o que torna a checagem honesta.
 */
function existeComACaixaExata(string $caminhoAbsoluto): bool
{
    $pai = dirname($caminhoAbsoluto);
    $nome = basename($caminhoAbsoluto);

    if (! is_dir($pai)) {
        return false;
    }

    return in_array($nome, scandir($pai) ?: [], strict: true);
}

it('aponta os page_paths do Inertia para um diretório que existe com a mesma caixa', function () {
    $caminhos = [
        ...config('inertia.page_paths', []),
        ...config('inertia.testing.page_paths', []),
    ];

    expect($caminhos)->not->toBeEmpty(
        'config/inertia.php precisa estar publicado — o padrão do pacote aponta para js/Pages'
    );

    foreach ($caminhos as $caminho) {
        expect(existeComACaixaExata($caminho))->toBeTrue(
            "o caminho configurado [{$caminho}] não existe com esta caixa — passa no Windows, reprova no Linux"
        );
    }
});

it('reprova se o controller renderizar uma página inexistente', function () {
    // Sem isto, um erro de digitação no nome do componente atravessaria
    // a suíte inteira e viraria tela em branco para o usuário.
    expect(config('inertia.testing.ensure_pages_exist'))->toBeTrue();
});

it('mantém as páginas Inertia em minúsculas, como o resolvedor de app.tsx espera', function () {
    $raiz = resource_path('js/pages');

    $diretorio = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS)
    );

    $forasDoPadrao = [];

    foreach ($diretorio as $arquivo) {
        /** @var SplFileInfo $arquivo */
        $relativo = str_replace('\\', '/', substr($arquivo->getPathname(), strlen($raiz) + 1));

        // Páginas e pastas em kebab-case minúsculo. Um `Pages/Users.tsx`
        // no meio de `pages/users/` é o começo da divergência.
        if (preg_match('/[A-Z]/', $relativo) === 1) {
            $forasDoPadrao[] = $relativo;
        }
    }

    expect($forasDoPadrao)->toBeEmpty();
});
