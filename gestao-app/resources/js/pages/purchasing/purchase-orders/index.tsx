import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';

type PedidoDeCompra = {
    public_id: string;
    number: number;
    fornecedor: string;
    status: string;
    status_label: string;
    itens: number;
    total: string;
    expected_delivery_at: string | null;
    created_at: string | null;
};

interface Props {
    pedidos: Paginado<PedidoDeCompra>;
    filtros: { status: string };
    status: { valor: string; rotulo: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Compras', href: '/compras' }];

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));
const emData = (iso: string | null) => (iso === null ? '—' : new Date(iso).toLocaleDateString('pt-BR'));

const corDoStatus = (status: string) => (status === 'confirmed' ? 'default' : status === 'cancelled' ? 'secondary' : 'outline');

export default function ComprasIndex({ pedidos, filtros, status }: Props) {
    const filtrar = (valor: string) =>
        router.get('/compras', valor === filtros.status ? {} : { status: valor }, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Compras" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Pedidos de compra</h1>
                        <p className="text-muted-foreground text-sm">
                            Confirmar um pedido gera a conta a pagar. O recebimento físico e a quarentena de secagem entram com o Estoque.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href="/compras/novo">
                            <Plus className="size-4" />
                            Novo pedido de compra
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap gap-2">
                    {status.map((s) => (
                        <Button key={s.valor} size="sm" variant={filtros.status === s.valor ? 'default' : 'outline'} onClick={() => filtrar(s.valor)}>
                            {s.rotulo}
                        </Button>
                    ))}
                </div>

                {pedidos.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum pedido de compra ainda.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Compra combinada por telefone também vale — registre depois, em “Novo pedido de compra”.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Nº</th>
                                    <th className="p-3 font-medium">Fornecedor</th>
                                    <th className="p-3 font-medium">Situação</th>
                                    <th className="p-3 text-right font-medium">Itens</th>
                                    <th className="p-3 text-right font-medium">Total</th>
                                    <th className="p-3 font-medium">Entrega prevista</th>
                                    <th className="p-3 font-medium">Aberto</th>
                                </tr>
                            </thead>
                            <tbody>
                                {pedidos.data.map((p) => (
                                    <tr key={p.public_id} className="border-t">
                                        <td className="p-3 font-mono text-xs">
                                            <Link href={`/compras/${p.public_id}`} className="hover:underline">
                                                #{p.number}
                                            </Link>
                                        </td>
                                        <td className="p-3">{p.fornecedor}</td>
                                        <td className="p-3">
                                            <Badge variant={corDoStatus(p.status)}>{p.status_label}</Badge>
                                        </td>
                                        <td className="p-3 text-right">{p.itens}</td>
                                        <td className="p-3 text-right whitespace-nowrap">{emReais(p.total)}</td>
                                        <td className="text-muted-foreground p-3 whitespace-nowrap">{emData(p.expected_delivery_at)}</td>
                                        <td className="text-muted-foreground p-3 whitespace-nowrap">{emData(p.created_at)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {pedidos.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {pedidos.links.map((link, i) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url, { preserveState: true })}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
