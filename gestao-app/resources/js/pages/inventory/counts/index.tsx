import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';

type Contagem = {
    public_id: string;
    local: string | null;
    status: string;
    status_label: string;
    total: number;
    contados: number;
    created_at: string | null;
    approved_at: string | null;
};

interface Props {
    contagens: Paginado<Contagem>;
    locais: { public_id: string; name: string }[];
    podeAjustar: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Estoque', href: '/estoque' },
    { title: 'Contagens', href: '/estoque/contagens' },
];

const emData = (iso: string | null) => (iso === null ? '—' : new Date(iso).toLocaleDateString('pt-BR'));

const corDoStatus = (status: string) =>
    status === 'approved' ? 'default' : status === 'cancelled' ? 'secondary' : status === 'awaiting_approval' ? 'outline' : 'secondary';

export default function ContagensIndex({ contagens, locais, podeAjustar }: Props) {
    const form = useForm({ local: locais[0]?.public_id ?? '', notes: '' });

    const abrir = (e: React.FormEvent) => {
        e.preventDefault();
        form.post('/estoque/contagens', { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Contagens de estoque" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Contagens de estoque</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">
                        O saldo do ERP nasce daqui. Quem conta registra o que viu na prateleira; outra pessoa confere a divergência antes de
                        virar movimento.
                    </p>
                </div>

                {podeAjustar && locais.length > 0 && (
                    <form onSubmit={abrir} className="flex flex-wrap items-end gap-2 rounded-md border p-4">
                        <div className="flex-1">
                            <label className="mb-1 block text-sm font-medium" htmlFor="notes">
                                Abrir contagem
                            </label>
                            <Input
                                id="notes"
                                placeholder="Observação (opcional) — ex.: inventário inicial"
                                value={form.data.notes}
                                onChange={(e) => form.setData('notes', e.target.value)}
                            />
                        </div>

                        {locais.length > 1 && (
                            <select
                                className="border-input h-9 rounded-md border px-3 text-sm"
                                value={form.data.local}
                                onChange={(e) => form.setData('local', e.target.value)}
                                aria-label="Local da contagem"
                            >
                                {locais.map((l) => (
                                    <option key={l.public_id} value={l.public_id}>
                                        {l.name}
                                    </option>
                                ))}
                            </select>
                        )}

                        <Button type="submit" disabled={form.processing}>
                            <ClipboardList className="size-4" />
                            Abrir contagem
                        </Button>
                    </form>
                )}

                {form.errors.local && <p className="text-destructive text-sm">{form.errors.local}</p>}

                {contagens.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhuma contagem ainda.</p>
                        <p className="text-muted-foreground mt-1 text-sm">
                            A primeira será o inventário inicial — é ela que dá ao ERP o saldo que nenhum sistema anterior tinha.
                        </p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Aberta em</th>
                                    <th className="p-3 font-medium">Local</th>
                                    <th className="p-3 font-medium">Situação</th>
                                    <th className="p-3 text-right font-medium">Progresso</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {contagens.data.map((c) => (
                                    <tr key={c.public_id} className="border-t">
                                        <td className="p-3 whitespace-nowrap">{emData(c.created_at)}</td>
                                        <td className="p-3">{c.local ?? '—'}</td>
                                        <td className="p-3">
                                            <Badge variant={corDoStatus(c.status)}>{c.status_label}</Badge>
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {c.contados} / {c.total}
                                        </td>
                                        <td className="p-3 text-right">
                                            <Link href={`/estoque/contagens/${c.public_id}`} className="text-xs underline decoration-dotted">
                                                abrir
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {contagens.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {contagens.links.map((link, i) => (
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
