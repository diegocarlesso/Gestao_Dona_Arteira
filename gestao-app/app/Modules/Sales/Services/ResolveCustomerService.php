<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Events\CustomerRegistered;
use App\Modules\Sales\Models\Customer;

/**
 * Acha o cliente de um pedido de canal, ou o cria — dedupe do de-para (16).
 *
 * A superfície que a Integração chama para não conhecer `Customer` por
 * dentro (ADR-0020): "este pedido do site é de fulano (e-mail, doc); me
 * devolve o cliente". Deduplica por **e-mail primeiro, documento depois**
 * — a ordem do de-para da pasta 16 —, para o mesmo comprador não virar
 * dois cadastros a cada compra.
 *
 * **Não corrige o cadastro existente com o dado do Woo.** Se o cliente já
 * existe, volta como está: a direção de correção cadastral é ERP→Woo
 * (docs/16 §3), não o contrário. Aqui só se resolve identidade.
 *
 * Devolve o **id**, não o model: quem chama de fora (a Integração) não
 * pode enxergar `Sales\Models` (ADR-0020).
 */
class ResolveCustomerService
{
    public function paraCanal(
        ?string $name,
        ?string $email,
        ?string $doc,
        ?string $phone = null,
    ): int {
        $email = $this->normalizarEmail($email);
        $doc = $this->normalizarDoc($doc);

        $existente = $this->deduplicar($email, $doc);

        if ($existente !== null) {
            return $existente->id;
        }

        // Documento com 14 dígitos é CNPJ → PJ; o resto (inclusive sem
        // documento, o caso do convidado) entra como PF, que é 100% do
        // histórico do site (ver CustomerType).
        $tipo = $doc !== null && mb_strlen($doc) === 14
            ? CustomerType::Company
            : CustomerType::Individual;

        $cliente = new Customer;
        $cliente->origin = CustomerOrigin::Woo;
        $cliente->type = $tipo;
        // Convidado sem nome não trava a importação — o pedido precisa de um
        // cliente, e a lacuna aparece na pendência do cadastro, não aqui.
        $cliente->name = $name !== null && trim($name) !== '' ? trim($name) : 'Cliente do site';
        $cliente->email = $email;
        $cliente->doc = $doc;
        $cliente->phone = $phone;
        $cliente->save();

        CustomerRegistered::dispatch($cliente);

        return $cliente->id;
    }

    private function deduplicar(?string $email, ?string $doc): ?Customer
    {
        if ($email !== null) {
            $porEmail = Customer::query()->where('email', $email)->first();

            if ($porEmail !== null) {
                return $porEmail;
            }
        }

        if ($doc !== null) {
            return Customer::query()->where('doc', $doc)->first();
        }

        return null;
    }

    /**
     * Minúsculas e sem espaço nas pontas — o e-mail é chave de dedupe, e
     * `Ana@X.com` e `ana@x.com` são a mesma pessoa. Normalizar dos dois
     * lados (aqui e ao gravar) garante que a busca ache, mesmo no SQLite
     * dos testes, cuja comparação de texto é sensível a caixa.
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
     * apresentação. O `Customer::saving` repete a limpeza; fazê-la aqui é
     * o que permite **comparar** antes de gravar.
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
