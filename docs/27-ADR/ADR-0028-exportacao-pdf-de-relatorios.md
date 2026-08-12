# ADR-0028: Exportação de relatórios em PDF via dompdf, com timbre da marca

> **Status:** ✅ Aceito · **Data:** 2026-08-12 · **Decisores:** dono do produto
> **Módulos afetados:** 20 (Relatórios), 12 (Financeiro — primeiro relatório), 06 (Frontend — reaproveita a identidade visual §10.1)

## Contexto

O catálogo de relatórios (pasta 20 §2, ponto 4) já previa: "Exportação CSV
sempre; PDF quando for documento de trabalho". Depois de entregar CSV para
os quatro primeiros relatórios (§3.3–3.6), o dono pediu PDF "estilo grandes
ERPs" — timbrado com a logo da empresa — começando por um relatório
(Vencimentos) para validar o formato antes de espalhar para os demais.

Restrição que decide a escolha técnica: **não há Node.js no servidor de
produção** (Hostinger Business, confirmado em
[docs/23-Deploy §3.1](../23-Deploy/04-atualizar-producao.md) — "o build
nunca roda no servidor"). Qualquer gerador de PDF que dependa de um
navegador headless (Puppeteer, Playwright, `spatie/browsershot`) exigiria
instalar Chrome no servidor — inviável no plano compartilhado, e o mesmo
motivo que já bloqueou `npm run build` no servidor. Um gerador que dependa
de binário externo via `exec`/`proc_open` (`wkhtmltopdf` + Snappy) reabriria
a mesma categoria de problema do **P-15** (`proc_open` historicamente
instável entre versões do PHP neste ambiente,
[docs/23-Deploy/01 §7.5](../23-Deploy/01-validacao-ambiente-business.md)) —
evitável ao escolher uma biblioteca **pura PHP**.

## Decisão

**Usaremos `barryvdh/laravel-dompdf`** (wrapper Laravel do `dompdf/dompdf`)
para gerar todo PDF de relatório. Pura PHP, renderiza HTML/CSS já
carregado no processo do PHP-FPM — sem subprocesso, sem binário externo,
sem `proc_open`. As extensões que precisa (`gd`, `mbstring`, `dom`) já
estão confirmadas presentes no ambiente de produção
(docs/23-Deploy/01 §6.2).

**Layout compartilhado.** Um único template base
(`resources/views/reports/layout.blade.php`) define o timbre — logo,
nome/CNPJ da empresa quando configurado (reaproveita `config('fiscal.
emitente.*')`, já a fonte canônica desse dado, sem duplicar em uma config
nova), título do relatório, data de geração, rodapé com numeração de
página. Cada relatório escreve só o conteúdo (tabela, totais); o timbre
nunca se repete. "Espalhar para os demais" (pedido do dono) vira estender
o layout, não recriar a moldura relatório a relatório.

**Onde nasce cada PDF**: método `pdf()` no mesmo Controller do relatório
(mesmo lugar de `index()`/`export()` CSV) — não um módulo de PDF à parte.
O relatório continua sendo dono da sua consulta (pasta 20 §2); o PDF é só
mais um formato de saída dela, como o CSV já é.

## Alternativas consideradas

### Alternativa A — `spatie/browsershot` (Puppeteer/Chrome headless)
**Prós:** renderização fiel a um navegador de verdade, CSS moderno completo
(flexbox, grid), reaproveitaria a mesma tela React via captura.
**Contras:** exige Node.js e Chrome instalados no servidor — não existe no
plano Hostinger Business, o mesmo motivo que já tira `npm run build` do
servidor (docs/23-Deploy §3.1/§8). Instalar um Chrome headless num plano
compartilhado é o tipo de mudança de infraestrutura que a pasta 03 já
descartou.
**Descartada:** infraestrutura indisponível no ambiente real.

### Alternativa B — `barryvdh/laravel-snappy` (wkhtmltopdf)
**Prós:** CSS mais completo que o dompdf (motor WebKit real), boa
reputação histórica em projetos Laravel.
**Contras:** exige o binário `wkhtmltopdf` instalado e chamado via
`exec`/`proc_open` — mesma categoria de risco do **P-15**
(`disable_functions` varia por versão de PHP no CloudLinux e já quebrou
`composer install` uma vez neste projeto). Descontinuado pelo autor
original desde 2023, sem atualização de segurança ativa.
**Descartada:** reabre uma classe de risco operacional já vivida, e
depende de manutenção externa que parou.

### Alternativa C — `mPDF`
**Prós:** também pura PHP, sem dependência de binário/Node — mesma
vantagem central do dompdf.
**Contras:** biblioteca maior, suporte a HTML/CSS na prática muito
parecido com o dompdf para o caso de uso (tabelas, texto, layout de
página), sem vantagem clara que justifique escolher a opção menos comum
no ecossistema Laravel (o `barryvdh/laravel-dompdf` é o pacote de fato
padrão, com integração pronta e mais exemplos).
**Descartada:** sem diferencial que compense a familiaridade menor.

## Consequências

**Positivas:**
- Nenhuma dependência de infraestrutura nova no servidor — funciona no
  mesmo PHP-FPM que já roda o resto do ERP.
- Timbre consistente entre relatórios, mudando em um lugar só.
- Reaproveita a identidade visual já definida (docs/06 §10.1) e o dado
  legal da empresa já configurado para NF-e (`config('fiscal.emitente')`),
  sem criar um terceiro lugar para guardar CNPJ/razão social.

**Negativas / dívidas assumidas:**
- **CSS do dompdf é mais limitado que um navegador real**: sem flexbox
  robusto, sem grid — o template usa tabelas e posicionamento clássico,
  como um documento impresso de verdade sempre usou. Não é regressão para
  este caso de uso (relatório impresso, não tela interativa).
- **Sem o CNPJ/razão social até o `.env` de produção ganhar
  `NFE_EMITENTE_*`** (ainda pendente, aguardando o certificado/contador —
  ver ADR-0025): o timbre sai só com o nome "Dona Arteira" e a logo até
  lá. Degrada graciosamente, não quebra.

**Gatilhos de revisão:**
- Se um relatório precisar de gráfico (não só tabela) no PDF, o dompdf
  não renderiza `<canvas>`/SVG complexo direito — reabrir a escolha só
  para esse caso, não trocar a base inteira.
- Se o ambiente de produção ganhar Node.js/Chrome disponível por outro
  motivo (ex.: mudança de plano de hospedagem, ADR-0016), reavaliar
  Browsershot para relatórios que quiserem espelhar a tela pixel a pixel.
