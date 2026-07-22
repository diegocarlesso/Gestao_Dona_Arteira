# 05 — Modelo de Custos do Projeto

> **Status:** Rascunho — valores a confirmar com cotação real · **Última atualização:** 2026-07-22 · **Responsável:** chief-architect + dono
> **ADRs relacionados:** [ADR-0016](../27-ADR/ADR-0016-hospedagem.md) (hospedagem), [ADR-0009](../27-ADR/ADR-0009-emissao-nfe.md) (NF-e), [ADR-0018](../27-ADR/ADR-0018-cobranca-boleto.md) (cobrança), [ADR-0017](../27-ADR/ADR-0017-midia-canonica.md) (mídia)
> Documento proposto na [análise crítica do Gate 00 §E](04-analise-critica-gate00.md#6-e-documentos-adicionais-propostos), para eliminar o risco R12 (*custo mensal cresce sem o dono ter mapeado*).

## 1. Objetivo

Consolidar **tudo que o ERP vai custar para operar**, mês a mês, para que nenhuma decisão técnica gere despesa surpresa. Este documento cobre o **custo de operação**; a proposta comercial de desenvolvimento é um artefato separado e não versionado aqui.

> ⚠️ Todos os valores são **estimativas de mercado em julho/2026** e devem ser substituídos por cotação real antes da contratação. Nenhum item aqui está contratado.

## 2. Custos recorrentes — cenário recomendado (VPS)

| # | Item | Faixa mensal | Quando começa | Observação |
|---|---|---|---|---|
| 1 | **VPS** (Ubuntu LTS, 2 vCPU / 8 GB) | R$ 40–100 | Gate 01 | [ADR-0016](../27-ADR/ADR-0016-hospedagem.md) — decisão pendente do dono |
| 2 | **Certificado digital A1** (e-CNPJ) | R$ 13–25 (R$ 150–300/ano) | Gate 05 | Renovação anual; alertas de vencimento no health check |
| 3 | **Storage de backup externo** (fora do VPS) | R$ 20–50 | Gate 01 | Backup no mesmo servidor não é backup |
| 4 | **SMTP transacional** | R$ 0–100 | Gate 02 | Faixa gratuita costuma bastar no volume atual |
| 5 | **Monitoramento / uptime** | R$ 0–40 | Gate 02 | Faixa gratuita atende no início |
| 6 | **Domínio** `gestao.donaarteira.com.br` | R$ 0 | Gate 01 | Subdomínio do domínio já existente |
| | **Subtotal recorrente (núcleo)** | **R$ 75–315/mês** | | |

## 3. Custos recorrentes — condicionais

Só existem se o gatilho correspondente for acionado.

| # | Item | Faixa mensal | Gatilho |
|---|---|---|---|
| 7 | **API fiscal gerenciada** | R$ 100–300 | Se os gatilhos do [ADR-0009](../27-ADR/ADR-0009-emissao-nfe.md) se materializarem (NTs da reforma inviabilizarem manter o sped-nfe local) |
| 8 | **Tarifa de cobrança — boleto** | R$ 0–4 **por boleto** | [ADR-0018](../27-ADR/ADR-0018-cobranca-boleto.md). Bancos digitais tendem a zero; gateways cobram por emissão/liquidação |
| 9 | **Tarifa de cobrança — PIX** | R$ 0–1 ou % **por recebimento** | Idem. Costuma ser bem menor que o boleto — argumento a favor do PIX-cobrança |
| 10 | **Storage de mídia próprio** | R$ 20–60 | Fase 2 do [ADR-0017](../27-ADR/ADR-0017-midia-canonica.md), se as imagens saírem do Woo |
| 11 | **Melhor Envio** | R$ 0 de mensalidade | Gate 06 — cobra por etiqueta, repassado ao frete |
| 12 | **WhatsApp Business API** | R$ 50–200 | Fase 7, se notificação transacional por WhatsApp entrar no escopo |

**Exemplo prático de cobrança:** 30 boletos/mês a R$ 3 = R$ 90/mês. Os mesmos 30 recebimentos via PIX-cobrança podem custar uma fração disso. É por isso que o [ADR-0018](../27-ADR/ADR-0018-cobranca-boleto.md) trata os dois sob a mesma interface — a escolha vira uma decisão de custo por operação, não de arquitetura.

## 4. Cenários consolidados

| Cenário | Composição | Recorrente estimado |
|---|---|---|
| **A — Plano Business** ✅ **escolhido em 2026-07-22** | itens 2, 3, 4, 5 (sem VPS) | **R$ 33–215/mês** + dívidas operacionais documentadas no [ADR-0016](../27-ADR/ADR-0016-hospedagem.md) |
| **B — VPS, núcleo** (Gates 01–03) | itens 1, 3, 4, 5, 6 | R$ 60–290/mês |
| **C — VPS, com fiscal** (Gates 01–05) | B + itens 2, 8/9 | R$ 90–420/mês |
| **D — Completo com contingências** | C + itens 7, 10, 12 | R$ 260–980/mês |

**Cenário vigente: A.** O dono optou por permanecer no plano Business já contratado e o escopo contratado é o completo (Gates 01–06). Como o item 2 (certificado A1) é obrigatório no Gate 05, ele entra no cenário A a partir dali.

⚠️ **O cenário A pode migrar para C por força maior.** Se a [validação do ambiente](../23-Deploy/01-validacao-ambiente-business.md) reprovar as extensões necessárias à NF-e, as saídas serão contratar um VPS no Gate 05 ou assinar uma **API fiscal gerenciada** (item 7, R$ 100–300/mês). Essa é a maior incerteza de custo aberta hoje, e ela se resolve em uma hora de verificação — por isso a validação foi antecipada para a semana 1.

## 5. Custos de implantação (uma vez)

| Item | Faixa | Observação |
|---|---|---|
| Provisionamento e hardening do VPS | ~2 dias de trabalho | Automatizável por script ([pasta 23](../23-Deploy/README.md)) |
| Primeira emissão do certificado A1 | R$ 150–300 | Inclui videoconferência de validação |
| Convênio de cobrança com o banco | R$ 0 (burocracia) | **Semanas de lead time** — ver [ADR-0018](../27-ADR/ADR-0018-cobranca-boleto.md) |
| Inventário físico de estoque no cutover | horas da equipe | [RC-03](../31-Inventario-Legado/15-recomendacoes.md) — não é custo de TI, mas é custo |

## 6. Esforço de desenvolvimento por gate (referência de planejamento)

Estimativa de engenharia para um desenvolvedor sênior, com a disciplina que o projeto exige (docs-first, testes obrigatórios, CI bloqueante). **Serve para planejar sequência e prazo — não é proposta comercial.**

| Gate | Escopo | Horas estimadas |
|---|---|---|
| 00 | Fundação documental + inventário do legado | 180–240 ✅ entregue |
| 01 | Núcleo + migração | 400–560 |
| 02 | Vendas + sincronização WooCommerce | 330–440 |
| 03 | Produção + Compras | 240–320 |
| 04 | Financeiro (**+ cobrança: 80–120**) | 260–360 |
| 05 | Fiscal / NF-e | 200–280 |
| 06 | Expedição avançada + Relatórios/Dashboards | 180–250 |
| — | Gestão, reuniões, treinamento, retrabalho (+15%) | 250–350 |
| | **Total Gates 01–06** | **~2.050–2.800 h** |

Leitura honesta: são **12 a 18 meses em tempo integral** para uma pessoa. O roadmap com gates bloqueantes existe justamente para que o negócio colha valor muito antes do fim ([análise crítica §B4](04-analise-critica-gate00.md#3-b-decisões-que-eu-questiono-não-aceite-só-porque-foram-pedidas)).

## 7. Manutenção pós-go-live

**Não é opcional.** Um ERP com módulo fiscal sem manutenção contratada para de funcionar na primeira Nota Técnica da reforma tributária que mude o layout da NF-e.

| Item | Periodicidade | Observação |
|---|---|---|
| Acompanhamento de NTs da SEFAZ e atualização da lib fiscal | contínuo | Risco R5 da análise crítica |
| Atualizações de SO e segurança do VPS | mensal | Automatizável, mas não eliminável |
| Teste de restore de backup | trimestral | [RB da pasta 23](../23-Deploy/README.md) — backup nunca testado é ausência de backup |
| Renovação do certificado A1 | anual | Operação planejada com runbook |
| Revisão de perfis fiscais com o contador | semestral | [Pauta bloco H](../13-Fiscal/01-pauta-validacao-contador.md) |

## 8. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Custo recorrente crescer item a item sem revisão | **Alta** | Médio | Este documento revisado ao fim de cada gate; todo ADR com custo aponta para cá |
| Gatilho da API fiscal disparar e triplicar o recorrente | Média | Alto | Gatilhos objetivos no ADR-0009; decisão consciente, não emergencial |
| Tarifa de boleto virar despesa invisível | Média | Médio | [BR-507](../01-Regras-de-Negocio/01-registro-de-regras.md) registra a tarifa como despesa categorizada na baixa |
| Cliente não absorver o custo recorrente | Baixa | Alto | Cenário A (Plano B do ADR-0016) mantém o mínimo viável em ~R$ 20–190/mês |
| Estimativa de horas se mostrar otimista | **Alta** | Alto | Gates bloqueantes limitam a exposição; reestimar ao fim de cada gate com dados reais |

## 9. Perguntas em aberto

- Qual o teto de custo mensal que o cliente aceita sem nova aprovação? Defini-lo agora evita negociação a cada ADR.
- O custo recorrente será pago diretamente pelo cliente (contas no nome dele) ou repassado? **Recomendação: contas no nome do cliente**, para que o acesso e a titularidade não dependam do desenvolvedor — reduz o risco de bus factor 1 (R6).
- Qual banco a empresa usa? Define os itens 8 e 9 ([ADR-0018](../27-ADR/ADR-0018-cobranca-boleto.md)).
