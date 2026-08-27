<?php

namespace App\Jobs;

use App\Mail\ReportGeneratedMail;
use App\Mail\ReportGenerationFailedMail;
use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\EmployeeBranchAutoMatchService;
use App\Services\FinanciamientoMotosAssignmentService;
use App\Services\GastosExcelBranchResolverService;
use App\Services\PeriodConsolidationService;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\PeriodRadiographyService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\RadiografiaExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class GenerateRadiographyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;
    public int $tries = 3;
    public int $maxExceptions = 1;

    public function __construct(
        public int $periodId,
        public ?int $userId = null,
        public ?int $runId = null,
        public array $config = [],
    ) {
    }

    /**
     * Evita ejecución duplicada del mismo periodo si el driver de cola libera el
     * job antes de que termine (retry_after mal configurado, worker duplicado,
     * etc.) — sin este guard, dos ejecuciones concurrentes pueden pisarse los
     * archivos/registros generados y dejar un status="failed" falso aunque la
     * otra ejecución sí haya terminado bien.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('generate-radiography-period-' . $this->periodId))->expireAfter(1800)];
    }

    public function handle(
        PeriodRadiographyService $radiographyService,
        RadiografiaExportService $exportService,
        PeriodConsolidationService $consolidationService,
        PeriodDerivedDataCleaner $cleaner,
        EmployeeBranchAutoMatchService $branchAutoMatch,
        FinanciamientoMotosAssignmentService $motosAssignment,
        GastosExcelBranchResolverService $gastosExcelResolver,
        RadiographySnapshotBuilder $snapshotBuilder,
    ): void {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '1800');
        @set_time_limit(1800);

        // ── Observabilidad de duración (Problema 6) — SOLO mide tiempos, no toca
        // ninguna fórmula/cálculo financiero. Permite saber qué etapa es lenta
        // (snapshot/consolidación vs Excel vs PDF vs correo) sin adivinar.
        $tStart = microtime(true);

        $period = Period::query()->findOrFail($this->periodId);

        $run = $this->runId
            ? PeriodRadiographyRun::query()->find($this->runId)
            : null;

        // ── Identidad del reporte (simple vs comparativo vs por sucursal/gestor) ──
        // Un reporte simple y un comparativo del mismo periodo NO son el mismo
        // reporte: deben poder coexistir. La identidad se calcula una sola vez aquí
        // y se persiste en el run/exports para que limpieza, listado y descarga
        // resuelvan siempre por identidad, nunca solo por period_id.
        $reportType = $this->config['report_type'] ?? 'simple';
        $scope      = $this->config['scope'] ?? 'general';
        $identity   = [
            'period_id'            => $period->id,
            'report_type'          => $reportType,
            'scope'                => $scope,
            'comparison_period_id' => !empty($this->config['compare_period_id']) ? (int) $this->config['compare_period_id'] : null,
            'branch_id'            => !empty($this->config['branch_id']) ? (int) $this->config['branch_id'] : null,
            'employee_id'          => !empty($this->config['employee_id']) ? (int) $this->config['employee_id'] : null,
        ];
        $isComparativeOrScoped = $reportType !== 'simple' || $scope !== 'general';

        if (!$run) {
            $run = PeriodRadiographyRun::query()->create(array_merge($identity, [
                'status'     => 'queued',
                'queued_at'  => now(),
                'created_by' => $this->userId,
                'log'        => 'Radiografía en cola.',
                'metadata'   => $this->config ? ['config' => $this->config] : null,
            ]));
        }

        // Mark as running and record start time — also (re)persist identity in case
        // this run was created earlier (by the controller) without these columns.
        $run->update(array_merge($identity, [
            'status'      => 'running',
            'started_at'  => $run->started_at ?: now(),
            'finished_at' => null,
        ]));
        $this->updateProgress($run, 3, 'Iniciando generación', 'Preparando configuración del reporte.');

        try {
            // ── 1. Generate summary (metrics from Expense/Recovery/Placement/Portfolio) ──
            // NO se borra la versión anterior de ESTA MISMA identidad aquí (antes sí se
            // hacía, al principio, antes de intentar nada). PROBLEMA 2/4/5 (auditoría
            // 27-ago-2026): borrar de entrada dejaba el reporte SIN NINGÚN success si esta
            // nueva regeneración fallaba en un paso posterior (Excel/PDF/asignaciones) —
            // el intento anterior, exitoso, ya no existía ni en BD ni en disco, así que
            // Vista previa/Exportación (que dependen del último ÉXITO de la identidad, ver
            // PeriodRadiographyRun::resolveForIdentity()) quedaban bloqueadas pese a que el
            // histórico (MonthlyReportController) mostraba "Generado" un instante antes.
            // Ahora la limpieza ocurre DESPUÉS de confirmar que el Excel/PDF nuevos existen
            // (ver bloque 7 más abajo) — un intento fallido nunca destruye el último éxito.
            $this->updateProgress($run, 12, 'Calculando métricas financieras', 'Analizando cobranza, colocación, nómina y cartera del periodo. Esta etapa puede tardar varios minutos.');
            $summary = $radiographyService->generate($period, $this->userId, $this->config);

            $this->updateProgress($run, 48, 'Métricas calculadas', 'Resumen del periodo registrado. Iniciando revisión de asignaciones.');
            $run->update(['period_summary_id' => $summary->id]);

            // ── 3. Verify and refine branch assignments (reads fact tables, does NOT re-import) ──
            // Skip if every relevant employee already has an EBA — avoids 168×61K-row scan.
            $this->updateProgress($run, 55, 'Revisando asignaciones de sucursales', 'Verificando y refinando asignaciones automáticas de empleados por cobranza, colocación y cartera.');
            if ($this->shouldRunAutomatch($period->id)) {
                $branchAutoMatch->handle($period->id, function (int $percent, string $step, string $log) use ($run) {
                    $this->updateProgress($run, $percent, $step, $log);
                });
            } else {
                $this->updateProgress($run, 66, 'Asignaciones verificadas', 'Todos los empleados ya tienen sucursal — omitiendo auto-asignación.');
            }

            // ── 3b. Resolve Financiamiento de Motos/Cascos → employee_id/branch_id ─────
            // Persisted directly on fact_expenses, never left in a "sin asignar" bucket.
            // Throws (stopping generation) if any record can't be tied to a real employee
            // and operative branch — see FinanciamientoMotosAssignmentService.
            $this->updateProgress($run, 66, 'Resolviendo sucursal de gastos Excel', 'Emparejando cada gasto del Excel de Lendus contra su fila equivalente en el PDF (monto + fecha) para asignar sucursal.');
            $dataIds = $snapshotBuilder->resolveDataIdsPublic($period);
            $gastosExcelResolver->resolveForPeriodOrFail($period, $dataIds);

            $this->updateProgress($run, 67, 'Resolviendo Financiamiento de Motos', 'Vinculando cada movimiento de Financiamiento de Motos/Cascos con su empleado y sucursal.');
            $motosAssignment->assignForPeriodOrFail($period, $dataIds);

            // ── 4. Consolidate employee summaries (populates fact_period_employee_summary) ──
            $this->updateProgress($run, 68, 'Consolidando resumen de empleados', 'Calculando totales de nómina, percepciones y deducciones por persona y sucursal.');
            $consolidationService->consolidate($period);

            // Fin de la etapa de cálculo/consolidación ("snapshot" en el sentido de
            // observabilidad — datos ya listos para exportar).
            $tSnapshotReady = microtime(true);

            // ── 5. Export Excel (from scratch, no template) ──
            // Comparativo/por sucursal/por gestor usan el builder con config (que SÍ
            // arma un archivo distinto), nunca el simple — este era el bug raíz por
            // el que un comparativo terminaba descargando el reporte simple.
            $this->updateProgress($run, 75, 'Generando Excel', 'Construyendo hojas, tablas y formato del reporte.');
            $tExcelStart = microtime(true);
            $path = $isComparativeOrScoped
                ? $exportService->exportWithConfig($period, $this->config)
                : $exportService->export($period, $this->config);
            $tExcelEnd = microtime(true);

            // ── 6. Export PDF (via Blade + dompdf) ──
            $this->updateProgress($run, 90, 'Generando PDF', 'Renderizando el reporte en formato PDF.');
            $tPdfStart = microtime(true);
            $pdfPath = $isComparativeOrScoped
                ? $exportService->exportPdfWithConfig($period, $this->config)
                : $exportService->exportPdf($period, $this->config);
            $tPdfEnd = microtime(true);
        } catch (\Throwable $exception) {
            // Fallo REAL: no se generaron archivos válidos. Marca el run como
            // fallido y notifica por correo — este es el único caso que debe
            // producir un correo de error.
            Log::error('GenerateRadiographyJob falló.', [
                'period_id'  => $period->id,
                'run_id'     => $run->id,
                'exception'  => get_class($exception),
                'message'    => $exception->getMessage(),
                'file'       => $exception->getFile(),
                'line'       => $exception->getLine(),
                'trace'      => $exception->getTraceAsString(),
            ]);

            $publicError = $this->publicErrorMessage($exception);
            $errorCode   = $this->publicErrorCode($exception);

            // PROBLEMA 7: metadata equivalente a error_code/error_message — el mensaje
            // genérico ya no es lo único disponible para la UI; error_code permite
            // mostrar/clasificar el fallo sin adivinar a partir de texto libre.
            $errMeta = array_merge(is_array($run->metadata) ? $run->metadata : [], [
                'current_step' => 'Error',
                'error_code'   => $errorCode,
            ]);

            $run->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'log'           => $publicError,
                'error_message' => $publicError,
                'metadata'      => $errMeta,
            ]);

            $this->notifyUser(
                subject: 'Error al generar Radiografía',
                message: $publicError,
                period: $period,
                run: $run,
                success: false,
            );

            throw $exception;
        }

        // ── 7. Registro de exportaciones y correo de éxito ──────────────────────
        // A partir de aquí, el Excel y el PDF YA EXISTEN en disco y son válidos. Un
        // fallo en este bloque (p. ej. un hipo de BD al guardar el registro) NO
        // significa que el reporte no se generó — nunca debe mandar un correo de
        // error ni marcar el run como failed; a lo más, deja constancia en el log.
        try {
            $this->updateProgress($run, 97, 'Registrando exportaciones', 'Guardando rutas de descarga en base de datos.');

            $summary = PeriodSummary::query()
                ->where('period_id', $period->id)
                ->first();

            if ($summary) {
                PeriodRadiographyExport::query()->create([
                    'period_summary_id' => $summary->id,
                    'run_id'            => $run->id,
                    'export_path'       => $path,
                    'file_type'         => 'excel',
                    'template_version'  => config('app.version'),
                    'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label, 'config' => $this->config],
                    'exported_at'       => now(),
                    'exported_by'       => $this->userId,
                ]);

                PeriodRadiographyExport::query()->create([
                    'period_summary_id' => $summary->id,
                    'run_id'            => $run->id,
                    'export_path'       => $pdfPath,
                    'file_type'         => 'pdf',
                    'template_version'  => config('app.version'),
                    'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label, 'config' => $this->config],
                    'exported_at'       => now(),
                    'exported_by'       => $this->userId,
                ]);
            }

            $finalMeta = array_merge(is_array($run->metadata) ? $run->metadata : [], [
                'progress_percent' => 100,
                'current_step'     => 'Completado',
            ]);

            $run->update([
                'status'            => 'success',
                'period_summary_id' => $summary?->id,
                'finished_at'       => now(),
                'log'               => 'Radiografía generada. Excel y PDF listos para descargar.',
                'metadata'          => $finalMeta,
                'output_excel_path' => $path,
                'output_pdf_path'   => $pdfPath,
            ]);

            $this->cleanupPreviousIdentityVersion($cleaner, $period, $identity, $run->id);
        } catch (\Throwable $bookkeepingException) {
            Log::error('GenerateRadiographyJob: el Excel/PDF se generaron correctamente pero falló el registro posterior (esto NO debe disparar un correo de error).', [
                'period_id' => $period->id,
                'run_id'    => $run->id,
                'exception' => get_class($bookkeepingException),
                'message'   => $bookkeepingException->getMessage(),
            ]);

            // Los archivos son reales — el run se marca success igual, aunque el
            // registro de exportaciones haya fallado parcialmente.
            $run->update([
                'status'            => 'success',
                'finished_at'       => now(),
                'log'               => 'Radiografía generada. Excel y PDF listos para descargar.',
                'output_excel_path' => $path,
                'output_pdf_path'   => $pdfPath,
            ]);

            $this->cleanupPreviousIdentityVersion($cleaner, $period, $identity, $run->id);
        }

        $tMailStart = microtime(true);
        $this->notifyUser(
            subject: 'Radiografía lista',
            message: "La Radiografía del periodo {$period->label} ya está lista. Puedes consultarla en Reportes mensuales.",
            period: $period,
            success: true,
            run: $run,
        );
        $tMailEnd = microtime(true);

        $this->logPerformance($run, $identity, [
            'snapshot' => $tSnapshotReady - $tStart,
            'excel'    => $tExcelEnd - $tExcelStart,
            'pdf'      => $tPdfEnd - $tPdfStart,
            'mail'     => $tMailEnd - $tMailStart,
            'total'    => $tMailEnd - $tStart,
        ]);
    }

    /**
     * Observabilidad de duración (Problema 6): deja constancia de cuánto tardó cada
     * etapa — snapshot/consolidación, Excel, PDF, correo y total — tanto en el log
     * (una línea fácil de grep: "Radiography generation performance") como en
     * run.metadata['timings'], para poder comparar entre corridas sin adivinar cuál
     * etapa es el cuello de botella. No participa en ningún cálculo del reporte.
     */
    private function logPerformance(PeriodRadiographyRun $run, array $identity, array $seconds): void
    {
        $rounded = array_map(fn ($s) => round($s, 1), $seconds);

        Log::info('Radiography generation performance', array_merge($identity, [
            'run_id'         => $run->id,
            'snapshot_sec'   => $rounded['snapshot'],
            'excel_sec'      => $rounded['excel'],
            'pdf_sec'        => $rounded['pdf'],
            'mail_sec'       => $rounded['mail'],
            'total_sec'      => $rounded['total'],
            'summary'        => sprintf(
                'snapshot: %.1f sec | excel: %.1f sec | pdf: %.1f sec | mail: %.1f sec | total: %.1f sec',
                $rounded['snapshot'], $rounded['excel'], $rounded['pdf'], $rounded['mail'], $rounded['total']
            ),
        ]));

        $run->update([
            'metadata' => array_merge(is_array($run->metadata) ? $run->metadata : [], [
                'timings' => $rounded,
            ]),
        ]);
    }

    /**
     * Update run progress in DB and keep in-memory model in sync.
     * Merges with existing metadata so prior fields (e.g. config) are preserved.
     */
    private function updateProgress(PeriodRadiographyRun $run, int $percent, string $step, string $log): void
    {
        $newMeta = array_merge(
            is_array($run->metadata) ? $run->metadata : [],
            ['progress_percent' => $percent, 'current_step' => $step]
        );
        if ($this->config) {
            $newMeta['config'] = $this->config;
        }
        $run->update(['log' => $log, 'metadata' => $newMeta]);
        // keep the in-memory attribute in sync for the next merge
        $run->setAttribute('metadata', $newMeta);
    }

    private function notifyUser(string $subject, string $message, Period $period, bool $success, ?PeriodRadiographyRun $run = null): void
    {
        if (!$this->userId) {
            return;
        }

        $user = User::query()->find($this->userId);

        if (!$user || !$user->email) {
            return;
        }

        $run ??= $this->runId
            ? PeriodRadiographyRun::query()->find($this->runId)
            : PeriodRadiographyRun::query()->where('period_id', $period->id)->latest('id')->first();

        try {
            // El CTA del correo debe apuntar al reporte REALMENTE generado — un
            // comparativo/por sucursal/por gestor nunca debe mandar al preview plano
            // del reporte simple del periodo (mismo criterio que
            // ReportUploadController::generationProgress()).
            $isSimpleGeneral = ($run?->report_type ?: 'simple') === 'simple' && ($run?->scope ?: 'general') === 'general';
            $downloadUrl = ($run && !$isSimpleGeneral)
                ? route('reportes-mensuales.run-ver', $run->id)
                : route('reportes-mensuales.preview', $period->id);

            if (!$success) {
                Mail::to($user->email)->send(
                    new ReportGenerationFailedMail($period, $user, $run, $message)
                );
            } else {
                Mail::to($user->email)->send(
                    new ReportGeneratedMail($period, $user, $run, $downloadUrl)
                );
            }
        } catch (\Throwable $exception) {
            Log::warning('No se pudo enviar correo de Radiografía.', [
                'user_id'   => $this->userId,
                'period_id' => $period->id,
                'error'     => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Borra el/los run(s)/export(s)/archivo(s) previos de la MISMA identidad, ahora
     * que el Excel/PDF nuevos ya están confirmados en disco y registrados (ver
     * comentario en el bloque try principal — PROBLEMA 2/4/5). Un fallo aquí es solo
     * housekeeping (deja un archivo viejo huérfano en disco) — nunca debe convertir
     * una generación ya exitosa en un run failed ni disparar un correo de error.
     */
    private function cleanupPreviousIdentityVersion(PeriodDerivedDataCleaner $cleaner, Period $period, array $identity, int $currentRunId): void
    {
        try {
            $cleaner->clearGeneratedReportsForIdentity($period, $identity, excludeRunId: $currentRunId);
        } catch (\Throwable $cleanupException) {
            Log::warning('GenerateRadiographyJob: no se pudo limpiar la versión anterior de esta identidad (el reporte nuevo generado sigue siendo válido).', [
                'period_id' => $period->id,
                'run_id'    => $currentRunId,
                'identity'  => $identity,
                'exception' => get_class($cleanupException),
                'message'   => $cleanupException->getMessage(),
            ]);
        }
    }

    /**
     * PROBLEMA 7: código corto y estable para que la UI clasifique el fallo sin
     * adivinar a partir de texto libre (el stack trace completo solo va al log).
     */
    private function publicErrorCode(\Throwable $exception): string
    {
        $msg = $exception->getMessage();

        if (
            str_contains($msg, 'PhpOffice') || str_contains($msg, 'PhpSpreadsheet') ||
            str_contains($msg, 'Spreadsheet') || str_contains(get_class($exception), 'PhpOffice')
        ) {
            return 'GENERATION_EXCEL_FAILED';
        }

        if (str_contains($msg, 'Dompdf') || str_contains($msg, 'dompdf') || str_contains(get_class($exception), 'Dompdf')) {
            return 'GENERATION_PDF_FAILED';
        }

        if (str_contains($msg, 'SQLSTATE') || str_contains($msg, 'QueryException') || str_contains(get_class($exception), 'QueryException')) {
            return 'GENERATION_QUERY_FAILED';
        }

        if (str_contains($msg, 'Faltan fuentes') || str_contains($msg, 'No se puede generar la Radiografía')) {
            return 'GENERATION_MISSING_SOURCES';
        }

        if (str_contains($msg, 'no quedó procesada') || str_contains($msg, 'no tiene ruta de almacenamiento')) {
            return 'GENERATION_SOURCE_NOT_PROCESSED';
        }

        return 'GENERATION_UNKNOWN_FAILED';
    }

    private function publicErrorMessage(\Throwable $exception): string
    {
        $msg = $exception->getMessage();

        if (
            str_contains($msg, 'PhpOffice') ||
            str_contains($msg, 'PhpSpreadsheet') ||
            str_contains($msg, 'Call to undefined method') ||
            str_contains($msg, 'Spreadsheet') ||
            str_contains(get_class($exception), 'PhpOffice')
        ) {
            return 'No se pudo preparar el archivo Excel del reporte. Revisa la configuración del formato e inténtalo nuevamente.';
        }

        if (
            str_contains($msg, 'SQLSTATE') ||
            str_contains($msg, 'QueryException') ||
            str_contains(get_class($exception), 'QueryException')
        ) {
            return 'No se pudo consultar la información del reporte. Verifica que los datos del periodo estén procesados correctamente.';
        }

        if (
            str_contains($msg, 'Dompdf') ||
            str_contains($msg, 'dompdf') ||
            str_contains(get_class($exception), 'Dompdf')
        ) {
            return 'No se pudo generar el PDF del reporte. Inténtalo nuevamente o descarga el Excel si está disponible.';
        }

        if (
            str_contains($msg, 'Faltan fuentes') ||
            str_contains($msg, 'No se puede generar la Radiografía')
        ) {
            return $msg;
        }

        if (str_contains($msg, 'no quedó procesada') || str_contains($msg, 'no tiene ruta de almacenamiento')) {
            return $msg;
        }

        return 'No se pudo generar la Radiografía. Revisa las fuentes cargadas, incidencias y configuración del reporte.';
    }

    private function shouldRunAutomatch(int $periodId): bool
    {
        $periodEmployeeIds = \Illuminate\Support\Facades\DB::table('fact_noi_movements')
            ->where('period_id', $periodId)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id');

        if ($periodEmployeeIds->isEmpty()) {
            return false;
        }

        $assigned = \Illuminate\Support\Facades\DB::table('employee_branch_assignments')
            ->where('period_id', $periodId)
            ->whereIn('employee_id', $periodEmployeeIds)
            ->count();

        return $assigned < $periodEmployeeIds->count();
    }
}
