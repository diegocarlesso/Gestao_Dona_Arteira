import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Permissões do usuário autenticado (pasta 19).
 *
 * IMPORTANTE: isto serve para **esconder** o que a pessoa não pode
 * fazer, nunca para proteger. A autoridade é a Policy no backend — uma
 * tela escondida continua alcançável por URL, e é o servidor que recusa.
 */
export function usePermissions() {
    const { auth } = usePage<SharedData>().props;
    const permissoes = auth?.permissions ?? [];

    return {
        /** O usuário tem esta permissão? */
        can: (permissao: string): boolean => permissoes.includes(permissao),

        /** Tem ao menos uma das permissões? */
        canAny: (...lista: string[]): boolean => lista.some((p) => permissoes.includes(p)),

        /** Tem todas? */
        canAll: (...lista: string[]): boolean => lista.every((p) => permissoes.includes(p)),

        permissoes,
    };
}
