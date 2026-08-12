import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

type Janelas = { 30: string; 60: string; 90: string };

interface Props {
    fluxo: {
        projetado: Janelas;
        realizado: Janelas;
    };
    podeExportar: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Financeiro', href: '/financeiro' },
    { title: 'Fluxo de caixa', href: '/financeiro/fluxo-de-caixa' },
];

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

function Cartao({ titulo, valor, descricao }: { titulo: string; valor: string; descricao: string }) {
    const positivo = Number(valor) >= 0;

    return (
        <div className="rounded-md border p-4">
            <div className="text-muted-foreground text-xs">{titulo}</div>
            <div className={`mt-1 text-2xl font-semibold ${positivo ? '' : 'text-destructive'}`}>{emReais(valor)}</div>
            <div className="text-muted-foreground mt-1 text-xs">{descricao}</div>
        </div>
    );
}

export default function FluxoDeCaixaIndex({ fluxo, podeExportar }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fluxo de caixa" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Fluxo de caixa</h1>
                        <p className="text-muted-foreground text-sm">
                            Projetado = títulos abertos por vencimento. Realizado = baixas por data. Saldo por conta se confere contra o
                            extrato bancário manualmente por enquanto.
                        </p>
                    </div>
                    {podeExportar && (
                        <Button asChild size="sm" variant="outline">
                            <a href="/financeiro/fluxo-de-caixa/csv">Exportar CSV</a>
                        </Button>
                    )}
                </div>

                <section>
                    <h2 className="mb-3 font-medium">Projetado (a receber − a pagar, por vencimento)</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <Cartao titulo="Próximos 30 dias" valor={fluxo.projetado[30]} descricao="Títulos com vencimento nesta janela" />
                        <Cartao titulo="Próximos 60 dias" valor={fluxo.projetado[60]} descricao="Títulos com vencimento nesta janela" />
                        <Cartao titulo="Próximos 90 dias" valor={fluxo.projetado[90]} descricao="Títulos com vencimento nesta janela" />
                    </div>
                </section>

                <section>
                    <h2 className="mb-3 font-medium">Realizado (baixas dos últimos dias)</h2>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <Cartao titulo="Últimos 30 dias" valor={fluxo.realizado[30]} descricao="Baixas efetivas neste período" />
                        <Cartao titulo="Últimos 60 dias" valor={fluxo.realizado[60]} descricao="Baixas efetivas neste período" />
                        <Cartao titulo="Últimos 90 dias" valor={fluxo.realizado[90]} descricao="Baixas efetivas neste período" />
                    </div>
                </section>
            </div>
        </AppLayout>
    );
}
