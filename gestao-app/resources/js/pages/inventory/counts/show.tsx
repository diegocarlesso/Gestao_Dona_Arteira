import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Lock, Save, X } from 'lucide-react';
import { useState } from 'react';

type Item = {
    id: number;
    sku: string | null;
    name: string | null;
    color: string | null;
    qty_system: string;
    qty_counted: string | null;
    divergencia: string | null;
};

interface Props {
    contagem: {
        public_id: string;
        local: string | null;
        status: string;
        status_label: string;
        notes: string | null;
        aberta: boolean;
        created_at: string | null;
    };
    resumo: { total: number; contados: number; divergentes: number };
    itens: Paginado<Item>;
    filtros: { divergentes: boolean; pendentes: boolean };
    podeAjustar: boolean;
    podeAprovar: boolean;
}

const emQuantidade = (valor: string | null) => {
    if (valor === null) return '—';
    const numero = Number(valor);
    return Number.isInteger(numero) ? String(numero) : numero.toLocaleString('pt-BR', { maximumFractionDigits: 3 });
};

export default function ContagemShow({ contagem, resumo, itens, filtros, podeAjustar, podeAprovar }: Props) {
    // As quantidades digitadas nesta página, antes de salvar. Chave é o id
    // do item — o backend grava por id, não por posição, então paginar no
    // meio da digitação não embaralha nada.
    const [quantidades, setQuantidades] = useState<Record<number, string>>({});
    const form = useForm({});

    // Do Inertia, e não de `form.errors`: aprovação e status são recusas
    // do servidor sobre a contagem inteira, não validação de campo — o
    // formulário vazio não tem chave onde pendurá-las.
    const { errors } = usePage().props;

    const salvar = () => {
        router.post(`/estoque/contagens/${contagem.public_id}/contar`, { quantidades }, { preserveScroll: true, preserveState: false });
        setQuantidades({});
    };

    const acao = (caminho: string, confirmacao?: string) => {
        if (confirmacao && !confirm(confirmacao)) return;
        form.post(`/estoque/contagens/${contagem.public_id}/${caminho}`, { preserveScroll: true });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Estoque', href: '/estoque' },
        { title: 'Contagens', href: '/estoque/contagens' },
        { title: contagem.local ?? 'Contagem', href: '#' },
    ];

    const filtrar = (chave: 'divergentes' | 'pendentes') =>
        router.get(
            `/estoque/contagens/${contagem.public_id}`,
            { [chave]: filtros[chave] ? '' : '1' },
            { preserveState: false },
        );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Contagem — ${contagem.local ?? ''}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            Contagem · {contagem.local}
                            <Badge variant={contagem.status === 'approved' ? 'default' : 'outline'}>{contagem.status_label}</Badge>
                        </h1>
                        {contagem.notes && <p className="text-muted-foreground text-sm">{contagem.notes}</p>}
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {contagem.aberta && podeAjustar && (
                            <>
                                <Button onClick={salvar} disabled={Object.keys(quantidades).length === 0}>
                                    <Save className="size-4" />
                                    Salvar contagem
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() =>
                                        acao(
                                            'encerrar',
                                            'Encerrar a contagem?\n\nEla para de aceitar quantidades e passa a precisar da aprovação de outra pessoa.',
                                        )
                                    }
                                >
                                    <Lock className="size-4" />
                                    Encerrar
                                </Button>
                            </>
                        )}

                        {podeAprovar && (
                            <Button
                                onClick={() =>
                                    acao(
                                        'aprovar',
                                        `Aprovar a contagem?\n\n${resumo.divergentes} divergência(s) viram movimento de ajuste no estoque. Isso não se desfaz — só por contra-movimento.`,
                                    )
                                }
                            >
                                <Check className="size-4" />
                                Aprovar e ajustar
                            </Button>
                        )}

                        {!contagem.aberta && contagem.status === 'awaiting_approval' && !podeAprovar && podeAjustar && (
                            <p className="text-muted-foreground max-w-xs text-xs">
                                Quem contou não aprova a própria contagem (BR-205). Peça a outra pessoa com permissão de ajuste.
                            </p>
                        )}

                        {!contagem.aberta && contagem.status !== 'approved' && contagem.status !== 'cancelled' && podeAjustar && (
                            <Button variant="ghost" onClick={() => acao('cancelar', 'Cancelar a contagem? Nada será ajustado.')}>
                                <X className="size-4" />
                                Cancelar
                            </Button>
                        )}
                    </div>
                </div>

                {errors.aprovacao && <p className="text-destructive text-sm">{errors.aprovacao}</p>}
                {errors.status && <p className="text-destructive text-sm">{errors.status}</p>}

                <div className="grid grid-cols-3 gap-3">
                    {[
                        { rotulo: 'Peças na lista', valor: resumo.total },
                        { rotulo: 'Contadas', valor: `${resumo.contados} / ${resumo.total}` },
                        { rotulo: 'Divergentes', valor: resumo.divergentes },
                    ].map(({ rotulo, valor }) => (
                        <div key={rotulo} className="rounded-md border p-3">
                            <div className="text-muted-foreground text-xs">{rotulo}</div>
                            <div className="text-lg font-semibold">{valor}</div>
                        </div>
                    ))}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant={filtros.pendentes ? 'default' : 'outline'} onClick={() => filtrar('pendentes')}>
                        Só não contadas
                    </Button>
                    <Button size="sm" variant={filtros.divergentes ? 'default' : 'outline'} onClick={() => filtrar('divergentes')}>
                        Só divergentes
                    </Button>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="p-3 font-medium">Código</th>
                                <th className="p-3 font-medium">Peça</th>
                                <th className="p-3 text-right font-medium">Sistema</th>
                                <th className="p-3 text-right font-medium">Contado</th>
                                <th className="p-3 text-right font-medium">Diferença</th>
                            </tr>
                        </thead>
                        <tbody>
                            {itens.data.map((item) => {
                                const digitado = quantidades[item.id];
                                const valor = digitado !== undefined ? digitado : (item.qty_counted ?? '');

                                return (
                                    <tr key={item.id} className="border-t">
                                        <td className="p-3 font-mono text-xs whitespace-nowrap">{item.sku ?? '—'}</td>
                                        <td className="p-3">
                                            {item.name}
                                            {item.color && <span className="text-muted-foreground"> · {item.color}</span>}
                                        </td>
                                        <td className="text-muted-foreground p-3 text-right whitespace-nowrap">
                                            {emQuantidade(item.qty_system)}
                                        </td>
                                        <td className="p-3 text-right">
                                            {contagem.aberta && podeAjustar ? (
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    inputMode="decimal"
                                                    className="ml-auto h-8 w-24 text-right"
                                                    value={valor}
                                                    onChange={(e) =>
                                                        setQuantidades((atual) => ({ ...atual, [item.id]: e.target.value }))
                                                    }
                                                    aria-label={`Quantidade contada de ${item.name}`}
                                                />
                                            ) : (
                                                emQuantidade(item.qty_counted)
                                            )}
                                        </td>
                                        <td className="p-3 text-right whitespace-nowrap">
                                            {item.divergencia === null || Number(item.divergencia) === 0 ? (
                                                <span className="text-muted-foreground">—</span>
                                            ) : (
                                                <Badge variant={Number(item.divergencia) > 0 ? 'outline' : 'destructive'}>
                                                    {Number(item.divergencia) > 0 ? '+' : ''}
                                                    {emQuantidade(item.divergencia)}
                                                </Badge>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                {itens.last_page > 1 && (
                    <div className="flex flex-wrap items-center gap-1">
                        {Object.keys(quantidades).length > 0 && (
                            <p className="text-destructive mr-3 text-xs">Salve antes de mudar de página — o digitado não vai junto.</p>
                        )}
                        {itens.links.map((link, i) => (
                            <Button
                                key={i}
                                size="sm"
                                variant={link.active ? 'default' : 'outline'}
                                disabled={!link.url}
                                onClick={() => link.url && router.visit(link.url)}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
