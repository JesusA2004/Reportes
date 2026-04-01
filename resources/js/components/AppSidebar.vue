<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    LayoutGrid,
    CalendarRange,
    Upload,
    Database,
    Building2,
    Users,
    GitCompareArrows,
    Wallet,
    BadgeDollarSign,
    PiggyBank,
    ReceiptText,
    FileSpreadsheet,
    BarChart3,
    Settings,
    ShieldCheck,
    ClipboardList,
    FolderCog,
} from 'lucide-vue-next';

import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },

    // OPERACIÓN
    {
        title: 'Histórico general',
        href: '/historico-general',
        icon: Upload,
    },
    {
        title: 'Periodos',
        href: '/periodos',
        icon: CalendarRange,
    },
    {
        title: 'Procesos',
        href: '/procesos',
        icon: ClipboardList,
    },

    // CATÁLOGOS
    {
        title: 'Sucursales',
        href: '/sucursales',
        icon: Building2,
    },
    {
        title: 'Empleados',
        href: '/empleados',
        icon: Users,
    },
    {
        title: 'Fuentes de datos',
        href: '/fuentes',
        icon: Database,
    },
    {
        title: 'Productos financieros',
        href: '/productos-financieros',
        icon: FolderCog,
    },

    // CONCILIACIÓN / MATCH
    {
        title: 'Asignación sucursal',
        href: '/asignaciones-empleado-sucursal',
        icon: GitCompareArrows,
    },
    {
        title: 'Validaciones',
        href: '/validaciones',
        icon: ShieldCheck,
    },

    // DATOS OPERATIVOS
    {
        title: 'Nómina NOI',
        href: '/noi-movimientos',
        icon: Wallet,
    },
    {
        title: 'Colocación',
        href: '/colocaciones',
        icon: BadgeDollarSign,
    },
    {
        title: 'Recuperación',
        href: '/recuperaciones',
        icon: PiggyBank,
    },
    {
        title: 'Cartera',
        href: '/cartera',
        icon: BarChart3,
    },
    {
        title: 'Gastos',
        href: '/gastos',
        icon: ReceiptText,
    },

    // RESULTADOS
    {
        title: 'Reportes mensuales',
        href: '/reportes-mensuales',
        icon: FileSpreadsheet,
    },

    // ADMINISTRACIÓN
    {
        title: 'Configuración',
        href: '/settings/profile',
        icon: Settings,
    },
];

const footerNavItems: NavItem[] = [];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>

    <slot />
</template>
