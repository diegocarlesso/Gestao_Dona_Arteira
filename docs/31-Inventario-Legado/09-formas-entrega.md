# 09 — Formas de Entrega

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** integration-specialist / sales-specialist
> **Regras relacionadas:** BR-004 (embalagem/frete), BR-306 (retirada/envio) · **Integrações:** Melhor Envio (pasta 15)

## 1. Objetivo

Inventariar como os pedidos são entregues — métodos, transportadoras e zonas — insumo para a expedição, o cálculo de frete e a integração de logística.

## 2. Métodos usados (nos 85 pedidos)

Cada pedido tem uma linha de `shipping`. Consolidando os métodos:

| Grupo | Pedidos | % |
|---|---:|---:|
| Melhor Envio — **Jadlog** (Package/.Com) | 34 | ~40% |
| **Retirada no local** ("Retirar/Retirada no local") | 32 | ~38% |
| Melhor Envio — **Correios** (PAC/Sedex) | 15 | ~18% |
| **Frete grátis** | 3 | ~4% |
| Melhor Envio — **Loggi** | 1 | ~1% |

*(Soma exata = 85; os percentuais totalizam ~100%.)*

- **Jadlog (~40%) e Retirada no local (~38%) são as duas opções dominantes, praticamente empatadas.** A alta fatia de retirada é consequência direta da concentração em **RS/Jacutinga** ([06](06-clientes.md)) — o ateliê **vende no balcão** e muita gente **busca**.
- O envio usa **Melhor Envio** (`melhor-envio-cotacao`) como agregador, com **Jadlog** claramente à frente dos Correios — casa com a [integração Melhor Envio](../15-Integracoes/README.md) já prevista.

## 3. Zonas de entrega configuradas

| Zona | Métodos |
|---|---|
| **Brasil** (nacional) | Melhor Envio (Correios PAC/Sedex, Jadlog Package/.Com) + Frenet |
| **Região Sul e Sudeste** | Melhor Envio (Correios, Jadlog, Loggi Express) + Frenet |
| **Região Norte Gaúcho** | **Retirada no local** (`local_pickup`) |
| **Região local** | **Retirada no local** (`local_pickup`) |

A existência de uma zona **"Região Norte Gaúcho"** com retirada confirma a lógica geográfica: **quem é do entorno de Jacutinga retira**; o resto recebe por transportadora. Há dois agregadores configurados — **Melhor Envio** (ativo) e **Frenet** (método presente nas zonas, plugin não listado entre os ativos).

## 4. Transportadoras / plugins de frete

- **Melhor Envio** (`melhor-envio-cotacao`) — ativo, principal.
- **WooCommerce Correios** (`woocommerce-correios`) — ativo (cotação Correios direta); tabela `correios_postcodes` presente mas **vazia** (cache não populado).
- **Frenet** — método configurado em zonas (agregador alternativo).

## 5. Dados de qualidade

- ⚠️ **Nomes de método inconsistentes**: convivem "Retirar no local" e "Retirada no local"; e o mesmo serviço aparece como "(Melhor Envio)" e sem sufixo, com prazos embutidos no nome ("(4 a 5 dias úteis)"). Na migração de histórico, **normalizar** para um método canônico + prazo separado.
- Todo pedido tem embalagem/peso implícitos no cálculo do Melhor Envio — reforça [BR-004](../01-Regras-de-Negocio/01-registro-de-regras.md) (frete calculado pela **embalagem**, cujas dimensões o desktop cataloga em `packages`).

## 6. Impacto no ERP / expedição

- Modelar **Retirada** e **Envio** como métodos de fulfillment ([BR-306](../01-Regras-de-Negocio/01-registro-de-regras.md)); **Retirada é caso de uso central** (não secundário).
- Integração de expedição via **Melhor Envio** (cotação/etiqueta/rastreio) — pasta 15/skill `integracao-melhor-envio`.
- Normalizar métodos de frete do histórico; migrar rastreio (se houver) — hoje sem plugin de rastreio ativo (o `EWD_OTP` de order tracking está **inativo**, ver [10](10-plugins.md)).
