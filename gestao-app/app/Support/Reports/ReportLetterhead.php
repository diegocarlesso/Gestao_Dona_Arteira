<?php

declare(strict_types=1);

namespace App\Support\Reports;

/**
 * O que o timbre de um relatório em PDF precisa — logo e identificação
 * legal da empresa — ADR-0028.
 *
 * Não pertence a nenhum módulo (`Support não depende de módulo algum`,
 * `tests/Architecture/ModulesTest.php`): todo relatório de qualquer
 * módulo usa o mesmo timbre, e a marca não é conceito de um módulo só.
 *
 * **Reaproveita `config('fiscal.emitente.*')`** em vez de duplicar CNPJ/
 * razão social numa config nova — é a mesma empresa, e esses campos já
 * são a fonte canônica desse dado para a NF-e (docs/13-Fiscal). Enquanto
 * o `.env` de produção não tiver `NFE_EMITENTE_*` (aguardando o
 * certificado/contador, ADR-0025), o timbre degrada graciosamente: só a
 * logo e o nome "Dona Arteira", sem CNPJ.
 */
class ReportLetterhead
{
    /**
     * A logo como data URI — dompdf não precisa resolver caminho nem
     * `isRemoteEnabled`, o PNG vai embutido no próprio HTML.
     */
    public function logoBase64(): string
    {
        $caminho = resource_path('js/assets/donaarteira-logo.png');

        return 'data:image/png;base64,'.base64_encode((string) file_get_contents($caminho));
    }

    /**
     * @return array{nome: string, razaoSocial: ?string, cnpj: ?string, endereco: ?string}
     */
    public function empresa(): array
    {
        $cnpj = $this->digitos((string) config('fiscal.emitente.cnpj', ''));

        return [
            'nome' => 'Dona Arteira',
            'razaoSocial' => $this->vazioParaNulo((string) config('fiscal.emitente.razao_social', '')),
            'cnpj' => $cnpj !== '' ? $this->formatarCnpj($cnpj) : null,
            'endereco' => $this->enderecoFormatado(),
        ];
    }

    private function enderecoFormatado(): ?string
    {
        $logradouro = (string) config('fiscal.emitente.endereco.logradouro', '');
        $municipio = (string) config('fiscal.emitente.endereco.municipio', '');

        if ($logradouro === '' && $municipio === '') {
            return null;
        }

        $numero = (string) config('fiscal.emitente.endereco.numero', '');
        $bairro = (string) config('fiscal.emitente.endereco.bairro', '');

        $partes = array_filter([
            trim("{$logradouro}, {$numero}"),
            $bairro,
            $municipio,
        ], static fn (string $p): bool => trim($p, ', ') !== '');

        return implode(' — ', $partes);
    }

    private function digitos(string $valor): string
    {
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    private function formatarCnpj(string $digitos): ?string
    {
        if (mb_strlen($digitos) !== 14) {
            return null;
        }

        return preg_replace(
            '/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/',
            '$1.$2.$3/$4-$5',
            $digitos
        );
    }

    private function vazioParaNulo(string $valor): ?string
    {
        return $valor === '' ? null : $valor;
    }
}
