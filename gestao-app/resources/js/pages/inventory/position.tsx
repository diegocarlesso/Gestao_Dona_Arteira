import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList, Search } from 'lucide-react';
import { useState } from 'react';

type Produto = {
    public_id: string;
    sku: string;
    name: string;
    color: string | null;
    unit: string;
};

type Saldo = {
    produto: Produto | null;
    local: string | null;
    on_hand: string;
    reserved: string;
    disponivel: string;
    avg_cost: string;
    abaixo_do_minimo: boolean;
};

interface Props {
    saldos: Paginado<Saldo>;
    locais: { public_id: string; name: string }[];
    filtros: { busca: string; local: string | null; com_saldo: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Estoque', href: '/estoque' }];

// Sem casas decimais quando a quantidade é inteira: peça de gesso se
// conta por unidade, e "12,000" na tela é ruído para quem procura 12.
const emQuantidade = (valor: string) => {
    const numero = Number(valor);
    return Number.isInteger(numero) ? String(numero) : numero.toLocaleString('pt-BR', { maximumFractionDigits: 3 });
};

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

export default function PosicaoDeEstoque({ saldos, locais, filtros }: Props) {
    const [busca, setBusca] = useState(filtros.busca ?? '');

    const aplicar = (parametros: Record<string, string>) =>
        router.get(
            '/estoque',
            { busca, local: filtros.local ?? '', com_saldo: filtros.com_saldo ? '1' : '', ...parametros },
            { preserveState: true, replace: true },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Estoque" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Posição de estoque</h1>
                        <p className="text-muted-foreground text-sm">
                            O que existe agora, por peça e por local. Clique no saldo para ver por que ele está assim.
                        </p>
                    </div>

                    {/* O saldo só entra por contagem — a tela precisa dizer
                        por onde, especialmente enquanto está vazia. */}
                    <Button variant="outline" asChild>
                        <Link href="/estoque/contagens">
                            <ClipboardList className="size-4" />
                            Contagens
                        </Link>
                    </Button>
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            aplicar({});
                        }}
                        className="flex max-w-md flex-1 gap-2"
                    >
                        <Input
                            type="search"
                            placeholder="Buscar por nome, código ou cor"
                            value={busca}
                            onChange={(e) => setBusca(e.target.value)}
                            aria-label="Buscar produtos no estoque"
                        />
                        <Button type="submit" variant="secondary">
                            <Search className="size-4" />
                            <span className="sr-only">Buscar</span>
                        </Button>
                    </form>

                    {locais.length > 1 &&
                        locais.map((l) => (
                            <Button
                                key={l.public_id}
                                size="sm"
                                variant={filtros.local === l.public_id ? 'default' : 'outline'}
                                onClick={() => aplicar({ local: l.public_id })}
                            >
                                {l.name}
                            </Button>
                        ))}

                    <Button
                        size="sm"
                        variant={filtros.com_saldo ? 'default' : 'outline'}
                        onClick={() => aplicar({ com_saldo: filtros.com_saldo ? '' : '1' })}
                    >
                        Só com saldo
                    </Button>
                </div>

                {saldos.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum saldo por aqui.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            O estoque começa vazio de propósito: o saldo inicial nasce da contagem física, não de um número importado do
                            site.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Código</th>
                                    <th className="p-3 font-medium">Peça</th>
                                    <th className="p-3 text-right font-medium">Físico</th>
                                    <th className="p-3 text-right font-medium">Reservado</th>
                                    <th className="p-3 text-right font-medium">Disponível</th>
                                    <th className="p-3 text-right font-medium">Custo médio</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {saldos.data.map((s, i) => (
                                    <tr key={s.produto?.public_id ?? i} className="border-t">
                                        <td className="p-3 font-mono text-xs whitespace-nowrap">{s.produto?.sku ?? '—'}</td>
                                        <td className="p-3">
                                            {s.produto?.name ?? 'Produto removido'}
                                            {s.produto?.color && <span className="text-muted-foreground"> · {s.produto.color}</span>}
                                            {s.abaixo_do_minimo && (
                                                <Badge variant="destructive" className="ml-2">
                                                    abaixo do mínimo
                                                </Badge>
                                            )}
                                        </td>
                                        <td className="p-3 text-right font-medium whitespace-nowrap">{emQuantidade(s.on_hand)}</td>
                                        <td className="text-muted-foreground p-3 text-right whitespace-nowrap">
                                            {emQuantidade(s.reserved)}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">{emQuantidade(s.disponivel)}</td>
                                        <td className="text-muted-foreground p-3 text-right whitespace-nowrap">{emReais(s.avg_cost)}</td>
                                        <td className="p-3 text-right">
                                            {s.produto && (
                                                <Link
                                                    href={`/estoque/produtos/${s.produto.public_id}/extrato`}
                                                    className="text-xs underline decoration-dotted"
                                                >
                                                    extrato
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {saldos.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {saldos.links.map((link, i) => (
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
