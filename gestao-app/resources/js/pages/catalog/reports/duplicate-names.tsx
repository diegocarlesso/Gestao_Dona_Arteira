import { PreviaDoProduto } from '@/components/catalog/previa-do-produto';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Archive, Download } from 'lucide-react';
import { useState } from 'react';

type ProdutoDoGrupo = {
    public_id: string;
    sku: string;
    name: string;
    color: string | null;
    categoria: string | null;
    preco_varejo: string | null;
    imagem: string | null;
    imagem_alt: string | null;
    pendencias: string[];
};

type Grupo = {
    nome: string;
    produtos: ProdutoDoGrupo[];
};

interface Props {
    grupos: Grupo[];
    totais: { grupos: number; produtos: number };
    podeArquivar: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Produtos', href: '/produtos' },
    { title: 'Nomes repetidos', href: '/relatorios/produtos-repetidos' },
];

const emReais = (valor: string | null) =>
    valor === null ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

export default function NomesRepetidos({ grupos, totais, podeArquivar }: Props) {
    // Guarda qual linha está em voo para não permitir dois cliques na
    // mesma — arquivar é ação de uma via só (BR-008: nada é excluído,
    // mas desarquivar exige outra tela).
    const [arquivando, setArquivando] = useState<string | null>(null);

    const arquivar = (produto: ProdutoDoGrupo) => {
        if (!confirm(`Arquivar "${produto.name}" (${produto.sku})?\n\nEle sai do catálogo ativo e do site. O histórico é preservado.`)) {
            return;
        }

        setArquivando(produto.public_id);

        router.post(
            `/produtos/${produto.public_id}/arquivar`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setArquivando(null),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Produtos com nome repetido" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Produtos com nome repetido</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">
                            A mesma peça cadastrada duas vezes. Escolha qual fica e arquive a outra — o grupo some da lista assim que sobra
                            um só.
                        </p>
                    </div>

                    {totais.produtos > 0 && (
                        <Button variant="outline" asChild>
                            <a href="/relatorios/produtos-repetidos/csv">
                                <Download className="size-4" />
                                Baixar CSV
                            </a>
                        </Button>
                    )}
                </div>

                {totais.produtos === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum nome repetido.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Cada peça ativa do catálogo tem nome próprio — é o estado em que a contagem física pode começar.
                        </p>
                    </div>
                ) : (
                    <>
                        <p className="text-sm">
                            <strong>{totais.produtos}</strong> produtos em <strong>{totais.grupos}</strong>{' '}
                            {totais.grupos === 1 ? 'grupo' : 'grupos'}.
                        </p>

                        <div className="flex flex-col gap-6">
                            {grupos.map((grupo) => (
                                <section key={grupo.nome} className="overflow-hidden rounded-md border">
                                    <header className="bg-muted/50 flex flex-wrap items-center gap-2 border-b p-3">
                                        <h2 className="font-medium">{grupo.nome}</h2>
                                        <Badge variant="secondary">{grupo.produtos.length} cadastros</Badge>
                                    </header>

                                    <div className="overflow-x-auto">
                                        <table className="w-full text-sm">
                                            <thead className="text-muted-foreground text-left">
                                                <tr>
                                                    <th className="p-3 font-medium">Código</th>
                                                    <th className="p-3 font-medium">Foto</th>
                                                    <th className="p-3 font-medium">Cor</th>
                                                    <th className="p-3 font-medium">Categoria</th>
                                                    <th className="p-3 text-right font-medium">Varejo</th>
                                                    <th className="p-3 font-medium">Cadastro</th>
                                                    <th className="p-3" />
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {grupo.produtos.map((p) => (
                                                    <tr key={p.public_id} className="border-t">
                                                        <td className="p-3 font-mono text-xs whitespace-nowrap">
                                                            <Link href={`/produtos/${p.public_id}`} className="hover:underline">
                                                                {p.sku}
                                                            </Link>
                                                        </td>
                                                        <td className="p-3">
                                                            {/* Com nomes iguais, a foto é o que
                                                                decide qual dos dois fica. */}
                                                            <PreviaDoProduto imagem={p.imagem} alt={p.imagem_alt}>
                                                                <span className="text-muted-foreground text-xs underline decoration-dotted">
                                                                    {p.imagem ? 'ver' : 'sem foto'}
                                                                </span>
                                                            </PreviaDoProduto>
                                                        </td>
                                                        <td className="p-3">{p.color ?? '—'}</td>
                                                        <td className="text-muted-foreground p-3">{p.categoria ?? '—'}</td>
                                                        <td className="p-3 text-right whitespace-nowrap">{emReais(p.preco_varejo)}</td>
                                                        <td className="p-3">
                                                            <div className="flex flex-wrap gap-1">
                                                                {p.pendencias.length === 0 ? (
                                                                    <span className="text-muted-foreground text-xs">completo</span>
                                                                ) : (
                                                                    p.pendencias.map((pendencia) => (
                                                                        <Badge key={pendencia} variant="destructive">
                                                                            {pendencia}
                                                                        </Badge>
                                                                    ))
                                                                )}
                                                            </div>
                                                        </td>
                                                        <td className="p-3 text-right">
                                                            {podeArquivar && (
                                                                <Button
                                                                    size="sm"
                                                                    variant="outline"
                                                                    disabled={arquivando !== null}
                                                                    onClick={() => arquivar(p)}
                                                                >
                                                                    <Archive className="size-4" />
                                                                    {arquivando === p.public_id ? 'Arquivando…' : 'Arquivar'}
                                                                </Button>
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            ))}
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
