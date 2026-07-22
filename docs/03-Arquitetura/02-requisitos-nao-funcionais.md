# Requisitos Não Funcionais (NFRs)

> **Status:** Em revisão · **Última atualização:** 2026-07-03 · **Responsável:** chief-architect
> Metas dimensionadas para o porte real da operação; números marcados 📏 devem ser recalibrados com os dados do WooCommerce antes do Gate 01 (ver pasta 17, fase de inventário).

## 1. Dimensionamento assumido 📏

| Métrica | Estimativa inicial | Fonte a confirmar |
|---|---|---|
| SKUs ativos | 300–3.000 | contagem Woo + legado |
| Pedidos/mês (todos os canais) | 100–1.000 | histórico Woo |
| Usuários simultâneos | ≤ 10 | equipe atual |
| NF-e/mês | ≤ 500 | histórico + contador |
| Crescimento previsto | 2–3× em 3 anos | dono |

## 2. Metas

| Categoria | Requisito | Meta | Verificação |
|---|---|---|---|
| Desempenho | Latência API (leituras) | p95 < 300 ms | teste de carga leve + monitoramento |
| Desempenho | Latência API (escritas com regra) | p95 < 800 ms | idem |
| Desempenho | Dashboard principal | < 2 s carregado | Lighthouse/monitoramento |
| Disponibilidade | Uptime do ERP | ≥ 99,5%/mês (~3,6 h down) | UptimeRobot |
| Sincronização | Estoque ERP→Woo após venda | < 2 min | métrica de fila |
| Sincronização | Pedido Woo→ERP | < 2 min | métrica de webhook |
| Dados | RPO (perda máxima aceitável) | 24 h (backup diário) — **melhorar para ≤ 1 h se VPS** | teste de restore |
| Dados | RTO (tempo de recuperação) | ≤ 4 h úteis | simulação semestral |
| Fiscal | Emissão NF-e (sem contingência) | < 30 s do clique à autorização | métrica |
| Fiscal | Guarda de XML | ≥ 5 anos, backup redundante | auditoria anual |
| Segurança | Ver pasta 25 | OWASP ASVS nível 1 completo; nível 2 nos módulos fiscal/financeiro | checklist por gate |
| Manutenibilidade | Cobertura de testes no domínio | ≥ 80% linhas nos módulos core | CI |
| Manutenibilidade | Análise estática | PHPStan nível 8 + Pint sem erros | CI bloqueante |
| Usabilidade | Interface pt-BR, moeda/data brasileiras, responsiva (desktop-first, utilizável em tablet) | — | revisão de UI |

## 3. Restrições de plataforma

- PHP 8.4 / LiteSpeed ou Apache na Hostinger; sem root, sem daemons persistentes garantidos no plano Business (ADR-0016).
- MariaDB versão da hospedagem (conferir ≥ 10.11 LTS); sem privilégios de SUPER — migrations devem evitar operações que os exijam.
- Limites de PHP em shared hosting (memória, `max_execution_time`) condicionam jobs longos → todo job de sincronização/migração trabalha em **lotes pequenos e retomáveis**.

## 4. Como os NFRs são garantidos

Cada meta tem dono e mecanismo: CI (qualidade), monitoramento (pasta 24), runbooks (pastas 23/24), testes de carga leves antes do go-live de cada gate com volume (2 e 5).

## 5. Riscos

| Risco | Mitigação |
|---|---|
| Metas irreais por falta de dados de volume | Recalibrar 📏 na fase de inventário da migração |
| RPO de 24 h ser inaceitável para NF-e emitidas no dia | Backup incremental/binlog se VPS; export de XML pós-emissão para storage secundário |
