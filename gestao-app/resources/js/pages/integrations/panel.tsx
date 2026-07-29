import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';

type Situacao = 'na_fila' | 'falha' | 'pendencia' | 'rejeitado' | 'processado';

type Evento = {
    id: number;
    topic: string;
    remote_id: string | null;
    situacao: Situacao;
    erro: string | null;
    received_at: string | null;
    processed_at: string | null;
    reprocessavel: boolean;
};

interface Props {
    resumo: { falha: number; pendencia: number; rejeitado: number; na_fila: number };
    eventos: Paginado<Evento>;
    filtro: string;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Integrações', href: '/integracoes' }];

const META: Record<Situacao, { rotulo: string; cor: string; badge: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    falha: { rotulo: 'Falha', cor: 'text-destructive', badge: 'destructive' },
    pendencia: { rotulo: 'Pendência', cor: 'text-amber-600', badge: 'secondary' },
    rejeitado: { rotulo: 'Rejeitado', cor: 'text-destructive', badge: 'destructive' },
    na_fila: { rotulo: 'Na fila', cor: 'text-muted-foreground', badge: 'outline' },
    processado: { rotulo: 'Processado', cor: 'text-muted-foreground', badge: 'outline' },
};

const CARDS: (keyof Props['resumo'])[] = ['falha', 'pendencia', 'rejeitado', 'na_fila'];

const emDataHora = (iso: string | null) => (iso === null ? '—' : new Date(iso).toLocaleString('pt-BR'));

export default function PainelIntegracoes({ resumo, eventos, filtro }: Props) {
    const { errors } = usePage().props;

    const filtrar = (situacao: string) => router.get('/integracoes', situacao === filtro ? {} : { situacao }, { preserveState: true, replace: true });

    const reprocessar = (id: number) => {
        if (!confirm('Reprocessar este evento?\n\nRe-roda a importação com o bruto guardado. Se o pedido já entrou, é inofensivo.')) return;
        router.post(`/integracoes/eventos/${id}/reprocessar`, {}, { preserveScroll: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Integrações" />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Integrações</h1>
                    <p className="text-muted-foreground text-sm">
                        O que a sincronização com o site está fazendo. Pendências e falhas aparecem aqui; corrija a causa (mapear o produto, repor o
                        saldo) e reprocesse.
                    </p>
                </div>

                {errors.reprocessar && <p className="text-destructive text-sm">{errors.reprocessar}</p>}

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    {CARDS.map((situacao) => (
                        <button
                            key={situacao}
                            type="button"
                            onClick={() => filtrar(situacao)}
                            className={`hover:bg-accent rounded-md border p-4 text-left transition ${filtro === situacao ? 'border-primary ring-primary/30 ring-1' : ''}`}
                        >
                            <div className={`text-2xl font-semibold ${resumo[situacao] > 0 ? META[situacao].cor : ''}`}>{resumo[situacao]}</div>
                            <div className="text-muted-foreground text-xs">{META[situacao].rotulo}</div>
                        </button>
                    ))}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant={filtro === 'todos' ? 'default' : 'outline'} onClick={() => filtrar('todos')}>
                        Todos
                    </Button>
                    {CARDS.map((situacao) => (
                        <Button key={situacao} size="sm" variant={filtro === situacao ? 'default' : 'outline'} onClick={() => filtrar(situacao)}>
                            {META[situacao].rotulo}
                        </Button>
                    ))}
                </div>

                {eventos.data.length === 0 ? (
                    <div className="rounded-md border border-dashed p-8 text-center">
                        <p className="font-medium">Nenhum evento por aqui.</p>
                        <p className="text-muted-foreground mt-1 text-sm">Quando o site enviar pedidos, eles aparecem nesta lista.</p>
                    </div>
                ) : (
                    <div className="overflow-x-auto rounded-md border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/50 text-left">
                                <tr>
                                    <th className="p-3 font-medium">Situação</th>
                                    <th className="p-3 font-medium">Tópico</th>
                                    <th className="p-3 font-medium">Pedido</th>
                                    <th className="p-3 font-medium">Recebido</th>
                                    <th className="p-3 font-medium">Aviso</th>
                                    <th className="p-3" />
                                </tr>
                            </thead>
                            <tbody>
                                {eventos.data.map((e) => (
                                    <tr key={e.id} className="border-t align-top">
                                        <td className="p-3">
                                            <Badge variant={META[e.situacao].badge}>{META[e.situacao].rotulo}</Badge>
                                        </td>
                                        <td className="p-3 font-mono text-xs">{e.topic}</td>
                                        <td className="p-3 font-mono text-xs">{e.remote_id ? `#${e.remote_id}` : '—'}</td>
                                        <td className="text-muted-foreground p-3 whitespace-nowrap">{emDataHora(e.received_at)}</td>
                                        <td className="text-muted-foreground max-w-md p-3">{e.erro ?? '—'}</td>
                                        <td className="p-3 text-right">
                                            {e.reprocessavel && (
                                                <Button size="sm" variant="ghost" onClick={() => reprocessar(e.id)}>
                                                    <RefreshCw className="size-4" />
                                                    Reprocessar
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {eventos.last_page > 1 && (
                    <div className="flex flex-wrap gap-1">
                        {eventos.links.map((link, i) => (
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
        </AppLayout>
    );
}
