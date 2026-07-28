import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { usePermissions } from '@/lib/permissions';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { Boxes, LayoutGrid, Package, ShoppingCart, Users } from 'lucide-react';
import AppLogo from './app-logo';

type ItemDoMenu = NavItem & { permissao?: string; children?: ItemDoMenu[] };

/**
 * Itens do menu, cada um com a permissão que o revela.
 *
 * Esconder ≠ proteger: quem digitar a URL continua sendo barrado pela
 * Policy no backend (pasta 19 §1). O menu só evita oferecer o que a
 * pessoa receberia um 403 ao tentar.
 *
 * **Módulo com mais de uma tela ganha filhas em vez de outra linha na
 * raiz.** A contagem de estoque ficou inalcançável até 2026-07-27 por não
 * estar em lugar nenhum do menu — e uma tela que só se abre digitando a
 * URL não existe para quem opera.
 */
const ITENS: ItemDoMenu[] = [
    {
        title: 'Painel',
        url: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Produtos',
        url: '/produtos',
        icon: Package,
        permissao: 'catalog.view',
        children: [
            {
                title: 'Nomes repetidos',
                url: '/relatorios/produtos-repetidos',
                permissao: 'reports.view',
            },
        ],
    },
    {
        title: 'Estoque',
        url: '/estoque',
        icon: Boxes,
        permissao: 'inventory.view',
        children: [
            {
                title: 'Contagens',
                url: '/estoque/contagens',
                permissao: 'inventory.view',
            },
        ],
    },
    {
        title: 'Vendas',
        url: '/pedidos',
        icon: ShoppingCart,
        permissao: 'sales.view',
        children: [
            {
                title: 'Clientes',
                url: '/clientes',
                permissao: 'sales.view',
            },
        ],
    },
    {
        title: 'Usuários',
        url: '/usuarios',
        icon: Users,
        permissao: 'users.manage',
    },
];

export function AppSidebar() {
    const { can } = usePermissions();

    const permitido = (item: ItemDoMenu) => item.permissao === undefined || can(item.permissao);

    // A filha é filtrada pela permissão dela, não pela da mãe: quem vê
    // produtos não necessariamente vê relatório, e oferecer o link seria
    // prometer um 403.
    const visiveis = ITENS.filter(permitido).map((item) => ({
        ...item,
        children: (item.children ?? []).filter(permitido),
    }));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={visiveis} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
