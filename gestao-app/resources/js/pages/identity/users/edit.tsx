import { SeletorDePapeis } from '@/components/identity/seletor-de-papeis';
import { StatusDaConta } from '@/components/identity/status-da-conta';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Opcao, type OpcaoDePapel, type RoleValue, type UserStatus, type UsuarioDaLista } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { Info, LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    usuario: UsuarioDaLista;
    papeisDisponiveis: OpcaoDePapel[];
    situacoesDisponiveis: Opcao[];
    ehVoce: boolean;
}

// `type`, não `interface`: exigência do useForm do Inertia.
type FormularioDeEdicao = {
    name: string;
    email: string;
    roles: RoleValue[];
    status: UserStatus;
};

export default function EditarUsuario({ usuario, papeisDisponiveis, situacoesDisponiveis, ehVoce }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Usuários', href: '/usuarios' },
        { title: usuario.name, href: `/usuarios/${usuario.public_id}` },
    ];

    const { data, setData, put, processing, errors } = useForm<FormularioDeEdicao>({
        name: usuario.name,
        email: usuario.email,
        roles: usuario.roles,
        status: usuario.status,
    });

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        put(`/usuarios/${usuario.public_id}`);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar ${usuario.name}`} />

            <form onSubmit={enviar} className="flex max-w-2xl flex-col gap-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold">{usuario.name}</h1>
                        <p className="text-muted-foreground text-sm">{usuario.email}</p>
                    </div>
                    <StatusDaConta status={usuario.status} label={usuario.status_label} />
                </div>

                {ehVoce && (
                    <div className="border-sidebar-border/70 dark:border-sidebar-border flex gap-3 rounded-lg border p-3 text-sm">
                        <Info className="mt-0.5 size-4 shrink-0" aria-hidden />
                        <p>
                            Esta é a sua conta. Você não pode alterar os próprios papéis nem a própria situação — caso contrário seria possível se
                            promover, ou se trancar do lado de fora do sistema.
                        </p>
                    </div>
                )}

                <div className="grid gap-2">
                    <Label htmlFor="name">Nome</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">E-mail</Label>
                    <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required />
                    <InputError message={errors.email} />
                </div>

                <div className="grid gap-2">
                    <Label>Papéis</Label>
                    <SeletorDePapeis
                        disponiveis={papeisDisponiveis}
                        selecionados={data.roles}
                        onChange={(papeis) => setData('roles', papeis)}
                        disabled={ehVoce}
                    />
                    <InputError message={errors.roles} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="status">Situação</Label>
                    <Select
                        value={data.status}
                        onValueChange={(v) => setData('status', v as UserStatus)}
                        disabled={ehVoce || situacoesDisponiveis.length <= 1}
                    >
                        <SelectTrigger id="status" className="max-w-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {situacoesDisponiveis.map((s) => (
                                <SelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                    <p className="text-muted-foreground text-xs">
                        A lista mostra só as transições permitidas a partir da situação atual. Desativar é definitivo: a conta não volta — cria-se uma
                        nova, e a antiga permanece para a auditoria referenciar.
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Salvar
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href="/usuarios">Cancelar</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
