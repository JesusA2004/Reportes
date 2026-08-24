<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { Head, router, useForm } from '@inertiajs/vue3'
import Swal from 'sweetalert2'
import { CalendarDays } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import WorkflowStepper from '@/components/historico-general/WorkflowStepper.vue'
import PeriodSelector from '@/components/historico-general/PeriodSelector.vue'
import UploadSourcesStep from '@/components/historico-general/UploadSourcesStep.vue'
import DatabaseUpdateStep from '@/components/historico-general/DatabaseUpdateStep.vue'
import IncidentsStep from '@/components/historico-general/IncidentsStep.vue'
import ReportConfigurationStep from '@/components/historico-general/ReportConfigurationStep.vue'
import GenerateReportStep from '@/components/historico-general/GenerateReportStep.vue'
import ReportPreview from '@/components/historico-general/ReportPreview.vue'
import GeneratedReportActions from '@/components/historico-general/GeneratedReportActions.vue'
import AutomaticPeriodDetail from '@/components/historico-general/AutomaticPeriodDetail.vue'
import { useHistoricWorkflow } from '@/composables/useHistoricWorkflow'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    periods: any[]
    sources: any[]
    groupedUploads: any[]
    currentPeriodId: number | null
    preview: any | null
    branches: Array<{ id: number; name: string }>
    employees: Array<{ id: number; full_name: string; branch_name?: string }>
}>()

const OPERATIVE_BRANCH_NAMES_INIT = new Set([
    'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
    'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN JUAN DEL RÍO',
    'SAN LUIS POTOSI', 'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
])

const selectedPeriodId    = ref<number | null>(props.currentPeriodId ?? null)
const periodEmployees     = ref<Array<{ id: number; full_name: string; branch_name?: string }>>(props.employees ?? [])
const incidents           = ref<any[]>([])
const incidentsHasData    = ref<boolean | null>(null)  // null = not loaded yet
const incidentsNoDataMsg  = ref<string>('')
const incidentsReloadKey  = ref(0)
const incidentsStepRef    = ref<any>(null)
const reportConfig     = ref({
    report_type: 'simple',
    scope: 'general',
    branch_id: null as number | null,
    employee_id: null as number | null,
    compare_period_id: null as number | null,
    extra_employee_expense_amount: 0,
    extra_employee_expense_notes: '',
    included_branch_ids: props.branches
        .filter((b) => OPERATIVE_BRANCH_NAMES_INIT.has(b.name.trim().toUpperCase()))
        .map((b) => b.id),
})

const period  = computed(() => props.periods.find((p) => p.id === selectedPeriodId.value) ?? null)
const grouped = computed(() => props.groupedUploads.find((p) => p.period_id === selectedPeriodId.value) ?? null)
const uploadsBySource = computed(() => {
    const map: Record<string, any> = {}
    for (const u of grouped.value?.uploads ?? []) map[u.source_code] = u
    return map
})

// True when the report config in Step 4 is complete enough to proceed to Step 5.
const configValid = computed(() => {
    const cfg = reportConfig.value
    if (!cfg.report_type) return false
    if (cfg.report_type !== 'simple' && !cfg.compare_period_id) return false
    if (cfg.scope === 'branch') return cfg.branch_id !== null
    if (cfg.scope === 'employee') return cfg.employee_id !== null
    return cfg.included_branch_ids.length > 0
})

const { currentStep, steps, selectStep } = useHistoricWorkflow(period, incidents, configValid)
const form = useForm({ period_id: '', data_source_id: '', file: null as File | null, notes: '', covered_period_ids: [] as number[] })

