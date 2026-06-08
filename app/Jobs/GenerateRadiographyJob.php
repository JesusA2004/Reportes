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
use App\Services\PeriodConsolidationService;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\PeriodRadiographyService;
use App\Services\RadiografiaExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
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

    public function handle(
        PeriodRadiographyService $radiographyService,
        RadiografiaExportService $exportService,
        PeriodConsolidationService $consolidationService,
        PeriodDerivedDataCleaner $cleaner,
        EmployeeBranchAutoMatchService $branchAutoMatch,
    ): void {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '1800');
        @set_time_limit(1800);

        $period = Period::query()->findOrFail($this->periodId);

        $run = $this->runId
            ? PeriodRadiographyRun::query()->find($this->runId)
            : null;

        if (!$run) {
            $run = PeriodRadiographyRun::query()->create([
                'period_id'  => $period->id,
                'status'     => 'queued',
                'queued_at'  => now(),
                'created_by' => $this->userId,
                'log'        => 'Radiografía en cola.',
                'metadata'   => $this->config ? ['config' => $this->config] : null,
            ]);
        }

        // Mark as running and record start time
        $run->update([
            'status'      => 'running',
            'started_at'  => $run->started_at ?: now(),
            'finished_at' => null,
        ]);
        $this->updateProgress($run, 3, 'Iniciando generación', 'Preparando configuración del reporte.');

        try {
            // ── 1. Remove previous export files ──────────────────────────────────
            $this->updateProgress($run, 8, 'Limpiando versión anterior', 'Eliminando reportes generados anteriormente.');
            $cleaner->clearGeneratedReports($period);

            // ── 2. Generate summary (metrics from Expense/Recovery/Placement/Portfolio) ──
            $this->updateProgress($run, 12, 'Calculando métricas financieras', 'Analizando cobranza, colocación, nómina y cartera del periodo. Esta etapa puede tardar varios minutos.');
            $summary = $radiographyService->generate($period, $this->userId, $this->config);

            $this->updateProgress($run, 48, 'Métricas calculadas', 'Resumen del periodo registrado. Iniciando revisión de asignaciones.');
            $run->update(['period_summary_id' => $summary->id]);

            // ── 3. Verify and refine branch assignments (reads fact tables, does NOT re-import) ──
            // Skip if every relevant employee already has an EBA — avoids 168×61K-row scan.
            $this->updateProgress($run, 55, 'Revisando asignaciones de sucursales', 'Verificando y refinando asignaciones automáticas de empleados por cobranza, colocación y cartera.');
            if ($this->shouldRunAutomatch($period->id)) {
                $branchAutoMatch->handle($period->id);
            } else {
                $this->updateProgress($run, 66, 'Asignaciones verificadas', 'Todos los empleados ya tienen sucursal — omitiendo auto-asignación.');
            }

            // ── 4. Consolidate employee summaries (populates fact_period_employee_summary) ──
            $this->updateProgress($run, 68, 'Consolidando resumen de empleados', 'Calculando totales de nómina, percepciones y deducciones por persona y sucursal.');
            $consolidationService->consolidate($period);

            // ── 5. Export Excel (from scratch, no template) ──
            $this->updateProgress($run, 75, 'Generando Excel', 'Construyendo hojas, tablas y formato del reporte.');
            $path = $exportService->export($period, $this->config);

            // ── 6. Export PDF (via Blade + dompdf) ──
            $this->updateProgress($run, 90, 'Generando PDF', 'Renderizando el reporte en formato PDF.');
            $pdfPath = $exportService->exportPdf($period, $this->config);

            // ── 7. Reload summary and register exports ──
            $this->updateProgress($run, 97, 'Registrando exportaciones', 'Guardando rutas de descarga en base de datos.');

            $summary = PeriodSummary::query()
                ->where('period_id', $period->id)
                ->first();

            if ($summary) {
                PeriodRadiographyExport::query()->create([
                    'period_summary_id' => $summary->id,
                    'export_path'       => $path,
                    'file_type'         => 'excel',
                    'template_version'  => config('app.version'),
                    'metadata'          => ['period_id' => $period->id, 'period_label' => $period->label, 'config' => $this->config],
                    'exported_at'       => now(),
                    'exported_by'       => $this->userId,
                ]);

                PeriodRadiographyExport::query()->create([
                    'period_summary_id' => $summary->id,
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

            $this->notifyUser(
                subject: 'Radiografía lista',
                message: "La Radiografía del periodo {$period->label} ya está lista. Puedes consultarla en Reportes mensuales.",
                period: $period,
                success: true,
                run: $run,
            );
        } catch (\Throwable $exception) {
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

            $errMeta = array_merge(is_array($run->metadata) ? $run->metadata : [], [
                'current_step' => 'Error',
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
            $downloadUrl = route('reportes-mensuales.preview', $period->id);

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
