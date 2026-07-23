<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * As permissões granulares do ERP, no formato `modulo.acao`.
 *
 * Fonte da verdade: docs/19-Permissoes/README.md §2. Criar uma permissão
 * nova exige atualizar aquele documento no mesmo PR (ADR-0011) — sem
 * isso, a matriz publicada deixa de descrever o sistema real.
 *
 * BR-801: acesso é negado por padrão. Não existir aqui significa que
 * ninguém pode fazer, nem o admin.
 */
enum Permission: string
{
    case CatalogView = 'catalog.view';
    case CatalogManage = 'catalog.manage';

    case InventoryView = 'inventory.view';
    case InventoryMove = 'inventory.move';
    case InventoryAdjust = 'inventory.adjust';

    case ProductionView = 'production.view';
    case ProductionExecute = 'production.execute';
    case ProductionManage = 'production.manage';

    case SalesView = 'sales.view';
    case SalesCreate = 'sales.create';
    case SalesCancel = 'sales.cancel';
    case SalesDiscountApprove = 'sales.discount.approve';

    case FulfillmentExecute = 'fulfillment.execute';

    case PurchasingManage = 'purchasing.manage';

    case FinanceView = 'finance.view';
    case FinanceSettle = 'finance.settle';
    case FinanceManage = 'finance.manage';

    case FiscalView = 'fiscal.view';
    case FiscalEmit = 'fiscal.emit';
    case FiscalCancel = 'fiscal.cancel';

    case ReportsView = 'reports.view';

    case IntegrationsManage = 'integrations.manage';
    case UsersManage = 'users.manage';
    case AuditView = 'audit.view';

    /**
     * O texto que o usuário lê na tela de papéis (pt-BR, pasta 18).
     */
    public function label(): string
    {
        return match ($this) {
            self::CatalogView => 'Ver catálogo',
            self::CatalogManage => 'Gerenciar catálogo',
            self::InventoryView => 'Ver estoque',
            self::InventoryMove => 'Movimentar estoque',
            self::InventoryAdjust => 'Ajustar estoque',
            self::ProductionView => 'Ver produção',
            self::ProductionExecute => 'Apontar produção',
            self::ProductionManage => 'Gerenciar produção',
            self::SalesView => 'Ver pedidos',
            self::SalesCreate => 'Criar pedidos',
            self::SalesCancel => 'Cancelar pedidos',
            self::SalesDiscountApprove => 'Aprovar desconto acima da alçada',
            self::FulfillmentExecute => 'Separar e expedir',
            self::PurchasingManage => 'Gerenciar compras',
            self::FinanceView => 'Ver financeiro',
            self::FinanceSettle => 'Baixar títulos',
            self::FinanceManage => 'Gerenciar financeiro',
            self::FiscalView => 'Ver documentos fiscais',
            self::FiscalEmit => 'Emitir NF-e',
            self::FiscalCancel => 'Cancelar NF-e',
            self::ReportsView => 'Ver relatórios',
            self::IntegrationsManage => 'Gerenciar integrações',
            self::UsersManage => 'Gerenciar usuários',
            self::AuditView => 'Ver trilha de auditoria',
        };
    }

    /**
     * O módulo a que a permissão pertence — o prefixo antes do ponto.
     *
     * Serve para agrupar a tela de papéis e para conferir, em teste, que
     * nenhuma permissão nasceu fora do padrão `modulo.acao`.
     */
    public function module(): string
    {
        return explode('.', $this->value)[0];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
