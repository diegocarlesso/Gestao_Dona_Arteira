# ADR-0016: Hospedagem do ERP — plano Business (compartilhado) vs VPS

> **Status:** ✅ **Aceito — Plano B (permanecer no plano Business)** · **Data da proposta:** 2026-07-03 · **Data da decisão:** 2026-07-22 · **Decisores:** dono do produto
> **Módulos afetados:** 23, 24, 14, 15, 25
> ⚠️ **A decisão contraria a recomendação técnica.** As dívidas operacionais abaixo foram assumidas conscientemente; os gatilhos de reabertura estão ativos desde já. Ver [§ Decisão tomada](#decisão-tomada-2026-07-22).

## Contexto

O enunciado do projeto indica **Hostinger Business** (hospedagem compartilhada, Apache/LiteSpeed) para `gestao.donaarteira.com.br`. Um ERP com filas, sincronizações, emissão de NF-e e certificado digital tem requisitos que hospedagem compartilhada atende mal:

| Necessidade do ERP | Shared (Business) | VPS (ex.: Hostinger KVM 2) |
|---|---|---|
| Workers de fila persistentes (sync < 2 min, NF-e assíncrona) | ❌ apenas cron por minuto com `--stop-when-empty` (latência até ~60 s + jobs concorrendo com limites de processo) | ✅ supervisor/systemd, workers dedicados |
| Redis (fila/cache — ADR-0014) | ❌ | ✅ |
| Certificado A1 em área controlada | ⚠️ isolamento parcial (vizinhança compartilhada) | ✅ isolamento completo, permissões próprias |
| Extensões/limites PHP (soap, exec time p/ assinatura/transmissão) | ⚠️ fixados pelo plano, sem garantia | ✅ controle total |
| Backups próprios + binlog (RPO < 24 h) | ⚠️ dump via cron apenas | ✅ dump + binlog, RPO ~1 h |
| Deploy atômico por symlink | ⚠️ depende do plano | ✅ |
| Observabilidade (processos, disco, serviços) | ⚠️ painel limitado | ✅ |
| Custo mensal | já contratado (WordPress usa) | + ~R$ 30–80/mês |
| Administração do SO | zero (gerenciado) | **nossa responsabilidade** (updates, firewall, hardening) |

O WordPress/WooCommerce **permanece onde está** em qualquer cenário — esta decisão é apenas sobre onde roda o ERP.

## Decisão tomada (2026-07-22)

**O dono optou pelo Plano B: o ERP roda no plano Hostinger Business já contratado**, junto com o WordPress. Nenhum custo mensal adicional de infraestrutura.

Consequência direta e **não negociável** desta escolha, dado que o escopo contratado inclui o Gate 05 (NF-e): a validação de extensões e limites do ambiente, que o texto original agendava para "antes do Gate 05", **passa para a semana 1 do Gate 01** — ver [23-Deploy/01](../23-Deploy/01-validacao-ambiente-business.md).

Motivo: se o plano não oferecer `soap`, `openssl`, tempo de execução suficiente para assinar e transmitir, ou cron com granularidade útil, então **o Gate 05 é impossível neste host**. Descobrir isso com ~1.800 h investidas inviabilizaria a entrega contratada. A verificação custa uma hora e é feita antes de qualquer código.

Se a validação reprovar, este ADR é reaberto imediatamente (é o primeiro gatilho da lista abaixo, antecipado).

## Decisão original (recomendação técnica — não seguida)

**Recomendação técnica: VPS** (Hostinger KVM ou equivalente, Ubuntu LTS) dedicado ao ERP: Nginx + PHP-FPM 8.4 + MariaDB + Redis + supervisor, provisionado por script documentado (pasta 23), com hardening básico (pasta 25). O plano Business permanece para o site WordPress.

**Plano B (se o dono optar por permanecer no Business):** a arquitetura funciona com as adaptações já documentadas (fila via cron, sem Redis, RPO 24 h), aceitando formalmente: latência de sync de até ~2 min no melhor caso, emissão de NF-e sujeita aos limites de processo do plano, e validação obrigatória de extensões antes do Gate 05. Gates 02 e 05 têm risco aumentado registrado.

## Consequências

**Se VPS (recomendado):** NFRs de sync/RPO plenamente atingíveis; Redis desde o início; risco fiscal de ambiente eliminado. Custo mensal adicional + ~2 dias de setup + manutenção de SO como rotina mensal (automatizável).

**Se Business:** custo zero adicional e zero administração de SO; dívidas operacionais acima assumidas conscientemente; gatilho de migração forçada se qualquer limite se materializar (migrar depois custa mais que começar certo).

**Gatilhos (no plano B) que forçam reabrir:** fila > 5 min recorrente · falha de emissão de NF-e por limite de ambiente · impossibilidade de rodar backup externo confiável.
