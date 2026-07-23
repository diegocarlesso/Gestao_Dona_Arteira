import { SeletorDePapeis } from '@/components/identity/seletor-de-papeis';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type OpcaoDePapel, type RoleValue } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Usuários', href: '/usuarios' },
    { title: 'Nova conta', href: '/usuarios/novo' },
];

// `type`, não `interface`: o useForm do Inertia exige índice de string
// (FormDataType), que interfaces não fornecem implicitamente.
type FormularioDeConvite = {
    name: string;
    email: string;
    roles: RoleValue[];
};

export default function CriarUsuario({ papeisDisponiveis }: { papeisDisponiveis: OpcaoDePapel[] }) {
    const { data, setData, post, processing, errors } = useForm<FormularioDeConvite>({
        name: '',
        email: '',
        roles: [],
    });

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        post('/usuarios');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Nova conta" />

            <form onSubmit={enviar} className="flex max-w-2xl flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Nova conta</h1>
                    <p className="text-muted-foreground text-sm">
                        A conta nasce <strong>aguardando ativação</strong> e sem senha utilizável. A pessoa define a própria senha pelo link de
                        redefinição — assim ela nunca passa por e-mail nem por conversa.
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="name">Nome</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus autoComplete="name" required />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="email">E-mail</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        autoComplete="email"
                        required
                    />
                    <InputError message={errors.email} />
                    <p className="text-muted-foreground text-xs">
                        Precisa ser individual. Conta compartilhada torna a auditoria inútil — não há a quem atribuir o que foi feito.
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label>Papéis</Label>
                    <SeletorDePapeis disponiveis={papeisDisponiveis} selecionados={data.roles} onChange={(papeis) => setData('roles', papeis)} />
                    <InputError message={errors.roles} />
                    <p className="text-muted-foreground text-xs">
                        Sem papel, a conta existe mas não acessa nada — o acesso é negado por padrão (BR-801).
                    </p>
                </div>

                <div className="flex items-center gap-3">
                    <Button type="submit" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Criar conta
                    </Button>
                    <Button asChild variant="ghost">
                        <Link href="/usuarios">Cancelar</Link>
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
