# ADR-0017: Modelo de distribuição — instalação própria por cliente com white-label configurável

> **Status:** Proposto · **Data:** 2026-08-07 · **Decisores:** dono (modelo de negócio) · **Módulos afetados:** Deploy/Infra, Configuração/Branding, Onboarding

## Contexto

O projeto nasceu como ERP sob medida para a Dona Arteira, mas há intenção de reaproveitar o sistema para outras empresas (estimativa: 1 a 3 clientes nos próximos 6–12 meses). Diferente de um SaaS centralizado, o modelo de negócio definido é **licenciamento de produto instalável**: cada cliente hospeda sua própria instância (domínio e infraestrutura próprios), e o papel do fornecedor é entregar o sistema pronto para instalação e, na medida do possível, fácil de personalizar visualmente (logo, cores) para cada empresa.

Esse modelo elimina o risco de isolamento de dados entre clientes (cada instalação é fisicamente separada) e resolve naturalmente a questão de certificado A1 por cliente (cada ambiente tem o seu). Em contrapartida, introduz um problema novo: manter N instalações independentes atualizáveis sem que cada uma vire um fork divergente e sem custo proibitivo de manutenção manual.

## Decisão

O sistema será distribuído como **projeto self-hosted por cliente**, sem multi-tenancy de banco de dados. Toda personalização visual e cadastral (nome da empresa, logo, cores, CNPJ, dados fiscais) será resolvida via **dados de configuração**, nunca via código hardcoded — permitindo reuso do mesmo código-fonte "core" entre instalações.

**Componentes da decisão:**

1. **Camada de branding via configuração, não código:** tabela `empresa_config` armazenando identidade visual (logo, cor primária/secundária, favicon) e dados cadastrais/fiscais. Frontend consome essas configurações via CSS variables carregadas dinamicamente, sem necessidade de alterar componentes React por cliente.
2. **Wizard de setup inicial:** tela de onboarding executada uma vez por instalação, coletando dados da empresa, branding e certificado — sem exigir edição manual de arquivos de configuração.
3. **Empacotamento via Docker Compose:** entrega padronizada (Laravel + frontend + MariaDB + Nginx) para instalação previsível em qualquer ambiente de hospedagem do cliente.
4. **Canal de atualização (fase atual — até 3 clientes):** distribuição via Git com tags de versão (`git pull` + `migrate`). Revisar para pacote Composer privado (core como dependência versionada) caso o número de clientes ultrapasse a faixa atual e a divergência manual entre instalações se torne custosa de manter.

## Alternativas consideradas

### SaaS multi-tenant centralizado (banco compartilhado ou schema por tenant)
**Prós:** atualização centralizada instantânea para todos os clientes; modelo de receita recorrente mais direto.
**Contras:** exige arquitetura de isolamento de dados robusta (crítico tratando-se de dados fiscais); responsabilidade de hospedagem e disponibilidade passa a ser do fornecedor; não corresponde ao modelo de negócio definido pelo dono (cliente hospeda a própria instância). Descartada para o momento.

### Pacote Composer privado desde o início
**Prós:** separação limpa entre core e customização desde o primeiro cliente; menor risco de divergência futura.
**Contras:** overhead de engenharia (versionamento, publicação de pacote, tooling) desproporcional para 1–3 clientes no horizonte atual. Mapeada como evolução natural do modelo Git, não como decisão imediata.

## Consequências

**Positivas:**
- Isolamento de dados entre clientes resolvido estruturalmente (sem risco de vazamento entre empresas).
- Certificado A1 e dados fiscais de cada cliente permanecem na infraestrutura do próprio cliente, alinhado ao princípio já registrado no ADR-0009 de que XML/certificado nunca saem da infraestrutura.
- Personalização visual não exige intervenção de código por cliente, reduzindo custo de onboarding.
- Modelo de licenciamento de produto (não serviço recorrente centralizado) simplifica questões tributárias e de responsabilidade sobre disponibilidade.

**Negativas / dívidas assumidas:**
- Risco de divergência entre instalações se alguma receber customização fora do padrão de configuração (precisa de disciplina: nada de alteração direta de código por cliente).
- Canal de atualização via Git é manual e não escala além de poucas instalações sem esforço crescente — gatilho de revisão abaixo.
- Suporte técnico a cada cliente precisa considerar que o ambiente de hospedagem é responsabilidade dele, não do fornecedor (variação de infraestrutura pode gerar inconsistência de comportamento).

**Gatilhos de revisão (revisar para pacote Composer privado ou outro modelo se QUALQUER um ocorrer):**
- Número de clientes ativos ultrapassar 3.
- Ocorrer divergência de código entre instalações que exija merge manual de correções.
- Atualização de uma instalação (`git pull` + `migrate`) causar quebra por customização não isolada em configuração.
