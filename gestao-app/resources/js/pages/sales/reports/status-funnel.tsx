import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

type LinhaStatus = { status: string; status_label: string; pedidos: number };

interface Props {
    porStatus: LinhaStatus[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Vendas', href: '/pedidos' },
    { title: 'Funil de pedidos', href: '/relatorios/funil-de-pedidos' },
];

export default function OrderStatusFunnelReport({ porStatus }: Props) {
    const maior = Math.max(1, ...porStatus.map((l) => l.pedidos));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Funil de pedidos" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Funil de pedidos por status</h1>
                        <p className="text-muted-foreground text-sm">O que está travado, contado agora — sem filtro de período.</p>
                    </div>
                    <Button asChild size="sm" variant="outline">
                        <a href="/relatorios/funil-de-pedidos/csv">Exportar CSV</a>
                    </Button>
                </div>

                <div className="flex flex-col gap-3">
                    {porStatus.map((linha) => (
                        <div key={linha.status} className="flex items-center gap-3">
                            <div className="w-32 shrink-0 text-sm">{linha.status_label}</div>
                            <div className="bg-muted h-6 flex-1 overflow-hidden rounded">
                                <div
                                    className={`h-full ${linha.status === 'cancelled' ? 'bg-destructive' : 'bg-primary'}`}
                                    style={{ width: `${(linha.pedidos / maior) * 100}%` }}
                                />
                            </div>
                            <div className="w-10 shrink-0 text-right text-sm font-medium">{linha.pedidos}</div>
                        </div>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
