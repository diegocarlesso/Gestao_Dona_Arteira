import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Search, Trash2, X } from 'lucide-react';
import { useState } from 'react';

type ItemPedido = {
    product: string | null;
    sku: string | null;
    name: string | null;
    qty: string;
    unit_price: string | null;
    line_total: string | null;
    note: string | null;
};

type ProdutoBusca = { public_id: string; sku: string; name: string; color: string | null; retail_price: string | null };
type ClienteBusca = { public_id: string; name: string; doc: string | null };

interface Props {
    pedido: {
        public_id: string;
        number: number;
        status: string;
        status_label: string;
        rascunho: boolean;
        cancelavel: boolean;
        canal: string;
        notes: string | null;
        subtotal: string;
        total: string;
        cliente: { public_id: string; name: string } | null;
        itens: ItemPedido[];
        historico: { de: string | null; para: string; motivo: string | null; quando: string | null }[];
    };
    podeConfirmar: boolean;
    podeCancelar: boolean;
}

const emReais = (valor: string | null) =>
    valor === null ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

async function buscar<T>(url: string, q: string): Promise<T[]> {
    if (q.trim().length < 2) return [];
    const r = await fetch(`${url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
    return r.ok ? r.json() : [];
}

export default function PedidoEdit({ pedido, podeConfirmar, podeCancelar }: Props) {
    const { errors } = usePage().props;
    const acao = useForm({});

    const [cliente, setCliente] = useState(pedido.cliente);
    const [itens, setItens] = useState<ItemPedido[]>(pedido.itens);
    const [notes, setNotes] = useState(pedido.notes ?? '');

    const [buscaProduto, setBuscaProduto] = useState('');
    const [resultados, setResultados] = useState<ProdutoBusca[]>([]);
    const [buscaCliente, setBuscaCliente] = useState('');
    const [clientes, setClientes] = useState<ClienteBusca[]>([]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Pedidos', href: '/pedidos' },
        { title: `#${pedido.number}`, href: `/pedidos/${pedido.public_id}` },
    ];

    const total = itens.reduce((soma, i) => soma + (i.unit_price ? Number(i.unit_price) * Number(i.qty) : 0), 0);

    const adicionar = (p: ProdutoBusca) => {
        if (itens.some((i) => i.product === p.public_id)) return;
        setItens([...itens, { product: p.public_id, sku: p.sku, name: p.name, qty: '1', unit_price: p.retail_price, line_total: null, note: null }]);
        setBuscaProduto('');
        setResultados([]);
    };

    const salvar = () => {
        router.put(
            `/pedidos/${pedido.public_id}`,
            {
                customer: cliente?.public_id ?? null,
                notes,
                itens: itens.map((i) => ({ product: i.product, qty: i.qty, note: i.note })),
            },
            { preserveScroll: true },
        );
    };

    const confirmar = () => {
        if (!confirm('Confirmar o pedido?\n\nIsso reserva o estoque de cada item. Falta de disponível impede a confirmação.')) return;
        acao.post(`/pedidos/${pedido.public_id}/confirmar`, { preserveScroll: true });
    };

    const cancelar = () => {
        const motivo = prompt('Motivo do cancelamento?');
        if (!motivo) return;
        router.post(`/pedidos/${pedido.public_id}/cancelar`, { motivo }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Pedido #${pedido.number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            Pedido #{pedido.number}
                            <Badge variant={pedido.status === 'confirmed' ? 'default' : pedido.status === 'cancelled' ? 'secondary' : 'outline'}>
                                {pedido.status_label}
                            </Badge>
                        </h1>
                        <p className="text-muted-foreground text-sm">Canal: {pedido.canal}</p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {pedido.rascunho && (
                            <Button variant="outline" onClick={salvar}>
                                Salvar
                            </Button>
                        )}
                        {pedido.rascunho && podeConfirmar && (
                            <Button onClick={confirmar} disabled={itens.length === 0}>
                                <Check className="size-4" />
                                Confirmar (reservar)
                            </Button>
                        )}
                        {pedido.cancelavel && podeCancelar && (
                            <Button variant="ghost" onClick={cancelar}>
                                <X className="size-4" />
                                Cancelar
                            </Button>
                        )}
                    </div>
                </div>

                {errors.confirmacao && <p className="text-destructive text-sm">{errors.confirmacao}</p>}
                {errors.cancelamento && <p className="text-destructive text-sm">{errors.cancelamento}</p>}
                {errors.pedido && <p className="text-destructive text-sm">{errors.pedido}</p>}

                {/* Cliente */}
                <section className="rounded-md border p-4">
                    <Label>Cliente</Label>
                    {cliente ? (
                        <div className="mt-1 flex items-center gap-2">
                            <span className="font-medium">{cliente.name}</span>
                            {pedido.rascunho && (
                                <Button variant="ghost" size="sm" onClick={() => setCliente(null)}>
                                    trocar
                                </Button>
                            )}
                        </div>
                    ) : (
                        <div className="mt-1">
                            <p className="text-muted-foreground mb-2 text-sm">Consumidor (venda de balcão sem cadastro).</p>
                            {pedido.rascunho && (
                                <div className="relative max-w-md">
                                    <Input
                                        placeholder="Buscar cliente por nome ou CPF/CNPJ"
                                        value={buscaCliente}
                                        onChange={async (e) => {
                                            setBuscaCliente(e.target.value);
                                            setClientes(await buscar<ClienteBusca>('/pedidos/buscar-clientes', e.target.value));
                                        }}
                                    />
                                    {clientes.length > 0 && (
                                        <div className="bg-popover absolute z-10 mt-1 w-full rounded-md border shadow">
                                            {clientes.map((c) => (
                                                <button
                                                    key={c.public_id}
                                                    type="button"
                                                    className="hover:bg-accent block w-full px-3 py-2 text-left text-sm"
                                                    onClick={() => {
                                                        setCliente({ public_id: c.public_id, name: c.name });
                                                        setBuscaCliente('');
                                                        setClientes([]);
                                                    }}
                                                >
                                                    {c.name} {c.doc && <span className="text-muted-foreground">· {c.doc}</span>}
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </section>

                {/* Itens */}
                <section className="flex flex-col gap-3 rounded-md border p-4">
                    <div className="flex items-center justify-between">
                        <h2 className="font-medium">Itens</h2>
                        {pedido.rascunho && <span className="text-muted-foreground text-xs">Preço congela ao adicionar (BR-302)</span>}
                    </div>

                    {pedido.rascunho && (
                        <div className="relative max-w-md">
                            <div className="flex gap-2">
                                <Input
                                    placeholder="Buscar peça por nome, código ou cor"
                                    value={buscaProduto}
                                    onChange={async (e) => {
                                        setBuscaProduto(e.target.value);
                                        setResultados(await buscar<ProdutoBusca>('/pedidos/buscar-produtos', e.target.value));
                                    }}
                                />
                                <Button type="button" variant="secondary" disabled>
                                    <Search className="size-4" />
                                </Button>
                            </div>
                            {resultados.length > 0 && (
                                <div className="bg-popover absolute z-10 mt-1 w-full rounded-md border shadow">
                                    {resultados.map((p) => (
                                        <button
                                            key={p.public_id}
                                            type="button"
                                            className="hover:bg-accent flex w-full items-center justify-between px-3 py-2 text-left text-sm"
                                            onClick={() => adicionar(p)}
                                        >
                                            <span>
                                                <span className="font-mono text-xs">{p.sku}</span> {p.name}
                                                {p.color && <span className="text-muted-foreground"> · {p.color}</span>}
                                            </span>
                                            <span className="text-muted-foreground">{p.retail_price ? emReais(p.retail_price) : 'sem preço'}</span>
                                        </button>
                                    ))}
                                </div>
                            )}
                        </div>
                    )}

                    {itens.length === 0 ? (
                        <p className="text-muted-foreground text-sm">Nenhum item.</p>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left">
                                    <tr>
                                        <th className="p-2 font-medium">Peça</th>
                                        <th className="p-2 text-right font-medium">Qtd</th>
                                        <th className="p-2 text-right font-medium">Preço</th>
                                        <th className="p-2 text-right font-medium">Total</th>
                                        <th className="p-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {itens.map((item, i) => (
                                        <tr key={item.product ?? i} className="border-t">
                                            <td className="p-2">
                                                <span className="font-mono text-xs">{item.sku}</span> {item.name}
                                            </td>
                                            <td className="p-2 text-right">
                                                {pedido.rascunho ? (
                                                    <Input
                                                        type="number"
                                                        min="1"
                                                        step="1"
                                                        className="ml-auto h-8 w-20 text-right"
                                                        value={item.qty}
                                                        onChange={(e) =>
                                                            setItens(itens.map((it, j) => (j === i ? { ...it, qty: e.target.value } : it)))
                                                        }
                                                    />
                                                ) : (
                                                    Number(item.qty)
                                                )}
                                            </td>
                                            <td className="p-2 text-right whitespace-nowrap">{emReais(item.unit_price)}</td>
                                            <td className="p-2 text-right whitespace-nowrap">
                                                {emReais(item.unit_price ? String(Number(item.unit_price) * Number(item.qty)) : item.line_total)}
                                            </td>
                                            <td className="p-2 text-right">
                                                {pedido.rascunho && (
                                                    <Button variant="ghost" size="sm" onClick={() => setItens(itens.filter((_, j) => j !== i))}>
                                                        <Trash2 className="size-4" />
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}

                    <div className="flex flex-wrap items-end justify-between gap-4 border-t pt-3">
                        <div className="max-w-md flex-1">
                            <Label htmlFor="notes">Observações</Label>
                            {pedido.rascunho ? (
                                <Input
                                    id="notes"
                                    placeholder="ex.: sem verniz, cor especial"
                                    value={notes}
                                    onChange={(e) => setNotes(e.target.value)}
                                />
                            ) : (
                                <p className="text-muted-foreground text-sm">{notes || '—'}</p>
                            )}
                        </div>
                        <div className="text-right">
                            <div className="text-muted-foreground text-xs">Total</div>
                            <div className="text-lg font-semibold">{emReais(pedido.rascunho ? String(total) : pedido.total)}</div>
                        </div>
                    </div>
                </section>

                {pedido.historico.length > 0 && (
                    <section className="rounded-md border p-4">
                        <h2 className="mb-2 font-medium">Histórico</h2>
                        <ul className="text-muted-foreground space-y-1 text-sm">
                            {pedido.historico.map((h, i) => (
                                <li key={i}>
                                    {h.quando && new Date(h.quando).toLocaleString('pt-BR')} — {h.de ? `${h.de} → ` : ''}
                                    {h.para}
                                    {h.motivo && ` (${h.motivo})`}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
