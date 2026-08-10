import { FormularioDePedidoDeCompra, emReais, type ItemDeCompra } from '@/components/purchasing/formulario-de-pedido-de-compra';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Send, X } from 'lucide-react';

type Titulo = {
    public_id: string;
    supplier_name: string;
    amount: string;
    due_date: string | null;
    status: string;
    categoria: string;
};

interface Props {
    pedido: {
        public_id: string;
        number: number;
        status: string;
        status_label: string;
        rascunho: boolean;
        confirmavel: boolean;
        cancelavel: boolean;
        pode_enviar: boolean;
        subtotal: string;
        freight: string;
        total: string;
        issued_at: string | null;
        expected_delivery_at: string | null;
        notes: string | null;
        fornecedor: { public_id: string; nome: string; document: string; payment_terms_days: number | null };
        itens: ItemDeCompra[];
        historico: { de: string | null; para: string; motivo: string | null; quando: string | null }[];
    };
    titulos: Titulo[];
    podeConfirmar: boolean;
    podeCancelar: boolean;
}

const emData = (iso: string | null) => (iso === null ? '—' : new Date(`${iso}T00:00:00`).toLocaleDateString('pt-BR'));

export default function CompraEdit({ pedido, titulos, podeConfirmar, podeCancelar }: Props) {
    const { errors } = usePage().props;
    const acao = useForm({});

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Compras', href: '/compras' },
        { title: `PC #${pedido.number}`, href: `/compras/${pedido.public_id}` },
    ];

    const enviarAoFornecedor = () => {
        if (!confirm('Marcar como enviado ao fornecedor?\n\nO envio em si é por WhatsApp/e-mail, na sua mão — aqui fica só o registro.')) return;
        acao.post(`/compras/${pedido.public_id}/enviar`, { preserveScroll: true });
    };

    const confirmar = () => {
        if (!confirm('Confirmar o pedido de compra?\n\nIsso gera a conta a pagar e o PC deixa de ser editável.')) return;
        acao.post(`/compras/${pedido.public_id}/confirmar`, { preserveScroll: true });
    };

    const cancelar = () => {
        const motivo = prompt('Motivo do cancelamento?');
        if (!motivo) return;
        router.post(`/compras/${pedido.public_id}/cancelar`, { motivo }, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`PC #${pedido.number}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            Pedido de compra #{pedido.number}
                            <Badge variant={pedido.status === 'confirmed' ? 'default' : pedido.status === 'cancelled' ? 'secondary' : 'outline'}>
                                {pedido.status_label}
                            </Badge>
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            {pedido.fornecedor.nome} · <span className="font-mono text-xs">{pedido.fornecedor.document}</span>
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {pedido.pode_enviar && (
                            <Button variant="outline" onClick={enviarAoFornecedor}>
                                <Send className="size-4" />
                                Marcar como enviado
                            </Button>
                        )}
                        {pedido.confirmavel && podeConfirmar && (
                            <Button onClick={confirmar}>
                                <Check className="size-4" />
                                Confirmar (gerar conta a pagar)
                            </Button>
                        )}
                        {pedido.cancelavel && podeCancelar && (
                            <Button variant="ghost" onClick={cancelar}>
                                <X className="size-4" />
                                Cancelar
                            </Button>
                        )}
                    </div>
                </div>

                {errors.transicao && <p className="text-destructive text-sm">{errors.transicao}</p>}

                <FormularioDePedidoDeCompra
                    metodo="put"
                    url={`/compras/${pedido.public_id}`}
                    rotuloDoBotao="Salvar pedido de compra"
                    editavel={pedido.rascunho}
                    erros={errors}
                    dados={{
                        fornecedor: {
                            public_id: pedido.fornecedor.public_id,
                            nome: pedido.fornecedor.nome,
                            payment_terms_days: pedido.fornecedor.payment_terms_days,
                        },
                        freight: pedido.freight,
                        issued_at: pedido.issued_at ?? '',
                        expected_delivery_at: pedido.expected_delivery_at ?? '',
                        notes: pedido.notes ?? '',
                        itens: pedido.itens,
                    }}
                />

                {titulos.length > 0 && (
                    <section className="rounded-md border p-4">
                        <h2 className="mb-1 font-medium">Conta a pagar</h2>
                        <p className="text-muted-foreground mb-3 text-sm">
                            Gerada pela confirmação deste pedido. A baixa (pagamento) é do módulo Financeiro e ainda não existe nesta fase.
                        </p>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead className="text-muted-foreground text-left">
                                    <tr>
                                        <th className="p-2 font-medium">Fornecedor</th>
                                        <th className="p-2 font-medium">Categoria</th>
                                        <th className="p-2 font-medium">Vencimento</th>
                                        <th className="p-2 font-medium">Situação</th>
                                        <th className="p-2 text-right font-medium">Valor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {titulos.map((t) => (
                                        <tr key={t.public_id} className="border-t">
                                            <td className="p-2">{t.supplier_name}</td>
                                            <td className="text-muted-foreground p-2">{t.categoria}</td>
                                            <td className="p-2 whitespace-nowrap">
                                                {t.due_date === null ? <span className="text-muted-foreground">a combinar</span> : emData(t.due_date)}
                                            </td>
                                            <td className="p-2">
                                                <Badge variant="outline">{t.status === 'open' ? 'Em aberto' : t.status}</Badge>
                                            </td>
                                            <td className="p-2 text-right font-medium whitespace-nowrap">{emReais(t.amount)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {pedido.historico.length > 0 && (
                    <section className="rounded-md border p-4">
                        <h2 className="mb-2 font-medium">Histórico</h2>
                        <ul className="text-muted-foreground space-y-1 text-sm">
                            {pedido.historico.map((h, i) => (
                                <li key={i}>
                                    {h.quando && new Date(h.quando).toLocaleString('pt-BR')} — {h.de ? `${h.de} → ` : ''}
                                    {h.para}
                                    {h.motivo && ` (${h.motivo})`}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </AppLayout>
    );
}
