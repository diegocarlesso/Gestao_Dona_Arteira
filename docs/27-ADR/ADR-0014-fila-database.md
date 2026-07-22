# ADR-0014: Fila com driver database (sem Redis)

> **Status:** Aceito com gatilhos · **Data:** 2026-07-03 · **Decisores:** devops-specialist, chief-architect
> **Módulos afetados:** 15, 23, 24

## Contexto

A arquitetura assíncrona (ADR-0007) exige fila. A hospedagem alvo (Hostinger) não oferece Redis gerenciado no plano considerado; o volume estimado é baixo (centenas de jobs/dia: syncs, e-mails, NF-e). Adicionar um serviço externo de fila criaria dependência de rede e custo.

## Decisão

**Driver `database`** do Laravel para a fila (tabela `jobs` + `failed_jobs`), com: jobs pequenos e idempotentes, `--max-time` compatível com os limites do host, monitor de idade da fila (pasta 24) e painel de reprocesso (pasta 15). Scheduler via cron. Modo de execução dos workers depende do ADR-0016 (supervisor no VPS vs cron no shared).

## Alternativas consideradas

### Redis (fila + cache)
Melhor latência/concorrência e desbloqueia Horizon — mas indisponível no ambiente atual; exigiria VPS (reforça ADR-0016) ou serviço externo (latência/custo). Adotar **quando** o ambiente tiver Redis é evolução natural e barata (trocar driver).

### Serviços gerenciados (SQS)
Custo/complexidade AWS para volume mínimo; latência para fora do host. Descartada por ora.

### Sync (sem fila)
Viola ADR-0007/BR-705. Descartada.

## Consequências

**Positivas:** zero infra extra; transacionalidade com o banco (job enfileirado na mesma transação do fato); suficiente para o volume por larga margem.

**Negativas / dívidas:** polling no banco (carga marginal); sem dashboard Horizon (painel próprio simples na pasta 15); concorrência de workers limitada.

**Gatilhos de revisão (migrar para Redis):**
- Idade do job mais antigo > 5 min de forma recorrente com workers saudáveis.
- Necessidade real de rate limiting distribuído/throttling fino por integração.
- VPS provisionado (ADR-0016) → adotar Redis já na configuração inicial é recomendado.
