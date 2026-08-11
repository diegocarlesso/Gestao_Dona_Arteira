<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\WooCommerce\Enums\DestinoDoCliente;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Support\Documento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Saneamento dos clientes — docs/17-Migracao F3.
 *
 * Decide o destino de cada cadastro do site e propõe o nome e o documento
 * que ele levará ao ERP. **Não carrega nada**: a carga é a F4.
 *
 * ## A rejeição é o produto principal desta fase
 *
 * No catálogo a triagem existia para propor SKU. Aqui ela existe para
 * **recusar**, e recusar em voz alta: dos 198 cadastros do site apenas 62
 * compraram alguma vez (pasta 31 §2), e a decisão da diretoria de
 * 2026-08-10 é migrar só esses — minimização de dado pessoal (LGPD,
 * pasta 25 §3). Os ~136 restantes ficam no staging com
 * `status_triagem = sem_pedido` e o motivo escrito, porque "nada é
 * descartado silenciosamente" (princípio 4 da pasta 17) vale
 * principalmente quando o descarte é o certo a fazer.
 *
 * O critério é o `orders_count` que a própria API do Woo devolve no objeto
 * do cliente — não uma contagem nossa. Isso é decisão: derivar de pedidos
 * exigiria ter os pedidos migrados, que ainda não estão, e recontar do
 * nosso lado criaria um segundo número para divergir do primeiro.
 */
class TriageWooCustomers
{
    /**
     * @return array<string, int> contagem por destino
     */
    public function executar(): array
    {
        DB::transaction(function (): void {
            $this->classificar();
            $this->propor();
            $this->marcarDuplicados();
        });

        return StgWooCustomer::query()
            ->selectRaw('status_triagem, count(*) as total')
            ->groupBy('status_triagem')
            ->pluck('total', 'status_triagem')
            ->all();
    }

    private function classificar(): void
    {
        foreach (StgWooCustomer::query()->cursor() as $linha) {
            [$destino, $motivo] = match (true) {
                $linha->orders_count === null => [
                    // A origem não mandou o campo. Rejeitar por dado
                    // ausente descartaria um comprador em silêncio, que é
                    // justamente o que a fase promete não fazer.
                    DestinoDoCliente::Pendente,
                    'sem `orders_count` na origem — não dá para decidir',
                ],

                $linha->orders_count > 0 => [
                    DestinoDoCliente::Cliente,
                    "comprou {$linha->orders_count}× no site",
                ],

                default => [
                    DestinoDoCliente::SemPedido,
                    'cadastro sem nenhuma compra — fora do escopo por minimização de dados (LGPD)',
                ],
            };

            $linha->forceFill([
                'status_triagem' => $destino->value,
                'motivo_triagem' => $motivo,
            ])->save();
        }
    }

    /**
     * Nome e documento propostos, só para quem vira cliente.
     *
     * Propor para os rejeitados seria trabalho jogado fora e, pior,
     * deixaria CPF garimpado no staging de gente que não vai migrar.
     */
    private function propor(): void
    {
        foreach ($this->doDestino(DestinoDoCliente::Cliente) as $linha) {
            $bruto = $this->documentoDe($linha);
            $doc = $bruto === null ? null : $this->digitos($bruto);

            $linha->forceFill([
                'nome_proposto' => $this->nomeDe($linha),
                'doc_proposto' => $doc,
                // Documento que a origem tinha e nós não conseguimos usar
                // não some calado: fica escrito no motivo, que é o que o
                // relatório imprime. A pessoa migra do mesmo jeito, com a
                // pendência "sem CPF/CNPJ" visível no cadastro (BR-001).
                'motivo_triagem' => $bruto !== null && $doc === null
                    ? $linha->motivo_triagem.' · documento da origem descartado, não é CPF nem CNPJ: "'.mb_substr($bruto, 0, 32).'"'
                    : $linha->motivo_triagem,
            ])->save();
        }
    }

    /**
     * O nome da pessoa, com o mesmo recuo que o pedido do site já usa.
     *
     * O `ResolveCustomerService` chama de "Cliente do site" quem chega sem
     * nome, e este é o **mesmo** texto de propósito: dois rótulos
     * diferentes para a mesma lacuna fariam a operação achar que são duas
     * situações diferentes.
     */
    private function nomeDe(StgWooCustomer $linha): string
    {
        $nome = trim(((string) $linha->first_name).' '.((string) $linha->last_name));

        if ($nome === '') {
            // O cadastro pode vir sem nome no topo e com nome na cobrança —
            // o checkout preenche um campo que o perfil não tem. Não é
            // inventar dado: é ler o outro lugar onde a origem o guarda.
            /** @var array<string, mixed> $billing */
            $billing = (array) ($linha->payload['billing'] ?? []);
            $nome = trim(((string) ($billing['first_name'] ?? '')).' '.((string) ($billing['last_name'] ?? '')));
        }

        return $nome === '' ? 'Cliente do site' : mb_substr($nome, 0, 255);
    }

