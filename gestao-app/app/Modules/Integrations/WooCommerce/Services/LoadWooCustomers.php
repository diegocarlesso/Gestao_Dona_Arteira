<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

use App\Modules\Integrations\WooCommerce\Enums\DestinoDoCliente;
use App\Modules\Integrations\WooCommerce\Models\IntegrationMapping;
use App\Modules\Integrations\WooCommerce\Models\StgWooCustomer;
use App\Modules\Sales\Services\CustomerLookupService;
use App\Modules\Sales\Services\ImportCustomerService;
use App\Support\Documento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Carga dos clientes do staging para Vendas — docs/17-Migracao F4.
 *
 * **Idempotente** (BR-706): o `integration_mappings` liga o id do Woo ao
 * cliente local, então rodar de novo enriquece em vez de duplicar.
 *
 * ## A diferença que o catálogo não tinha: o destino já está povoado
 *
 * Quando o catálogo foi migrado, a tabela `products` estava vazia. Aqui
 * não: desde o Gate 02, todo pedido do site que entra chama o
 * `ResolveCustomerService`, que **já cria o cliente** (origem Woo) e **já
 * grava o mapeamento** quando o comprador tem conta no Woo — só nunca
 * grava endereço. Então a carga encontra três situações, e cada uma tem um
 * tratamento diferente:
 *
 * 1. **Mapeamento existe** — o pedido já criou este cliente. A carga
 *    *enriquece*: grava os endereços que faltam e completa os campos
 *    vazios. Não cria um segundo.
 * 2. **Sem mapeamento, mas o e-mail/documento casa** com um cliente do
 *    ERP (cadastrado à mão ou por pedido de convidado). *Merge*, pela regra
 *    da pasta 17 F3 — doc válido > e-mail > mais recente: o mapeamento é
 *    ligado a esse cliente e o merge aparece no relatório, porque "dois
 *    cadastros viraram um" é informação que a operação precisa ver.
 * 3. **Nenhum dos dois** — cria cliente novo com origem Woo.
 *
 * Em nenhum caso duplica. É a diferença entre uma migração e um segundo
 * cadastro de todo mundo.
 *
 * ## Não constrói models de Vendas
 *
 * Fala com os Services dele (ADR-0020), que são a superfície pública do
 * módulo. O teste de arquitetura reprova o contrário, e com razão: as
 * invariantes do cliente moram no model.
 */
class LoadWooCustomers
{
    /** Cliente que nasce nesta carga — não existia de forma alguma. */
    private const CRIADO = 'criado';

    /** Cliente que o pedido do site já criara; a carga completa o que falta. */
    private const ENRIQUECIDO = 'enriquecido';

    /** Cadastro do site que casou com um cliente já existente no ERP. */
    private const MESCLADO = 'mesclado';

    public function __construct(
        private readonly CustomerLookupService $lookup,
        private readonly ImportCustomerService $importador,
    ) {}

