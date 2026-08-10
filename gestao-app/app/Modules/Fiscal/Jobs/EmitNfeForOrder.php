<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Jobs;

use App\Modules\Fiscal\Contracts\NfeGatewayInterface;
use App\Modules\Fiscal\Enums\InvoiceStatus;
use App\Modules\Fiscal\Events\InvoiceAuthorized;
use App\Modules\Fiscal\Events\InvoiceRejected;
use App\Modules\Fiscal\Exceptions\EmissaoInvalida;
use App\Modules\Fiscal\Exceptions\PerfilFiscalAusente;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\BuildInvoiceFromOrder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emite a NF-e de um pedido, fora da requisição — ADR-0025 §2, BR-705.
 *
 * Recebe **id**, nunca model: o pedido é de Vendas e este job é do Fiscal
 * (ADR-0020). Quem precisa dos dados do pedido é o
 * `BuildOrderInvoiceSnapshot`, do lado de lá da fronteira.
 *
 * Em fila pelo mesmo motivo do `ProcessWooOrder`: a SEFAZ é um terceiro que
 * cai, e emitir dentro da confirmação do pedido faria a loja travar quando
 * o fisco estivesse fora do ar. A venda se conclui; a nota se resolve
 * depois, com retry.
 *
 * **Duas famílias de falha, tratadas de formas opostas:**
 *
 * - *Pendência de cadastro* (`EmissaoInvalida`, `PerfilFiscalAusente`) —
 *   NCM faltando, perfil fiscal não cadastrado, cliente sem documento.
 *   Tentar de novo não resolve: só um humano preenchendo o cadastro
 *   resolve. Registra a pendência na nota (visível no painel) e **encerra
 *   sem relançar** — cinco tentativas idênticas só encheriam o log.
 * - *Falha de infraestrutura* (`Throwable`) — rede, certificado, SEFAZ
 *   instável. Registra e **relança**, para a fila tentar de novo com espera
 *   crescente.
 */
class EmitNfeForOrder implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Cinco tentativas — mesmo perfil do `ProcessWooOrder`. */
    public int $tries = 5;

    public function __construct(public int $orderId) {}

    /**
     * Espera crescente: 10s, 30s, 1min, 2min. Instabilidade da SEFAZ dura
     * minutos, não milissegundos, e insistir na hora só piora a fila.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }

    public function handle(BuildInvoiceFromOrder $montagem, NfeGatewayInterface $gateway): void
    {
        try {
            $nota = $montagem->handle($this->orderId);
        } catch (EmissaoInvalida|PerfilFiscalAusente $e) {
            // Pendência de cadastro: fica registrada e esperando gente.
            $montagem->registrarPendencia($this->orderId, $e->getMessage());

            Log::warning('NF-e não pôde ser montada — pendência de cadastro.', [
                'order_id' => $this->orderId,
                'motivo' => $e->getMessage(),
            ]);

            return;
        }

        // Já autorizada (reentrega do job, reprocesso manual): nada a fazer.
        // A nota autorizada é imutável — retransmitir geraria duplicidade.
        if (! $nota->status->transmissivel()) {
            return;
        }

        try {
            $resultado = $gateway->transmitir($nota);
        } catch (Throwable $e) {
            // Falha de infraestrutura: o número permanece reservado na nota
            // e a fila tenta de novo (docs/14 §3 — timeout não queima
            // número). Sem dado de certificado no log (pasta 25).
            Log::error('Falha ao transmitir NF-e à SEFAZ.', [
                'order_id' => $this->orderId,
                'invoice_id' => $nota->id,
                'erro' => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->registrarRetorno($nota, $resultado->status, $resultado->accessKey, $resultado->protocol, $resultado->rejectionReason, $resultado->xmlPath);
    }

    /**
     * Grava o retorno **antes** de anunciar qualquer coisa.
     *
     * A ordem importa: nota autorizada cujo estado não foi persistido é
     * nota perdida — a SEFAZ já a conhece e o ERP não. Persistir primeiro e
     * disparar o evento depois faz com que, no pior caso, alguém tenha de
     * reprocessar um evento; no caso invertido, alguém teria de descobrir
     * na SEFAZ uma nota que o sistema não sabe que existe.
     */
    private function registrarRetorno(
        Invoice $nota,
        InvoiceStatus $status,
        ?string $accessKey,
        ?string $protocol,
        ?string $motivo,
        ?string $xmlPath,
    ): void {
        $nota->status = $status;
        $nota->access_key = $accessKey ?? $nota->access_key;
        $nota->protocol = $protocol ?? $nota->protocol;
        $nota->rejection_reason = $motivo;
        $nota->xml_path = $xmlPath ?? $nota->xml_path;
        $nota->save();

        match ($status) {
            InvoiceStatus::Authorized => InvoiceAuthorized::dispatch($nota, $nota->order_id),
            InvoiceStatus::Rejected => InvoiceRejected::dispatch($nota, $nota->order_id, $motivo ?? 'Rejeitada sem motivo informado pela SEFAZ.'),
            // `pending` não anuncia nada — é o caso do `NullNfeGateway`
            // hoje. Disparar "autorizada" aqui daria à expedição um sinal
            // verde para uma nota que não existe na SEFAZ, que é exatamente
            // o que o gate BR-309 existe para impedir.
            default => null,
        };
    }
}
