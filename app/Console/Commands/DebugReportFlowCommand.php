<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shows the exact state of every upload, every source, every block,
 * and every reason why generation might be blocked.
 */
class DebugReportFlowCommand extends Command
{
    protected $signature   = 'reportes:debug-report-flow {period_id}';
    protected $description = 'Diagnóstico exacto del flujo: uploads, estados, motivos de bloqueo de generación';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds, [$period->id]
        )));

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  DEBUG REPORT FLOW — {$period->label} (ID {$period->id})");
        $this->info("════════════════════════════════════════════════════════════");

        // ── A. Archivos subidos ───────────────────────────────────────────────
        $this->line('');
        $this->info('════ A. ARCHIVOS / UPLOADS ════');

        $sources = DB::table('data_sources')->where('is_active', true)->orderBy('code')->get();
        $uploads = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('ru.period_id', $period->id)
            ->select('ru.id', 'ds.code', 'ds.name', 'ru.original_name', 'ru.status', 'ru.stored_path',
                     'ru.created_at', 'ru.updated_at', 'ds.is_required_for_bd',
                     'ds.is_required_for_report')
            ->orderBy('ds.code')
            ->get();

        $this->line(str_pad('Upload ID', 10) . str_pad('Código fuente', 32) . str_pad('Status', 12) .
                    str_pad('Para BD', 8) . str_pad('Para Rep.', 10) . 'Archivo');
        $this->line(str_repeat('─', 100));

        $processedCount   = 0;
        $pendingBd        = [];
        $pendingReport    = [];
        $failedSources    = [];
        $missingForBd     = [];
        $missingForReport = [];

        foreach ($sources as $src) {
            $upload = $uploads->firstWhere('code', $src->code);
            if (!$upload) {
                if ($src->is_required_for_bd) $missingForBd[]     = $src->code;
                if ($src->is_required_for_report)    $missingForReport[]  = $src->code;
                $this->warn(str_pad('---', 10) . str_pad($src->code, 32) . str_pad('SIN ARCHIVO', 12) .
                            str_pad($src->is_required_for_bd ? 'SÍ' : 'no', 8) .
                            str_pad($src->is_required_for_report ? 'SÍ' : 'no', 10) . '← FALTA');
                continue;
            }

            $status = (string)($upload->status?->value ?? $upload->status ?? 'unknown');
            if ($status === 'processed') {
                $processedCount++;
            } elseif ($status === 'failed') {
                $failedSources[] = $upload->code;
            } else {
                if ($src->is_required_for_bd) $pendingBd[]     = $upload->code;
                if ($src->is_required_for_report)    $pendingReport[]  = $upload->code;
            }

            $statusLabel = match($status) {
                'processed'  => '✓ procesado',
                'pending'    => '⏳ pendiente',
                'processing' => '🔄 procesando',
                'failed'     => '✗ error',
                default      => $status,
            };

            $this->line(
                str_pad($upload->id, 10) .
                str_pad(mb_substr($upload->code, 0, 30), 32) .
                str_pad($statusLabel, 12) .
                str_pad($src->is_required_for_bd ? 'SÍ' : 'no', 8) .
                str_pad($src->is_required_for_report ? 'SÍ' : 'no', 10) .
                mb_substr($upload->original_name ?? '?', 0, 50)
            );
        }

        $this->line(str_repeat('─', 100));
        $this->line("Procesados: {$processedCount} / " . $uploads->count() . " subidos");

        // ── B. Estado de la BD ────────────────────────────────────────────────
        $this->line('');
        $this->info('════ B. ESTADO DE TABLAS FACT_* ════');

        $facts = [
            ['fact_noi_movements',  'NOI / Nómina'],
            ['fact_placements',     'Ministraciones'],
            ['fact_recoveries',     'Cobranza/Ingresos'],
            ['fact_portfolios',     'Saldos / Cartera'],
            ['fact_expenses',       'Gastos'],
        ];

        foreach ($facts as [$table, $label]) {
            $cnt = DB::table($table)->count();
            $status = $cnt > 0 ? "✓ {$cnt} filas" : "✗ VACÍO";
            $this->line("  " . str_pad($table, 28) . str_pad("({$label})", 22) . $status);
        }

        // ── C. Period_summary y corridas ─────────────────────────────────────
        $this->line('');
        $this->info('════ C. PERIOD_SUMMARY Y CORRIDAS ════');

        $summary = DB::table('period_summaries')->where('period_id', $period->id)->orderByDesc('id')->first();
        if ($summary) {
            $this->line("  period_summary ID={$summary->id} status={$summary->status} v={$summary->version}");
            $this->line("  generated_at={$summary->generated_at} invalidated={$summary->invalidated_at}");
            $dbUpdateStats = json_decode($summary->warnings ?? '{}', true)['db_update'] ?? null;
            if ($dbUpdateStats) {
                $this->line("  DB_UPDATE stats: records_loaded={$dbUpdateStats['records_loaded']}, persons={$dbUpdateStats['persons_detected']}, branches_incl={$dbUpdateStats['branches_included']}");
            }
        } else {
            $this->warn("  Sin period_summary");
        }

        $dbRun = DB::table('period_database_update_runs')->where('period_id', $period->id)->orderByDesc('id')->first();
        if ($dbRun) {
            $this->line("  DB_UPDATE_RUN ID={$dbRun->id} status={$dbRun->status} finished={$dbRun->finished_at}");
            if ($dbRun->error_message) {
                $this->error("  Error: " . mb_strimwidth($dbRun->error_message, 0, 120));
            }
        }

        $radRun = DB::table('period_radiography_runs')->where('period_id', $period->id)->orderByDesc('id')->first();
        if ($radRun) {
            $this->line("  RAD_RUN ID={$radRun->id} status={$radRun->status} finished={$radRun->finished_at}");
        }

        // ── D. resolveWorkflowState simulado ─────────────────────────────────
        $this->line('');
        $this->info('════ D. CÁLCULO DE can_generate (resolveWorkflowState) ════');

        $bdSourceCodes     = DB::table('data_sources')->where('is_required_for_bd', true)->pluck('code')->toArray();
        $reportSourceCodes = DB::table('data_sources')->where('is_required_for_report', true)->pluck('code')->toArray();
        $uploadCodes       = $uploads->pluck('code')->toArray();

        $this->line("  Fuentes requeridas para BD: [" . implode(', ', $bdSourceCodes) . "]");
        $this->line("  Fuentes requeridas para Rep: [" . implode(', ', $reportSourceCodes) . "]");

        $missingBd      = array_diff($bdSourceCodes, $uploadCodes);
        $missingRep     = array_diff($reportSourceCodes, $uploadCodes);
        $failedBd       = array_intersect($failedSources, $bdSourceCodes);
        $unprocessedRep = array_filter($reportSourceCodes, function($code) use ($uploads) {
            $u = $uploads->firstWhere('code', $code);
            return $u && (string)($u->status?->value ?? $u->status) !== 'processed';
        });

        // Stale detection: run succeeded but BD-required uploads are still pending
        $staleUploads = [];
        if ($dbRun && $dbRun->status === 'success' && $dbRun->finished_at && $summary
            && in_array($summary->status, ['database_updated', 'generated']) && !$summary->invalidated_at) {
            foreach ($bdSourceCodes as $code) {
                $u = $uploads->firstWhere('code', $code);
                if ($u && (string)($u->status?->value ?? $u->status) !== 'processed') {
                    $staleUploads[] = $code . ' (' . ($u->original_name ?? '?') . ', estado: ' . ($u->status ?? 'pending') . ')';
                }
            }
        }

        $summaryValid    = $summary && in_array($summary->status, ['database_updated', 'generated']) && !$summary->invalidated_at && empty($staleUploads);
        $databaseUpdated = $summaryValid;
        $pendingCritical = $summary ? DB::table('period_incidents')->where('period_summary_id', $summary->id)->where('severity', 'high')->count() : 0;
        $running         = $radRun && in_array($radRun->status, ['queued', 'running']);

        $blockingReasons = [];
        if (!empty($missingBd))       $blockingReasons[] = 'Faltan archivos de BD: ' . implode(', ', $missingBd);
        if (!empty($failedBd))        $blockingReasons[] = 'Fuentes de BD con error: ' . implode(', ', $failedBd);
        if (!empty($staleUploads))    $blockingReasons[] = '⚠ ESTADO INCONSISTENTE: BD_UPDATE_RUN exitoso pero uploads BD siguen pending — vuelve a ejecutar Cargar registros. Afectados: ' . implode(', ', $staleUploads);
        if (!$databaseUpdated)        $blockingReasons[] = 'BD no actualizada (summary.status=' . ($summary?->status ?? 'null') . ')';
        if ($pendingCritical > 0)     $blockingReasons[] = "{$pendingCritical} incidencias críticas pendientes";
        if (!empty($missingRep))      $blockingReasons[] = 'Faltan fuentes para reporte: ' . implode(', ', $missingRep);
        if (!empty($unprocessedRep))  $blockingReasons[] = 'Fuentes sin procesar: ' . implode(', ', $unprocessedRep);
        if ($running)                 $blockingReasons[] = 'Radiografía en proceso';

        $canGenerate = $databaseUpdated && $pendingCritical === 0 && empty($missingRep) && empty($unprocessedRep) && !$running;

        $this->line(str_pad('Variable', 35) . 'Valor');
        $this->line(str_repeat('─', 75));
        $this->line(str_pad('database_updated', 35) . ($databaseUpdated ? '✓ true' : '✗ false'));
        $this->line(str_pad('pending_critical_incidents', 35) . $pendingCritical);
        $this->line(str_pad('missing_report_sources', 35) . (empty($missingRep) ? '(ninguna)' : implode(', ', $missingRep)));
        $this->line(str_pad('unprocessed_report_sources', 35) . (empty($unprocessedRep) ? '(ninguna)' : count($unprocessedRep) . ' fuentes'));
        $this->line(str_pad('radiography_running', 35) . ($running ? '✓ sí' : 'no'));
        $this->line(str_repeat('─', 75));

        if ($canGenerate) {
            $this->info("  ✓ can_generate_radiography = TRUE");
        } else {
            $this->warn("  ✗ can_generate_radiography = FALSE");
            $this->line("  MOTIVOS EXACTOS:");
            foreach ($blockingReasons as $r) {
                $this->line("    → {$r}");
            }

            if (!empty($unprocessedRep)) {
                $this->line('');
                $this->line("  FUENTES SIN PROCESAR (las que bloquean generación):");
                foreach ($unprocessedRep as $code) {
                    $u = $uploads->firstWhere('code', $code);
                    $status = $u ? ($u->status?->value ?? $u->status ?? 'unknown') : 'NO SUBIDA';
                    $this->line(str_pad("  {$code}", 35) . "status={$status} | archivo=" .
                                mb_substr($u->original_name ?? 'no encontrado', 0, 40));
                }
            }
        }

        // ── E. Contradicción Etapa 1 vs Etapa 2 ─────────────────────────────
        $this->line('');
        $this->info('════ E. CONTRADICCIÓN ETAPA 1 vs ETAPA 2 ════');

        if ($databaseUpdated && !empty($unprocessedRep)) {
            $this->warn("  ⚠ CONTRADICCIÓN DETECTADA:");
            $this->warn("    Etapa 2 (Cargar registros): 'completo' → period_summary.status={$summary->status}");
            $this->warn("    Etapa 1 (Archivos): muestra 'Sin procesar' para " . count($unprocessedRep) . " fuentes");
            $this->line("  CAUSA:");
            if (DB::table('fact_noi_movements')->count() === 0 && DB::table('fact_recoveries')->count() === 0) {
                $this->line("    Las tablas fact_* están vacías aunque el DB_UPDATE_RUN completó.");
                $this->line("    Probable causa: Se subieron archivos NUEVOS después del DB_UPDATE_RUN,");
                $this->line("    lo que llamó clearForUpload() y borró los datos importados.");
                $this->line("    Los nuevos uploads están en status=pending (no procesados aún).");
            }
            $this->line("  ACCIÓN:");
            $this->line("    1. Verificar que los archivos subidos son correctos.");
            $this->line("    2. Ejecutar 'Cargar registros' (Etapa 2) de nuevo.");
            $this->line("    3. O ejecutar: php artisan reportes:reimportar-periodo {$period->id}");
        } elseif ($databaseUpdated && empty($unprocessedRep)) {
            $this->info("  ✓ Sin contradicción — Etapa 1 y Etapa 2 consistentes.");
        } else {
            $this->line("  Etapa 2 no completada aún (summary.status=" . ($summary?->status ?? 'null') . ")");
        }

        return 0;
    }
}
