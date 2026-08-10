<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Services;

use App\Modules\Fiscal\Exceptions\PerfilFiscalAusente;
use App\Modules\Fiscal\Models\TaxProfile;
use Illuminate\Support\Carbon;

/**
 * Resolve o CFOP/CSOSN de uma operação pela matriz — docs/13 §3.
 *
 * O objetivo declarado da pasta 13 é que **o operador nunca escolha CFOP
 * manualmente**. Não é conveniência: CFOP é escolha com consequência
 * fiscal, feita por quem entende de tributo, uma vez, no cadastro — e não
 * por quem está faturando, toda vez, sob pressão do balcão.
 *
 * ⚠️ **Esta resolução vai falhar na primeira emissão de qualquer ambiente
 * novo.** A tabela `tax_profiles` nasce vazia de propósito: as hipóteses da
 * doc 13 (CSOSN 102, CFOP 5101/6101, NCM 6809.90.00) estão marcadas 💡 e
 * bloqueadas por validação do contador — semeá-las na migration as
 * transformaria, seis meses depois, em "o que o sistema sempre usou". Até
 * alguém popular a matriz com os valores validados, toda emissão registra a
 * pendência `PerfilFiscalAusente` e nenhuma nota sai. É o comportamento
 * desejado, não uma regressão.
 *
 * Quando houver mais de um perfil vigente para o mesmo cenário (transição
 * de vigência mal cadastrada, sobreposição), vence o de `valid_from` mais
 * recente — a regra mais nova é a que o contador acabou de decidir.
 */
class ResolveTaxProfile
{
    /**
     * @param  string  $operationType  `TaxProfile::OPERACAO_*`
     * @param  string  $destination  `TaxProfile::DESTINO_*`
     * @param  string  $customerType  `TaxProfile::CLIENTE_*`
     * @param  Carbon|null  $em  data de referência da vigência; hoje, por padrão
     *
     * @throws PerfilFiscalAusente
     */
    public function handle(
        string $operationType,
        string $destination,
        string $customerType,
        ?Carbon $em = null,
    ): TaxProfile {
        $data = $em ?? Carbon::now();

        $perfil = TaxProfile::query()
            ->where('operation_type', $operationType)
            ->where('destination', $destination)
            ->where('customer_type', $customerType)
            ->vigenteEm($data)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();

        if ($perfil === null) {
            throw PerfilFiscalAusente::para(
                $operationType,
                $destination,
                $customerType,
                $data->toDateString(),
            );
        }

        return $perfil;
    }
}
