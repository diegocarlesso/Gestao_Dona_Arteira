# ADR-0013: Dinheiro em DECIMAL(15,2) + brick/money (nunca float)

> **Status:** Aceito · **Data:** 2026-07-03 · **Decisores:** senior-dba, financial-specialist
> **Módulos afetados:** 04, 12, 13, 14 e todo módulo com valores

## Contexto

Float binário não representa decimais exatos: somas de itens, impostos e totais de NF-e divergem por centavos — e a SEFAZ rejeita nota cujos totais não fecham. O legado usa `Float` para preços (risco herdado a tratar na migração).

## Decisão

Banco: `DECIMAL(15,2)` para valores monetários, `DECIMAL(15,3)` para quantidades, `DECIMAL(5,2)` para percentuais. PHP: toda aritmética monetária via **`brick/money`** (BRL), com arredondamento **half-up** documentado nos cálculos fiscais (padrão das regras de NF-e) e half-even apenas onde regra específica exigir. API: dinheiro serializado como **string** decimal + moeda (pasta 07). Frontend nunca calcula total autoritativo — exibe o que a API retorna.

## Alternativas consideradas

### Inteiro em centavos (minor units)
Também correto e comum; porém: queries/relatórios SQL menos legíveis, conversões constantes em relatórios e na migração, e o ganho sobre DECIMAL no MariaDB é nulo para o nosso volume. Descartada.

### Float com round() disciplinado
Disciplina não sobrevive a anos de manutenção; classe de bug silenciosa e cara (fiscal). Descartada.

## Consequências

**Positivas:** totais determinísticos; conferência de centavos na migração e nos totais de NF-e viável; queries de relatório diretas.

**Negativas / dívidas:** conversão explícita float→decimal na importação do legado/Woo com relatório de diferenças (pasta 17); disciplina de sempre usar Money no PHP (PHPStan rule/convenção em code review).

**Gatilhos de revisão:** nenhum previsto.
