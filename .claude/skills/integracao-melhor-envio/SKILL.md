---
name: integracao-melhor-envio
description: Implementa a integração com o Melhor Envio (cotação, compra de etiqueta, rastreio) para a expedição do ERP, sobre o framework da pasta 15. Use no Gate 06 ou ao trabalhar em frete/etiquetas/rastreamento.
---

# Skill: Integração Melhor Envio

## Objetivo
Da embalagem à etiqueta sem sair do ERP: cotar frete, comprar/imprimir etiqueta e acompanhar rastreio — devolvendo o código ao pedido (e ao Woo).

## Pré-requisitos
1. Framework da pasta 15 + template de integração preenchido (`docs/_templates/TEMPLATE-INTEGRACAO.md`) e salvo em `docs/15-Integracoes/` ANTES do código.
2. Conta Melhor Envio + app OAuth2 criado; tokens (access/refresh) armazenados cifrados com renovação automática.
3. Peças com embalagem padrão dimensionada (BR-004) — o cálculo usa dimensões/peso da EMBALAGEM.
4. **Sandbox** do Melhor Envio validado antes de produção.

## Entradas
Credenciais OAuth, pedido embalado (dimensões/peso finais), endereço validado do destinatário (BR-306).

## Fluxo
1. `Integrations/MelhorEnvio/`: Client OAuth2 (refresh transparente), DTOs (cotação, etiqueta, rastreio).
2. Cotação: chamada interativa (timeout curto, fallback "informar frete manual" — exceção síncrona prevista no ADR-0007) na tela de expedição; opções exibidas com prazo+preço.
3. Compra de etiqueta: job idempotente (carrinho→checkout→geração); PDF armazenado e vinculado ao `shipment`; custo lançado como despesa de frete (categoria automática).
4. Rastreio: código salvo no shipment → evento → status/rastreio devolvidos ao Woo (skill sync-pedidos) + e-mail ao cliente.
5. Polling agendado de rastreios ativos (sem webhook confiável) → atualiza timeline do pedido; entrega detectada → `Entregue`.
6. Falhas: saldo insuficiente/serviço fora → alerta acionável + fluxo manual documentado (a expedição NUNCA para por causa da integração — BR-705).
7. Testes com fixtures do sandbox; teste de refresh de token expirado.

## Saídas
Módulo de integração + tela de cotação/etiqueta + doc da integração preenchida + runbook de falhas.

## Critérios mínimos
Etiqueta comprada 1× mesmo com retry (idempotência); fluxo manual alternativo sempre disponível; custo de frete visível no financeiro.

## Checklist final
- [ ] Template de integração preenchido antes do código?
- [ ] OAuth com refresh automático testado? Tokens cifrados?
- [ ] Cotação com timeout+fallback manual?
- [ ] Compra de etiqueta idempotente (teste 2×)?
- [ ] Rastreio → Woo + cliente notificado?
- [ ] Sandbox validado de ponta a ponta antes de produção?
