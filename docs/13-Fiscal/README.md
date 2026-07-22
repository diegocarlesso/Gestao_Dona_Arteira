# 13 — Fiscal

> **Status:** Rascunho — **BLOQUEADO por validação com contador** · **Última atualização:** 2026-07-03 · **Responsável:** fiscal-specialist
> **Regras:** BR-601…BR-606 · **Fase:** Gate 05 (regras) — decisões precisam começar antes

## 1. Objetivo

Consolidar as definições tributárias que parametrizam a emissão de NF-e (pasta 14) e o cálculo de preços/custos. Este documento organiza **o que perguntar e onde configurar**; a autoridade das respostas é o contador (pasta 00/02 — RACI).

## 2. Responsabilidades

- **Faz:** regime tributário, perfis de operação (CFOP/CSOSN por cenário), dados fiscais de produto (NCM/CEST/origem), obrigações e calendário, relação com a reforma tributária.
- **Não faz:** transmissão de NF-e (pasta 14), contabilidade formal (contador).

## 3. Hipóteses de trabalho (⚠️ TODAS a validar com o contador)

| Tema | Hipótese | Status |
|---|---|---|
| Regime | Simples Nacional | 💡 confirmar anexo/faixa |
| CSOSN | 102 (sem permissão de crédito) para vendas típicas | 💡 |
| NCM das peças de gesso | 6809.90.00 (outras obras de gesso/estuque) | 💡 confirmar por linha de produto |
| CFOP venda produção própria | 5101 (dentro UF) / 6101 (fora UF) | 💡 |
| CFOP revenda | 5102 / 6102 | 💡 |
| Devolução de venda | 1202/2202 (entrada) | 💡 |
| IE / ICMS ST | peças decorativas sem ST | 💡 verificar CEST aplicável |
| DIFAL consumidor final interestadual | Simples dispensado (decisão STF) — confirmar situação atual | 💡 |
| Obrigações acessórias | PGDAS-D mensal; DEFIS anual | 💡 |

Essas hipóteses viram registros em `tax_profiles` (pasta 04) — matriz simples: **tipo de operação × destino (dentro/fora UF) × tipo de cliente (PF/PJ contribuinte ou não)** → CFOP + CSOSN + observações da nota. O objetivo é que o operador **nunca escolha CFOP manualmente**: o sistema resolve pelo perfil.

## 4. Reforma tributária (IBS/CBS) — requisito vivo de 2026 em diante

A transição da reforma (CBS/IBS substituindo PIS/COFINS/ICMS/ISS) **começou em 2026** com alíquotas-teste e novos grupos de campos no layout da NF-e (Notas Técnicas da SEFAZ). Impactos a gerenciar:

1. Layout da NF-e ganhará/ganhou campos IBS/CBS — a biblioteca de emissão precisa acompanhar NTs (reforça a avaliação de API fiscal gerenciada no ADR-0009).
2. Regras do Simples durante a transição devem ser acompanhadas semestralmente com o contador.
3. `tax_profiles` já deve nascer **versionado por vigência** (`valid_from`/`valid_to`) para absorver mudanças sem reescrita.

## 5. Dados fiscais no cadastro de produto (BR-606)

NCM (obrigatório), CEST (quando aplicável), origem da mercadoria (0 = nacional), unidade tributável, GTIN ou "SEM GTIN". Bloqueio de emissão se incompleto — validação na ficha do produto com checklist visual.

## 6. Dependências

| Depende de | Motivo |
|---|---|
| **Contador** | validação de TODAS as hipóteses (reunião pré-Gate 05 obrigatória) |
| Catálogo | dados fiscais por produto |
| 14-NFe | consome os perfis daqui |

## 7. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Emitir com CFOP/CSOSN errado | Média | Alto (autuação/retrabalho) | Perfis validados pelo contador + ambiente de homologação + amostra revisada pelo contador antes do go-live fiscal |
| Reforma tributária mudar layout no meio do caminho | **Certa** | Médio | Perfis versionados; monitorar NTs; ADR-0009 reavaliado a cada NT relevante |
| Produto migrado do Woo sem NCM | Certa | Médio | Migração marca pendência fiscal; painel de produtos com cadastro fiscal incompleto |

## 8. Evoluções futuras

- Importação de XML de compra + manifestação do destinatário (fase 6).
- Se sair do Simples (crescimento): revisão completa de perfis — gatilho registrado.
