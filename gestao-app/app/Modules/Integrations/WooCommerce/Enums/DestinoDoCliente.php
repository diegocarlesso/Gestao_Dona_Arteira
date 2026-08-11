<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Enums;

/**
 * O que acontece com cada cadastro do site na carga — docs/17-Migracao F3.
 *
 * Enum separado do `DestinoDaTriagem` (produtos) porque os destinos não se
 * parecem: lá a pergunta é "isto é produto, invólucro ou variação vazia?",
 * aqui é "esta pessoa comprou alguma vez?". Juntar os dois num enum só
 * daria uma lista onde metade dos casos nunca ocorre para metade das
 * entidades — e caso que não ocorre convida tratamento que nunca é
 * exercitado.
 */
enum DestinoDoCliente: string
{
    /** Ainda não triado, ou triado sem informação suficiente para decidir. */
    case Pendente = 'pending';

    /** Vira cliente no ERP: comprou pelo menos uma vez. */
    case Cliente = 'cliente';

    /**
     * Cadastro sem nenhuma compra — **rejeitado por minimização de dados**.
     *
     * São ~136 dos 198 cadastros do site (pasta 31 §2): checkout
     * abandonado, newsletter, importação antiga. Trazê-los para o ERP seria
     * guardar dado pessoal sem finalidade de venda nem obrigação fiscal, o
     * oposto do que a [pasta 25 §3](../../../../../../docs/25-Seguranca/README.md)
     * exige. Decisão da diretoria em 2026-08-10.
     *
     * Rejeição **explícita**, com motivo e contagem no relatório — nada é
     * descartado em silêncio (princípio 4 da pasta 17).
     */
    case SemPedido = 'sem_pedido';

    /**
     * Segundo cadastro do site para a mesma pessoa.
     *
     * O inventário mediu **zero** duplicatas entre os 62 compradores
     * (pasta 31 §7: CPF e e-mail batem 1:1), então este caso não deve
     * ocorrer. Existe porque a medição é de uma data e a carga roda noutra,
     * e porque descobrir a duplicata como violação de UNIQUE no meio da
     * carga seria descobrir tarde demais.
     */
    case Duplicado = 'duplicado';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Ainda não triado',
            self::Cliente => 'Vira cliente',
            self::SemPedido => 'Sem pedido — não migra (LGPD)',
            self::Duplicado => 'Duplicado no próprio site — não migra',
        };
    }

    /** Entra no cadastro de clientes do ERP na carga (F4)? */
    public function viraCliente(): bool
    {
        return $this === self::Cliente;
    }
}
