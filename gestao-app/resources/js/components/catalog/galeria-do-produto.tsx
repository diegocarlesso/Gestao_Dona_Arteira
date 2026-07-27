import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ImageOff } from 'lucide-react';
import { useState } from 'react';

export type ImagemDoProduto = {
    url: string;
    previa: string;
    alt: string | null;
};

interface Props {
    imagens: ImagemDoProduto[];
    nome: string;
}

/**
 * A peça, na ficha do produto.
 *
 * Serve a quem está conferindo o cadastro: com 754 produtos de nomes
 * parecidos, ver a foto é o que confirma que se está na ficha certa antes
 * de mexer em preço ou medida.
 *
 * As imagens são servidas pelo WordPress (ADR-0017, fase 1) — o ERP
 * guarda a referência, não o arquivo. Por isso o tratamento de falha:
 * a origem é outro sistema, e ele pode sair do ar sem avisar.
 */
export function GaleriaDoProduto({ imagens, nome }: Props) {
    const [selecionada, setSelecionada] = useState(0);
    const [falharam, setFalharam] = useState<Record<number, boolean>>({});

    if (imagens.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Imagem</CardTitle>
                    <CardDescription>Este produto não tem imagem cadastrada.</CardDescription>
                </CardHeader>
            </Card>
        );
    }

    const atual = imagens[selecionada];

    return (
        <Card>
            <CardHeader>
                <CardTitle>Imagem</CardTitle>
                <CardDescription>
                    {imagens.length === 1
                        ? 'Hospedada no site — o ERP guarda a referência, não o arquivo.'
                        : `${imagens.length} imagens, hospedadas no site. Clique para ver cada uma.`}
                </CardDescription>
            </CardHeader>

            <CardContent className="flex flex-col gap-3">
                <div className="bg-muted flex w-fit items-center justify-center overflow-hidden rounded-md">
                    {falharam[selecionada] ? (
                        <div className="text-muted-foreground flex size-64 flex-col items-center justify-center gap-2 text-sm">
                            <ImageOff className="size-8" />
                            Imagem indisponível no site
                        </div>
                    ) : (
                        // Abre a original em nova aba: a versão exibida é
                        // reduzida, e quem confere um detalhe da peça
                        // precisa do tamanho cheio.
                        <a href={atual.url} target="_blank" rel="noopener noreferrer" title="Abrir em tamanho original">
                            <img
                                src={atual.previa}
                                alt={atual.alt ?? nome}
                                className="size-64 object-contain"
                                onError={() => setFalharam((antes) => ({ ...antes, [selecionada]: true }))}
                            />
                        </a>
                    )}
                </div>

                {imagens.length > 1 && (
                    <div className="flex flex-wrap gap-2">
                        {imagens.map((imagem, i) => (
                            <button
                                key={i}
                                type="button"
                                onClick={() => setSelecionada(i)}
                                className={`overflow-hidden rounded border-2 ${i === selecionada ? 'border-primary' : 'border-transparent'}`}
                                aria-label={`Ver imagem ${i + 1} de ${imagens.length}`}
                            >
                                <img src={imagem.previa} alt="" className="size-14 object-cover" loading="lazy" />
                            </button>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
