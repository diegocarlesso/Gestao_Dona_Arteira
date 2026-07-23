import { StatusDaConta } from '@/components/identity/status-da-conta';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado, type UsuarioDaLista } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { KeyRound, Plus, Search, ShieldAlert, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Usuários', href: '/usuarios' }];

interface Props {
    usuarios: Paginado<UsuarioDaLista>;
    filtros: { busca: string };
}

export default function UsuariosIndex({ usuarios, filtros }: Props) {
    const [busca, setBusca] = useState(filtros.busca ?? '');

    function pesquisar(e: React.FormEvent) {
        e.preventDefault();
        // A URL é a fonte do estado do filtro (pasta 06 §5), então o
        // botão "voltar" e o compartilhamento de link funcionam sozinhos.
        router.get('/usuarios', { busca }, { preserveState: true, replace: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usuários" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">Usuários</h1>
                        <p className="text-muted-foreground text-sm">Contas são nominais e individuais — a auditoria registra quem fez o quê.</p>
                    </div>

                    <Button asChild>
                        <Link href="/usuarios/novo">
                            <Plus className="size-4" />
                            Nova conta
                        </Link>
                    </Button>
                </div>

                <form onSubmit={pesquisar} className="flex max-w-md gap-2">
                    <Input
                        type="search"
                        placeholder="Buscar por nome ou e-mail"
                        value={busca}
                        onChange={(e) => setBusca(e.target.value)}
                        aria-label="Buscar usuários"
                    />
                    <Button type="submit" variant="secondary">
                        <Search className="size-4" />
                        <span className="sr-only">Buscar</span>
                    </Button>
                </form>

                {usuarios.data.length === 0 ? (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border rounded-xl border p-10 text-center">
                        <p className="font-medium">Nenhuma conta encontrada.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {filtros.busca ? 'Tente outro termo de busca.' : 'Crie a primeira conta para dar acesso à equipe.'}
                        </p>
                    </div>
                ) : (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="px-4 py-3 font-medium">Pessoa</th>
                                    <th className="px-4 py-3 font-medium">Papéis</th>
                                    <th className="px-4 py-3 font-medium">Situação</th>
                                    <th className="px-4 py-3 font-medium">2FA</th>
                                    <th className="px-4 py-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {usuarios.data.map((u) => (
                                    <tr key={u.public_id} className="border-sidebar-border/70 dark:border-sidebar-border border-t">
                                        <td className="px-4 py-3">
                                            <div className="font-medium">{u.name}</div>
                                            <div className="text-muted-foreground">{u.email}</div>
                                        </td>

                                        <td className="px-4 py-3">
                                            {u.role_labels.length > 0 ? (
                                                <span>{u.role_labels.join(', ')}</span>
                                            ) : (
                                                <span className="text-muted-foreground">Sem papel — não acessa nada</span>
                                            )}
                                        </td>

                                        <td className="px-4 py-3">
                                            <StatusDaConta status={u.status} label={u.status_label} />
                                        </td>

                                        <td className="px-4 py-3">
                                            <Indicador2fa exigido={u.requires_two_factor} ativo={u.has_two_factor} />
                                        </td>

                                        <td className="px-4 py-3 text-right">
                                            <Button asChild variant="ghost" size="sm">
                                                <Link href={`/usuarios/${u.public_id}`}>Editar</Link>
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {usuarios.last_page > 1 && (
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {usuarios.from}–{usuarios.to} de {usuarios.total}
                        </span>
                        <div className="flex gap-1">
                            {usuarios.links.map((link, i) => (
                                <Button
                                    key={i}
                                    asChild={link.url !== null}
                                    disabled={link.url === null}
                                    variant={link.active ? 'default' : 'ghost'}
                                    size="sm"
                                >
                                    {link.url !== null ? (
                                        <Link href={link.url} dangerouslySetInnerHTML={{ __html: link.label }} />
                                    ) : (
                                        <span dangerouslySetInnerHTML={{ __html: link.label }} />
                                    )}
                                </Button>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function Indicador2fa({ exigido, ativo }: { exigido: boolean; ativo: boolean }) {
    if (ativo) {
        return (
            <span className="text-muted-foreground inline-flex items-center gap-1.5">
                <ShieldCheck className="size-4" aria-hidden />
                Ativo
            </span>
        );
    }

    // BR-804: admin e financeiro precisam de 2FA. Enquanto o fluxo TOTP
    // não existe, a tela ao menos torna a pendência visível em vez de
    // deixá-la só na regra escrita.
    if (exigido) {
        return (
            <span className="inline-flex items-center gap-1.5 text-amber-600 dark:text-amber-500">
                <ShieldAlert className="size-4" aria-hidden />
                Exigido (BR-804)
            </span>
        );
    }

    return (
        <span className="text-muted-foreground inline-flex items-center gap-1.5">
            <KeyRound className="size-4" aria-hidden />—
        </span>
    );
}
