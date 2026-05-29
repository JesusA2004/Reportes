<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\ReportAnalysisService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Repairs upload statuses when a DB_UPDATE_RUN loaded data but failed to mark uploads as processed.
 *
 * Strategy:
 * 1. For each upload: check if fact_* has rows with that report_upload_id.
 * 2. If yes → mark as processed (data exists, state is out of sync).
 * 3. If no → run full analyze() to import and mark as processed.
 * 4. If analyze fails → leave as pending/failed and report.
 * 5. If fact_* are empty and analyze fails for all → tell user to re-run Cargar registros.
 */
class RepairUploadStatusCommand extends Command
{
    protected $signature = 'reportes:repair-upload-status
                                {period_id}
                                {--dry-run : Show what would change without making changes}
                                {--reimport : Force reimport even if fact_* already have data}';
    protected $description = 'Repara inconsistencias de status en report_uploads y fact_* para un periodo';

    public function handle(ReportAnalysisService $analysisService): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $dryRun   = (bool) $this->option('dry-run');
        $reimport = (bool) $this->option('reimport');

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds, [$period->id]
        )));

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  REPAIR UPLOAD STATUS — {$period->name} (ID {$period->id})");
        $this->info("  Modo: " . ($dryRun ? 'DRY-RUN (sin cambios)' : ($reimport ? 'REIMPORT' : 'REPARACIÓN')) );
        $this->info("════════════════════════════════════════════════════════════");

        // ── Estado actual ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── ESTADO ACTUAL ──');

        $facts = [
            'fact_noi_movements'  => ['table' => 'fact_noi_movements',  'label' => 'NOI Nómina',    'upload_col' => 'report_upload_id'],
            'fact_recoveries'     => ['table' => 'fact_recoveries',     'label' => 'Cobranza',      'upload_col' => 'report_upload_id'],
            'fact_placements'     => ['table' => 'fact_placements',     'label' => 'Ministraciones','upload_col' => 'report_upload_id'],
            'fact_portfolios'     => ['table' => 'fact_portfolios',     'label' => 'Saldos',        'upload_col' => 'report_upload_id'],
            'fact_expenses'       => ['table' => 'fact_expenses',       'label' => 'Gastos',        'upload_col' => 'report_upload_id'],
        ];

        foreach ($facts as $key => $f) {
            $cnt = DB::table($f['table'])->whereIn('period_id', $dataIds)->count();
            $status = $cnt > 0 ? "✓ {$cnt} filas" : '✗ VACÍO';
            $this->line("  " . str_pad($f['table'], 25) . " ({$f['label']}): {$status}");
        }

        // ── Uploads del periodo ───────────────────────────────────────────────
        $this->line('');
        $this->info('── UPLOADS DEL PERIODO ──');

        $uploads = ReportUpload::query()->with('dataSource')->where('period_id', $period->id)->get();

        $changed  = 0;
        $failed   = 0;
        $skipped  = 0;

        $this->line(str_pad('Upload ID', 10) . str_pad('Código', 32) . str_pad('Status actual', 14) .
                    str_pad('Filas en fact_*', 18) . 'Acción');
        $this->line(str_repeat('─', 90));

        foreach ($uploads as $upload) {
            $code   = $upload->dataSource?->code ?? '?';
            $status = (string)($upload->status?->value ?? $upload->status ?? 'pending');

            // Count rows in relevant fact table for this upload
            $factRows = $this->countFactRows($upload->id, $dataIds);

            $action = '—';

            if ($status === 'processed' && !$reimport) {
                $action = 'ya procesado (skip)';
                $skipped++;
            } elseif ($factRows > 0 && !$reimport) {
                // Data exists in fact_* → just fix the status
                $action = "marcar processed ({$factRows} filas en BD)";
                if (!$dryRun) {
                    $upload->update(['status' => 'processed']);
                    $changed++;
                } else {
                    $changed++;
                }
            } else {
                // No data in fact_* OR forced reimport → run analyze()
                if (!$upload->stored_path || !Storage::disk('public')->exists($upload->stored_path)) {
                    $action = '✗ archivo físico no encontrado (skip)';
                    $skipped++;
                } else {
                    $action = 'importar via analyze()';
                    if (!$dryRun) {
                        try {
                            $run = $analysisService->analyze($upload);
                            $inserted = $run->rows_inserted ?? 0;
                            $action = "✓ importado: {$inserted} filas insertadas → processed";
                            $changed++;
                        } catch (\Throwable $e) {
                            $action = '✗ ERROR: ' . mb_strimwidth($e->getMessage(), 0, 60);
                            $failed++;
                        }
                    } else {
                        $changed++; // Would change
                    }
                }
            }

            $this->line(
                str_pad($upload->id, 10) .
                str_pad(mb_substr($code, 0, 30), 32) .
                str_pad($status, 14) .
                str_pad($factRows > 0 ? "{$factRows}" : 'vacío', 18) .
                $action
            );
        }

        $this->line('');
        $this->info('── RESULTADO ──');
        $this->line("  Uploads " . ($dryRun ? 'que cambiarían' : 'cambiados') . ": {$changed}");
        $this->line("  Skipped: {$skipped}");
        if ($failed > 0) {
            $this->error("  Errores de importación: {$failed}");
        }

        // ── Estado después de reparación ─────────────────────────────────────
        if (!$dryRun) {
            $this->line('');
            $this->info('── ESTADO DESPUÉS DE REPARACIÓN ──');
            foreach ($facts as $key => $f) {
                $cnt = DB::table($f['table'])->whereIn('period_id', $dataIds)->count();
                $status = $cnt > 0 ? "✓ {$cnt} filas" : '✗ VACÍO';
                $this->line("  " . str_pad($f['table'], 25) . " ({$f['label']}): {$status}");
            }

            $processedCount = ReportUpload::query()->where('period_id', $period->id)
                ->where('status', 'processed')->count();
            $totalCount     = $uploads->count();
            $this->line("  Uploads processed: {$processedCount}/{$totalCount}");

            if ($failed > 0 || DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->count() === 0) {
                $this->warn('');
                $this->warn('  REPARACIÓN INCOMPLETA. Fact tables aún tienen datos faltantes.');
                $this->warn('  Acción requerida: ejecutar "Cargar registros" (Etapa 2) nuevamente desde la UI.');
            } else {
                $this->info('');
                $this->info('  ✓ Reparación completada. Ahora corre debug-report-flow para verificar.');
            }
        }

        return 0;
    }

    private function countFactRows(int $uploadId, array $dataIds): int
    {
        $tables = ['fact_noi_movements', 'fact_recoveries', 'fact_placements', 'fact_portfolios', 'fact_expenses'];
        foreach ($tables as $table) {
            $cnt = DB::table($table)
                ->whereIn('period_id', $dataIds)
                ->where('report_upload_id', $uploadId)
                ->count();
            if ($cnt > 0) return $cnt;
        }
        return 0;
    }
}
