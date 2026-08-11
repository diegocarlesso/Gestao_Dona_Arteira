<?php

declare(strict_types=1);

use App\Modules\Sales\Models\CustomerAddress;
use App\Modules\Sales\Models\IbgeMunicipality;
use Database\Seeders\Sales\IbgeMunicipalitySeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Tabela de referência dos municípios do IBGE — ADR-0026
|--------------------------------------------------------------------------
|
| Isto é dado fiscal: o código daqui vai para o `cMun` da NF-e. Um valor
| errado não quebra nada visivelmente — produz uma nota autorizada com o
| município do destinatário incorreto, que é o pior tipo de falha, porque
| ninguém percebe. Daí a suíte conferir o conteúdo, e não só o esquema.
|
*/

/**
 * O mesmo mapa de acentos da migration, em PHP.
 *
 * A resolução automática vai normalizar o que o usuário digitou com uma
 * função como esta e comparar com `name_normalized`. Se as duas pontas
 * divergirem em um único caractere, o casamento silenciosamente para de
 * acontecer para aquele município. O teste logo abaixo compara as duas
 * implementações nas 5.571 linhas — é ele que trava esse desvio.
 */
function normalizarNomeDeMunicipio(string $nome): string
{
    return strtr(mb_strtoupper($nome, 'UTF-8'), [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
        'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
    ]);
}

beforeEach(function () {
    $this->seed(IbgeMunicipalitySeeder::class);
});

it('carrega os municípios do arquivo oficial do IBGE', function () {
    $arquivo = json_decode(
        (string) file_get_contents(database_path('seeders/data/ibge_municipios.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(IbgeMunicipality::query()->count())->toBe($arquivo['_total'])
        ->and(IbgeMunicipality::query()->distinct()->count('uf'))->toBe(27);
});

it('guarda o código correto das cidades conferidas contra a fonte oficial', function (string $nome, string $uf, string $codigo) {
    $municipio = IbgeMunicipality::query()->where('uf', $uf)->where('name', $nome)->first();

    expect($municipio)->not->toBeNull("{$nome}/{$uf} não está na tabela")
        ->and($municipio->ibge_code)->toBe($codigo);
})->with([
    // Capitais de conferência — valores públicos e verificáveis.
    ['São Paulo', 'SP', '3550308'],
    ['Rio de Janeiro', 'RJ', '3304557'],
    ['Porto Alegre', 'RS', '4314902'],
    ['Curitiba', 'PR', '4106902'],
    // A cidade da própria Dona Arteira (docs/31-Inventario-Legado/01).
    ['Jacutinga', 'RS', '4310900'],
]);

it('distingue municípios homônimos em UFs diferentes', function () {
    // Existem duas Jacutingas, e uma delas é a da loja. Se a resolução
    // automática casasse só por nome, metade da base de clientes — que é
    // majoritariamente do entorno de Jacutinga/RS — poderia receber o
    // código de Minas Gerais. É o caso que justifica a chave ser (uf, nome).
    $porUf = IbgeMunicipality::query()
        ->where('name', 'Jacutinga')
        ->pluck('ibge_code', 'uf');

    expect($porUf->toArray())->toBe(['MG' => '3134905', 'RS' => '4310900']);
});

it('normaliza o nome no banco exatamente como o PHP normalizaria', function () {
    // Sem esta garantia, a coluna gerada e o normalizador da aplicação
    // seriam dois algoritmos que apenas parecem concordar.
    $divergentes = IbgeMunicipality::query()
        ->get(['name', 'name_normalized'])
        ->filter(fn (IbgeMunicipality $m): bool => $m->name_normalized !== normalizarNomeDeMunicipio($m->name))
        ->map(fn (IbgeMunicipality $m): string => "{$m->name} => banco[{$m->name_normalized}] php[".normalizarNomeDeMunicipio($m->name).']')
        ->all();

    expect($divergentes)->toBe([]);
});

it('deixa todo nome normalizado sem acento e em caixa alta', function () {
    $comAcento = IbgeMunicipality::query()
        ->whereRaw('name_normalized <> CONVERT(name_normalized USING ASCII)')
        ->pluck('name_normalized')
        ->all();

    expect($comAcento)->toBe([]);
});

it('mantém (uf + nome normalizado) único, para o casamento nunca ser ambíguo', function () {
    $duplicados = DB::table('ibge_municipalities')
        ->select('uf', 'name_normalized')
        ->groupBy('uf', 'name_normalized')
        ->havingRaw('COUNT(*) > 1')
        ->get();

    expect($duplicados)->toHaveCount(0);
});

it('recalcula o nome normalizado sozinho, sem a aplicação escrever nada', function () {
    $municipio = IbgeMunicipality::query()->create([
        'ibge_code' => '9999999',
        'uf' => 'SP',
        'name' => "Sant'Ana do Coração",
    ]);

    expect($municipio->refresh()->name_normalized)->toBe("SANT'ANA DO CORACAO");
});

it('roda de novo sem duplicar nem sujar as linhas existentes', function () {
    $antes = DB::table('ibge_municipalities')
        ->orderBy('ibge_code')
        ->get(['ibge_code', 'uf', 'name', 'created_at', 'updated_at']);

    $this->seed(IbgeMunicipalitySeeder::class);

    $depois = DB::table('ibge_municipalities')
        ->orderBy('ibge_code')
        ->get(['ibge_code', 'uf', 'name', 'created_at', 'updated_at']);

    // Não basta "não duplicou": o reseed também não pode reescrever
    // `updated_at` de 5.571 linhas a cada deploy.
    expect($depois->toArray())->toEqual($antes->toArray());
});

it('recusa um código IBGE que não existe na tabela de referência', function () {
    // A FK é o que impede a digitação manual de virar chute — o risco que
    // fez o ADR-0026 descartar "Alternativa A" como caminho único.
    //
    // Parte de um endereço já gravado e mexe só em `city_code`: assim a
    // única coisa que pode fazer o UPDATE falhar é a constraint. Montar um
    // INSERT cru daria um erro de coluna obrigatória e o teste passaria
    // sem nunca ter exercitado a FK.
    $endereco = CustomerAddress::factory()->create();

    expect(fn () => DB::table('customer_addresses')
        ->where('id', $endereco->id)
        ->update(['city_code' => '0000000']))
        ->toThrow(QueryException::class);

    // E aceita um código que existe.
    DB::table('customer_addresses')
        ->where('id', $endereco->id)
        ->update(['city_code' => '4310900']);

    expect($endereco->refresh()->city_code)->toBe('4310900');
});

it('aceita endereço sem código, que é como todos nascem', function () {
    $endereco = CustomerAddress::factory()->create();

    expect($endereco->city_code)->toBeNull();
});
