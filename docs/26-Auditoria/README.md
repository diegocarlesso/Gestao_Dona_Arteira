# 26 — Auditoria

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** security-specialist
> **Regras:** BR-802 · **ADR:** [0012 (laravel-auditing)](../27-ADR/ADR-0012-auditoria.md) · **Fase:** Gate 01 (transversal desde o início)

## 1. Objetivo

Responder com evidência a qualquer "quem mudou isso, quando e de que valor para qual?" — exigência de um sistema com estoque, dinheiro e documentos fiscais. Auditoria completa é princípio do projeto.

## 2. O que é auditado

| Categoria | Eventos | Mecanismo |
|---|---|---|
| Mutações de dados de negócio | create/update/delete/restore de produtos, preços, clientes, pedidos, títulos, OPs, configurações | `owen-it/laravel-auditing` → tabela `audits` (old/new JSON, user, ip, user_agent) |
| Fatos de domínio | eventos do catálogo da [pasta 02](../02-Dominio/01-eventos-de-dominio.md) (transições de pedido, movimentos, emissões) | tabelas próprias dos módulos (`order_status_history`, `inventory_movements`, `fiscal_document_events`) — o fato JÁ É a auditoria |
| Segurança | login ok/falha, logout, troca de senha, 2FA, `PermissionDenied`, criação/suspensão de usuário, mudança de papel | canal dedicado `security_events` |
| Ações sensíveis (BR-802) | ajuste de estoque, cancelamento de NF-e, estorno financeiro, mudança de preço em massa, reprocesso de integração | auditoria + **motivo obrigatório** digitado pelo autor |

## 3. Propriedades

- **Imutável**: nenhuma rota/serviço edita ou apaga `audits`/`security_events`; expurgo só por rotina de retenção (pasta 04: 2 anos online, arquivamento depois).
- **Atribuível**: toda entrada tem autor (usuário nominal — por isso contas compartilhadas são proibidas, pasta 18) ou `system`/nome do job.
- **Consultável**: tela "Trilha de auditoria" (permissão `audit.view`, só admin): filtro por entidade, autor, período, tipo; linha do tempo por registro ("ver histórico" em toda ficha).
- **Íntegra**: auditoria roda na mesma transação da mutação — mutação sem trilha não é commitada.

## 4. Dependências

Pasta 18/19 (autor nominal + permissão), pasta 04 (retenção/volume), pasta 25 (LGPD: trilha minimiza dados pessoais — guarda IDs e diffs, não cópias integrais de documentos).

## 5. Boas práticas

- Diffs legíveis na UI (de → para, com formatação pt-BR), não JSON cru.
- Campos sensíveis (senha, tokens) **excluídos** do diff por configuração.
- Relatório mensal de ações sensíveis para o dono (rotina de compliance leve).

## 6. Riscos

| Risco | Mitigação |
|---|---|
| Volume da tabela `audits` crescer rápido | retenção/arquivamento (pasta 04); índices por entidade+data; sem auditoria de tabelas de log |
| Falsa sensação de segurança (auditar ≠ impedir) | auditoria complementa permissões/alçadas, nunca as substitui |

## 7. Evoluções futuras

- Exportação assinada (hash encadeado) se surgir exigência de não-repúdio forte · alertas sobre padrões anômalos (muitos ajustes do mesmo autor) — fase 7.
