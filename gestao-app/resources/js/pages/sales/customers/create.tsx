import { FormularioDeCliente } from '@/components/sales/formulario-de-cliente';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

interface Props {
    tipos: { valor: string; rotulo: string; documento: string }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Clientes', href: '/clientes' },
    { title: 'Novo', href: '/clientes/novo' },
];

export default function ClienteCreate({ tipos }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo cliente" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold">Novo cliente</h1>

                <FormularioDeCliente
                    tipos={tipos}
                    metodo="post"
                    url="/clientes"
                    rotuloDoBotao="Cadastrar cliente"
                    dados={{
                        type: tipos[0]?.valor ?? 'individual',
                        name: '',
                        doc: '',
                        email: '',
                        phone: '',
                        state_registration: '',
                        is_wholesale: false,
                        notes: '',
                        lgpd_consent_at: '',
                        enderecos: [],
                    }}
                />
            </div>
        </AppLayout>
    );
}
