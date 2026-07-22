# 08 — Formas de Pagamento

> **Status:** Em revisão · **Última atualização:** 2026-07-06 · **Responsável:** financial-specialist / integration-specialist
> **Regras relacionadas:** BR-308 (formas de pagamento), BR-501/502 (títulos/baixa)

## 1. Objetivo

Inventariar os métodos e gateways de pagamento usados no site — insumo para o Financeiro (baixa por forma de pagamento) e para o de-para de pedidos.

## 2. Métodos usados nos pedidos

| Método (`_payment_method_title`) | Pedidos |
|---|---:|
| Pague com PIX | 37 (+2 variante) |
| Débito e crédito | 23 |
| Pague com cartões de crédito | 18 |
| Boleto e lotérica | 5 |

**Consolidado por natureza:**

| Natureza | Pedidos | % |
|---|---:|---:|
| **PIX** | 39 | ~46% |
| **Cartão** (débito/crédito) | 41 | ~48% |
| **Boleto** | 5 | ~6% |

- **PIX e cartão dividem quase toda a operação**; boleto é residual. Coerente com [BR-308](../01-Regras-de-Negocio/01-registro-de-regras.md) (PIX/Cartão/Boleto) e com o desktop (default de pagamento = PIX).

## 3. Gateway: Mercado Pago

Os títulos ("Pague com PIX", "Débito e crédito", "Boleto e lotérica") e o badge HTML `<small class="mp-pix-checkout-title-badge">` identificam o **Mercado Pago** (`woocommerce-mercadopago`) como gateway principal. Também estão **ativos**:

- **Pagaleve** (`wc-pagaleve`) — PIX parcelado / "compre agora, pague depois".
- **Parcelas com e sem juros** (`woo-parcelas-com-e-sem-juros`) — simulação/exibição de parcelamento.
- **Checkout Fees for Woocommerce** — taxas/acréscimos por forma de pagamento.

> ⚠️ **Dado sujo:** os títulos de pagamento contêm **HTML** (`<small ...>Novo</small>`). Na importação, o campo precisa ser **higienizado** para não vazar markup para o ERP.

## 4. Cupom / desconto

- Único cupom da base: **`primeiracompra`** — desconto **percentual de 10%**, **sem valor mínimo** e **sem expiração**, usado em **19 pedidos**.
- É o **único mecanismo promocional** identificado. Regra implícita: **10% na primeira compra**. Ver [17](17-glossario-extraido.md).

## 5. Observações para o Financeiro

- A **baixa de título** deverá reconhecer a forma de pagamento e o gateway (Mercado Pago concentra PIX/cartão/boleto) — [BR-501/502](../01-Regras-de-Negocio/01-registro-de-regras.md).
- **Taxas de gateway** (Mercado Pago/Pagaleve) e acréscimos de parcelamento existem mas **não** aparecem como categoria financeira no dump — levantar com o negócio/contador ([98](98-perguntas-para-o-negocio.md)).
- `woocommerce_calc_taxes = no`: **não há imposto no pagamento** — o valor pago é o valor comercial; a parte fiscal é externa.

## 6. Impacto no ERP / integração

- Mapear `payment_method_title` → forma de pagamento canônica do ERP, **removendo HTML**.
- Reconhecer que **PIX e cartão** são o padrão; prever conciliação com Mercado Pago no Financeiro (evolução).
- Migrar o cupom `primeiracompra` como regra de desconto (ou descontinuar) — decisão de negócio.
