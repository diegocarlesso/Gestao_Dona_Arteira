import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuAction,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage();

    const filhaAberta = (item: NavItem) => (item.children ?? []).some((filho) => page.url.startsWith(filho.url));

    // O item do módulo fica aceso também quando a tela aberta é uma filha
    // dele — sem isso, entrar em "Contagens" apagaria "Estoque" no menu e
    // a pessoa perderia a referência de onde está.
    const ativo = (item: NavItem) => item.url === page.url || filhaAberta(item);

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Gestão</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) =>
                    item.children && item.children.length > 0 ? (
                        <Collapsible
                            key={item.title}
                            asChild
                            // Nasce aberto quando se está dentro dele: chegar
                            // numa tela e não ver no menu onde ela fica é
                            // pior que um grupo a mais aberto.
                            defaultOpen={filhaAberta(item)}
                            className="group/collapsible"
                        >
                            <SidebarMenuItem>
                                {/* O botão continua sendo link — `/estoque` é
                                    uma tela de verdade, não só um rótulo de
                                    grupo. Quem quer a posição clica no nome;
                                    quem quer as filhas, na seta. */}
                                <SidebarMenuButton asChild isActive={ativo(item)} tooltip={item.title}>
                                    <Link href={item.url} prefetch>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>

                                <CollapsibleTrigger asChild>
                                    <SidebarMenuAction className="data-[state=open]:rotate-90">
                                        <ChevronRight />
                                        <span className="sr-only">Mostrar telas de {item.title}</span>
                                    </SidebarMenuAction>
                                </CollapsibleTrigger>

                                <CollapsibleContent>
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
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>
                    ) : (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton asChild isActive={ativo(item)} tooltip={item.title}>
                                <Link href={item.url} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    ),
                )}
            </SidebarMenu>
        </SidebarGroup>
    );
}
