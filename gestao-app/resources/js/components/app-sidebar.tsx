import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { usePermissions } from '@/lib/permissions';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { LayoutGrid, Package, Users } from 'lucide-react';
import AppLogo from './app-logo';

/**
 * Itens do menu, cada um com a permissão que o revela.
 *
 * Esconder ≠ proteger: quem digitar a URL continua sendo barrado pela
 * Policy no backend (pasta 19 §1). O menu só evita oferecer o que a
 * pessoa receberia um 403 ao tentar.
 */
const ITENS: (NavItem & { permissao?: string })[] = [
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

    const visiveis = ITENS.filter((item) => item.permissao === undefined || can(item.permissao));

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
