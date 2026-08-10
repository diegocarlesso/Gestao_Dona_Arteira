# ADR-0017: Modelo de distribuição — instalação própria por cliente com white-label configurável

> **Status:** Proposto · **Data:** 2026-08-07 · **Decisores:** dono (modelo de negócio) · **Módulos afetados:** Deploy/Infra, Configuração/Branding, Onboarding, Modelagem de Domínio

## Contexto

O projeto nasceu como ERP sob medida para a Dona Arteira, mas há intenção de reaproveitar o sistema para outras empresas (estimativa: 1 a 3 clientes nos próximos 6–12 meses). Diferente de um SaaS centralizado, o modelo de negócio definido é **licenciamento de produto instalável**: cada cliente hospeda sua própria instância (domínio e infraestrutura próprios), e o papel do fornecedor é entregar o sistema pronto para instalação e, na medida do possível, fácil de personalizar visualmente (logo, cores) para cada empresa.

Esse modelo elimina o risco de isolamento de dados entre clientes (cada instalação é fisicamente separada) e resolve naturalmente a questão de certificado A1 por cliente (cada ambiente tem o seu). Em contrapartida, introduz problemas novos que precisam ser tratados com timing correto para não travar a entrega do cliente atual nem gerar retrabalho caro no futuro.

### Os três baldes de complexidade

Analisando como sistemas de mercado equivalentes (Bling, GestãoClick, Tiny, VendaSimples e similares) resolvem "um sistema, N segmentos de negócio diferentes", ficou claro que a personalização se divide em complexidades bem distintas — e só uma delas é realmente cara:

1. **Visual/branding** — trivial. Logo, cores, nome da empresa. 100% dado, zero impacto no restante do sistema.
2. **Configuração fiscal/cadastral** — custo médio, mas não é overhead causado pela decisão de generalizar: CNPJ, inscrição estadual, regime tributário, certificado A1 já precisariam ser configuráveis mesmo em um sistema single-cliente (a própria Dona Arteira mudou de regime ao desenquadrar do MEI).
3. **Genericidade do domínio** — o ponto de risco real. Categorias de produto, unidades de medida, variações/atributos e workflows de produção não podem ser hardcoded pensando apenas na Dona Arteira. Nos sistemas de mercado analisados, "segmento" (artesanato, loja de roupas, distribuidora etc.) é resolvido como **preset de configuração sobre um motor genérico** — nunca como código diferente por segmento. O motor (produto → estoque → venda → NF-e → financeiro) é idêntico para todos os clientes; o que muda é o dado cadastrado.

## Decisão

O sistema será distribuído como **projeto self-hosted por cliente**, sem multi-tenancy de banco de dados. Toda personalização visual, cadastral e de domínio será resolvida via **dados de configuração**, nunca via código hardcoded — permitindo reuso do mesmo código-fonte "core" entre instalações.

A implementação será dividida em duas fases, evitando generalização prematura antes de haver um segundo cliente real:

### Fase 1 — imediata, junto com o desenvolvimento da Dona Arteira

Custo baixo: é disciplina de modelagem, não trabalho adicional significativo.

