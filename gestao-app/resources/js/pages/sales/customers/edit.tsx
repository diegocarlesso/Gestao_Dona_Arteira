import { FormularioDeCliente, type Endereco } from '@/components/sales/formulario-de-cliente';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

interface Props {
    cliente: {
        public_id: string;
        type: string;
        name: string;
        doc: string | null;
        doc_formatado: string | null;
        email: string | null;
        phone: string | null;
        state_registration: string | null;
        is_wholesale: boolean;
        notes: string | null;
        lgpd_consent_at: string | null;
        origem: string;
        pendencias: string[];
        enderecos: (Endereco & { public_id: string })[];
    };
    tipos: { valor: string; rotulo: string; documento: string }[];
}

export default function ClienteEdit({ cliente, tipos }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Clientes', href: '/clientes' },
        { title: cliente.name, href: `/clientes/${cliente.public_id}` },
    ];

    const inativar = () => {
        if (!confirm(`Inativar ${cliente.name}?\n\nEle sai da lista, e o histórico de compras continua intacto.`)) return;
        router.delete(`/clientes/${cliente.public_id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={cliente.name} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{cliente.name}</h1>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <span className="text-muted-foreground text-sm">{cliente.origem}</span>
                            {cliente.pendencias.map((p) => (
                                <Badge key={p} variant="destructive">
                                    {p}
                                </Badge>
                            ))}
                        </div>
                    </div>

                    <Button variant="ghost" onClick={inativar}>
                        Inativar
                    </Button>
                </div>

                <FormularioDeCliente
                    tipos={tipos}
                    metodo="put"
                    url={`/clientes/${cliente.public_id}`}
                    rotuloDoBotao="Salvar alterações"
                    dados={{
                        type: cliente.type,
                        name: cliente.name,
                        doc: cliente.doc_formatado ?? '',
                        email: cliente.email ?? '',
                        phone: cliente.phone ?? '',
                        state_registration: cliente.state_registration ?? '',
                        is_wholesale: cliente.is_wholesale,
                        notes: cliente.notes ?? '',
                        lgpd_consent_at: cliente.lgpd_consent_at ?? '',
                        enderecos: cliente.enderecos.map((e) => ({
                            public_id: e.public_id,
                            label: e.label ?? '',
                            zip: e.zip ?? '',
                            street: e.street ?? '',
                            number: e.number ?? '',
                            complement: e.complement ?? '',
                            district: e.district ?? '',
                            city: e.city ?? '',
                            state: e.state ?? '',
                            is_default_shipping: e.is_default_shipping,
                            is_default_billing: e.is_default_billing,
                        })),
                    }}
                />
            </div>
        </AppLayout>
    );
}
