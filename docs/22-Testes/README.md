# 22 — Testes

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** qa-specialist
> **Fase:** desde o Gate 01 (CI bloqueante) · NFRs de qualidade: [pasta 03](../03-Arquitetura/02-requisitos-nao-funcionais.md)

## 1. Objetivo

Estratégia de testes que permite a uma equipe de 1 dev evoluir um ERP fiscal-financeiro **sem medo**: a suíte é a rede de segurança que substitui o QA manual que não existe.

## 2. Pirâmide adotada

| Nível | Ferramenta | Cobre | Meta |
|---|---|---|---|
| Unit (domínio) | Pest | invariantes, máquinas de estado, cálculo (custo médio, totais, impostos), BRs | ≥ 80% nos módulos core; **toda BR implementada tem teste nomeado com o ID** |
| Feature (API) | Pest + RefreshDatabase | endpoint completo: auth, validação, regra, resposta, evento disparado | todo endpoint: caminho feliz + 403 por papel + erros de negócio |
| Contrato | validação OpenAPI | resposta real ≡ spec (pasta 07) | endpoints públicos |
| Integração externa | Pest + payloads gravados (fixtures) | adapters Woo/SEFAZ/Melhor Envio com HTTP fake | casos de borda do de-para; **sandbox real** manual antes de cada gate |
| Frontend | Vitest + Testing Library | componentes críticos: formulários com máscara/validação, tabelas, guards de permissão | fluxos de formulário principais |
| E2E | Playwright (fase 2+) | 5 fluxos de fumaça: login, venda balcão, pedido Woo simulado, apontar OP, emitir NF-e homolog | roda no CI noturno |

## 3. Regras

1. **Teste nomeia a regra**: `it('BR-201: rejeita movimento que negativaria o saldo')` — rastreabilidade regra↔teste (pasta 01).
2. PR sem teste para código de domínio novo não passa (checklist das skills).
3. Fixtures fiscais versionadas: XMLs de exemplo (autorizada, rejeições comuns) para testar o fluxo NF-e sem SEFAZ.
4. Teste de migração: importadores rodam 2× no CI sobre dump-fixture → assert de não-duplicação (BR-706).
5. Job/listener: teste de idempotência (executar 2× = 1 efeito).
6. Dados de teste em factories com estados nomeados (`Order::factory()->paid()`); nunca depender de seed de produção.
7. Testes rodam em MariaDB real no CI (mesma engine de produção — SQLite mente sobre locks/collation).

## 4. Qualidade estática (mesma esteira)

Pint (formatação) · PHPStan/larastan nível 8 · ESLint+tsc `--noEmit` · `composer audit`/`npm audit` (falha em vulnerabilidade alta). Tudo bloqueante no CI (pasta 23).

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Suíte lenta desencorajar execução | paralelização Pest; unit rápido separado de feature; meta < 5 min no CI |
| Testes de integração acoplados a payload real do Woo quebrarem por ruído | fixtures mínimas com apenas campos usados; contrato do adapter testado, não o Woo inteiro |
| Cobertura virar métrica de vaidade | cobertura é guard-rail; revisão foca em BRs testadas, não % |

## 6. Evoluções futuras

- Mutation testing (Infection) nos módulos de cálculo (fase 6) · testes de carga leves antes de picos (Natal) com k6 (fase 5–6).