- Nunca hardcode nome, cor ou identidade visual da empresa direto no código — usar variável/config desde o início.
- Dados fiscais/cadastrais (CNPJ, inscrição estadual, regime tributário, certificado) sempre configuráveis, nunca fixos.
- **Modelagem de domínio genérica desde o primeiro cadastro:** categorias de produto, unidades de medida e atributos/variações modelados como cadastro livre (tabelas configuráveis), não como enum ou campo fixo no código.
- Toda regra de negócio nova passa pelo [registro de regras](https://github.com/diegocarlesso/Gestao_Dona_Arteira/blob/main/docs/01-Regras-de-Negocio/01-registro-de-regras.md) já existente, classificada explicitamente como regra do sistema (genérica) ou regra específica da Dona Arteira.
- **Teste prático de genericidade**, aplicado antes de codificar qualquer regra ou modelo: *"Se amanhã aparecesse um cliente de outro segmento (ex.: loja de roupas em vez de artesanato), esse campo ou regra ainda funcionaria apenas mudando o dado cadastrado, ou seria necessário mexer no código?"* Se a resposta for "mexer no código", é sinal de que a regra é específica da Dona Arteira e deve ser tratada como tal (configurável ou isolada), não meio-embutida no domínio geral.

### Fase 2 — apenas quando o cliente #2 assinar de fato

Custo mais alto, e por isso adiado até haver necessidade real, evitando over-engineering especulativo:

- Wizard de onboarding para configuração inicial (empresa, branding, certificado) sem edição manual de arquivos.
- Empacotamento via Docker Compose para instalação padronizada em qualquer ambiente de hospedagem do cliente.
- Estratégia de atualização entre instalações (fase inicial: Git com tags de versão; reavaliar para pacote Composer privado se o número de clientes crescer — ver gatilhos de revisão).

## Alternativas consideradas

### SaaS multi-tenant centralizado (banco compartilhado ou schema por tenant)
**Prós:** atualização centralizada instantânea para todos os clientes; modelo de receita recorrente mais direto.
**Contras:** exige arquitetura de isolamento de dados robusta (crítico tratando-se de dados fiscais); responsabilidade de hospedagem e disponibilidade passa a ser do fornecedor; não corresponde ao modelo de negócio definido pelo dono (cliente hospeda a própria instância). Descartada para o momento.

### Generalizar tudo agora (branding + domínio + empacotamento + onboarding de uma vez)
**Prós:** terreno completamente pronto antes mesmo do segundo cliente aparecer.
**Contras:** paga complexidade de produto genérico para resolver um problema ainda hipotético; atrasa a entrega real da Dona Arteira, que é quem paga a conta hoje. Descartada em favor da abordagem faseada.

### Pacote Composer privado desde o início
**Prós:** separação limpa entre core e customização desde o primeiro cliente; menor risco de divergência futura.
**Contras:** overhead de engenharia (versionamento, publicação de pacote, tooling) desproporcional para 1–3 clientes no horizonte atual. Mapeada como evolução natural do modelo Git, não como decisão imediata.

## Consequências

**Positivas:**
- Isolamento de dados entre clientes resolvido estruturalmente (sem risco de vazamento entre empresas).
- Certificado A1 e dados fiscais de cada cliente permanecem na infraestrutura do próprio cliente, alinhado ao princípio já registrado no ADR-0009 de que XML/certificado nunca saem da infraestrutura.
- Personalização visual e cadastral não exige intervenção de código por cliente, reduzindo custo de onboarding futuro.
- Modelagem de domínio genérica desde a Fase 1 evita retrabalho caro de "desenrosca-Dona-Arteira-do-código" caso um segundo cliente apareça.
- Fase 2 só é paga quando há receita/cliente real justificando o investimento, evitando over-engineering especulativo.
- Modelo de licenciamento de produto (não serviço recorrente centralizado) simplifica questões tributárias e de responsabilidade sobre disponibilidade.

**Negativas / dívidas assumidas:**
- Exige disciplina constante durante a Fase 1 (aplicar o teste de genericidade em toda regra nova) — risco de relaxamento sob pressão de prazo.
- Risco de divergência entre instalações se alguma receber customização fora do padrão de configuração.
- Canal de atualização via Git é manual e não escala além de poucas instalações sem esforço crescente.
- Suporte técnico a cada cliente precisa considerar que o ambiente de hospedagem é responsabilidade dele, não do fornecedor.

**Gatilhos de revisão:**
- Revisar Fase 2 (Docker, onboarding, versionamento) quando: um segundo cliente assinar de fato.
- Revisar canal de atualização para pacote Composer privado se: número de clientes ativos ultrapassar 3; ocorrer divergência de código entre instalações que exija merge manual; ou atualização de uma instalação causar quebra por customização não isolada em configuração.
- Revisar disciplina de modelagem de domínio se: for identificado, em qualquer momento, campo ou regra da Dona Arteira hardcoded que reprove o teste de genericidade — tratar como débito técnico a corrigir antes de aceitar um segundo cliente.
