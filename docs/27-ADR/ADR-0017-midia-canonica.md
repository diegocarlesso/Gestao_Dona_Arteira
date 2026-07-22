# ADR-0017: Mídia de produtos — fase 1 no WooCommerce, fase 2 storage próprio

> **Status:** ⚠️ Proposto (aprovar até o Gate 02) · **Data:** 2026-07-03 · **Decisores:** dono, chief-architect
> **Módulos afetados:** 16, 17, 04 (product_images)

## Contexto

As imagens das peças vivem na biblioteca de mídia do WordPress (upload via wp-admin e via FTP pelo legado). O ERP precisa exibi-las e, como mestre do catálogo (ADR-0006), idealmente controlá-las. Porém: migrar toda a mídia para o ERP no Gate 01 consome espaço da hospedagem, exige pipeline de imagens (redimensionamento, variantes para o site) e o Woo continua precisando delas para renderizar a loja.

## Decisão (proposta)

**Fase 1 (Gates 01–05):** a mídia permanece hospedada no WordPress; o ERP guarda **referências** (URLs + IDs de mídia em `product_images.source=woo`) e as exibe. Upload de imagem nova é feito **pelo ERP**, que envia ao Woo via API (o ERP continua sendo a porta de entrada — a política BR-702 se mantém).

**Fase 2 (Gate 06+):** avaliar storage de objetos barato (Cloudflare R2/Backblaze B2) como origem canônica, com o Woo recebendo URLs/cópias. Migração de mídia vira projeto próprio com novo ADR se aprovada.

## Alternativas consideradas

### Migrar toda a mídia para o ERP no Gate 01
Puro conceitualmente (mestre completo), mas: consumo de disco no host do ERP, pipeline de imagem a construir cedo, reenvio de tudo ao Woo (que precisa das imagens localmente para performance da loja) — muito custo antes do núcleo operar. Descartada para fase 1.

### Storage de objetos desde o dia 1
Melhor destino final, porém adiciona fornecedor/custo/complexidade no gate mais crítico (migração de dados). Adiada para fase 2 com avaliação.

## Consequências

**Positivas (fase 1):** migração do Gate 01 fica mais leve e rápida; loja não muda em nada; ERP já centraliza o fluxo de upload.

**Negativas / dívidas:** o mestre do catálogo referencia mídia hospedada no canal (inversão tolerada e documentada); dependência do WP para servir imagens ao ERP (cache local de thumbnails no ERP mitiga); a dívida tem prazo (reavaliação no Gate 06).

**Gatilhos de revisão antecipada:** espaço/performance de mídia no WP virar problema · segundo canal de vendas precisar das imagens (marketplace) → acelera fase 2.
