<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Events\CustomerRegistered;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;

/**
 * Cria ou atualiza um cliente — BR-001.
 *
 * Um Service para os dois casos porque a diferença entre cadastrar e
 * corrigir um cliente é nenhuma do ponto de vista das regras: o mesmo
 * documento precisa ser válido e único, e a mesma trilha de auditoria
 * precisa registrar quem mexeu. Separar em dois duplicaria a validação
 * para ganhar dois nomes.
 */
class SaveCustomerService
{
    public function __construct(private readonly ResolveIbgeCityCode $municipios) {}

    /**
     * @param  array<string, mixed>  $atributos
     * @param  list<array<string, mixed>>  $enderecos  substituem os atuais quando informados
     */
    public function handle(?Customer $cliente, array $atributos, array $enderecos = []): Customer
    {
        return DB::transaction(function () use ($cliente, $atributos, $enderecos): Customer {
            $novo = $cliente === null;

            if ($novo) {
                $cliente = new Customer;
                $cliente->origin = CustomerOrigin::Erp;
            }

            $cliente->fill($atributos)->save();

            if ($enderecos !== []) {
                $this->sincronizarEnderecos($cliente, $enderecos);
            }

            if ($novo) {
                CustomerRegistered::dispatch($cliente);
            }

            return $cliente;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $enderecos
     */
    private function sincronizarEnderecos(Customer $cliente, array $enderecos): void
    {
        $mantidos = [];

        foreach ($enderecos as $dados) {
            $publicId = is_string($dados['public_id'] ?? null) ? $dados['public_id'] : null;
            unset($dados['public_id']);

            $endereco = $publicId === null
                ? $cliente->addresses()->make()
                : $cliente->addresses()->where('public_id', $publicId)->first() ?? $cliente->addresses()->make();

            $endereco->fill($dados);
            $endereco->customer_id = $cliente->id;
            $this->resolverMunicipio($endereco, $dados);
            $endereco->save();

            $mantidos[] = $endereco->id;
        }

        // O que sumiu do formulário é apagado de verdade: endereço não tem
        // histórico próprio a preservar — o pedido guarda o endereço de
        // entrega em snapshot no momento da venda (mesmo princípio da
        // BR-302 para preço), então apagar aqui não reescreve pedido
        // nenhum.
        $cliente->addresses()->whereNotIn('id', $mantidos)->delete();
    }

    /**
     * Preenche o código IBGE do município quando o formulário não escolheu
     * um — ADR-0026.
     *
     * A ordem de precedência é uma regra só: **escolha explícita manda**.
     * Se a tela mandou um código, foi porque alguém abriu a busca de
     * municípios e clicou num deles — normalmente porque a grafia do
     * cadastro não bate com a do IBGE, que é exatamente o caso em que a
     * resolução automática não deve opinar. Sem escolha, tenta-se resolver
     * por `cidade + UF`; não achando, fica nulo, e nulo é pendência
     * visível, não erro.
     *
     * Vale só para este caminho (o formulário de cliente). A importação do
     * Woo não passa por aqui de propósito: os endereços que ela cria são
     * varridos depois pelo `erp:enderecos:resolver-ibge`, num lote que uma
     * pessoa revisa — em vez de resolverem-se um a um no meio de uma carga
     * de milhares de registros, onde ninguém leria o resultado.
     *
     * @param  array<string, mixed>  $dados
     */
    private function resolverMunicipio(CustomerAddress $endereco, array $dados): void
    {
        $escolhido = is_string($dados['city_code'] ?? null) ? trim($dados['city_code']) : '';

        if ($escolhido !== '') {
            return;
        }

        $endereco->city_code = $this->municipios->resolver($endereco->city, $endereco->state);
    }
}
