import { computed, ref } from 'vue'

export function useHistoricWorkflow(period: any, incidents: any) {
    const currentStep = ref('files')

    const steps = computed(() => {
        const selected = period.value
        const dbReady   = !!selected?.can_update_database
        const dbDone    = !!selected?.database_updated
        const dbRunStatus = selected?.database_update_run_status ?? null
        const dbRunning = ['queued', 'running'].includes(dbRunStatus)
        const dbFailed  = dbRunStatus === 'failed'
        const critical  = (incidents.value ?? []).filter((item: any) => item.severity === 'high').length
        const canConfig = dbDone && critical === 0
        const generated = !!selected?.radiography_ready
        const radioRunning = !!selected?.radiography_running

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
            ? (dbRunning ? 'running' : 'blocked')
            : critical > 0
                ? 'error'
                : 'completed'

        return [
            {
                key: 'files',
                label: 'Periodo y archivos',
                description: 'Selecciona periodo y carga fuentes base.',
                status: selected
                    ? (selected.missing_radiography_sources?.length ? 'ready' : 'completed')
                    : 'pending',
            },
            {
                key: 'bd',
                label: 'Actualización BD',
                description: 'Actualiza con NOI y Cobranza.',
                status: bdStatus,
            },
            {
                key: 'incidents',
                label: 'Incidencias',
                description: 'Resuelve críticos antes de generar.',
                status: incidentsStatus,
            },
            {
                key: 'config',
                label: 'Configurar reporte',
                description: 'Tipo, alcance y filtros.',
                status: canConfig ? 'ready' : 'blocked',
            },
            {
                key: 'preview',
                label: 'Vista previa',
                description: 'Reporte web navegable.',
                status: generated ? 'completed' : radioRunning ? 'running' : 'blocked',
            },
            {
                key: 'exports',
                label: 'Exportación / archivos',
                description: 'Excel, PDF y metadata.',
                status: selected?.can_export_radiography
                    ? 'completed'
                    : radioRunning
                        ? 'running'
                        : 'blocked',
            },
        ] as any[]
    })

    const selectStep = (key: string) => {
        const step = steps.value.find((item: any) => item.key === key)
        if (step && step.status !== 'blocked') currentStep.value = key
    }

    return { currentStep, steps, selectStep }
}
