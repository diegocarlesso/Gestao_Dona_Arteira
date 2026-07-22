---
name: criar-crud
description: Cria um CRUD completo de cadastro do ERP (migration→model→spec→endpoints→telas React→testes) encadeando as demais skills na ordem certa. Use para cadastros convencionais (categorias, embalagens, fornecedores, transportadoras…).
---

# Skill: Criar CRUD

## Objetivo
Entregar um cadastro completo e consistente com o resto do sistema, encadeando as skills atômicas — sem reinventar padrão a cada tela.

## Pré-requisitos
1. Entidade descrita no doc do módulo + modelo conceitual (04/01).
2. Confirmar que é cadastro de verdade (BR-008: inativa, não deleta, se tem movimento) e não um fato (fatos não têm CRUD — têm fluxo próprio).

## Entradas
Entidade, campos com tipos/validações, unicidades, papel que gerencia (permissões `x.view`/`x.manage`), regras de inativação.

## Fluxo (ordem obrigatória)
1. `criar-migration` — tabela conforme convenções (+ soft delete se BR-008).
2. `criar-model` — casts, invariantes, factory, auditoria.
3. `criar-api` — spec dos endpoints: `GET /` (paginado+filtros+busca), `GET /{id}`, `POST`, `PUT/PATCH`, `DELETE` (=inativar quando BR-008).
4. `criar-service` — apenas se houver regra além de persistir (senão Eloquent direto no controller action é aceitável para cadastro trivial — ADR-0015).
5. `criar-controller` — com testes feature completos (incl. 403 e unicidade 422).
6. Frontend (padrões docs/06): página de listagem (DataTable server-side, busca, filtros na URL, estado vazio com ação), formulário criar/editar (RHF+Zod espelhando validação, máscaras BR), inativação com confirmação de consequência.
7. Permissões na matriz (docs/19) + seeder; itens de menu com gating `can()`.

## Saídas
Cadastro fim-a-fim funcional com testes back+front e documentação em dia.

## Critérios mínimos
Comportamento idêntico aos CRUDs existentes (mesma UX de tabela/form/erros); auditoria registrando mutações; inativação em vez de exclusão onde a BR-008 manda.

## Checklist final
- [ ] Todas as skills da cadeia cumpriram seus próprios checklists?
- [ ] Unicidades com UNIQUE no banco + 422 amigável na API + mensagem no form?
- [ ] Listagem paginada server-side com filtros persistidos na URL?
- [ ] Inativado some dos selects de operação mas aparece no histórico?
- [ ] Permissões testadas (403) e menu escondido sem permissão?
