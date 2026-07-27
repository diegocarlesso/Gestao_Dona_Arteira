import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage();

    // O item do módulo fica aceso também quando a tela aberta é uma filha
    // dele — sem isso, entrar em "Contagens" apagaria "Estoque" no menu e
    // a pessoa perderia a referência de onde está.
    const ativo = (item: NavItem) => item.url === page.url || (item.children ?? []).some((filho) => page.url.startsWith(filho.url));

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Gestão</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={ativo(item)}>
                            <Link href={item.url} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>

                        {item.children && item.children.length > 0 && (
                            <SidebarMenuSub>
                                {item.children.map((filho) => (
                                    <SidebarMenuSubItem key={filho.title}>
                                        <SidebarMenuSubButton asChild isActive={page.url.startsWith(filho.url)}>
                                            <Link href={filho.url} prefetch>
                                                <span>{filho.title}</span>
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                ))}
                            </SidebarMenuSub>
                        )}
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
