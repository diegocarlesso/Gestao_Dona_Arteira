<?php

declare(strict_types=1);

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Contracts\CobrancaGatewayInterface;
use App\Modules\Finance\DTO\DadosDoPagador;
use App\Modules\Finance\DTO\EmitirCobrancaComando;
use App\Modules\Finance\Enums\StatusCobranca;
use App\Modules\Finance\Enums\TipoCobranca;
use App\Modules\Finance\Exceptions\CobrancaGatewayIndisponivel;
use App\Modules\Finance\Exceptions\CobrancaInvalida;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Jobs\ProcessarEmissaoCobranca;
use App\Modules\Finance\Models\BillingCharge;
use App\Modules\Finance\Models\BillingProfile;
use App\Modules\Finance\Models\Receivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Emite uma cobrança (PIX com vencimento ou boleto) a partir de um título a
 * receber — BR-505/506/509, docs/12-Financeiro/01-cobranca-e-boletos.md §3.
 *
 * Dois métodos, dois momentos:
 *
 * - `solicitar()` roda na requisição HTTP: valida, cria a `BillingCharge`
 *   local em `pending` e **enfileira** o registro no provedor
 *   (`ProcessarEmissaoCobranca`) — a chamada ao Mercado Pago nunca trava a
 *   resposta ao operador (ADR-0007, princípio 3).
 * - `processar()` roda dentro do job: monta o comando contra
 *   `CobrancaGatewayInterface` e persiste o retorno. Só depende da
 *   interface, nunca de `Integrations` — quem implementa é injetado
 *   (ADR-0018 §Decisão).
 */
class EmitirCobrancaService
{
    public function __construct(
        private readonly CobrancaGatewayInterface $gateway,
    ) {}

    /**
     * @throws TituloInvalido
     * @throws CobrancaInvalida
     */
    public function solicitar(
        int $receivableId,
        TipoCobranca $type,
        DadosDoPagador $payer,
        ?int $profileId = null,
    ): BillingCharge {
        $titulo = Receivable::query()->find($receivableId);

        if ($titulo === null) {
            throw TituloInvalido::tituloInexistente('receivable', $receivableId);
        }

        if (! $titulo->aberto()) {
            throw CobrancaInvalida::tituloJaQuitado($receivableId);
        }

        if ($type->exigeEnderecoDoPagador() && ! $payer->temEnderecoCompleto()) {
            throw CobrancaInvalida::enderecoDoPagadorObrigatorio();
        }

        $jaAtiva = BillingCharge::query()
            ->where('receivable_id', $titulo->id)
            ->whereIn('status', [StatusCobranca::Pendente->value, StatusCobranca::Registrada->value])
            ->exists();

        if ($jaAtiva) {
            throw CobrancaInvalida::jaExisteCobrancaAtiva($receivableId);
        }

        $perfil = $profileId !== null ? BillingProfile::query()->find($profileId) : null;
        $vencimento = $titulo->due_date?->toDateString()
            ?? ($perfil?->days_to_due !== null ? now()->addDays($perfil->days_to_due)->toDateString() : null);

        $cobranca = DB::transaction(function () use ($titulo, $type, $payer, $perfil, $vencimento): BillingCharge {
            return BillingCharge::query()->create([
                'receivable_id' => $titulo->id,
                'profile_id' => $perfil?->id,
                'type' => $type,
                'amount' => $titulo->saldoAberto(),
                'due_date' => $vencimento,
                'status' => StatusCobranca::Pendente,
                'payer_name' => $payer->name,
                'payer_email' => $payer->email,
                'payer_document_type' => $payer->documentType,
                'payer_document_number' => $payer->documentNumber,
                'payer_address_zip' => $payer->addressZip,
                'payer_address_street' => $payer->addressStreet,
                'payer_address_number' => $payer->addressNumber,
                'payer_address_neighborhood' => $payer->addressNeighborhood,
                'payer_address_city' => $payer->addressCity,
                'payer_address_state' => $payer->addressState,
            ]);
        });

        ProcessarEmissaoCobranca::dispatch($cobranca->public_id);

        return $cobranca;
    }

    /**
     * Roda dentro do job. Falha de configuração/validação do provedor vira
     * `status=failed` com motivo — nunca relança (tentar de novo não
     * preenche uma credencial ausente). Qualquer outra exceção (rede, 5xx)
     * sobe para o job tentar de novo com backoff — mesmo desenho do
     * `EmitNfeForOrder` (Fiscal).
     */
    public function processar(string $chargePublicId): void
    {
        $cobranca = BillingCharge::query()->where('public_id', $chargePublicId)->first();

        if ($cobranca === null) {
            Log::error('Financeiro: job de emissão de cobrança recebeu public_id inexistente.', [
                'charge_public_id' => $chargePublicId,
            ]);

            return;
        }

        if ($cobranca->status !== StatusCobranca::Pendente) {
            // Reentrega do job (retry, reprocesso manual) numa cobrança que
            // já saiu de `pending` — nada a fazer, reemitir duplicaria.
            return;
        }

        $comando = new EmitirCobrancaComando(
            chargePublicId: $cobranca->public_id,
            type: $cobranca->type,
            amount: $cobranca->amount,
            dueDate: $cobranca->due_date?->toDateString(),
            payer: new DadosDoPagador(
                name: (string) $cobranca->payer_name,
                email: (string) $cobranca->payer_email,
                documentType: (string) $cobranca->payer_document_type,
                documentNumber: (string) $cobranca->payer_document_number,
                addressZip: $cobranca->payer_address_zip,
                addressStreet: $cobranca->payer_address_street,
                addressNumber: $cobranca->payer_address_number,
                addressNeighborhood: $cobranca->payer_address_neighborhood,
                addressCity: $cobranca->payer_address_city,
                addressState: $cobranca->payer_address_state,
            ),
            instructions: $cobranca->profile?->instructions,
        );

        try {
            $resultado = $this->gateway->emitir($comando);
        } catch (CobrancaGatewayIndisponivel $e) {
            $cobranca->falhar($e->getMessage());

            Log::warning('Financeiro: emissão de cobrança falhou.', [
                'charge_public_id' => $chargePublicId,
                'motivo' => $e->getMessage(),
            ]);

            return;
        }

        $cobranca->registrar($resultado);
    }
}
