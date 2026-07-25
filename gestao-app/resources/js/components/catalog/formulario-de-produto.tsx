import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

export type OpcoesDoFormulario = {
    tipos: { value: string; label: string }[];
    categorias: { id: number; nome: string }[];
    embalagens: { id: number; nome: string }[];
};

export type ValoresDoProduto = {
    name: string;
    description: string;
    kind: string;
    unit: string;
    color: string;
    height_cm: string;
    width_cm: string;
    depth_cm: string;
    weight_g: string;
    ncm: string;
    cest: string;
    origin: string;
    gtin: string;
    product_category_id: string;
    default_package_id: string;
    min_stock: string;
    drying_days: string;
    sell_on_woo: boolean;
    preco_varejo: string;
    preco_atacado: string;
};

const vazio: ValoresDoProduto = {
    name: '',
    description: '',
    kind: 'finished_good',
    unit: 'UN',
    color: '',
    height_cm: '',
    width_cm: '',
    depth_cm: '',
    weight_g: '',
    ncm: '',
    cest: '',
    origin: '',
    gtin: '',
    product_category_id: '',
    default_package_id: '',
    min_stock: '0',
    drying_days: '',
    sell_on_woo: false,
    preco_varejo: '',
    preco_atacado: '',
};

interface Props {
    opcoes: OpcoesDoFormulario;
    acao: string;
    metodo: 'post' | 'put';
    valores?: Partial<ValoresDoProduto>;
    /** Na edição o preço tem fluxo próprio (linha nova, nunca UPDATE). */
    ocultarPrecos?: boolean;
}

export function FormularioDeProduto({ opcoes, acao, metodo, valores, ocultarPrecos = false }: Props) {
    const { data, setData, post, put, processing, errors } = useForm<ValoresDoProduto>({ ...vazio, ...valores });

    const enviar: FormEventHandler = (e) => {
        e.preventDefault();
        (metodo === 'post' ? post : put)(acao);
    };

    return (
        <form onSubmit={enviar} className="flex max-w-3xl flex-col gap-6">
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2 sm:col-span-2">
                    <Label htmlFor="name">Nome</Label>
                    <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} autoFocus required />
                    <InputError message={errors.name} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="kind">Tipo</Label>
                    <select
                        id="kind"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={data.kind}
                        onChange={(e) => setData('kind', e.target.value)}
                    >
                        {opcoes.tipos.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.kind} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="color">Cor / acabamento</Label>
                    <Input id="color" value={data.color} onChange={(e) => setData('color', e.target.value)} placeholder="Azul, dourado…" />
                    <p className="text-muted-foreground text-xs">Cada acabamento é um produto próprio — não some com os outros no estoque.</p>
                    <InputError message={errors.color} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="product_category_id">Categoria</Label>
                    <select
                        id="product_category_id"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={data.product_category_id}
                        onChange={(e) => setData('product_category_id', e.target.value)}
                    >
                        <option value="">—</option>
                        {opcoes.categorias.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.nome}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.product_category_id} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="unit">Unidade</Label>
                    <Input id="unit" value={data.unit} onChange={(e) => setData('unit', e.target.value)} required />
                    <InputError message={errors.unit} />
                </div>
            </div>

            <fieldset className="grid gap-4 rounded-md border p-4 sm:grid-cols-4">
                <legend className="px-1 text-sm font-medium">Medidas da peça</legend>

                {(
                    [
                        ['height_cm', 'Altura (cm)'],
                        ['width_cm', 'Largura (cm)'],
                        ['depth_cm', 'Profundidade (cm)'],
                        ['weight_g', 'Peso (g)'],
                    ] as const
                ).map(([campo, rotulo]) => (
                    <div key={campo} className="grid gap-2">
                        <Label htmlFor={campo}>{rotulo}</Label>
                        <Input id={campo} inputMode="decimal" value={data[campo]} onChange={(e) => setData(campo, e.target.value)} />
                        <InputError message={errors[campo]} />
                    </div>
                ))}

                <p className="text-muted-foreground text-xs sm:col-span-4">
                    Opcionais no cadastro, mas o peso é necessário para cotar frete — produtos sem peso ficam sinalizados na listagem.
                </p>
            </fieldset>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="grid gap-2">
                    <Label htmlFor="default_package_id">Embalagem padrão</Label>
                    <select
                        id="default_package_id"
                        className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        value={data.default_package_id}
                        onChange={(e) => setData('default_package_id', e.target.value)}
                    >
                        <option value="">—</option>
                        {opcoes.embalagens.map((emb) => (
                            <option key={emb.id} value={emb.id}>
                                {emb.nome}
                            </option>
                        ))}
                    </select>
                    <InputError message={errors.default_package_id} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="min_stock">Estoque mínimo</Label>
                    <Input id="min_stock" inputMode="decimal" value={data.min_stock} onChange={(e) => setData('min_stock', e.target.value)} />
                    <InputError message={errors.min_stock} />
                </div>
            </div>

            <fieldset className="grid gap-4 rounded-md border p-4 sm:grid-cols-4">
                <legend className="px-1 text-sm font-medium">Dados fiscais</legend>

                {(
                    [
                        ['ncm', 'NCM'],
                        ['cest', 'CEST'],
                        ['origin', 'Origem'],
                        ['gtin', 'GTIN'],
                    ] as const
                ).map(([campo, rotulo]) => (
                    <div key={campo} className="grid gap-2">
                        <Label htmlFor={campo}>{rotulo}</Label>
                        <Input id={campo} value={data[campo]} onChange={(e) => setData(campo, e.target.value)} />
                        <InputError message={errors[campo]} />
                    </div>
                ))}

                <p className="text-muted-foreground text-xs sm:col-span-4">
                    Peça artesanal quase nunca tem código de barras — a nota fiscal aceita “SEM GTIN”.
                </p>
            </fieldset>

            {!ocultarPrecos && (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="preco_varejo">Preço de varejo</Label>
                        <Input
                            id="preco_varejo"
                            inputMode="decimal"
                            placeholder="0.00"
                            value={data.preco_varejo}
                            onChange={(e) => setData('preco_varejo', e.target.value)}
                        />
                        <InputError message={errors.preco_varejo} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="preco_atacado">Preço de atacado</Label>
                        <Input
                            id="preco_atacado"
                            inputMode="decimal"
                            placeholder="0.00"
                            value={data.preco_atacado}
                            onChange={(e) => setData('preco_atacado', e.target.value)}
                        />
                        <p className="text-muted-foreground text-xs">Pode ficar em branco e ser preenchido depois.</p>
                        <InputError message={errors.preco_atacado} />
                    </div>
                </div>
            )}

            <div className="flex items-center gap-3">
                <Checkbox id="sell_on_woo" checked={data.sell_on_woo} onCheckedChange={(marcado) => setData('sell_on_woo', marcado === true)} />
                <Label htmlFor="sell_on_woo" className="font-normal">
                    Publicar no site
                </Label>
            </div>

            <Button type="submit" disabled={processing} className="w-fit">
                {processing && <LoaderCircle className="size-4 animate-spin" />}
                Salvar
            </Button>
        </form>
    );
}
