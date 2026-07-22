---
name: criar-service
description: Cria um Service/Action de caso de uso no backend do ERP — dono da transação e das regras de orquestração, disparando eventos de domínio. Use para implementar qualquer operação de negócio (confirmar pedido, registrar movimento, apontar produção…).
---

# Skill: Criar Service

## Objetivo
Um caso de uso = um Service nomeado (`ConfirmOrderService`), que orquestra domínio e repositórios dentro de UMA transação, dispara eventos e devolve resultado tipado (ADR-0015).

## Pré-requisitos
1. Caso de uso descrito no doc do módulo; BRs envolvidas registradas (senão → business-analyst).
2. Models/invariantes existentes (skill `criar-model`).
3. Evento(s) a disparar previstos no catálogo `docs/02-Dominio/01-eventos-de-dominio.md` (adicionar lá se novo).

## Entradas
Nome do caso de uso, módulo, pré-condições, efeitos (mutações + eventos), comportamento em falha.

## Fluxo
1. Classe em `app/Modules/<X>/Services` com método único (`handle`/`execute`) e DTO/parâmetros tipados — nada de Request HTTP aqui.
2. Pré-condições primeiro: validar estado do domínio; violação → exceção de domínio tipada com código estável (`sales.invalid_transition`).
3. `DB::transaction` envolvendo TODAS as mutações do caso de uso; locks pessimistas onde as convenções exigem (saldo, numeração fiscal — docs/04/02 §Transações).
4. **Nenhuma chamada HTTP externa dentro da transação** (persistir intenção → job).
5. Eventos de domínio despachados após commit (`DB::afterCommit`) com payload mínimo.
6. Efeitos colaterais (sync, e-mail) ficam em listeners/jobs idempotentes — nunca inline.
7. Testes: caminho feliz + cada pré-condição violada (409) + eventos emitidos + idempotência se aplicável.

## Saídas
Service + exceções tipadas + testes citando BRs + doc do módulo atualizado se o fluxo mudou.

## Critérios mínimos
Ler o Service conta o caso de uso completo; transação única; zero conhecimento de HTTP/payload externo.

## Checklist final
- [ ] 1 Service = 1 caso de uso nomeado pelo negócio?
- [ ] Transação cobre todas as mutações — e nada além (sem HTTP dentro)?
- [ ] Exceções de domínio com código estável mapeável pela API?
- [ ] Eventos após commit, payload mínimo, registrados no catálogo?
- [ ] Testes: feliz + violações + eventos? BRs citadas?
- [ ] Service sem regra nenhuma? → não deveria existir (chame o model/Eloquent direto).
