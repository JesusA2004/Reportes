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
        const hasRealPreview = !!selected?.preview_summary
        const canExport     = !!selected?.can_export_radiography

        const loadStatus = dbDone
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
            : hasRealPreview
                ? 'completed'
                : 'ready'

        const generateStatus = !canConfig
            ? 'blocked'
            : radioRunning
                ? 'running'
                : hasRealPreview
                    ? 'generated'
                    : 'ready'

        return [
            {
                key: 'files',
                label: 'Archivos y periodo',
                description: 'Selecciona el periodo operativo y carga los archivos requeridos.',
                status: selected
                    ? (selected.missing_radiography_sources?.length ? 'ready' : 'completed')
                    : 'pending',
            },
            {
                key: 'load',
                label: 'Cargar registros',
                description: 'Lee los archivos y guarda los registros base necesarios para el reporte.',
                status: loadStatus,
            },
            {
                key: 'incidents',
                label: 'Incidencias',
                description: 'Resuelve incidencias críticas antes de generar el reporte.',
                status: incidentsStatus,
            },
            {
                key: 'config',
                label: 'Configurar reporte',
                description: 'Define si el reporte será general, por sucursal, por gestor o comparativo.',
                status: configStatus,
            },
            {
                key: 'generate',
                label: 'Generar reporte',
                description: 'Calcula las métricas del reporte según la configuración seleccionada.',
                status: generateStatus,
            },
            {
                key: 'preview',
                label: 'Vista previa',
                description: 'Revisa KPIs, tablas y gráficas antes de exportar.',
                status: hasRealPreview ? 'completed' : radioRunning ? 'running' : 'blocked',
            },
            {
                key: 'exports',
                label: 'Exportación',
                description: 'Descarga Excel y PDF generados desde el mismo resultado.',
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
