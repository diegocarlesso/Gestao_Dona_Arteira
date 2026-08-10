<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Fiscal — docs/13-Fiscal, docs/14-NFe, ADR-0025
|--------------------------------------------------------------------------
|
| Mesmo padrão de `config/integrations.php`: flag por integração, tudo do
| `.env`, nada de credencial no repositório.
|
| A trava que importa aqui é o `environment`. O pipeline inteiro (gatilho,
| job, montagem, transmissão) roda igual nos dois ambientes — a única
| diferença é o `tpAmb` enviado à SEFAZ. Virar para `producao` é decisão
| humana explícita, documentada em runbook, e **só depois** de a pauta de
| validação com o contador (docs/13-Fiscal/01) estar resolvida (ADR-0025 §4).
|
*/

return [

    'nfe' => [
        // Desligada por padrão, pelo mesmo motivo do Woo: integração que
        // nasce ligada é integração que dispara no primeiro deploy de quem
        // só queria rodar os testes. Aqui o custo seria maior — documento
        // fiscal não se desfaz com um `git revert`.
        'enabled' => (bool) env('NFE_ENABLED', false),

        // `homologacao` (tpAmb=2) | `producao` (tpAmb=1).
        //
        // O default **nunca** é `producao`. Não por cautela genérica: a doc
        // 13 está bloqueada por validação do contador, então NCM, CFOP e
        // CSOSN ainda são hipóteses — emitir com eles em produção é
        // autuação, não bug reversível. BR-605 manda manter homologação
        // permanentemente disponível, e é nela que este pipeline vive até
        // segunda ordem.
        'environment' => env('NFE_ENVIRONMENT', 'homologacao'),

        // Série usada pela emissão automática. `fiscal_series` guarda o
        // contador; isto só diz qual série procurar (BR-602).
        'series' => env('NFE_SERIES', '1'),

        // UF do emitente — decide se a operação é dentro ou fora do estado
        // e, com isso, qual perfil de `tax_profiles` se aplica (CFOP 5xxx
        // vs 6xxx, docs/13 §3). Sem ela não dá para resolver o perfil, e a
        // emissão registra a pendência em vez de chutar: um CFOP errado é
        // exatamente o erro que o ADR-0025 se propõe a não cometer.
        'uf_emitente' => env('NFE_UF_EMITENTE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificado A1 — docs/14 §4, pasta 25
    |--------------------------------------------------------------------------
    |
    | Lido pelo `SpedNfeGateway` (que ainda **não** é o bind ativo — ver
    | `FiscalServiceProvider`). As chaves nascem aqui para que a integração
    | do `sped-nfe` seja configuração, e não uma caça a onde pôr o `.pfx`.
    |
    | O arquivo fica **fora do webroot**, cifrado, e a senha vem do `.env` —
    | nunca do repositório, nunca em log.
    |
    */
    'certificado' => [
        'path' => env('NFE_CERT_PATH'),
        'password' => env('NFE_CERT_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Emitente — quem assina a nota (docs/13 §A-01/A-02, docs/14 §3)
    |--------------------------------------------------------------------------
    |
    | Cada campo daqui vai **literalmente** para dentro do XML, nos grupos
    | `emit`/`enderEmit`. Todos nascem vazios e não têm default: a pauta de
    | validação com o contador (docs/13-Fiscal/01) tem CNPJ, IE, regime e
    | código IBGE do município como itens 🔴 em aberto, e um valor plantado
    | "só para funcionar" viraria uma nota errada assinada com certificado
    | de verdade no dia em que alguém virasse a chave.
    |
    | Faltando qualquer obrigatório, `SpedNfeGateway` recusa a emissão com
    | `EmissaoInvalida` dizendo **qual variável** preencher — não emite meia
    | nota nem chuta.
    |
    | A UF **não** está aqui de propósito: já existe em `nfe.uf_emitente`,
    | que é o que decide dentro/fora do estado (e, portanto, o CFOP).
    | Duplicá-la criaria o cenário em que alguém atualiza uma e esquece a
    | outra — e a nota sairia com endereço de um estado e CFOP de outro.
    |
    */
    'emitente' => [
        'cnpj' => env('NFE_EMITENTE_CNPJ'),
        'razao_social' => env('NFE_EMITENTE_RAZAO_SOCIAL'),

        // Opcional no layout (`xFant`) — omitido quando vazio.
        'nome_fantasia' => env('NFE_EMITENTE_NOME_FANTASIA'),

        'ie' => env('NFE_EMITENTE_IE'),

        // Código de Regime Tributário: 1=Simples Nacional, 2=Simples com
        // excesso de sublimite, 3=Regime Normal, 4=MEI. A doc 13 trabalha
        // com Simples Nacional como **hipótese**, e é por isso que o valor
        // vem do `.env` e não de uma constante: 1/2/4 fazem o item sair com
        // CSOSN (o que `tax_profiles` guarda hoje); 3 exigiria CST e
        // alíquotas que o modelo ainda não tem, e a emissão recusa em vez
        // de improvisar.
        'crt' => env('NFE_EMITENTE_CRT'),

        'endereco' => [
            'logradouro' => env('NFE_EMITENTE_LOGRADOURO'),
            'numero' => env('NFE_EMITENTE_NUMERO'),
            'bairro' => env('NFE_EMITENTE_BAIRRO'),
            'municipio' => env('NFE_EMITENTE_MUNICIPIO'),

            // Código IBGE de 7 dígitos. O schema exige (`cMun`), e ele
            // também vira o `cMunFG` (município do fato gerador) da nota.
            'codigo_municipio' => env('NFE_EMITENTE_COD_MUNICIPIO'),

            'cep' => env('NFE_EMITENTE_CEP'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tributos federais por item — provisório, docs/13 (bloqueado)
    |--------------------------------------------------------------------------
    |
    | O layout 4.00 exige um grupo PIS e um COFINS em **todo** item; sem
    | eles o XML não passa nem no XSD. O CST de cada um é decisão do
    | contador (para Simples Nacional costuma ser 07 ou 49, mas "costuma"
    | não emite nota), então mora aqui, vazio, em vez de virar constante.
    |
    | **Lugar definitivo é `tax_profiles`**, junto de CFOP e CSOSN: o CST
    | varia por operação, não por empresa. Fica em config enquanto a doc 13
    | está bloqueada — mover para coluna é migration, e migration sobre
    | hipótese não validada é retrabalho garantido.
    |
    */
    'tributos' => [
        'pis_cst' => env('NFE_PIS_CST'),
        'cofins_cst' => env('NFE_COFINS_CST'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Responsável técnico — NT 2018.005
    |--------------------------------------------------------------------------
    |
    | Grupo `infRespTec`: identifica quem **desenvolve** o emissor, não quem
    | emite. Opcional no XSD, exigido na prática pela maioria das UFs — sem
    | ele a autorização costuma voltar rejeitada.
    |
    | Não é dado do contador: é o CNPJ/contato de quem mantém este ERP. Sai
    | do XML por inteiro enquanto estiver vazio (grupo opcional), o que
    | mantém o XSD válido; preencher é pré-requisito da primeira
    | transmissão em homologação.
    |
    */
    'resp_tecnico' => [
        'cnpj' => env('NFE_RESPTEC_CNPJ'),
        'contato' => env('NFE_RESPTEC_CONTATO'),
        'email' => env('NFE_RESPTEC_EMAIL'),
        'fone' => env('NFE_RESPTEC_FONE'),
    ],

];
