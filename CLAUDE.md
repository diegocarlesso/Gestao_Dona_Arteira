# Dona Arteira ERP — Regras do Projeto

ERP para fabricação artesanal e venda de peças decorativas em gesso. Idioma do projeto: **português (pt-BR)** — código em inglês, documentação/commits/UI em português.

## Estado atual

**Gate 00 (Fundação) concluído.** Existe apenas documentação. A implementação só começa quando o dono do produto autorizar o Gate 01 e as decisões pendentes do [ADR-0016 (hospedagem)](docs/27-ADR/ADR-0016-hospedagem.md) forem tomadas.

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
