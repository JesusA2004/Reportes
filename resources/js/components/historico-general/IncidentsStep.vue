<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { AlertTriangle, CheckCircle2, ChevronDown, ChevronRight, Copy, Info, MapPin, Search, Users, XCircle } from 'lucide-vue-next'
import EmptyState from './EmptyState.vue'
import SectionHeader from './SectionHeader.vue'
import StatusBadge from './StatusBadge.vue'
import SearchableSelect from './SearchableSelect.vue'

const props = defineProps<{
    incidents: any[]
    period: any
    branches: Array<{ id: number; name: string }>
    reloadKey?: number
}>()

const emit = defineEmits(['resolve', 'refresh', 'assign-branch', 'confirm-match', 'resolve-location', 'confirm-coincidencia-from-duplicates', 'discard-coincidencia'])

const OPERATIVE_BRANCH_NAMES = new Set([
    'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
    'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI',
    'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
])

const branchItems = computed(() =>
    props.branches
        .filter((b) => OPERATIVE_BRANCH_NAMES.has(b.name.trim().toUpperCase()))
        .map((b) => ({ id: b.id, label: b.name }))
)

// ── Segregar tipos especiales ──────────────────────────────────────────
const SPECIAL_TYPES = new Set([
    'db_update.pending_location',
    'empleados_sin_sucursal',
    'db_update.empleados_sin_sucursal',
    'posibles_duplicados_persona',
    'posibles_coincidencias_persona',
    'mora_alta',
    'db_update.mora_alta',
])

const pendingLocations = computed(() =>
    props.incidents.filter((i) => i.type === 'db_update.pending_location')
)
const pendingUnresolved = computed(() => pendingLocations.value.filter((i) => i.severity !== 'resolved'))
const pendingResolved   = computed(() => pendingLocations.value.filter((i) => i.severity === 'resolved'))

const employeesIncident = computed(() =>
    props.incidents.find((i) => ['empleados_sin_sucursal', 'db_update.empleados_sin_sucursal'].includes(i.type))
)

const duplicatesIncident = computed(() =>
    props.incidents.find((i) => ['posibles_duplicados_persona', 'posibles_coincidencias_persona'].includes(i.type))
)

const genericIncidents = computed(() =>
    props.incidents.filter((i) => !SPECIAL_TYPES.has(i.type))
)

// ── Contadores de cabecera (sin mora_alta) ─────────────────────────────
const summary = computed(() => ({
    critical:             props.incidents.filter((i) => i.severity === 'high'  && !['mora_alta', 'db_update.mora_alta'].includes(i.type)).length,
    // raw employee_id count (may include same person from multiple sources)
    personsAffected:      (employeesIncident.value?.context?.count ?? 0) as number,
    // unique grouped persons (by normalized_name) — provided by backend since last refresh
    uniquePersons:        (employeesIncident.value?.context?.unique_persons_count
                           ?? employeesIncident.value?.context?.count ?? 0) as number,
    coincidenciasPending: (duplicatesIncident.value?.context?.count ?? 0) as number,
    warnings:             props.incidents.filter((i) => i.severity === 'warning' && !['mora_alta', 'db_update.mora_alta'].includes(i.type)).length,
    resolved:             props.incidents.filter((i) => i.severity === 'resolved').length,
}))

// ── Posibles coincidencias ─────────────────────────────────────────────
// All state keyed by pair_key (string) — stable across array re-renders
const loadingKeys   = ref<Set<string>>(new Set())
const hiddenKeys    = ref<Set<string>>(new Set())
const confirmedKeys = ref<Set<string>>(new Set())

/** Derive a stable key for a pair (prefer explicit pair_key; fall back to ID-based). */
function getPairKey(pair: any): string {
    if (pair.pair_key) return String(pair.pair_key)
    if (pair.a_id && pair.b_id) return `${Math.min(pair.a_id, pair.b_id)}-${Math.max(pair.a_id, pair.b_id)}`
    return `${pair.a}__${pair.b}`
}
function isPairLoading(pair: any)   { return loadingKeys.value.has(getPairKey(pair)) }
function isPairHidden(pair: any)    { return hiddenKeys.value.has(getPairKey(pair)) }
function isPairConfirmed(pair: any) { return confirmedKeys.value.has(getPairKey(pair)) }

// ── Called by parent (via template ref) to signal result ──────────────
function onPairConfirmed(key: string) {
    confirmedKeys.value = new Set([...confirmedKeys.value, key])
    hiddenKeys.value    = new Set([...hiddenKeys.value, key])
    loadingKeys.value   = new Set([...loadingKeys.value].filter((k) => k !== key))
}
function onPairRejected(key: string) {
    hiddenKeys.value  = new Set([...hiddenKeys.value, key])
    loadingKeys.value = new Set([...loadingKeys.value].filter((k) => k !== key))
}
function clearPairLoading(key: string) {
    loadingKeys.value = new Set([...loadingKeys.value].filter((k) => k !== key))
}
defineExpose({ onPairConfirmed, onPairRejected, clearPairLoading })

function confirmDuplicatePair(pair: any) {
    if (!pair.a_id || !pair.b_id) return
    const key = getPairKey(pair)
    if (loadingKeys.value.has(key)) return
    loadingKeys.value = new Set([...loadingKeys.value, key])
    emit('confirm-coincidencia-from-duplicates', {
        a_id:     pair.a_id,
        b_id:     pair.b_id,
        a_name:   pair.a,
        b_name:   pair.b,
        pair_key: key,
    })
}

