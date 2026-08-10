import { FormularioDeFornecedor } from '@/components/purchasing/formulario-de-fornecedor';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Props {
    fornecedor: {
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
        notes: string | null;
    };
}

export default function FornecedorEdit({ fornecedor }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Fornecedores', href: '/fornecedores' },
        { title: fornecedor.nome, href: `/fornecedores/${fornecedor.public_id}` },
    ];

    const inativar = () => {
        if (!confirm(`Inativar ${fornecedor.nome}?\n\nEle sai da lista, e os pedidos de compra dele continuam intactos.`)) return;
        router.delete(`/fornecedores/${fornecedor.public_id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={fornecedor.nome} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{fornecedor.nome}</h1>
                        <p className="text-muted-foreground text-sm">
                            <span className="font-mono text-xs">{fornecedor.document_formatado}</span>
                            {' · '}
                            {fornecedor.pedidos_count} pedido(s) de compra
                        </p>
                    </div>

                    <Button variant="ghost" onClick={inativar}>
                        Inativar
                    </Button>
                </div>

                <FormularioDeFornecedor
                    metodo="put"
                    url={`/fornecedores/${fornecedor.public_id}`}
                    rotuloDoBotao="Salvar alterações"
                    dados={{
                        legal_name: fornecedor.legal_name,
                        trade_name: fornecedor.trade_name ?? '',
                        document: fornecedor.document_formatado,
                        email: fornecedor.email ?? '',
                        phone: fornecedor.phone ?? '',
                        payment_terms_days: fornecedor.payment_terms_days === null ? '' : String(fornecedor.payment_terms_days),
                        lead_time_days: fornecedor.lead_time_days === null ? '' : String(fornecedor.lead_time_days),
                        notes: fornecedor.notes ?? '',
                    }}
                />
            </div>
        </AppLayout>
    );
}
