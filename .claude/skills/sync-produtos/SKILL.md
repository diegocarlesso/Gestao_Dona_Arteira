---
name: sync-produtos
description: Implementa/ajusta a sincronização de catálogo ERP→WooCommerce (dados, preço varejo, categorias, imagens, publicação/arquivamento) conforme o de-para da doc 16/01. Use para publicar/atualizar produtos no site a partir do ERP.
---

# Skill: Sincronização de Produtos

## Objetivo
Catálogo editado SOMENTE no ERP (BR-702) e refletido no site: dados, preço de varejo, categorias, imagens e status de publicação — ERP sempre vence.

## Pré-requisitos
1. Integração base ativa (skill `integracao-woocommerce`); mapeamentos de categoria prontos.
2. De-para de campos atualizado (`docs/16-WooCommerce/01-mapeamento-de-campos.md`) — divergência encontrada = atualizar a doc PRIMEIRO.
3. Política de mídia conforme ADR-0017 (fase 1: upload via ERP → API do Woo; mídia hospedada no WP).

## Entradas
Eventos `ProductCreated/Updated/Archived`, `PriceChanged`; flag `sell_on_woo` por produto.

## Fluxo
1. Listener → job `PushProductToWoo` por produto, idempotente, com checksum (pular se nada mudou).
2. Payload conforme de-para: name/description(HTML sanitizado)/`regular_price`(APENAS varejo — atacado nunca vai ao site)/peso+dimensões **da embalagem** (BR-004)/categorias por mapping/status publish↔active.
3. Produto novo com `sell_on_woo`: cria no Woo → grava mapping; arquivado → `status=draft` no Woo (nunca delete — preserva histórico de pedidos do site).
4. Imagens: upload/ordenação via API de mídia; refs em `product_images`; falha de imagem não bloqueia dados (retry separado).
5. Variações (se inventário confirmar uso): mapear variação↔SKU próprio — cada variação é linha de estoque própria.
6. Reconciliação: checksum de campos sincronizados; edição indevida no wp-admin → sobrescreve + alerta nominal (BR-702).
7. Testes: de-para campo a campo com fixture, idempotência, arquivamento, produto sem SKU (bloqueado com erro claro).

## Saídas
Job de push + criação/arquivamento + sync de imagens + testes + doc 16/01 em dia.

## Critérios mínimos
Preço atacado jamais vaza ao site (teste explícito); MP/insumos jamais sincronizam (kind ≠ finished_good/resale); ERP vence toda divergência.

## Checklist final
- [ ] De-para da doc 16/01 seguido campo a campo (e atualizado se mudou)?
- [ ] Checksum evita pushes redundantes?
- [ ] Arquivar = draft no Woo (nunca delete)?
- [ ] Dimensões/peso da EMBALAGEM enviados (frete correto no checkout)?
- [ ] Teste garantindo que wholesale_price nunca aparece no payload?
