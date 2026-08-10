<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Contracts;

use App\Modules\Fiscal\DTO\NfeTransmissionResult;
use App\Modules\Fiscal\Models\Invoice;

/**
 * O único ponto do sistema que fala com a SEFAZ — ADR-0009/ADR-0015.
 *
 * A interface existe porque o ADR-0009 deixou duas rotas abertas de
 * propósito: `sped-nfe` com certificado A1 próprio (mais barato, mais
 * trabalho a cada Nota Técnica) ou API fiscal gerenciada (mais cara, o
 * fornecedor acompanha as NTs). A reforma tributária vai mexer no layout
 * várias vezes nos próximos anos, e trocar de rota no meio do caminho tem
 * que ser um bind diferente no provider — não uma reescrita do domínio.
 *
 * Por isso o contrato recebe a `Invoice` já montada e devolve um DTO
 * próprio: quem implementa cuida de XML, assinatura, SOAP e retorno; quem
 * chama cuida de estado, evento e guarda. Nenhum detalhe de biblioteca
 * atravessa esta fronteira.
 *
 * Implementação ativa hoje: `Services\Gateways\NullNfeGateway`.
 */
interface NfeGatewayInterface
{
    /**
     * Transmite a nota e devolve o que a SEFAZ respondeu.
     *
     * **Não** lança exceção para rejeição — rejeição é resposta, e o fluxo
     * a trata (corrige o cadastro, reemite com o mesmo número). Exceção
     * fica reservada para o que é falha de fato: rede, certificado
     * inválido, ambiente quebrado — coisas que a fila deve tentar de novo.
     */
    public function transmitir(Invoice $invoice): NfeTransmissionResult;
}