function rejectDuplicatePair(pair: any) {
    if (!pair.a_id || !pair.b_id) return
    const key = getPairKey(pair)
    if (loadingKeys.value.has(key)) return
    loadingKeys.value = new Set([...loadingKeys.value, key])
    emit('discard-coincidencia', {
        a_id:     pair.a_id,
        b_id:     pair.b_id,
        pair_key: key,
    })
}

// ── Filtros de lista genérica ──────────────────────────────────────────
const query  = ref('')
const filter = ref('all')
const filteredGeneric = computed(() => genericIncidents.value.filter((item) => {
    const matchesFilter = filter.value === 'all' || item.severity === filter.value
    const term = query.value.trim().toLowerCase()
    const matchesSearch = !term || [item.type, item.message, JSON.stringify(item.context ?? {})].join(' ').toLowerCase().includes(term)
    return matchesFilter && matchesSearch
}))

// ── Panel personas sin sucursal ────────────────────────────────────────
const showPersonas    = ref(false)
const personasLoading = ref(false)
const personasItems   = ref<any[]>([])
const personaBranch   = ref<Record<string, number | null>>({})
// 'confirm' | 'reject' | null — which action mode the user chose per person
const personaAction   = ref<Record<string, 'confirm' | 'reject' | null>>({})
const diagnosisOpen   = ref<Record<string, boolean>>({})

function empKey(emp: any): string {
    return emp.normalized_name || String(emp.employee_id)
}
function toggleDiagnosis(emp: any) {
    const k = empKey(emp)
    diagnosisOpen.value[k] = !diagnosisOpen.value[k]
}

// Only fuzzy matches warrant the "same person?" question — exact_name_no_branch means
// both records already share a name; confirming doesn't gain a branch so go straight to selector.
function needsConfirmation(emp: any): boolean {
    return ['fuzzy_match_has_branch', 'fuzzy_match_no_branch'].includes(emp.resolution_type)
}
function hasBranchFromMatch(emp: any): boolean {
    return emp.resolution_type === 'fuzzy_match_has_branch'
}
// exact_name_no_branch: same person for sure, but neither has a branch — show selector with note
function isSamePersonNoBranch(emp: any): boolean {
    return emp.resolution_type === 'exact_name_no_branch'
}

function confirmMatch(emp: any) {
    const targetId = emp.best_match?.employee_id
    if (!targetId) return
    emit('confirm-match', { employee_id: emp.employee_id, target_employee_id: targetId, period_id: props.period?.id })
}
function rejectMatch(emp: any) {
    personaAction.value[empKey(emp)] = 'reject'
}

// Reload personas list when parent signals a refresh (e.g., after a branch assignment)
watch(() => props.reloadKey, (newVal, oldVal) => {
    if (newVal !== oldVal && showPersonas.value) loadPersonas()
})

