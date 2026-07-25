<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Events\ProductArchived;
use App\Modules\Catalog\Models\Product;

/**
 * Arquiva (ou desarquiva) um produto — BR-008, docs/32-Catalogo §3.8.
 *
 * Produto **não é excluído**: pedido, movimento de estoque e nota fiscal
 * antigos o referenciam, e apagá-lo transformaria o histórico em "um item
 * que não existe mais foi vendido". Arquivar tira das telas de venda e do
 * site; o registro continua.
 *
 * Ao contrário do ciclo de vida da conta (pasta 18), aqui a volta é
 * permitida: arquivar um produto é decisão comercial reversível — a peça
 * saiu de linha nesta temporada e pode voltar na próxima. Nenhum acesso é
 * concedido por desarquivar.
 */
class ArchiveProductService
{
    public function arquivar(Product $produto): Product
    {
        if ($produto->status === ProductStatus::Archived) {
            return $produto;
        }

        $produto->status = ProductStatus::Archived;
        $produto->save();

        ProductArchived::dispatch($produto, arquivado: true);

        return $produto->refresh();
    }

    public function desarquivar(Product $produto): Product
    {
        if ($produto->status === ProductStatus::Active) {
            return $produto;
        }

        $produto->status = ProductStatus::Active;
        $produto->save();

        ProductArchived::dispatch($produto, arquivado: false);

        return $produto->refresh();
    }
}
