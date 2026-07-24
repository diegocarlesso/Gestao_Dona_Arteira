import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

// `type`, não `interface`: exigência do useForm do Inertia.
type FormularioDoDesafio = {
    codigo: string;
    lembrar_dispositivo: boolean;
};

/**
 * O segundo fator no login (ADR-0021).
 *
 * Quem está nesta tela acertou a senha e **não** está autenticado — não há
 * menu porque não há sessão. O mesmo campo aceita o código do aplicativo e
 * um código de recuperação; o servidor tenta o TOTP primeiro.
 */
export default function DesafioDeDoisFatores() {
    const [usandoRecuperacao, setUsandoRecuperacao] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm<FormularioDoDesafio>({
        codigo: '',
        lembrar_dispositivo: false,
    });

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        post('/entrar/dois-fatores', {
            onFinish: () => reset('codigo'),
        });
    };

    return (
        <AuthLayout
            title="Confirme que é você"
            description={
                usandoRecuperacao
                    ? 'Use um dos códigos de recuperação que você guardou ao ativar o segundo fator. Cada um serve uma vez só.'
                    : 'Abra o aplicativo autenticador e digite o código de seis dígitos que ele mostra agora.'
            }
        >
            <Head title="Confirmação em duas etapas" />

            <form onSubmit={enviar} className="flex flex-col gap-6">
                <div className="grid gap-2">
                    <Label htmlFor="codigo">{usandoRecuperacao ? 'Código de recuperação' : 'Código do aplicativo'}</Label>
                    <Input
                        id="codigo"
                        // `text` e não `number`: o campo também aceita
                        // código de recuperação, que tem letras e hífen —
                        // e `number` esconderia o teclado numérico do
                        // celular atrás de setas de incremento.
                        type="text"
                        inputMode={usandoRecuperacao ? 'text' : 'numeric'}
                        value={data.codigo}
                        onChange={(e) => setData('codigo', e.target.value)}
                        autoComplete="one-time-code"
                        autoFocus
                        required
                    />
                    <InputError message={errors.codigo} />
                </div>

                {!usandoRecuperacao && (
                    <div className="flex items-start gap-3">
                        <Checkbox
                            id="lembrar_dispositivo"
                            checked={data.lembrar_dispositivo}
                            onCheckedChange={(marcado) => setData('lembrar_dispositivo', marcado === true)}
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="lembrar_dispositivo" className="font-normal">
                                Lembrar deste dispositivo por 30 dias
                            </Label>
                            <p className="text-muted-foreground text-xs">
                                Só marque em um aparelho seu. Neste dispositivo o código deixa de ser pedido até lá — trocar a senha volta a exigi-lo
                                em todos.
                            </p>
                        </div>
                    </div>
                )}

                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Entrar
                </Button>

                <button
                    type="button"
                    className="text-muted-foreground hover:text-foreground text-center text-sm underline-offset-4 hover:underline"
                    onClick={() => {
                        setUsandoRecuperacao(!usandoRecuperacao);
                        reset('codigo');
                        // Um código de recuperação nunca confia no
                        // dispositivo (ADR-0021). Desmarcar aqui evita
                        // prometer na tela o que o servidor vai recusar.
                        setData('lembrar_dispositivo', false);
                    }}
                >
                    {usandoRecuperacao ? 'Usar o código do aplicativo' : 'Perdi o acesso ao aplicativo'}
                </button>
            </form>
        </AuthLayout>
    );
}
