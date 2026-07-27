<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Enums\PriceList;
use App\Modules\Catalog\Enums\ProductKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Exceptions\SkuEhImutavel;
use Database\Factories\Catalog\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um produto do catálogo — docs/32-Catalogo, ADR-0022.
 *
 * Peça acabada, matéria-prima, embalagem, revenda e insumo, distinguidos
 * por `kind` — um só cadastro e um só estoque (BR-207).
 *
 * **Cada acabamento é um produto** (BR-009): "buda azul" e "buda dourado"
 * são duas linhas, com dois SKUs e dois saldos. A cor é acabamento de
 * pintura manual, produzida separadamente e ocupando lugar próprio na
 * prateleira — saldo agregado não responde à pergunta que a operação faz.
 *
 * **Não há coluna de saldo aqui.** Quantidade vem do ledger da pasta 09.
 *
 * @property int $id
 * @property string $public_id
 * @property string $sku
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property ProductKind $kind
 * @property string $unit
 * @property ProductStatus $status
 * @property string|null $color
 * @property string|null $height_cm
 * @property string|null $width_cm
 * @property string|null $depth_cm
 * @property string|null $weight_g
 * @property string|null $ncm
 * @property string|null $cest
 * @property string|null $origin
 * @property string|null $gtin
 * @property int|null $product_category_id
 * @property int|null $default_package_id
 * @property string $min_stock
 * @property int|null $drying_days
 * @property bool $sell_on_woo
 * @property Carbon $created_at
 */
class Product extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * `sku` fica de fora: ele é gerado pelo Service, nunca vem de
     * formulário. Deixá-lo aqui abriria a porta para um campo escondido
     * definir o código de um produto.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'kind',
        'unit',
        'color',
        'height_cm',
        'width_cm',
        'depth_cm',
        'weight_g',
        'ncm',
        'cest',
        'origin',
        'gtin',
        'product_category_id',
        'default_package_id',
        'min_stock',
        'drying_days',
        'sell_on_woo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => ProductKind::class,
            'status' => ProductStatus::class,
            'sell_on_woo' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $produto): void {
            $produto->public_id ??= (string) Str::ulid();
            $produto->slug ??= self::slugUnico($produto->name);
        });

        // BR-002: o SKU é imutável após a criação. A invariante mora aqui,
        // e não só na ausência de tela de edição: seeder, tinker, job de
        // migração e comando de console também passam por este ponto.
        static::updating(function (self $produto): void {
            if ($produto->isDirty('sku')) {
                throw SkuEhImutavel::para(
                    (string) $produto->getOriginal('sku'),
                    (string) $produto->sku,
                );
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    /**
     * @return BelongsTo<Package, $this>
     */
    public function defaultPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'default_package_id');
    }

    /**
     * @return HasMany<ProductPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }

    /**
     * Imagens, na ordem em que a origem as trouxe (ADR-0017).
     *
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('position');
    }

    /**
     * A imagem que representa o produto numa listagem.
     */
    public function imagemPrincipal(): ?ProductImage
    {
        return $this->images->first();
    }

    /**
     * O preço que vale hoje numa lista, ou nulo se nunca houve.
     *
     * Nulo é resposta legítima e esperada para `wholesale`: a empresa
     * vende atacado, mas os preços nunca foram registrados em sistema
     * nenhum e a equipe os preencherá produto a produto (pasta 32 §3.5).
     */
    public function precoVigente(PriceList $lista): ?ProductPrice
    {
        return $this->prices()
            ->where('price_list', $lista->value)
            ->where('valid_from', '<=', now())
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();
    }

    public function temPrecoDe(PriceList $lista): bool
    {
        return $this->precoVigente($lista) !== null;
    }

    /**
     * Falta algo que só se descobre tarde demais?
     *
     * Peso ausente quebra a cotação de frete na hora da venda; atacado
     * ausente obriga a digitar preço no pedido. Nenhum dos dois impede
     * salvar o cadastro — mas a listagem mostra, porque lacuna silenciosa
     * vira problema no balcão.
     *
     * @return list<string>
     */
    public function pendencias(): array
    {
        $pendencias = [];

        if ($this->weight_g === null) {
            $pendencias[] = 'sem peso';
        }

        if (! $this->temPrecoDe(PriceList::Retail)) {
            $pendencias[] = 'sem preço de varejo';
        }

        if (! $this->temPrecoDe(PriceList::Wholesale)) {
            $pendencias[] = 'sem preço de atacado';
        }

        return $pendencias;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }

    /**
     * Produtos sem preço numa lista — o filtro que a equipe usa para
     * saber o que ainda falta preencher, sem varrer 716 fichas.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeSemPrecoDe(Builder $query, PriceList $lista): Builder
    {
        return $query->whereDoesntHave(
            'prices',
            fn (Builder $q) => $q->where('price_list', $lista->value)
        );
    }

    private static function slugUnico(string $nome): string
    {
        $base = Str::slug($nome);
        $slug = $base;
        $sufixo = 2;

        // O nome repete com frequência neste catálogo — 14 grupos de
        // títulos idênticos no legado, e agora cada cor é um produto com
        // nome parecido. Sem desambiguar, o UNIQUE do slug reprovaria o
        // segundo cadastro com uma mensagem que não explica nada.
        while (self::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$sufixo;
            $sufixo++;
        }

        return $slug;
    }
}
