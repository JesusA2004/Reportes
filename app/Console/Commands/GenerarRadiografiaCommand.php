<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\EmployeeBranchAutoMatchService;
use App\Services\PeriodConsolidationService;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\PeriodRadiographyService;
use App\Services\RadiografiaExportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerarRadiografiaCommand extends Command
{
    protected $signature   = 'reportes:generar-radiografia
                                {period_id : ID del periodo mensual}
                                {--force-automatch : Forzar auto-asignación aunque ya existan asignaciones}';
    protected $description = 'Genera la radiografía de un periodo de forma sincrónica (sin queue): summary, asignación, consolidación, Excel y PDF.';

    public function __construct(
        private readonly PeriodRadiographyService      $radiographyService,
        private readonly RadiografiaExportService      $exportService,
        private readonly PeriodConsolidationService    $consolidationService,
        private readonly PeriodDerivedDataCleaner      $cleaner,
        private readonly EmployeeBranchAutoMatchService $branchAutoMatch,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        @ini_set('memory_limit', '2048M');
        @set_time_limit(0);

        $this->line('');
        $this->info("══════ GENERAR RADIOGRAFÍA — {$period->label} (ID #{$periodId}) ══════");
        $this->line('');

        $this->line('  1. Limpiando archivos y exports previos...');
        $this->cleaner->clearGeneratedReports($period);
        $this->line('  <fg=green>✓ Limpieza lista.</>');

        $this->line('  2. Generando summary (métricas de hecho)...');
        $summary = $this->radiographyService->generate($period, null);
        $this->line("  <fg=green>✓ Summary ID={$summary->id} generado.</>");

        $this->line('  3. Auto-asignando sucursales a empleados...');
        if ($this->shouldRunAutomatch($period->id)) {
            $this->branchAutoMatch->handle($period->id);
            $this->line('  <fg=green>✓ Auto-asignación ejecutada.</>');
        } else {
            $this->line('  <fg=green>✓ Auto-asignación omitida — todos los empleados ya tienen asignación. Usa --force-automatch para forzar.<//>');
        }

        $this->line('  4. Consolidando empleados...');
        $this->consolidationService->consolidate($period);
        $this->line('  <fg=green>✓ Consolidación lista.</>');

        $this->line('  5. Exportando Excel...');
        $path = $this->exportService->export($period);
        $this->line("  <fg=green>✓ Excel: {$path}</>");

        $this->line('  6. Exportando PDF...');
        try {
            $pdfPath = $this->exportService->exportPdf($period);
            $this->line("  <fg=green>✓ PDF: {$pdfPath}</>");
        } catch (\Throwable $e) {
            $this->warn("  ⚠ PDF falló (no crítico): " . mb_strimwidth($e->getMessage(), 0, 120));
            $pdfPath = null;
        }

        $this->line('');
        $this->info("══════ RADIOGRAFÍA GENERADA ══════");
        $this->line('  Ahora valida con: php artisan reportes:debug-radiografia ' . $periodId);
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Returns true only when the automatch is actually needed:
     * - --force-automatch flag is set, OR
     * - There are employees in the period's NOI data that do NOT yet have a branch assignment.
     *
     * This avoids running 168 × 61K-row queries when all employees are already assigned,
     * which was causing generation to stall for 20+ minutes.
     */
    private function shouldRunAutomatch(int $periodId): bool
    {
        if ($this->option('force-automatch')) {
            return true;
        }

        // Employees that appear in the period's NOI movements
        $periodEmployeeIds = DB::table('fact_noi_movements')
            ->where('period_id', $periodId)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id');

        if ($periodEmployeeIds->isEmpty()) {
            return false;
        }

        // Check how many of those employees lack an assignment for this period
        $unassigned = DB::table('employee_branch_assignments')
            ->where('period_id', $periodId)
            ->whereIn('employee_id', $periodEmployeeIds)
            ->count();

        // If every relevant employee already has an assignment row, skip automatch
        return $unassigned < $periodEmployeeIds->count();
    }
}
