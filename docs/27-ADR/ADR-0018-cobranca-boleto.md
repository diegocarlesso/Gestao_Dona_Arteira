# ADR-0018: Cobrança (boleto e PIX) via adapter, com provedor plugável

> **Status:** ✅ **Decidido** — provedor Mercado Pago (branch "Gateway" da tabela de decisão) · **Data:** 2026-07-22, decisão de provedor em 2026-08-11 · **Decisores:** dono do produto, com recomendação técnica
> **Módulos afetados:** 12 (Financeiro), 15 (Integrações), 25 (Segurança), 28 (Roadmap), 14 (NF-e — grupo de cobrança, adiado — BR-512)
> **Pré-requisito externo:** conta Mercado Pago do negócio com credenciais de produção ativas (a mesma já usada no checkout do site via WooCommerce resolve a autenticação — falta apenas gerar o Access Token de produção com escopo de pagamentos no painel do desenvolvedor)

> 🔧 **Implementado em 2026-08-12** (Fase C do plano de Financeiro): `CobrancaGatewayInterface`
> + `NullCobrancaGateway` (padrão, como o ADR-0009 já estabeleceu para NF-e) no módulo
> Financeiro; `MercadoPagoGateway` + submódulo `Integrations\MercadoPago` (`Client`, `Mappers\
> StatusMap`, `Services\VerificaAssinaturaMercadoPago`, `Adapters\MercadoPagoGateway`,
> `Http\Controllers\MercadoPagoWebhookController`, `Jobs\ProcessarWebhookMercadoPago`,
> `Jobs\ReconciliarCobrancasMercadoPago` agendado de hora em hora) implementando a interface.
> Rebind condicionado a `integrations.mercadopago.enabled` — enquanto o Access Token de
> produção não estiver configurado, o sistema continua no `NullCobrancaGateway` (mesmo
> espírito do `NullNfeGateway`: nunca finge sucesso). 29 testes cobrindo o domínio (Finance)
> e o adapter (Integrations), incluindo o ciclo completo webhook → job → baixa idempotente
> + tarifa como despesa (BR-507).

## Contexto

O cliente solicitou que o ERP **emita boletos**. Hoje isso está registrado como evolução de **fase 7** ([12-Financeiro §8](../12-Financeiro/README.md), [Roadmap Gate 07](../28-Roadmap/README.md)) — ou seja, o pedido é uma **mudança de escopo**, não um detalhe de implementação.

Fatos que pressionam a decisão:

1. **Boleto é a materialização de um título a receber.** Sem contas a receber (BR-501/502, Gate 04), um boleto é um PDF solto que ninguém baixa e ninguém concilia — o oposto do que o ERP existe para resolver. Antecipar boleto implica antecipar um **Gate 04 mínimo**.
2. **O volume atual de boleto é residual.** O inventário do legado mede **~6% dos pedidos** em boleto, contra PIX ~46% e cartão ~48% ([31/08](../31-Inventario-Legado/08-formas-pagamento.md)). A demanda real provavelmente vem do **atacado/PJ**, cujo contas-a-pagar exige boleto — não do varejo do site.
3. **Emitir boleto exige convênio de cobrança com o banco**: carteira, código do beneficiário, faixa de nosso número, credenciais e homologação. É burocracia bancária de semanas, executada pelo cliente. **É a dependência de maior lead time do módulo** — da mesma natureza da validação fiscal com o contador.
4. **Boleto registrado é obrigatório**; não existe mais boleto "sem registro" emitido por conta própria. Toda emissão passa pelo banco.
5. O projeto já tem um precedente arquitetural para exatamente este problema: o módulo Fiscal fala com uma `NfeGatewayInterface` para permitir trocar sped-nfe por uma API gerenciada sem tocar no domínio ([ADR-0009](ADR-0009-emissao-nfe.md)).
6. Movimentar cobrança significa guardar **credenciais bancárias** — ativo de risco superior ao certificado A1, porque está ligado a dinheiro.

## Decisão

**Usaremos um adapter `CobrancaGatewayInterface` no módulo Financeiro, com o provedor concreto escolhido depois — e trataremos boleto e PIX-cobrança como o mesmo caso de uso ("Cobrança"), não como funcionalidades separadas.**

Três partes:

**1. Arquitetura.** O domínio Financeiro emite um comando de cobrança contra a interface; o adapter concreto (banco ou gateway) fica na camada `Integrations`, sob o framework da [pasta 15](../15-Integracoes/README.md) — filas, idempotência, mapeamento e reconciliação. Trocar de banco não toca em regra de negócio.

