@extends('reports.layout')

@section('titulo-pagina', 'Vencimentos — ' . ($tipo === 'payable' ? 'a pagar' : 'a receber'))

@section('titulo', 'Vencimentos — ' . ($tipo === 'payable' ? 'a pagar' : 'a receber'))

@section('subtitulo')
    Quem {{ $tipo === 'payable' ? 'devemos' : 'nos deve' }}, com o saldo em aberto por faixa de atraso
    @if ($dataDe || $dataAte)
        · Vencimento {{ $dataDe ? 'de ' . \Illuminate\Support\Carbon::parse($dataDe)->format('d/m/Y') : '' }}
        {{ $dataAte ? 'até ' . \Illuminate\Support\Carbon::parse($dataAte)->format('d/m/Y') : '' }}
    @endif
@endsection

@section('conteudo')
    <table class="totais">
        <tr>
            <td>
                <span class="rotulo">A vencer</span>
                <span class="valor">{{ $emReais($totais['a_vencer']) }}</span>
            </td>
            <td>
                <span class="rotulo">Vencido 1–15 dias</span>
                <span class="valor">{{ $emReais($totais['1-15']) }}</span>
            </td>
            <td>
                <span class="rotulo">Vencido 16–30 dias</span>
                <span class="valor">{{ $emReais($totais['16-30']) }}</span>
            </td>
            <td>
                <span class="rotulo">Vencido 30+ dias</span>
                <span class="valor">{{ $emReais($totais['30+']) }}</span>
            </td>
            <td>
                <span class="rotulo">Total</span>
                <span class="valor">{{ $emReais($totais['total']) }}</span>
            </td>
        </tr>
    </table>

    <table class="dados">
        <thead>
            <tr>
                <th>{{ $tipo === 'payable' ? 'Fornecedor' : 'Cliente' }}</th>
                <th>Categoria</th>
                <th>Vencimento</th>
                <th>Faixa</th>
                <th style="text-align: right;">Saldo aberto</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($titulos as $titulo)
                <tr>
                    <td>{{ $titulo['nome'] }}</td>
                    <td>{{ $titulo['categoria'] }}</td>
                    <td>{{ $titulo['vencimento'] ? \Illuminate\Support\Carbon::parse($titulo['vencimento'])->format('d/m/Y') : '—' }}</td>
                    <td>{{ $faixaLabel($titulo['faixa']) }}</td>
                    <td style="text-align: right;">{{ $emReais($titulo['saldo_aberto']) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align: center; color: hsl(0, 0%, 45.1%);">Nenhum título aberto neste filtro.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
