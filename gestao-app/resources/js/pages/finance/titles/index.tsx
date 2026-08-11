import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

type Baixa = {
    public_id: string;
    valor: string;
    data: string;
    conta: string;
    estorno: boolean;
    notas: string | null;
};

type Titulo = {
    public_id: string;
    nome: string;
    categoria: string;
    valor: string;
    saldo_aberto: string;
    vencimento: string | null;
    dias_vencido: number | null;
    faixa_aging: '1-15' | '16-30' | '30+' | null;
    status: 'open' | 'partially_settled' | 'settled';
    baixas: Baixa[];
};

type Conta = { id: number; public_id: string; name: string };

interface Props {
    tipo: 'receivable' | 'payable';
    somenteAbertos: boolean;
    titulos: Paginado<Titulo>;
    contas: Conta[];
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Financeiro', href: '/financeiro' }];

const STATUS_LABEL: Record<Titulo['status'], string> = {
    open: 'Aberto',
    partially_settled: 'Parcial',
    settled: 'Baixado',
};

const STATUS_BADGE: Record<Titulo['status'], 'default' | 'secondary' | 'outline'> = {
    open: 'secondary',
    partially_settled: 'outline',
    settled: 'default',
};

const AGING_LABEL: Record<NonNullable<Titulo['faixa_aging']>, string> = {
    '1-15': 'Vencido 1–15 dias',
    '16-30': 'Vencido 16–30 dias',
    '30+': 'Vencido 30+ dias',
};

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));
const emData = (data: string) => new Date(`${data}T00:00:00`).toLocaleDateString('pt-BR');

