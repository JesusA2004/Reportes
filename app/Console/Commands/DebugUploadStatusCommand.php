<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugUploadStatusCommand extends Command
{
    protected $signature   = 'reportes:debug-upload-status {period_id : ID del periodo}';
    protected $description = 'Muestra estado de uploads y process runs del periodo.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG UPLOAD STATUS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        // ── Uploads ──────────────────────────────────────────────────────────
        $uploads = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('ru.period_id', $periodId)
            ->select(
                'ru.id', 'ds.code', 'ru.original_name', 'ru.status',
                'ru.file_size', 'ru.uploaded_at'
            )
            ->orderBy('ru.id')
            ->get();

        $this->info('── Uploads ──');
        $this->table(
            ['ID', 'Fuente', 'Archivo', 'Status', 'Tamaño (KB)'],
            $uploads->map(fn ($u) => [
                $u->id,
                $u->code,
                mb_substr($u->original_name, 0, 45),
                $u->status,
                $u->file_size ? number_format((int) $u->file_size / 1024, 0) : '?',
            ])->all()
        );

        // ── Process runs (latest per upload) ─────────────────────────────────
        $runs = DB::table('process_runs as pr')
            ->join('report_uploads as ru', 'pr.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('ru.period_id', $periodId)
            ->select(
                'pr.id as run_id', 'ru.id as upload_id', 'ds.code',
                'pr.status', 'pr.rows_read', 'pr.rows_inserted',
                'pr.rows_skipped', 'pr.rows_with_errors',
                'pr.started_at', 'pr.finished_at'
            )
            ->orderBy('pr.id', 'desc')
            ->get();

        $this->line('');
        $this->info('── Process Runs (más recientes primero) ──');
        $this->table(
            ['RunID', 'UploadID', 'Fuente', 'Status', 'Leídas', 'Insertadas', 'Skipped', 'Errores'],
            $runs->map(fn ($r) => [
                $r->run_id,
                $r->upload_id,
                $r->code,
                $r->status,
                $r->rows_read    ?? '?',
                $r->rows_inserted ?? '?',
                $r->rows_skipped  ?? '?',
                $r->rows_with_errors ?? '?',
            ])->all()
        );

        // ── Fact tables ───────────────────────────────────────────────────────
        $this->line('');
        $this->info('── Fact tables (period_id = ' . $periodId . ') ──');

        $allPeriods = Period::all();
        $weekly     = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge($weekly, [$period->id])));
        $this->line('  Data IDs resueltos: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        $tables = [
            'fact_portfolios'   => 'Saldos por Cliente',
            'fact_placements'   => 'Ministraciones',
            'fact_recoveries'   => 'Ingresos Cobranza',
            'fact_expenses'     => 'Gastos',
            'fact_noi_movements' => 'NOI Nómina',
        ];

        $tableRows = [];
        foreach ($tables as $table => $label) {
            $cnt = DB::table($table)->whereIn('period_id', $dataIds)->count();
            $tableRows[] = [$label, $table, number_format($cnt), $cnt > 0 ? '✓' : '✗ VACÍA'];
        }
        $this->table(['Fuente', 'Tabla', 'Filas', 'Estado'], $tableRows);

        // ── Pendientes ────────────────────────────────────────────────────────
        $pendingUploads = $uploads->filter(fn ($u) => $u->status !== 'processed');
        if ($pendingUploads->isNotEmpty()) {
            $this->line('');
            $this->warn('⚠ Uploads NO procesados:');
            foreach ($pendingUploads as $u) {
                $this->warn("  - ID={$u->id}  {$u->code}  ({$u->original_name}) → STATUS={$u->status}");
            }
        } else {
            $this->line('');
            $this->line('  <fg=green>✓ Todos los uploads están en status "processed".</>');
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }
}
