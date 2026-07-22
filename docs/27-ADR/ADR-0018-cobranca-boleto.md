# ADR-0018: Cobrança (boleto e PIX) via adapter, com provedor plugável

> **Status:** ⚠️ **Proposto — decisão do dono** (impacto de custo, escopo e prazo) · **Data:** 2026-07-22 · **Decisores:** dono do produto, com recomendação técnica
> **Módulos afetados:** 12 (Financeiro), 15 (Integrações), 25 (Segurança), 28 (Roadmap), 14 (NF-e — grupo de cobrança)
> **Prazo da decisão:** antes do início do Gate 04 · **Pré-requisito externo:** convênio de cobrança com o banco (lead time de semanas)

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

**3. Provedor.** A escolha depende de duas respostas que ainda não temos (qual banco o cliente usa; por que o cliente quer boleto). O critério de decisão fica fixado aqui:

| Se… | Então |
|---|---|
| O cliente já opera com **banco digital com API pública boa** (Inter, Cora, Sicoob, C6…) | Adapter direto do banco — custo por boleto próximo de zero, liquidação em tempo real |
| O cliente opera com **banco tradicional grande** (Itaú, Bradesco, BB, Santander) | **Gateway** (Asaas, Efí, Iugu…) — a homologação direta com banco grande é lenta e burocrática demais para um dev solo |
| O banco **só oferece CNAB** | Reabrir esta decisão. Não implementar CNAB sem nova análise |

**Não implementaremos CNAB 240/400 nesta fase.**

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

## Decisões que o dono precisa tomar para este ADR sair de "Proposto"

1. **Aprovar a mudança de roadmap**: cobrança sai da fase 7 e entra no Gate 04 (regra 1 do [roadmap](../28-Roadmap/README.md) — mudança de escopo é decisão do dono registrada).
2. **Confirmar o orçamento adicional** do módulo (80–120 h) e o custo recorrente por boleto.
3. **Obter do cliente**: qual banco/conta PJ, e **por que** boleto (§ Contexto, item 2) — sem isso o provedor não pode ser escolhido.
4. **Iniciar o convênio de cobrança** imediatamente, em paralelo ao desenvolvimento — é o caminho crítico.
