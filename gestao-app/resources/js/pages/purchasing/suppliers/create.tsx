import { FormularioDeFornecedor } from '@/components/purchasing/formulario-de-fornecedor';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Fornecedores', href: '/fornecedores' },
    { title: 'Novo', href: '/fornecedores/novo' },
];

export default function FornecedorCreate() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo fornecedor" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <h1 className="text-xl font-semibold">Novo fornecedor</h1>

                <FormularioDeFornecedor
                    metodo="post"
                    url="/fornecedores"
                    rotuloDoBotao="Cadastrar fornecedor"
                    dados={{
                        legal_name: '',
                        trade_name: '',
                        document: '',
                        email: '',
                        phone: '',
                        payment_terms_days: '',
                        lead_time_days: '',
                        notes: '',
                    }}
                />
            </div>
        </AppLayout>
    );
}
