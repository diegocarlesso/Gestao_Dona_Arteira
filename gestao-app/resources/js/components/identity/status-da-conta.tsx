import { cn } from '@/lib/utils';
import { type UserStatus } from '@/types';

const ESTILOS: Record<UserStatus, string> = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-950 dark:text-emerald-400 dark:ring-emerald-400/20',
    invited: 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-950 dark:text-sky-400 dark:ring-sky-400/20',
    suspended: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-950 dark:text-amber-400 dark:ring-amber-400/20',
    disabled: 'bg-neutral-100 text-neutral-600 ring-neutral-500/20 dark:bg-neutral-800 dark:text-neutral-400 dark:ring-neutral-400/20',
};

/**
 * Situação da conta (pasta 18 §3).
 *
 * A cor acompanha a consequência, não a estética: verde só para quem
 * realmente entra no sistema; âmbar para bloqueio reversível; cinza para
 * o estado terminal.
 */
export function StatusDaConta({ status, label }: { status: UserStatus; label: string }) {
    return (
        <span className={cn('inline-flex items-center rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset', ESTILOS[status])}>{label}</span>
    );
}
