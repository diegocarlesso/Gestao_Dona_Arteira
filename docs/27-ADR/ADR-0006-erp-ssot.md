# ADR-0006: ERP como Single Source of Truth; WooCommerce como canal

> **Status:** Aceito (princípio fundacional do projeto) · **Data:** 2026-07-03 · **Decisores:** dono do produto
> **Módulos afetados:** todos; especialmente 09, 10, 15, 16, 17

## Contexto

Hoje o WooCommerce é o único sistema "vivo" com dados de produtos/clientes/pedidos, e o desktop opera isolado. Dois mestres = estoque divergente e retrabalho. O projeto define: o WordPress **não será substituído** (continua sendo o e-commerce), mas não pode continuar sendo o dono dos dados.

## Decisão

Após a migração e o cutover (pasta 17): o **ERP é o sistema mestre** de produtos, preços, estoque, clientes e do fulfillment; o WooCommerce é **canal de vendas** — recebe catálogo/estoque, origina pedidos. Toda sincronização via API/webhooks (BR-701); conflitos se resolvem a favor do ERP, exceto o fato "pedido criado no canal" (BR-703). Editar catálogo no wp-admin passa a ser proibido por política (BR-702).

## Alternativas consideradas

### Woo continua mestre; ERP consome
O ERP viraria relatório glorificado: produção/estoque/fiscal exigem dono único dos dados operacionais. Descartada.

### Mestres por entidade (Woo dono do catálogo; ERP do estoque)
Fronteira confusa na prática (preço é catálogo ou comercial?); dupla edição garantida. Descartada.

### Sincronização por acesso direto ao banco WP
Frágil (esquema interno do Woo muda), perigosa (locks, integridade), proibida pelo projeto. Descartada.

## Consequências

**Positivas:** uma verdade operacional; catálogo/estoque/pedidos coerentes em todos os canais; adicionar marketplace = mais um canal no mesmo padrão.

**Negativas / dívidas:** mudança de hábito da equipe (política BR-702 + reconciliação vigilante); dependência da qualidade da sync (mitigada pela pasta 15/16).

**Gatilhos de revisão:** nenhum — princípio do projeto. Troca da plataforma de e-commerce não altera o princípio (só o adapter).
