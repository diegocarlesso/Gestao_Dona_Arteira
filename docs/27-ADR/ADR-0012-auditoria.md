# ADR-0012: Auditoria via owen-it/laravel-auditing + fatos de domínio próprios

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** security-specialist, chief-architect
> **Módulos afetados:** 26, 04

## Contexto

"Auditoria completa" é princípio do projeto: quem mudou o quê, quando, de que valor para qual — em estoque, preços, pedidos, financeiro e fiscal. Parte disso já é estrutural (ledger de estoque, histórico de status, eventos fiscais); falta a trilha genérica de mutações de cadastro/configuração.

## Decisão

Estratégia em duas camadas (pasta 26): **fatos de domínio têm tabelas próprias** (movimentos, `order_status_history`, `fiscal_document_events` — o fato é a auditoria) e **mutações genéricas usam `owen-it/laravel-auditing`** (tabela `audits` com old/new, autor, IP), na mesma transação da mutação. Ações sensíveis exigem motivo digitado (BR-802). Trilha imutável com retenção da pasta 04.

## Alternativas consideradas

### Triggers de banco
Capturam tudo, mas sem contexto de aplicação (usuário logado, motivo, correlation id) e difíceis de versionar/testar. Descartada.

### Log de auditoria em arquivo
Não consultável relacionalmente, frágil para retenção/integridade. Descartada.

### Somente eventos de domínio (sem diff genérico)
Perderia mudanças de cadastro/configuração que não têm evento próprio. As duas camadas se complementam.

## Consequências

**Positivas:** cobertura total com esforço baixo; tela de histórico por registro sai "de graça"; conformidade com BR-802.

**Negativas / dívidas:** volume da tabela `audits` (retenção + arquivamento planejados); atenção para excluir campos sensíveis do diff (configuração revisada em code review).

**Gatilhos de revisão:** exigência externa de não-repúdio criptográfico → hash encadeado/export assinado (pasta 26, evolução).
