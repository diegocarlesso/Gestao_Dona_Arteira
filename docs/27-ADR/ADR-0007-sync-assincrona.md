# ADR-0007: Sincronização assíncrona — filas + idempotência + mapeamento + reconciliação

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** integration-specialist, chief-architect
> **Módulos afetados:** 15, 16, 03

## Contexto

Integrações (Woo hoje; Melhor Envio, WhatsApp, marketplaces depois) dependem de redes e sistemas de terceiros que falham. A operação local não pode travar porque o site está lento (BR-705); eventos de webhook chegam duplicados/fora de ordem/nunca.

## Decisão

Toda integração segue o pipeline: **evento de domínio → job em fila (retry com backoff) → adapter → API externa**, entrada por **webhook persistido bruto + processamento assíncrono deduplicado**, estado ligado por **`integration_mappings`** com checksum, e **reconciliação periódica** como garantia final de convergência. Framework completo na pasta 15.

## Alternativas consideradas

### Chamadas síncronas inline (salvar produto → chamar Woo na hora)
Simples, mas: latência do terceiro vira latência da UI; falha do terceiro vira erro do usuário; retry manual. Viola BR-705. Descartada (exceção: consultas interativas com timeout, ex.: cotação de frete).

### Mensageria dedicada (RabbitMQ/SQS)
Infra que o ambiente não oferece e o volume não exige. A fila do Laravel entrega o padrão; o driver é detalhe (ADR-0014). Descartada por ora.

### Sincronização apenas por polling agendado
Latência alta (estoque desatualizado por minutos/horas) e carga desnecessária. Polling fica como **reconciliação**, não como mecanismo primário.

## Consequências

**Positivas:** UI sempre rápida; falhas externas viram retries invisíveis; auditoria completa de sincronização; padrão único reutilizado por toda integração futura.

**Negativas / dívidas:** consistência eventual entre ERP e canais (janela < 2 min por NFR) — mitigada por reserva imediata + buffer (BR-203/204); mais peças (fila, painel, reconciliação) para manter.

**Gatilhos de revisão:** volume de eventos > capacidade da fila database (ver gatilhos do ADR-0014).