export default function TitulosIndex({ tipo, somenteAbertos, titulos, contas }: Props) {
    const { errors } = usePage().props;
    const [tituloParaBaixar, setTituloParaBaixar] = useState<Titulo | null>(null);
    const [baixaParaEstornar, setBaixaParaEstornar] = useState<Baixa | null>(null);

    const trocarAba = (novoTipo: 'receivable' | 'payable') =>
        router.get('/financeiro', { tipo: novoTipo, abertos: somenteAbertos ? 1 : 0 }, { preserveState: true, replace: true });

    const trocarFiltro = (abertos: boolean) => router.get('/financeiro', { tipo, abertos: abertos ? 1 : 0 }, { preserveState: true, replace: true });

    const nomeDaColuna = tipo === 'payable' ? 'Fornecedor' : 'Cliente';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Financeiro" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Contas a {tipo === 'payable' ? 'pagar' : 'receber'}</h1>
                    <p className="text-muted-foreground text-sm">Títulos gerados automaticamente pelas vendas e compras — a baixa é manual.</p>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex gap-2">
                        <Button size="sm" variant={tipo === 'receivable' ? 'default' : 'outline'} onClick={() => trocarAba('receivable')}>
                            A receber
                        </Button>
                        <Button size="sm" variant={tipo === 'payable' ? 'default' : 'outline'} onClick={() => trocarAba('payable')}>
                            A pagar
                        </Button>
                    </div>
                    <Button size="sm" variant="outline" onClick={() => trocarFiltro(!somenteAbertos)}>
                        {somenteAbertos ? 'Mostrar todos' : 'Só abertos'}
                    </Button>
                </div>

                {titulos.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum título por aqui.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">{nomeDaColuna}</th>
                                    <th className="p-3 font-medium">Categoria</th>
                                    <th className="p-3 font-medium">Valor</th>
                                    <th className="p-3 font-medium">Saldo aberto</th>
                                    <th className="p-3 font-medium">Vencimento</th>
                                    <th className="p-3 font-medium">Status</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {titulos.data.map((t) => (
                                    <tr key={t.public_id} className="border-t align-top">
                                        <td className="p-3">{t.nome}</td>
                                        <td className="text-muted-foreground p-3">{t.categoria}</td>
                                        <td className="p-3 whitespace-nowrap">{emReais(t.valor)}</td>
                                        <td className="p-3 whitespace-nowrap">{emReais(t.saldo_aberto)}</td>
                                        <td className="p-3 whitespace-nowrap">
                                            {t.vencimento ? emData(t.vencimento) : '—'}
                                            {t.faixa_aging && <div className="text-destructive text-xs">{AGING_LABEL[t.faixa_aging]}</div>}
                                        </td>
                                        <td className="p-3">
                                            <Badge variant={STATUS_BADGE[t.status]}>{STATUS_LABEL[t.status]}</Badge>
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {t.status !== 'settled' && (
                                                <Button size="sm" variant="ghost" onClick={() => setTituloParaBaixar(t)}>
                                                    Dar baixa
                                                </Button>
                                            )}
                                            {t.baixas.length > 0 && (
                                                <details className="mt-1 text-left">
                                                    <summary className="text-muted-foreground cursor-pointer text-xs">
                                                        {t.baixas.length} baixa(s)
                                                    </summary>
                                                    <ul className="mt-1 flex flex-col gap-1">
                                                        {t.baixas.map((b) => (
                                                            <li key={b.public_id} className="text-xs">
                                                                {b.estorno ? 'Estorno: ' : ''}
                                                                {emReais(b.valor)} em {emData(b.data)} ({b.conta})
                                                                {!b.estorno && (
                                                                    <Button
                                                                        size="sm"
                                                                        variant="link"
                                                                        className="h-auto p-0 pl-1 text-xs"
                                                                        onClick={() => setBaixaParaEstornar(b)}
                                                                    >
                                                                        estornar
                                                                    </Button>
                                                                )}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </details>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {titulos.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {titulos.links.map((link, i) => (
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

            {tituloParaBaixar && (
                <FormularioDeBaixa
                    tipo={tipo}
                    titulo={tituloParaBaixar}
                    contas={contas}
                    erro={typeof errors.baixa === 'string' ? errors.baixa : undefined}
                    aoFechar={() => setTituloParaBaixar(null)}
                />
            )}

            {baixaParaEstornar && (
                <FormularioDeEstorno
                    baixa={baixaParaEstornar}
                    erro={typeof errors.estorno === 'string' ? errors.estorno : undefined}
                    aoFechar={() => setBaixaParaEstornar(null)}
                />
            )}
        </AppLayout>
    );
}

function FormularioDeBaixa({
    tipo,
    titulo,
    contas,
    erro,
    aoFechar,
}: {
    tipo: 'receivable' | 'payable';
    titulo: Titulo;
    contas: Conta[];
    erro?: string;
    aoFechar: () => void;
}) {
    const form = useForm({
        account_id: '',
        amount: titulo.saldo_aberto,
        settled_at: new Date().toISOString().slice(0, 10),
        notes: '',
    });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/financeiro/titulos/${tipo}/${titulo.public_id}/baixa`, {
            preserveScroll: true,
            onSuccess: aoFechar,
        });
    };

    return (
        <Dialog open onOpenChange={(aberto) => !aberto && aoFechar()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Dar baixa — {titulo.nome}</DialogTitle>
                    <DialogDescription>Saldo aberto: {emReais(titulo.saldo_aberto)}. Baixa parcial é permitida.</DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="flex flex-col gap-4">
                    {erro && <p className="text-destructive text-sm">{erro}</p>}

                    <div>
                        <Label htmlFor="account_id">Conta</Label>
                        <Select value={form.data.account_id} onValueChange={(v) => form.setData('account_id', v)}>
                            <SelectTrigger id="account_id">
                                <SelectValue placeholder="Selecione a conta" />
                            </SelectTrigger>
                            <SelectContent>
                                {contas.map((c) => (
                                    <SelectItem key={c.id} value={String(c.id)}>
                                        {c.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {form.errors.account_id && <p className="text-destructive mt-1 text-sm">{form.errors.account_id}</p>}
                    </div>

                    <div>
                        <Label htmlFor="amount">Valor</Label>
                        <Input id="amount" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required />
                        {form.errors.amount && <p className="text-destructive mt-1 text-sm">{form.errors.amount}</p>}
                    </div>

                    <div>
                        <Label htmlFor="settled_at">Data</Label>
                        <Input
                            id="settled_at"
                            type="date"
                            value={form.data.settled_at}
                            onChange={(e) => form.setData('settled_at', e.target.value)}
                            required
                        />
                    </div>

                    <div>
                        <Label htmlFor="notes">Observações</Label>
                        <Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing || !form.data.account_id}>
                            Confirmar baixa
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function FormularioDeEstorno({ baixa, erro, aoFechar }: { baixa: Baixa; erro?: string; aoFechar: () => void }) {
    const form = useForm({ motivo: '' });

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(`/financeiro/baixas/${baixa.public_id}/estornar`, {
            preserveScroll: true,
            onSuccess: aoFechar,
        });
    };

    return (
        <Dialog open onOpenChange={(aberto) => !aberto && aoFechar()}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Estornar baixa de {emReais(baixa.valor)}</DialogTitle>
                    <DialogDescription>
                        A baixa original continua na trilha; o estorno é uma linha nova que devolve o título ao aberto.
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={enviar} className="flex flex-col gap-4">
                    {erro && <p className="text-destructive text-sm">{erro}</p>}

                    <div>
                        <Label htmlFor="motivo">Motivo</Label>
                        <Input id="motivo" value={form.data.motivo} onChange={(e) => form.setData('motivo', e.target.value)} required />
                        {form.errors.motivo && <p className="text-destructive mt-1 text-sm">{form.errors.motivo}</p>}
                    </div>

                    <DialogFooter>
                        <Button type="submit" variant="destructive" disabled={form.processing}>
                            Confirmar estorno
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
