# Escopo e Não-Escopo

> **Status:** Aprovado · **Última atualização:** 2026-07-27 · **Responsável:** business-analyst

## 1. Objetivo

Delimitar com precisão o que o ERP faz e — igualmente importante — o que ele deliberadamente **não** faz, para proteger o projeto de inchaço de escopo.

## 2. Dentro do escopo (por fase — ver Roadmap)

| Capacidade | Fase | Observação |
|---|---|---|
| Catálogo de produtos (peças, variações, embalagens, imagens) | 1 | Migrado do WooCommerce |
| Clientes (PF/PJ, varejo/atacado) e fornecedores | 1 | Migrados + saneados |
| Estoque com ledger de movimentos, reservas e inventário | 1–2 | Núcleo do sistema |
| Migração inicial dos dados do WooCommerce | 1 | Idempotente, re-executável |
| Usuários, papéis e permissões (RBAC) | 1 | Deny-by-default |
| Vendas multicanal (balcão/atacado/encomenda + pedidos do site) | 2 | Máquina de estados única |
| Sincronização bidirecional WooCommerce (produtos, estoque, pedidos, clientes) | 2 | Via API/webhooks, nunca banco |
| Produção artesanal (OPs de pintura, etapas, perdas, consumo de tinta/verniz) | 3 | Diferencial do ERP |
| Compras e recebimento de matéria-prima | 3 | Alimenta custo médio |
| Financeiro (contas a pagar/receber, fluxo de caixa, categorias) | 4 | Gerencial, não contábil |
| NF-e modelo 55 com certificado A1 (emissão, cancelamento, CC-e, guarda) | 5 | Homologação primeiro |
| Expedição com Melhor Envio (etiquetas, rastreio) | 6 | Rastreio devolvido ao Woo |
| Relatórios e dashboards | 6 | Catálogo na pasta 20/21 |
| Auditoria completa de mutações | 1+ | Transversal |

## 3. Fora do escopo (explícito)

| Item | Por quê | Alternativa |
|---|---|---|
| Substituir o WordPress/WooCommerce | Princípio fundamental do projeto | Woo permanece como canal |
| Contabilidade formal (SPED, balancetes, lançamentos contábeis) | Complexidade sem retorno; há contador externo | Exportações mensais para o contador |
| Folha de pagamento / RH | Fora do domínio | Sistema do contador |
| NFC-e / cupom fiscal e PDV offline | Canal balcão emite NF-e mod. 55 quando exigido | Reavaliar se abrir loja física com volume |
| CRM de marketing (campanhas, funis) | Woo/ferramentas próprias cobrem | Integração futura se necessário |
| Multi-empresa / multi-tenant | Uma única empresa | Arquitetura não deve impedir no futuro |
| Edição de conteúdo do site (páginas, blog) | Continua no WordPress | — |
| MRP avançado / planejamento de capacidade finita | Sofisticação incompatível com o porte | Sugestão simples de reposição (fase 3) |

## 4. Critério para mudar o escopo

1. Registrar proposta como issue/documento com justificativa de negócio.
2. Analisar impacto em: roadmap, arquitetura (ADR?), custo de manutenção.
3. Aprovação explícita do dono do produto.
4. Atualizar este documento ANTES de qualquer implementação.

## 5. Riscos

| Risco | Mitigação |
|---|---|
| "Só mais um campinho" corroer o modelo de dados | Toda mudança de modelo passa pelo senior-dba + doc 04 atualizado |
| Funcionalidade fora de fase consumir o gate atual | Critérios de saída dos gates são bloqueantes (pasta 28) |
