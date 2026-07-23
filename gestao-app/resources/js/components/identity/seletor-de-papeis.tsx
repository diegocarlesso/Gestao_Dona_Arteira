import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { type OpcaoDePapel, type RoleValue } from '@/types';
import { ShieldAlert } from 'lucide-react';

interface Props {
    disponiveis: OpcaoDePapel[];
    selecionados: RoleValue[];
    onChange: (papeis: RoleValue[]) => void;
    disabled?: boolean;
}

/**
 * Escolha dos papéis de uma conta (pasta 19 §2).
 *
 * Papéis são acumuláveis — a mesma pessoa pode ser Vendas e Expedição, e
 * recebe a união das permissões. Por isso caixas de seleção, não uma
 * lista de escolha única.
 */
export function SeletorDePapeis({ disponiveis, selecionados, onChange, disabled }: Props) {
    function alternar(papel: RoleValue, marcado: boolean) {
        onChange(marcado ? [...selecionados, papel] : selecionados.filter((p) => p !== papel));
    }

    return (
        <div className="grid gap-3 sm:grid-cols-2">
            {disponiveis.map((papel) => {
                const id = `papel-${papel.value}`;
                const marcado = selecionados.includes(papel.value as RoleValue);

                return (
                    <div
                        key={papel.value}
                        className="border-sidebar-border/70 dark:border-sidebar-border flex items-start gap-3 rounded-lg border p-3"
                    >
                        <Checkbox
                            id={id}
                            checked={marcado}
                            disabled={disabled}
                            onCheckedChange={(v) => alternar(papel.value as RoleValue, v === true)}
                        />
                        <div className="grid gap-1 leading-none">
                            <Label htmlFor={id} className="cursor-pointer font-medium">
                                {papel.label}
                            </Label>
                            {papel.requires_two_factor && (
                                <span className="inline-flex items-center gap-1 text-xs text-amber-600 dark:text-amber-500">
                                    <ShieldAlert className="size-3" aria-hidden />
                                    Exige 2FA (BR-804)
                                </span>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
