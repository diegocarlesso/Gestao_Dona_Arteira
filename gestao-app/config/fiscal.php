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
    | Ainda **não usado** nesta passada: a transmissão real está atrás do
    | `NfeGatewayInterface` e a implementação ativa é a Null. As chaves
    | nascem aqui para que a integração do `sped-nfe` seja configuração, e
    | não uma caça a onde pôr o caminho do `.pfx`.
    |
    | O arquivo fica **fora do webroot**, cifrado, e a senha vem do `.env` —
    | nunca do repositório, nunca em log.
    |
    */
    'certificado' => [
        'path' => env('NFE_CERT_PATH'),
        'password' => env('NFE_CERT_PASSWORD'),
    ],

];
