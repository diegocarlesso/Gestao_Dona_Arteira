---
name: sync-clientes
description: Implementa/ajusta a sincronização de clientes Woo→ERP (novos cadastros e compradores convidados, com deduplicação por e-mail/CPF) e correções cadastrais ERP→Woo. Use para fluxo de clientes entre site e ERP, incluindo LGPD.
---

# Skill: Sincronização de Clientes

## Objetivo
Todo comprador do site vira (ou casa com) UM cliente no ERP — sem duplicatas — e correções cadastrais feitas no ERP refletem no site quando aplicável.

## Pré-requisitos
1. Integração base ativa; webhooks `customer.created/updated` registrados.
2. Regra de dedupe definida (docs/17 §F3 + 16/01): doc válido > e-mail > mais recente.
3. Metadados do plugin de checkout BR identificados (campo exato de CPF/CNPJ — pendência da doc 16/01 resolvida).

## Entradas
Webhooks de cliente; pedidos de convidado (guest checkout — cliente vem no payload do pedido).

## Fluxo
1. Webhook/pedido → job idempotente `UpsertCustomerFromWoo`.
2. Resolução de identidade nesta ordem: mapping existente → doc (só dígitos, validado por DV — BR-001) → e-mail (case-insensitive) → cria novo com `origin=woo`.
3. Casou com existente: enriquecer campos vazios (nunca sobrescrever dado preenchido no ERP — ERP vence em correções); registrar mapping.
4. Convidado sem conta: cliente criado/casado pelo e-mail do pedido; sem mapping de customer id (não existe no Woo) — flag `guest`.
5. Doc inválido no site: cliente entra com pendência fiscal marcada (não bloqueia venda; bloqueia NF-e até corrigir — BR-001).
6. ERP→Woo: apenas correções cadastrais de clientes mapeados com conta (job sob demanda, não automático em massa).
7. LGPD (docs/25 §3): sync traz apenas dados necessários (nome, doc, contato, endereços); anonimização no ERP NÃO propaga delete ao Woo (registrar limitação na doc 16).
8. Testes: matriz de dedupe (doc×e-mail×mapping), convidado, doc inválido, não-sobrescrita.

## Saídas
Jobs de upsert + resolução de identidade testada + relatório de merges da migração/operação.

## Critérios mínimos
Zero clientes duplicados criados pela sync (teste de matriz completo); dados do ERP nunca degradados por payload do site.

## Checklist final
- [ ] Ordem de resolução (mapping→doc→e-mail) testada caso a caso?
- [ ] Campo de CPF/CNPJ do plugin real mapeado e documentado?
- [ ] Enriquecer-sem-sobrescrever testado?
- [ ] Convidados casados por e-mail sem duplicar?
- [ ] Pendência fiscal marcada para doc inválido (e visível no painel)?
