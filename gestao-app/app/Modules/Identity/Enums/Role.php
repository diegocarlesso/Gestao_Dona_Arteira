<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Os papéis do ERP e o conjunto de permissões de cada um.
 *
 * `permissions()` é a transcrição literal da matriz de
 * docs/19-Permissoes/README.md §3 — uma coluna daquela tabela por caso
 * deste enum. O teste MatrizPapelPermissaoTest confere célula a célula
 * contra o documento, de modo que os dois não conseguem divergir em
 * silêncio.
 *
 * Papéis são acumuláveis (pasta 18 §2): a mesma pessoa pode ser `sales`
 * e `fulfillment`, e recebe a união das permissões.
 */
enum Role: string
{
    case Admin = 'admin';
    case Production = 'production';
    case Sales = 'sales';
    case Fulfillment = 'fulfillment';
    case Finance = 'finance';
    case Accountant = 'accountant';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrador',
            self::Production => 'Produção',
            self::Sales => 'Vendas',
            self::Fulfillment => 'Expedição',
            self::Finance => 'Financeiro',
            self::Accountant => 'Contador',
        };
    }

    /**
     * 2FA obrigatório para admin e financeiro (BR-804).
     */
    public function requiresTwoFactor(): bool
    {
        return match ($this) {
            self::Admin, self::Finance => true,
            default => false,
        };
    }

    /**
     * As permissões do papel — a coluna correspondente da matriz da pasta 19.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            // Admin tem tudo. Não é uma lista literal de propósito: uma
            // permissão nova nasceria fora do alcance de quem administra
            // o sistema, e a falta só apareceria em produção.
            self::Admin => Permission::cases(),

            self::Production => [
                Permission::CatalogView,
                Permission::InventoryView,
                Permission::InventoryMove,
                Permission::ProductionView,
                Permission::ProductionExecute,
            ],

            self::Sales => [
                Permission::CatalogView,
                Permission::InventoryView,
                Permission::ProductionView,
                Permission::SalesView,
                Permission::SalesCreate,
                // Restrita por Policy aos próprios pedidos, antes da
                // expedição — ver pasta 19 §3, nota (*).
                Permission::SalesCancel,
                Permission::FulfillmentExecute,
                Permission::FiscalView,
                Permission::FiscalEmit,
            ],

            self::Fulfillment => [
                Permission::CatalogView,
                Permission::InventoryView,
                Permission::InventoryMove,
                Permission::ProductionView,
                Permission::SalesView,
                Permission::FulfillmentExecute,
                Permission::FiscalView,
                Permission::FiscalEmit,
            ],

            self::Finance => [
                Permission::CatalogView,
                Permission::InventoryView,
                Permission::SalesView,
                Permission::FinanceView,
                Permission::FinanceSettle,
                Permission::FinanceManage,
                Permission::FiscalView,
                Permission::ReportsView,
            ],

            // Somente leitura, restrito a fiscal e relatórios (BR-803).
            // Nenhuma permissão de escrita aparece aqui, e o teste da
            // matriz reprova se alguma for acrescentada.
            self::Accountant => [
                Permission::CatalogView,
                Permission::FiscalView,
                // Restrita por Policy aos relatórios fiscais — pasta 19
                // §3, nota (**).
                Permission::ReportsView,
            ],
        };
    }

    /**
     * @return list<string>
     */
    public function permissionValues(): array
    {
        return array_map(
            static fn (Permission $p): string => $p->value,
            $this->permissions(),
        );
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