    /**
     * @return array{
     *   criados: int, enriquecidos: int, mesclados: int,
     *   enderecos_novos: int, sem_endereco: int,
     *   sem_documento: int, documento_invalido: int,
     *   merges: list<string>, conflitos: list<string>,
     *   documentos_recusados: list<string>, enderecos_recusados: list<string>,
     *   total_mapeado: int,
     * }
     */
    public function executar(bool $dryRun = false): array
    {
        /**
         * @var array{
         *   criados: int, enriquecidos: int, mesclados: int,
         *   enderecos_novos: int, sem_endereco: int,
         *   sem_documento: int, documento_invalido: int,
         *   merges: list<string>, conflitos: list<string>,
         *   documentos_recusados: list<string>, enderecos_recusados: list<string>,
         *   total_mapeado: int,
         * } $r
         */
        $r = [
            'criados' => 0, 'enriquecidos' => 0, 'mesclados' => 0,
            'enderecos_novos' => 0, 'sem_endereco' => 0,
            'sem_documento' => 0, 'documento_invalido' => 0,
            'merges' => [], 'conflitos' => [],
            'documentos_recusados' => [], 'enderecos_recusados' => [],
            'total_mapeado' => 0,
        ];

        $carregar = function () use (&$r, $dryRun): void {
            foreach ($this->aCarregar() as $linha) {
                $this->carregarUm($linha, $r, $dryRun);
            }
        };

        // A carga real é uma transação só: ou a base de clientes fica
        // inteira, ou não muda. Migração pela metade é o pior estado
        // possível — dá trabalho descobrir onde parou e não dá para
        // simplesmente rodar de novo sem antes saber disso.
        $dryRun ? $carregar() : DB::transaction($carregar);

        // Contado pelo mapeamento porque o ADR-0020 impede este módulo de
        // enxergar `Sales\Models`, e porque o número que interessa é
        // "quantos clientes do site estão no ERP" — quem foi cadastrado à
        // mão no balcão não é resultado desta carga.
        $r['total_mapeado'] = IntegrationMapping::query()
            ->where('remote_system', IntegrationMapping::SISTEMA_WOO)
            ->where('entity_type', 'customer')
            ->count();

        return $r;
    }

    /**
     * @param  array{
     *   criados: int, enriquecidos: int, mesclados: int,
     *   enderecos_novos: int, sem_endereco: int,
     *   sem_documento: int, documento_invalido: int,
     *   merges: list<string>, conflitos: list<string>,
     *   documentos_recusados: list<string>, enderecos_recusados: list<string>,
     *   total_mapeado: int,
     * }  $r
     */
    private function carregarUm(StgWooCustomer $linha, array &$r, bool $dryRun): void
    {
        $alvo = IntegrationMapping::localDe('customer', $linha->woo_id);
        $caso = self::ENRIQUECIDO;

        if ($alvo === null) {
            $alvo = $this->lookup->idPorEmailOuDoc($linha->email, $linha->doc_proposto);
            $caso = $alvo !== null ? self::MESCLADO : self::CRIADO;
        }

        [$doc, $recusa] = $this->documentoPara($linha, $alvo);

        if ($recusa !== null) {
            $r['documentos_recusados'][] = $recusa;
        }

        $enderecos = $this->enderecosDe($linha, $r['enderecos_recusados']);

        $resultado = $dryRun
            ? $this->importador->prever($alvo, (string) $linha->nome_proposto, $linha->email, $doc, $this->telefoneDe($linha), $enderecos)
            : $this->importador->importar($alvo, (string) $linha->nome_proposto, $linha->email, $doc, $this->telefoneDe($linha), $enderecos);

        if ($caso === self::CRIADO) {
            $r['criados']++;
        } elseif ($caso === self::MESCLADO) {
            $r['mesclados']++;
            $r['merges'][] = sprintf(
                'Woo #%d (%s) → cliente local #%d — mapeamento ligado ao cadastro que já existia',
                $linha->woo_id,
                $linha->email ?? 'sem e-mail',
                $resultado->id,
            );
        } else {
            $r['enriquecidos']++;
        }

        $r['enderecos_novos'] += $resultado->enderecosNovos;
        $r['sem_endereco'] += $enderecos === [] ? 1 : 0;
        $r['sem_documento'] += $doc === null ? 1 : 0;
        $r['documento_invalido'] += ($doc !== null && ! Documento::valido($doc)) ? 1 : 0;

        foreach ($resultado->conflitos as $conflito) {
            $r['conflitos'][] = "Woo #{$linha->woo_id} — {$conflito}";
        }

        if (! $dryRun) {
            // BR-704: sem o mapeamento, a sincronização pós-cutover criaria
            // um segundo cliente a cada webhook em vez de atualizar o
            // primeiro. Gravado inclusive no caso do merge, que é o ponto
            // dele: dizer que aquele woo_id é *este* cadastro daqui.
            IntegrationMapping::registrar('customer', $resultado->id, $linha->woo_id);
        }
    }

