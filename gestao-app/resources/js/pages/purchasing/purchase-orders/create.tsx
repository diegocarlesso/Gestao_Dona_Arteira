import { FormularioDePedidoDeCompra } from '@/components/purchasing/formulario-de-pedido-de-compra';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Compras', href: '/compras' },
    { title: 'Novo', href: '/compras/novo' },
];

export default function CompraCreate() {
    const { errors } = usePage().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo pedido de compra" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Novo pedido de compra</h1>
                    <p className="text-muted-foreground text-sm">
                        Ele nasce em rascunho. Marcar como enviado só registra que o pedido foi ao fornecedor; confirmar é o que gera a conta a pagar.
                    </p>
                </div>

                <FormularioDePedidoDeCompra
                    metodo="post"
                    url="/compras"
                    rotuloDoBotao="Abrir pedido de compra"
                    erros={errors}
                    dados={{
                        fornecedor: null,
                        freight: '0.00',
                        issued_at: '',
                        expected_delivery_at: '',
                        notes: '',
                        itens: [],
                    }}
                />
            </div>
        </AppLayout>
    );
}
