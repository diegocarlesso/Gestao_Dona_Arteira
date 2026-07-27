import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { ImageOff } from 'lucide-react';
import { ReactNode, useState } from 'react';

interface Props {
    imagem: string | null;
    alt: string | null;
    children: ReactNode;
}

/**
 * Mostra a foto da peça ao passar o mouse sobre o nome.
 *
 * O catálogo tem 754 produtos com nomes parecidos — seis "Incensário
 * cascata buda na lua 12 cm" diferindo só pela cor. Ler a linha não
 * distingue; ver a peça, sim.
 *
 * A imagem é servida pelo WordPress (ADR-0017, fase 1): o ERP guarda a
 * referência, não o arquivo. Se a foto sumir de lá, aqui aparece o aviso
 * — melhor um espaço vazio nomeado que um ícone quebrado do navegador.
 */
export function PreviaDoProduto({ imagem, alt, children }: Props) {
    const [falhou, setFalhou] = useState(false);

    if (!imagem) {
        return <>{children}</>;
    }

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>{children}</TooltipTrigger>
                <TooltipContent side="right" className="bg-popover p-1">
                    {falhou ? (
                        <div className="text-muted-foreground flex size-40 flex-col items-center justify-center gap-2 text-xs">
                            <ImageOff className="size-6" />
                            Imagem indisponível
                        </div>
                    ) : (
                        <img
                            src={imagem}
                            alt={alt ?? ''}
                            className="size-40 rounded object-contain"
                            // `lazy` porque a prévia só existe ao passar o
                            // mouse: sem isso o navegador baixaria as 20
                            // fotos da página que ninguém vai olhar.
                            loading="lazy"
                            onError={() => setFalhou(true)}
                        />
                    )}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
