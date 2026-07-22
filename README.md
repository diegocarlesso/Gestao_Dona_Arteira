# Dona Arteira ERP

Sistema de gestão integrada (ERP) da **Dona Arteira** — fabricação artesanal e comercialização de peças decorativas em gesso pintadas manualmente.

> **Status do projeto:** Gate 00 concluído — Fundação de Engenharia (somente documentação).
> Nenhuma linha de código de aplicação foi ou deve ser escrita antes da documentação correspondente.

## O que é este repositório

| Componente | Caminho | Descrição |
|---|---|---|
| Documentação | [`docs/`](docs/README.md) | Fundação completa: visão, domínio, arquitetura, módulos, ADRs, roadmap |
| Agentes | [`.claude/agents/`](.claude/agents/) | Agentes especializados para desenvolvimento assistido |
| Skills | [`.claude/skills/`](.claude/skills/) | Fluxos reutilizáveis padronizados (migrations, sync, integrações…) |
| Sistema legado | `Dona_Arteira_Gestao_desktop/` | Desktop Python — **somente referência de regras de negócio**, não evoluir |

## Princípios inegociáveis

1. **ERP = Single Source of Truth.** O WooCommerce continua sendo o e-commerce, mas passa a ser apenas um canal de vendas sincronizado via API.
2. **Nenhum sistema externo acessa o banco do ERP.** Toda comunicação é via API.
3. **Documentação primeiro.** Nenhum código sem documento correspondente; nenhuma decisão arquitetural sem ADR.
4. **Nenhuma regra de negócio vive apenas no código.** Toda regra tem ID no [registro de regras](docs/01-Regras-de-Negocio/01-registro-de-regras.md).
5. **Integrações desacopladas.** Cada sistema externo tem adapter próprio atrás de contrato interno.

## Stack decidida

- **Backend:** Laravel 12 · PHP 8.4 · MariaDB — monolito modular ([ADR-0001](docs/27-ADR/ADR-0001-monolito-modular.md))
- **Frontend:** React · Vite · TypeScript — SPA consumindo a API ([ADR-0004](docs/27-ADR/ADR-0004-spa-react-separada.md))
- **Hospedagem:** Hostinger — `gestao.donaarteira.com.br` (ver ressalvas em [ADR-0016](docs/27-ADR/ADR-0016-hospedagem.md))
- **Fiscal:** NF-e modelo 55 com certificado A1 ([ADR-0009](docs/27-ADR/ADR-0009-emissao-nfe.md))

## Por onde começar

1. [`docs/README.md`](docs/README.md) — mapa completo da documentação
2. [`docs/00-Visao-Geral/`](docs/00-Visao-Geral/README.md) — visão executiva e escopo
3. [`docs/28-Roadmap/`](docs/28-Roadmap/README.md) — fases e gates de implementação
4. [`docs/00-Visao-Geral/04-analise-critica-gate00.md`](docs/00-Visao-Geral/04-analise-critica-gate00.md) — riscos e decisões pendentes do dono do produto
