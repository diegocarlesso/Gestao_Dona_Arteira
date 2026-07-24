<?php

declare(strict_types=1);

namespace App\Modules\Identity\Events;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Um código de recuperação autenticou uma entrada (ADR-0021).
 *
 * O sinal de "perdi o celular" ou "o aplicativo parou" — e também o
 * caminho que alguém de posse da lista de códigos usaria. Por isso é
 * sensível na pasta 26, ao contrário de um TOTP válido, que é o ruído de
 * fundo de um sistema saudável e não gera evento próprio.
 *
 * Quantos códigos restam vai no contexto: chegar perto de zero sem
 * regenerar é uma conta prestes a se trancar do lado de fora.
 */
class RecoveryCodeUsed
{
    use Dispatchable;

    public function __construct(
        public readonly User $usuario,
        public readonly int $restantes,
    ) {}
}
