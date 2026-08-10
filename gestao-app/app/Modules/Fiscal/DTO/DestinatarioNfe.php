<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\DTO;

use App\Modules\Fiscal\Exceptions\EmissaoInvalida;
use App\Modules\Sales\DTO\OrderInvoiceAddress;
use App\Modules\Sales\DTO\OrderInvoiceSnapshot;

/**
 * Quem recebe a nota — grupos `dest`/`enderDest` do layout 4.00.
 *
 * O par do `EmitenteNfe`: traduz o retrato que Vendas publica
 * (`OrderInvoiceSnapshot`) para o vocabulário do XML, e faz isso **aqui**,
 * dentro do Fiscal, porque `indIEDest` e a regra de CPF/CNPJ são conceito
 * fiscal — Vendas não deve aprender o layout da SEFAZ para responder quem
 * é o cliente (mesmo argumento do `tipoDeCliente` em
 * `BuildInvoiceFromOrder`).
 *
 * O nome do destinatário **não** é decidido aqui: em homologação a SEFAZ
 * exige a razão social fixa de teste (BR-605), e essa é uma decisão de
 * quem monta o XML, que conhece o `tpAmb`.
 */
final readonly class DestinatarioNfe
{
    /** Contribuinte de ICMS — tem inscrição estadual. */
    public const IE_CONTRIBUINTE = 1;

    /** Não contribuinte: o caso do consumidor final que compra no site. */
    public const IE_NAO_CONTRIBUINTE = 9;

    public function __construct(
        public string $nome,
        /** CPF (11) ou CNPJ (14), só dígitos. */
        public string $documento,
        public ?string $email,
        public int $indIEDest,
        public ?string $inscricaoEstadual,
        public string $logradouro,
        public string $numero,
        public ?string $complemento,
        public string $bairro,
        /** Código IBGE de 7 dígitos — `enderDest/cMun`, obrigatório. */
        public string $codigoMunicipio,
        public string $municipio,
        public string $uf,
        public string $cep,
    ) {}

    /**
     * Traduz o retrato do pedido.
     *
     * Recebe o código IBGE do município por fora porque **o cadastro de
     * endereços de Vendas ainda não o tem** — quem chama é obrigado a
     * resolvê-lo (e hoje só sabe falhar). Deixar o parâmetro explícito é
     * melhor que aceitar nulo e descobrir a falta lá dentro do XML.
     *
     * @throws EmissaoInvalida
     */
    public static function doPedido(OrderInvoiceSnapshot $pedido, string $codigoMunicipio): self
    {
        $documento = preg_replace('/\D/', '', (string) $pedido->customerDocument) ?? '';
        $endereco = $pedido->shippingAddress;
        $nome = $pedido->customerName ?? '';

        // As três guardas abaixo repetem o que `BuildInvoiceFromOrder`
        // já pré-validou. É repetição de propósito: este DTO também é
        // construído em teste e pode ser construído por outro caminho no
        // futuro, e um destinatário meio montado vira rejeição na SEFAZ em
        // vez de pendência no painel.
        if ($nome === '') {
            throw EmissaoInvalida::semCliente($pedido->orderNumber);
        }

        if ($documento === '') {
            throw EmissaoInvalida::clienteSemDocumento($nome);
        }

        if (! $endereco instanceof OrderInvoiceAddress) {
            throw EmissaoInvalida::clienteSemEndereco($nome);
        }

        $ie = self::inscricao($pedido->customerStateRegistration);
        $pessoaJuridica = strlen($documento) === 14;

        // PJ sem IE é ambíguo: pode ser isento (indIEDest=2) ou não
        // contribuinte (9), e a escolha muda a tributação da operação
        // interestadual. Recusar é mais barato que acertar por sorte.
        if ($pessoaJuridica && $ie === null) {
            throw EmissaoInvalida::destinatarioSemInscricaoEstadual($nome);
        }

        return new self(
            nome: $nome,
            documento: $documento,
            email: $pedido->customerEmail,
            indIEDest: $ie === null ? self::IE_NAO_CONTRIBUINTE : self::IE_CONTRIBUINTE,
            inscricaoEstadual: $ie,
            logradouro: $endereco->street,
            // `nro` é obrigatório no layout e o cadastro aceita vazio
            // (apartamento sem número, condomínio). "SN" é o que a SEFAZ
            // espera nesse caso — não é um número inventado, é a ausência
            // declarada do jeito que o layout prevê.
            numero: self::preenchido($endereco->number) ?? 'SN',
            complemento: self::preenchido($endereco->complement),
            bairro: self::preenchido($endereco->district) ?? $endereco->city,
            codigoMunicipio: $codigoMunicipio,
            municipio: $endereco->city,
            uf: strtoupper(trim($endereco->state)),
            cep: preg_replace('/\D/', '', $endereco->zip) ?? '',
        );
    }

    public function pessoaFisica(): bool
    {
        return strlen($this->documento) === 11;
    }

    /**
     * "ISENTO" e afins não são inscrição: são a ausência dela escrita à
     * mão no cadastro. Tratar como IE mandaria a palavra para dentro do
     * XML e voltaria rejeitada.
     */
    private static function inscricao(?string $valor): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $valor) ?? '';

        return $digitos === '' ? null : $digitos;
    }

    private static function preenchido(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
