<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\DTO\CustomerAddressSnapshot;
use App\Modules\Sales\DTO\CustomerSnapshot;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;
use App\Support\Documento;

/**
 * A porta pela qual os outros módulos perguntam a Vendas quem é um cliente
 * — ADR-0020, mesmo papel do `ProductLookupService` no Catálogo.
 *
 * Só leitura. Quem escreve é o `ImportCustomerService` (migração), o
 * `SaveCustomerService` (cadastro pela tela) ou o `ResolveCustomerService`
 * (pedido do site).
 *
 * Devolve DTOs, nunca `Customer`: o `arch()` verifica namespace e não
 * semântica, e um Service que devolvesse o model passaria no teste
 * enquanto vazava o acoplamento inteiro.
 */
class CustomerLookupService
{
    /**
     * O cliente já cadastrado que é **esta mesma pessoa**, se houver —
     * regra de dedupe da pasta 17 F3: **doc válido > e-mail > mais
     * recente**.
     *
     * A ordem não é arbitrária. O CPF identifica a pessoa; o e-mail
     * identifica uma caixa postal, que casal e família compartilham. Onde
     * os dois discordam, o documento decide — desde que feche no dígito
     * verificador, senão é só um número digitado errado, que vale menos
     * que o e-mail.
     *
     * O documento **inválido** ainda casa, mas por último: `customers.doc`
     * é único no banco, então dois cadastros com o mesmo CPF errado não
     * podem coexistir de qualquer forma, e descobrir isso como violação de
     * UNIQUE no meio da carga seria descobrir tarde.
     */
    public function idPorEmailOuDoc(?string $email, ?string $doc): ?int
    {
        $email = $this->normalizarEmail($email);
        $doc = $this->normalizarDoc($doc);

        if ($doc !== null && Documento::valido($doc)) {
            $porDoc = $this->idComDoc($doc);

            if ($porDoc !== null) {
                return $porDoc;
            }
        }

        if ($email !== null) {
            $porEmail = Customer::query()
                ->where('email', $email)
                // Mais recente entre homônimos de caixa postal: `email` não
                // é único no banco (é comum a família toda usar um só), e
                // sem ordem explícita a escolha seria a ordem física da
                // tabela — ou seja, sorteio.
                ->orderByDesc('id')
                ->value('id');

            if ($porEmail !== null) {
                return (int) $porEmail;
            }
        }

        return $doc !== null ? $this->idComDoc($doc) : null;
    }

    /**
     * Quem já ocupa este documento — **inclusive cadastro inativado**.
     *
     * `customers.doc` é único na tabela inteira, e o soft delete não libera
     * o número: a linha continua lá. Sem esta pergunta, gravar um CPF que
     * pertence a outro cadastro (vivo ou inativado) estouraria a carga com
     * violação de UNIQUE no meio do lote, com metade da base dentro.
     *
     * Com ela, quem chama descobre antes, deixa o documento em branco e
     * registra a pendência — o cliente e os endereços dele entram do mesmo
     * jeito, e um humano decide o que fazer com o número repetido, que é a
     * decisão certa e não é nossa.
     */
    public function donoDoDocumento(?string $doc): ?int
    {
        $doc = $this->normalizarDoc($doc);

        if ($doc === null) {
            return null;
        }

        $id = Customer::query()->withTrashed()->where('doc', $doc)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * O estado atual do cliente para a conferência da migração (F5).
     */
    public function snapshot(int $id): ?CustomerSnapshot
    {
        $cliente = Customer::query()->with('addresses')->find($id);

        if ($cliente === null) {
            return null;
        }

        return new CustomerSnapshot(
            name: $cliente->name,
            email: $cliente->email,
            doc: $cliente->doc,
            phone: $cliente->phone,
            type: $cliente->type,
            origin: $cliente->origin,
            isWholesale: $cliente->is_wholesale,
            enderecos: $cliente->addresses
                ->map(fn (CustomerAddress $e): CustomerAddressSnapshot => new CustomerAddressSnapshot(
                    zip: $e->zip,
                    street: $e->street,
                    number: $e->number,
                    complement: $e->complement,
                    district: $e->district,
                    city: $e->city,
                    state: $e->state,
                    isDefaultBilling: $e->is_default_billing,
                    isDefaultShipping: $e->is_default_shipping,
                ))
                ->values()
                ->all(),
        );
    }

    private function idComDoc(string $doc): ?int
    {
        $id = Customer::query()->where('doc', $doc)->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Minúsculas e sem espaço nas pontas.
     *
     * Mesmo comportamento do `ResolveCustomerService::normalizarEmail()`,
     * e isso é **de propósito, não descuido**: as duas portas resolvem a
     * mesma identidade (a do comprador do site) e normalizar diferente
     * faria a carga histórica achar "novo" um cliente que o pedido já
     * criara. A unificação num único ponto fica para quando o fluxo de
     * pedido for tocado — mexer nele agora, só por elegância, arriscaria o
     * caminho que está em produção.
     */
    private function normalizarEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return $email === '' ? null : $email;
    }

    /**
     * Só dígitos, como o cadastro guarda (BR-001) — a máscara é
     * apresentação. Feito aqui para poder **comparar** antes de gravar; o
     * `Customer::saving` repete a limpeza na escrita.
     */
    private function normalizarDoc(?string $doc): ?string
    {
        if ($doc === null) {
            return null;
        }

        $doc = preg_replace('/\D/', '', $doc) ?? '';

        return $doc === '' ? null : $doc;
    }
}
