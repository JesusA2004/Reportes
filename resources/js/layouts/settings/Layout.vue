<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const sidebarNavItems: NavItem[] = [
    {
        title: 'Perfil',
        href: editProfile(),
    },
    {
        title: 'Seguridad',
        href: editSecurity(),
    },
    {
        title: 'Apariencia',
        href: editAppearance(),
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();
</script>

<template>
    <section class="space-y-8 rounded-3xl border border-sidebar-border/70 bg-background p-5 shadow-sm sm:p-8">
        <Heading
            title="Configuración"
            description="Administra la información de tu cuenta, la seguridad y la apariencia del sistema."
        />

        <div class="grid gap-8 lg:grid-cols-[240px_minmax(0,1fr)] lg:items-start">
            <aside>
                <nav
                    aria-label="Configuración"
                    class="grid gap-2 rounded-2xl border border-sidebar-border/70 bg-muted/30 p-2"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        as-child
                        variant="ghost"
                        :class="[
                            'h-11 justify-start rounded-xl px-4 text-sm font-medium transition-all',
                            isCurrentOrParentUrl(item.href)
                                ? 'bg-primary text-primary-foreground shadow-sm hover:bg-primary/90'
                                : 'text-muted-foreground hover:bg-background hover:text-foreground'
                        ]"
                    >
                        <Link :href="item.href">
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="lg:hidden" />

            <main class="min-w-0">
                <section class="space-y-6 rounded-2xl border border-sidebar-border/70 bg-card/60 p-4 sm:p-6">
                    <slot />
                </section>
            </main>
        </div>
    </section>
</template>
