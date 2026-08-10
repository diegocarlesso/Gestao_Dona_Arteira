<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Services\Gateways;

use App\Modules\Fiscal\Contracts\NfeGatewayInterface;
use App\Modules\Fiscal\DTO\NfeTransmissionResult;
use App\Modules\Fiscal\Models\Invoice;
use Illuminate\Support\Facades\Log;

/**
 * O gateway que **não transmite nada** — e diz isso em voz alta.
 *
 * Implementação ativa enquanto duas coisas não acontecerem:
 *
 * 1. **Pré-flight de ambiente** (docs/14 §6): confirmar `openssl`, `soap`,
 *    `curl` e `dom` no Hostinger *de fato*, executando código no servidor —
 *    o painel da hospedagem aceita configuração de PHP sem aplicá-la, e
 *    descobrir isso depois de instalar o `sped-nfe` seria descobrir tarde.
 * 2. **Validação fiscal** (docs/13, bloqueada no contador): NCM por linha,
 *    CFOP, CSOSN e o próprio regime ainda são hipóteses.
 *
 * Devolver `pending` em vez de fingir `authorized` é a decisão inteira
 * deste arquivo. Um fake otimista deixaria o pipeline "verde", o pedido
 * marcado como faturado e a expedição liberada por uma nota que não existe
 * na SEFAZ — o modo de falha exato que o gate BR-309 existe para impedir.
 * Pendente é a verdade: a nota está registrada, numerada, e esperando
 * transmissão.
 *
 * Trocar por `SpedNfeGateway` é uma linha no `FiscalServiceProvider`.
 */
class NullNfeGateway implements NfeGatewayInterface
{
    public const MOTIVO = 'sped-nfe ainda não integrado — aguardando pré-flight de ambiente '
        .'(docs/14-NFe §6: openssl, soap, curl, dom) e validação fiscal com o contador '
        .'(docs/13-Fiscal, bloqueado). A nota está registrada e numerada, mas NÃO foi transmitida.';

    public function transmitir(Invoice $invoice): NfeTransmissionResult
    {
        // `warning` e não `info`: uma nota que fica pendente porque o
        // emissor não está plugado precisa doer no log de quem opera. Se
        // isto aparecer em produção, alguém ligou `NFE_ENABLED` sem ter
        // trocado o gateway.
        Log::warning('NF-e não transmitida: gateway real ainda não integrado (ADR-0025).', [
            'invoice_id' => $invoice->id,
            'order_id' => $invoice->order_id,
            'number' => $invoice->number,
            'environment' => config('fiscal.nfe.environment'),
        ]);

        return NfeTransmissionResult::pendente(self::MOTIVO);
    }
}
