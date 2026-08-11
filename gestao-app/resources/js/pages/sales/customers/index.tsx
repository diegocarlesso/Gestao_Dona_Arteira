import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';

type Cliente = {
    public_id: string;
    type: string;
    type_label: string;
    name: string;
    doc: string | null;
    doc_formatado: string | null;
    email: string | null;
    phone: string | null;
    is_wholesale: boolean;
    enderecos_count: number;
    pendencias: string[];
};

interface Props {
    clientes: Paginado<Cliente>;
    filtros: { busca: string; pendencia: string };
    contagens: { sem_doc: number; sem_endereco: number; sem_ibge: number; atacado: number };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Clientes', href: '/clientes' }];

export default function ClientesIndex({ clientes, filtros, contagens }: Props) {
    const [busca, setBusca] = useState(filtros.busca ?? '');

    const aplicar = (parametros: Record<string, string>) =>
        router.get('/clientes', { busca, pendencia: filtros.pendencia, ...parametros }, { preserveState: true, replace: true });

    const filtrosRapidos = [
        { chave: 'sem_doc', rotulo: 'Sem CPF/CNPJ', total: contagens.sem_doc },
        { chave: 'sem_endereco', rotulo: 'Sem endereço', total: contagens.sem_endereco },
        // ADR-0026: quem sobrou da resolução automática do município e
        // precisa de escolha manual antes de virar nota fiscal.
        { chave: 'sem_ibge', rotulo: 'Sem código IBGE', total: contagens.sem_ibge },
        { chave: 'atacado', rotulo: 'Atacado', total: contagens.atacado },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clientes" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Clientes</h1>
                        <p className="text-muted-foreground text-sm">
                            Balcão pode comprar sem CPF; nota fiscal, não. A pendência aparece aqui para ser resolvida antes da venda.
                        </p>
                    </div>

                    <Button asChild>
                        <Link href="/clientes/novo">
                            <Plus className="size-4" />
                            Novo cliente
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
                        placeholder="Buscar por nome, e-mail ou CPF/CNPJ"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        aria-label="Buscar clientes"
                    />
                    <Button type="submit" variant="secondary">
                        <Search className="size-4" />
                        <span className="sr-only">Buscar</span>
                    </Button>
                </form>

                <div className="flex flex-wrap gap-2">
                    {filtrosRapidos.map(({ chave, rotulo, total }) => (
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

                {clientes.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum cliente por aqui.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Os 198 cadastros do site entram na migração dos clientes; até lá, cadastre quem comprar no balcão.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Nome</th>
                                    <th className="p-3 font-medium">CPF/CNPJ</th>
                                    <th className="p-3 font-medium">Contato</th>
                                    <th className="p-3 font-medium">Situação</th>
                                </tr>
                            </thead>
                            <tbody>
                                {clientes.data.map((c) => (
                                    <tr key={c.public_id} className="border-t">
                                        <td className="p-3">
                                            <Link href={`/clientes/${c.public_id}`} className="font-medium hover:underline">
                                                {c.name}
                                            </Link>
                                            <div className="text-muted-foreground text-xs">{c.type_label}</div>
                                        </td>
                                        <td className="p-3 font-mono text-xs whitespace-nowrap">{c.doc_formatado ?? '—'}</td>
                                        <td className="text-muted-foreground p-3">
                                            {c.email ?? '—'}
                                            {c.phone && <div className="text-xs">{c.phone}</div>}
                                        </td>
                                        <td className="p-3">
                                            <div className="flex flex-wrap gap-1">
                                                {c.is_wholesale && <Badge variant="outline">Atacado</Badge>}
                                                {c.pendencias.map((p) => (
                                                    <Badge key={p} variant="destructive">
                                                        {p}
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

                {clientes.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {clientes.links.map((link, i) => (
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
