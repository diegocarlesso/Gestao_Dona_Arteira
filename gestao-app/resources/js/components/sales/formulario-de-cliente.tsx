import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';

export type Endereco = {
    public_id?: string | null;
    label: string;
    zip: string;
    street: string;
    number: string;
    complement: string;
    district: string;
    city: string;
    state: string;
    is_default_shipping: boolean;
    is_default_billing: boolean;
};

export type DadosDoCliente = {
    type: string;
    name: string;
    doc: string;
    email: string;
    phone: string;
    state_registration: string;
    is_wholesale: boolean;
    notes: string;
    lgpd_consent_at: string;
    enderecos: Endereco[];
};

type Tipo = { valor: string; rotulo: string; documento: string };

interface Props {
    dados: DadosDoCliente;
    tipos: Tipo[];
    metodo: 'post' | 'put';
    url: string;
    rotuloDoBotao: string;
}

const enderecoVazio = (): Endereco => ({
    label: '',
    zip: '',
    street: '',
    number: '',
    complement: '',
    district: '',
    city: '',
    state: '',
    is_default_shipping: false,
    is_default_billing: false,
});

export function FormularioDeCliente({ dados, tipos, metodo, url, rotuloDoBotao }: Props) {
    const form = useForm<DadosDoCliente>(dados);

    const tipoAtual = tipos.find((t) => t.valor === form.data.type) ?? tipos[0];

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        if (metodo === 'post') {
            form.post(url);
        } else {
            form.put(url);
        }
    };

    const mudarEndereco = (indice: number, campo: keyof Endereco, valor: string | boolean) =>
        form.setData(
            'enderecos',
            form.data.enderecos.map((e, i) => (i === indice ? { ...e, [campo]: valor } : e)),
        );

    return (
        <form onSubmit={enviar} className="flex max-w-3xl flex-col gap-6">
            <section className="flex flex-col gap-4 rounded-md border p-4">
                <div className="flex flex-wrap gap-2">
                    {tipos.map((t) => (
                        <Button
                            key={t.valor}
                            type="button"
                            size="sm"
                            variant={form.data.type === t.valor ? 'default' : 'outline'}
                            onClick={() => form.setData('type', t.valor)}
                        >
                            {t.rotulo}
                        </Button>
                    ))}
                </div>

                <div>
                    <Label htmlFor="name">Nome{form.data.type === 'company' ? ' / Razão social' : ''}</Label>
                    <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                    {form.errors.name && <p className="text-destructive mt-1 text-sm">{form.errors.name}</p>}
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="doc">{tipoAtual?.documento}</Label>
                        <Input
                            id="doc"
                            value={form.data.doc}
                            onChange={(e) => form.setData('doc', e.target.value)}
                            placeholder="opcional para venda de balcão"
                        />
                        {form.errors.doc && <p className="text-destructive mt-1 text-sm">{form.errors.doc}</p>}
                        <p className="text-muted-foreground mt-1 text-xs">Sem ele o cliente existe, mas não sai nota fiscal.</p>
                    </div>

                    {form.data.type === 'company' && (
                        <div>
                            <Label htmlFor="ie">Inscrição estadual</Label>
                            <Input
                                id="ie"
                                value={form.data.state_registration}
                                onChange={(e) => form.setData('state_registration', e.target.value)}
                            />
                        </div>
                    )}
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="email">E-mail</Label>
                        <Input id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                        {form.errors.email && <p className="text-destructive mt-1 text-sm">{form.errors.email}</p>}
                    </div>
                    <div>
                        <Label htmlFor="phone">Telefone</Label>
                        <Input id="phone" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                    </div>
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.data.is_wholesale} onChange={(e) => form.setData('is_wholesale', e.target.checked)} />
                    Cliente de atacado
                    <span className="text-muted-foreground text-xs">(o critério de preço ainda será definido — BR-301)</span>
                </label>

                <div>
                    <Label htmlFor="notes">Observações</Label>
                    <Input id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                </div>
            </section>

            <section className="flex flex-col gap-4 rounded-md border p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h2 className="font-medium">Endereços</h2>
                        <p className="text-muted-foreground text-xs">
                            Entrega e cobrança podem ser diferentes — presente vai para um endereço e a nota, para outro.
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => form.setData('enderecos', [...form.data.enderecos, enderecoVazio()])}
                    >
                        <Plus className="size-4" />
                        Adicionar
                    </Button>
                </div>

                {form.data.enderecos.length === 0 && <p className="text-muted-foreground text-sm">Nenhum endereço cadastrado.</p>}

                {form.data.enderecos.map((endereco, i) => (
                    <div key={endereco.public_id ?? `novo-${i}`} className="flex flex-col gap-3 rounded-md border p-3">
                        <div className="flex items-center justify-between">
                            <Input
                                className="max-w-40"
                                placeholder="Apelido (Casa)"
                                value={endereco.label}
                                onChange={(e) => mudarEndereco(i, 'label', e.target.value)}
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    form.setData(
                                        'enderecos',
                                        form.data.enderecos.filter((_, indice) => indice !== i),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                                <span className="sr-only">Remover endereço</span>
                            </Button>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-4">
                            <Input placeholder="CEP" value={endereco.zip} onChange={(e) => mudarEndereco(i, 'zip', e.target.value)} required />
                            <Input
                                className="sm:col-span-2"
                                placeholder="Rua"
                                value={endereco.street}
                                onChange={(e) => mudarEndereco(i, 'street', e.target.value)}
                                required
                            />
                            <Input placeholder="Número" value={endereco.number} onChange={(e) => mudarEndereco(i, 'number', e.target.value)} />
                            <Input
                                placeholder="Complemento"
                                value={endereco.complement}
                                onChange={(e) => mudarEndereco(i, 'complement', e.target.value)}
                            />
                            <Input placeholder="Bairro" value={endereco.district} onChange={(e) => mudarEndereco(i, 'district', e.target.value)} />
                            <Input placeholder="Cidade" value={endereco.city} onChange={(e) => mudarEndereco(i, 'city', e.target.value)} required />
                            <Input
                                placeholder="UF"
                                maxLength={2}
                                value={endereco.state}
                                onChange={(e) => mudarEndereco(i, 'state', e.target.value.toUpperCase())}
                                required
                            />
                        </div>

                        <div className="flex flex-wrap gap-4 text-sm">
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={endereco.is_default_shipping}
                                    onChange={(e) => mudarEndereco(i, 'is_default_shipping', e.target.checked)}
                                />
                                Entrega padrão
                            </label>
                            <label className="flex items-center gap-2">
                                <input
                                    type="checkbox"
                                    checked={endereco.is_default_billing}
                                    onChange={(e) => mudarEndereco(i, 'is_default_billing', e.target.checked)}
                                />
                                Cobrança padrão
                            </label>
                        </div>
                    </div>
                ))}

                {Object.keys(form.errors)
                    .filter((chave) => chave.startsWith('enderecos.'))
                    .map((chave) => (
                        <p key={chave} className="text-destructive text-sm">
                            {form.errors[chave as keyof typeof form.errors]}
                        </p>
                    ))}
            </section>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={form.processing}>
                    {rotuloDoBotao}
                </Button>
                {form.data.lgpd_consent_at && <Badge variant="outline">Consentimento LGPD registrado</Badge>}
            </div>
        </form>
    );
}
