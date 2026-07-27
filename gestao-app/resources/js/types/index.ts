import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    /**
     * Permissões do usuário, no formato `modulo.acao` (pasta 19).
     * Servem para ESCONDER o que a pessoa não pode fazer — a autoridade
     * é sempre a Policy no backend. Use o helper `can()` de `@/lib/permissions`.
     */
    permissions: string[];
}

export interface Flash {
    sucesso?: string;
    erro?: string;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
    /** Telas do mesmo módulo, um nível abaixo. */
    children?: NavItem[];
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: Flash;
    [key: string]: unknown;
}

export interface User {
    public_id: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    status: UserStatus;
    must_change_password: boolean;
    [key: string]: unknown; // This allows for additional properties...
}

/** Espelha App\Modules\Identity\Enums\UserStatus. */
export type UserStatus = 'invited' | 'active' | 'suspended' | 'disabled';

/** Espelha App\Modules\Identity\Enums\Role. */
export type RoleValue = 'admin' | 'production' | 'sales' | 'fulfillment' | 'finance' | 'accountant';

export interface Opcao {
    value: string;
    label: string;
}

export interface OpcaoDePapel extends Opcao {
    requires_two_factor: boolean;
}

/** Uma linha da listagem de usuários (pasta 18). */
export interface UsuarioDaLista {
    public_id: string;
    name: string;
    email: string;
    status: UserStatus;
    status_label: string;
    roles: RoleValue[];
    role_labels: string[];
    requires_two_factor: boolean;
    has_two_factor: boolean;
    last_login_at: string | null;
}

/** Paginação do Laravel, na forma que o Inertia entrega. */
export interface Paginado<T> {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: { url: string | null; label: string; active: boolean }[];
}
