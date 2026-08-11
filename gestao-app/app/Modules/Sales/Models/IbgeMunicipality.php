<?php

declare(strict_types=1);

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Um município brasileiro, como o IBGE o publica (ADR-0026).
 *
 * Dado de referência puro: existe para traduzir cidade + UF no `cMun` da
 * NF-e. Não tem regra de negócio, não tem ciclo de vida, ninguém cadastra
 * — quem escreve é o `IbgeMunicipalitySeeder`, a partir do arquivo
 * versionado com a resposta oficial do IBGE.
 *
 * Mora em Sales porque seu único consumidor é `CustomerAddress`. O
 * ADR-0020 não prevê "Support com tabela" (lá só vivem value objects sem
 * estado), e o Fiscal consome o código já resolvido no snapshot do pedido,
 * não esta tabela.
 *
 * Sem auditoria (`Auditable`) de propósito: auditoria registra o que
 * pessoas fizeram com dados do negócio. 5.571 linhas escritas por um
 * seeder a cada deploy só encheriam a trilha de ruído.
 *
 * @property int $id
 * @property string $ibge_code Os 7 dígitos oficiais — o `cMun` da NF-e.
 * @property string $uf
 * @property string $name Grafia oficial do IBGE, com acentos.
 * @property string $name_normalized Caixa alta e sem acento. Coluna GERADA pelo banco: somente leitura.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class IbgeMunicipality extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'ibge_code',
        'uf',
        'name',
    ];

    /**
     * `name_normalized` está fora do `$fillable` porque o banco a calcula:
     * o MariaDB recusa um INSERT que traga valor para coluna gerada.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ibge_code' => 'string',
            'uf' => 'string',
            'name' => 'string',
            'name_normalized' => 'string',
        ];
    }

    /**
     * O código do IBGE é a identidade pública do município — por isso esta
     * tabela não carrega `public_id` (ver a migration). Sem isto, uma rota
     * que receba um município exporia o autoincremento.
     */
    public function getRouteKeyName(): string
    {
        return 'ibge_code';
    }
}