// ── Carga de incidencias ──────────────────────────────────────────────
async function loadIncidents() {
    if (!selectedPeriodId.value) return
    const wasBlocked = !period.value?.can_generate_radiography
    const res  = await fetch(`/historico-general/${selectedPeriodId.value}/incidencias`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    const data = await res.json()
    incidents.value      = data.items ?? []
    incidentsHasData.value = data.has_data !== false  // undefined = true (old API compat)
    incidentsNoDataMsg.value = data.message ?? ''
    // When the period was blocked and all critical incidents are now resolved, refresh the
    // Inertia period prop so can_generate_radiography updates without a full page reload.
    if (wasBlocked && data.has_data !== false && data.has_critical === false) {
        router.reload({ only: ['periods'] })
    }
}

// El colaborador debe seleccionarse SIEMPRE del roster canónico del periodo en
// curso (deduplicado, sucursal resuelta) — nunca de la lista estática que llegó
// en el primer render de la página, que corresponde a otro periodo en cuanto el
// usuario cambia la selección en el stepper.
async function loadPeriodEmployees() {
    if (!selectedPeriodId.value) { periodEmployees.value = []; return }
    try {
        const res  = await fetch(`/historico-general/${selectedPeriodId.value}/colaboradores`, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        const data = await res.json()
        periodEmployees.value = data.employees ?? []
    } catch {
        periodEmployees.value = []
    }
}

watch(selectedPeriodId, () => {
    incidents.value        = []
    incidentsHasData.value = null
    incidentsNoDataMsg.value = ''
    currentStep.value      = 'files'
    loadPeriodEmployees()
    // Solo cargar incidencias si los registros ya fueron cargados
    if (period.value?.database_updated) loadIncidents()
}, { immediate: true })

// ── Polling ──────────────────────────────────────────────────────────
const isDbUpdating      = computed(() => ['queued', 'running'].includes(period.value?.database_update_run_status))
const isRadioGenerating = computed(() => ['queued', 'running'].includes(period.value?.radiography_run_status))

let pollInterval: ReturnType<typeof setInterval> | null = null

function startPolling() {
    if (pollInterval) return
    pollInterval = setInterval(() => {
        router.reload({ only: ['periods', 'groupedUploads'] })
    }, 7000)
}

function stopPolling() {
    if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
}

watch([isDbUpdating, isRadioGenerating], ([db, radio]) => {
    if (db || radio) startPolling()
    else stopPolling()
}, { immediate: true })

// Notificar cuando BD termina (queued/running → success/failed)
watch(() => period.value?.database_update_run_status, (newStatus, oldStatus) => {
    const wasRunning = ['queued', 'running'].includes(String(oldStatus ?? ''))
    if (!wasRunning) return

    if (newStatus === 'success') {
        loadIncidents().then(() => {
            Swal.fire({
                title: 'Registros cargados',
                html: `Los registros del periodo <strong>${period.value?.label ?? ''}</strong> se cargaron correctamente.<br><br>Puedes revisar las incidencias y continuar.`,
                icon: 'success',
                confirmButtonText: 'Revisar incidencias',
                showCancelButton: true,
                cancelButtonText: 'Continuar después',
                reverseButtons: true,
            }).then((r) => {
                if (r.isConfirmed) selectStep('incidents')
            })
        })
    } else if (newStatus === 'failed') {
        Swal.fire({
            title: 'La carga de registros falló',
            text: period.value?.database_update_run_error ?? 'El proceso terminó con error. Revisa el detalle en la etapa de Cargar registros.',
            icon: 'error',
            confirmButtonText: 'Ver detalle',
        }).then(() => selectStep('load'))
    } else if (newStatus === 'cancelled') {
        Swal.fire({
            title: 'Carga cancelada',
            text: 'El proceso fue cancelado. Puedes reiniciarlo cuando estés listo.',
            icon: 'info',
            confirmButtonText: 'Entendido',
        })
    }
})

// Notificar cuando la generación de reporte termina (queued/running → success/failed)
watch(() => period.value?.radiography_run_status, (newStatus, oldStatus) => {
    const wasRunning = ['queued', 'running'].includes(String(oldStatus ?? ''))
    if (!wasRunning) return

    if (newStatus === 'success' || (newStatus === 'failed' && period.value?.radiography_ready)) {
        // El run más reciente puede marcar 'failed' (ej. un paso posterior a la
        // consolidación, como el resolver de gastos) mientras el reporte en sí ya
        // quedó generado correctamente (radiography_ready=true). En ese caso el
        // resultado real es éxito — mostrar error aquí sería un falso negativo.
        Swal.fire({
            title: '¡Reporte generado!',
            html: `El reporte del periodo <strong>${period.value?.label ?? ''}</strong> ya está listo.<br><br>Descarga Excel y PDF desde el paso de reporte o en Reportes mensuales.`,
            icon: 'success',
            confirmButtonText: 'Ver resultado',
        }).then(() => {
            if (!period.value?.is_derived) selectStep('generate')
        })
    } else if (newStatus === 'failed') {
        Swal.fire({
            title: 'La generación falló',
            text: period.value?.radiography_run_error ?? 'El proceso terminó con error. Revisa el detalle en la etapa de generar reporte.',
            icon: 'error',
            confirmButtonText: 'Ver detalle',
        }).then(() => selectStep('generate'))
    } else if (newStatus === 'cancelled') {
        Swal.fire({
            title: 'Generación cancelada',
            text: 'El proceso fue cancelado. Puedes reiniciarlo cuando estés listo.',
            icon: 'info',
            confirmButtonText: 'Entendido',
        })
    }
})

onUnmounted(stopPolling)

// ── Helpers ───────────────────────────────────────────────────────────
const toastError = (title: string, text: string) =>
    Swal.fire({ title, text, icon: 'warning', confirmButtonText: 'Entendido' })

// ── Acciones ──────────────────────────────────────────────────────────
const uploadFile = async ({ sourceId, file }: { sourceId: number; file: File }) => {
    if (!period.value) return toastError('Selecciona periodo', 'Elige un periodo antes de cargar archivos.')
    if (period.value.type === 'weekly') return toastError('Semana base', 'Los archivos se suben al mes operativo que agrupa esta semana. Selecciona el mes operativo correspondiente.')
    if (period.value.is_derived) return toastError('Periodo automático', 'Este periodo es automático (bimestral/trimestral/etc.) y no recibe archivos directos.')
    if (!period.value.can_receive_uploads) return toastError('Mes sin configurar', 'Este mes operativo no tiene semanas asociadas. Ve a Periodos para configurarlo primero.')
    form.period_id         = String(selectedPeriodId.value)
    form.data_source_id    = String(sourceId)
    form.file              = file
    form.covered_period_ids = period.value?.component_period_ids?.length
        ? (period.value.component_period_ids as number[])
        : [selectedPeriodId.value as number]
    Swal.fire({ title: 'Subiendo archivo', text: 'Validando formato y guardando fuente.', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() })
    form.post('/historico-general', { forceFormData: true, preserveScroll: true, onSuccess: () => Swal.fire('Archivo subido', 'La fuente quedó registrada para este periodo.', 'success'), onError: () => Swal.fire('Error de carga', 'Revisa formato, periodo y fuente seleccionada.', 'error') })
}

const deleteUpload = async (id: number) => {
    const result = await Swal.fire({ title: '¿Eliminar archivo?', text: 'Se quitará esta fuente del periodo.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', reverseButtons: true })
    if (!result.isConfirmed) return
    router.delete(`/historico-general/${id}`, { preserveScroll: true, onSuccess: () => Swal.fire('Archivo eliminado', 'La fuente fue retirada correctamente.', 'success'), onError: () => Swal.fire('No se pudo eliminar', 'Intenta nuevamente.', 'error') })
}

const updateDatabase = async () => {
    if (!selectedPeriodId.value) return
    const result = await Swal.fire({ title: 'Cargar registros', text: 'Se enviará el proceso a cola. Recibirás un correo cuando termine.', icon: 'info', showCancelButton: true, confirmButtonText: 'Enviar a cola', cancelButtonText: 'Cancelar', reverseButtons: true })
    if (!result.isConfirmed) return
    router.post(`/historico-general/${selectedPeriodId.value}/actualizar-bd`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Proceso en cola', text: 'Puedes cerrar esta ventana. Te avisaremos por correo cuando los registros terminen de cargarse.', icon: 'success', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo iniciar', 'Faltan fuentes o ya hay un proceso en ejecución.', 'error'),
    })
}

