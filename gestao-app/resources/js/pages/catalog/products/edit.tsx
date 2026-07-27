import { FormularioDeProduto, type OpcoesDoFormulario, type ValoresDoProduto } from '@/components/catalog/formulario-de-produto';
import { GaleriaDoProduto, type ImagemDoProduto } from '@/components/catalog/galeria-do-produto';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Archive, ArchiveRestore } from 'lucide-react';

type HistoricoDePreco = { lista: string; preco: string; desde: string };

type Produto = Partial<ValoresDoProduto> & {
    public_id: string;
    sku: string;
    name: string;
    status: string;
    pendencias: string[];
    historicoDePrecos: HistoricoDePreco[];
    imagens: ImagemDoProduto[];
};

interface Props extends OpcoesDoFormulario {
    produto: Produto;
}

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));
const emData = (iso: string) => new Date(iso).toLocaleDateString('pt-BR');

export default function ProdutoEdit({ produto, ...opcoes }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Produtos', href: '/produtos' },
        { title: produto.sku, href: `/produtos/${produto.public_id}` },
    ];

    const preco = useForm({ lista: 'wholesale', preco: '' });
    const arquivamento = useForm({});

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${produto.sku} — ${produto.name}`} />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{produto.name}</h1>
                        {/* O código nunca aparece sozinho: ele é neutro por
                            decisão e não diz nada sem o nome ao lado. */}
                        <p className="text-muted-foreground font-mono text-sm">{produto.sku}</p>
                    </div>

                    <Button
                        variant="outline"
                        disabled={arquivamento.processing}
                        onClick={() => arquivamento.post(`/produtos/${produto.public_id}/arquivar`)}
                    >
                        {produto.status === 'archived' ? (
                            <>
                                <ArchiveRestore className="size-4" />
                                Reativar
                            </>
                        ) : (
                            <>
                                <Archive className="size-4" />
                                Arquivar
                            </>
                        )}
                    </Button>
                </div>

                <GaleriaDoProduto imagens={produto.imagens} nome={produto.name} />

                <FormularioDeProduto
                    opcoes={opcoes}
                    acao={`/produtos/${produto.public_id}`}
                    metodo="put"
                    valores={produto}
                    // Preço na edição tem fluxo próprio: cada alteração é
                    // uma linha nova, para o histórico continuar íntegro.
                    ocultarPrecos
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Preços</CardTitle>
                        <CardDescription>
                            Cada alteração cria um registro novo — o preço anterior fica no histórico, porque um pedido antigo precisa continuar
                            explicável.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <form
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(e) => {
                                e.preventDefault();
                                preco.post(`/produtos/${produto.public_id}/preco`, { onSuccess: () => preco.reset('preco') });
                            }}
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="lista">Lista</Label>
                                <select
                                    id="lista"
                                    className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                    value={preco.data.lista}
                                    onChange={(e) => preco.setData('lista', e.target.value)}
                                >
                                    <option value="retail">Varejo</option>
                                    <option value="wholesale">Atacado</option>
                                </select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="preco">Preço</Label>
                                <Input
                                    id="preco"
                                    inputMode="decimal"
                                    placeholder="0.00"
                                    value={preco.data.preco}
                                    onChange={(e) => preco.setData('preco', e.target.value)}
                                    required
                                />
                            </div>

                            <Button type="submit" variant="secondary" disabled={preco.processing}>
                                Registrar preço
                            </Button>
                        </form>

                        {produto.historicoDePrecos.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Nenhum preço registrado ainda.</p>
                        ) : (
                            <ul className="grid gap-1 text-sm">
                                {produto.historicoDePrecos.map((h, i) => (
                                    <li key={i} className="flex justify-between border-b pb-1 last:border-0">
                                        <span>
                                            {h.lista} — <strong>{emReais(h.preco)}</strong>
                                        </span>
                                        <span className="text-muted-foreground">desde {emData(h.desde)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
