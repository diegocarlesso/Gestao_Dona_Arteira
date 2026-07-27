<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Enums;

/**
 * O que acontece com cada linha do staging na carga — docs/17-Migracao F3.
 *
 * A classificação **não é uniforme por tipo do Woo**, e essa foi a
 * descoberta que o saneamento trouxe: um `variable` pode virar produto ou
 * ser descartado, dependendo de as variações dele existirem de verdade.
 */
enum DestinoDaTriagem: string
{
    case Pendente = 'pending';

    /** Vira um produto no catálogo do ERP. */
    case Produto = 'produto';

    /**
     * Invólucro do WooCommerce, sem correspondente no ERP.
     *
     * Produto `variable` cujas variações são reais: no Woo ele agrupa,
     * mas o que se vende são as variações — e é cada uma delas que vira
     * produto ([ADR-0022](../../../../../../docs/27-ADR/ADR-0022-modelo-de-produto-e-sku.md)).
     * O pai não tem preço próprio, o que confirma o papel: é vitrine.
     */
    case Involucro = 'involucro';

    /**
     * Variação que nunca recebeu atributo.
     *
     * Existe em 21 casos, todos de produtos que declaram cor como eixo e
     * nunca materializaram as variações. Quem carrega o nome e o anúncio é
     * o pai, então é o pai que vira produto — e esta linha se descarta
     * depois de emprestar o preço a ele.
     */
    case VariacaoVazia = 'variacao_vazia';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Ainda não triado',
            self::Produto => 'Vira produto',
            self::Involucro => 'Invólucro do Woo — descartado',
            self::VariacaoVazia => 'Variação vazia — descartada',
        };
    }

    /** Entra no catálogo do ERP na carga (F4)? */
    public function viraProduto(): bool
    {
        return $this === self::Produto;
    }
}
