import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';

export type DadosDoFornecedor = {
    legal_name: string;
    trade_name: string;
    document: string;
    email: string;
    phone: string;
    payment_terms_days: string;
    lead_time_days: string;
    notes: string;
};

interface Props {
    dados: DadosDoFornecedor;
    metodo: 'post' | 'put';
    url: string;
    rotuloDoBotao: string;
}

export function FormularioDeFornecedor({ dados, metodo, url, rotuloDoBotao }: Props) {
    const form = useForm<DadosDoFornecedor>(dados);

    const enviar = (e: React.FormEvent) => {
        e.preventDefault();

        if (metodo === 'post') {
            form.post(url);
        } else {
            form.put(url);
        }
    };

    return (
        <form onSubmit={enviar} className="flex max-w-3xl flex-col gap-6">
            <section className="flex flex-col gap-4 rounded-md border p-4">
                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="legal_name">Razão social</Label>
                        <Input id="legal_name" value={form.data.legal_name} onChange={(e) => form.setData('legal_name', e.target.value)} required />
                        {form.errors.legal_name && <p className="text-destructive mt-1 text-sm">{form.errors.legal_name}</p>}
                    </div>

                    <div>
                        <Label htmlFor="trade_name">Nome fantasia</Label>
                        <Input
                            id="trade_name"
                            placeholder="como a operação chama"
                            value={form.data.trade_name}
                            onChange={(e) => form.setData('trade_name', e.target.value)}
                        />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="document">CNPJ</Label>
                        <Input id="document" value={form.data.document} onChange={(e) => form.setData('document', e.target.value)} required />
                        {form.errors.document && <p className="text-destructive mt-1 text-sm">{form.errors.document}</p>}
                        <p className="text-muted-foreground mt-1 text-xs">CPF também vale — o artesão que fornece a peça crua nem sempre tem CNPJ.</p>
                    </div>

                    <div>
                        <Label htmlFor="phone">Telefone</Label>
                        <Input id="phone" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} />
                    </div>
                </div>

                <div>
                    <Label htmlFor="email">E-mail</Label>
                    <Input id="email" type="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} />
                    {form.errors.email && <p className="text-destructive mt-1 text-sm">{form.errors.email}</p>}
                </div>
            </section>

            <section className="flex flex-col gap-4 rounded-md border p-4">
                <div>
                    <h2 className="font-medium">Prazos</h2>
                    <p className="text-muted-foreground text-xs">
                        O prazo de pagamento vira o vencimento da conta a pagar quando o pedido de compra for confirmado.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div>
                        <Label htmlFor="payment_terms_days">Prazo de pagamento (dias)</Label>
                        <Input
                            id="payment_terms_days"
                            type="number"
                            min="0"
                            max="365"
                            placeholder="30"
                            value={form.data.payment_terms_days}
                            onChange={(e) => form.setData('payment_terms_days', e.target.value)}
                        />
                        {form.errors.payment_terms_days && <p className="text-destructive mt-1 text-sm">{form.errors.payment_terms_days}</p>}
                        <p className="text-muted-foreground mt-1 text-xs">Em branco = à vista ou a combinar.</p>
                    </div>

                    <div>
                        <Label htmlFor="lead_time_days">Prazo de entrega (dias)</Label>
                        <Input
                            id="lead_time_days"
                            type="number"
                            min="0"
                            max="365"
                            placeholder="15"
                            value={form.data.lead_time_days}
                            onChange={(e) => form.setData('lead_time_days', e.target.value)}
                        />
                        {form.errors.lead_time_days && <p className="text-destructive mt-1 text-sm">{form.errors.lead_time_days}</p>}
                    </div>
                </div>

                <div>
                    <Label htmlFor="notes">Observações</Label>
                    <Input
                        id="notes"
                        placeholder="ex.: só entrega às terças, pede pedido mínimo"
                        value={form.data.notes}
                        onChange={(e) => form.setData('notes', e.target.value)}
                    />
                </div>
            </section>

            <div>
                <Button type="submit" disabled={form.processing}>
                    {rotuloDoBotao}
                </Button>
            </div>
        </form>
    );
}
