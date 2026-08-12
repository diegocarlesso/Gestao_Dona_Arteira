# 01 - Pauta de Validação com o Contador

> **Status:** Em revisão · **Última atualização:** 2026-08-12 · **Responsável:** fiscal-specialist
> **ADRs relacionados:** [ADR-0009](../27-ADR/ADR-0009-emissao-nfe.md) · **Regras relacionadas:** BR-001, BR-309, BR-508, BR-512, BR-601…BR-608
> **Bloqueia:** Gate 05 (Fiscal/NF-e) · **Influencia desde já:** modelo de dados do Gate 01 (ver §11)

## 1. Objetivo

Reunir, em **uma única rodada**, todas as definições tributárias que o ERP precisa para emitir NF-e corretamente. Cada ida-e-volta com o contador custa semanas. Esta pauta existe para que não haja uma segunda.

O documento é **para enviar ao contador**. Ele pode responder diretamente na coluna "Resposta" de cada tabela.

## 2. Como usar

1. Enviar este arquivo (ou uma cópia em PDF/planilha) ao contador.
2. Priorizar os itens marcados 🔴. Sem eles a primeira emissão **não funciona**, independentemente de o imposto estar certo.
3. Cada resposta recebida vira parametrização em `tax_profiles`/cadastro, ou uma BR-6xx promovida de 💡 para ✅ (com nome de quem validou e data), conforme a pasta [01-Regras-de-Negocio](../01-Regras-de-Negocio/01-registro-de-regras.md).
4. Registrar o retorno no §12 (tracker) e atualizar a tabela de hipóteses do [README da pasta 13](README.md#3-hipóteses-de-trabalho--todas-a-validar-com-o-contador).

> ⚠️ Enquanto não houver resposta, **todas** as hipóteses da pasta 13 permanecem 💡. O Gate 05 não inicia com hipótese.

---

## 3. Bloco A - Identificação e cadastro 🔴

Sem estes dados exatos (como constam no cadastro da SEFAZ), a nota é rejeitada antes de qualquer cálculo.

| # | Pergunta | Resposta |
|---|---|---|
| A-01 🔴 | CNPJ, razão social e nome fantasia exatos | |
| A-02 🔴 | Endereço fiscal completo, incluindo o **código IBGE do município** e a UF | |
| A-03 🔴 | **Inscrição Estadual** e situação: contribuinte, isento ou não contribuinte | |
| A-04 | A empresa tem Inscrição Estadual de substituto tributário (ICMS-ST) em alguma outra UF? | |
| A-05 🔴 | CNAE principal e secundários | |

## 4. Bloco B - Regime tributário 🔴

| # | Pergunta | Resposta |
|---|---|---|
| B-01 🔴 | Qual o regime tributário atual da empresa? | |
| B-02 🔴 | Qual o **CRT** a informar na NF-e? (1 = Simples Nacional, 2 = Simples Nacional com excesso de sublimite, 3 = Regime Normal, 4 = MEI) | |
| B-03 | Qual o **Anexo** e a **faixa** (alíquota efetiva) do Simples Nacional aplicável hoje? | |
| B-04 | A pintura e o acabamento de peças de gesso compradas cruas contam como industrialização para fins de enquadramento no Simples Nacional? A empresa também revende itens comprados prontos de terceiros (incenso, MDF). Isso significa que ela tem dois Anexos convivendo? Se sim, como a receita deve ser segregada entre eles? | |
| B-05 | Há previsão de a empresa ultrapassar o sublimite ou o limite do Simples Nacional nos próximos 12 meses? | |

## 5. Bloco C - Produtos (dados fiscais do cadastro) 🔴

Estes campos são obrigatórios no cadastro de produto do ERP e bloqueiam a emissão se incompletos ([BR-606](../01-Regras-de-Negocio/01-registro-de-regras.md)). O catálogo tem **quatro naturezas distintas** de produto, e cada uma pode ter tratamento fiscal próprio.

| # | Linha de produto | NCM | CEST | Origem (0 a 8) |
|---|---|---|---|---|
| C-01 🔴 | Peças decorativas de **gesso** vendidas pela empresa (a peça crua é comprada de fornecedor, e a Dona Arteira faz a pintura e o acabamento) | | | |
| C-02 🔴 | Itens de **MDF** | | | |
| C-03 🔴 | **Incenso** (vareta ou cone), comprado para revenda | | | |
| C-04 | **Kits ou trios** (conjuntos com mais de uma peça): o NCM deve ser do conjunto todo ou de cada peça individualmente? | | | |

| # | Pergunta | Resposta |
|---|---|---|
| C-05 🔴 | Algum dos NCMs acima está sujeito a **ICMS-ST**? Se sim, para cada um: qual o CEST e como calcular o imposto (MVA, alíquota e base de cálculo)? | |

## 6. Bloco D - Operações e CFOP

O ERP resolve o CFOP automaticamente por **perfil de operação**. O operador nunca escolhe o CFOP manualmente. A matriz é *tipo de operação × destino (dentro ou fora da UF) × tipo de cliente (PF, PJ contribuinte ou PJ não contribuinte)*. Precisamos do CFOP de cada célula.

### D.1 - Operações já previstas

| # | Operação | Dentro da UF | Fora da UF |
|---|---|---|---|
| D-01 🔴 | Venda de **produção própria** (peças pintadas e acabadas pela Dona Arteira) | | |
| D-02 🔴 | Venda de **mercadoria adquirida para revenda** (incenso, MDF) | | |
| D-03 | **Devolução de venda** (entrada) | | |
| D-04 | **Remessa em bonificação, brinde ou amostra grátis** | | |

### D.2 - Operações que podem não estar mapeadas ⚠️

A empresa pode vender em feiras e eventos, e pode deixar peças em consignação com lojas parceiras. Ainda não confirmamos se isso realmente acontece hoje. Se acontecer, exige CFOP próprio que a documentação ainda não cobre.

| # | Operação | Ocorre? | CFOP de saída | CFOP de retorno |
|---|---|---|---|---|
| D-05 ⚠️ | **Venda fora do estabelecimento**: mercadoria sai já com preço definido para vender em feira | | | |
| D-06 ⚠️ | **Remessa para exposição ou mostruário** em feira, com retorno do que não for vendido | | | |
| D-07 ⚠️ | **Consignação**: peças deixadas em lojas parceiras, faturadas só quando a loja parceira as revende | | | |

### D.3 - Naturezas de operação (texto que vai na nota)

| # | Pergunta | Resposta |
|---|---|---|
| D-08 🔴 | Qual a descrição da natureza de operação para cada CFOP acima? Por exemplo, "Venda de produção do estabelecimento". | |

## 7. Bloco E - Tributos por operação 🔴

| # | Tributo | Pergunta | Resposta |
|---|---|---|---|
| E-01 🔴 | ICMS | Qual o **CSOSN** padrão a usar nas vendas? | |
| E-02 🔴 | ICMS | Vendemos para **lojistas revendedores** (atacado), que vão pedir crédito de ICMS. Devemos usar o **CSOSN 101** com percentual de crédito nessas vendas? Se sim, qual o percentual e como ele é apurado a cada mês? | |
| E-03 | ICMS | Há um CSOSN diferente para a revenda de itens de terceiros (incenso, MDF)? | |
| E-04 | ICMS | Existe alguma **isenção ou benefício estadual para artesanato** que se aplique à empresa? | |
| E-05 🔴 | PIS/COFINS | Qual o CST e a alíquota de cada um a informar na NF-e? | |
| E-06 🔴 | IPI | A empresa é **contribuinte de IPI** (estabelecimento industrial)? Se sim, qual o CST e a alíquota a informar, e a empresa destaca o IPI na nota ou informa como "outras saídas"? | |
| E-07 🔴 | DIFAL | Em venda interestadual a **consumidor final não contribuinte**, o Simples Nacional recolhe o DIFAL? | |
| E-08 | FCP | O FCP é aplicável às nossas vendas? Em que casos, e qual o percentual por UF de destino? | |
| E-09 🔴 | Textos da nota | Qual a redação exata do **texto obrigatório** de informações complementares para optante do Simples Nacional (art. 23 da LC 123)? Há outros textos padrão (avisos, prazos, condições) que devem constar na nota? | |

## 8. Bloco F - Emissão: operacional 🔴

Estes itens não são tributários, mas **impedem a emissão** e costumam ser esquecidos.

| # | Pergunta | Resposta |
|---|---|---|
| F-01 🔴 | A empresa **já emite NF-e hoje**? Por qual sistema? | |
| F-02 🔴 | Se sim, qual **série** está em uso e qual foi o **último número emitido**? Precisamos saber para não numerar em duplicidade quando o ERP começar a emitir. | |
| F-03 🔴 | Qual **série** o ERP deve usar para emissão? | |
| F-04 🔴 | Existe **certificado A1** no CNPJ da empresa? Qual a validade, e quem tem a posse do arquivo e da senha? | |
| F-05 | Toda venda exige nota fiscal, ou só em situações específicas (por exemplo, só envio, só para PJ, ou só acima de certo valor)? | |
| F-06 ⚠️ | A empresa vende no balcão e pode vender em feiras. Hoje o plano é emitir sempre **NF-e modelo 55** nessas vendas, sem usar NFC-e ou cupom fiscal. Essa decisão é cara de reverter depois de a emissão entrar em produção, por isso queremos confirmar agora: ela se sustenta para o nosso caso, ou a legislação exige NFC-e para venda presencial ao consumidor final? | |
| F-07 | Qual a **modalidade de frete** padrão (CIF, FOB ou sem frete) a informar na nota, e quais os dados da transportadora quando houver? | |
| F-08 🔴 | Qual o **e-mail do contador** para o envio automático dos XMLs? Ele prefere receber XML, planilha de vendas, ou ambos, e com qual periodicidade (por nota ou mensal)? | |

## 9. Bloco G - Cobrança na nota fiscal

Perguntas que nascem do cruzamento entre a [cobrança por boleto](../12-Financeiro/01-cobranca-e-boletos.md) e a própria NF-e. As demais perguntas financeiras (plano de contas, condições de pagamento no atacado, contabilização de taxas de gateway) não exigem NF-e para funcionar e ficam de fora desta pauta.

| # | Pergunta | Resposta |
|---|---|---|
| G-02 | Toda venda a prazo com boleto deve trazer o grupo de **duplicatas e cobrança** na NF-e, ou isso é opcional? Quando esse grupo deve ser preenchido? | |
| G-03 | Quais são os percentuais e prazos padrão de **multa, juros e desconto** a aplicar nos boletos? | |

## 10. Bloco H - Reforma tributária (IBS/CBS)

2026 é ano de transição da reforma tributária. Isto **não bloqueia** o Gate 01, mas define parte do desenho de `tax_profiles`.

| # | Pergunta | Resposta |
|---|---|---|
| H-01 | O contador pode nos avisar quando surgirem **Notas Técnicas** relevantes da reforma tributária (IBS/CBS) para a NF-e? Podemos combinar também uma revisão conjunta dos perfis fiscais a cada seis meses? | |
| H-05 | A partir de **03/08/2026** a SEFAZ passou a rejeitar automaticamente NF-e de contribuintes do regime regular (Lucro Real ou Presumido) sem os campos de IBS/CBS preenchidos. Empresas do **Simples Nacional e MEI** têm prazo até **04/01/2027**. Para o nosso caso, no Simples Nacional, o contador confirma que o prazo aplicável é o de 2027, e não o de agosto de 2026? | |
| H-06 | Quando o preenchimento do grupo IBS/CBS entrar em vigor para nós, qual `cClassTrib` (classificação tributária) e quais alíquotas devemos usar para os nossos produtos (peças decorativas em gesso, CFOP de venda ao consumidor final)? | |

## 11. O que fazemos com cada resposta

| Bloco | Destino no sistema |
|---|---|
| A, B | Cadastro da empresa (emitente) e CRT no XML. `tax_profiles` nasce com `valid_from` |
| C | Campos `ncm`, `cest`, `origin`, `gtin` da tabela `products` ([04/01](../04-Banco-de-Dados/01-modelo-conceitual.md)). **Influencia o Gate 01** |
| D | Registros de `tax_profiles`: uma linha por célula da matriz operação × destino × cliente |
| E | Colunas de tributo de `tax_profiles` e `fiscal_document_items` |
| F | `fiscal_series`, cofre do certificado A1, [BR-601](../01-Regras-de-Negocio/01-registro-de-regras.md)/[BR-309](../01-Regras-de-Negocio/01-registro-de-regras.md), perfil de acesso do contador |
| G | Percentuais de `billing_profiles` ([BR-508](../01-Regras-de-Negocio/01-registro-de-regras.md)) e grupo `cobr`/`dup` da NF-e ([BR-512](../01-Regras-de-Negocio/01-registro-de-regras.md)) |
| H | Calendário de revisão semestral e os 5 campos de IBS/CBS de `tax_profiles` ([BR-608](../01-Regras-de-Negocio/01-registro-de-regras.md)) |

> **Atenção ao Gate 01:** apenas o **Bloco C** (e o CRT do Bloco B) precisam existir antes da implementação do núcleo, pois são colunas do cadastro de produto. Todo o resto pode chegar até o Gate 05 sem atrasar nada. **A espera pelo contador não justifica adiar o Gate 01.**

> **Atenção ao Gate 05, backlog já pronto:** G-02, G-03, H-05 e H-06 não são só parametrização futura. `billing_profiles` (BR-508) e o grupo `IBSCBS` da NF-e (BR-608) já estão implementados no código e ficam **vazios, esperando exatamente essas respostas**. Atraso nelas trava funcionalidade que já existe, não só configuração pendente.

## 12. Tracker de respostas

| Bloco | Enviado em | Respondido em | Quem validou | Status |
|---|---|---|---|---|
| A - Identificação | | | | ⏳ aguardando |
| B - Regime | | | | ⏳ aguardando |
| C - Produtos | | | | ⏳ aguardando |
| D - CFOP | | | | ⏳ aguardando |
| E - Tributos | | | | ⏳ aguardando |
| F - Operacional | | | | ⏳ aguardando |
| G - Cobrança na NF-e | | | | ⏳ aguardando |
| H - Reforma | | | | ⏳ aguardando |

## 13. Riscos

| Risco | Prob. | Impacto | Mitigação |
|---|---|---|---|
| Contador responder só parte da pauta | Alta | Médio | Blocos independentes, tracker por bloco, cobrar os 🔴 primeiro |
| Resposta genérica ("usa 5102 mesmo") sem cobrir a matriz completa | Alta | Alto | Tabelas com célula por cenário forçam resposta específica |
| Cenários do Bloco D.2 (feira, consignação) não terem processo fiscal definido hoje, ou nem serem práticas reais da empresa ainda | Média | Alto | Descobrir agora é o objetivo. Confirmar com o dono do negócio em paralelo ao envio da pauta (ver §3, item 12, de [30-Dominio-da-Dona-Arteira](../30-Dominio-da-Dona-Arteira/README.md)). Pode virar BR nova e ajuste de processo, ou a resposta fica arquivada até o cenário existir |
| CSOSN 101 (E-02) exigir apuração mensal de percentual de crédito | Média | Médio | Se confirmado, `tax_profiles` precisa de campo de percentual com vigência mensal |
| Numeração colidir com emissor atual (F-02) | Média | Alto | Série dedicada ao ERP |
| Resposta a G-02, G-03, H-05 ou H-06 demorar | Média | Médio | `billing_profiles` e o grupo IBSCBS já estão implementados e vazios esperando essas respostas. Atraso trava funcionalidade pronta, não só parametrização futura |

## 14. Perguntas em aberto (nossas, não do contador)

- O Bloco D.2 (feira, consignação) presume cenários que ainda não confirmamos como práticas reais da empresa hoje (ver §3, item 12, de [30-Dominio-da-Dona-Arteira](../30-Dominio-da-Dona-Arteira/README.md)). Vale confirmar com o dono do negócio antes ou em paralelo ao envio da pauta.
- Se o contador não souber responder sobre feira ou consignação, quem responde? O dono do negócio precisa descrever o processo real primeiro.
- Se a resposta a F-06 for que a empresa precisa de NFC-e, isso é um **novo ADR** e altera o escopo do Gate 05, com impacto de prazo e custo.
- Reconsiderar mais adiante, se algum se mostrar necessário, os itens cortados desta pauta: terceirização de pintura, múltiplos locais físicos, retrabalho com saída de mercadoria, unidade tributável diferente da comercial, GTIN, prazo de cancelamento e carta de correção, condições de pagamento no atacado e contabilização de taxas de gateway.