async function loadPersonas() {
    if (!props.period?.id) return
    personasLoading.value = true
    try {
        const res  = await fetch(`/historico-general/${props.period.id}/personas-sin-sucursal`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        const data = await res.json()
        personasItems.value = data.items ?? []
        personaAction.value  = {}
        personaBranch.value  = {}
    } finally {
        personasLoading.value = false
    }
}

function togglePersonas() {
    showPersonas.value = !showPersonas.value
    if (showPersonas.value && !personasItems.value.length) loadPersonas()
}

function asignarSucursalPersona(emp: any) {
    const branchId = personaBranch.value[empKey(emp)]
    if (!branchId) return
    emit('assign-branch', {
        employee_ids: emp.employee_ids ?? [emp.employee_id],
        employee_id:  emp.employee_id,
        branch_id:    branchId,
        period_id:    props.period?.id,
    })
}

// ── Panel ubicaciones pendientes ───────────────────────────────────────
const locationAction = ref<Record<number, string>>({})
const locationBranch = ref<Record<number, number | null>>({})

function initLocation(incident: any) {
    if (!(incident.id in locationAction.value)) {
        locationAction.value[incident.id] = 'exclude'
        locationBranch.value[incident.id] = null
    }
}

function resolveLocation(incident: any) {
    const action   = locationAction.value[incident.id] ?? 'exclude'
    const branchId = action === 'map_to_branch' ? (locationBranch.value[incident.id] ?? null) : null
    emit('resolve-location', { incident_id: incident.id, action, branch_id: branchId })
}

const LOCATION_ACTIONS = [
    { k: 'map_to_branch',  l: 'Mapear a sucursal' },
    { k: 'exclude',        l: 'Excluir del reporte' },
    { k: 'mark_corporate', l: 'Marcar Corporativo' },
    { k: 'mark_closed',    l: 'Marcar sucursal cerrada' },
]
</script>

<template>
    <section class="rounded-[2rem] border border-white/70 bg-white p-6 shadow-xl shadow-slate-200/70">
        <SectionHeader eyebrow="Etapa 3" title="Incidencias" description="Resuelve incidencias críticas antes de generar el reporte. Las incidencias críticas bloquean la generación.">
            <button type="button" class="rounded-xl border border-slate-200 px-4 py-2 text-sm font-bold text-slate-700 transition hover:bg-slate-50" @click="emit('refresh')">Refrescar</button>
        </SectionHeader>

        <!-- Contadores -->
        <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <!-- Incidencias críticas: count of critical incident groups -->
            <div class="rounded-2xl border border-rose-100 bg-rose-50 p-4">
                <p class="text-xs font-bold text-rose-700">Incidencias críticas</p>
                <p class="mt-1 text-2xl font-black text-rose-800">{{ summary.critical }}</p>
                <p class="mt-0.5 text-[11px] text-rose-500">grupo(s) bloqueantes</p>
            </div>
            <!-- Personas sin sucursal: unique grouped persons vs raw records -->
            <div class="rounded-2xl border border-rose-100 bg-rose-50/60 p-4">
                <p class="text-xs font-bold text-rose-600">Sin sucursal</p>
                <p class="mt-1 text-2xl font-black text-rose-800">{{ summary.uniquePersons }}</p>
                <p class="mt-0.5 text-[11px] text-rose-400">
                    persona(s) agrupada(s)
                    <template v-if="summary.personsAffected > summary.uniquePersons">
                        · {{ summary.personsAffected }} registros
                    </template>
                </p>
            </div>
            <!-- Coincidencias: unique pairs pending resolution -->
            <div class="rounded-2xl border border-violet-100 bg-violet-50 p-4">
                <p class="text-xs font-bold text-violet-700">Coincidencias</p>
                <p class="mt-1 text-2xl font-black text-violet-800">{{ summary.coincidenciasPending }}</p>
                <p class="mt-0.5 text-[11px] text-violet-400">pares únicos pendientes</p>
            </div>
            <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4">
                <p class="text-xs font-bold text-amber-700">Advertencias</p>
                <p class="mt-1 text-2xl font-black text-amber-800">{{ summary.warnings }}</p>
                <p class="mt-0.5 text-[11px] text-amber-400">no bloquean generación</p>
            </div>
            <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-4">
                <p class="text-xs font-bold text-emerald-700">Resueltas</p>
                <p class="mt-1 text-2xl font-black text-emerald-800">{{ summary.resolved }}</p>
                <p class="mt-0.5 text-[11px] text-emerald-400">incidencias cerradas</p>
            </div>
        </div>

        <EmptyState v-if="!incidents.length" class="mt-6" title="Sin incidencias" description="La base operativa no tiene incidencias pendientes para este periodo." />

        <div v-else class="mt-6 space-y-5">

            <!-- ── Panel: Ubicaciones pendientes ─────────────────────────── -->
            <div v-if="pendingLocations.length > 0" class="overflow-hidden rounded-2xl border border-rose-200">
                <div class="flex items-center gap-3 bg-rose-50 px-5 py-4">
                    <MapPin class="size-5 shrink-0 text-rose-600" />
                    <div class="flex-1">
                        <p class="font-black text-slate-950">Ubicaciones pendientes de clasificación</p>
                        <p class="text-xs text-rose-700">
                            {{ pendingUnresolved.length }} ubicación(es) no reconocida(s) — bloquean la generación del reporte.
                            <span v-if="pendingResolved.length > 0" class="text-emerald-700"> · {{ pendingResolved.length }} resuelta(s).</span>
                        </p>
                    </div>
                    <StatusBadge status="error" :label="`${pendingUnresolved.length} crítica(s)`" />
                </div>

                <div class="divide-y divide-slate-100">
                    <div
                        v-for="incident in pendingLocations"
                        :key="incident.id"
                        class="px-5 py-4"
                        :class="incident.severity === 'resolved' ? 'bg-emerald-50/40' : 'bg-white'"
                    >
                        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                            <div class="flex-1">
                                <p class="font-bold text-slate-900">
                                    <CheckCircle2 v-if="incident.severity === 'resolved'" class="mr-1.5 inline size-4 text-emerald-600" />
                                    <AlertTriangle v-else class="mr-1.5 inline size-4 text-rose-600" />
                                    {{ incident.context?.location ?? incident.message }}
                                </p>
                                <p v-if="incident.severity === 'resolved'" class="mt-0.5 text-xs text-emerald-700">
                                    Resuelta: {{ incident.context?.resolved_action ?? 'marcada' }}
                                    <span v-if="incident.context?.resolved_branch"> → {{ incident.context.resolved_branch }}</span>
                                </p>
                            </div>

                            <!-- Acción cuando no resuelta -->
                            <div v-if="incident.severity !== 'resolved'" class="flex flex-wrap items-center gap-2">
                                <div @vue:before-mount="initLocation(incident)">
                                    <select
                                        v-model="locationAction[incident.id]"
                                        class="h-9 rounded-xl border border-slate-200 bg-slate-50 px-3 text-xs font-bold outline-none focus:ring-2 focus:ring-indigo-100"
                                    >
                                        <option v-for="opt in LOCATION_ACTIONS" :key="opt.k" :value="opt.k">{{ opt.l }}</option>
                                    </select>
                                    <div v-if="locationAction[incident.id] === 'map_to_branch'" class="mt-2 w-56">
                                        <SearchableSelect
                                            :items="branchItems"
                                            :model-value="locationBranch[incident.id] ?? null"
                                            placeholder="Sucursal destino..."
                                            @update:model-value="locationBranch[incident.id] = $event"
                                        />
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="rounded-xl bg-slate-900 px-4 py-2 text-xs font-black text-white transition hover:bg-slate-700 disabled:opacity-40"
                                    :disabled="locationAction[incident.id] === 'map_to_branch' && !locationBranch[incident.id]"
                                    @click="resolveLocation(incident)"
                                >
                                    Resolver
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Panel: Personas sin sucursal ───────────────────────────── -->
            <div
                v-if="employeesIncident"
                class="overflow-hidden rounded-2xl border"
                :class="employeesIncident.severity === 'high' ? 'border-rose-300' : 'border-amber-200'"
            >
                <!-- Panel header — severity-aware -->
                <button
                    type="button"
                    class="flex w-full items-center gap-3 px-5 py-4 text-left transition"
                    :class="employeesIncident.severity === 'high'
                        ? 'bg-rose-50 hover:bg-rose-100/60'
                        : 'bg-amber-50 hover:bg-amber-100/60'"
                    @click="togglePersonas"
                >
                    <Users
                        class="size-5 shrink-0"
                        :class="employeesIncident.severity === 'high' ? 'text-rose-600' : 'text-amber-600'"
                    />
                    <div class="flex-1">
                        <p class="font-black text-slate-950">
                            <span v-if="employeesIncident.severity === 'high'" class="mr-1.5 inline-flex items-center rounded-lg bg-rose-600 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-white">Incidencia crítica</span>
                            Personas / gestores sin sucursal asignada
                        </p>
                        <p class="text-xs" :class="employeesIncident.severity === 'high' ? 'text-rose-700' : 'text-amber-700'">
                            <!-- unique_persons_count = grouped persons; count = raw records -->
                            <strong>{{ employeesIncident.context?.unique_persons_count ?? employeesIncident.context?.count ?? 0 }}</strong> persona(s) agrupada(s) sin sucursal
                            <template v-if="(employeesIncident.context?.count ?? 0) > (employeesIncident.context?.unique_persons_count ?? employeesIncident.context?.count ?? 0)">
                                · <strong>{{ employeesIncident.context.count }}</strong> registros afectados (NOI regular + NOI fiscal)
                            </template>
                            <template v-if="(employeesIncident.context?.monto ?? 0) > 0">
                                · Impacto: <strong>${{ Number(employeesIncident.context.monto).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }}</strong>
                            </template>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <StatusBadge
                            :status="employeesIncident.severity === 'high' ? 'error' : 'warning'"
                            :label="employeesIncident.severity === 'high' ? 'Crítico' : 'Advertencia'"
                        />
                        <ChevronDown class="size-4 text-slate-400 transition" :class="showPersonas ? 'rotate-180' : ''" />
                    </div>
                </button>

                <div v-if="showPersonas" class="p-5">
                    <div v-if="personasLoading" class="py-4 text-center text-sm text-slate-400">Cargando personas...</div>
                    <div v-else-if="!personasItems.length" class="py-4 text-center text-sm text-slate-400">Sin datos o todas ya tienen sucursal asignada.</div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-200 text-left">
                                    <th class="pb-2 pr-3 text-xs font-black uppercase tracking-wide text-slate-500">Persona / gestor</th>
                                    <th class="pb-2 pr-3 text-xs font-black uppercase tracking-wide text-slate-500">Fuente</th>
                                    <th class="pb-2 pr-3 text-right text-xs font-black uppercase tracking-wide text-slate-500">Movs.</th>
                                    <th class="pb-2 pr-3 text-right text-xs font-black uppercase tracking-wide text-slate-500">Monto</th>
                                    <th class="pb-2 pr-3 text-xs font-black uppercase tracking-wide text-slate-500">Diagnóstico</th>
                                    <th class="pb-2 text-xs font-black uppercase tracking-wide text-slate-500">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="emp in personasItems" :key="empKey(emp)">
                                    <!-- Main row -->
                                    <tr class="border-b border-slate-100 align-middle">
                                        <td class="py-2.5 pr-3 font-semibold text-slate-900">{{ emp.nombre }}</td>
                                        <td class="py-2.5 pr-3">
                                            <div class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="fuente in (emp.fuentes ?? [emp.fuente])"
                                                    :key="fuente"
                                                    class="inline-flex rounded-lg px-2 py-0.5 text-xs font-bold"
                                                    :class="fuente === 'noi_nomina_fiscal' ? 'bg-violet-100 text-violet-700' : 'bg-slate-100 text-slate-600'"
                                                >{{ fuente === 'noi_nomina_fiscal' ? 'NOI FISCAL' : fuente.replace('noi_nomina', 'NOI REGULAR') }}</span>
                                            </div>
                                        </td>
                                        <td class="py-2.5 pr-3 text-right text-slate-700">{{ emp.movimientos }}</td>
                                        <td class="py-2.5 pr-3 text-right font-bold text-slate-900">${{ Number(emp.monto).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }}</td>
                                        <td class="py-2.5 pr-3">
                                            <button
                                                type="button"
                                                class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-700 transition hover:bg-slate-100"
                                                @click="toggleDiagnosis(emp)"
                                            >
                                                <Info class="size-3.5 shrink-0" />
                                                {{ diagnosisOpen[empKey(emp)] ? 'Ocultar' : 'Ver diagnóstico' }}
                                                <ChevronRight class="size-3 transition" :class="diagnosisOpen[empKey(emp)] ? 'rotate-90' : ''" />
                                            </button>
                                        </td>
                                        <td class="py-2.5 min-w-[260px]">
                                            <!-- A) Fuzzy-name variant → ask "same person?" first -->
                                            <template v-if="needsConfirmation(emp) && personaAction[empKey(emp)] !== 'reject'">
                                                <div class="space-y-1.5">
                                                    <div class="rounded-xl border px-3 py-2 text-xs"
                                                        :class="hasBranchFromMatch(emp) ? 'border-emerald-200 bg-emerald-50' : 'border-amber-200 bg-amber-50'">
                                                        <p class="font-black" :class="hasBranchFromMatch(emp) ? 'text-emerald-800' : 'text-amber-800'">
                                                            {{ hasBranchFromMatch(emp) ? 'Persona existente con sucursal' : 'Posible misma persona' }}
                                                        </p>
                                                        <p class="mt-0.5 truncate font-semibold text-slate-700">{{ emp.best_match?.name }}</p>
                                                        <p v-if="emp.best_match?.branch" class="text-emerald-700">→ {{ emp.best_match.branch }}</p>
                                                        <p v-else class="text-amber-700">→ sin sucursal</p>
                                                        <p class="text-slate-400">score: {{ emp.best_match?.score }}%</p>
                                                    </div>
                                                    <div class="flex flex-wrap gap-1.5">
                                                        <button type="button"
                                                            class="rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-black text-white transition hover:bg-emerald-700"
                                                            @click="confirmMatch(emp)">
                                                            ✓ Es la misma persona
                                                        </button>
                                                        <button type="button"
                                                            class="rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100"
                                                            @click="rejectMatch(emp)">
                                                            No, es otra
                                                        </button>
                                                    </div>
                                                    <button type="button"
                                                        class="text-xs text-slate-400 hover:text-indigo-600 hover:underline"
                                                        @click="rejectMatch(emp)">
                                                        · Asignar sucursal manualmente
                                                    </button>
                                                </div>
                                            </template>

                                            <!-- B) Direct branch selector (exact same name grouped, or no fuzzy match) -->
                                            <template v-else>
                                                <div class="space-y-1.5">
                                                    <p v-if="isSamePersonNoBranch(emp)" class="text-xs text-slate-500">
                                                        <template v-if="(emp.employee_ids ?? [emp.employee_id]).length > 1">
                                                            Aparece en {{ (emp.fuentes ?? [emp.fuente]).length }} fuentes — sin sucursal en el sistema.
                                                        </template>
                                                        <template v-else>
                                                            Misma persona (nombre exacto) — sin sucursal en el sistema.
                                                        </template>
                                                    </p>
                                                    <div class="flex flex-col gap-1.5 sm:flex-row sm:items-start">
                                                        <div class="min-w-[210px] max-w-[280px] w-full">
                                                            <SearchableSelect
                                                                :items="branchItems"
                                                                :model-value="personaBranch[empKey(emp)] ?? null"
                                                                placeholder="Seleccionar sucursal..."
                                                                @update:model-value="personaBranch[empKey(emp)] = $event"
                                                            />
                                                        </div>
                                                        <button
                                                            type="button"
                                                            class="shrink-0 rounded-xl bg-indigo-600 px-4 py-2 text-xs font-black text-white transition hover:bg-indigo-700 disabled:opacity-40"
                                                            :disabled="!personaBranch[empKey(emp)]"
                                                            @click="asignarSucursalPersona(emp)"
                                                        >
                                                            Asignar sucursal
                                                        </button>
                                                    </div>
                                                </div>
                                            </template>
                                        </td>
                                    </tr>

                                    <!-- Diagnosis row -->
                                    <tr v-if="diagnosisOpen[empKey(emp)]" class="bg-slate-50/70">
                                        <td colspan="6" class="px-4 pb-5 pt-3">
                                            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">

                                                <!-- Header -->
                                                <p class="mb-3 text-xs font-black uppercase tracking-wider text-slate-500">Diagnóstico de resolución — {{ emp.nombre }}</p>

                                                <div class="grid gap-4 sm:grid-cols-2">

                                                    <!-- Búsquedas realizadas -->
                                                    <div>
                                                        <p class="mb-1.5 text-xs font-black text-slate-700">Búsquedas realizadas</p>
                                                        <ul class="space-y-1 text-xs text-slate-600">
                                                            <li class="flex items-center gap-1.5">
                                                                <CheckCircle2 class="size-3.5 shrink-0 text-slate-400" />
                                                                Nombre exacto normalizado:
                                                                <code class="ml-1 rounded bg-slate-100 px-1 font-mono text-[11px]">{{ emp.diagnosis?.normalized_name }}</code>
                                                            </li>
                                                            <li class="flex items-center gap-1.5">
                                                                <CheckCircle2 class="size-3.5 shrink-0 text-slate-400" />
                                                                Coincidencia aproximada (fuzzy ≥80%)
                                                            </li>
                                                            <li class="flex items-center gap-1.5">
                                                                <CheckCircle2 class="size-3.5 shrink-0 text-slate-400" />
                                                                Prefijo de contrato (HUAM, ATLA, etc.)
                                                            </li>
                                                            <li class="flex items-center gap-1.5">
                                                                <CheckCircle2 class="size-3.5 shrink-0 text-slate-400" />
                                                                Ministraciones / Colocación (promotor)
                                                            </li>
                                                            <li class="flex items-center gap-1.5">
                                                                <span :class="emp.diagnosis?.checked_lendus_directory ? 'text-emerald-600' : 'text-slate-300'">
                                                                    <CheckCircle2 v-if="emp.diagnosis?.checked_lendus_directory" class="size-3.5" />
                                                                    <XCircle v-else class="size-3.5" />
                                                                </span>
                                                                Directorio empleados Lendus
                                                                <span v-if="emp.diagnosis?.lendus_match" class="text-emerald-700 font-bold">
                                                                    → {{ emp.diagnosis.lendus_match.branch_name }}
                                                                </span>
                                                                <span v-else-if="emp.diagnosis?.checked_lendus_directory" class="text-slate-400 italic">no encontrado</span>
                                                                <span v-else class="text-slate-300 italic">sin datos cargados</span>
                                                            </li>
                                                            <li class="flex items-center gap-1.5">
                                                                <CheckCircle2 class="size-3.5 shrink-0 text-slate-400" />
                                                                Asignaciones históricas en periodos anteriores
                                                            </li>
                                                        </ul>
                                                    </div>

                                                    <!-- Códigos detectados -->
                                                    <div>
                                                        <p class="mb-1.5 text-xs font-black text-slate-700">Códigos detectados</p>
                                                        <ul class="space-y-1 text-xs">
                                                            <li class="flex items-center gap-1.5">
                                                                <span :class="emp.diagnosis?.detected_codes?.employee_code ? 'text-emerald-600' : 'text-slate-400'">
                                                                    <CheckCircle2 v-if="emp.diagnosis?.detected_codes?.employee_code" class="size-3.5" />
                                                                    <XCircle v-else class="size-3.5" />
                                                                </span>
                                                                <span class="text-slate-600">Código empleado:</span>
                                                                <span class="font-bold">{{ emp.diagnosis?.detected_codes?.employee_code ?? 'N/D' }}</span>
                                                            </li>
                                                            <li class="flex items-center gap-1.5">
                                                                <span :class="emp.diagnosis?.detected_codes?.contract_prefix ? 'text-emerald-600' : 'text-slate-400'">
                                                                    <CheckCircle2 v-if="emp.diagnosis?.detected_codes?.contract_prefix" class="size-3.5" />
                                                                    <XCircle v-else class="size-3.5" />
                                                                </span>
                                                                <span class="text-slate-600">Prefijo contrato:</span>
                                                                <span class="font-bold">{{ emp.diagnosis?.detected_codes?.contract_prefix ?? 'N/D' }}</span>
                                                            </li>
                                                            <li v-if="emp.diagnosis?.detected_codes?.lendus_codigo" class="flex items-center gap-1.5">
                                                                <CheckCircle2 class="size-3.5 shrink-0 text-emerald-600" />
                                                                <span class="text-slate-600">Código Lendus:</span>
                                                                <span class="font-bold text-emerald-700">{{ emp.diagnosis.detected_codes.lendus_codigo }}</span>
                                                                <span v-if="emp.diagnosis?.detected_codes?.lendus_puesto" class="text-slate-500">({{ emp.diagnosis.detected_codes.lendus_puesto }})</span>
                                                            </li>
                                                            <li class="flex items-center gap-1.5">
                                                                <span :class="emp.diagnosis?.detected_codes?.branch_hint ? 'text-emerald-600' : 'text-slate-400'">
                                                                    <CheckCircle2 v-if="emp.diagnosis?.detected_codes?.branch_hint" class="size-3.5" />
                                                                    <XCircle v-else class="size-3.5" />
                                                                </span>
                                                                <span class="text-slate-600">Sucursal sugerida:</span>
                                                                <span class="font-bold">{{ emp.diagnosis?.detected_codes?.branch_hint ?? 'N/D' }}</span>
                                                            </li>
                                                        </ul>
                                                        <!-- raw_clues -->
                                                        <ul v-if="emp.diagnosis?.detected_codes?.raw_clues?.length" class="mt-2 space-y-1 text-xs text-slate-500">
                                                            <li v-for="clue in emp.diagnosis.detected_codes.raw_clues" :key="clue" class="italic">· {{ clue }}</li>
                                                        </ul>
                                                    </div>

                                                </div>

                                                <!-- Posibles coincidencias -->
                                                <div v-if="emp.diagnosis?.candidate_matches?.length" class="mt-4">
                                                    <p class="mb-1.5 text-xs font-black text-slate-700">Posibles coincidencias</p>
                                                    <div class="space-y-1">
                                                        <div
                                                            v-for="m in emp.diagnosis.candidate_matches"
                                                            :key="m.employee_id"
                                                            class="flex items-center gap-3 rounded-xl border px-3 py-2 text-xs"
                                                            :class="m.accepted ? 'border-emerald-200 bg-emerald-50' : 'border-slate-200 bg-slate-50'"
                                                        >
                                                            <span class="font-bold text-slate-900">{{ m.name }}</span>
                                                            <span
                                                                class="rounded-lg px-2 py-0.5 font-black"
                                                                :class="m.score >= 80 ? 'bg-emerald-100 text-emerald-700' : m.score >= 70 ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-500'"
                                                            >{{ m.score }}%</span>
                                                            <span class="text-slate-500">{{ m.reason }}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div v-else class="mt-3 text-xs text-slate-400 italic">
                                                    Sin coincidencias con score ≥60%.
                                                </div>

                                                <!-- Sample movements -->
                                                <div v-if="emp.diagnosis?.sample_movements?.length" class="mt-4">
                                                    <p class="mb-1.5 text-xs font-black text-slate-700">Muestra de movimientos</p>
                                                    <div class="overflow-x-auto">
                                                        <table class="w-full text-xs">
                                                            <thead>
                                                                <tr class="border-b border-slate-200">
                                                                    <th class="pb-1 pr-3 text-left font-black text-slate-500">Fecha</th>
                                                                    <th class="pb-1 pr-3 text-left font-black text-slate-500">Concepto</th>
                                                                    <th class="pb-1 pr-3 text-right font-black text-slate-500">Monto</th>
                                                                    <th class="pb-1 pr-3 text-left font-black text-slate-500">Fuente</th>
                                                                    <th class="pb-1 text-left font-black text-slate-500">Código</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody class="divide-y divide-slate-100">
                                                                <tr v-for="(mov, mi) in emp.diagnosis.sample_movements" :key="mi">
                                                                    <td class="py-1 pr-3 text-slate-500">{{ mov.date ?? '—' }}</td>
                                                                    <td class="py-1 pr-3 text-slate-700">{{ mov.concept }}</td>
                                                                    <td class="py-1 pr-3 text-right font-bold">${{ Number(mov.amount).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</td>
                                                                    <td class="py-1 pr-3 text-slate-500">{{ mov.source ?? '—' }}</td>
                                                                    <td class="py-1 font-mono text-[11px] text-slate-400">{{ mov.code ?? '—' }}</td>
                                                                </tr>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>

                                                <!-- Result -->
                                                <div class="mt-4 space-y-1.5 rounded-xl border p-3"
                                                    :class="(needsConfirmation(emp) || isSamePersonNoBranch(emp)) ? 'border-amber-200 bg-amber-50' : 'border-rose-200 bg-rose-50'">
                                                    <p class="text-xs font-black"
                                                        :class="(needsConfirmation(emp) || isSamePersonNoBranch(emp)) ? 'text-amber-800' : 'text-rose-800'">
                                                        <template v-if="emp.resolution_type === 'fuzzy_match_has_branch'">
                                                            Persona equivalente con sucursal detectada — confirma para heredar sucursal.
                                                        </template>
                                                        <template v-else-if="emp.resolution_type === 'exact_name_no_branch'">
                                                            Misma persona (nombre exacto) — ningún registro tiene sucursal asignada aún.
                                                        </template>
                                                        <template v-else-if="emp.resolution_type === 'fuzzy_match_no_branch'">
                                                            Posible misma persona (variante de nombre) — ninguna tiene sucursal aún.
                                                        </template>
                                                        <template v-else-if="emp.resolution_type === 'possible_match'">
                                                            Coincidencia probable (no segura) — requiere confirmación.
                                                        </template>
                                                        <template v-else>
                                                            Resultado: No resuelta
                                                        </template>
                                                    </p>
                                                    <p class="text-xs leading-5" :class="needsConfirmation(emp) ? 'text-amber-700' : 'text-rose-700'">{{ emp.diagnosis?.reason }}</p>
                                                    <p class="mt-1 text-xs font-bold text-indigo-700">
                                                        → {{ emp.diagnosis?.suggested_action }}
                                                    </p>
                                                </div>

                                            </div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div class="mt-3 flex justify-end">
                            <button type="button" class="text-xs font-bold text-indigo-600 hover:underline" @click="loadPersonas">Recargar lista</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Panel: Posibles coincidencias de persona ───────────────── -->
            <div
                v-if="duplicatesIncident"
                class="overflow-hidden rounded-2xl border"
                :class="duplicatesIncident.severity === 'high' ? 'border-rose-300' : 'border-amber-200'"
            >
                <div
                    class="flex items-center gap-3 px-5 py-4"
                    :class="duplicatesIncident.severity === 'high' ? 'bg-rose-50' : 'bg-amber-50'"
                >
                    <Copy
                        class="size-5 shrink-0"
                        :class="duplicatesIncident.severity === 'high' ? 'text-rose-600' : 'text-amber-600'"
                    />
                    <div class="flex-1">
                        <p class="font-black text-slate-950">
                            <span v-if="duplicatesIncident.severity === 'high'" class="mr-1.5 inline-flex items-center rounded-lg bg-rose-600 px-2 py-0.5 text-[10px] font-black uppercase tracking-wide text-white">Crítico</span>
                            Posibles coincidencias de persona
                        </p>
                        <p class="text-xs" :class="duplicatesIncident.severity === 'high' ? 'text-rose-700' : 'text-amber-700'">
                            <strong>{{ duplicatesIncident.context?.count ?? 0 }}</strong> par(es) detectado(s)
                            <template v-if="(duplicatesIncident.context?.monto ?? 0) > 0">
                                · Impacto monetario: <strong>${{ Number(duplicatesIncident.context.monto).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }}</strong>
                            </template>
                        </p>
                    </div>
                    <StatusBadge
                        :status="duplicatesIncident.severity === 'high' ? 'error' : 'warning'"
                        :label="duplicatesIncident.severity === 'high' ? 'Crítico' : 'Advertencia'"
                    />
                </div>
                <div v-if="duplicatesIncident.context?.samples?.length" class="p-5">
                    <p class="mb-1 text-xs font-bold text-slate-500">Nombres similares que podrían ser la misma persona escrita de forma diferente:</p>
                    <p class="mb-3 text-xs text-slate-400">Registros con nombre exacto ya se unifican automáticamente y no aparecen aquí. Resuelve cada par desde esta misma pantalla.</p>
                    <div class="space-y-3">
                        <template v-for="pair in duplicatesIncident.context.samples" :key="getPairKey(pair)">
                            <!-- Hidden after action -->
                            <div
                                v-if="isPairHidden(pair)"
                                class="flex items-center gap-3 rounded-2xl border px-4 py-2.5 text-xs"
                                :class="isPairConfirmed(pair)
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : 'border-slate-200 bg-slate-50 text-slate-400'"
                            >
                                <CheckCircle2 v-if="isPairConfirmed(pair)" class="size-4 shrink-0 text-emerald-600" />
                                <XCircle     v-else                       class="size-4 shrink-0 text-slate-400" />
                                <span class="font-bold" :class="isPairConfirmed(pair) ? '' : 'line-through'">{{ pair.a }}</span>
                                <span>·</span>
                                <span class="font-bold" :class="isPairConfirmed(pair) ? '' : 'line-through'">{{ pair.b }}</span>
                                <span class="ml-auto italic">
                                    {{ isPairConfirmed(pair) ? 'Unificados — alias guardado.' : 'Descartado permanentemente.' }}
                                </span>
                            </div>

                            <!-- Pending pair -->
                            <div
                                v-else
                                class="rounded-2xl border bg-white px-4 py-3"
                                :class="duplicatesIncident.severity === 'high' ? 'border-rose-100' : 'border-amber-100'"
                            >
                                <!-- Names + score row -->
                                <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-4">
                                    <div class="min-w-0 flex-1">
                                        <span class="text-sm font-bold text-slate-900">{{ pair.a }}</span>
                                        <span v-if="pair.a_source" class="ml-2 rounded-lg bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{{ pair.a_source }}</span>
                                    </div>
                                    <div class="flex shrink-0 flex-col items-center gap-0.5">
                                        <span class="text-xs font-black" :class="duplicatesIncident.severity === 'high' ? 'text-rose-600' : 'text-amber-600'">≈ posible coincidencia ≈</span>
                                        <span v-if="pair.score" class="text-[10px] text-slate-400">{{ pair.score }}% similitud</span>
                                    </div>
                                    <div class="min-w-0 flex-1 sm:text-right">
                                        <span class="text-sm font-bold text-slate-900">{{ pair.b }}</span>
                                        <span v-if="pair.b_source" class="ml-2 rounded-lg bg-slate-100 px-1.5 py-0.5 text-[10px] font-bold text-slate-500">{{ pair.b_source }}</span>
                                    </div>
                                </div>

                                <!-- Reason + monto -->
                                <div v-if="pair.reason || (pair.monto_relacionado ?? 0) > 0" class="mt-1.5 text-[11px] text-slate-400">
                                    <span v-if="pair.reason">{{ pair.reason }}</span>
                                    <span v-if="(pair.monto_relacionado ?? 0) > 0" class="ml-2 font-bold text-amber-600">
                                        Impacto: ${{ Number(pair.monto_relacionado).toLocaleString('es-MX', { minimumFractionDigits: 0, maximumFractionDigits: 0 }) }}
                                    </span>
                                </div>

                                <!-- Warning when IDs missing (stale incident — refresh to fix) -->
                                <div v-if="!pair.a_id || !pair.b_id" class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-700">
                                    ⚠ Sin IDs — pulsa <strong>Refrescar</strong> en la cabecera para regenerar las coincidencias.
                                </div>

                                <!-- Action buttons -->
                                <div v-else class="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3 py-1.5 text-xs font-black text-white transition hover:bg-emerald-700 disabled:opacity-40"
                                        :disabled="isPairLoading(pair)"
                                        @click="confirmDuplicatePair(pair)"
                                    >
                                        <svg v-if="isPairLoading(pair)" class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        <template v-else>✓</template>
                                        Es la misma persona
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 px-3 py-1.5 text-xs font-bold text-slate-600 transition hover:bg-slate-100 disabled:opacity-40"
                                        :disabled="isPairLoading(pair)"
                                        @click="rejectDuplicatePair(pair)"
                                    >
                                        <svg v-if="isPairLoading(pair)" class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                        <template v-else>✕</template>
                                        No, son diferentes
                                    </button>
                                </div>
                            </div>
                        </template>
                    </div>
                    <div class="mt-3 space-y-1 text-xs text-slate-500">
                        <p><span class="font-bold text-emerald-700">✓ Es la misma persona</span> — crea alias permanente, guarda nombre canónico y hereda sucursal. Se unifica en la BD.</p>
                        <p><span class="font-bold text-slate-600">✕ No, son diferentes</span> — guarda el descarte en la BD (<code>employee_match_rejections</code>). El par no volverá a aparecer. No se elimina ningún empleado ni movimiento.</p>
                    </div>
                </div>
            </div>

            <!-- ── Incidencias genéricas con filtros ──────────────────────── -->
            <div v-if="genericIncidents.length > 0">
                <div class="mb-3 flex flex-col gap-3 md:flex-row">
                    <label class="relative flex-1">
                        <Search class="pointer-events-none absolute left-4 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                        <input v-model="query" class="h-11 w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 text-sm outline-none focus:border-indigo-300 focus:ring-4 focus:ring-indigo-100" placeholder="Buscar incidencia..." />
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <button v-for="option in [{k:'all',l:'Todas'},{k:'high',l:'Críticas'},{k:'warning',l:'Advertencias'},{k:'resolved',l:'Resueltas'}]" :key="option.k" type="button" class="rounded-xl px-4 py-2 text-sm font-bold transition" :class="filter === option.k ? 'bg-indigo-600 text-white' : 'border border-slate-200 text-slate-700 hover:bg-slate-50'" @click="filter = option.k">{{ option.l }}</button>
                    </div>
                </div>

                <div class="overflow-hidden rounded-2xl border border-slate-200">
                    <div v-for="item in filteredGeneric" :key="item.id" class="border-b border-slate-100 p-4 last:border-b-0">
                        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                            <div class="flex gap-3">
                                <AlertTriangle v-if="item.severity === 'high'" class="mt-1 size-5 shrink-0 text-rose-600" />
                                <CheckCircle2 v-else-if="item.severity === 'resolved'" class="mt-1 size-5 shrink-0 text-emerald-600" />
                                <AlertTriangle v-else class="mt-1 size-5 shrink-0 text-amber-600" />
                                <div>
                                    <p class="font-black text-slate-950">{{ item.type }}</p>
                                    <p class="mt-1 text-sm leading-6 text-slate-600">{{ item.message }}</p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <StatusBadge :status="item.severity === 'resolved' ? 'resolved' : item.severity === 'high' ? 'error' : 'warning'" :label="item.severity === 'high' ? 'Crítica' : item.severity === 'resolved' ? 'Resuelta' : 'Advertencia'" />
                                <button v-if="item.severity !== 'resolved'" type="button" class="rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white transition hover:bg-slate-700" @click="emit('resolve', item.id)">Resolver</button>
                            </div>
                        </div>
                    </div>
                    <div v-if="filteredGeneric.length === 0" class="p-6 text-center text-sm text-slate-400">Sin incidencias que coincidan con el filtro.</div>
                </div>
            </div>

        </div>
    </section>
</template>
