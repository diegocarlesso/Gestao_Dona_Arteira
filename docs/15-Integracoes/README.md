# 15 — Integrações (Framework)

> **Status:** Aprovado · **Última atualização:** 2026-08-06 · **Responsável:** integration-specialist
> **Regras:** BR-701…BR-705 · **ADR:** [0007 (sync assíncrona)](../27-ADR/ADR-0007-sync-assincrona.md) · **Template:** [_templates/TEMPLATE-INTEGRACAO.md](../_templates/TEMPLATE-INTEGRACAO.md)

## 1. Objetivo

Definir o padrão único que **toda** integração segue — WooCommerce hoje; SEFAZ, Melhor Envio, WhatsApp, e-mail, marketplaces e gateway amanhã. Integração nova = preencher o template + implementar o padrão; nunca inventar mecanismo novo.

## 2. Princípios (invioláveis)

1. **Nunca banco a banco** (BR-701): só APIs autenticadas.
2. **Camada anticorrupção**: `App\Modules\Integrations\<Sistema>` traduz payloads externos ↔ DTOs internos; domínio não conhece formato externo.
3. **Assíncrono por padrão** (ADR-0007): eventos de domínio → jobs em fila; a operação local nunca espera sistema externo (BR-705). Exceção única: consultas interativas (ex.: cotação de frete em tela) com timeout curto e fallback.
4. **Idempotência em ambas as direções**: entrada deduplicada por `delivery_id`/ID externo; saída segura para retry (upsert por mapping, `Idempotency-Key` quando o parceiro suportar).
5. **Mapeamento persistente**: `integration_mappings` liga entidade local ↔ ID externo (BR-704) com checksum para detectar mudança.
6. **Reconciliação periódica**: webhooks perdem eventos; um job compara estado ERP × externo e corrige/alerta — a sincronização eventual é garantida pela reconciliação, não pela sorte.
7. **Feature flag por integração**: ligar/desligar sem deploy; desligada = jobs acumulam ou descartam conforme configuração documentada.
8. **Credenciais cifradas** no banco/`.env` (cast encrypted), jamais no repositório.

## 3. Anatomia de uma integração

```text
Integrations/<Sistema>/
├── Client.php            # HTTP client: auth, base URL, rate limit, retries de transporte
├── DTOs/                 # espelho tipado do payload externo
├── Adapters/             # tradução DTO externo ↔ entidades/DTOs internos
├── Jobs/                 # PushX / PullX / ReconcileX — pequenos, idempotentes, em lotes
├── Webhooks/             # controller + verificação de assinatura + persistência bruta
└── Mappers/StatusMap.php # tabelas de-para (status, métodos de pagamento…)
```

### Pipeline de saída (ERP → externo)
`Evento de domínio → Listener enfileira Job → Job lê mapping → Adapter monta payload → Client envia → atualiza mapping/checksum → loga em sync_jobs_log`.
Retry: backoff exponencial 1m/5m/30m/2h; esgotou → `IntegrationSyncFailed` (alerta + item fica no painel para reprocesso manual).

### Pipeline de entrada (externo → ERP)
`Webhook → verificação HMAC → grava incoming_webhooks (bruto) → 200 imediato → Job processa → dedupe → Adapter → Service do módulo → mapping`.
Reprocessável: payload bruto guardado 30 dias permite reprocessar após bug fix.

## 4. Catálogo de integrações

| Sistema | Direções | Fase | Criticidade | Doc |
|---|---|---|---|---|
| WooCommerce | bidirecional | 2 | Alta | [pasta 16](../16-WooCommerce/README.md) |
| SEFAZ (NF-e) | ERP→SEFAZ | 5 | Alta | [pasta 14](../14-NFe/README.md) |
| E-mail (SMTP) | saída | 2 | Média | [E-mail transacional](01-email-transacional.md) — confirmação e rastreio; NF-e fica para o Gate 05 |
| Melhor Envio | bidirecional (etiqueta/rastreio) | 6 | Média | template a preencher no Gate 06 |
| Transportadoras diretas | saída | 6+ | Baixa | idem |
| WhatsApp (Meta Cloud API) | saída (notificações) | 7 | Baixa | idem |
| Gateway de pagamento (Mercado Pago) | bidirecional | 4 | Média | 🔧 implementado 2026-08-12 (ADR-0018) — [pasta 12, cobrança](../12-Financeiro/01-cobranca-e-boletos.md) |
| Marketplaces | bidirecional | 7 | Média | um adapter por marketplace, mesmo padrão |

## 5. Observabilidade (obrigatória por integração)

Painel "Integrações" no ERP: status (ok/atenção/falha), última sync por entidade, fila pendente, erros recentes com payload, botão de reprocesso. Métricas mínimas: taxa de sucesso, latência, idade do item mais antigo na fila. Alertas: falha persistente > 30 min → e-mail/notificação (pasta 24).

## 6. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Mudança de API externa quebrar adapter | Média | Alto | DTOs tipados falham cedo; testes de contrato com payloads gravados; versão da API fixada |
| Loop de eco (ERP→Woo→webhook→ERP…) | Média | Médio | Toda escrita marca origem; webhook cujo autor é o próprio ERP é descartado (checksum idêntico) |
| Fila parada sem ninguém ver | Média | Alto | Health check monitora idade da fila (pasta 24) |

## 7. Evoluções futuras

- Webhooks de saída do ERP para parceiros (fase 6+), reutilizando assinatura HMAC.
- Extrair workers para host próprio quando volume exigir (gatilho no ADR-0001/0016).
