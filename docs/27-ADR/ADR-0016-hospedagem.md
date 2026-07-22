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

### Resultado da validação (2026-07-22) — ✅ ambiente aprovado

A validação foi executada no mesmo dia. **O plano Business suporta a emissão de NF-e**: `soap`, `openssl` e toda a cadeia de XML estão presentes, `max_execution_time` é de 360 s e `memory_limit` de 2 GB — folga confortável. As duas falhas reportadas pelo script eram artefatos (um hostname errado no próprio script e ausência de CA bundle, não bloqueio de rede) — análise em [23-Deploy/01 §7.1](../23-Deploy/01-validacao-ambiente-business.md#71-as-duas-falhas-não-eram-falhas).

A execução via **CLI** (17:30), que é a que realmente importa para fila e emissão, fechou com **0 falhas**: `max_execution_time` **ilimitado**, extensões idênticas às do web, MariaDB 11.8.8 com InnoDB e — o ponto decisivo — **os três endpoints da SEFAZ, incluindo o webservice da SVRS, confirmados como alcançáveis**. Não há bloqueio de saída.

**O risco de ambiente que motivava a recomendação de VPS caiu substancialmente. A decisão pelo plano Business está validada pelos fatos.**

Duas ressalvas que a validação revelou e que seguem ativas como gatilho:

1. **O ERP compartilha o plano com o WordPress em produção** (mesmo usuário `u917402451` do dump do site). Limites de processo, CPU e I/O são disputados entre os dois — um pico sazonal no site concorre com a fila do ERP. É a materialização mais provável do primeiro gatilho abaixo.
2. **`symlink` bloqueada**: não há deploy atômico por troca de symlink nem `storage:link`. A estratégia de release e o tratamento de uploads precisam ser redesenhados na pasta 23.

Permanecem em verificação, sem invalidar o veredito: granularidade do cron, document root apontável para `public/` e cota real de disco — [§7.3](../23-Deploy/01-validacao-ambiente-business.md#73-pendências-e-ressalvas).

## Decisão original (recomendação técnica — não seguida)

**Recomendação técnica: VPS** (Hostinger KVM ou equivalente, Ubuntu LTS) dedicado ao ERP: Nginx + PHP-FPM 8.4 + MariaDB + Redis + supervisor, provisionado por script documentado (pasta 23), com hardening básico (pasta 25). O plano Business permanece para o site WordPress.

**Plano B (se o dono optar por permanecer no Business):** a arquitetura funciona com as adaptações já documentadas (fila via cron, sem Redis, RPO 24 h), aceitando formalmente: latência de sync de até ~2 min no melhor caso, emissão de NF-e sujeita aos limites de processo do plano, e validação obrigatória de extensões antes do Gate 05. Gates 02 e 05 têm risco aumentado registrado.

## Consequências

**Se VPS (recomendado):** NFRs de sync/RPO plenamente atingíveis; Redis desde o início; risco fiscal de ambiente eliminado. Custo mensal adicional + ~2 dias de setup + manutenção de SO como rotina mensal (automatizável).

**Se Business:** custo zero adicional e zero administração de SO; dívidas operacionais acima assumidas conscientemente; gatilho de migração forçada se qualquer limite se materializar (migrar depois custa mais que começar certo).

**Gatilhos (no plano B) que forçam reabrir:** fila > 5 min recorrente · falha de emissão de NF-e por limite de ambiente · impossibilidade de rodar backup externo confiável.
