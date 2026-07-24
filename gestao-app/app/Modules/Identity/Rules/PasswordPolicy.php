<?php

declare(strict_types=1);

namespace App\Modules\Identity\Rules;

use Illuminate\Validation\Rules\Password;

/**
 * A política de senha do ERP, em um só lugar (pasta 18 §3).
 *
 * Existe porque havia duas: o `ForcedPasswordController` exigia 12
 * caracteres e verificação contra vazamentos, e o
 * `Settings\PasswordController` do starter kit aceitava o
 * `Password::defaults()` — 8 caracteres, sem checagem. Quem trocasse a
 * senha pela tela de configurações escapava da regra que a documentação
 * afirma valer, e nada reprovava.
 *
 * Regra que mora em dois lugares é regra que vai divergir. Aqui há um
 * teste conferindo que os dois caminhos usam esta classe.
 */
class PasswordPolicy
{
    public const MINIMO_DE_CARACTERES = 12;

    /*
     * Não há regra de composição (maiúscula + minúscula + número +
     * especial), e a ausência é deliberada — ver pasta 18 §3.5.
     *
     * O NIST SP 800-63B desaconselha composição obrigatória: ela produz
     * o mesmo punhado de padrões previsíveis (`Nome123!`) sem entropia
     * real, enquanto o comprimento entrega o ganho de verdade. Baixar
     * para 8 com composição foi considerado e recusado pelo dono em
     * 2026-07-24: seria uma senha mais fraca com aparência de mais
     * rigorosa.
     *
     * Se alguém for adicionar `->mixedCase()->numbers()->symbols()`
     * aqui, o pedido precisa vir com um requisito externo que o exija
     * (cliente, contador, compliance) — e aí some, não substitua os 12.
     */

    /**
     * @return list<Password>
     */
    public static function rules(): array
    {
        return [
            // `uncompromised` consulta o HaveIBeenPwned por k-anonymity:
            // só os cinco primeiros caracteres do hash SHA-1 saem daqui,
            // nunca a senha nem o hash completo. Uma senha que já apareceu
            // em vazamento é uma senha pública, por mais longa que seja.
            Password::min(self::MINIMO_DE_CARACTERES)->uncompromised(),
        ];
    }

    /**
     * As regras completas para um campo de senha nova, com confirmação.
     *
     * @return list<string|Password>
     */
    public static function validationRules(): array
    {
        return ['required', ...self::rules(), 'confirmed'];
    }
}
