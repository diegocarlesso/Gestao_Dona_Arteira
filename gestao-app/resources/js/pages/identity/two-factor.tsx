import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle, ShieldAlert, ShieldCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

type Dispositivo = {
    device_id: string;
    ultimo_uso: string | null;
    expira_em: string;
    criado_em: string;
};

type Props = {
    confirmado: boolean;
    obrigatorio: boolean;
    qrCodeSvg: string | null;
    chaveManual: string | null;
    codigosDeRecuperacao: string[] | null;
    dispositivos: Dispositivo[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Segundo fator', href: '/dois-fatores' }];

const dataLegivel = (iso: string) => new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', year: 'numeric' });

/**
 * Configuração do segundo fator (BR-804, ADR-0021).
 *
 * Três estados possíveis: sem 2FA, com o segredo gerado mas não confirmado
 * (o QR na tela), e confirmado. O middleware `2fa.confirmado` empurra para
 * cá quem tem papel que exige e ainda não configurou.
 */
export default function DoisFatores({ confirmado, obrigatorio, qrCodeSvg, chaveManual, codigosDeRecuperacao, dispositivos }: Props) {
    const iniciar = useForm({});
    const confirmar = useForm<{ codigo: string }>({ codigo: '' });
    const desativar = useForm<{ current_password: string }>({ current_password: '' });
    const renovar = useForm<{ current_password: string }>({ current_password: '' });
    const esquecer = useForm({});

    const enviarConfirmacao: FormEventHandler = (e) => {
        e.preventDefault();
        confirmar.post('/dois-fatores/confirmar', { onFinish: () => confirmar.reset('codigo') });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Segundo fator" />

            <div className="flex flex-col gap-6 p-4">
                {obrigatorio && !confirmado && (
                    <Alert variant="destructive">
                        <ShieldAlert className="size-4" />
                        <AlertTitle>Seu papel exige segundo fator</AlertTitle>
                        <AlertDescription>
                            Enquanto não estiver configurado, o restante do sistema fica indisponível para esta conta. Não há prazo de carência.
                        </AlertDescription>
                    </Alert>
                )}

                {codigosDeRecuperacao && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Guarde os códigos de recuperação</CardTitle>
                            <CardDescription>
                                São a única forma de entrar se você perder o celular. Cada um serve <strong>uma vez só</strong>, e esta é a única vez
                                que eles aparecem — depois de sair desta tela não há como vê-los de novo.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <ul className="bg-muted grid gap-1 rounded-md p-4 font-mono text-sm sm:grid-cols-2">
                                {codigosDeRecuperacao.map((codigo) => (
                                    <li key={codigo}>{codigo}</li>
                                ))}
                            </ul>
                        </CardContent>
                    </Card>
                )}

                {!confirmado && !qrCodeSvg && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Ativar o segundo fator</CardTitle>
                            <CardDescription>
                                Você vai precisar de um aplicativo autenticador no celular (Google Authenticator, Authy, 1Password e afins).
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button onClick={() => iniciar.post('/dois-fatores')} disabled={iniciar.processing}>
                                {iniciar.processing && <LoaderCircle className="size-4 animate-spin" />}
                                Começar
                            </Button>
                        </CardContent>
                    </Card>
                )}

                {qrCodeSvg && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Leia o código no aplicativo</CardTitle>
                            <CardDescription>Depois digite abaixo os seis dígitos que ele passar a mostrar.</CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-6">
                            {/* O SVG vem do servidor, gerado a partir do
                                próprio segredo — não é conteúdo de usuário. */}
                            <div className="w-fit rounded-md bg-white p-3" dangerouslySetInnerHTML={{ __html: qrCodeSvg }} />

                            {chaveManual && (
                                <div className="grid gap-1">
                                    <p className="text-muted-foreground text-xs">Não consegue ler o código? Digite esta chave no aplicativo:</p>
                                    <code className="bg-muted w-fit rounded px-2 py-1 font-mono text-sm break-all">{chaveManual}</code>
                                </div>
                            )}

                            <form onSubmit={enviarConfirmacao} className="grid max-w-xs gap-2">
                                <Label htmlFor="codigo">Código do aplicativo</Label>
                                <Input
                                    id="codigo"
                                    type="text"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    value={confirmar.data.codigo}
                                    onChange={(e) => confirmar.setData('codigo', e.target.value)}
                                    required
                                />
                                <InputError message={confirmar.errors.codigo} />
                                <Button type="submit" disabled={confirmar.processing}>
                                    {confirmar.processing && <LoaderCircle className="size-4 animate-spin" />}
                                    Confirmar e ativar
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {confirmado && (
                    <>
                        <Alert>
                            <ShieldCheck className="size-4" />
                            <AlertTitle>Segundo fator ativo</AlertTitle>
                            <AlertDescription>O código do aplicativo é pedido a cada entrada, exceto nos dispositivos lembrados.</AlertDescription>
                        </Alert>

                        <Card>
                            <CardHeader>
                                <CardTitle>Dispositivos lembrados</CardTitle>
                                <CardDescription>
                                    Nestes aparelhos o código não é pedido até a data indicada. Trocar a senha esquece todos automaticamente.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-4">
                                {dispositivos.length === 0 ? (
                                    <p className="text-muted-foreground text-sm">Nenhum dispositivo lembrado.</p>
                                ) : (
                                    <ul className="grid gap-2 text-sm">
                                        {dispositivos.map((d) => (
                                            <li key={d.device_id} className="flex flex-wrap justify-between gap-2 border-b pb-2 last:border-0">
                                                <span>Confiado em {dataLegivel(d.criado_em)}</span>
                                                <span className="text-muted-foreground">
                                                    vale até {dataLegivel(d.expira_em)}
                                                    {d.ultimo_uso && ` · último uso em ${dataLegivel(d.ultimo_uso)}`}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                {dispositivos.length > 0 && (
                                    <Button
                                        variant="outline"
                                        className="w-fit"
                                        disabled={esquecer.processing}
                                        onClick={() => esquecer.delete('/dois-fatores/dispositivos')}
                                    >
                                        Esquecer todos os dispositivos
                                    </Button>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Renovar os códigos de recuperação</CardTitle>
                                <CardDescription>Gera oito códigos novos e invalida os anteriores na hora.</CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form
                                    className="grid max-w-xs gap-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        renovar.post('/dois-fatores/codigos-de-recuperacao', {
                                            onFinish: () => renovar.reset('current_password'),
                                        });
                                    }}
                                >
                                    <Label htmlFor="senha_renovar">Senha atual</Label>
                                    <Input
                                        id="senha_renovar"
                                        type="password"
                                        autoComplete="current-password"
                                        value={renovar.data.current_password}
                                        onChange={(e) => renovar.setData('current_password', e.target.value)}
                                        required
                                    />
                                    <InputError message={renovar.errors.current_password} />
                                    <Button type="submit" variant="outline" disabled={renovar.processing}>
                                        Renovar códigos
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle>Desativar</CardTitle>
                                <CardDescription>
                                    {obrigatorio
                                        ? 'Seu papel exige segundo fator: desativar prende esta conta na tela de configuração até você ativá-lo de novo. Serve para trocar de celular.'
                                        : 'Sua conta volta a entrar apenas com a senha.'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <form
                                    className="grid max-w-xs gap-2"
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        desativar.delete('/dois-fatores', {
                                            onFinish: () => desativar.reset('current_password'),
                                        });
                                    }}
                                >
                                    <Label htmlFor="senha_desativar">Senha atual</Label>
                                    <Input
                                        id="senha_desativar"
                                        type="password"
                                        autoComplete="current-password"
                                        value={desativar.data.current_password}
                                        onChange={(e) => desativar.setData('current_password', e.target.value)}
                                        required
                                    />
                                    <InputError message={desativar.errors.current_password} />
                                    <Button type="submit" variant="destructive" disabled={desativar.processing}>
                                        Desativar segundo fator
                                    </Button>
                                </form>
                            </CardContent>
                        </Card>
                    </>
                )}
            </div>
        </AppLayout>
    );
}
