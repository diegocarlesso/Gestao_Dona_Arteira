<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Models;

use App\Support\Documento;
use Carbon\CarbonInterface;
use Database\Factories\Purchasing\SupplierFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Um fornecedor — docs/11-Compras §4.
 *
 * Auditado porque o cadastro decide dinheiro: mudar `payment_terms_days`
 * muda o vencimento de todo título novo, e "quem trocou o prazo deste
 * fornecedor" é pergunta que aparece quando a conta vence antes do
 * esperado.
 *
 * @property int $id
 * @property string $public_id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property string $document
 * @property string|null $email
 * @property string|null $phone
 * @property int|null $payment_terms_days
 * @property int|null $lead_time_days
 * @property string|null $notes
 * @property Carbon|null $deleted_at
 */
class Supplier extends Model implements Auditable
{
    use AuditableTrait;

    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'legal_name',
        'trade_name',
        'document',
        'email',
        'phone',
        'payment_terms_days',
        'lead_time_days',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer',
            'lead_time_days' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $fornecedor): void {
            $fornecedor->public_id ??= (string) Str::ulid();
        });

        // Normaliza em qualquer caminho de escrita — cadastro pela tela,
        // importação, correção por comando. Deixar a limpeza só no
        // FormRequest funcionaria apenas no caminho que passa por ele, e
        // aí o índice único deixaria entrar o mesmo CNPJ mascarado.
        static::saving(function (self $fornecedor): void {
            $fornecedor->document = preg_replace('/\D/', '', $fornecedor->document) ?? '';
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasMany<PurchaseOrder, $this>
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    /**
     * Como a operação chama este fornecedor — o fantasia quando existe, a
     * razão social quando não. Quem digita "Gesso do Zé" na busca não
     * lembra de "Comércio de Artigos Decorativos Ltda ME".
     */
    public function apelido(): string
    {
        return $this->trade_name ?? $this->legal_name;
    }

    /**
     * O documento com máscara, para leitura.
     */
    public function documentoFormatado(): string
    {
        return Documento::valido($this->document)
            ? Documento::de($this->document)->formatado()
            : $this->document;
    }

    /**
     * O vencimento de um título emitido nesta data, pelo prazo do
     * fornecedor — BR-402.
     *
     * Nulo quando o prazo não está cadastrado: título sem vencimento
     * continua devido e visível no aberto (o Financeiro aceita), enquanto
     * um vencimento inventado entraria mudo no caixa projetado como se
     * fosse acordado.
     */
    public function vencimentoAPartirDe(CarbonInterface $emissao): ?string
    {
        if ($this->payment_terms_days === null) {
            return null;
        }

        return $emissao->copy()->addDays($this->payment_terms_days)->toDateString();
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeBuscandoPor(Builder $query, string $termo): Builder
    {
        $digitos = preg_replace('/\D/', '', $termo) ?? '';

        return $query->where(fn (Builder $q): Builder => $q
            ->where('legal_name', 'like', "%{$termo}%")
            ->orWhere('trade_name', 'like', "%{$termo}%")
            ->orWhere('email', 'like', "%{$termo}%")
            ->when($digitos !== '', fn (Builder $q): Builder => $q->orWhere('document', 'like', "%{$digitos}%")));
    }
}
