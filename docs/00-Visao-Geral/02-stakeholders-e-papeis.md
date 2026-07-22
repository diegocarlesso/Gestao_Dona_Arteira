# Stakeholders e Papéis

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** business-analyst

## 1. Objetivo

Identificar quem participa do projeto, o que cada um decide e quem é impactado — base para as personas de usuário (pasta 18) e para as alçadas (pasta 19).

## 2. Stakeholders

| Stakeholder | Papel no projeto | Decide sobre |
|---|---|---|
| **Dono do produto** (Diego) | Patrocinador, product owner e desenvolvedor principal | Escopo, prioridades, gates, custos (hospedagem, serviços), ADRs |
| **Operação/produção** (artesãs, pintoras) | Usuárias do módulo de produção | Validação das regras de produção (pasta 08/30) |
| **Vendas/atendimento** | Usuárias de pedidos, clientes, expedição | Validação do fluxo de venda e expedição |
| **Financeiro** (dono ou responsável) | Usuário de contas a pagar/receber | Categorias, alçadas de desconto |
| **Contador (externo)** | Consultor fiscal | Regime tributário, CFOP/NCM/CSOSN, obrigações; recebe XMLs e relatórios mensais |
| **Clientes finais e lojistas (atacado)** | Impactados indiretamente | — (mas definem requisitos de prazo/rastreio) |
| **Hostinger** | Fornecedor de infraestrutura | Limites técnicos do ambiente |
| **SEFAZ** | Autoridade fiscal | Layout e validação de NF-e |

## 3. Matriz RACI simplificada (macro-atividades)

| Atividade | Dono | Contador | Operação | Agentes/dev |
|---|---|---|---|---|
| Aprovar escopo e roadmap | **A/R** | C | C | I |
| Regras fiscais (CFOP, NCM, CSOSN, séries) | A | **R** | I | C |
| Regras de produção/estoque | **A** | — | **R** (validação) | C |
| Arquitetura e ADRs | **A** | — | — | **R** |
| Implementação e testes | A | — | I | **R** |
| Operação diária pós-go-live | **R** | C | **R** | — |

A = aprova · R = responsável · C = consultado · I = informado

## 4. Canais e cadência

- Decisões pendentes do dono ficam listadas em [04-analise-critica-gate00.md](04-analise-critica-gate00.md) e nos ADRs com status `Proposto`.
- Validações com a operação acontecem por entrevista guiada — roteiro na pasta [30](../30-Dominio-da-Dona-Arteira/README.md).
- Validações fiscais com o contador devem ocorrer ANTES do Gate 05 (NF-e).

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Dono acumular todos os papéis e virar gargalo | Documentação e agentes reduzem dependência de memória; decisões registradas |
| Regras fiscais assumidas sem contador | Toda regra fiscal nasce com status `Hipótese` até validação (template BR) |