// ── Incidencias críticas — bloquean Paso 4 ───────────────────────────
const hasCriticalIncidents = computed(() =>
    incidents.value.some((i) => ['high', 'critical'].includes(i.severity) && i.severity !== 'resolved')
)

const cancelDatabaseUpdate = async () => {
    if (!selectedPeriodId.value) return
    const result = await Swal.fire({
        title: '¿Cancelar la carga de registros?',
        text: 'El proceso se marcará como cancelado. Podrás reiniciarlo desde la etapa de Cargar registros.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar proceso',
        cancelButtonText: 'No, dejar corriendo',
        reverseButtons: true,
        confirmButtonColor: '#ef4444',
    })
    if (!result.isConfirmed) return
    router.post(`/historico-general/${selectedPeriodId.value}/actualizacion-bd/cancelar`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Carga cancelada', text: 'Puedes reiniciar la carga cuando estés listo.', icon: 'info', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo cancelar', 'El proceso ya terminó o no existe.', 'error'),
    })
}

const processNow = async () => {
    if (!selectedPeriodId.value) return
    const result = await Swal.fire({
        title: 'Procesar ahora (local)',
        text: 'Ejecuta el job directamente sin worker. La página esperará hasta que termine (puede tardar varios minutos).',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Procesar ahora',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    })
    if (!result.isConfirmed) return
    Swal.fire({ title: 'Procesando…', text: 'Ejecutando carga de registros directamente. No cierres esta ventana.', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() })
    router.post(`/historico-general/${selectedPeriodId.value}/actualizacion-bd/procesar-ahora`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Listo', text: 'El proceso terminó. Revisa el resultado.', icon: 'success', confirmButtonText: 'Ver resultado' }),
        onError:   () => Swal.fire({ title: 'El proceso terminó con error', text: 'Revisa el detalle en la etapa de carga.', icon: 'error', confirmButtonText: 'Ver detalle' }).then(() => selectStep('load')),
    })
}

const processPendingSources = async () => {
    if (!selectedPeriodId.value) return
    const pendingCount = grouped.value?.pending_count ?? 0
    if (!pendingCount) return Swal.fire({ title: 'Sin fuentes pendientes', text: 'No hay archivos pendientes de procesar en este periodo.', icon: 'info', confirmButtonText: 'Entendido' })
    const result = await Swal.fire({
        title: 'Procesar fuentes pendientes',
        html: `Se enviarán a procesamiento <strong>${pendingCount}</strong> archivo(s) pendiente(s) (IMSS, Rotación, etc.), sin volver a cargar los registros base.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Procesar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    })
    if (!result.isConfirmed) return
    router.post(`/historico-general/${selectedPeriodId.value}/procesar-fuentes-pendientes`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Procesamiento enviado', text: 'Las fuentes están en cola. Recibirás correo cuando terminen.', icon: 'success', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo iniciar', 'Verifica que haya archivos pendientes y vuelve a intentarlo.', 'error'),
    })
}

const reprocessFailedSources = async () => {
    if (!selectedPeriodId.value) return
    const failedCount = grouped.value?.failed_count ?? 0
    if (!failedCount) return Swal.fire({ title: 'Sin fuentes con error', text: 'No hay archivos en error en este periodo.', icon: 'info', confirmButtonText: 'Entendido' })
    const result = await Swal.fire({
        title: 'Reprocesar fuentes con error',
        html: `Se reprocesarán únicamente las fuentes vigentes que estén en error (NOI Nómina, NOI Fiscal, Rotación e IMSS si su auditoría falla). No se tocará Cobranza, Cartera, Colocación ni Gastos.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Reprocesar',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    })
    if (!result.isConfirmed) return
    router.post(`/historico-general/${selectedPeriodId.value}/reprocesar-fuentes-con-error`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Reprocesamiento enviado', text: 'Las fuentes con error están en cola. Recibirás correo cuando terminen.', icon: 'success', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo iniciar', 'Verifica que haya fuentes en error y vuelve a intentarlo.', 'error'),
    })
}

const requeueRun = async () => {
    if (!selectedPeriodId.value) return
    router.post(`/historico-general/${selectedPeriodId.value}/actualizacion-bd/reencolar`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Job reencolado', text: 'Inicia el worker para procesarlo: php artisan queue:work --tries=1 --timeout=0 -vvv', icon: 'info', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo reencolar', 'El run ya no está en cola o el job ya existe.', 'error'),
    })
}

