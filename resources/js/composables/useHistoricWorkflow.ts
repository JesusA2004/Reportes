import { computed, ref } from 'vue'

export function useHistoricWorkflow(period: any, incidents: any) {
    const currentStep = ref('files')

    const steps = computed(() => {
        const selected      = period.value
        const dbReady       = !!selected?.can_update_database
        const dbDone        = !!selected?.database_updated
        const dbRunStatus   = selected?.database_update_run_status ?? null
        const dbRunning     = ['queued', 'running'].includes(dbRunStatus)
        const dbFailed      = dbRunStatus === 'failed'
        const critical      = (incidents.value ?? []).filter((item: any) => item.severity === 'high').length
        const canConfig     = dbDone && critical === 0
        const radioRunning  = !!selected?.radiography_running
        // Preview step: only "completed" when there's a real generated summary (preview_summary exists)
        const hasRealPreview = !!selected?.preview_summary
        // Exports step: only "completed" when can_export is true (files really exist)
        const canExport     = !!selected?.can_export_radiography

        const bdStatus = dbDone
            ? 'completed'
            : dbRunning
                ? 'running'
                : dbFailed
                    ? 'error'
                    : dbReady
                        ? 'ready'
                        : 'blocked'

        const incidentsStatus = !dbDone
            ? 'blocked'
            : critical > 0
                ? 'error'
                : 'completed'

        const configStatus = !canConfig
            ? 'blocked'
            : radioRunning
                ? 'running'
                : hasRealPreview
                    ? 'completed'
                    : 'ready'

        return [
            {
                key: 'files',
                label: 'Periodo y archivos',
                description: 'Selecciona periodo y carga las 5 fuentes.',
                status: selected
                    ? (selected.missing_radiography_sources?.length ? 'ready' : 'completed')
                    : 'pending',
            },
            {
                key: 'bd',
                label: 'Actualización BD',
                description: 'Actualiza empleados y sucursales con NOI y Cobranza.',
                status: bdStatus,
            },
            {
                key: 'incidents',
                label: 'Incidencias',
                description: 'Resuelve incidencias críticas antes de generar.',
                status: incidentsStatus,
            },
            {
                key: 'config',
                label: 'Configurar reporte',
                description: 'Tipo, alcance y filtros del reporte.',
                status: configStatus,
            },
            {
                key: 'preview',
                label: 'Vista previa',
                description: 'Resumen del reporte generado.',
                // Only "completed" when a real summary exists, not just because run was queued
                status: hasRealPreview ? 'completed' : radioRunning ? 'running' : 'blocked',
            },
            {
                key: 'exports',
                label: 'Exportación / archivos',
                description: 'Excel, PDF y vista completa del reporte.',
                // Only "completed" when files actually exist
                status: canExport ? 'completed' : radioRunning ? 'running' : 'blocked',
            },
        ] as any[]
    })

    const selectStep = (key: string) => {
        const step = steps.value.find((item: any) => item.key === key)
        if (step && step.status !== 'blocked') currentStep.value = key
    }

    return { currentStep, steps, selectStep }
}
