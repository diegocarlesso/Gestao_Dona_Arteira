import { FormularioDeProduto, type OpcoesDoFormulario } from '@/components/catalog/formulario-de-produto';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Produtos', href: '/produtos' },
    { title: 'Novo', href: '/produtos/novo' },
];

/**
 * Cadastro de produto.
 *
 * Não há campo de código: o SKU é gerado pelo sistema e imutável depois
 * (BR-002). Ele aparece na tela de edição, depois de criado.
 */
export default function ProdutoCreate(opcoes: OpcoesDoFormulario) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo produto" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Novo produto</h1>
                    <p className="text-muted-foreground text-sm">
                        O código é gerado automaticamente ao salvar e não muda depois. Cada cor ou acabamento é um produto próprio.
                    </p>
                </div>

                <FormularioDeProduto opcoes={opcoes} acao="/produtos" metodo="post" />
            </div>
        </AppLayout>
    );
}
