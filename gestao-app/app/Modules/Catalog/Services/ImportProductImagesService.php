<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductImage;

/**
 * Registra as referências de imagem de um produto — ADR-0017, fase 1.
 *
 * Exposto como Service pelo mesmo motivo do `ImportProductService`: a
 * migração não constrói models do Catálogo por fora (ADR-0020).
 *
 * Idempotente por (produto, origem, id remoto): recarregar atualiza a URL
 * em vez de duplicar a imagem (BR-706).
 */
class ImportProductImagesService
{
    /**
     * @param  list<array{remote_id?: string|int|null, url: string, thumbnail_url?: string|null, alt?: string|null}>  $imagens
     * @return int quantas foram registradas
     */
    public function importar(int $produtoId, array $imagens): int
    {
        $registradas = 0;

        // Sem `array_values`: o tipo já é uma lista, e a chave do `foreach`
        // é a posição que queremos gravar. Sem `?? ''` no url: o contrato
        // acima diz que ele é obrigatório — o guarda é contra string
        // vazia, que a origem produz quando a mídia foi apagada no
        // WordPress mas a referência ficou.
        foreach ($imagens as $posicao => $imagem) {
            if ($imagem['url'] === '') {
                continue;
            }

            ProductImage::query()->updateOrCreate(
                [
                    'product_id' => $produtoId,
                    'source' => ProductImage::ORIGEM_WOO,
                    'remote_id' => isset($imagem['remote_id']) ? (string) $imagem['remote_id'] : null,
                ],
                [
                    'url' => mb_substr($imagem['url'], 0, 1023),
                    'thumbnail_url' => isset($imagem['thumbnail_url'])
                        ? mb_substr((string) $imagem['thumbnail_url'], 0, 1023)
                        : null,
                    'alt' => isset($imagem['alt']) ? mb_substr((string) $imagem['alt'], 0, 255) : null,
                    'position' => $posicao,
                ],
            );

            $registradas++;
        }

        return $registradas;
    }
}
