<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\DTO;

use App\Modules\Fiscal\Enums\InvoiceStatus;

/**
 * O que a SEFAZ respondeu — traduzido para o vocabulário do ERP.
 *
 * Existe para que o domínio não conheça o formato de retorno de nenhuma
 * biblioteca: o `sped-nfe` devolve um XML de `retEnviNFe`; uma API fiscal
 * gerenciada devolveria JSON com outros nomes (ADR-0009 mantém as duas em
 * aberto). Quem troca o gateway troca a tradução, não o `EmitNfeForOrder`.
 *
 * `pending` é resposta legítima, não ausência de resposta: SEFAZ em
 * processamento, lote em fila, contingência — e, hoje, gateway ainda não
 * plugado. Quem recebe `pending` não conclui nada e tenta de novo depois.
 */
final readonly class NfeTransmissionResult
{
    private function __construct(
        public InvoiceStatus $status,
        public ?string $accessKey,
        public ?string $protocol,
        /** Motivo da rejeição, ou a pendência que explica o `pending`. */
        public ?string $rejectionReason,
        /** Caminho do XML autorizado no storage, quando houver (BR-603). */
        public ?string $xmlPath,
    ) {}

    /**
     * Autorizada: chave de acesso e protocolo são obrigatórios — sem os
     * dois não há como provar a autorização, e "autorizada sem protocolo"
     * é um estado que não deve poder ser construído.
     */
    public static function autorizado(string $accessKey, string $protocol, ?string $xmlPath = null): self
    {
        return new self(InvoiceStatus::Authorized, $accessKey, $protocol, null, $xmlPath);
    }

    /**
     * Rejeitada: o motivo é obrigatório pelo mesmo princípio. Rejeição sem
     * texto é o que faz o operador abrir chamado em vez de corrigir o
     * cadastro — a pasta 14 exige a tradução em ação, não o código seco.
     */
    public static function rejeitado(string $motivo): self
    {
        return new self(InvoiceStatus::Rejected, null, null, $motivo, null);
    }

    public static function pendente(string $motivo): self
    {
        return new self(InvoiceStatus::Pending, null, null, $motivo, null);
    }
}
