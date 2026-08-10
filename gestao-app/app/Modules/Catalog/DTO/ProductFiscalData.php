<?php

declare(strict_types=1);

namespace App\Modules\Catalog\DTO;

/**
 * O que a nota fiscal precisa saber de um produto — BR-606, docs/13 §5.
 *
 * Terceiro DTO do Catálogo, e de propósito: o `ProductSummary` é o cartão
 * de visita para exibir, o `ProductSnapshot` é a conferência da migração, e
 * este é a linha da NF-e. Alargar qualquer um dos outros até caber NCM e
 * origem misturaria "mostrar o produto" com "declará-lo ao fisco" — e o dia
 * em que a reforma tributária acrescentar campo, o DTO errado mudaria.
 *
 * Todos os campos fiscais são nuláveis porque o catálogo migrado do Woo
 * **nasce incompleto** (pasta 32: a origem não tinha NCM). A lacuna não é
 * erro de programação, é estado do cadastro — e quem decide o que fazer com
 * ela é a emissão, não este objeto.
 */
final readonly class ProductFiscalData
{
    public function __construct(
        public int $id,
        public string $sku,
        public string $name,
        public string $unit,
        public ?string $ncm,
        public ?string $cest,
        public ?string $origin,
        public ?string $gtin,
    ) {}

    /**
     * O que falta neste produto para ele entrar numa NF-e — BR-606.
     *
     * NCM e origem são exigidos pelo layout; CEST só quando há substituição
     * tributária (hipótese ainda aberta na doc 13, então não entra como
     * pendência) e GTIN aceita "SEM GTIN", então a ausência também não
     * bloqueia. A lista volta vazia quando o cadastro está pronto.
     *
     * @return list<string>
     */
    public function pendencias(): array
    {
        $pendencias = [];

        if ($this->ncm === null || $this->ncm === '') {
            $pendencias[] = 'sem NCM';
        }

        if ($this->origin === null || $this->origin === '') {
            $pendencias[] = 'sem origem da mercadoria';
        }

        return $pendencias;
    }
}
