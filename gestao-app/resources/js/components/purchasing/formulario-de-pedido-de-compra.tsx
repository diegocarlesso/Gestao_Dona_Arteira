import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { useState } from 'react';

export type ItemDeCompra = {
    product: string | null;
    sku: string | null;
    name: string | null;
    unit: string | null;
    qty: string;
    unit_cost: string;
};

export type FornecedorEscolhido = {
    public_id: string;
    nome: string;
    payment_terms_days: number | null;
};

export type DadosDoPedidoDeCompra = {
    fornecedor: FornecedorEscolhido | null;
    freight: string;
    issued_at: string;
    expected_delivery_at: string;
    notes: string;
    itens: ItemDeCompra[];
};

type ProdutoBusca = { public_id: string; sku: string; name: string; color: string | null; unit: string };
type FornecedorBusca = { public_id: string; nome: string; document: string; payment_terms_days: number | null };

interface Props {
    dados: DadosDoPedidoDeCompra;
    metodo: 'post' | 'put';
    url: string;
    rotuloDoBotao: string;
    /** Fora do rascunho a tela vira leitura: o PC já saiu ou já virou dívida. */
    editavel?: boolean;
    erros?: Record<string, string>;
}

export const emReais = (valor: string | null) =>
    valor === null || valor === '' ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

