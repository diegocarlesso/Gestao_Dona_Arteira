import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';

type Situacao = 'pending' | 'rejected' | 'authorized' | 'cancelled';

type Nota = {
    public_id: string;
    identificacao: string;
    status: Situacao;
    status_label: string;
    pedido_numero: number | null;
    pedido_public_id: string | null;
    canal: string | null;
    cliente: string | null;
    total: string;
    pendencia: string | null;
    created_at: string;
    reprocessavel: boolean;
};

interface Props {
    resumo: { pending: number; rejected: number; authorized: number; cancelled: number };
    notas: Paginado<Nota>;
    filtro: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Fiscal', href: '/fiscal' }];

const META: Record<Situacao, { badge: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    pending: { badge: 'secondary' },
    rejected: { badge: 'destructive' },
    authorized: { badge: 'default' },
    cancelled: { badge: 'outline' },
};

const CARDS: Situacao[] = ['pending', 'rejected', 'authorized', 'cancelled'];

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));
const emDataHora = (iso: string) => new Date(iso).toLocaleString('pt-BR');

export default function PainelFiscal({ resumo, notas, filtro }: Props) {
    const { errors } = usePage().props;

    const filtrar = (status: string) => router.get('/fiscal', status === filtro ? {} : { status }, { preserveState: true, replace: true });

    const reprocessar = (publicId: string) => {
        if (!confirm('Reenfileirar esta nota para emissão?\n\nSe a pendência que a travou já foi corrigida, ela tenta de novo.')) return;
        router.post(`/fiscal/notas/${publicId}/reprocessar`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Fiscal</h1>
                    <p className="text-muted-foreground text-sm">
                        Notas fiscais dos pedidos do site. Pendência ou rejeição aparecem aqui — corrija a causa (cadastro, configuração) e reprocesse.
                    </p>
                </div>

                {errors.reprocessar && <p className="text-destructive text-sm">{errors.reprocessar}</p>}

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {CARDS.map((situacao) => (
                        <button
                            key={situacao}
                            type="button"
                            onClick={() => filtrar(situacao)}
                            className={`hover:bg-accent rounded-md border p-4 text-left transition ${filtro === situacao ? 'border-primary ring-primary/30 ring-1' : ''}`}
                        >
                            <div className="text-2xl font-semibold">{resumo[situacao]}</div>
                            <div className="text-muted-foreground text-xs capitalize">
                                {situacao === 'pending' && 'Pendentes'}
                                {situacao === 'rejected' && 'Rejeitadas'}
                                {situacao === 'authorized' && 'Autorizadas'}
                                {situacao === 'cancelled' && 'Canceladas'}
                            </div>
                        </button>
                    ))}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant={filtro === 'todos' ? 'default' : 'outline'} onClick={() => filtrar('todos')}>
                        Todas
                    </Button>
                    {CARDS.map((situacao) => (
                        <Button key={situacao} size="sm" variant={filtro === situacao ? 'default' : 'outline'} onClick={() => filtrar(situacao)}>
                            {situacao === 'pending' && 'Pendentes'}
                            {situacao === 'rejected' && 'Rejeitadas'}
                            {situacao === 'authorized' && 'Autorizadas'}
                            {situacao === 'cancelled' && 'Canceladas'}
                        </Button>
                    ))}
                </div>

                {notas.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhuma nota por aqui.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Quando um pedido do site for confirmado, a emissão entra na fila e a nota aparece nesta lista.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Situação</th>
                                    <th className="p-3 font-medium">Nota</th>
                                    <th className="p-3 font-medium">Pedido</th>
                                    <th className="p-3 font-medium">Cliente</th>
                                    <th className="p-3 font-medium">Total</th>
                                    <th className="p-3 font-medium">Pendência</th>
                                    <th className="p-3 font-medium">Criada</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {notas.data.map((n) => (
                                    <tr key={n.public_id} className="border-t align-top">
                                        <td className="p-3">
                                            <Badge variant={META[n.status].badge}>{n.status_label}</Badge>
                                        </td>
                                        <td className="p-3 font-mono text-xs whitespace-nowrap">{n.identificacao}</td>
                                        <td className="p-3 whitespace-nowrap">
                                            {n.pedido_public_id ? (
                                                <Link href={`/pedidos/${n.pedido_public_id}`} className="text-primary hover:underline">
                                                    #{n.pedido_numero} · {n.canal}
                                                </Link>
                                            ) : (
                                                '—'
                                            )}
                                        </td>
                                        <td className="p-3">{n.cliente ?? '—'}</td>
                                        <td className="p-3 whitespace-nowrap">{emReais(n.total)}</td>
                                        <td className="text-muted-foreground max-w-md p-3">{n.pendencia ?? '—'}</td>
                                        <td className="text-muted-foreground p-3 whitespace-nowrap">{emDataHora(n.created_at)}</td>
                                        <td className="p-3 text-right">
                                            {n.reprocessavel && (
                                                <Button size="sm" variant="ghost" onClick={() => reprocessar(n.public_id)}>
                                                    <RefreshCw className="size-4" />
                                                    Reprocessar
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {notas.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {notas.links.map((link, i) => (
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
