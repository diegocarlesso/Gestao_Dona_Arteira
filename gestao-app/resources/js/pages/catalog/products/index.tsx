import { PreviaDoProduto } from '@/components/catalog/previa-do-produto';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';

type ProdutoDaLista = {
    public_id: string;
    sku: string;
    name: string;
    color: string | null;
    kind: string;
    status: string;
    categoria: string | null;
    weight_g: string | null;
    sell_on_woo: boolean;
    preco_varejo: string | null;
    preco_atacado: string | null;
    imagem: string | null;
    imagem_alt: string | null;
    pendencias: string[];
};

interface Props {
    produtos: Paginado<ProdutoDaLista>;
    filtros: { busca: string; pendencia: string };
    contagemPendencias: { sem_atacado: number; sem_varejo: number; sem_peso: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Produtos', href: '/produtos' }];

const emReais = (valor: string | null) =>
    valor === null ? null : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

export default function ProdutosIndex({ produtos, filtros, contagemPendencias }: Props) {
    const [busca, setBusca] = useState(filtros.busca ?? '');

    // A URL é a fonte do estado do filtro (pasta 06 §5): "voltar" e
    // compartilhar link funcionam sozinhos.
    const aplicar = (parametros: Record<string, string>) =>
        router.get('/produtos', { busca, pendencia: filtros.pendencia, ...parametros }, { preserveState: true, replace: true });

    const filtrosDePendencia = [
        { chave: 'sem_atacado', rotulo: 'Sem preço de atacado', total: contagemPendencias.sem_atacado },
        { chave: 'sem_varejo', rotulo: 'Sem preço de varejo', total: contagemPendencias.sem_varejo },
        { chave: 'sem_peso', rotulo: 'Sem peso', total: contagemPendencias.sem_peso },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Produtos" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Produtos</h1>
                        <p className="text-muted-foreground text-sm">Cada acabamento é um produto próprio, com código e saldo próprios.</p>
                    </div>

                    <Button asChild>
                        <Link href="/produtos/novo">
                            <Plus className="size-4" />
                            Novo produto
                        </Link>
                    </Button>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        aplicar({});
                    }}
                    className="flex max-w-md gap-2"
                >
                    <Input
                        type="search"
                        // O código é neutro e ninguém o decora — mas quem
                        // tem a etiqueta na mão digita exatamente ele.
                        placeholder="Buscar por nome, código ou cor"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        aria-label="Buscar produtos"
                    />
                    <Button type="submit" variant="secondary">
                        <Search className="size-4" />
                        <span className="sr-only">Buscar</span>
                    </Button>
                </form>

                {/* O que ainda falta preencher, sem varrer o catálogo ficha a ficha. */}
                <div className="flex flex-wrap gap-2">
                    {filtrosDePendencia.map(({ chave, rotulo, total }) => (
                        <Button
                            key={chave}
                            size="sm"
                            variant={filtros.pendencia === chave ? 'default' : 'outline'}
                            disabled={total === 0 && filtros.pendencia !== chave}
                            onClick={() => aplicar({ pendencia: filtros.pendencia === chave ? '' : chave })}
                        >
                            {rotulo}
                            <Badge variant="secondary" className="ml-1">
                                {total}
                            </Badge>
                        </Button>
                    ))}
                </div>

                {produtos.data.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Nenhum produto encontrado.</p>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Código</th>
                                    <th className="p-3 font-medium">Produto</th>
                                    <th className="p-3 font-medium">Categoria</th>
                                    <th className="p-3 text-right font-medium">Varejo</th>
                                    <th className="p-3 text-right font-medium">Atacado</th>
                                    <th className="p-3 font-medium">Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                {produtos.data.map((p) => (
                                    <tr key={p.public_id} className="border-t">
                                        <td className="p-3 font-mono text-xs whitespace-nowrap">{p.sku}</td>
                                        <td className="p-3">
                                            {/* O nome sempre ao lado do código: o SKU
                                                não diz nada sozinho, por decisão. */}
                                            <PreviaDoProduto imagem={p.imagem} alt={p.imagem_alt}>
                                                <Link href={`/produtos/${p.public_id}`} className="font-medium hover:underline">
                                                    {p.name}
                                                </Link>
                                            </PreviaDoProduto>
                                            {p.color && <span className="text-muted-foreground"> · {p.color}</span>}
                                            <div className="text-muted-foreground text-xs">{p.kind}</div>
                                        </td>
                                        <td className="text-muted-foreground p-3">{p.categoria ?? '—'}</td>
                                        <td className="p-3 text-right whitespace-nowrap">{emReais(p.preco_varejo) ?? '—'}</td>
                                        <td className="p-3 text-right whitespace-nowrap">{emReais(p.preco_atacado) ?? '—'}</td>
                                        <td className="p-3">
                                            <div className="flex flex-wrap gap-1">
                                                {p.status === 'archived' && <Badge variant="secondary">Arquivado</Badge>}
                                                {p.sell_on_woo && <Badge variant="outline">No site</Badge>}
                                                {p.pendencias.map((pendencia) => (
                                                    <Badge key={pendencia} variant="destructive">
                                                        {pendencia}
                                                    </Badge>
                                                ))}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {produtos.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {produtos.links.map((link, i) => (
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
