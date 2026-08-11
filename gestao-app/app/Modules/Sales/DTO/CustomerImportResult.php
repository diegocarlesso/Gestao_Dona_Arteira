<?php

declare(strict_types=1);

namespace App\Modules\Sales\DTO;

/**
 * O que a carga histórica fez com um cliente — docs/17-Migracao F4.
 *
 * Devolve mais que "id + criou?" porque a migração de clientes **encontra
 * cadastro existente na maioria dos casos**: o site já cria cliente no ERP
 * a cada pedido importado. O relatório precisa distinguir "criei" de
 * "completei o que faltava" de "achei diferença e não mexi" — sem isso, a
 * operação vê um número redondo e não sabe o que aconteceu com a base
 * dela.
 */
final readonly class CustomerImportResult
{
    /**
     * @param  int  $id  id local do cliente — **zero na previsão**, quando o
     *                   cliente ainda não existe
     * @param  bool  $criado  se nasceu agora
     * @param  list<string>  $completados  campos que estavam vazios e foram preenchidos
     * @param  list<string>  $conflitos  campos com valor local diferente do da origem, preservados
     * @param  int  $enderecosNovos  endereços que passam a existir (zero na
     *                               segunda rodada — é o que prova a
     *                               idempotência no relatório)
     */
    public function __construct(
        public int $id,
        public bool $criado,
        public array $completados = [],
        public array $conflitos = [],
        public int $enderecosNovos = 0,
    ) {}
}