const clearStuckDatabaseUpdate = async () => {
    if (!selectedPeriodId.value) return
    const result = await Swal.fire({
        title: 'Limpiar estado atascado',
        text: 'Se marcará el proceso como cancelado para que puedas volver a iniciar la carga.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Limpiar estado',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    })
    if (!result.isConfirmed) return
    router.post(`/historico-general/${selectedPeriodId.value}/actualizacion-bd/limpiar`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Estado limpiado', text: 'Ya puedes reiniciar la carga de registros.', icon: 'success', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo limpiar', 'No hay proceso atascado o ya fue limpiado.', 'error'),
    })
}

const resolveIncident = async (id: number) => {
    const result = await Swal.fire({ title: 'Resolver incidencia', input: 'textarea', inputLabel: 'Nota de resolución', inputPlaceholder: 'Describe cómo quedó resuelta…', showCancelButton: true, confirmButtonText: 'Guardar resolución', cancelButtonText: 'Cancelar', inputValidator: (value) => !value ? 'Captura una nota de resolución.' : undefined })
    if (!result.isConfirmed || !selectedPeriodId.value) return
    router.post(`/historico-general/${selectedPeriodId.value}/incidencias/${id}/resolver`, { resolution_note: result.value }, {
        preserveScroll: true,
        onSuccess: () => { Swal.fire('Incidencia resuelta', 'El estado del flujo se actualizó.', 'success'); loadIncidents() },
        onError:   () => Swal.fire('No se pudo resolver', 'Intenta nuevamente.', 'error'),
    })
}

const assignBranchFromIncident = async ({ employee_ids, employee_id, branch_id, period_id }: { employee_ids?: number[]; employee_id: number; branch_id: number; period_id: number }) => {
    if (!selectedPeriodId.value) return
    const ids  = employee_ids && employee_ids.length > 0 ? employee_ids : [employee_id]
    const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
    try {
        const res = await fetch('/empleados/batch-asignar-sucursal', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body:    JSON.stringify({ employee_ids: ids, branch_id, period_id, notes: 'Asignado desde incidencias.' }),
        })
        if (!res.ok) throw new Error()
        // loadIncidents already calls lightweight refreshes (sin-sucursal + coincidencias)
        await loadIncidents()
        incidentsReloadKey.value++
        // Refresh period props so can_generate_radiography reflects resolved state
        router.reload({ only: ['periods'] })
        Swal.fire({ title: 'Sucursal asignada', text: 'La persona fue asignada correctamente.', icon: 'success', timer: 2000, showConfirmButton: false })
    } catch {
        Swal.fire('No se pudo asignar', 'Intenta nuevamente.', 'error')
    }
}

