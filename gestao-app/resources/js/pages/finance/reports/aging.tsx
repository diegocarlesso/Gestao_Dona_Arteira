import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';

type Faixa = 'a_vencer' | '1-15' | '16-30' | '30+';

type Titulo = {
    public_id: string;
    nome: string;
    categoria: string;
    vencimento: string | null;
    faixa: Faixa;
    saldo_aberto: string;
};

type Totais = Record<Faixa | 'total', string>;

interface Props {
    tipo: 'receivable' | 'payable';
    dataDe: string | null;
    dataAte: string | null;
    titulos: Titulo[];
    totais: Totais;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Financeiro', href: '/financeiro' },
    { title: 'Vencimentos', href: '/financeiro/relatorios/vencimentos' },
];

const FAIXA_LABEL: Record<Faixa, string> = {
    a_vencer: 'A vencer',
    '1-15': 'Vencido 1–15 dias',
    '16-30': 'Vencido 16–30 dias',
    '30+': 'Vencido 30+ dias',
};

const FAIXA_BADGE: Record<Faixa, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    a_vencer: 'secondary',
    '1-15': 'outline',
    '16-30': 'outline',
    '30+': 'destructive',
};

const emReais = (valor: string) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor));
const emData = (data: string) => new Date(`${data}T00:00:00`).toLocaleDateString('pt-BR');

export default function RelatorioDeVencimentos({ tipo, dataDe, dataAte, titulos, totais }: Props) {
    const filtrar = (novosFiltros: Partial<{ tipo: string; data_de: string; data_ate: string }>) => {
        router.get(
            '/financeiro/relatorios/vencimentos',
            {
                tipo: novosFiltros.tipo ?? tipo,
                data_de: novosFiltros.data_de ?? dataDe ?? '',
                data_ate: novosFiltros.data_ate ?? dataAte ?? '',
            },
            { preserveState: true, replace: true },
        );
    };

    const urlCsv = `/financeiro/relatorios/vencimentos/csv?tipo=${tipo}&data_de=${dataDe ?? ''}&data_ate=${dataAte ?? ''}`;
    const urlPdf = `/financeiro/relatorios/vencimentos/pdf?tipo=${tipo}&data_de=${dataDe ?? ''}&data_ate=${dataAte ?? ''}`;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Vencimentos" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Vencimentos — quem nos deve, a quem devemos</h1>
                        <p className="text-muted-foreground text-sm">
                            Lista completa dos títulos em aberto, somados por prazo. Para dar baixa num título, use a tela de Contas a
                            receber/pagar.
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button asChild size="sm" variant="outline">
                            <a href={urlCsv}>Exportar CSV</a>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <a href={urlPdf} target="_blank" rel="noreferrer">
                                Exportar PDF
                            </a>
                        </Button>
                    </div>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="flex gap-2">
                        <Button size="sm" variant={tipo === 'receivable' ? 'default' : 'outline'} onClick={() => filtrar({ tipo: 'receivable' })}>
                            A receber
                        </Button>
                        <Button size="sm" variant={tipo === 'payable' ? 'default' : 'outline'} onClick={() => filtrar({ tipo: 'payable' })}>
                            A pagar
                        </Button>
                    </div>
                    <div>
                        <Label htmlFor="data_de">Vencimento de</Label>
                        <Input
                            id="data_de"
                            type="date"
                            value={dataDe ?? ''}
                            onChange={(e) => filtrar({ data_de: e.target.value })}
                        />
                    </div>
                    <div>
                        <Label htmlFor="data_ate">até</Label>
                        <Input
                            id="data_ate"
                            type="date"
                            value={dataAte ?? ''}
                            onChange={(e) => filtrar({ data_ate: e.target.value })}
                        />
                    </div>
                </div>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-5">
                    <TotalCard titulo="A vencer" valor={totais.a_vencer} />
                    <TotalCard titulo="1–15 dias" valor={totais['1-15']} />
                    <TotalCard titulo="16–30 dias" valor={totais['16-30']} />
                    <TotalCard titulo="30+ dias" valor={totais['30+']} destaque />
                    <TotalCard titulo="Total" valor={totais.total} forte />
                </div>

                {titulos.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum título aberto neste filtro.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">{tipo === 'payable' ? 'Fornecedor' : 'Cliente'}</th>
                                    <th className="p-3 font-medium">Categoria</th>
                                    <th className="p-3 font-medium">Vencimento</th>
                                    <th className="p-3 font-medium">Faixa</th>
                                    <th className="p-3 font-medium">Saldo aberto</th>
                                </tr>
                            </thead>
                            <tbody>
                                {titulos.map((t) => (
                                    <tr key={t.public_id} className="border-t">
                                        <td className="p-3">{t.nome}</td>
                                        <td className="text-muted-foreground p-3">{t.categoria}</td>
                                        <td className="p-3 whitespace-nowrap">{t.vencimento ? emData(t.vencimento) : '—'}</td>
                                        <td className="p-3">
                                            <Badge variant={FAIXA_BADGE[t.faixa]}>{FAIXA_LABEL[t.faixa]}</Badge>
                                        </td>
                                        <td className="p-3 whitespace-nowrap">{emReais(t.saldo_aberto)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

function TotalCard({ titulo, valor, destaque, forte }: { titulo: string; valor: string; destaque?: boolean; forte?: boolean }) {
    return (
        <div className="rounded-md border p-3">
            <div className="text-muted-foreground text-xs">{titulo}</div>
            <div className={`mt-1 text-lg font-semibold ${destaque ? 'text-destructive' : ''} ${forte ? 'text-xl' : ''}`}>{emReais(valor)}</div>
        </div>
    );
}
