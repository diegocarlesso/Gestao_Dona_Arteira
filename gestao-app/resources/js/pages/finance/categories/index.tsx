import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Categoria = {
    public_id: string;
    name: string;
    type: 'payable' | 'receivable';
    type_label: string;
    parent_public_id: string | null;
};

interface Props {
    categorias: Categoria[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Financeiro', href: '/financeiro' },
    { title: 'Categorias', href: '/financeiro/categorias' },
];

export default function CategoriasIndex({ categorias }: Props) {
    const [categoriaEmEdicao, setCategoriaEmEdicao] = useState<Categoria | 'nova' | null>(null);

    const nomeDoPai = (publicId: string | null) => categorias.find((c) => c.public_id === publicId)?.name ?? '—';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Categorias financeiras" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Categorias financeiras</h1>
                        <p className="text-muted-foreground text-sm">
                            Árvore inicial já cadastrada — renomear ou reorganizar não afeta os títulos já classificados.
                        </p>
                    </div>
                    <Button size="sm" onClick={() => setCategoriaEmEdicao('nova')}>
                        Nova categoria
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Nome</th>
                                <th className="p-3 font-medium">Tipo</th>
                                <th className="p-3 font-medium">Categoria-mãe</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {categorias.map((c) => (
                                <tr key={c.public_id} className="border-t">
                                    <td className="p-3">{c.name}</td>
                                    <td className="p-3">
                                        <Badge variant={c.type === 'receivable' ? 'default' : 'secondary'}>{c.type_label}</Badge>
                                    </td>
                                    <td className="text-muted-foreground p-3">{nomeDoPai(c.parent_public_id)}</td>
                                    <td className="p-3 text-right">
                                        <Button size="sm" variant="ghost" onClick={() => setCategoriaEmEdicao(c)}>
                                            Editar
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {categoriaEmEdicao && (
                <FormularioDeCategoria
                    categoria={categoriaEmEdicao === 'nova' ? null : categoriaEmEdicao}
                    categorias={categorias}
                    aoFechar={() => setCategoriaEmEdicao(null)}
                />
            )}
        </AppLayout>
    );
}

function FormularioDeCategoria({ categoria, categorias, aoFechar }: { categoria: Categoria | null; categorias: Categoria[]; aoFechar: () => void }) {
    const form = useForm({
        name: categoria?.name ?? '',
        type: categoria?.type ?? ('payable' as Categoria['type']),
        parent_public_id: categoria?.parent_public_id ?? '',
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        const opcoes = { preserveScroll: true, onSuccess: aoFechar } as const;

        if (categoria) {
            form.put(`/financeiro/categorias/${categoria.public_id}`, opcoes);
        } else {
            form.post('/financeiro/categorias', opcoes);
        }
    };

    const possiveisPais = categorias.filter((c) => c.type === form.data.type && c.public_id !== categoria?.public_id);

    return (
        <Dialog open onOpenChange={(aberto) => !aberto && aoFechar()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{categoria ? 'Editar categoria' : 'Nova categoria'}</DialogTitle>
                </DialogHeader>

                <form onSubmit={enviar} className="flex flex-col gap-4">
                    <div>
                        <Label htmlFor="name">Nome</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        {form.errors.name && <p className="text-destructive mt-1 text-sm">{form.errors.name}</p>}
                    </div>

                    <div>
                        <Label htmlFor="type">Tipo</Label>
                        <Select value={form.data.type} onValueChange={(v) => form.setData('type', v as Categoria['type'])}>
                            <SelectTrigger id="type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="payable">A pagar</SelectItem>
                                <SelectItem value="receivable">A receber</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <Label htmlFor="parent_public_id">Categoria-mãe (opcional)</Label>
                        <Select
                            value={form.data.parent_public_id || 'nenhuma'}
                            onValueChange={(v) => form.setData('parent_public_id', v === 'nenhuma' ? '' : v)}
                        >
                            <SelectTrigger id="parent_public_id">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="nenhuma">Nenhuma (raiz)</SelectItem>
                                {possiveisPais.map((p) => (
                                    <SelectItem key={p.public_id} value={p.public_id}>
                                        {p.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            {categoria ? 'Salvar' : 'Cadastrar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