const confirmMatchFromIncident = async ({ employee_id, target_employee_id, branch_id, canonical_name }: { employee_id: number; target_employee_id: number; branch_id?: number; canonical_name?: string }) => {
    if (!selectedPeriodId.value) return
    try {
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
        const body: Record<string, any> = { employee_id, target_employee_id }
        if (branch_id != null) body.branch_id = branch_id
        if (canonical_name?.trim()) body.canonical_name = canonical_name.trim()
        const res = await fetch(`/historico-general/${selectedPeriodId.value}/personas-sin-sucursal/confirmar-coincidencia`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
        })
        if (!res.ok) {
            const body = await res.json().catch(() => ({}))
            throw new Error((body as any).message || `Error ${res.status}`)
        }
        const data = await res.json()
        await loadIncidents()
        incidentsReloadKey.value++
        const text = data.branch_assigned
            ? 'La persona fue unificada y se heredó la sucursal correspondiente.'
            : 'La persona fue unificada. Aún requiere asignar sucursal.'
        Swal.fire({ title: 'Coincidencia confirmada', text, icon: 'success', timer: 3000, showConfirmButton: false })
    } catch (e: any) {
        Swal.fire('No se pudo confirmar', e?.message || 'Intenta nuevamente.', 'error')
    }
}

// ── Coincidencias desde panel de posibles duplicados ─────────────────
const confirmCoincidenciaFromDuplicates = async ({ a_id, b_id, a_name, b_name, pair_key }: { a_id: number; b_id: number; a_name: string; b_name: string; pair_key: string }) => {
    // Step 1: Choose canonical name
    const step1 = await Swal.fire({
        title: 'Confirmar coincidencia de persona',
        html: `
            <p class="text-sm text-gray-600 mb-4">Estas dos entradas serán unificadas como <strong>una sola persona</strong>. Elige qué nombre queda como definitivo:</p>
            <div class="flex flex-col gap-3 text-left">
                <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 px-4 py-3 hover:bg-slate-50 transition">
                    <input type="radio" name="swal_canon" value="a" class="mt-0.5 accent-indigo-600">
                    <span class="text-sm font-bold text-slate-900">${a_name}</span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 px-4 py-3 hover:bg-slate-50 transition">
                    <input type="radio" name="swal_canon" value="b" class="mt-0.5 accent-indigo-600">
                    <span class="text-sm font-bold text-slate-900">${b_name}</span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-xl border border-slate-200 px-4 py-3 hover:bg-slate-50 transition">
                    <input type="radio" name="swal_canon" value="custom" class="mt-0.5 accent-indigo-600">
                    <span class="text-sm text-slate-600">Escribir nombre personalizado…</span>
                </label>
            </div>
        `,
        preConfirm: () => {
            const checked = document.querySelector<HTMLInputElement>('input[name="swal_canon"]:checked')
            if (!checked) {
                Swal.showValidationMessage('Selecciona una opción para el nombre definitivo.')
                return false
            }
            return checked.value
        },
        showCancelButton: true,
        confirmButtonText: 'Confirmar unificación',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
        focusConfirm: false,
    })

    if (!step1.isConfirmed) {
        incidentsStepRef.value?.clearPairLoading(pair_key)
        return
    }

    let canonicalName = ''
    if (step1.value === 'a') {
        canonicalName = a_name
    } else if (step1.value === 'b') {
        canonicalName = b_name
    } else {
        // Step 2: Custom name input
        const step2 = await Swal.fire({
            title: 'Nombre canónico personalizado',
            input: 'text',
            inputLabel: 'Escribe el nombre definitivo para esta persona',
            inputPlaceholder: 'Ej: GARCIA LOPEZ JUAN PABLO',
            inputAttributes: { autocomplete: 'off', style: 'text-transform: uppercase' },
            showCancelButton: true,
            confirmButtonText: 'Guardar',
            cancelButtonText: 'Volver',
            reverseButtons: true,
            inputValidator: (value) => !value?.trim() ? 'Ingresa el nombre definitivo.' : undefined,
        })
        if (!step2.isConfirmed) {
            incidentsStepRef.value?.clearPairLoading(pair_key)
            return
        }
        canonicalName = (step2.value as string).trim()
    }

    // Guard: IDs must be present (stale incidents won't have them)
    if (!a_id || !b_id) {
        incidentsStepRef.value?.clearPairLoading(pair_key)
        Swal.fire('Sin IDs válidos', 'Esta coincidencia no tiene IDs. Pulsa Refrescar en la cabecera y vuelve a intentarlo.', 'warning')
        return
    }

    // Perform unification directly (so we can manage pair_key state on success/error)
    if (!selectedPeriodId.value) return
    try {
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
        const res = await fetch(`/historico-general/${selectedPeriodId.value}/personas-sin-sucursal/confirmar-coincidencia`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ employee_id: a_id, target_employee_id: b_id, canonical_name: canonicalName }),
        })
        if (!res.ok) {
            const body = await res.json().catch(() => ({}))
            throw new Error((body as any).message || `Error ${res.status}`)
        }
        const data = await res.json()
        // loadIncidents triggers lightweight refreshes for both counters
        await loadIncidents()
        incidentsReloadKey.value++
        incidentsStepRef.value?.onPairConfirmed(pair_key)
        const text = data.branch_assigned
            ? 'La persona fue unificada y se heredó la sucursal correspondiente.'
            : 'La persona fue unificada. Aún requiere asignar sucursal.'
        Swal.fire({ title: 'Coincidencia confirmada', text, icon: 'success', timer: 3000, showConfirmButton: false })
    } catch (e: any) {
        incidentsStepRef.value?.clearPairLoading(pair_key)
        Swal.fire('No se pudo confirmar', e?.message || 'Intenta nuevamente.', 'error')
    }
}

