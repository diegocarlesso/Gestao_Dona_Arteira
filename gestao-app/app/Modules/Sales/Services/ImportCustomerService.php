<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Modules\Sales\DTO\CustomerImportResult;
use App\Modules\Sales\Enums\CustomerOrigin;
use App\Modules\Sales\Enums\CustomerType;
use App\Modules\Sales\Events\CustomerRegistered;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerAddress;

/**
 * Cria ou **enriquece** um cliente vindo da migração histórica — docs/17 F4.
 *
 * Existe para que a migração não construa `Customer` por fora: o ADR-0020
 * diz que a superfície pública de um módulo são seus Services, e as
 * invariantes do cliente (documento só com dígitos, ULID público, trilha
 * de auditoria da LGPD) moram no model. Mesmo papel do
 * `ImportProductService` no Catálogo.
 *
 * ## Por que não é o `ResolveCustomerService`
 *
 * Aquele resolve **identidade** para um pedido do site: acha ou cria, e
 * nunca mexe em cadastro existente — "a direção de correção cadastral é
 * ERP→Woo". Este resolve **completude**: chega depois, com o cadastro
 * inteiro do site na mão (inclusive os endereços, que o outro nunca grava),
 * e o propósito da migração é justamente trazer o que falta.
 *
 * ## Completa lacuna, não sobrescreve decisão
 *
 * Campo vazio no ERP recebe o valor do site. Campo **já preenchido** fica
 * como está, e a diferença vai para o relatório em vez de sumir. É a
 * regra 5 do projeto — "conflito de dados resolve-se a favor do ERP" — e é
 * o que protege a correção que alguém da operação já tenha feito à mão: a
 * base de clientes do ERP não nasceu vazia, ela vem sendo alimentada pelos
 * pedidos do site desde o Gate 02.
 *
 * A única exceção é o nome-recuo `Cliente do site`, que o
 * `ResolveCustomerService` grava quando o comprador chega sem nome. Ele é
 * literalmente a marca de "não sabemos" — preservá-lo diante do nome real
 * seria preservar a lacuna, não a decisão.
 *
 * Idempotente por `$localId` (BR-706): recebendo um id existente, atualiza.
 */
class ImportCustomerService
{
    /** O texto que o pedido do site grava quando o comprador não tem nome. */
    private const NOME_RECUO = 'Cliente do site';

    /**
     * @param  int|null  $localId  id do cliente já mapeado/casado, se houver
     * @param  list<array<string, mixed>>  $enderecos  formato em `atributosDoEndereco()`
     */
    public function importar(
        ?int $localId,
        string $name,
        ?string $email = null,
        ?string $doc = null,
        ?string $phone = null,
        array $enderecos = [],
    ): CustomerImportResult {
        return $this->aplicar(true, $localId, $name, $email, $doc, $phone, $enderecos);
    }

    /**
     * O que a carga **faria**, sem gravar nada — o `--dry-run` da pasta 17
     * (princípio 3).
     *
     * Roda exatamente o mesmo caminho de decisão da carga real, só que sem
     * `save()`. Não é elegância: um dry-run que reimplementasse as regras
     * prometeria uma coisa e faria outra no dia seguinte, e é justamente
     * esse relatório que o dono lê antes de autorizar a carga de dados
     * pessoais de gente de verdade.
     *
     * @param  list<array<string, mixed>>  $enderecos
     */
    public function prever(
        ?int $localId,
        string $name,
        ?string $email = null,
        ?string $doc = null,
        ?string $phone = null,
        array $enderecos = [],
    ): CustomerImportResult {
        return $this->aplicar(false, $localId, $name, $email, $doc, $phone, $enderecos);
    }