    /**
     * CPF/CNPJ do cadastro, onde o plugin de checkout brasileiro o guardar.
     *
     * A varredura é a mesma que o `ImportWooOrder::documentoDe()` resolveu
     * empiricamente para o pedido — os nomes exatos dos metadados eram
     * pendência de inventário da pasta 16 e foram descobertos rodando. O
     * objeto de cliente expõe `meta_data` igual ao de pedido, então a mesma
     * lista de chaves conhecidas serve.
     *
     * Documento inválido **não** é apagado nem bloqueia a migração: fica
     * como veio e vira pendência fiscal no cadastro (BR-001, decisão da
     * pasta 17 F3). Devolve o valor **cru**; a limpeza é de quem chama,
     * para poder registrar o que foi descartado.
     */
    private function documentoDe(StgWooCustomer $linha): ?string
    {
        /** @var array<string, mixed> $billing */
        $billing = (array) ($linha->payload['billing'] ?? []);

        foreach (['cpf', 'cnpj'] as $campo) {
            if (isset($billing[$campo]) && (string) $billing[$campo] !== '') {
                return (string) $billing[$campo];
            }
        }

        $conhecidos = ['_billing_cpf', 'billing_cpf', '_billing_cnpj', 'billing_cnpj'];

        foreach ((array) ($linha->payload['meta_data'] ?? []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $chave = (string) ($meta['key'] ?? '');
            $valor = (string) ($meta['value'] ?? '');

            if ($valor !== '' && in_array($chave, $conhecidos, true)) {
                return $valor;
            }
        }

        return null;
    }

    /**
     * Marca o segundo cadastro da mesma pessoa — regra da pasta 17 F3:
     * **doc válido > e-mail > mais recente**.
     *
     * O inventário mediu zero duplicatas entre os 62 compradores (pasta 31
     * §7), então o esperado é este método não marcar nada. Ele existe
     * porque a medição é de uma data e a carga roda noutra — e porque a
     * alternativa a descobrir a duplicata aqui é descobri-la como violação
     * do UNIQUE de `customers.doc` no meio da carga, com metade da base
     * dentro.
     */
    private function marcarDuplicados(): void
    {
        /** @var array<string, StgWooCustomer> $donos */
        $donos = [];

        foreach ($this->doDestino(DestinoDoCliente::Cliente) as $linha) {
            $chaves = $this->chavesDe($linha);
            $anterior = null;

            foreach ($chaves as $chave) {
                $anterior ??= $donos[$chave] ?? null;
            }

            if ($anterior === null) {
                foreach ($chaves as $chave) {
                    $donos[$chave] = $linha;
                }

                continue;
            }

            [$vencedor, $perdedor, $criterio] = $this->desempatar($anterior, $linha);

            $perdedor->forceFill([
                'status_triagem' => DestinoDoCliente::Duplicado->value,
                'motivo_triagem' => "mesma pessoa do cadastro Woo #{$vencedor->woo_id} ({$criterio})",
            ])->save();

            // O vencedor passa a responder por todas as chaves dos dois:
            // sem isso, um terceiro cadastro casaria com o perdedor, que já
            // não migra, e escaparia da marcação.
            foreach (array_merge($chaves, $this->chavesDe($vencedor)) as $chave) {
                $donos[$chave] = $vencedor;
            }
        }
    }

    /**
     * Quem fica, quem sai, e por quê — a regra da pasta 17 F3.
     *
     * @return array{0: StgWooCustomer, 1: StgWooCustomer, 2: string}
     */
    private function desempatar(StgWooCustomer $a, StgWooCustomer $b): array
    {
        $aValido = $a->doc_proposto !== null && Documento::valido($a->doc_proposto);
        $bValido = $b->doc_proposto !== null && Documento::valido($b->doc_proposto);

        if ($aValido !== $bValido) {
            return $aValido
                ? [$a, $b, 'documento válido vence']
                : [$b, $a, 'documento válido vence'];
        }

        // Empate no documento: fica o **mais recente**, porque é o cadastro
        // que a pessoa usou por último — o outro é o abandonado.
        $aQuando = $a->woo_modified_at?->getTimestamp() ?? $a->woo_id;
        $bQuando = $b->woo_modified_at?->getTimestamp() ?? $b->woo_id;

        return $aQuando >= $bQuando
            ? [$a, $b, 'cadastro mais recente vence']
            : [$b, $a, 'cadastro mais recente vence'];
    }

    /**
     * As identidades pelas quais dois cadastros podem ser a mesma pessoa.
     *
     * @return list<string>
     */
    private function chavesDe(StgWooCustomer $linha): array
    {
        $chaves = [];

        if ($linha->email !== null) {
            $chaves[] = 'email:'.$linha->email;
        }

        if ($linha->doc_proposto !== null) {
            $chaves[] = 'doc:'.$linha->doc_proposto;
        }

        return $chaves;
    }

    /**
     * @return LazyCollection<int, StgWooCustomer>
     */
    private function doDestino(DestinoDoCliente $destino): LazyCollection
    {
        return StgWooCustomer::query()
            ->where('status_triagem', $destino->value)
            // Ordem estável: a triagem tem de decidir o mesmo vencedor toda
            // vez que rodar, senão a conferência humana vira lixo a cada
            // execução (mesma exigência dos SKUs propostos no catálogo).
            ->orderBy('woo_id')
            ->cursor();
    }

    private function digitos(string $bruto): ?string
    {
        $digitos = preg_replace('/\D/', '', $bruto) ?? '';

        // Mais de 14 dígitos não é CPF nem CNPJ — é lixo de digitação, e
        // truncar produziria um documento que não é de ninguém. A coluna
        // tem 14; guardar como pendência exige que caiba.
        return $digitos === '' || mb_strlen($digitos) > 14 ? null : $digitos;
    }
}