const discardCoincidenciaFromDuplicates = async ({ a_id, b_id, pair_key }: { a_id: number; b_id: number; pair_key: string }) => {
    if (!selectedPeriodId.value) return

    // Confirm before persisting the rejection
    const confirm = await Swal.fire({
        title: '¿Son personas diferentes?',
        text: 'No se eliminará nada. Solo se guardará que este par no debe volver a sugerirse como posible coincidencia.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, son diferentes',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    })
    if (!confirm.isConfirmed) {
        incidentsStepRef.value?.clearPairLoading(pair_key)
        return
    }

    try {
        const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? ''
        const res = await fetch(`/historico-general/${selectedPeriodId.value}/coincidencias/descartar`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ employee_id_a: a_id, employee_id_b: b_id }),
        })
        if (!res.ok) {
            const body = await res.json().catch(() => ({}))
            throw new Error((body as any).message || `Error ${res.status}`)
        }
        await loadIncidents()
        incidentsStepRef.value?.onPairRejected(pair_key)
    } catch (e: any) {
        incidentsStepRef.value?.clearPairLoading(pair_key)
        Swal.fire('No se pudo descartar', e?.message || 'Intenta nuevamente.', 'error')
    }
}

const resolveLocationFromIncident = ({ incident_id, action, branch_id }: { incident_id: number; action: string; branch_id: number | null }) => {
    if (!selectedPeriodId.value) return
    router.post(`/historico-general/${selectedPeriodId.value}/incidencias/ubicacion-pendiente/resolver`, { incident_id, action, branch_id }, {
        preserveScroll: true,
        onSuccess: () => { Swal.fire('Ubicación resuelta', 'La ubicación fue clasificada correctamente.', 'success'); loadIncidents() },
        onError:   () => Swal.fire('No se pudo resolver', 'Intenta nuevamente.', 'error'),
    })
}

const isGeneratingReport = ref(false)

const generateReport = () => {
    if (isGeneratingReport.value) return
    if (!period.value?.can_generate_radiography) return toastError('Generación bloqueada', period.value?.blocking_reasons?.join(' ') || 'Completa las etapas previas.')
    if (reportConfig.value.report_type !== 'simple' && !reportConfig.value.compare_period_id) return toastError('Falta periodo comparable', 'Selecciona explícitamente el periodo a comparar.')
    isGeneratingReport.value = true
    router.post(`/historico-general/${selectedPeriodId.value}/generar-radiografia`, { config: reportConfig.value }, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Generación en cola', text: 'El reporte se genera en segundo plano. Puedes cerrar esta ventana; te avisaremos por correo cuando Excel y PDF estén listos.', icon: 'info', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo iniciar', 'Ya hay una generación en proceso o faltan pasos previos.', 'error'),
        onFinish:  () => { isGeneratingReport.value = false },
    })
}

const cancelGeneration = async () => {
    if (!selectedPeriodId.value) return
    const result = await Swal.fire({
        title: '¿Cancelar la generación?',
        text: 'El proceso se marcará como cancelado. Podrás reiniciarlo desde esta misma etapa.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar',
        cancelButtonText: 'No, dejar corriendo',
        reverseButtons: true,
        confirmButtonColor: '#ef4444',
    })
    if (!result.isConfirmed) return
    router.post(`/historico-general/${selectedPeriodId.value}/generar-reporte/cancelar`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Generación cancelada', text: 'Puedes reiniciarla cuando estés listo.', icon: 'info', confirmButtonText: 'Entendido' }),
        onError:   () => Swal.fire('No se pudo cancelar', 'El proceso ya terminó o no existe.', 'error'),
    })
}

