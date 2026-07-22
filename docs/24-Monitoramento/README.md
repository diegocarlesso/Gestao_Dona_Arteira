# 24 — Monitoramento e Observabilidade

> **Status:** Em revisão · **Última atualização:** 2026-07-03 · **Responsável:** devops-specialist
> **Fase:** Gate 02+ (health check desde o Gate 01) · Template de runbook: [_templates/TEMPLATE-RUNBOOK.md](../_templates/TEMPLATE-RUNBOOK.md)

## 1. Objetivo

Saber que algo quebrou **antes** do cliente ou da SEFAZ avisarem — com ferramentas compatíveis com o ambiente (sem APM caro, sem root): logs estruturados, health checks, métricas de fila/sync e alertas acionáveis.

## 2. Os quatro pilares

### Health check — `GET /api/health` (Gate 01)
JSON com status por dependência: banco (SELECT 1), fila (idade do job mais antigo), storage (escrita), última reconciliação Woo, **dias para vencer o certificado A1**, espaço em disco. Consumido por UptimeRobot (ou similar free) a cada 5 min → alerta por e-mail/push se down ou degradado.

### Logs estruturados
JSON por linha (canal por módulo: `sales`, `fiscal`, `integrations`…), com `correlation_id` propagado do request ao job (pasta 03). Rotação diária, 30 dias online. Sem dado sensível em log (LGPD — pasta 25): documento mascarado, nunca senha/token/conteúdo de certificado.

### Erros — Sentry (plano free)
Exceções não tratadas do backend e do frontend com release tag e correlation_id. Erro novo em produção → e-mail imediato.

### Métricas de negócio-operação (painel Integrações + home)
Idade da fila, falhas de sync 24 h, NF-e rejeitadas do dia, tempo médio de emissão, divergências da última reconciliação. Persistidas em tabela simples (`ops_metrics`) — sem stack de métricas externa por ora.

## 3. Alertas (todos acionáveis, com runbook)

| Alerta | Limiar | Canal | Runbook |
|---|---|---|---|
| ERP fora do ar | 2 checks seguidos | e-mail + push | RB-01 site fora |
| Fila parada | job mais antigo > 10 min | e-mail | RB-02 fila |
| Sync Woo falhando | > 30 min de falhas | e-mail | RB-03 sync |
| NF-e rejeitada | imediato | e-mail + painel | RB-04 rejeição |
| SEFAZ indisponível | 3 falhas de transmissão | painel + e-mail | RB-05 contingência (pasta 14) |
| Certificado A1 | 30/15/7 dias p/ vencer | e-mail | RB-06 renovação |
| Disco > 80% | diário | e-mail | RB-07 limpeza/expurgo |
| Backup falhou | na execução | e-mail | RB-08 backup |
| Divergência Σ movimentos ≠ saldo | reconciliação noturna | e-mail crítico | RB-09 integridade estoque |

Runbooks numerados vivem nesta pasta (`RB-xx-*.md`), escritos quando cada capacidade nascer — usando o template. Regra: **alerta sem runbook não entra em produção** (alarme que ninguém sabe tratar vira ruído ignorado).

## 4. Dependências

Pasta 23 (health check no deploy), pasta 15 (métricas de integração), ADR-0016 (VPS ampliaria opções — node exporter etc., não requisito).

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Fadiga de alerta (muitos e-mails) → ignorar todos | Poucos alertas, todos acionáveis; revisão mensal: alerta que não gerou ação 3× é recalibrado |
| Logs crescerem sem controle no shared | rotação + expurgo agendado (pasta 04, retenção) |

## 6. Evoluções futuras

- Uptime Kuma self-hosted (se VPS) · notificação push via app (fase 7) · dashboards de métricas históricas (fase 6).
