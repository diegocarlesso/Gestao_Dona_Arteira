<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * CPF ou CNPJ, validado pelo dígito verificador — BR-001.
 *
 * Mora em `Support/` e não em `Sales/` porque documento não é conceito de
 * cliente: fornecedor, transportadora e o próprio emitente da NF-e usam a
 * mesma regra (ADR-0020 — value object no núcleo compartilhado). Duplicar
 * a validação por módulo criaria versões que divergem, e a que divergir
 * será descoberta pela SEFAZ.
 *
 * **Guarda só dígitos.** A máscara é apresentação: gravar
 * "123.456.789-09" faria a busca por documento depender de a pessoa ter
 * digitado os pontos nos mesmos lugares, e a unicidade do banco deixaria
 * passar o mesmo CPF escrito de dois jeitos.
 */
final readonly class Documento
{
    private function __construct(
        public string $numero,
        public bool $ehPessoaJuridica,
    ) {}

    /**
     * @throws InvalidArgumentException
     */
    public static function de(string $bruto): self
    {
        $digitos = preg_replace('/\D/', '', $bruto) ?? '';

        return match (strlen($digitos)) {
            11 => self::cpf($digitos),
            14 => self::cnpj($digitos),
            default => throw new InvalidArgumentException('Documento precisa ter 11 dígitos (CPF) ou 14 (CNPJ).'),
        };
    }

    public static function valido(string $bruto): bool
    {
        try {
            self::de($bruto);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Com máscara, para leitura humana.
     */
    public function formatado(): string
    {
        return $this->ehPessoaJuridica
            ? preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $this->numero) ?? $this->numero
            : preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $this->numero) ?? $this->numero;
    }

    private static function cpf(string $digitos): self
    {
        // "111.111.111-11" passa na conta dos dígitos verificadores e não
        // é CPF de ninguém. São os erros de digitação mais comuns e o
        // preenchimento preguiçoso de formulário.
        if (preg_match('/^(\d)\1{10}$/', $digitos) === 1) {
            throw new InvalidArgumentException('CPF inválido.');
        }

        foreach ([9, 10] as $posicao) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $digitos[$i] * ($posicao + 1 - $i);
            }

            $resto = ($soma * 10) % 11;
            $esperado = $resto === 10 ? 0 : $resto;

            if ((int) $digitos[$posicao] !== $esperado) {
                throw new InvalidArgumentException('CPF inválido.');
            }
        }

        return new self($digitos, false);
    }

    private static function cnpj(string $digitos): self
    {
        if (preg_match('/^(\d)\1{13}$/', $digitos) === 1) {
            throw new InvalidArgumentException('CNPJ inválido.');
        }

        // Os pesos do CNPJ ciclam de 9 até 2 e recomeçam — não é a mesma
        // progressão simples do CPF.
        foreach ([[12, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]], [13, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]]] as [$posicao, $pesos]) {
            $soma = 0;

            for ($i = 0; $i < $posicao; $i++) {
                $soma += (int) $digitos[$i] * $pesos[$i];
            }

            $resto = $soma % 11;
            $esperado = $resto < 2 ? 0 : 11 - $resto;

            if ((int) $digitos[$posicao] !== $esperado) {
                throw new InvalidArgumentException('CNPJ inválido.');
            }
        }

        return new self($digitos, true);
    }
}