const processGenerationNow = async () => {
    if (!selectedPeriodId.value) return
    const result = await Swal.fire({
        title: 'Procesar ahora (local)',
        text: 'Ejecuta el job directamente sin worker. La página esperará hasta que termine (puede tardar varios minutos).',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Procesar ahora',
        cancelButtonText: 'Cancelar',
        reverseButtons: true,
    })
    if (!result.isConfirmed) return
    Swal.fire({ title: 'Generando reporte…', text: 'Ejecutando directamente. No cierres esta ventana.', allowOutsideClick: false, showConfirmButton: false, didOpen: () => Swal.showLoading() })
    router.post(`/historico-general/${selectedPeriodId.value}/generar-reporte/procesar-ahora`, {}, {
        preserveScroll: true,
        onSuccess: () => Swal.fire({ title: 'Listo', text: 'El reporte fue generado. Descarga Excel y PDF.', icon: 'success', confirmButtonText: 'Ver resultado' }),
        onError:   () => Swal.fire({ title: 'Terminó con error', text: 'Revisa el detalle en la etapa de generar reporte.', icon: 'error', confirmButtonText: 'Ver detalle' }),
    })
}
</script>

<template>
    <Head title="Histórico general" />
    <main class="min-h-screen bg-gradient-to-br from-slate-50 via-white to-indigo-50/40 p-4 sm:p-6 lg:p-8">
        <div class="mx-auto max-w-screen-2xl space-y-6">

            <!-- Hero header compacto -->
            <section class="overflow-hidden rounded-[2rem] bg-slate-950 px-6 py-5 text-white shadow-2xl shadow-slate-300 sm:px-8 sm:py-6">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.28em] text-indigo-300">Histórico general</p>
                        <h1 class="mt-1 text-2xl font-black tracking-tight sm:text-3xl">
                            Flujo guiado · Carga, normalización y Radiografía Financiera
                        </h1>
                    </div>
                    <p class="max-w-sm text-xs leading-5 text-slate-400 sm:text-right">
                        Selecciona el periodo, carga las {{ sources.length }} fuentes requeridas,<br class="hidden sm:block" />
                        resuelve incidencias y genera tu reporte Excel y PDF.
                    </p>
                </div>
            </section>

            <!-- ① Selección de periodo — siempre visible y primero -->
            <PeriodSelector v-model="selectedPeriodId" :periods="periods" />

            <!-- Sin periodo: empty state guiado -->
            <div
                v-if="!selectedPeriodId"
                class="flex flex-col items-center justify-center rounded-[2rem] border border-dashed border-slate-300 bg-white/60 py-20 text-center shadow-sm"
            >
                <div class="flex size-16 items-center justify-center rounded-3xl bg-indigo-100 shadow-lg shadow-indigo-100">
                    <CalendarDays class="size-8 text-indigo-500" />
                </div>
                <h2 class="mt-5 text-xl font-black text-slate-800">Selecciona un periodo para iniciar</h2>
                <p class="mt-2 max-w-sm text-sm leading-6 text-slate-500">
                    Elige un mes operativo para cargar archivos y generar reportes, o un periodo compuesto (bimestre, etc.) para reportes agregados.<br />
                    El flujo guiado se desplegará aquí.
                </p>
            </div>

            <!-- Periodo automático: vista simplificada sin stepper -->
            <AutomaticPeriodDetail
                v-else-if="period?.is_derived"
                :period="period"
                @generate="generateReport"
            />

            <!-- Periodo mensual: stepper + contenido de la etapa -->
            <template v-else>

                <!-- ② Stepper del flujo -->
                <WorkflowStepper :steps="steps" :current="currentStep" @select="selectStep" />

                <!-- ③ Contenido de la etapa actual -->
                <transition name="fade" mode="out-in">
                    <div v-if="currentStep === 'files'" :key="`files-${selectedPeriodId}`" class="space-y-4">
                        <UploadSourcesStep
                            :period="period"
                            :sources="sources"
                            :uploads-by-source="uploadsBySource"
                            :selected-period-id="selectedPeriodId"
                            @upload="uploadFile"
                            @delete="deleteUpload"
                        />

                        <!-- Avance a Etapa 2 -->
                        <div class="flex items-center justify-between rounded-[2rem] border border-white/70 bg-white px-6 py-4 shadow-xl shadow-slate-200/70">
                            <div class="flex flex-col gap-1.5">
                                <p v-if="!period?.can_update_database" class="text-sm text-slate-500">
                                    Carga las fuentes requeridas para continuar a la siguiente etapa.
                                </p>
                                <p v-else class="text-sm text-slate-600">
                                    Todas las fuentes requeridas están cargadas.
                                </p>
                                <button
                                    v-if="(grouped?.pending_count ?? 0) > 0"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-indigo-200 bg-indigo-50 px-3 py-1.5 text-xs font-bold text-indigo-700 transition hover:bg-indigo-100"
                                    @click="processPendingSources"
                                >
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 0 1 0 1.972l-11.54 6.347a1.125 1.125 0 0 1-1.667-.986V5.653Z" /></svg>
                                    Procesar fuentes pendientes ({{ grouped?.pending_count }})
                                </button>
                                <button
                                    v-if="(grouped?.failed_count ?? 0) > 0"
                                    type="button"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-bold text-rose-700 transition hover:bg-rose-100"
                                    @click="reprocessFailedSources"
                                >
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182m0-4.991v4.99" /></svg>
                                    Reprocesar fuentes con error ({{ grouped?.failed_count }})
                                </button>
                            </div>
                            <button
                                type="button"
                                class="ml-4 inline-flex h-10 shrink-0 items-center gap-2 rounded-2xl px-5 text-sm font-black transition focus:outline-none focus:ring-4 disabled:cursor-not-allowed disabled:opacity-40"
                                :class="period?.can_update_database
                                    ? 'bg-indigo-600 text-white shadow-lg shadow-indigo-100 hover:bg-indigo-700 focus:ring-indigo-100'
                                    : 'bg-slate-200 text-slate-400'"
                                :disabled="!period?.can_update_database"
                                @click="selectStep('load')"
                            >
                                Siguiente etapa
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                            </button>
                        </div>
                    </div>
                    <DatabaseUpdateStep
                        v-else-if="currentStep === 'load'"
                        :period="period"
                        :can-update="Boolean(period?.can_update_database)"
                        @update="updateDatabase"
                        @cancel="cancelDatabaseUpdate"
                        @clear-stuck="clearStuckDatabaseUpdate"
                        @refresh="() => router.reload({ only: ['periods', 'groupedUploads'] })"
                        @process-now="processNow"
                        @requeue="requeueRun"
                    />
                    <IncidentsStep
                        v-else-if="currentStep === 'incidents'"
                        ref="incidentsStepRef"
                        :period="period"
                        :incidents="incidents"
                        :incidents-has-data="incidentsHasData"
                        :incidents-no-data-message="incidentsNoDataMsg"
                        :branches="branches"
                        :reload-key="incidentsReloadKey"
                        @resolve="resolveIncident"
                        @refresh="loadIncidents"
                        @assign-branch="assignBranchFromIncident"
                        @confirm-match="confirmMatchFromIncident"
                        @resolve-location="resolveLocationFromIncident"
                        @confirm-coincidencia-from-duplicates="confirmCoincidenciaFromDuplicates"
                        @discard-coincidencia="discardCoincidenciaFromDuplicates"
                    />
                    <div v-else-if="currentStep === 'config'" class="space-y-5">
                        <!-- Bloqueo por incidencias críticas -->
                        <div v-if="hasCriticalIncidents" class="flex flex-col items-center justify-center gap-4 rounded-[2rem] border-2 border-dashed border-red-300 bg-red-50 p-10 text-center shadow-sm">
                            <div class="flex size-14 items-center justify-center rounded-2xl bg-red-100 shadow">
                                <svg class="size-7 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" /></svg>
                            </div>
                            <div>
                                <p class="text-lg font-black text-red-800">Configuración bloqueada</p>
                                <p class="mt-1.5 max-w-sm text-sm leading-6 text-red-700">Resuelve las incidencias críticas antes de configurar el reporte.</p>
                            </div>
                            <button type="button" class="inline-flex h-10 items-center gap-2 rounded-2xl bg-red-600 px-5 text-sm font-black text-white shadow-lg shadow-red-200 transition hover:bg-red-700" @click="selectStep('incidents')">
                                Ir a incidencias
                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
                            </button>
                        </div>
                        <ReportConfigurationStep
                            v-else
                            v-model="reportConfig"
                            :period="period"
                            :periods="periods"
                            :branches="branches"
                            :employees="periodEmployees"
                            :can-generate="Boolean(period?.can_generate_radiography)"
                            @next="selectStep('generate')"
                        />
                    </div>
                    <GenerateReportStep
                        v-else-if="currentStep === 'generate'"
                        :period="period"
                        :report-config="reportConfig"
                        :can-generate="Boolean(period?.can_generate_radiography)"
                        :is-submitting="isGeneratingReport"
                        @generate="generateReport"
                        @cancel="cancelGeneration"
                        @refresh="() => router.reload({ only: ['periods', 'groupedUploads'] })"
                        @process-now="processGenerationNow"
                    />
                    <ReportPreview
                        v-else-if="currentStep === 'preview'"
                        :period="period"
                        :preview="preview"
                        :config="reportConfig"
                    />
                    <GeneratedReportActions v-else :period="period" :config="reportConfig" />
                </transition>

            </template>

        </div>
    </main>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: all 220ms ease; }
.fade-enter-from, .fade-leave-to { opacity: 0; transform: translateY(8px); }
</style>
