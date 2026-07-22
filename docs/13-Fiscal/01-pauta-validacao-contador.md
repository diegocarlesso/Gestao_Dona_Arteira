# 01 — Pauta de Validação com o Contador

> **Status:** Em revisão · **Última atualização:** 2026-07-22 · **Responsável:** fiscal-specialist
> **ADRs relacionados:** [ADR-0009](../27-ADR/ADR-0009-emissao-nfe.md) · **Regras relacionadas:** BR-601…BR-606, BR-001, BR-309
> **Bloqueia:** Gate 05 (Fiscal/NF-e) · **Influencia desde já:** modelo de dados do Gate 01 (§10)

## 1. Objetivo

Reunir, em **uma única rodada**, todas as definições tributárias que o ERP precisa para emitir NF-e corretamente. Cada ida-e-volta com o contador custa semanas; esta pauta existe para que não haja uma segunda.

O documento é **para enviar ao contador**. Ele pode responder diretamente na coluna "Resposta" de cada tabela.

## 2. Como usar

1. Enviar este arquivo (ou uma cópia em PDF/planilha) ao contador.
2. Priorizar os itens marcados 🔴 — sem eles a primeira emissão **não funciona**, independentemente de o imposto estar certo.
3. Cada resposta recebida vira: parametrização em `tax_profiles`/cadastro, ou uma BR-6xx promovida de 💡 para ✅ (com nome de quem validou e data), conforme a pasta [01-Regras-de-Negocio](../01-Regras-de-Negocio/01-registro-de-regras.md).
4. Registrar o retorno na §11 (tracker) e atualizar a tabela de hipóteses do [README da pasta 13](README.md#3-hipóteses-de-trabalho--todas-a-validar-com-o-contador).

> ⚠️ Enquanto não houver resposta, **todas** as hipóteses da pasta 13 permanecem 💡. O Gate 05 não inicia com hipótese.

---

## 3. Bloco A — Identificação e cadastro 🔴

Sem estes dados exatos (como constam no cadastro da SEFAZ), a nota é rejeitada antes de qualquer cálculo.

| # | Pergunta | Hipótese/estado atual | Resposta |
|---|---|---|---|
| A-01 🔴 | CNPJ, razão social e nome fantasia exatos | — | |
| A-02 🔴 | Endereço fiscal completo + **código IBGE do município** e UF | — | |
| A-03 🔴 | **Inscrição Estadual** e situação (contribuinte / isento / não contribuinte) | não documentada | |
| A-04 | Há IE substituta em outra UF (ICMS-ST como substituto)? | presumimos que não | |
| A-05 | Inscrição Municipal (se houver serviço/ISS) | fora de escopo hoje | |
| A-06 🔴 | CNAE principal e secundários | **pendente** | |
| A-07 | O CNAE principal é de **indústria** ou de comércio? | fabricação de gesso sugere indústria | |

## 4. Bloco B — Regime tributário 🔴

| # | Pergunta | Hipótese | Resposta |
|---|---|---|---|
| B-01 🔴 | Regime tributário atual | Simples Nacional | |
| B-02 🔴 | **CRT** a informar na NF-e (1 Simples · 2 Simples c/ excesso de sublimite · 3 Regime Normal · 4 MEI) | 1 | |
| B-03 | **Anexo e faixa** do Simples (fabricação → Anexo II? revenda → Anexo I?) | Anexo II para fabricação | |
| B-04 | A empresa tem **dois anexos** convivendo (fabrica gesso + revende incenso/MDF)? Como segregar a receita? | provável | |
| B-05 | Regime de **caixa ou competência** no PGDAS-D | — | |
| B-06 | Há previsão de estourar sublimite/limite do Simples nos próximos 12 meses? | não | |
| B-07 | A empresa é **contribuinte de IPI** (estabelecimento industrial)? | provável, mas incluso no DAS | |

## 5. Bloco C — Produtos (dados fiscais do cadastro) 🔴

Estes campos são obrigatórios no cadastro de produto do ERP e bloqueiam a emissão se incompletos ([BR-606](../01-Regras-de-Negocio/01-registro-de-regras.md)). O catálogo tem **quatro naturezas distintas** — cada uma pode ter tratamento próprio.

| # | Linha de produto | Hipótese de NCM | NCM confirmado | CEST | Origem (0–8) |
|---|---|---|---|---|---|
| C-01 🔴 | Peças decorativas de **gesso** (fabricação própria) | 6809.90.00 | | | 0 (nacional)? |
| C-02 🔴 | Itens de **MDF** | ? | | | |
| C-03 🔴 | **Incenso** (vareta/cone) — revenda | ? | | | pode ser importado |
| C-04 | **Kits/trios** (composição de peças) — NCM do conjunto ou por item? | por item | | | |
| C-05 | **Embalagens** vendidas separadamente (se houver) | — | | | |
| C-06 🔴 | Algum NCM da lista está sujeito a **ICMS-ST**? Se sim, quais e com qual CEST? | presumimos que não | | | |
| C-07 | Unidade tributável a usar (UN, PC, KG…) | UN | | | |
| C-08 | GTIN: usar "SEM GTIN" quando não houver? | sim | | | |

## 6. Bloco D — Operações e CFOP

O ERP resolve o CFOP automaticamente por **perfil de operação** — o operador nunca escolhe. A matriz é: *tipo de operação × destino (dentro/fora da UF) × tipo de cliente (PF / PJ contribuinte / PJ não contribuinte)*. Precisamos do CFOP de cada célula.

### D.1 — Operações já previstas

| # | Operação | Dentro da UF | Fora da UF | Hipótese |
|---|---|---|---|---|
| D-01 🔴 | Venda de **produção própria** | | | 5101 / 6101 |
| D-02 🔴 | Venda de **mercadoria adquirida para revenda** (incenso, MDF) | | | 5102 / 6102 |
| D-03 | **Devolução de venda** (entrada) | | | 1202 / 2202 |
| D-04 | **Remessa em bonificação / brinde / amostra grátis** | | | 5910 / 5911 |

### D.2 — Operações que a Dona Arteira usa e não estavam mapeadas ⚠️

O negócio vende em **feiras e eventos** e pode terceirizar etapas — cenários que exigem CFOP próprio e que a documentação ainda não cobria.

| # | Operação | Ocorre? | CFOP saída | CFOP retorno |
|---|---|---|---|---|
| D-05 ⚠️ | **Venda fora do estabelecimento** (mercadoria sai para vender em feira) | | | |
| D-06 ⚠️ | **Remessa para exposição/feira** e respectivo retorno | | | |
| D-07 | **Remessa para industrialização por terceiro** (ex.: pintura terceirizada) e retorno | | | |
| D-08 | **Consignação** (peças deixadas em lojas parceiras) | | | |
| D-09 | **Transferência entre locais próprios** (ateliê ↔ loja ↔ depósito) | | | |
| D-10 | **Remessa para conserto/retrabalho** | | | |

### D.3 — Naturezas de operação (texto que vai na nota)

| # | Pergunta | Resposta |
|---|---|---|
| D-11 🔴 | Descrição da natureza de operação para cada CFOP acima (ex.: "Venda de produção do estabelecimento") | |

## 7. Bloco E — Tributos por operação 🔴

| # | Tributo | Pergunta | Hipótese | Resposta |
|---|---|---|---|---|
| E-01 🔴 | ICMS | **CSOSN** padrão nas vendas | 102 (sem permissão de crédito) | |
| E-02 🔴 | ICMS | Vendemos para **lojistas revendedores** (atacado). Eles pedirão crédito de ICMS. Devemos usar **CSOSN 101** com percentual de crédito? Qual percentual e como ele é apurado a cada mês? | a definir | |
| E-03 | ICMS | Há CSOSN diferente para revenda (incenso/MDF)? | | |
| E-04 | ICMS | Alguma **isenção/benefício estadual para artesanato** aplicável? | não sabemos | |
| E-05 🔴 | PIS | CST e alíquota a informar na NF-e | CST de "outras operações", alíquota zero | |
| E-06 🔴 | COFINS | CST e alíquota a informar na NF-e | idem | |
| E-07 🔴 | IPI | CST e alíquota; a empresa destaca IPI ou informa como "outras saídas"? | não destaca (incluso no DAS) | |
| E-08 🔴 | DIFAL | Venda interestadual a **consumidor final não contribuinte**: o Simples recolhe DIFAL? | dispensado — confirmar situação atual | |
| E-09 | FCP | Aplicável? Em que casos e qual percentual por UF de destino? | acompanha o DIFAL | |
| E-10 | ICMS-ST | Alguma operação com ST? Como calcular (MVA)? | não | |
| E-11 🔴 | — | **Texto obrigatório** de informações complementares para optante do Simples (art. 23 da LC 123) — redação exata a usar | | |
| E-12 | — | Outros textos padrão que devem constar na nota (avisos, prazos, condições) | | |

## 8. Bloco F — Emissão: operacional 🔴

Estes itens não são tributários, mas **impedem a emissão** e costumam ser esquecidos.

| # | Pergunta | Por que importa | Resposta |
|---|---|---|---|
| F-01 🔴 | A empresa **já emite NF-e hoje**? Por qual sistema? | define o ponto de partida | |
| F-02 🔴 | Se sim: qual **série** e qual o **último número emitido**? | numerar em duplicidade = rejeição garantida ([BR-602](../01-Regras-de-Negocio/01-registro-de-regras.md)) | |
| F-03 🔴 | Qual **série** o ERP deve usar? (sugestão: série nova e dedicada, para não colidir com o emissor atual) | | |
| F-04 🔴 | **Certificado A1**: existe? Está no CNPJ? Validade? Quem tem a posse do arquivo e da senha? | assinatura da nota; o mesmo certificado pode servir à API bancária (ver [ADR-0018](../27-ADR/ADR-0018-cobranca-boleto.md)) | |
| F-05 | **Quando a venda exige nota fiscal?** Toda venda? Só envio, só PJ, acima de certo valor? | parametriza [BR-601](../01-Regras-de-Negocio/01-registro-de-regras.md) e a trava de expedição [BR-309](../01-Regras-de-Negocio/01-registro-de-regras.md) | |
| F-06 ⚠️ | A empresa vende no **balcão e em feiras**. A decisão de **não usar NFC-e** (emitir NF-e mod. 55 quando exigido) se sustenta? | reverter isso depois do Gate 05 é caro — [00/01](../00-Visao-Geral/01-escopo-e-nao-escopo.md) | |
| F-07 | **Modalidade do frete** padrão (CIF / FOB / sem frete) e dados da transportadora quando houver | grupo de transporte da NF-e | |
| F-08 | Prazo e política de **cancelamento** e de **carta de correção** que o contador recomenda | [BR-604](../01-Regras-de-Negocio/01-registro-de-regras.md) | |
| F-09 🔴 | **E-mail do contador** para envio automático dos XMLs e periodicidade desejada (por nota / mensal) | [BR-603](../01-Regras-de-Negocio/01-registro-de-regras.md) e perfil de acesso do contador | |
| F-10 | O contador prefere receber XML, planilha de vendas, ou ambos? Em que formato? | exportações da pasta [20](../20-Relatorios/README.md) | |

## 9. Bloco G — Financeiro e cobrança

Perguntas que nascem do módulo Financeiro e da [cobrança por boleto](../12-Financeiro/01-cobranca-e-boletos.md).

| # | Pergunta | Por que importa | Resposta |
|---|---|---|---|
| G-01 | Como contabilizar as **taxas de gateway** (Mercado Pago, Pagaleve) e os **juros de parcelamento**? | plano de contas ([BR-503](../01-Regras-de-Negocio/01-registro-de-regras.md)); hoje invisíveis, distorcem margem | |
| G-02 | Venda a prazo com boleto exige o grupo de **duplicatas/cobrança** na NF-e? Preencher sempre que houver parcela? | liga NF-e ↔ título a receber | |
| G-03 | **Multa, juros e desconto** padrão para boletos (percentuais e prazos) | parametriza o perfil de cobrança ([BR-508](../01-Regras-de-Negocio/01-registro-de-regras.md)) | |
| G-04 | Condições de pagamento praticadas no **atacado** (30/60 dias?) | geração de parcelas ([BR-501](../01-Regras-de-Negocio/01-registro-de-regras.md)) | |
| G-05 | A emissão da NF-e deve ocorrer **antes** do pagamento do boleto (venda a prazo) ou só após a compensação? | ordem do fluxo Vendas → Fiscal → Financeiro | |

## 10. Bloco H — Reforma tributária (IBS/CBS)

2026 é ano de transição. Isto **não bloqueia** o Gate 01, mas define o desenho de `tax_profiles`.

| # | Pergunta | Resposta |
|---|---|---|
| H-01 | Como a empresa está tratando o ano de transição (alíquotas-teste de CBS/IBS)? Há obrigação acessória a cumprir? | |
| H-02 | O contador acompanha as **Notas Técnicas** da NF-e e nos avisará das mudanças de layout? Com que antecedência? | |
| H-03 | Há expectativa de mudança de anexo/enquadramento durante a transição? | |
| H-04 | Periodicidade sugerida para revisarmos os perfis fiscais juntos (semestral?) | |

## 11. O que fazemos com cada resposta

| Bloco | Destino no sistema |
|---|---|
| A, B | Cadastro da empresa (emitente) + CRT no XML; `tax_profiles` nasce com `valid_from` |
| C | Campos `ncm`, `cest`, `origin`, `gtin` da tabela `products` ([04/01](../04-Banco-de-Dados/01-modelo-conceitual.md)) — **influencia o Gate 01** |
| D | Registros de `tax_profiles`: uma linha por célula da matriz operação × destino × cliente |
| E | Colunas de tributo de `tax_profiles` + `fiscal_document_items` |
| F | `fiscal_series`, cofre do certificado A1, [BR-601](../01-Regras-de-Negocio/01-registro-de-regras.md)/[BR-309](../01-Regras-de-Negocio/01-registro-de-regras.md), perfil de acesso do contador |
| G | Perfil de cobrança do Financeiro + grupo `cobr`/`dup` da NF-e |
| H | Versionamento por vigência de `tax_profiles`; calendário de revisão |

> **Atenção ao Gate 01:** apenas o **Bloco C** (e o CRT do Bloco B) precisam existir antes da implementação do núcleo — são três colunas no cadastro de produto. Todo o resto pode chegar até o Gate 05 sem atrasar nada. **A espera pelo contador não justifica adiar o Gate 01.**

## 12. Tracker de respostas

| Bloco | Enviado em | Respondido em | Quem validou | Status |
|---|---|---|---|---|
| A — Identificação | | | | ⏳ aguardando |
| B — Regime | | | | ⏳ aguardando |
| C — Produtos | | | | ⏳ aguardando |
| D — CFOP | | | | ⏳ aguardando |
| E — Tributos | | | | ⏳ aguardando |
| F — Operacional | | | | ⏳ aguardando |
| G — Financeiro | | | | ⏳ aguardando |
| H — Reforma | | | | ⏳ aguardando |

## 13. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Contador responder só parte da pauta | Alta | Médio | Blocos independentes + tracker por bloco; cobrar os 🔴 primeiro |
| Resposta genérica ("usa 5102 mesmo") sem cobrir a matriz completa | Alta | Alto | Tabelas com célula por cenário forçam resposta específica |
| Operações de feira/consignação (D.2) revelarem processo fiscal inexistente hoje | Média | Alto | Descobrir agora é o objetivo; pode virar BR nova e ajuste de processo do negócio |
| CSOSN 101 (E-02) exigir apuração mensal de percentual de crédito | Média | Médio | Se confirmado, `tax_profiles` precisa de campo de percentual com vigência mensal |
| Numeração colidir com emissor atual (F-02) | Média | Alto | Série dedicada ao ERP |

## 14. Perguntas em aberto (nossas, não do contador)

- Se o contador não souber responder sobre feiras/consignação (D.2), quem responde? O dono do negócio precisa descrever o processo real primeiro.
- Se a resposta a F-06 for "precisa de NFC-e", isso é um **novo ADR** e altera o escopo do Gate 05 — com impacto de prazo e custo.