    /**
     * O documento a gravar, ou nada e o motivo.
     *
     * `customers.doc` é único, e o soft delete não libera o número. Se o
     * CPF do cadastro do site já pertence a **outro** cliente do ERP,
     * gravá-lo estouraria a carga inteira com violação de UNIQUE. A pessoa
     * entra sem documento, com a pendência fiscal visível (BR-001) e a
     * recusa escrita no relatório — a alternativa seria perder o cliente e
     * os endereços dele por causa de um campo.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function documentoPara(StgWooCustomer $linha, ?int $alvo): array
    {
        $doc = $linha->doc_proposto;

        if ($doc === null) {
            return [null, null];
        }

        $dono = $this->lookup->donoDoDocumento($doc);

        if ($dono === null || $dono === $alvo) {
            return [$doc, null];
        }

        return [null, sprintf(
            'Woo #%d — documento %s já pertence ao cliente local #%d; migrado sem documento (pendência fiscal)',
            $linha->woo_id,
            $doc,
            $dono,
        )];
    }

    /**
     * Os endereços do cadastro do site, prontos para Vendas.
     *
     * ## Cobrança e entrega iguais viram **um** registro com os dois selos
     *
     * É o caso mais comum: o checkout do site marca "entregar no endereço
     * de cobrança" e devolve os dois blocos idênticos. Dois registros
     * gêmeos fariam a tela de venda pedir que alguém escolhesse entre duas
     * linhas iguais — escolha sem conteúdo — e o
     * `CustomerAddress::saved` já garante um padrão de cada tipo por
     * cliente. Um registro marcado como cobrança **e** entrega diz
     * exatamente o que o site disse.
     *
     * ## Endereço incompleto não vira registro em branco
     *
     * Sem rua, cidade ou UF de duas letras, o endereço não é endereço: não
     * cota frete, não vira destinatário de NF-e e ocuparia a tela fingindo
     * ser um dado. Fica de fora, com o motivo no relatório — o cliente
     * migra assim mesmo e aparece com a pendência "sem endereço".
     *
     * @param  list<string>  $recusas
     * @return list<array<string, mixed>>
     */
    private function enderecosDe(StgWooCustomer $linha, array &$recusas): array
    {
        $enderecos = [];

        foreach (['billing' => 'Cobrança', 'shipping' => 'Entrega'] as $bloco => $rotulo) {
            $endereco = $this->enderecoDe($linha, $bloco, $rotulo, $recusas);

            if ($endereco === null) {
                continue;
            }

            $chave = $this->chaveDe($endereco);
            $anterior = $enderecos[$chave] ?? null;

            if ($anterior !== null) {
                // O mesmo endereço nos dois blocos: um registro, dois selos.
                $anterior['is_default_billing'] = $anterior['is_default_billing'] || $endereco['is_default_billing'];
                $anterior['is_default_shipping'] = $anterior['is_default_shipping'] || $endereco['is_default_shipping'];
                $anterior['label'] = 'Cobrança e entrega';
                $enderecos[$chave] = $anterior;

                continue;
            }

            $enderecos[$chave] = $endereco;
        }

        return array_values($enderecos);
    }

