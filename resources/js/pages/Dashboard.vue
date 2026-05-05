<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { CalendarRange, DatabaseZap, FileSpreadsheet, LayoutGrid, TrendingUp, Upload, Users } from 'lucide-vue-next'
import { dashboard } from '@/routes'

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
})

const quickLinks = [
    { label: 'Histórico general', description: 'Carga fuentes, actualiza BD y genera reportes.', href: '/historico-general', icon: Upload, color: 'bg-indigo-500' },
    { label: 'Periodos', description: 'Gestiona semanas, meses y periodos agrupados.', href: '/periodos', icon: CalendarRange, color: 'bg-violet-500' },
    { label: 'Empleados', description: 'Consulta empleados, sucursales y asignaciones.', href: '/asignaciones-empleado-sucursal', icon: Users, color: 'bg-sky-500' },
    { label: 'Reportes mensuales', description: 'Descarga Excel y PDF de reportes generados.', href: '/reportes-mensuales', icon: FileSpreadsheet, color: 'bg-emerald-500' },
]
</script>

<template>
    <Head title="Dashboard" />
    <div class="space-y-8 p-4 sm:p-6 lg:p-8">

        <!-- Hero -->
        <section class="overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-200 sm:p-8">
            <div class="flex items-center gap-3">
                <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-500">
                    <LayoutGrid class="size-5 text-white" />
                </div>
                <p class="text-xs font-black uppercase tracking-[0.25em] text-indigo-300">Sistema Reportes</p>
            </div>
            <h1 class="mt-4 text-3xl font-black tracking-tight sm:text-4xl">Panel de control</h1>
            <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">
                Accede a todos los módulos: carga de fuentes, actualización de base de datos, generación de radiografías y consulta de reportes históricos.
            </p>
        </section>

        <!-- Accesos rápidos -->
        <section>
            <h2 class="mb-4 text-lg font-black text-slate-950">Accesos rápidos</h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <Link
                    v-for="link in quickLinks"
                    :key="link.href"
                    :href="link.href"
                    class="group rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm transition duration-200 hover:-translate-y-1 hover:shadow-xl hover:shadow-slate-200/60"
                >
                    <div class="flex items-center gap-4">
                        <div class="flex size-12 shrink-0 items-center justify-center rounded-2xl shadow-lg" :class="link.color">
                            <component :is="link.icon" class="size-6 text-white" />
                        </div>
                        <div>
                            <p class="font-black text-slate-950 transition-colors group-hover:text-indigo-700">{{ link.label }}</p>
                            <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ link.description }}</p>
                        </div>
                    </div>
                </Link>
            </div>
        </section>

        <!-- Estado del sistema -->
        <section>
            <h2 class="mb-4 text-lg font-black text-slate-950">Estado del sistema</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center gap-3">
                        <DatabaseZap class="size-6 text-indigo-600" />
                        <p class="font-black text-slate-950">Base de datos</p>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-slate-500">
                        Actualiza la BD desde Histórico General seleccionando un periodo y cargando NOI Nómina y Cobranza.
                    </p>
                    <Link href="/historico-general" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-indigo-50 px-3 py-2 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100">
                        Ir a Histórico General →
                    </Link>
                </div>

                <div class="rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center gap-3">
                        <TrendingUp class="size-6 text-emerald-600" />
                        <p class="font-black text-slate-950">Reportes generados</p>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-slate-500">
                        Consulta y descarga los reportes de Radiografía ya generados desde el módulo de Reportes mensuales.
                    </p>
                    <Link href="/reportes-mensuales" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-emerald-50 px-3 py-2 text-xs font-bold text-emerald-700 transition hover:bg-emerald-100">
                        Ver reportes →
                    </Link>
                </div>

                <div class="rounded-[1.75rem] border border-slate-200 bg-white p-6 shadow-sm">
                    <div class="flex items-center gap-3">
                        <Users class="size-6 text-sky-600" />
                        <p class="font-black text-slate-950">Empleados y sucursales</p>
                    </div>
                    <p class="mt-3 text-sm leading-6 text-slate-500">
                        Revisa asignaciones de empleados a sucursales y resuelve pendientes de matcheo automático.
                    </p>
                    <Link href="/asignaciones-empleado-sucursal" class="mt-4 inline-flex items-center gap-1.5 rounded-xl bg-sky-50 px-3 py-2 text-xs font-bold text-sky-700 transition hover:bg-sky-100">
                        Ver empleados →
                    </Link>
                </div>
            </div>
        </section>

    </div>
</template>