**2. Escopo do produto.** O módulo se chama **Cobrança** e cobre boleto **e** PIX com vencimento sob a mesma interface. Motivo: o público que pede boleto é o mesmo do atacado, e para boa parte dele um PIX-cobrança com vencimento, multa e juros resolve a mesma dor por uma fração do custo — mas alguns compradores PJ só conseguem pagar via boleto. Suportar os dois sob uma interface custa pouco a mais que suportar um.

**3. Provedor: Mercado Pago (decidido em 2026-08-11).** A escolha caiu no branch **Gateway** da tabela de critério abaixo — o mesmo provedor já usado no checkout do site via WooCommerce, o que resolve de uma vez a pergunta "qual banco/conta o cliente usa" (não é banco digital nem banco tradicional; é subadquirente, mesma categoria de Asaas/Efí/Iugu já citados como exemplo):

| Se… | Então |
|---|---|
| O cliente já opera com **banco digital com API pública boa** (Inter, Cora, Sicoob, C6…) | Adapter direto do banco — custo por boleto próximo de zero, liquidação em tempo real |
| O cliente opera com **banco tradicional grande** (Itaú, Bradesco, BB, Santander) | **Gateway** (Asaas, Efí, Iugu, **Mercado Pago**…) — a homologação direta com banco grande é lenta e burocrática demais para um dev solo |
| O banco **só oferece CNAB** | Reabrir esta decisão. Não implementar CNAB sem nova análise |

**Não implementaremos CNAB 240/400 nesta fase.**

### 3.1 Notas técnicas do provedor (Mercado Pago, levantadas em 2026-08-11 na documentação oficial)

Confirmam que a API de Pagamentos do Mercado Pago cobre o desenho deste ADR sem gambiarra:

- **Um único endpoint** para PIX e boleto: `POST /v1/payments`, variando só `payment_method_id`
  (`pix` ou `bolbradesco`) e os dados do pagador — PIX exige e-mail/CPF; **boleto exige também
  endereço completo do pagador**, o que o formulário de emissão de cobrança precisa validar.
- **Idempotência nativa**: header `X-Idempotency-Key` (UUID v4) obrigatório na criação — casa
  diretamente com o princípio 4 da [pasta 15](../15-Integracoes/README.md); a chave usada é o
  `public_id` da `billing_charges` sendo criada.
- **Webhooks, não IPN** (IPN está deprecado). Payload de notificação é mínimo
  (`{type, data:{id}}`) — o job sempre faz `GET /v1/payments/{id}` para pegar o estado real,
  nunca confia no corpo do webhook por si só (mesma desconfiança que o BR-701 já aplica ao Woo).
  Autenticidade por header `x-signature` (`ts=...,v1=...`, HMAC-SHA256 sobre
  `id:{data.id};request-id:{request-id};ts:{ts};` com o segredo do painel) — mesmo padrão de
  `VerificaAssinaturaWoo`, algoritmo diferente.
- **Cancelamento** é `PUT /v1/payments/{id}` com `status: cancelled`, só válido enquanto a
  cobrança está `pending`/`in_process` — depois de `approved` não cancela mais (BR-506: cobrança
  registrada é imutável; mudar de ideia é cancelar e reemitir, nunca editar).
- **Tarifa** não tem endpoint de consulta dedicada; vem no próprio objeto do pagamento
  liquidado (`fee_details`) — a baixa idempotente lê esse campo para registrar a tarifa como
  despesa (BR-502/12-Financeiro §3).
- **Sandbox = mesma URL, credencial de teste** (prefixo `TEST_`) — não existe ambiente de
  homologação separado; a distinção fica inteiramente na credencial configurada
  (`integrations.mercadopago.enabled` + par de chaves teste/produção), mesmo espírito do
  ambiente de homologação permanente da SEFAZ (BR-605).

## Alternativas consideradas

### Alternativa A — API REST do banco, integração direta
OAuth2 + mTLS com certificado; emissão unitária; webhook de liquidação.
**Prós:** tempo real; baixa e conciliação automáticas; custo por boleto baixo ou nulo nos bancos digitais; boleto híbrido com PIX embutido; o dinheiro não passa por terceiro.
**Contras:** uma integração por banco; qualidade de documentação e de sandbox varia enormemente; trocar de banco = reescrever o adapter.
**Status:** escolhida **se** o cliente usar banco digital.

### Alternativa B — Gateway / subadquirente
API única cobrindo boleto + PIX + cartão, com webhooks e régua de cobrança prontos.
**Prós:** uma integração só; documentação e sandbox decentes; já entrega notificação ao cliente e conciliação; menos burocracia bancária.
**Contras:** custo por boleto emitido/liquidado; repasse em D+1/D+2 (o dinheiro passa por terceiro); mais um fornecedor no caminho do caixa; risco de lock-in comercial.
**Status:** escolhida **se** o cliente usar banco tradicional.