async function buscar<T>(url: string, q: string): Promise<T[]> {
    if (q.trim().length < 2) return [];
    const r = await fetch(`${url}?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
    return r.ok ? r.json() : [];
}

const soma = (itens: ItemDeCompra[]) => itens.reduce((total, i) => total + Number(i.qty || 0) * Number(i.unit_cost || 0), 0);

export function FormularioDePedidoDeCompra({ dados, metodo, url, rotuloDoBotao, editavel = true, erros = {} }: Props) {
    const [fornecedor, setFornecedor] = useState(dados.fornecedor);
    const [freight, setFreight] = useState(dados.freight);
    const [issuedAt, setIssuedAt] = useState(dados.issued_at);
    const [expectedAt, setExpectedAt] = useState(dados.expected_delivery_at);
    const [notes, setNotes] = useState(dados.notes);
    const [itens, setItens] = useState<ItemDeCompra[]>(dados.itens);
    const [enviando, setEnviando] = useState(false);

    const [buscaFornecedor, setBuscaFornecedor] = useState('');
    const [fornecedores, setFornecedores] = useState<FornecedorBusca[]>([]);
    const [buscaProduto, setBuscaProduto] = useState('');
    const [produtos, setProdutos] = useState<ProdutoBusca[]>([]);

    const subtotal = soma(itens);
    const total = subtotal + Number(freight || 0);

    const adicionar = (p: ProdutoBusca) => {
        if (itens.some((i) => i.product === p.public_id)) return;
        setItens([...itens, { product: p.public_id, sku: p.sku, name: p.name, unit: p.unit, qty: '1', unit_cost: '0.00' }]);
        setBuscaProduto('');
        setProdutos([]);
    };

    const mudarItem = (indice: number, campo: 'qty' | 'unit_cost', valor: string) =>
        setItens(itens.map((item, i) => (i === indice ? { ...item, [campo]: valor } : item)));

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        setEnviando(true);

        const carga = {
            supplier: fornecedor?.public_id ?? '',
            freight: freight === '' ? '0' : freight,
            issued_at: issuedAt === '' ? null : issuedAt,
            expected_delivery_at: expectedAt === '' ? null : expectedAt,
            notes: notes === '' ? null : notes,
            itens: itens.map((i) => ({ product: i.product, qty: i.qty, unit_cost: i.unit_cost })),
        };

        const opcoes = { preserveScroll: true, onFinish: () => setEnviando(false) };

        if (metodo === 'post') {
            router.post(url, carga, opcoes);
        } else {
            router.put(url, carga, opcoes);
        }
    };

    return (
        <form onSubmit={enviar} className="flex flex-col gap-6">
            {erros.pedido && <p className="text-destructive text-sm">{erros.pedido}</p>}

            <section className="flex flex-col gap-4 rounded-md border p-4">
                <Label>Fornecedor</Label>

                {fornecedor ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="font-medium">{fornecedor.nome}</span>
                        {fornecedor.payment_terms_days !== null && (
                            <span className="text-muted-foreground text-xs">paga em {fornecedor.payment_terms_days} dias</span>
                        )}
                        {editavel && (
                            <Button type="button" variant="ghost" size="sm" onClick={() => setFornecedor(null)}>
                                trocar
                            </Button>
                        )}
                    </div>
                ) : (
                    <div className="relative max-w-md">
                        <Input
                            placeholder="Buscar fornecedor por nome ou CNPJ"
                            value={buscaFornecedor}
                            disabled={!editavel}
                            onChange={async (e) => {
                                setBuscaFornecedor(e.target.value);
                                setFornecedores(await buscar<FornecedorBusca>('/fornecedores/buscar', e.target.value));
                            }}
                        />
                        {fornecedores.length > 0 && (
                            <div className="bg-popover absolute z-10 mt-1 w-full rounded-md border shadow">
                                {fornecedores.map((f) => (
                                    <button
                                        key={f.public_id}
                                        type="button"
                                        className="hover:bg-accent flex w-full items-center justify-between px-3 py-2 text-left text-sm"
                                        onClick={() => {
                                            setFornecedor({ public_id: f.public_id, nome: f.nome, payment_terms_days: f.payment_terms_days });
                                            setBuscaFornecedor('');
                                            setFornecedores([]);
                                        }}
                                    >
                                        <span>{f.nome}</span>
                                        <span className="text-muted-foreground font-mono text-xs">{f.document}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                )}
                {erros.supplier && <p className="text-destructive text-sm">{erros.supplier}</p>}
            </section>

            <section className="flex flex-col gap-3 rounded-md border p-4">
                <div className="flex items-center justify-between">
                    <h2 className="font-medium">Itens</h2>
                    <span className="text-muted-foreground text-xs">O custo é o negociado com o fornecedor, não o preço de venda.</span>
                </div>

                {editavel && (
                    <div className="relative max-w-md">
                        <Input
                            placeholder="Buscar matéria-prima, embalagem, insumo ou revenda por nome ou código"
                            value={buscaProduto}
                            onChange={async (e) => {
                                setBuscaProduto(e.target.value);
                                setProdutos(await buscar<ProdutoBusca>('/compras/buscar-produtos', e.target.value));
                            }}
                        />
                        {produtos.length > 0 && (
                            <div className="bg-popover absolute z-10 mt-1 w-full rounded-md border shadow">
                                {produtos.map((p) => (
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
                                        <span className="text-muted-foreground text-xs">{p.unit}</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {itens.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Nenhum item. Um pedido de compra sem item não vira compra nem conta a pagar.</p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-muted-foreground text-left">
                                <tr>
                                    <th className="p-2 font-medium">Peça</th>
                                    <th className="p-2 text-right font-medium">Qtd</th>
                                    <th className="p-2 text-right font-medium">Custo unit.</th>
                                    <th className="p-2 text-right font-medium">Total</th>
                                    <th className="p-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {itens.map((item, i) => (
                                    <tr key={item.product ?? i} className="border-t">
                                        <td className="p-2">
                                            <span className="font-mono text-xs">{item.sku}</span> {item.name}
                                            {item.unit && <span className="text-muted-foreground text-xs"> · {item.unit}</span>}
                                        </td>
                                        <td className="p-2 text-right">
                                            {editavel ? (
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    step="0.001"
                                                    className="ml-auto h-8 w-24 text-right"
                                                    value={item.qty}
                                                    onChange={(e) => mudarItem(i, 'qty', e.target.value)}
                                                />
                                            ) : (
                                                Number(item.qty)
                                            )}
                                        </td>
                                        <td className="p-2 text-right">
                                            {editavel ? (
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    step="0.01"
                                                    className="ml-auto h-8 w-28 text-right"
                                                    value={item.unit_cost}
                                                    onChange={(e) => mudarItem(i, 'unit_cost', e.target.value)}
                                                />
                                            ) : (
                                                emReais(item.unit_cost)
                                            )}
                                        </td>
                                        <td className="p-2 text-right whitespace-nowrap">
                                            {emReais(String(Number(item.qty || 0) * Number(item.unit_cost || 0)))}
                                        </td>
                                        <td className="p-2 text-right">
                                            {editavel && (
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() => setItens(itens.filter((_, j) => j !== i))}
                                                >
                                                    <Trash2 className="size-4" />
                                                    <span className="sr-only">Remover item</span>
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {Object.keys(erros)
                    .filter((chave) => chave === 'itens' || chave.startsWith('itens.'))
                    .map((chave) => (
                        <p key={chave} className="text-destructive text-sm">
                            {erros[chave]}
                        </p>
                    ))}
            </section>

            <section className="flex flex-col gap-4 rounded-md border p-4">
                <div className="grid gap-4 sm:grid-cols-3">
                    <div>
                        <Label htmlFor="freight">Frete</Label>
                        <Input
                            id="freight"
                            type="number"
                            min="0"
                            step="0.01"
                            value={freight}
                            disabled={!editavel}
                            onChange={(e) => setFreight(e.target.value)}
                        />
                        <p className="text-muted-foreground mt-1 text-xs">Destacado — soma no total da compra.</p>
                    </div>
                    <div>
                        <Label htmlFor="issued_at">Data do pedido</Label>
                        <Input id="issued_at" type="date" value={issuedAt} disabled={!editavel} onChange={(e) => setIssuedAt(e.target.value)} />
                        <p className="text-muted-foreground mt-1 text-xs">Base do vencimento da conta a pagar.</p>
                    </div>
                    <div>
                        <Label htmlFor="expected_delivery_at">Entrega prevista</Label>
                        <Input
                            id="expected_delivery_at"
                            type="date"
                            value={expectedAt}
                            disabled={!editavel}
                            onChange={(e) => setExpectedAt(e.target.value)}
                        />
                    </div>
                </div>

                <div className="flex flex-wrap items-end justify-between gap-4 border-t pt-3">
                    <div className="max-w-md flex-1">
                        <Label htmlFor="notes">Observações</Label>
                        <Input
                            id="notes"
                            placeholder="ex.: combinado por telefone, entrega parcial"
                            value={notes}
                            disabled={!editavel}
                            onChange={(e) => setNotes(e.target.value)}
                        />
                    </div>
                    <div className="text-right">
                        <div className="text-muted-foreground text-xs">
                            Subtotal {emReais(String(subtotal))} + frete {emReais(String(Number(freight || 0)))}
                        </div>
                        <div className="text-lg font-semibold">{emReais(String(total))}</div>
                    </div>
                </div>
            </section>

            {editavel && (
                <div>
                    <Button type="submit" disabled={enviando || itens.length === 0 || fornecedor === null}>
                        {rotuloDoBotao}
                    </Button>
                </div>
            )}
        </form>
    );
}
