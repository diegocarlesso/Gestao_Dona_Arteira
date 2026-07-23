import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

// `type`, não `interface`: exigência do useForm do Inertia.
type FormularioDeTroca = {
    current_password: string;
    password: string;
    password_confirmation: string;
};

/**
 * Troca obrigatória no primeiro acesso (pasta 18 §5).
 *
 * Usa o layout de autenticação, sem menu, de propósito: o middleware
 * prende a pessoa aqui, e mostrar uma navegação que não leva a lugar
 * nenhum só geraria cliques frustrados.
 */
export default function TrocarSenhaObrigatoria() {
    const { data, setData, put, processing, errors, reset } = useForm<FormularioDeTroca>({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        put('/trocar-senha', {
            onFinish: () => reset('current_password', 'password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout
            title="Defina uma senha sua"
            description="Esta conta ainda usa a senha provisória gerada na instalação — que apareceu no console do deploy e pode ter sido vista por outras pessoas."
        >
            <Head title="Trocar senha" />

            <form onSubmit={enviar} className="flex flex-col gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="current_password">Senha provisória</Label>
                    <Input
                        id="current_password"
                        type="password"
                        value={data.current_password}
                        onChange={(e) => setData('current_password', e.target.value)}
                        autoComplete="current-password"
                        autoFocus
                        required
                    />
                    <InputError message={errors.current_password} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password">Nova senha</Label>
                    <Input
                        id="password"
                        type="password"
                        value={data.password}
                        onChange={(e) => setData('password', e.target.value)}
                        autoComplete="new-password"
                        required
                    />
                    <InputError message={errors.password} />
                    <p className="text-muted-foreground text-xs">
                        Mínimo de 12 caracteres. A senha é conferida contra vazamentos públicos conhecidos — só um trecho do código dela sai daqui,
                        nunca a senha inteira.
                    </p>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="password_confirmation">Repita a nova senha</Label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        value={data.password_confirmation}
                        onChange={(e) => setData('password_confirmation', e.target.value)}
                        autoComplete="new-password"
                        required
                    />
                    <InputError message={errors.password_confirmation} />
                </div>

                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Salvar e entrar
                </Button>
            </form>
        </AuthLayout>
    );
}
