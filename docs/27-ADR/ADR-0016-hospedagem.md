# ADR-0016: Hospedagem do ERP — plano Business (compartilhado) vs VPS

> **Status:** ⚠️ **Proposto — decisão do dono (impacto de custo)** · **Data:** 2026-07-03 · **Decisores:** dono do produto, com recomendação técnica
> **Módulos afetados:** 23, 24, 14, 15, 25 · **Prazo da decisão:** antes do fim do Gate 01

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

## Decisão (recomendada — aguardando aprovação)

**Recomendação técnica: VPS** (Hostinger KVM ou equivalente, Ubuntu LTS) dedicado ao ERP: Nginx + PHP-FPM 8.4 + MariaDB + Redis + supervisor, provisionado por script documentado (pasta 23), com hardening básico (pasta 25). O plano Business permanece para o site WordPress.

**Plano B (se o dono optar por permanecer no Business):** a arquitetura funciona com as adaptações já documentadas (fila via cron, sem Redis, RPO 24 h), aceitando formalmente: latência de sync de até ~2 min no melhor caso, emissão de NF-e sujeita aos limites de processo do plano, e validação obrigatória de extensões antes do Gate 05. Gates 02 e 05 têm risco aumentado registrado.

## Consequências

**Se VPS (recomendado):** NFRs de sync/RPO plenamente atingíveis; Redis desde o início; risco fiscal de ambiente eliminado. Custo mensal adicional + ~2 dias de setup + manutenção de SO como rotina mensal (automatizável).

**Se Business:** custo zero adicional e zero administração de SO; dívidas operacionais acima assumidas conscientemente; gatilho de migração forçada se qualquer limite se materializar (migrar depois custa mais que começar certo).

**Gatilhos (no plano B) que forçam reabrir:** fila > 5 min recorrente · falha de emissão de NF-e por limite de ambiente · impossibilidade de rodar backup externo confiável.
