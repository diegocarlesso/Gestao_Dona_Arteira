import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { 
    TrendingUp, 
    ShoppingCart, 
    PackageSearch, 
    Package, 
    Truck, 
    Activity, 
    AlertCircle, 
    Clock, 
    CheckCircle2,
    DollarSign,
    Box,
    RefreshCw
} from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel Geral',
        href: '/dashboard',
    },
];

interface DashboardProps {
    metrics: {
        vendas: {
            dia: { total: number; pedidos: number; ticket_medio: number };
            mes: { total: number; pedidos: number; ticket_medio: number; por_canal: Record<string, number> };
        };
        funil: Record<string, number>;
        fulfillment: { a_separar: number; a_embalar: number; a_expedir: number };
        sync: {
            pendencias: number;
            rejeitados: number;
            ultima_reconciliacao: { status: string; data: string; falhas: number } | null;
        };
    };
    auth: { user: { role: string; name: string } };
}

export default function Dashboard({ metrics, auth }: DashboardProps) {
    const formatCurrency = (value: number) => {
        return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
    };

    // Para admin mostra tudo. Para sales/fulfillment, foca no fulfillment no topo.
    const isAdmin = auth.user.role === 'admin';
    const isOperacional = ['sales', 'fulfillment'].includes(auth.user.role);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Painel" />
            <div className="flex h-full flex-1 flex-col gap-6 p-6 bg-neutral-50/50 dark:bg-neutral-900/50">
                
                {/* Header Section */}
                <div className="flex items-center justify-between mb-2">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight text-neutral-900 dark:text-neutral-100">
                            Olá, {auth.user.name.split(' ')[0]}
                        </h1>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            Aqui está o resumo da operação de hoje.
                        </p>
                    </div>
                </div>

                {/* Fulfillment Queue (Highlighted for Operacional) */}
                <div className="grid gap-6 md:grid-cols-3">
                    <Link href="/sales/orders?status=confirmed" className="group relative overflow-hidden rounded-2xl bg-white p-6 shadow-sm ring-1 ring-neutral-200 transition-all hover:shadow-md hover:ring-blue-500 dark:bg-neutral-900 dark:ring-neutral-800 dark:hover:ring-blue-500">
                        <div className="absolute right-0 top-0 -mr-4 -mt-4 h-24 w-24 rounded-full bg-blue-50/50 transition-transform group-hover:scale-150 dark:bg-blue-500/10" />
                        <div className="relative flex items-center justify-between">
                            <div>
                                <p className="text-sm font-medium text-neutral-500 dark:text-neutral-400">A Separar</p>
                                <p className="mt-2 text-4xl font-bold text-neutral-900 dark:text-white">{metrics.fulfillment.a_separar}</p>
                            </div>
                            <div className="rounded-full bg-blue-100 p-3 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400">
                                <PackageSearch className="h-6 w-6" />
                            </div>
                        </div>
                    </Link>

                    <Link href="/sales/orders?status=separando" className="group relative overflow-hidden rounded-2xl bg-white p-6 shadow-sm ring-1 ring-neutral-200 transition-all hover:shadow-md hover:ring-amber-500 dark:bg-neutral-900 dark:ring-neutral-800 dark:hover:ring-amber-500">
                        <div className="absolute right-0 top-0 -mr-4 -mt-4 h-24 w-24 rounded-full bg-amber-50/50 transition-transform group-hover:scale-150 dark:bg-amber-500/10" />
                        <div className="relative flex items-center justify-between">
                            <div>
                                <p className="text-sm font-medium text-neutral-500 dark:text-neutral-400">A Embalar</p>
                                <p className="mt-2 text-4xl font-bold text-neutral-900 dark:text-white">{metrics.fulfillment.a_embalar}</p>
                            </div>
                            <div className="rounded-full bg-amber-100 p-3 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400">
                                <Package className="h-6 w-6" />
                            </div>
                        </div>
                    </Link>

                    <Link href="/sales/orders?status=embalado" className="group relative overflow-hidden rounded-2xl bg-white p-6 shadow-sm ring-1 ring-neutral-200 transition-all hover:shadow-md hover:ring-green-500 dark:bg-neutral-900 dark:ring-neutral-800 dark:hover:ring-green-500">
                        <div className="absolute right-0 top-0 -mr-4 -mt-4 h-24 w-24 rounded-full bg-green-50/50 transition-transform group-hover:scale-150 dark:bg-green-500/10" />
                        <div className="relative flex items-center justify-between">
                            <div>
                                <p className="text-sm font-medium text-neutral-500 dark:text-neutral-400">A Expedir</p>
                                <p className="mt-2 text-4xl font-bold text-neutral-900 dark:text-white">{metrics.fulfillment.a_expedir}</p>
                            </div>
                            <div className="rounded-full bg-green-100 p-3 text-green-600 dark:bg-green-500/20 dark:text-green-400">
                                <Truck className="h-6 w-6" />
                            </div>
                        </div>
                    </Link>
                </div>

                {/* Vendas & Funil (Admin sees full details) */}
                {(!isOperacional || isAdmin) && (
                    <div className="grid gap-6 lg:grid-cols-7">
                        {/* Vendas */}
                        <div className="lg:col-span-4 rounded-2xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                            <h2 className="mb-6 flex items-center text-lg font-semibold text-neutral-900 dark:text-white">
                                <TrendingUp className="mr-2 h-5 w-5 text-indigo-500" /> Vendas do Período
                            </h2>
                            <div className="grid grid-cols-2 gap-6">
                                <div className="space-y-4 rounded-xl bg-neutral-50 p-5 dark:bg-neutral-800/50">
                                    <p className="text-sm font-medium text-neutral-500 dark:text-neutral-400">Hoje</p>
                                    <div>
                                        <p className="text-3xl font-bold text-neutral-900 dark:text-white">{formatCurrency(metrics.vendas.dia.total)}</p>
                                        <div className="mt-2 flex items-center text-sm text-neutral-500 dark:text-neutral-400">
                                            <ShoppingCart className="mr-1.5 h-4 w-4" />
                                            {metrics.vendas.dia.pedidos} pedidos (TM: {formatCurrency(metrics.vendas.dia.ticket_medio)})
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-4 rounded-xl bg-neutral-50 p-5 dark:bg-neutral-800/50">
                                    <p className="text-sm font-medium text-neutral-500 dark:text-neutral-400">Este Mês</p>
                                    <div>
                                        <p className="text-3xl font-bold text-neutral-900 dark:text-white">{formatCurrency(metrics.vendas.mes.total)}</p>
                                        <div className="mt-2 flex items-center text-sm text-neutral-500 dark:text-neutral-400">
                                            <ShoppingCart className="mr-1.5 h-4 w-4" />
                                            {metrics.vendas.mes.pedidos} pedidos (TM: {formatCurrency(metrics.vendas.mes.ticket_medio)})
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {/* Saúde da Sync */}
                        <div className="lg:col-span-3 rounded-2xl border border-neutral-200 bg-white p-6 dark:border-neutral-800 dark:bg-neutral-900">
                            <div className="flex items-center justify-between mb-6">
                                <h2 className="flex items-center text-lg font-semibold text-neutral-900 dark:text-white">
                                    <Activity className="mr-2 h-5 w-5 text-teal-500" /> Saúde das Integrações
                                </h2>
                                <Link href="/integrations" className="text-sm font-medium text-teal-600 hover:text-teal-700 dark:text-teal-400 dark:hover:text-teal-300">
                                    Ver todas →
                                </Link>
                            </div>
                            <div className="space-y-5">
                                <div className="flex items-center justify-between rounded-lg border border-neutral-100 p-4 dark:border-neutral-800">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-full bg-rose-100 p-2 text-rose-600 dark:bg-rose-500/20 dark:text-rose-400">
                                            <AlertCircle className="h-5 w-5" />
                                        </div>
                                        <div>
                                            <p className="font-medium text-neutral-900 dark:text-white">Pendências</p>
                                            <p className="text-sm text-neutral-500 dark:text-neutral-400">Erros ou filas travadas</p>
                                        </div>
                                    </div>
                                    <span className="text-2xl font-bold text-rose-600 dark:text-rose-400">{metrics.sync.pendencias}</span>
                                </div>

                                <div className="flex items-center justify-between rounded-lg border border-neutral-100 p-4 dark:border-neutral-800">
                                    <div className="flex items-center gap-3">
                                        <div className="rounded-full bg-neutral-100 p-2 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                            <Box className="h-5 w-5" />
                                        </div>
                                        <div>
                                            <p className="font-medium text-neutral-900 dark:text-white">Rejeitados</p>
                                            <p className="text-sm text-neutral-500 dark:text-neutral-400">Assinatura inválida (24h)</p>
                                        </div>
                                    </div>
                                    <span className="text-2xl font-bold text-neutral-900 dark:text-white">{metrics.sync.rejeitados}</span>
                                </div>

                                {metrics.sync.ultima_reconciliacao && (
                                    <div className="flex items-center gap-2 text-sm text-neutral-500 dark:text-neutral-400 mt-2">
                                        <RefreshCw className="h-4 w-4" />
                                        <span>Última reconciliação em {metrics.sync.ultima_reconciliacao.data}</span>
                                        {metrics.sync.ultima_reconciliacao.falhas > 0 && (
                                            <span className="text-rose-500 ml-auto">({metrics.sync.ultima_reconciliacao.falhas} falhas)</span>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
