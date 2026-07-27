import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownRight, ArrowUpRight } from 'lucide-react';

type Movimento = {
    public_id: string;
    tipo: string;
    entrada: boolean;
    qty: string;
    saldo_apos: string | null;
    unit_cost: string | null;
    notes: string | null;
    occurred_at: string;
};

interface Props {
    produto: { public_id: string; sku: string; name: string; color: string | null; unit: string } | null;
    local: { public_id: string; name: string };
    locais: { public_id: string; name: string }[];
    saldo: { on_hand: string; reserved: string; disponivel: string; avg_cost: string };
    movimentos: Paginado<Movimento>;
}

const emQuantidade = (valor: string) => {
    const numero = Number(valor);
    return Number.isInteger(numero) ? String(numero) : numero.toLocaleString('pt-BR', { maximumFractionDigits: 3 });
};

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

const emDataHora = (iso: string) => new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });

export default function ExtratoDeEstoque({ produto, local, locais, saldo, movimentos }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Estoque', href: '/estoque' },
        { title: produto?.sku ?? 'Extrato', href: '#' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Extrato — ${produto?.name ?? 'produto'}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">
                        {produto?.name}
                        {produto?.color && <span className="text-muted-foreground font-normal"> · {produto.color}</span>}
                    </h1>
                    <p className="text-muted-foreground text-sm">
                        <span className="font-mono">{produto?.sku}</span> · {local.name}
                    </p>
                </div>

                {/* O saldo em destaque, porque a pergunta do extrato é sempre
                    "por que ele está assim?" — e a resposta começa por saber
                    quanto ele é. */}
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {[
                        { rotulo: 'Físico', valor: emQuantidade(saldo.on_hand) },
                        { rotulo: 'Reservado', valor: emQuantidade(saldo.reserved) },
                        { rotulo: 'Disponível', valor: emQuantidade(saldo.disponivel) },
                        { rotulo: 'Custo médio', valor: emReais(saldo.avg_cost) },
                    ].map(({ rotulo, valor }) => (
                        <div key={rotulo} className="rounded-md border p-3">
                            <div className="text-muted-foreground text-xs">{rotulo}</div>
                            <div className="text-lg font-semibold">{valor}</div>
                        </div>
                    ))}
                </div>

                {locais.length > 1 && (
                    <div className="flex flex-wrap gap-2">
                        {locais.map((l) => (
                            <Button
                                key={l.public_id}
                                size="sm"
                                variant={l.public_id === local.public_id ? 'default' : 'outline'}
                                onClick={() =>
                                    router.get(`/estoque/produtos/${produto?.public_id}/extrato`, { local: l.public_id }, { preserveState: true })
                                }
                            >
                                {l.name}
                            </Button>
                        ))}
                    </div>
                )}

                {movimentos.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum movimento neste local.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Enquanto não houver contagem, produção ou recebimento, esta peça não tem história de estoque para contar.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Quando</th>
                                    <th className="p-3 font-medium">O que foi</th>
                                    <th className="p-3 text-right font-medium">Movimento</th>
                                    <th className="p-3 text-right font-medium">Saldo depois</th>
                                    <th className="p-3 font-medium">Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                {movimentos.data.map((m) => (
                                    <tr key={m.public_id} className="border-t">
                                        <td className="text-muted-foreground p-3 whitespace-nowrap">{emDataHora(m.occurred_at)}</td>
                                        <td className="p-3">
                                            <span className="inline-flex items-center gap-1">
                                                {m.entrada ? (
                                                    <ArrowUpRight className="size-4 text-emerald-600" />
                                                ) : (
                                                    <ArrowDownRight className="size-4 text-rose-600" />
                                                )}
                                                {m.tipo}
                                            </span>
                                            {m.unit_cost && (
                                                <Badge variant="outline" className="ml-2">
                                                    {emReais(m.unit_cost)} / {produto?.unit}
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="p-3 text-right font-medium whitespace-nowrap">
                                            {m.entrada ? '+' : ''}
                                            {emQuantidade(m.qty)}
                                        </td>
                                        {/* A coluna que faz o extrato valer: sem ela,
                                            somar a pilha fica por conta de quem lê. */}
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {m.saldo_apos === null ? '—' : emQuantidade(m.saldo_apos)}
                                        </td>
                                        <td className="text-muted-foreground p-3">{m.notes ?? '—'}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {movimentos.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {movimentos.links.map((link, i) => (
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

                <div>
                    <Link href="/estoque" className="text-muted-foreground text-sm underline decoration-dotted">
                        ← Voltar à posição de estoque
                    </Link>
                </div>
            </div>
        </AppLayout>
    );
}
