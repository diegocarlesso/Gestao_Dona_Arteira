import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

type LinhaCanal = {
    canal: string;
    canal_label: string;
    pedidos: number;
    total: string;
    ticket_medio: string;
};

interface Props {
    dataDe: string;
    dataAte: string;
    porCanal: LinhaCanal[];
    totalGeral: { pedidos: number; total: string; ticket_medio: string };
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Vendas', href: '/pedidos' },
    { title: 'Vendas por período', href: '/relatorios/vendas-por-periodo' },
];

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

export default function SalesByPeriodReport({ dataDe, dataAte, porCanal, totalGeral }: Props) {
    const filtrar = (novosFiltros: Partial<{ data_de: string; data_ate: string }>) => {
        router.get(
            '/relatorios/vendas-por-periodo',
            { data_de: novosFiltros.data_de ?? dataDe, data_ate: novosFiltros.data_ate ?? dataAte },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vendas por período" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Vendas por período e canal</h1>
                        <p className="text-muted-foreground text-sm">
                            Venda = pedidos confirmados no período, rascunhos e cancelados excluídos — mesma definição do painel geral.
                        </p>
                    </div>
                    <Button asChild size="sm" variant="outline">
                        <a href={`/relatorios/vendas-por-periodo/csv?data_de=${dataDe}&data_ate=${dataAte}`}>Exportar CSV</a>
                    </Button>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div>
                        <Label htmlFor="data_de">De</Label>
                        <Input id="data_de" type="date" value={dataDe} onChange={(e) => filtrar({ data_de: e.target.value })} />
                    </div>
                    <div>
                        <Label htmlFor="data_ate">Até</Label>
                        <Input id="data_ate" type="date" value={dataAte} onChange={(e) => filtrar({ data_ate: e.target.value })} />
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div className="rounded-md border p-4">
                        <div className="text-muted-foreground text-xs">Pedidos no período</div>
                        <div className="mt-1 text-2xl font-semibold">{totalGeral.pedidos}</div>
                    </div>
                    <div className="rounded-md border p-4">
                        <div className="text-muted-foreground text-xs">Total vendido</div>
                        <div className="mt-1 text-2xl font-semibold">{emReais(totalGeral.total)}</div>
                    </div>
                    <div className="rounded-md border p-4">
                        <div className="text-muted-foreground text-xs">Ticket médio</div>
                        <div className="mt-1 text-2xl font-semibold">{emReais(totalGeral.ticket_medio)}</div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Canal</th>
                                <th className="p-3 font-medium">Pedidos</th>
                                <th className="p-3 font-medium">Total vendido</th>
                                <th className="p-3 font-medium">Ticket médio</th>
                            </tr>
                        </thead>
                        <tbody>
                            {porCanal.map((linha) => (
                                <tr key={linha.canal} className="border-t">
                                    <td className="p-3">{linha.canal_label}</td>
                                    <td className="p-3">{linha.pedidos}</td>
                                    <td className="p-3 whitespace-nowrap">{emReais(linha.total)}</td>
                                    <td className="p-3 whitespace-nowrap">{emReais(linha.ticket_medio)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
