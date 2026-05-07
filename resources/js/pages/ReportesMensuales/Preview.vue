<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowLeft, AlertTriangle, FileSpreadsheet, FileText } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    period: any
    snapshot: any | null
    run: any | null
    hasExcelExport: boolean
    hasPdfExport: boolean
    excelUrl: string
    pdfUrl: string
}>()

const activeTab = ref<'resumen' | 'productos' | 'sucursales' | 'gestores' | 'cartera' | 'gastos' | 'nomina' | 'incidencias'>('resumen')

const snap    = computed(() => props.snapshot)
const sum     = computed(() => snap.value?.summary ?? {})
const pay     = computed(() => snap.value?.sections?.payroll ?? {})
const inc     = computed(() => snap.value?.sections?.incidents ?? [])
const highInc = computed(() => inc.value.filter((i: any) => i.severity === 'high').length)

const money = (v: number) =>
    new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN' }).format(Number(v || 0))
const pct  = (v: number) => Number(v || 0).toFixed(2) + '%'
const num  = (v: number) => new Intl.NumberFormat('es-MX').format(Number(v || 0))
</script>

<template>
    <div class="min-h-screen bg-slate-50">
        <!-- HERO HEADER -->
        <div class="bg-slate-950 px-6 py-8 text-white">
            <div class="mx-auto max-w-7xl">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-widest text-indigo-400">Reporte Mensual · Radiografía Financiera</p>
                        <h1 class="mt-1 text-2xl font-black">{{ period.label }}</h1>
                        <p class="mt-1 text-sm text-slate-400">
                            <span v-if="snap">Generado {{ snap.generated_at }} · Versión {{ snap.version }}</span>
                            <span v-else>Sin radiografía generada</span>
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <a :href="hasExcelExport ? excelUrl : '#'"
                           :class="hasExcelExport ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-slate-700 opacity-50 pointer-events-none'"
                           class="inline-flex h-9 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                            <FileSpreadsheet class="size-4" /> Excel
                        </a>
                        <a :href="hasPdfExport ? pdfUrl : '#'"
                           :class="hasPdfExport ? 'bg-rose-600 hover:bg-rose-500' : 'bg-slate-700 opacity-50 pointer-events-none'"
                           class="inline-flex h-9 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                            <FileText class="size-4" /> PDF
                        </a>
                        <a href="/historico-general"
                           class="inline-flex h-9 items-center gap-2 rounded-xl bg-slate-700 px-4 text-sm font-bold text-slate-200 transition hover:bg-slate-600">
                            <ArrowLeft class="size-4" /> Histórico
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- No snapshot -->
        <div v-if="!snap" class="mx-auto max-w-7xl px-6 py-16 text-center">
            <AlertTriangle class="mx-auto mb-4 size-12 text-amber-500" />
            <h2 class="text-xl font-black text-slate-800">Sin radiografía generada</h2>
            <p class="mt-2 text-sm text-slate-500">Genera la radiografía en Histórico General para ver el reporte completo.</p>
        </div>

        <template v-else>
            <!-- KPI CARDS -->
            <div class="mx-auto max-w-7xl px-6 py-6">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Empleados</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ num(sum.employees_count) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Recuperación</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ money(sum.recovery_total) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Colocación</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ money(sum.placement_total) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow" :class="sum.mora_index > 25 ? 'border-red-200' : ''">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Índice mora</p>
                        <p class="mt-1 text-2xl font-black" :class="sum.mora_index > 25 ? 'text-red-700' : 'text-slate-950'">{{ pct(sum.mora_index) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cartera total</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ money(sum.portfolio_total) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow" :class="sum.overdue_portfolio > 0 ? 'border-red-200' : ''">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cartera vencida</p>
                        <p class="mt-1 text-2xl font-black" :class="sum.overdue_portfolio > 0 ? 'text-red-700' : 'text-slate-950'">{{ money(sum.overdue_portfolio) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Neto nómina</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ money(sum.net_payroll) }}</p>
                    </div>
                    <div class="rounded-2xl border border-white/70 bg-white p-4 shadow">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Gastos totales</p>
                        <p class="mt-1 text-2xl font-black text-slate-950">{{ money(sum.expenses_total) }}</p>
                    </div>
                </div>
            </div>

            <!-- TABS -->
            <div class="mx-auto max-w-7xl px-6 pb-10">
                <div class="mb-4 flex flex-wrap gap-1 border-b border-slate-200">
                    <button v-for="t in [
                        { key: 'resumen',     label: 'Resumen' },
                        { key: 'productos',   label: 'Productos' },
                        { key: 'sucursales',  label: 'Sucursales' },
                        { key: 'gestores',    label: 'Gestores' },
                        { key: 'cartera',     label: 'Cartera y mora' },
                        { key: 'gastos',      label: 'Gastos' },
                        { key: 'nomina',      label: 'Nómina' },
                        { key: 'incidencias', label: 'Incidencias' + (highInc > 0 ? ' (' + highInc + ')' : '') },
                    ]" :key="t.key" @click="activeTab = t.key as any"
                       class="px-4 py-2 text-sm font-bold transition border-b-2"
                       :class="activeTab === t.key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-800'">
                        {{ t.label }}
                    </button>
                </div>

                <!-- TAB: RESUMEN -->
                <div v-show="activeTab === 'resumen'" class="grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border bg-white p-5 shadow-sm">
                        <h3 class="mb-3 text-sm font-black uppercase tracking-wider text-slate-600">Métricas financieras</h3>
                        <table class="w-full text-sm">
                            <tr v-for="row in [
                                ['Recuperación total',  money(sum.recovery_total)],
                                ['Colocación total',    money(sum.placement_total)],
                                ['Cartera total',       money(sum.portfolio_total)],
                                ['Cartera vencida',     money(sum.overdue_portfolio)],
                                ['Índice de mora',      pct(sum.mora_index)],
                                ['Gastos totales',      money(sum.expenses_total)],
                            ]" :key="row[0]" class="border-b last:border-0">
                                <td class="py-2 font-semibold text-slate-700">{{ row[0] }}</td>
                                <td class="py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="rounded-2xl border bg-white p-5 shadow-sm">
                        <h3 class="mb-3 text-sm font-black uppercase tracking-wider text-slate-600">Nómina / empleados</h3>
                        <table class="w-full text-sm">
                            <tr v-for="row in [
                                ['Total empleados',  num(pay.total_empleados)],
                                ['Total pagos',      money(pay.pagos)],
                                ['Total bonos',      money(pay.bonos)],
                                ['Total descuentos', money(pay.descuentos)],
                                ['Total gastos',     money(pay.gastos)],
                                ['Neto acumulado',   money(pay.neto)],
                            ]" :key="row[0]" class="border-b last:border-0">
                                <td class="py-2 font-semibold text-slate-700">{{ row[0] }}</td>
                                <td class="py-2 text-right font-black text-slate-950">{{ row[1] }}</td>
                            </tr>
                        </table>
                        <p v-if="pay.source === 'noi_direct'" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">
                            ⚠ Nómina calculada directo de movimientos NOI. Verifica el campo "concept_type".
                        </p>
                    </div>
                </div>

                <!-- TAB: PRODUCTOS -->
                <div v-show="activeTab === 'productos'">
                    <div v-if="!snap.sections?.products?.length" class="rounded-2xl border bg-white p-8 text-center text-sm text-slate-400">
                        Sin datos de producto. Verifica que el archivo de ministraciones incluya la columna de producto financiero.
                    </div>
                    <div v-else class="overflow-hidden rounded-2xl border bg-white shadow-sm">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Producto</th>
                                    <th class="px-4 py-3 text-right">Operaciones</th>
                                    <th class="px-4 py-3 text-right">Colocación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in snap.sections.products" :key="p.producto" class="border-t">
                                    <td class="px-4 py-2 font-semibold">{{ p.producto }}</td>
                                    <td class="px-4 py-2 text-right">{{ num(p.operaciones) }}</td>
                                    <td class="px-4 py-2 text-right font-black">{{ money(p.colocacion) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: SUCURSALES -->
                <div v-show="activeTab === 'sucursales'">
                    <div v-if="!snap.sections?.branches?.length" class="rounded-2xl border bg-white p-8 text-center text-sm text-slate-400">Sin datos por sucursal.</div>
                    <div v-else class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Sucursal</th>
                                    <th class="px-4 py-3 text-right">Recuperación</th>
                                    <th class="px-4 py-3 text-right">Colocación</th>
                                    <th class="px-4 py-3 text-right">Cartera</th>
                                    <th class="px-4 py-3 text-right">Vencida</th>
                                    <th class="px-4 py-3 text-right">Mora %</th>
                                    <th class="px-4 py-3 text-right">Gastos</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="b in snap.sections.branches" :key="b.branch_id" class="border-t">
                                    <td class="px-4 py-2 font-semibold">{{ b.nombre }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(b.recuperacion) }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(b.colocacion) }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(b.cartera) }}</td>
                                    <td class="px-4 py-2 text-right" :class="b.vencida > 0 ? 'font-bold text-red-700' : ''">{{ money(b.vencida) }}</td>
                                    <td class="px-4 py-2 text-right" :class="b.mora > 25 ? 'font-bold text-red-700' : ''">{{ pct(b.mora) }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(b.gastos) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: GESTORES -->
                <div v-show="activeTab === 'gestores'">
                    <p class="mb-3 text-xs text-slate-400">Fuente: ministraciones/colocación. Recuperación por gestor no disponible en el esquema actual.</p>
                    <div v-if="!snap.sections?.promoters?.length" class="rounded-2xl border bg-white p-8 text-center text-sm text-slate-400">Sin promotores registrados en colocación para este periodo.</div>
                    <div v-else class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Gestor / Promotor</th>
                                    <th class="px-4 py-3 text-left">Sucursal</th>
                                    <th class="px-4 py-3 text-right">Operaciones</th>
                                    <th class="px-4 py-3 text-right">Colocación</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="p in snap.sections.promoters" :key="p.gestor + p.sucursal" class="border-t">
                                    <td class="px-4 py-2 font-semibold">{{ p.gestor }}</td>
                                    <td class="px-4 py-2 text-slate-500">{{ p.sucursal }}</td>
                                    <td class="px-4 py-2 text-right">{{ num(p.operaciones) }}</td>
                                    <td class="px-4 py-2 text-right font-black">{{ money(p.colocacion) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: CARTERA Y MORA -->
                <div v-show="activeTab === 'cartera'" class="space-y-4">
                    <div class="grid grid-cols-3 gap-3">
                        <div class="rounded-2xl border bg-white p-4 shadow-sm">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cartera total</p>
                            <p class="mt-1 text-xl font-black">{{ money(sum.portfolio_total) }}</p>
                        </div>
                        <div class="rounded-2xl border bg-white p-4 shadow-sm" :class="sum.overdue_portfolio > 0 ? 'border-red-200' : ''">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Cartera vencida</p>
                            <p class="mt-1 text-xl font-black" :class="sum.overdue_portfolio > 0 ? 'text-red-700' : ''">{{ money(sum.overdue_portfolio) }}</p>
                        </div>
                        <div class="rounded-2xl border bg-white p-4 shadow-sm" :class="sum.mora_index > 25 ? 'border-red-200' : ''">
                            <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Índice mora</p>
                            <p class="mt-1 text-xl font-black" :class="sum.mora_index > 25 ? 'text-red-700' : ''">{{ pct(sum.mora_index) }}</p>
                        </div>
                    </div>
                    <div v-if="!snap.sections?.portfolio_buckets?.length" class="rounded-2xl border bg-amber-50 p-4 text-sm text-amber-700">
                        Sin datos de días vencidos. Verifica que el archivo "Lendus Saldos por Cliente" incluya la columna "días_mora" o "días_vencidos".
                    </div>
                    <div v-else class="overflow-hidden rounded-2xl border bg-white shadow-sm">
                        <div class="border-b bg-slate-50 px-4 py-2 text-xs font-bold uppercase tracking-wider text-slate-500">Distribución por días vencidos</div>
                        <table class="w-full text-sm">
                            <thead class="text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-2 text-left">Bucket</th>
                                    <th class="px-4 py-2 text-right">Contratos</th>
                                    <th class="px-4 py-2 text-right">Balance</th>
                                    <th class="px-4 py-2 text-right">Vencido</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="b in snap.sections.portfolio_buckets" :key="b.label" class="border-t">
                                    <td class="px-4 py-2 font-semibold">{{ b.label }}</td>
                                    <td class="px-4 py-2 text-right">{{ num(b.contratos) }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(b.balance) }}</td>
                                    <td class="px-4 py-2 text-right" :class="b.vencida > 0 && b.label !== 'Al corriente' ? 'font-bold text-red-700' : ''">{{ money(b.vencida) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: GASTOS -->
                <div v-show="activeTab === 'gastos'">
                    <div v-if="!snap.sections?.expenses_detail?.length" class="rounded-2xl border bg-white p-8 text-center text-sm text-slate-400">Sin gastos registrados para este periodo.</div>
                    <div v-else class="overflow-hidden rounded-2xl border bg-white shadow-sm">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Categoría</th>
                                    <th class="px-4 py-3 text-left">Sucursal</th>
                                    <th class="px-4 py-3 text-right">Registros</th>
                                    <th class="px-4 py-3 text-right">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="e in snap.sections.expenses_detail" :key="e.categoria + e.sucursal" class="border-t">
                                    <td class="px-4 py-2 font-semibold">{{ e.categoria }}</td>
                                    <td class="px-4 py-2 text-slate-500">{{ e.sucursal }}</td>
                                    <td class="px-4 py-2 text-right">{{ num(e.count) }}</td>
                                    <td class="px-4 py-2 text-right font-black">{{ money(e.total) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: NÓMINA -->
                <div v-show="activeTab === 'nomina'">
                    <div v-if="!snap.sections?.employees?.length" class="rounded-2xl border bg-amber-50 p-6 text-sm text-amber-700">
                        Sin datos de nómina. Verifica que el archivo NOI fue procesado y que la columna "concept_type" está mapeada.
                    </div>
                    <div v-else class="overflow-x-auto rounded-2xl border bg-white shadow-sm">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3 text-left">Empleado</th>
                                    <th class="px-4 py-3 text-left">Sucursal</th>
                                    <th class="px-4 py-3 text-right">Pagos</th>
                                    <th class="px-4 py-3 text-right">Bonos</th>
                                    <th class="px-4 py-3 text-right">Descuentos</th>
                                    <th class="px-4 py-3 text-right">Gastos</th>
                                    <th class="px-4 py-3 text-right">Neto</th>
                                    <th class="px-4 py-3 text-center">Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="e in snap.sections.employees" :key="e.name + e.branch" class="border-t">
                                    <td class="px-4 py-2 font-semibold">{{ e.name }}</td>
                                    <td class="px-4 py-2 text-slate-500">{{ e.branch }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(e.pagos) }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(e.bonos) }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(e.descuentos) }}</td>
                                    <td class="px-4 py-2 text-right">{{ money(e.gastos) }}</td>
                                    <td class="px-4 py-2 text-right font-black">{{ money(e.neto) }}</td>
                                    <td class="px-4 py-2 text-center">
                                        <span class="rounded-full px-2 py-0.5 text-xs font-bold"
                                              :class="e.included ? 'bg-indigo-100 text-indigo-700' : 'bg-amber-100 text-amber-700'">
                                            {{ e.included ? 'Incluido' : 'Excluido' }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- TAB: INCIDENCIAS -->
                <div v-show="activeTab === 'incidencias'">
                    <div v-if="!inc.length" class="rounded-2xl border bg-white p-8 text-center text-sm text-slate-400">Sin incidencias registradas.</div>
                    <div v-else class="space-y-3">
                        <div v-for="i in inc" :key="i.type + i.message"
                             class="rounded-2xl border p-4"
                             :class="i.severity === 'high' ? 'border-red-200 bg-red-50' : 'border-amber-200 bg-amber-50'">
                            <div class="flex items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-xs font-black uppercase"
                                      :class="i.severity === 'high' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700'">{{ i.severity }}</span>
                                <span class="text-xs font-bold text-slate-500">{{ i.type }}</span>
                            </div>
                            <p class="mt-1 text-sm font-semibold" :class="i.severity === 'high' ? 'text-red-800' : 'text-amber-800'">{{ i.message }}</p>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