### Alternativa C — CNAB 240/400 (arquivo de remessa/retorno)
ERP gera arquivo de remessa → upload manual no internet banking → banco devolve arquivo de retorno para baixa.
**Prós:** funciona com qualquer banco; sem custo de API.
**Contras:** **batch, não tempo real**; exige rotina operacional manual diária que ninguém vai sustentar; layout varia por banco e é notoriamente propenso a erro; nosso número e dígito verificador por nossa conta; esforço de implementação alto para um valor baixo.
**Descartada:** o custo de manutenção não se justifica para o volume da Dona Arteira. Só volta à mesa se o banco escolhido não oferecer API.

### Alternativa D — Não implementar; usar o painel do banco manualmente
O cliente emite boleto pelo internet banking e registra a baixa no ERP à mão.
**Prós:** custo zero de desenvolvimento; disponível hoje.
**Contras:** dupla digitação; conciliação manual; sem régua de cobrança; o título e o boleto podem divergir.
**Descartada como solução final, mas é o *fallback* válido** enquanto o convênio bancário não sai — e deve ser explicitamente comunicada ao cliente como o estado do dia 1.

## Consequências

**Positivas:**
- O domínio Financeiro não conhece banco nenhum; trocar de provedor é escrever um adapter novo.
- Boleto e PIX-cobrança sob a mesma interface evitam duas implementações concorrentes no futuro.
- A escolha do provedor pode ser adiada sem travar o desenho do módulo — o que importa decidir agora (a fronteira) está decidido.
- Reaproveita integralmente o framework de integrações da pasta 15 (fila, idempotência, reconciliação) já desenhado para o WooCommerce.

**Negativas / dívidas assumidas:**
- **Escopo:** exige antecipar um Gate 04 mínimo (títulos a receber + baixa + contas financeiras). Estimativa do módulo de cobrança isolado: **80–120 h**; o Gate 04 mínimo que o sustenta é maior.
- **Custo recorrente novo** para o cliente: tarifa por boleto emitido e/ou liquidado, variável por provedor — entra no [modelo de custos](../00-Visao-Geral/05-modelo-de-custos.md).
- **Superfície de segurança nova e crítica:** credenciais bancárias e certificado de cobrança passam a viver no sistema. Exige tratamento igual ou superior ao do certificado A1 (pasta 25), com credencial de **escopo mínimo — só cobrança, nunca pagamento ou transferência**.
- **Dependência externa de lead time longo** (convênio bancário) que pode atrasar o Gate 04 mesmo com o código pronto.
- A camada de abstração custa: um adapter é mais código que uma chamada direta. Aceitável pelo precedente do ADR-0009.

**Gatilhos de revisão:**
- O banco escolhido não oferecer API de cobrança → reabrir para avaliar CNAB ou troca de banco.
- Volume de boletos ultrapassar ~200/mês → renegociar tarifa ou reavaliar provedor (a diferença entre gateway e banco direto passa a ser material).
- Inadimplência do atacado exigir régua de cobrança, protesto ou negativação → novo ADR (é outro produto, não uma extensão deste).
- PIX-cobrança absorver mais de ~90% da cobrança a prazo por 6 meses → avaliar descontinuar o boleto e economizar a tarifa.
- Cliente exigir cartão de crédito recorrente/link de pagamento → reabre a discussão de gateway completo (o "gateway de pagamento, fase 7" já no backlog de ADRs).

## Decisões que o dono tomou (histórico, ADR fechado em 2026-08-11)

1. ✅ **Mudança de roadmap aprovada**: cobrança saiu da fase 7 e entrou no Gate 04, na mesma
   sessão que fechou o núcleo do Financeiro (regra 1 do [roadmap](../28-Roadmap/README.md)).
2. ✅ **Provedor confirmado**: Mercado Pago, o mesmo já usado no checkout do site — resolve a
   pergunta de qual conta/banco usar sem exigir convênio bancário novo (o "convênio de
   cobrança" deste ADR passa a ser: gerar o Access Token de produção com escopo de pagamentos
   no painel do desenvolvedor do Mercado Pago, não um processo bancário de semanas).
3. **Ainda em aberto, não bloqueia o código**: percentuais de multa/juros/desconto do perfil de
   cobrança (BR-508) e o grupo de duplicatas na NF-e (BR-512) dependem do retorno do contador
   (pauta em `docs/13-Fiscal/01-pauta-validacao-contador.md`) — o `billing_profiles` nasce com
   esses campos nulos/editáveis, sem valor padrão chutado, mesmo princípio já aplicado ao
   grupo IBS/CBS (ADR-0027).
4. **Antes de ligar em produção**: gerar o Access Token de produção real (hoje só o de teste
   `TEST_` está em uso) e configurar o webhook secret no painel do Mercado Pago apontando para
   `/webhooks/mercado-pago` do ERP.
