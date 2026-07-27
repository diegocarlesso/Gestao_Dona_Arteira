<?php

declare(strict_types=1);

namespace App\Support\Rules;

use App\Support\Documento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regra de validação para CPF/CNPJ — BR-001.
 *
 * Fina de propósito: a regra mora no value object `Documento`, e esta
 * classe só a empresta ao validador do Laravel. Duas implementações da
 * mesma conta divergiriam.
 */
class DocumentoValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! Documento::valido($value)) {
            $fail('O CPF/CNPJ informado não é válido.');
        }
    }
}
