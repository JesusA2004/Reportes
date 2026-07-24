<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import {
    BookOpen, Download, Upload, RefreshCw, Trash2, Settings2, Play, Eye,
    FileSpreadsheet, FileText, History, CheckCircle2, Clock, AlertTriangle,
    XCircle, Layers3, TrendingUp, TrendingDown, PiggyBank, Users, Percent,
    HelpCircle, Lightbulb, GitCompareArrows, ChevronRight, CalendarPlus,
    FolderOpen, ListChecks, Sliders, Building2, UserRound,
    Sparkles, FileCheck2, Filter, Search, Ban,
} from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import GuideMockup from '@/components/guide/GuideMockup.vue'
import GuideStepList from '@/components/guide/GuideStepList.vue'
import GuideButtonChip from '@/components/guide/GuideButtonChip.vue'

defineOptions({ layout: AppLayout })

const sections = [
    { id: 's1',  label: '1. Bienvenida' },
    { id: 's2',  label: '2. Flujo general' },
    { id: 's3',  label: '3. Alta de periodo' },
    { id: 's4',  label: '4. Carga de archivos' },
    { id: 's5',  label: '5. Carga de registros' },
    { id: 's6',  label: '6. Revisión de incidencias' },
    { id: 's7',  label: '7. Configurar reporte' },
    { id: 's8',  label: '8. Generar reporte' },
    { id: 's9',  label: '9. Vista previa' },
    { id: 's10', label: '10. Exportar Excel / PDF' },
    { id: 's11', label: '11. Reportes generados' },
    { id: 's12', label: '12. Reportes mensuales' },
    { id: 's13', label: '13. Bimestres y trimestres' },
    { id: 's14', label: '14. Comparativos' },
    { id: 's15', label: '15. Reporte por sucursal' },
    { id: 's16', label: '16. Reporte por empleado/gestor' },
    { id: 's17', label: '17. Interpretar los KPIs' },
    { id: 's18', label: '18. Errores comunes' },
    { id: 's19', label: '19. Buenas prácticas de cierre' },
]
</script>