    /**
     * @param  list<string>  $recusas
     * @return array<string, mixed>|null
     */
    private function enderecoDe(StgWooCustomer $linha, string $bloco, string $rotulo, array &$recusas): ?array
    {
        /** @var array<string, mixed> $dados */
        $dados = (array) ($linha->payload[$bloco] ?? []);

        $rua = trim((string) ($dados['address_1'] ?? ''));
        $cidade = trim((string) ($dados['city'] ?? ''));
        $uf = mb_strtoupper(trim((string) ($dados['state'] ?? '')));

        if ($rua === '' && $cidade === '' && $uf === '') {
            // Bloco inteiramente vazio é o normal, não um problema: o Woo
            // deixa `shipping` em branco quando o comprador entrega no
            // endereço de cobrança. Reportar isso como recusa encheria o
            // relatório de ruído e escondendo as recusas de verdade.
            return null;
        }

        if ($rua === '' || $cidade === '' || mb_strlen($uf) !== 2) {
            $recusas[] = sprintf(
                'Woo #%d — endereço de %s incompleto (rua "%s", cidade "%s", UF "%s"); não migrado',
                $linha->woo_id,
                mb_strtolower($rotulo),
                $rua,
                $cidade,
                $uf,
            );

            return null;
        }

        return [
            'label' => $rotulo,
            'zip' => (string) ($dados['postcode'] ?? ''),
            'street' => $rua,
            // O número mora num campo do plugin brasileiro, não em
            // `address_1`. Quando ele não vem, o número fica **nulo** em vez
            // de ser adivinhado dentro da rua: separar "Rua X, 123" por
            // heurística acerta na maioria e erra calado no resto, e
            // endereço errado só aparece quando a encomenda volta.
            'number' => $this->campoBr($linha, $dados, $bloco, 'number'),
            'complement' => trim((string) ($dados['address_2'] ?? '')),
            'district' => $this->campoBr($linha, $dados, $bloco, 'neighborhood'),
            'city' => $cidade,
            'state' => $uf,
            'is_default_billing' => $bloco === 'billing',
            'is_default_shipping' => $bloco === 'shipping',
        ];
    }

    /**
     * Campo que o plugin de checkout brasileiro acrescenta (número,
     * bairro), onde quer que ele o guarde.
     *
     * Mesma varredura empírica do documento: o campo pode vir dentro do
     * bloco (`billing.number`) se o plugin o registrou no REST, ou nos
     * metadados com e sem sublinhado. Qual plugin e quais nomes exatos era
     * pendência de inventário da pasta 16 — resolvida rodando, não lendo.
     *
     * @param  array<string, mixed>  $dados
     */
    private function campoBr(StgWooCustomer $linha, array $dados, string $bloco, string $campo): ?string
    {
        if (isset($dados[$campo]) && trim((string) $dados[$campo]) !== '') {
            return trim((string) $dados[$campo]);
        }

        $conhecidos = ["_{$bloco}_{$campo}", "{$bloco}_{$campo}"];

        foreach ((array) ($linha->payload['meta_data'] ?? []) as $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $chave = (string) ($meta['key'] ?? '');
            $valor = trim((string) ($meta['value'] ?? ''));

            if ($valor !== '' && in_array($chave, $conhecidos, true)) {
                return $valor;
            }
        }

        return null;
    }

    private function telefoneDe(StgWooCustomer $linha): ?string
    {
        /** @var array<string, mixed> $billing */
        $billing = (array) ($linha->payload['billing'] ?? []);
        $telefone = trim((string) ($billing['phone'] ?? ''));

        return $telefone === '' ? null : mb_substr($telefone, 0, 32);
    }

    /**
     * @param  array<string, mixed>  $endereco
     */
    private function chaveDe(array $endereco): string
    {
        return implode('|', [
            preg_replace('/\D/', '', (string) $endereco['zip']) ?? '',
            mb_strtolower((string) $endereco['street']),
            (string) ($endereco['number'] ?? ''),
            mb_strtolower((string) $endereco['city']),
            (string) $endereco['state'],
        ]);
    }

    /**
     * @return LazyCollection<int, StgWooCustomer>
     */
    private function aCarregar(): LazyCollection
    {
        return StgWooCustomer::query()
            ->where('status_triagem', DestinoDoCliente::Cliente->value)
            // Ordem estável: a carga tem de tomar as mesmas decisões de
            // merge em toda execução. Se a ordem variasse, dois cadastros
            // que casam entre si poderiam trocar de papel entre a rodada do
            // dry-run e a de verdade — e o relatório que o dono aprovou
            // descreveria outra coisa.
            ->orderBy('woo_id')
            ->cursor();
    }
}
