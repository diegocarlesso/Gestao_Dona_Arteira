# Dona Arteira ERP — Regras do Projeto

ERP para **pintura artesanal e venda** de peças decorativas em gesso. Idioma do projeto: **português (pt-BR)** — código em inglês, documentação/commits/UI em português.

> ⚠️ **A Dona Arteira NÃO fabrica as peças** (confirmado pelo dono em
> 2026-07-27). Compra as peças **prontas, mas cruas** — sem pintura, e
> nem sempre secas — de fornecedores. A produção é **pintura +
> acabamento**, não fundição: não há moldes nem consumo de gesso para
> moldar. A secagem é **quarentena pós-recebimento** (peça úmida), não
> etapa pós-fundição. A documentação de Produção (08), Compras (11),
> Domínio (30), Estoque (09), modelo de dados (04) e as BR-1xx **já foram
> remodeladas** para essa premissa em 2026-07-27; as decisões estão nos
> **[ADR-0023](docs/27-ADR/ADR-0023-producao-e-pintura-nao-fundicao.md)**
> (produção é pintura) e **[ADR-0024](docs/27-ADR/ADR-0024-quarentena-de-secagem.md)**
> (quarentena de secagem), ainda `Proposto` aguardando o aval do dono. O
> ledger de estoque (ADR-0008) **não muda**: peça crua é um `kind` de
> produto e a pintura é `production_input`(crua+tinta) →
> `production_output`(acabado).

## Estado atual (2026-07-28)

**Gate 00 e Gate 01 concluídos.** A F5 da migração foi executada em
produção e **assinada pelo dono em 2026-07-28** (754 = 754, amostra de 30
sem divergências) — último critério de saída do Gate 01. Tag `gate-01`.
Implementado, testado e em produção:

| Módulo | Estado |
|---|---|
| Identity | ✅ auth, 2FA TOTP, RBAC, auditoria, convite (ADR-0021/0011/0012) |
| Catalog | ✅ 754 produtos migrados do Woo, SKU imutável, preço histórico |
| Integrations/WooCommerce | ✅ extração → staging → triagem → carga (F2–F4) |
| Inventory | ✅ ledger imutável, posição, extrato, contagem física (BR-205) |
| Sales | ✅ clientes (PF/PJ, CPF/CNPJ validado, endereços, LGPD) |
| Marca | ✅ identidade visual da Dona Arteira (docs/06 §10.1) |

~309 testes Pest, PHPStan nível 6, CI verde. **Não é mais "existe apenas
documentação".** Ao continuar, consulte o git e `app/Modules/` como fonte
de verdade do que existe — não presuma pelo roadmap.

> 🔴 **Dívida de segurança aberta:** o commit `e13ae02` versionou um
> arquivo `.db` com segredos de produção em texto puro (removido do
> rastreamento em `4eb9076`, mas **ainda no histórico**). Rotação de
> credenciais em andamento pelo dono (2026-07-27). Segredo nunca no repo.

## Regras obrigatórias (valem para qualquer sessão)

1. **Documentação primeiro.** Antes de criar qualquer artefato de código, verifique se o documento do módulo em `docs/` cobre o que será feito; se não cobre, atualize o documento ANTES.
2. **Toda decisão arquitetural gera ADR** em `docs/27-ADR/` (use o template `ADR-0000-template.md`, status `Proposto` até aprovação do dono).
3. **Toda regra de negócio tem ID `BR-xxx`** em `docs/01-Regras-de-Negocio/01-registro-de-regras.md`. Código que implementa uma regra referencia o ID em comentário/teste.
4. **Nunca acessar banco de sistemas externos** (WordPress/WooCommerce). Integração só via API REST + webhooks, sempre pela camada `Integrations` com filas e idempotência.
5. **ERP é o Single Source of Truth** após a migração; conflito de dados resolve-se a favor do ERP, exceto pedidos originados no canal.
6. **Dinheiro nunca em float**: `DECIMAL(15,2)` no banco, `brick/money` no PHP. Quantidades: `DECIMAL(15,3)`.
7. **Estoque nunca é atualizado diretamente**: todo ajuste passa por movimento imutável em `inventory_movements` (ver `docs/09-Estoque/`).
8. **Sem código sem teste**: Pest para backend, Vitest para frontend; fluxos críticos exigem teste de feature.
9. O sistema desktop Python (`Dona_Arteira_Gestao_desktop/`) é **somente leitura** — referência de regras, jamais evoluir ou converter automaticamente.

## Onde encontrar o quê

- Mapa da documentação: `docs/README.md`
- Convenções de banco: `docs/04-Banco-de-Dados/02-convencoes-de-banco.md`
- Convenções de API: `docs/07-API/README.md`
- Padrões backend/frontend: `docs/05-Backend/` e `docs/06-Frontend/`
- Glossário do domínio: `docs/29-Glossario/README.md`
- Agentes e skills: `.claude/agents/` e `.claude/skills/`

## Convenções de escrita

- Documentos seguem `docs/_templates/TEMPLATE-DOCUMENTO.md` (objetivo, responsabilidades, fluxo, dependências, boas práticas, riscos, evoluções futuras).
- Datas em documentos: formato ISO (`2026-07-03`).
- Nomes de arquivos/pastas de docs: sem acentos, kebab-case.
