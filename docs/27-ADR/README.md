# 27 — ADRs (Architecture Decision Records)

> **Status:** Aprovado · **Última atualização:** 2026-07-03 · **Responsável:** chief-architect
> Template: [ADR-0000-template.md](ADR-0000-template.md) (cópia de `_templates/TEMPLATE-ADR.md`)

## Processo

1. Toda decisão com efeito duradouro (tecnologia, padrão, formato, estratégia) nasce como ADR `Proposto`.
2. Decisões com impacto de custo/risco de negócio são aprovadas pelo **dono**; as puramente técnicas, pelo chief-architect.
3. ADR aceito é **imutável** — mudou de ideia? Novo ADR que o substitui (`Substituído por ADR-YYYY`).
4. Todo ADR tem **gatilhos de revisão** objetivos (condições que reabrem a discussão).

## Índice

| ADR | Título | Status |
|---|---|---|
| [0001](ADR-0001-monolito-modular.md) | Monolito modular Laravel (não microserviços) | ✅ Aceito |
| [0002](ADR-0002-mariadb.md) | MariaDB como SGBD único + convenções de identidade | ✅ Aceito |
| [0003](ADR-0003-api-first-rest.md) | API First: REST versionada + OpenAPI como contrato | ✅ Aceito — **escopo reduzido pelo 0019** |
| [0004](ADR-0004-spa-react-separada.md) | ~~Frontend SPA React separada consumindo a API~~ | ❌ **Substituído pelo [0019](ADR-0019-inertia-substitui-spa.md)** |
| [0005](ADR-0005-autenticacao-sanctum.md) | Autenticação: Sanctum (tokens de integração) + sessão Laravel | ✅ Aceito — **parcialmente revisto pelo 0019** |
| [0006](ADR-0006-erp-ssot.md) | ERP como Single Source of Truth; Woo como canal | ✅ Aceito (princípio do projeto) |
| [0007](ADR-0007-sync-assincrona.md) | Sincronização assíncrona: filas + idempotência + mapeamento + reconciliação | ✅ Aceito |
| [0008](ADR-0008-ledger-estoque.md) | Estoque como ledger imutável + saldos materializados | ✅ Aceito |
| [0009](ADR-0009-emissao-nfe.md) | NF-e via sped-nfe com certificado A1 local | ✅ Aceito **com gatilhos** (alternativa gerenciada mapeada) |
| [0010](ADR-0010-migracao-etl.md) | Migração via staging + ETL idempotente (Woo API + legado) | ✅ Aceito |
| [0011](ADR-0011-rbac.md) | RBAC com spatie/laravel-permission, deny-by-default | ✅ Aceito |
| [0012](ADR-0012-auditoria.md) | Auditoria via laravel-auditing + fatos de domínio próprios | ✅ Aceito |
| [0013](ADR-0013-dinheiro-decimal.md) | Dinheiro: DECIMAL(15,2) + brick/money (nunca float) | ✅ Aceito |
| [0014](ADR-0014-fila-database.md) | Fila com driver database (sem Redis) | ✅ Aceito com gatilhos |
| [0015](ADR-0015-camadas-e-repositorios.md) | Camadas Controller→Service→Model e repositórios dosados | ✅ Aceito |
| [0016](ADR-0016-hospedagem.md) | Hospedagem: **plano Business** (Plano B; contraria a recomendação técnica) | ✅ Aceito 2026-07-22 **com gatilhos ativos** |
| [0017](ADR-0017-midia-canonica.md) | Mídia (imagens): fase 1 no Woo, fase 2 storage próprio | ⚠️ Proposto |
| [0018](ADR-0018-cobranca-boleto.md) | **Cobrança (boleto e PIX) via adapter, provedor plugável** | ⚠️ **Proposto — decisão do dono** |
| [0019](ADR-0019-inertia-substitui-spa.md) | **Laravel + Inertia + React** (substitui o 0004) | ✅ Aceito 2026-07-22 |
| [0020](ADR-0020-fronteiras-entre-modulos.md) | Fronteiras entre módulos (verificadas por `arch()`), migrations centralizadas, testes em `tests/` | ✅ Aceito 2026-07-22 |
| [0021](ADR-0021-2fa-totp.md) | **2FA TOTP (BR-804): `laravel/fortify` só pelas Actions, sem suas rotas/views** | ✅ Aceito 2026-07-24 |
| [0022](ADR-0022-modelo-de-produto-e-sku.md) | **Produto: variação é produto próprio; SKU sequencial neutro `DA-0001`** | ✅ Aceito 2026-07-25 |
| [0023](ADR-0023-producao-e-pintura-nao-fundicao.md) | **Produção é pintura, não fundição** (remove moldes; peça crua é `kind`; custeio ABC com mão de obra) | ⚠️ **Proposto — premissa do dono** |
| [0024](ADR-0024-quarentena-de-secagem.md) | **Quarentena de secagem no recebimento** (peça úmida entra mas não libera para pintar; via localização, ledger inalterado) | ⚠️ **Proposto — premissa do dono** |

## Backlog de ADRs futuros (escrever quando o tema chegar)

Gateway de pagamento completo — cartão/link de pagamento (fase 7, evolução do ADR-0018) · régua de cobrança/protesto/negativação (fase 7) · estratégia de marketplaces (fase 7) · PWA vs app nativo (fase 7) · **rastreio por lote (`batch`) ao longo da vida da peça** — extensão do ADR-0008, se o atacado/recall exigir (a taxa de quebra por lote de recebimento já sai do ADR-0024 sem isso) · **NFC-e** (se a resposta ao item F-06 da [pauta do contador](../13-Fiscal/01-pauta-validacao-contador.md) exigir) · saída do Simples Nacional (se ocorrer).
