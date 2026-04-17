<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Apariencia',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Apariencia" />

    <section class="space-y-6 rounded-3xl border border-sidebar-border/70 bg-background p-6 shadow-sm sm:p-8">
        <h1 class="sr-only">Configuración de apariencia</h1>

        <Heading
            variant="small"
            title="Apariencia"
            description="Personaliza el aspecto visual de tu cuenta."
        />

        <AppearanceTabs class="rounded-2xl border border-sidebar-border/70 bg-muted/30 p-3 sm:p-4" />
    </section>
</template>
