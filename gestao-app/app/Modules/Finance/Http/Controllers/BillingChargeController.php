<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Finance\DTO\DadosDoPagador;
use App\Modules\Finance\Enums\TipoCobranca;
use App\Modules\Finance\Exceptions\CobrancaInvalida;
use App\Modules\Finance\Exceptions\TituloInvalido;
use App\Modules\Finance\Models\BillingCharge;
use App\Modules\Finance\Models\Payable;
use App\Modules\Finance\Models\Receivable;
use App\Modules\Finance\Services\CancelarCobrancaService;
use App\Modules\Finance\Services\EmitirCobrancaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Emitir/cancelar cobrança a partir de um título a receber — ADR-0018,
 * docs/12-Financeiro/01-cobranca-e-boletos.md §3.
 *
 * Reaproveita a habilidade `settle` do `TitlePolicy` (registrada em
 * `Payable`/`Receivable`): emitir ou cancelar cobrança é, na prática, o
 * mesmo nível de acesso de dar baixa — mexe com dinheiro do título.
 */
class BillingChargeController extends Controller
{
    public function store(Request $request, string $publicId, EmitirCobrancaService $service): RedirectResponse
    {
        $this->authorize('settle', Payable::class);

        $dados = $request->validate([
            'type' => ['required', 'string', 'in:pix,boleto'],
            'profile_id' => ['nullable', 'integer'],
            'payer_name' => ['required', 'string', 'max:190'],
            'payer_email' => ['required', 'email', 'max:190'],
            'payer_document_type' => ['required', 'string', 'in:CPF,CNPJ'],
            'payer_document_number' => ['required', 'string', 'max:20'],
            'payer_address_zip' => ['nullable', 'string', 'max:10'],
            'payer_address_street' => ['nullable', 'string', 'max:190'],
            'payer_address_number' => ['nullable', 'string', 'max:20'],
            'payer_address_neighborhood' => ['nullable', 'string', 'max:120'],
            'payer_address_city' => ['nullable', 'string', 'max:120'],
            'payer_address_state' => ['nullable', 'string', 'max:2'],
        ]);

        try {
            $titulo = Receivable::query()->where('public_id', $publicId)->first();

            if ($titulo === null) {
                throw TituloInvalido::tituloInexistente('receivable', 0);
            }

            $service->solicitar(
                receivableId: $titulo->id,
                type: TipoCobranca::from($dados['type']),
                payer: new DadosDoPagador(
                    name: $dados['payer_name'],
                    email: $dados['payer_email'],
                    documentType: $dados['payer_document_type'],
                    documentNumber: $dados['payer_document_number'],
                    addressZip: $dados['payer_address_zip'] ?? null,
                    addressStreet: $dados['payer_address_street'] ?? null,
                    addressNumber: $dados['payer_address_number'] ?? null,
                    addressNeighborhood: $dados['payer_address_neighborhood'] ?? null,
                    addressCity: $dados['payer_address_city'] ?? null,
                    addressState: $dados['payer_address_state'] ?? null,
                ),
                profileId: isset($dados['profile_id']) ? (int) $dados['profile_id'] : null,
            );
        } catch (TituloInvalido|CobrancaInvalida $e) {
            return back()->withErrors(['cobranca' => $e->getMessage()]);
        }

        return back()->with('sucesso', 'Cobrança solicitada — o registro no provedor roda em segundo plano.');
    }

    public function cancel(BillingCharge $charge, CancelarCobrancaService $service): RedirectResponse
    {
        $this->authorize('settle', Payable::class);

        try {
            $service->solicitar($charge->public_id);
        } catch (CobrancaInvalida $e) {
            return back()->withErrors(['cobranca' => $e->getMessage()]);
        }

        return back()->with('sucesso', 'Cancelamento solicitado.');
    }
}
