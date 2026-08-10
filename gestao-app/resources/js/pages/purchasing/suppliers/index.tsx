import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';

type Fornecedor = {
    public_id: string;
    legal_name: string;
    trade_name: string | null;
    nome: string;
    document: string;
    document_formatado: string;
    email: string | null;
    phone: string | null;
    payment_terms_days: number | null;
    lead_time_days: number | null;
    pedidos_count: number;
};

interface Props {
    fornecedores: Paginado<Fornecedor>;
    filtros: { busca: string };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Fornecedores', href: '/fornecedores' }];

const emDias = (dias: number | null) => (dias === null ? '—' : `${dias} d`);

export default function FornecedoresIndex({ fornecedores, filtros }: Props) {
    const [busca, setBusca] = useState(filtros.busca ?? '');

    const aplicar = () => router.get('/fornecedores', { busca }, { preserveState: true, replace: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Fornecedores" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Fornecedores</h1>
                        <p className="text-muted-foreground text-sm">
                            De quem vem a peça crua, a tinta e a embalagem. O prazo de pagamento daqui decide o vencimento da conta a pagar.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href="/fornecedores/novo">
                            <Plus className="size-4" />
                            Novo fornecedor
                        </Link>
                    </Button>
                </div>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        aplicar();
                    }}
                    className="flex max-w-md gap-2"
                >
                    <Input
                        type="search"
                        placeholder="Buscar por nome, CNPJ ou e-mail"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        aria-label="Buscar fornecedores"
                    />
                    <Button type="submit" variant="secondary">
                        <Search className="size-4" />
                        <span className="sr-only">Buscar</span>
                    </Button>
                </form>

                {fornecedores.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum fornecedor cadastrado.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Comece pelo de quem chega a peça crua — é dele que sai a maior parte da conta.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Fornecedor</th>
                                    <th className="p-3 font-medium">CNPJ/CPF</th>
                                    <th className="p-3 font-medium">Contato</th>
                                    <th className="p-3 text-right font-medium">Pagamento</th>
                                    <th className="p-3 text-right font-medium">Entrega</th>
                                    <th className="p-3 text-right font-medium">PCs</th>
                                </tr>
                            </thead>
                            <tbody>
                                {fornecedores.data.map((f) => (
                                    <tr key={f.public_id} className="border-t">
                                        <td className="p-3">
                                            <Link href={`/fornecedores/${f.public_id}`} className="font-medium hover:underline">
                                                {f.nome}
                                            </Link>
                                            {f.trade_name && <div className="text-muted-foreground text-xs">{f.legal_name}</div>}
                                        </td>
                                        <td className="p-3 font-mono text-xs whitespace-nowrap">{f.document_formatado}</td>
                                        <td className="text-muted-foreground p-3">
                                            {f.email ?? '—'}
                                            {f.phone && <div className="text-xs">{f.phone}</div>}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">{emDias(f.payment_terms_days)}</td>
                                        <td className="p-3 text-right whitespace-nowrap">{emDias(f.lead_time_days)}</td>
                                        <td className="p-3 text-right">{f.pedidos_count}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {fornecedores.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {fornecedores.links.map((link, i) => (
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
