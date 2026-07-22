# Integração — <Sistema externo>

> **Status:** Planejada | Em desenvolvimento | Ativa | Suspensa
> **Direções:** ERP→Externo | Externo→ERP | Bidirecional
> **Criticidade:** Alta | Média | Baixa · **Última atualização:** AAAA-MM-DD

## 1. Objetivo de negócio

Por que esta integração existe; o que acontece se ela parar por 1h / 1 dia.

## 2. Contrato

- **Protocolo/API:** REST/SOAP/arquivo + versão da API externa
- **Autenticação:** método, onde ficam as credenciais (sempre cifradas)
- **Ambientes:** sandbox/homologação e produção (URLs)
- **Limites:** rate limits, tamanho de payload, janelas de manutenção do parceiro

## 3. Entidades e direção de sincronização

| Entidade | Direção | Gatilho | Frequência | Conflito: quem vence |
|---|---|---|---|---|

## 4. Mecanismo

- Adapter: `App\Modules\Integrations\<Sistema>` — o domínio NUNCA vê payload externo (DTOs próprios).
- Fila/job responsável, política de retry (backoff exponencial, máx. N tentativas), DLQ/parking.
- Idempotência: chave de deduplicação usada.
- Mapeamento de IDs: entradas em `integration_mappings`.
- Reconciliação periódica: job, frequência, relatório de divergência.

## 5. Observabilidade

- Logs (canal, correlação), métricas (última sync, pendências, taxa de erro), alertas e limiares.
- Painel de status: o que o usuário vê em `Integrações` no ERP.

## 6. Modos de falha e degradação

| Falha | Comportamento do ERP | Ação do operador (runbook) |
|---|---|---|

## 7. Segurança

Assinatura de webhooks (HMAC), allowlist de IP se possível, LGPD (quais dados pessoais trafegam e por quê).

## 8. Plano de descontinuação

Como desligar a integração sem corromper dados (feature flag, drenagem de fila).