<template>
    <Head title="Guía del sistema" />

    <div class="mx-auto max-w-screen-2xl space-y-6 p-4 sm:p-6 lg:p-8">
        <!-- Hero -->
        <section class="overflow-hidden rounded-[2rem] bg-slate-950 p-6 text-white shadow-2xl shadow-slate-300 sm:p-8">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-11 items-center justify-center rounded-2xl bg-indigo-500">
                        <BookOpen class="size-6 text-white" />
                    </div>
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.28em] text-indigo-300">Guía del sistema</p>
                        <h1 class="mt-1 text-2xl font-black tracking-tight sm:text-3xl">Manual de uso — Radiografía Financiera</h1>
                    </div>
                </div>
                <a href="/guia-sistema/pdf"
                   class="inline-flex h-11 items-center gap-2 rounded-2xl bg-emerald-500 px-5 text-sm font-black text-white transition hover:bg-emerald-400">
                    <Download class="size-4" /> Descargar guía en PDF
                </a>
            </div>
            <p class="mt-4 max-w-3xl text-sm leading-6 text-slate-300">
                Manual paso a paso para usar el sistema: desde crear el periodo de trabajo hasta descargar el
                reporte final. Cada sección explica qué hacer y qué significa cada botón.
            </p>
        </section>

        <div class="grid gap-6 lg:grid-cols-[260px_1fr]">
            <!-- TOC -->
            <nav class="app-card sticky top-6 h-fit space-y-1 p-4">
                <p class="mb-2 px-2 text-xs font-black uppercase tracking-wider text-slate-400">Contenido</p>
                <a v-for="s in sections" :key="s.id" :href="`#${s.id}`"
                   class="flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-semibold text-slate-600 transition hover:bg-indigo-50 hover:text-indigo-700">
                    <ChevronRight class="size-3.5 shrink-0" />{{ s.label }}
                </a>
            </nav>

            <div class="space-y-6">
                <!-- 1. Bienvenida -->
                <section id="s1" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Sparkles class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">1. Bienvenida</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Este sistema arma, mes con mes, el reporte financiero completo del negocio: cuánto se
                        recuperó de cartera, cuánto se colocó en créditos nuevos, cuánto se gastó, cuánto se pagó
                        de nómina, y cuál fue la utilidad del negocio — en general y por cada sucursal.
                    </p>
                    <p class="mt-2 text-sm leading-7 text-slate-600">
                        Esta guía te acompaña paso a paso: crear el periodo, cargar los archivos, revisar que todo
                        esté correcto, generar el reporte y consultarlo cuando lo necesites.
                    </p>
                </section>

                <!-- 2. Flujo general -->
                <section id="s2" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Layers3 class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">2. Flujo general del sistema</h2>
                    </div>
                    <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div v-for="(step, i) in [
                            { icon: CalendarPlus, t: 'Crear periodo', d: 'Das de alta el mes, bimestre o trimestre que vas a trabajar.' },
                            { icon: Upload, t: 'Cargar archivos', d: 'Subes los archivos fuente de ese periodo.' },
                            { icon: Settings2, t: 'Configurar y generar', d: 'Eliges tipo y alcance, y generas el reporte.' },
                            { icon: Eye, t: 'Revisar y descargar', d: 'Consultas la vista previa y descargas Excel/PDF.' },
                        ]" :key="i" class="rounded-2xl border border-slate-200 p-4">
                            <div class="flex size-9 items-center justify-center rounded-xl bg-indigo-50 text-indigo-600">
                                <component :is="step.icon" class="size-4.5" />
                            </div>
                            <p class="mt-3 text-sm font-black text-slate-900">{{ i + 1 }}. {{ step.t }}</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">{{ step.d }}</p>
                        </div>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Una vez generado, el reporte queda guardado — puedes consultarlo y descargarlo cuantas
                        veces quieras desde <strong>Reportes mensuales</strong>, sin volver a generarlo.
                    </p>
                </section>

                <!-- 3. Alta de periodo -->
                <section id="s3" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><CalendarPlus class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">3. Alta de periodo</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Primero se crea el periodo operativo que se va a trabajar. Este periodo define las fechas
                        y semanas que tomará en cuenta la radiografía.
                    </p>
                    <div class="mt-5 grid gap-5 lg:grid-cols-2">
                        <GuideStepList :steps="[
                            'Entra al módulo Periodos.',
                            'Presiona el botón para crear un nuevo periodo.',
                            'Selecciona el tipo de periodo: mensual, bimestral o trimestral.',
                            'Elige el mes (o el bimestre/trimestre correspondiente).',
                            'Revisa las fechas de inicio y fin que se muestran.',
                            'Confirma que las semanas que abarca sean las correctas.',
                            'Guarda el periodo.',
                            'Verifica que el periodo aparezca en la lista como creado.',
                        ]" />
                        <GuideMockup title="Periodos">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-black text-slate-700">Periodos</span>
                                <span class="rounded-lg bg-indigo-600 px-3 py-1.5 text-[11px] font-black text-white">+ Crear periodo</span>
                            </div>
                            <div class="mt-3 flex gap-2">
                                <span class="rounded-full bg-indigo-100 px-2.5 py-1 text-[10px] font-black text-indigo-700">Mensual</span>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500">Bimestral</span>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-500">Trimestral</span>
                            </div>
                            <div class="mt-3 space-y-2">
                                <div v-for="i in 3" :key="i" class="flex items-center justify-between rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <span class="text-[11px] font-bold text-slate-700">Junio 2026</span>
                                    <span class="text-[10px] text-slate-400">01/06 → 30/06</span>
                                    <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-black text-emerald-600">Creado</span>
                                </div>
                            </div>
                        </GuideMockup>
                    </div>
                </section>

                <!-- 4. Carga de archivos -->
                <section id="s4" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Upload class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">4. Carga de archivos</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Con el periodo mensual creado, se cargan los archivos fuente de ese mes: nómina, cobranza,
                        colocación, cartera y gastos. El sistema muestra una tarjeta por cada archivo que se
                        necesita para ese periodo.
                    </p>
                    <div class="mt-5 grid gap-5 lg:grid-cols-2">
                        <GuideStepList :steps="[
                            'Entra al periodo mensual que vas a trabajar.',
                            'Revisa la lista de archivos que pide el sistema.',
                            'Selecciona o arrastra el archivo correspondiente a cada tarjeta.',
                            'Verifica que el archivo quede marcado como cargado.',
                            'Si subiste el archivo incorrecto, usa Reemplazar archivo.',
                            'Si subiste un archivo por error, usa Eliminar archivo.',
                        ]" />
                        <GuideMockup title="Archivos y periodo">
                            <div class="grid grid-cols-2 gap-2">
                                <div v-for="i in 4" :key="i" class="rounded-xl border border-dashed border-slate-300 bg-white p-3 text-center">
                                    <FileCheck2 v-if="i % 2 === 0" class="mx-auto size-4 text-emerald-500" />
                                    <Upload v-else class="mx-auto size-4 text-slate-400" />
                                    <p class="mt-1 text-[10px] font-bold text-slate-600">{{ i % 2 === 0 ? 'Cargado' : 'Pendiente' }}</p>
                                </div>
                            </div>
                        </GuideMockup>
                    </div>
                    <div class="mt-4 grid gap-2 sm:grid-cols-2">
                        <GuideButtonChip name="Seleccionar archivo" desc="Abre el explorador de tu computadora para elegir el archivo." />
                        <GuideButtonChip name="Arrastrar archivo" desc="Suelta el archivo directamente sobre la tarjeta." />
                        <GuideButtonChip name="Reemplazar archivo" desc="Sustituye el archivo ya cargado por uno nuevo." />
                        <GuideButtonChip name="Eliminar archivo" desc="Borra el archivo cargado (pide confirmación)." />
                    </div>
                </section>

                <!-- 5. Carga de registros -->
                <section id="s5" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><History class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">5. Carga de registros</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Después de cargar los archivos, el sistema lee su información y la prepara para el
                        reporte. Puedes cerrar la ventana mientras procesa; al terminar te muestra si todo salió
                        correcto o si hay algo que revisar.
                    </p>
                    <div class="mt-5 grid gap-5 lg:grid-cols-2">
                        <GuideStepList :steps="[
                            'Con los archivos cargados, presiona Cargar registros.',
                            'Espera a que el estado cambie de Procesando a Completado.',
                            'Si algo falla, usa Reintentar carga.',
                            'Usa Refrescar si quieres confirmar el estado más reciente.',
                        ]" />
                        <GuideMockup title="Estado de carga">
                            <div class="space-y-2">
                                <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <Clock class="size-4 text-amber-500" /><span class="text-[11px] font-bold text-slate-600">Pendiente</span>
                                </div>
                                <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <RefreshCw class="size-4 text-indigo-500" /><span class="text-[11px] font-bold text-slate-600">Procesando…</span>
                                </div>
                                <div class="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2">
                                    <CheckCircle2 class="size-4 text-emerald-500" /><span class="text-[11px] font-bold text-slate-600">Completado</span>
                                </div>
                            </div>
                        </GuideMockup>
                    </div>
                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <GuideButtonChip name="Cargar registros" desc="Procesa los archivos cargados." />
                        <GuideButtonChip name="Reintentar carga" desc="Vuelve a intentar si algo falló." />
                        <GuideButtonChip name="Refrescar" desc="Consulta el estado más reciente." />
                    </div>
                </section>

                <!-- 6. Incidencias -->
                <section id="s6" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><AlertTriangle class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">6. Revisión de incidencias</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Una incidencia es una advertencia: el sistema detectó algo que no pudo resolver solo, por
                        ejemplo una persona sin sucursal asignada o un gasto sin identificar. No todas las
                        incidencias bloquean el reporte, pero conviene revisarlas antes de generar.
                    </p>
                    <GuideStepList :steps="[
                        'Abre el panel de incidencias del periodo.',
                        'Si hay personas sin sucursal, asígnalas desde el mismo panel.',
                        'Si hay gastos sin asignar, indica a qué sucursal o colaborador corresponden.',
                        'Usa Ver detalle para entender exactamente qué encontró el sistema.',
                        'Marca la incidencia como resuelta cuando la hayas corregido.',
                        'Usa Refrescar para confirmar que ya no aparece.',
                    ]" />
                    <div class="mt-4 grid gap-2 sm:grid-cols-3">
                        <GuideButtonChip name="Ver detalle" desc="Muestra la información completa de la incidencia." />
                        <GuideButtonChip name="Resolver" desc="Aplica la corrección indicada." />
                        <GuideButtonChip name="Refrescar" desc="Actualiza la lista de incidencias." />
                    </div>
                </section>

                <!-- 7. Configurar reporte -->
                <section id="s7" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Sliders class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">7. Configurar reporte</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">Elige el tipo de reporte y qué tanto vas a revisar (alcance).</p>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <p class="text-xs font-black uppercase tracking-wide text-indigo-600">Tipos de reporte</p>
                            <ul class="mt-2 space-y-2 text-sm text-slate-600">
                                <li><strong>Radiografía simple</strong> — para ver un periodo individual.</li>
                                <li><strong>Comparativo mes vs mes</strong> — para comparar un mes contra otro.</li>
                                <li><strong>Comparativo bimestre vs bimestre</strong> — compara dos bimestres.</li>
                                <li><strong>Comparativo trimestre vs trimestre</strong> — compara dos trimestres.</li>
                            </ul>
                        </div>
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <p class="text-xs font-black uppercase tracking-wide text-indigo-600">Alcances</p>
                            <ul class="mt-2 space-y-2 text-sm text-slate-600">
                                <li><strong>General</strong> — todas las sucursales juntas.</li>
                                <li><strong>Por sucursal</strong> — para revisar una sucursal específica.</li>
                                <li><strong>Por empleado / gestor</strong> — para revisar desempeño individual.</li>
                            </ul>
                        </div>
                    </div>
                    <GuideMockup title="Configurar reporte" class="mt-4">
                        <p class="text-[11px] font-black text-slate-500">TIPO DE REPORTE</p>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            <span class="rounded-xl bg-indigo-600 px-3 py-1.5 text-[10px] font-black text-white">Radiografía simple</span>
                            <span class="rounded-xl bg-white px-3 py-1.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">Comparativo mes vs mes</span>
                        </div>
                        <p class="mt-3 text-[11px] font-black text-slate-500">ALCANCE</p>
                        <div class="mt-1.5 flex flex-wrap gap-2">
                            <span class="rounded-xl bg-indigo-600 px-3 py-1.5 text-[10px] font-black text-white">General</span>
                            <span class="rounded-xl bg-white px-3 py-1.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">Por sucursal</span>
                            <span class="rounded-xl bg-white px-3 py-1.5 text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">Por empleado / gestor</span>
                        </div>
                    </GuideMockup>
                </section>

                <!-- 8. Generar reporte -->
                <section id="s8" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Play class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">8. Generar reporte</h2>
                    </div>
                    <div class="mt-5 grid gap-5 lg:grid-cols-2">
                        <GuideStepList :steps="[
                            'Revisa que la configuración elegida sea la correcta.',
                            'Presiona Generar reporte.',
                            'Espera la confirmación — puedes cerrar la ventana, te avisamos por correo.',
                            'Cuando termine, se habilitan Vista previa, Excel y PDF.',
                        ]" />
                        <GuideMockup title="Generar reporte">
                            <div class="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2.5">
                                <CheckCircle2 class="size-4 text-emerald-600" />
                                <span class="text-[11px] font-black text-emerald-700">Reporte generado</span>
                            </div>
                            <div class="mt-2 flex gap-2">
                                <span class="flex-1 rounded-lg bg-slate-900 px-2 py-1.5 text-center text-[10px] font-black text-white">Ver resultado</span>
                                <span class="flex-1 rounded-lg bg-white px-2 py-1.5 text-center text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">Excel</span>
                                <span class="flex-1 rounded-lg bg-white px-2 py-1.5 text-center text-[10px] font-bold text-slate-500 ring-1 ring-slate-200">PDF</span>
                            </div>
                        </GuideMockup>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Mientras se genera, el reporte pasa por los estados <strong>En cola</strong> →
                        <strong>Procesando</strong> → <strong>Generado</strong>. Si algo realmente falla, verás
                        <strong>Error</strong> con el motivo. Si el reporte terminó bien, siempre verás
                        "Generado" — no debe aparecer un error si el resultado ya está disponible.
                    </p>
                </section>

                <!-- 9. Vista previa -->
                <section id="s9" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Eye class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">9. Vista previa</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        La vista previa muestra el reporte completo dentro del sistema, organizado en pestañas:
                        resumen general, sucursales, ingresos, gastos, nómina, cartera y mora, rotación de
                        personal y EBITDA. Cada pestaña tiene sus propios filtros para revisar el detalle.
                    </p>
                    <GuideMockup title="Vista previa">
                        <div class="flex gap-2 overflow-hidden text-[10px] font-bold">
                            <span class="rounded-lg bg-indigo-600 px-2.5 py-1 text-white">Resumen</span>
                            <span class="rounded-lg bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Sucursales</span>
                            <span class="rounded-lg bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Nómina</span>
                            <span class="rounded-lg bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Mora</span>
                            <span class="rounded-lg bg-white px-2.5 py-1 text-slate-500 ring-1 ring-slate-200">Rotación</span>
                        </div>
                        <div class="mt-3 grid grid-cols-3 gap-2">
                            <div v-for="i in 3" :key="i" class="rounded-xl border border-slate-200 bg-white p-2 text-center">
                                <p class="text-[9px] font-bold text-slate-400">KPI</p>
                                <p class="text-[11px] font-black text-slate-800">$ · · ·</p>
                            </div>
                        </div>
                    </GuideMockup>
                </section>

                <!-- 10. Exportación -->
                <section id="s10" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Download class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">10. Exportar Excel y PDF</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Desde la vista previa o desde Reportes generados puedes descargar el reporte completo.
                    </p>
                    <div class="mt-3 grid gap-2 sm:grid-cols-2">
                        <GuideButtonChip name="Excel" desc="Ideal para analizar el detalle, hacer filtros propios o revisar hoja por hoja." />
                        <GuideButtonChip name="PDF" desc="Ideal para compartir o imprimir un resumen ejecutivo ya formateado." />
                    </div>
                </section>

                <!-- 11. Reportes generados -->
                <section id="s11" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><FolderOpen class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">11. Reportes generados</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Aquí se listan todos los reportes que ya generaste, con buscador y filtros por estado.
                        Este módulo es solo para consultar reportes existentes.
                    </p>
                    <GuideStepList :steps="[
                        'Usa el buscador para encontrar un reporte por nombre o periodo.',
                        'Usa los filtros de estado si quieres ver solo generados, con error, etc.',
                        'Presiona Ver para abrir la vista previa.',
                        'Presiona Excel o PDF para descargarlo directamente.',
                    ]" />
                    <GuideMockup title="Reportes generados" class="mt-4">
                        <div class="flex items-center gap-2">
                            <div class="flex flex-1 items-center gap-1.5 rounded-lg bg-white px-2.5 py-1.5 ring-1 ring-slate-200">
                                <Search class="size-3 text-slate-400" /><span class="text-[10px] text-slate-400">Buscar reporte…</span>
                            </div>
                            <Filter class="size-3.5 text-slate-400" />
                        </div>
                        <div class="mt-2 space-y-1.5">
                            <div v-for="i in 2" :key="i" class="flex items-center justify-between rounded-lg border border-slate-200 bg-white px-2.5 py-1.5">
                                <span class="text-[10px] font-bold text-slate-700">Radiografía Junio 2026</span>
                                <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-black text-emerald-600">Generado</span>
                            </div>
                        </div>
                    </GuideMockup>
                </section>

                <!-- 12. Reportes mensuales -->
                <section id="s12" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><FileSpreadsheet class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">12. Reportes mensuales</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Un reporte mensual es la radiografía de un solo mes. Es el punto de partida: los
                        bimestres y trimestres se arman a partir de los meses ya generados.
                    </p>
                </section>

                <!-- 13. Bimestres y trimestres -->
                <section id="s13" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Layers3 class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">13. Reportes bimestrales y trimestrales</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Un bimestre o trimestre se arma automáticamente con sus meses operativos. Antes de poder
                        generarlo, deben existir los reportes mensuales de todos los meses que lo componen — si
                        falta alguno, el sistema te indica claramente cuál falta.
                    </p>
                    <p class="mt-2 text-sm leading-7 text-slate-600">
                        El reporte resultante muestra el rango completo del periodo, incluyendo qué meses abarca:
                    </p>
                    <div class="mt-3 rounded-2xl border border-indigo-200 bg-indigo-50 p-4 font-mono text-xs leading-6 text-indigo-800">
                        Trimestre 2 - 2026<br>
                        Abril 2026 + Mayo 2026 + Junio 2026<br>
                        Rango: 2026-03-30 → 2026-06-21
                    </div>
                </section>

                <!-- 14. Comparativos -->
                <section id="s14" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><GitCompareArrows class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">14. Comparativos</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Un comparativo pone lado a lado dos periodos del mismo tipo (mes vs mes, bimestre vs
                        bimestre o trimestre vs trimestre) para ver qué tanto creció o bajó cada indicador.
                    </p>
                    <div class="mt-3 overflow-x-auto rounded-2xl border border-slate-200">
                        <table class="w-full text-xs">
                            <thead class="bg-slate-50 text-slate-500">
                                <tr><th class="px-3 py-2 text-left font-black">Métrica</th><th class="px-3 py-2 text-right font-black">Mayo 2026</th><th class="px-3 py-2 text-right font-black">Junio 2026</th><th class="px-3 py-2 text-right font-black">Diferencia</th><th class="px-3 py-2 text-right font-black">Variación %</th></tr>
                            </thead>
                            <tbody>
                                <tr class="border-t"><td class="px-3 py-2 font-bold text-slate-700">Recuperación</td><td class="px-3 py-2 text-right text-slate-500">$17,697,872</td><td class="px-3 py-2 text-right text-slate-500">$17,888,527</td><td class="px-3 py-2 text-right text-emerald-600">+$190,655</td><td class="px-3 py-2 text-right font-black text-emerald-600">+1.08%</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="mt-3 text-sm leading-7 text-slate-600">
                        Verde significa que la métrica subió; rojo, que bajó. Sirve para ver el crecimiento o la
                        disminución del negocio entre un periodo y otro.
                    </p>
                </section>

                <!-- 15. Por sucursal -->
                <section id="s15" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Building2 class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">15. Reporte por sucursal</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Al elegir alcance <strong>Por sucursal</strong> en Configurar reporte, el reporte trae
                        únicamente la información de esa sucursal: su recuperación, colocación, cartera, mora,
                        gastos, nómina y utilidad. Útil para revisar el desempeño de una sucursal en particular
                        sin mezclarlo con las demás.
                    </p>
                </section>

                <!-- 16. Por empleado -->
                <section id="s16" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><UserRound class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">16. Reporte por empleado / gestor</h2>
                    </div>
                    <p class="mt-4 text-sm leading-7 text-slate-600">
                        Al elegir alcance <strong>Por empleado / gestor</strong>, el reporte trae únicamente la
                        información de esa persona: su recuperación, colocación, cartera asignada y utilidad
                        generada. Útil para revisar el desempeño individual de un gestor.
                    </p>
                </section>

                <!-- 17. KPIs -->
                <section id="s17" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><TrendingUp class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">17. Interpretación de KPIs</h2>
                    </div>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div v-for="(k, i) in [
                            { icon: TrendingUp, t: 'Recuperación', d: 'Dinero real cobrado a los clientes en el periodo.' },
                            { icon: PiggyBank, t: 'Colocación', d: 'Monto total desembolsado en créditos nuevos.' },
                            { icon: FileSpreadsheet, t: 'Valor cartera', d: 'Saldo total de los créditos activos al cierre del periodo.' },
                            { icon: TrendingDown, t: 'Cartera vencida', d: 'Parte de la cartera que está atrasada en sus pagos.' },
                            { icon: Percent, t: 'Mora %', d: 'Cartera vencida entre valor cartera. Entre más bajo, mejor.' },
                            { icon: FileText, t: 'OPEX', d: 'Gastos operativos del negocio, sin nómina.' },
                            { icon: Users, t: 'Nómina y Capital Humano', d: 'Todo el gasto relacionado a pago de personal.' },
                            { icon: TrendingUp, t: 'EBITDA', d: 'La utilidad real del negocio en el periodo.' },
                            { icon: Percent, t: 'Margen EBITDA', d: 'Qué porcentaje de los ingresos se convierte en utilidad.' },
                            { icon: ListChecks, t: 'Préstamo activo', d: 'Número de créditos/contratos vigentes en el periodo.' },
                            { icon: Users, t: 'Percepciones', d: 'Todo lo que se le pagó al personal antes de descuentos.' },
                            { icon: Users, t: 'Deducciones', d: 'Descuentos aplicados a la nómina, solo informativos.' },
                            { icon: PiggyBank, t: 'Neto pagado', d: 'Lo que efectivamente recibió el personal.' },
                            { icon: UserRound, t: 'Rotación de personal', d: 'Qué tanto entra y sale personal de la empresa en el periodo.' },
                        ]" :key="i" class="flex items-start gap-3 rounded-2xl border border-slate-200 p-4">
                            <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-700">
                                <component :is="k.icon" class="size-4.5" />
                            </div>
                            <div>
                                <p class="text-sm font-black text-slate-900">{{ k.t }}</p>
                                <p class="mt-0.5 text-xs leading-5 text-slate-500">{{ k.d }}</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 18. Errores comunes -->
                <section id="s18" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Ban class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">18. Errores comunes y qué hacer</h2>
                    </div>
                    <div class="mt-4 space-y-3">
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <p class="flex items-center gap-2 text-sm font-black text-slate-900"><AlertTriangle class="size-4 text-amber-500" />"Faltan reportes mensuales" al generar un bimestre/trimestre</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Genera primero el reporte del mes o meses que indica el mensaje; después el consolidado se habilita solo.</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <p class="flex items-center gap-2 text-sm font-black text-slate-900"><AlertTriangle class="size-4 text-amber-500" />Un archivo queda en incidencia</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Revisa el detalle de la incidencia y sigue la acción sugerida — casi siempre se resuelve indicando manualmente el dato faltante.</p>
                        </div>
                        <div class="rounded-2xl border border-slate-200 p-4">
                            <p class="flex items-center gap-2 text-sm font-black text-slate-900"><AlertTriangle class="size-4 text-amber-500" />El reporte tarda en generarse</p>
                            <p class="mt-1 text-xs leading-5 text-slate-500">Es normal para una radiografía mensual — puede tardar varios minutos. Puedes cerrar la ventana; te avisamos por correo cuando esté listo.</p>
                        </div>
                    </div>
                </section>

                <!-- 19. Buenas prácticas -->
                <section id="s19" class="app-card scroll-mt-6 p-6 sm:p-8">
                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-2xl bg-indigo-50 text-indigo-600"><Lightbulb class="size-5" /></div>
                        <h2 class="text-xl font-black text-slate-950">19. Buenas prácticas de cierre mensual</h2>
                    </div>
                    <div class="mt-4 space-y-2">
                        <div v-for="(tip, i) in [
                            'Carga todos los archivos del mes antes de generar el reporte.',
                            'Revisa las incidencias antes de generar.',
                            'Genera primero los reportes mensuales antes de un bimestre o trimestre.',
                            'Abre Ver resultado antes de compartir un reporte, para confirmar que los números se ven correctos.',
                            'Si algo se ve raro, compáralo contra el mes anterior con un comparativo.',
                        ]" :key="i" class="flex items-start gap-3 rounded-2xl bg-emerald-50 p-3">
                            <Lightbulb class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                            <p class="text-sm leading-6 text-emerald-800">{{ tip }}</p>
                        </div>
                    </div>
                    <div class="mt-5 flex items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                        <HelpCircle class="mt-0.5 size-5 shrink-0 text-slate-500" />
                        <p class="text-sm leading-6 text-slate-600">
                            ¿Tienes dudas que esta guía no resuelve? Descarga la versión en PDF para consultarla
                            sin conexión, o contacta al equipo responsable del sistema.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </div>
</template>
