---
name: integracao-woocommerce
description: Configura ou evolui a integração base com o WooCommerce (client REST v3, webhooks assinados, mapeamentos, reconciliação) sobre o framework da pasta 15. Use para trabalhos na infraestrutura da integração Woo; para fluxos específicos use as skills sync-* correspondentes.
---

# Skill: Integração WooCommerce (base)

## Objetivo
Montar/manter a fundação da integração: client autenticado, webhooks de entrada seguros, tabelas de mapeamento e reconciliação — sobre a qual as syncs de produtos/estoque/clientes/pedidos operam.

## Pré-requisitos
1. Docs lidos: `docs/16-WooCommerce/README.md` + `docs/15-Integracoes/README.md` (framework) + de-para `docs/16-WooCommerce/01-mapeamento-de-campos.md`.
2. Chaves REST do Woo (consumer key/secret) com escopo read/write, cadastradas CIFRADAS em `integration_settings` — nunca em código/repositório.
3. Staging do WordPress disponível para testes (obrigatório antes de produção).

## Entradas
Credenciais, URL da loja, lista de webhooks a registrar, feature flag da integração.

## Fluxo
1. `Integrations/WooCommerce/Client.php`: base URL `/wp-json/wc/v3/`, auth, timeout curto, retry de transporte (429/5xx com backoff), rate limiting em lotes ~20 (docs/16 §2).
2. DTOs tipados espelhando payloads usados (produto, pedido, cliente) — só os campos consumidos.
3. Webhooks de entrada: endpoint `POST /api/webhooks/woocommerce`, verificação HMAC-SHA256 do secret, persistência bruta em `incoming_webhooks` com `delivery_id` UNIQUE, resposta 200 imediata, processamento em job.
4. Registro dos webhooks no Woo via API (order.created/updated, customer.created/updated) com secret forte gerado.
5. Anti-eco: toda escrita do ERP no Woo registra checksum no mapping; webhook com checksum idêntico é descartado.
6. Job de reconciliação agendado (madrugada) comparando checksums ERP×Woo → correções conforme tabela de conflitos (docs/16 §3) + relatório.
7. Painel de integrações: status, última sync, pendências, reprocesso manual.
8. Testes com fixtures de payloads reais anonimizados; teste de assinatura inválida (401) e de dedupe.

## Saídas
Client + DTOs + webhook handler + reconciliação + painel + feature flag + testes.

## Critérios mínimos
BR-701 (só API), BR-703/704 (idempotência+mapping) e BR-705 (falha não trava operação) verificáveis por teste; assinatura sempre validada.

## Checklist final
- [ ] Credenciais cifradas + flag on/off funcional?
- [ ] Webhook: HMAC + bruto persistido + 200 imediato + dedupe testado?
- [ ] Retry/backoff + parking com alerta após esgotar?
- [ ] Anti-eco testado (update do ERP não volta como mudança)?
- [ ] Reconciliação corrige e reporta conforme "quem vence" da doc 16?
- [ ] Testado no staging Woo de ponta a ponta?
