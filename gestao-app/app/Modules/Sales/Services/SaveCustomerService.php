<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Events\CustomerRegistered;
use App\Modules\Sales\Models\Customer;
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
}
