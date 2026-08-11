import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Conta = {
    public_id: string;
    name: string;
    type: 'cash' | 'bank' | 'gateway';
    type_label: string;
    notes: string | null;
    saldo: string;
};

interface Props {
    contas: Conta[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Financeiro', href: '/financeiro' },
    { title: 'Contas', href: '/financeiro/contas' },
];

const TIPOS: { value: Conta['type']; label: string }[] = [
    { value: 'cash', label: 'Caixa' },
    { value: 'bank', label: 'Banco' },
    { value: 'gateway', label: 'Gateway de pagamento' },
];

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));

export default function ContasIndex({ contas }: Props) {
    const [contaEmEdicao, setContaEmEdicao] = useState<Conta | 'nova' | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Contas financeiras" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Contas financeiras</h1>
                        <p className="text-muted-foreground text-sm">O saldo é sempre a soma das baixas — não é editável diretamente.</p>
                    </div>
                    <Button size="sm" onClick={() => setContaEmEdicao('nova')}>
                        Nova conta
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Nome</th>
                                <th className="p-3 font-medium">Tipo</th>
                                <th className="p-3 font-medium">Saldo</th>
                                <th className="p-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {contas.map((c) => (
                                <tr key={c.public_id} className="border-t">
                                    <td className="p-3">{c.name}</td>
                                    <td className="text-muted-foreground p-3">{c.type_label}</td>
                                    <td className="p-3">{emReais(c.saldo)}</td>
                                    <td className="p-3 text-right">
                                        <Button size="sm" variant="ghost" onClick={() => setContaEmEdicao(c)}>
                                            Editar
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {contaEmEdicao && <FormularioDeConta conta={contaEmEdicao === 'nova' ? null : contaEmEdicao} aoFechar={() => setContaEmEdicao(null)} />}
        </AppLayout>
    );
}

function FormularioDeConta({ conta, aoFechar }: { conta: Conta | null; aoFechar: () => void }) {
    const form = useForm({
        name: conta?.name ?? '',
        type: conta?.type ?? ('cash' as Conta['type']),
        notes: conta?.notes ?? '',
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        const opcoes = { preserveScroll: true, onSuccess: aoFechar } as const;

        if (conta) {
            form.put(`/financeiro/contas/${conta.public_id}`, opcoes);
        } else {
            form.post('/financeiro/contas', opcoes);
        }
    };

    return (
        <Dialog open onOpenChange={(aberto) => !aberto && aoFechar()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{conta ? 'Editar conta' : 'Nova conta financeira'}</DialogTitle>
                </DialogHeader>

                <form onSubmit={enviar} className="flex flex-col gap-4">
                    <div>
                        <Label htmlFor="name">Nome</Label>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                        {form.errors.name && <p className="text-destructive mt-1 text-sm">{form.errors.name}</p>}
                    </div>

                    <div>
                        <Label htmlFor="type">Tipo</Label>
                        <Select value={form.data.type} onValueChange={(v) => form.setData('type', v as Conta['type'])}>
                            <SelectTrigger id="type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {TIPOS.map((t) => (
                                    <SelectItem key={t.value} value={t.value}>
                                        {t.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    <div>
                        <Label htmlFor="notes">Observações</Label>
                        <Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            {conta ? 'Salvar' : 'Cadastrar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