    /**
     * @param  list<array<string, mixed>>  $enderecos
     */
    private function aplicar(
        bool $gravar,
        ?int $localId,
        string $name,
        ?string $email,
        ?string $doc,
        ?string $phone,
        array $enderecos,
    ): CustomerImportResult {
        $email = $this->normalizarEmail($email);
        $doc = $this->normalizarDoc($doc);
        $name = trim($name) !== '' ? mb_substr(trim($name), 0, 255) : self::NOME_RECUO;

        $cliente = $localId !== null ? Customer::query()->find($localId) : null;

        if ($cliente === null) {
            return $this->criar($gravar, $name, $email, $doc, $phone, $enderecos);
        }

        [$completados, $conflitos] = $this->completar($cliente, $name, $email, $doc, $phone, $gravar);

        return new CustomerImportResult(
            id: (int) $cliente->getKey(),
            criado: false,
            completados: $completados,
            conflitos: $conflitos,
            enderecosNovos: $this->sincronizarEnderecos($cliente, $enderecos, $gravar),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $enderecos
     */
    private function criar(
        bool $gravar,
        string $name,
        ?string $email,
        ?string $doc,
        ?string $phone,
        array $enderecos,
    ): CustomerImportResult {
        if (! $gravar) {
            // Na previsão o cliente ainda não existe, então não há id — e
            // todos os endereços da origem seriam novos, porque não há
            // cadastro com o qual compará-los.
            return new CustomerImportResult(id: 0, criado: true, enderecosNovos: count($enderecos));
        }

        $cliente = new Customer;
        $cliente->origin = CustomerOrigin::Woo;
        // O canal é 100% varejo/PF: a pasta 31 §3 mediu 85 pedidos, todos
        // pessoa física, nenhum CNPJ. O atacado acontece fora do site e não
        // tem fonte de dados nenhuma.
        $cliente->is_wholesale = false;
        $cliente->type = $this->tipoPara($doc);
        $cliente->name = $name;
        $cliente->email = $email;
        $cliente->doc = $doc;
        $cliente->phone = $phone;
        $cliente->save();

        // Mesmo evento que o pedido do site dispara ao criar cliente — um
        // cliente novo é um cliente novo, seja qual for a porta. Quem for
        // reagir a ele para empurrar cadastro ao Woo já precisa olhar o
        // `origin`, para não devolver ao site o que veio dele.
        CustomerRegistered::dispatch($cliente);

        return new CustomerImportResult(
            id: (int) $cliente->getKey(),
            criado: true,
            enderecosNovos: $this->sincronizarEnderecos($cliente, $enderecos, true),
        );
    }

    /**
     * Preenche o que está vazio; anota o que diverge sem tocar.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function completar(
        Customer $cliente,
        string $name,
        ?string $email,
        ?string $doc,
        ?string $phone,
        bool $gravar,
    ): array {
        $completados = [];
        $conflitos = [];

        // O nome-recuo conta como lacuna: é a marca de "não sabemos".
        $nomeAtual = trim($cliente->name);
        $nomeVazio = $nomeAtual === '' || $nomeAtual === self::NOME_RECUO;

        if ($name !== self::NOME_RECUO) {
            if ($nomeVazio) {
                $cliente->name = $name;
                $completados[] = 'nome';
            } elseif ($nomeAtual !== $name) {
                $conflitos[] = "nome: ERP “{$nomeAtual}” × site “{$name}”";
            }
        }

        foreach (['email' => $email, 'phone' => $phone] as $campo => $valor) {
            if ($valor === null) {
                continue;
            }

            $atual = $cliente->{$campo};

            if ($atual === null || trim((string) $atual) === '') {
                $cliente->{$campo} = $valor;
                $completados[] = $campo;
            } elseif ((string) $atual !== $valor) {
                $conflitos[] = "{$campo}: ERP “{$atual}” × site “{$valor}”";
            }
        }

        if ($doc !== null) {
            if ($cliente->doc === null || $cliente->doc === '') {
                $cliente->doc = $doc;
                $completados[] = 'doc';

                // O tipo acompanha o documento que acabou de chegar: um
                // cadastro que era "física por falta de documento" e recebe
                // 14 dígitos é jurídica. Sem isso, o cliente ficaria PF com
                // CNPJ, e a NF-e sairia com o destinatário errado.
                $cliente->type = $this->tipoPara($doc);
            } elseif ($cliente->doc !== $doc) {
                $conflitos[] = "doc: ERP “{$cliente->doc}” × site “{$doc}”";
            }
        }

        if ($gravar && $completados !== []) {
            $cliente->save();
        }

        return [$completados, $conflitos];
    }

    /**
     * Grava os endereços do site, sem repetir os que já estão lá.
     *
     * O casamento é por **conteúdo** (CEP, rua, número, cidade, UF), e não
     * pelo id ou pela ordem: recarregar a migração não pode criar uma
     * segunda cópia da mesma rua (BR-706), e nada garante que o endereço
     * ocupe a mesma posição nas duas execuções.
     *
     * Endereço que o cliente tenha cadastrado por outro caminho **não é
     * apagado** — ao contrário do `SaveCustomerService`, onde a lista do
     * formulário é a verdade inteira. Aqui a origem é uma foto parcial: o
     * site guarda um endereço de cobrança e um de entrega, e o ERP pode ter
     * mais.
     *
     * Devolve quantos passam a **existir**, não quantos foram tocados: é o
     * número que prova a idempotência no relatório — na segunda rodada ele
     * é zero.
     *
     * @param  list<array<string, mixed>>  $enderecos
     */
    private function sincronizarEnderecos(Customer $cliente, array $enderecos, bool $gravar): int
    {
        $novos = 0;

        foreach ($enderecos as $dados) {
            $atributos = $this->atributosDoEndereco($dados);
            $existente = $this->enderecoEquivalente($cliente, $atributos);

            $novos += $existente === null ? 1 : 0;

            if (! $gravar) {
                continue;
            }

            $endereco = $existente ?? $cliente->addresses()->make();

            // As marcas de padrão são acumulativas: quando cobrança e
            // entrega são o mesmo endereço, ele chega com os dois selos, e
            // uma recarga não pode desmarcar o que já estava marcado —
            // desmarcar é decisão de quem edita o cadastro.
            $atributos['is_default_billing'] = $atributos['is_default_billing'] || (bool) $endereco->is_default_billing;
            $atributos['is_default_shipping'] = $atributos['is_default_shipping'] || (bool) $endereco->is_default_shipping;

            $endereco->fill($atributos);
            $endereco->customer_id = $cliente->id;
            $endereco->save();
        }

        return $novos;
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function enderecoEquivalente(Customer $cliente, array $atributos): ?CustomerAddress
    {
        return $cliente->addresses()
            ->where('zip', $atributos['zip'])
            ->where('street', $atributos['street'])
            ->where('city', $atributos['city'])
            ->where('state', $atributos['state'])
            ->get()
            // O número fica fora do `where` porque é nulável, e
            // `where('number', null)` no SQL não casa com `NULL` — a
            // comparação teria de virar `whereNull` condicional. Em PHP,
            // sobre os poucos endereços de um cliente, é a mesma resposta
            // sem a armadilha.
            ->first(fn (CustomerAddress $e): bool => (string) $e->number === (string) ($atributos['number'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{label: string|null, zip: string, street: string, number: string|null, complement: string|null, district: string|null, city: string, state: string, is_default_billing: bool, is_default_shipping: bool}
     */
    private function atributosDoEndereco(array $dados): array
    {
        return [
            'label' => $this->corta($dados['label'] ?? null, 64),
            // Só dígitos e no máximo 9 caracteres, que é o que a coluna
            // aceita: o `CustomerAddress::saving` repete a limpeza, mas um
            // CEP com máscara e 10 caracteres estouraria antes de chegar lá.
            'zip' => mb_substr(preg_replace('/\D/', '', (string) ($dados['zip'] ?? '')) ?? '', 0, 9),
            'street' => (string) $this->corta($dados['street'] ?? '', 255),
            'number' => $this->corta($dados['number'] ?? null, 32),
            'complement' => $this->corta($dados['complement'] ?? null, 120),
            'district' => $this->corta($dados['district'] ?? null, 120),
            'city' => (string) $this->corta($dados['city'] ?? '', 120),
            'state' => mb_strtoupper(trim((string) ($dados['state'] ?? ''))),
            'is_default_billing' => (bool) ($dados['is_default_billing'] ?? false),
            'is_default_shipping' => (bool) ($dados['is_default_shipping'] ?? false),
        ];
    }

    private function corta(mixed $valor, int $limite): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : mb_substr($valor, 0, $limite);
    }

    /**
     * Documento com 14 dígitos é CNPJ → PJ; o resto (inclusive sem
     * documento) entra como PF — mesma regra do `ResolveCustomerService`, e
     * o histórico do site é 100% PF (pasta 31 §3).
     */
    private function tipoPara(?string $doc): CustomerType
    {
        return $doc !== null && mb_strlen($doc) === 14
            ? CustomerType::Company
            : CustomerType::Individual;
    }

    private function normalizarEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = mb_strtolower(trim($email));

        return $email === '' ? null : mb_substr($email, 0, 255);
    }

    private function normalizarDoc(?string $doc): ?string
    {
        if ($doc === null) {
            return null;
        }

        $doc = preg_replace('/\D/', '', $doc) ?? '';

        return $doc === '' ? null : $doc;
    }
}
