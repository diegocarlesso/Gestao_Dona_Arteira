<?php

declare(strict_types=1);

use App\Modules\Fiscal\DTO\NfeTransmissionResult;
use App\Modules\Fiscal\Enums\InvoiceStatus;

/*
|--------------------------------------------------------------------------
| Vocabulário do retorno da SEFAZ — sem banco, sem container
|--------------------------------------------------------------------------
|
| O que o resto do módulo assume sobre estes dois tipos: `transmissivel()`
| decide se o job tenta de novo, e `valeComoDocumento()` é a pergunta do
| gate BR-309. Se qualquer um dos dois mudar de sentido, o efeito aparece
| longe daqui — daí valerem teste próprio.
|
*/

it('autorizado exige chave e protocolo', function () {
    $r = NfeTransmissionResult::autorizado('4326...', '1432600', 'nfe/x.xml');

    expect($r->status)->toBe(InvoiceStatus::Authorized)
        ->and($r->accessKey)->toBe('4326...')
        ->and($r->protocol)->toBe('1432600')
        ->and($r->xmlPath)->toBe('nfe/x.xml')
        ->and($r->rejectionReason)->toBeNull();
});

it('rejeitado carrega o motivo e nenhuma prova de autorização', function () {
    $r = NfeTransmissionResult::rejeitado('778: NCM inválido');

    expect($r->status)->toBe(InvoiceStatus::Rejected)
        ->and($r->rejectionReason)->toBe('778: NCM inválido')
        ->and($r->accessKey)->toBeNull()
        ->and($r->protocol)->toBeNull();
});

it('pendente é resposta legítima, não ausência de resposta', function () {
    $r = NfeTransmissionResult::pendente('em processamento');

    expect($r->status)->toBe(InvoiceStatus::Pending)
        ->and($r->rejectionReason)->toBe('em processamento');
});

it('só a autorizada vale como documento fiscal (BR-309)', function () {
    expect(InvoiceStatus::Authorized->valeComoDocumento())->toBeTrue()
        ->and(InvoiceStatus::Pending->valeComoDocumento())->toBeFalse()
        ->and(InvoiceStatus::Rejected->valeComoDocumento())->toBeFalse()
        ->and(InvoiceStatus::Cancelled->valeComoDocumento())->toBeFalse();
});

it('pendente e rejeitada podem ser (re)transmitidas; autorizada e cancelada, não', function () {
    // Rejeitada reemite com o MESMO número (BR-602); autorizada é imutável.
    expect(InvoiceStatus::Pending->transmissivel())->toBeTrue()
        ->and(InvoiceStatus::Rejected->transmissivel())->toBeTrue()
        ->and(InvoiceStatus::Authorized->transmissivel())->toBeFalse()
        ->and(InvoiceStatus::Cancelled->transmissivel())->toBeFalse();
});
