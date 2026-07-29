import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Paginado } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { Check, RefreshCw } from 'lucide-react';

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

type Achado = {
    id: number;
    remote_id: string;
    detail: string | null;
    occurrences: number;
    recorrente: boolean;
    last_detected_at: string | null;
    resolvivel: boolean;
};

type UltimaReconciliacao = {
    started_at: string | null;
    finished_at: string | null;
    situacao: 'ok' | 'falha' | 'incompleta';
    atrasada: boolean;
    orders_checked: number;
    orders_missing_imported: number;
    orders_divergent: number;
    findings_resolved: number;
};

interface Props {
    resumo: { falha: number; pendencia: number; rejeitado: number; na_fila: number };
    eventos: Paginado<Evento>;
    filtro: string;
    achados: { abertos: number; recorrentes: number; lista: Achado[] };
    ultimaReconciliacao: UltimaReconciliacao | null;
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

export default function PainelIntegracoes({ resumo, eventos, filtro, achados, ultimaReconciliacao }: Props) {
    const { errors } = usePage().props;

    const filtrar = (situacao: string) => router.get('/integracoes', situacao === filtro ? {} : { situacao }, { preserveState: true, replace: true });

    const reprocessar = (id: number) => {
        if (!confirm('Reprocessar este evento?\n\nRe-roda a importação com o bruto guardado. Se o pedido já entrou, é inofensivo.')) return;
        router.post(`/integracoes/eventos/${id}/reprocessar`, {}, { preserveScroll: true });
    };

    const resolver = (id: number, remoteId: string) => {
        if (
            !confirm(
                `Aceitar o novo total do site para o pedido #${remoteId} como linha de base?\n\n` +
                    'O alerta para de reaparecer. O pedido no ERP NÃO muda (valores congelados). ' +
                    'Se o certo é o total antigo, corrija no site — a reconciliação resolve sozinha.',
            )
        )
            return;
        router.post(`/integracoes/divergencias/${id}/resolver`, {}, { preserveScroll: true });
    };

    const reconciliacaoAlerta = ultimaReconciliacao !== null && (ultimaReconciliacao.atrasada || ultimaReconciliacao.situacao !== 'ok');

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

                {/* Heartbeat da reconciliação diária (ADR-0025). */}
                {ultimaReconciliacao === null ? (
                    <div className="text-muted-foreground rounded-md border border-dashed p-3 text-sm">A reconciliação diária ainda não rodou.</div>
                ) : (
                    <div className={`rounded-md border p-3 text-sm ${reconciliacaoAlerta ? 'border-amber-500/50 bg-amber-500/5' : ''}`}>
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span className="font-medium">Última reconciliação</span>
                            <span className="text-muted-foreground">
                                {emDataHora(ultimaReconciliacao.finished_at ?? ultimaReconciliacao.started_at)}
                            </span>
                            <span className="text-muted-foreground">
                                {ultimaReconciliacao.orders_checked} conferidos · {ultimaReconciliacao.orders_missing_imported} importados ·{' '}
                                {ultimaReconciliacao.orders_divergent} divergências
                            </span>
                            {ultimaReconciliacao.situacao === 'incompleta' && <Badge variant="destructive">Não concluiu</Badge>}
                            {ultimaReconciliacao.situacao === 'falha' && <Badge variant="destructive">Falhou</Badge>}
                            {ultimaReconciliacao.atrasada && <Badge variant="secondary">Atrasada — o agendador pode ter parado</Badge>}
                        </div>
                    </div>
                )}

                {/* Divergências de valor abertas — a seção de reconciliação (ADR-0025). */}
                {achados.abertos > 0 && (
                    <div className="flex flex-col gap-3">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-lg font-semibold">Divergências de valor</h2>
                            <Badge variant="secondary">
                                {achados.abertos} aberta{achados.abertos > 1 ? 's' : ''}
                            </Badge>
                            {achados.recorrentes > 0 && (
                                <Badge variant="destructive">
                                    {achados.recorrentes} recorrente{achados.recorrentes > 1 ? 's' : ''}
                                </Badge>
                            )}
                        </div>
                        <p className="text-muted-foreground text-sm">
                            Pedidos cujo total mudou no site depois de importados — provável edição no wp-admin. O pedido no ERP fica congelado; ao
                            resolver, você aceita o novo total como linha de base.
                        </p>
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-left">
                                    <tr>
                                        <th className="p-3 font-medium">Pedido</th>
                                        <th className="p-3 font-medium">Divergência</th>
                                        <th className="p-3 font-medium">Ocorrências</th>
                                        <th className="p-3 font-medium">Detectada</th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {achados.lista.map((a) => (
                                        <tr key={a.id} className="border-t align-top">
                                            <td className="p-3 font-mono text-xs">#{a.remote_id}</td>
                                            <td className="text-muted-foreground max-w-md p-3">{a.detail ?? '—'}</td>
                                            <td className="p-3">
                                                {a.occurrences}
                                                {a.recorrente && (
                                                    <Badge variant="destructive" className="ml-2">
                                                        recorrente
                                                    </Badge>
                                                )}
                                            </td>
                                            <td className="text-muted-foreground p-3 whitespace-nowrap">{emDataHora(a.last_detected_at)}</td>
                                            <td className="p-3 text-right">
                                                {a.resolvivel && (
                                                    <Button size="sm" variant="ghost" onClick={() => resolver(a.id, a.remote_id)}>
                                                        <Check className="size-4" />
                                                        Resolver
                                                    </Button>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

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
