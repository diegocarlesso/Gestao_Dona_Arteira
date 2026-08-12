<!DOCTYPE html>
{{--
    Timbre compartilhado de todo relatório em PDF — ADR-0028.

    Cada relatório só preenche `titulo`, `subtitulo` e `conteudo`; a
    moldura (logo, identificação da empresa, rodapé com paginação) mora
    aqui uma vez só. Renderizado pelo dompdf (HTML/CSS pura PHP, sem
    Node/Chrome) — cabeçalho e rodapé usam o padrão de `position: fixed`
    com deslocamento negativo dentro da margem da página, que é como o
    dompdf repete conteúdo em toda página.
--}}
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>@yield('titulo-pagina', 'Relatório')</title>
    <style>
        @page {
            margin: 125px 28px 55px 28px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: hsl(0, 0%, 3.9%);
        }

        header {
            position: fixed;
            top: -125px;
            left: 0;
            right: 0;
            height: 105px;
        }

        .timbre {
            display: table;
            width: 100%;
        }

        .timbre .logo {
            display: table-cell;
            width: 90px;
            vertical-align: middle;
        }

        .timbre .logo img {
            height: 48px;
        }

        .timbre .empresa {
            display: table-cell;
            text-align: right;
            vertical-align: middle;
            font-size: 9px;
            color: hsl(0, 0%, 45.1%);
            line-height: 1.5;
        }

        .timbre .empresa strong {
            font-size: 12px;
            color: hsl(326, 60%, 12%);
        }

        .linha-marca {
            margin-top: 10px;
            border-top: 2px solid hsl(323, 100%, 70%);
        }

        .cabecalho-relatorio {
            margin-top: 14px;
        }

        .cabecalho-relatorio h1 {
            margin: 0 0 4px 0;
            font-size: 16px;
            color: hsl(326, 60%, 12%);
        }

        .cabecalho-relatorio .subtitulo {
            margin: 0;
            font-size: 10px;
            color: hsl(0, 0%, 45.1%);
        }

        footer {
            position: fixed;
            bottom: -55px;
            left: 0;
            right: 0;
            height: 40px;
            border-top: 1px solid hsl(0, 0%, 92.8%);
            padding-top: 6px;
            font-size: 8px;
            color: hsl(0, 0%, 45.1%);
        }

        footer .rodape {
            display: table;
            width: 100%;
        }

        footer .rodape .gerado {
            display: table-cell;
            text-align: left;
        }

        footer .rodape .pagina {
            display: table-cell;
            text-align: right;
        }

        footer .rodape .pagina:after {
            content: counter(page) " de " counter(pages);
        }

        table.dados {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        table.dados thead {
            display: table-header-group;
        }

        table.dados th {
            background: hsl(0, 0%, 96.1%);
            color: hsl(0, 0%, 9%);
            text-align: left;
            padding: 6px 8px;
            font-size: 9px;
            border-bottom: 1px solid hsl(0, 0%, 92.8%);
        }

        table.dados td {
            padding: 5px 8px;
            border-bottom: 1px solid hsl(0, 0%, 92.8%);
            font-size: 9.5px;
        }

        table.dados tr {
            page-break-inside: avoid;
        }

        table.totais {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        table.totais td {
            padding: 8px;
            border: 1px solid hsl(0, 0%, 92.8%);
            text-align: center;
        }

        table.totais .rotulo {
            display: block;
            font-size: 8px;
            color: hsl(0, 0%, 45.1%);
            margin-bottom: 3px;
        }

        table.totais .valor {
            display: block;
            font-size: 12px;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <header>
        <div class="timbre">
            <div class="logo">
                <img src="{{ $letterhead->logoBase64() }}" alt="Dona Arteira">
            </div>
            <div class="empresa">
                <strong>{{ $empresa['nome'] }}</strong><br>
                @if ($empresa['razaoSocial'])
                    {{ $empresa['razaoSocial'] }}@if ($empresa['cnpj']) — CNPJ {{ $empresa['cnpj'] }}@endif<br>
                @endif
                @if ($empresa['endereco'])
                    {{ $empresa['endereco'] }}
                @endif
            </div>
        </div>
        <div class="linha-marca"></div>
        <div class="cabecalho-relatorio">
            <h1>@yield('titulo')</h1>
            <p class="subtitulo">@yield('subtitulo')</p>
        </div>
    </header>

    <footer>
        <div class="rodape">
            <div class="gerado">
                Gerado em {{ $geradoEm }} pelo sistema ERP Dona Arteira · Documento gerencial, sem valor fiscal
            </div>
            <div class="pagina"></div>
        </div>
    </footer>

    @yield('conteudo')
</body>
</html>
