<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\ProductCategory;

/**
 * Cria ou atualiza uma categoria vinda de importação em massa — BR-007.
 *
 * Mesmo motivo do `ImportProductService`: a migração não constrói models
 * do Catálogo por fora (ADR-0020). Devolve o id local para quem chamou
 * guardar no mapeamento.
 */
class ImportProductCategoryService
{
    /**
     * @param  int|null  $localId  id da categoria já importada antes, se houver
     * @return array{0: int, 1: bool} id e se foi criada agora
     */
    public function importar(?int $localId, string $nome, ?string $slug = null): array
    {
        $categoria = $localId !== null ? ProductCategory::query()->find($localId) : null;
        $nova = $categoria === null;

        if ($nova) {
            $categoria = new ProductCategory;
        }

        $categoria->forceFill([
            'name' => $nome,
            'slug' => $slug ?: str($nome)->slug()->value(),
        ])->save();

        return [(int) $categoria->getKey(), $nova];
    }

    /**
     * Liga a categoria à mãe, num segundo passo.
     *
     * Separado da criação porque a origem não garante ordem por
     * profundidade: uma subcategoria pode chegar antes da mãe, e ligar na
     * hora exigiria uma mãe que ainda não existe.
     */
    public function definirMae(int $localId, int $maeId): void
    {
        ProductCategory::query()->whereKey($localId)->update(['parent_id' => $maeId]);
    }
}
