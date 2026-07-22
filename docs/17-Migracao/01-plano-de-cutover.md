# Plano de Cutover

> **Status:** Rascunho (datas definidas no Gate 01) · **Última atualização:** 2026-07-03 · **Responsável:** migration-specialist
> Momento em que o ERP assume como mestre e a sincronização com o Woo é ligada. Janela recomendada: domingo à noite / época de baixa venda — **nunca** véspera de pico (Natal/Dia das Mães).

## Pré-condições (gate de entrada — todas obrigatórias)

- [ ] Fases 1–5 da migração validadas (contagens e amostras assinadas pelo dono).
- [ ] Sincronização Woo testada de ponta a ponta no **staging** (pedido de teste → ERP → estoque → status de volta).
- [ ] Backup completo do WordPress (arquivos + banco) e do banco ERP tirados e testados.
- [ ] Equipe treinada nos fluxos de pedido/estoque do ERP.
- [ ] Runbook de rollback lido por quem executa.
- [ ] Plano de inventário físico com responsáveis por área/categoria.

## Sequência

| # | Ação | Responsável | Duração alvo |
|---|---|---|---|
| 1 | Congelar mudanças de catálogo no wp-admin (aviso à equipe — BR-702 passa a valer) | dono | — |
| 2 | Colocar loja em modo "pausa curta" ou janela de madrugada (checkout segue aberto se inventário for por categoria em ondas) | dono | — |
| 3 | Rodar extração/carga **delta** (`modified_after` do último lote) | migration-specialist | < 1 h |
| 4 | **Inventário físico** → contagem no ERP → aprovação → estoque inicial oficial | operação | 4–8 h (por ondas) |
| 5 | Publicar estoque ERP→Woo (primeira sync completa) e conferir amostra de 20 produtos no site | integração | < 1 h |
| 6 | Ativar webhooks Woo→ERP + jobs de push + reconciliação agendada | integração | < 30 min |
| 7 | Pedido de teste real de ponta a ponta (comprar item barato no site → separar → expedir fake em homolog) | dono | 30 min |
| 8 | Go/No-Go formal → comunicar equipe: **ERP é o mestre a partir de agora** | dono | — |

## Rollback (se falhar antes do passo 8)

1. Desativar webhooks e jobs de push (feature flag off).
2. Woo volta a operar sozinho como antes (nenhum dado do Woo foi alterado até o passo 5; o passo 5 altera apenas `stock_quantity` — restaurável pelo backup ou re-push do snapshot pré-cutover, guardado pelo passo 3).
3. Registrar causa, corrigir, reagendar. Migração é re-executável (BR-706) — rollback é barato **por design**.

## Operação assistida (2 semanas pós-cutover)

- Reconciliação Woo×ERP **2×/dia** (em vez de diária) com revisão manual do relatório.
- Canal direto da equipe para reportar estranhezas; toda divergência investigada até causa raiz.
- Critério de saída: 14 dias com divergência zero não explicada → cadência normal.

## Riscos específicos

| Risco | Mitigação |
|---|---|
| Venda no site durante a janela de inventário | Inventário por ondas de categoria com buffer temporário aumentado; ou pausa curta de checkout na madrugada |
| Equipe esquecer e editar no wp-admin | Aviso + banner no wp-admin (mini-plugin de aviso) + reconciliação alerta nominalmente |
| Cutover às vésperas de pico por pressão de prazo | Regra fixa: janela proibida em nov/dez — registrada aqui para resistir à pressão |
