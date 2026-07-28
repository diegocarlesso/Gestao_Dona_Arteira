<?php

declare(strict_types=1);

namespace App\Modules\Integrations\WooCommerce\Services;

/**
 * O que aconteceu ao importar um pedido do Woo.
 *
 * Existe para o comando de puxada e o job de webhook relatarem sem
 * reabrir o pedido: quantos entraram confirmados, quantos ficaram
 * pendentes, quantos eram repetição. O `motivo` é o que vai para o
 * `error`/log quando não deu para confirmar.
 *
 * Guarda o **id** do pedido, não o model `Order`: a Integração não pode
 * enxergar `Sales\Models` (ADR-0020).
 */
final readonly class ResultadoDaImportacao
{
    public const IMPORTADO = 'importado';

    public const PENDENTE = 'pendente';

    public const DUPLICADO = 'duplicado';

    public const IGNORADO = 'ignorado';

    public const SEM_ITENS = 'sem_itens';

    /** Pedido já importado que o site cancelou → refletido no ERP. */
    public const CANCELADO = 'cancelado';

    /** Site cancelou um pedido que o ERP já expediu → alerta, não desfaz. */
    public const CONFLITO = 'conflito';

    /**
     * @param  list<string>  $naoMapeados  SKUs/ids do Woo que não casaram
     */
    private function __construct(
        public string $status,
        public ?int $orderId = null,
        public array $naoMapeados = [],
        public ?string $motivo = null,
    ) {}

    public static function importado(int $orderId): self
    {
        return new self(self::IMPORTADO, $orderId);
    }

    /**
     * @param  list<string>  $naoMapeados
     */
    public static function pendente(int $orderId, string $motivo, array $naoMapeados = []): self
    {
        return new self(self::PENDENTE, $orderId, $naoMapeados, $motivo);
    }

    public static function duplicado(): self
    {
        return new self(self::DUPLICADO);
    }

    public static function cancelado(int $orderId): self
    {
        return new self(self::CANCELADO, $orderId);
    }

    public static function conflito(int $orderId, string $motivo): self
    {
        return new self(self::CONFLITO, $orderId, motivo: $motivo);
    }

    public static function ignorado(string $motivo): self
    {
        return new self(self::IGNORADO, motivo: $motivo);
    }

    /**
     * @param  list<string>  $naoMapeados
     */
    public static function semItens(array $naoMapeados, string $motivo): self
    {
        return new self(self::SEM_ITENS, naoMapeados: $naoMapeados, motivo: $motivo);
    }

    public function processado(): bool
    {
        return in_array($this->status, [self::IMPORTADO, self::PENDENTE], true);
    }
}
