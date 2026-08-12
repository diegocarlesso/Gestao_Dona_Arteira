<?php

declare(strict_types=1);

namespace App\Modules\Integrations\MercadoPago\Mappers;

use App\Modules\Finance\Enums\StatusCobranca;
use ValueError;

/**
 * Traduz o vocabulário de status do Mercado Pago para o vocabulário
 * interno do Financeiro — ADR-0018 §3.1.
 *
 * Único ponto do sistema que conhece as duas nomenclaturas ao mesmo tempo.
 * `StatusCobranca` nunca deveria mudar de significado porque o provedor
 * renomeou um status numa versão nova da API dele — é para isto que esta
 * classe existe.
 */
class StatusMap
{
    public static function de(string $statusMercadoPago): StatusCobranca
    {
        return match ($statusMercadoPago) {
            'approved' => StatusCobranca::Paga,
            'pending', 'in_process' => StatusCobranca::Registrada,
            'cancelled' => StatusCobranca::Cancelada,
            'rejected' => StatusCobranca::Falhou,
            // Sem caso de uso de estorno de PIX/boleto nesta v1 (só
            // cancelamento) — tratar como cancelada evita inventar um
            // status interno sem baixa nem reconciliação prontos para ele.
            'refunded' => StatusCobranca::Cancelada,
            default => throw new ValueError("status desconhecido do Mercado Pago: {$statusMercadoPago}"),
        };
    }
}
