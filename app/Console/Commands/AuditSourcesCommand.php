<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\DataSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditSourcesCommand extends Command
{
    protected $signature = 'reportes:audit-sources
                                {period_id}
                                {--all : mostrar también fuentes no requeridas}';

    protected $description = 'Auditoría de fuentes de datos: estado de uploads por periodo. Muestra fuente, archivo, estatus, fechas, filas y si cuenta para generación.';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $allIds     = array_unique(array_merge([$period->id], $weeklyIds));

        $sources = DataSource::where('is_active', true)->orderBy('name')->get();
        $requiredForReport = $sources->where('is_required_for_report', true)->pluck('code')->toArray();
        $requiredForBd     = $sources->where('is_required_for_bd', true)->pluck('code')->toArray();

        $uploads = ReportUpload::query()
            ->whereIn('period_id', $allIds)
            ->with(['dataSource:id,code,name', 'processRuns:id,report_upload_id,status,rows_inserted,finished_at'])
            ->orderBy('data_source_id')
            ->orderByDesc('id')
            ->get();

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA DE FUENTES — {$period->label} (ID {$period->id})");
        $this->info("  Periodos incluidos: " . implode(', ', $allIds));
        $this->info('════════════════════════════════════════════════════════════════════════');

        // ── Tabla de requeridos ────────────────────────────────────────────────
        $this->line('');
        $this->info('════ FUENTES REQUERIDAS PARA RADIOGRAFÍA (' . count($requiredForReport) . ') ════');
        $this->line('');
        $this->line(
            str_pad('Fuente (código)', 36) .
            str_pad('Req.BD', 8) .
            str_pad('Uploads', 9) .
            str_pad('Último estado', 16) .
            str_pad('Cargado', 18) .
            str_pad('Procesado', 18) .
            str_pad('Filas ins.', 12) .
            'Cuenta para gen.'
        );
        $this->line(str_repeat('─', 135));

        $processedCount = 0;
        foreach ($requiredForReport as $code) {
            $source   = $sources->firstWhere('code', $code);
            $sourceName = $source ? mb_substr($source->name, 0, 34) : $code;

            // Latest upload for this source
            $latestUpload = $uploads->filter(fn($u) => $u->dataSource?->code === $code)->sortByDesc('id')->first();
            $allUploadsForSource = $uploads->filter(fn($u) => $u->dataSource?->code === $code)->count();

            if (!$latestUpload) {
                $this->error(str_pad($sourceName, 36) .
                    str_pad(in_array($code, $requiredForBd) ? 'SÍ' : 'NO', 8) .
                    str_pad('0', 9) .
                    str_pad('NO SUBIDO', 16) .
                    str_pad('—', 18) .
                    str_pad('—', 18) .
                    str_pad('—', 12) .
                    'NO — falta archivo'
                );
                continue;
            }

            $status = (string) ($latestUpload->status?->value ?? $latestUpload->status ?? 'desconocido');
            $processRun = $latestUpload->processRuns->sortByDesc('id')->first();
            $rowsInserted = $processRun?->rows_inserted ?? 0;
            $uploadedAt   = optional($latestUpload->created_at)->format('d/m/Y H:i');
            $processedAt  = $processRun && $processRun->finished_at ? optional($processRun->finished_at)->format('d/m/Y H:i') : '—';

            $countaParaGen = $status === 'processed' ? 'SÍ' : 'NO — ' . $status;
            if ($status === 'processed') $processedCount++;

            $line = str_pad(mb_substr($sourceName, 0, 34), 36) .
                str_pad(in_array($code, $requiredForBd) ? 'SÍ' : 'NO', 8) .
                str_pad($allUploadsForSource, 9) .
                str_pad(strtoupper($status), 16) .
                str_pad($uploadedAt, 18) .
                str_pad($processedAt, 18) .
                str_pad(number_format($rowsInserted), 12) .
                $countaParaGen;

            if ($status === 'processed') {
                $this->line($line);
            } elseif (in_array($status, ['pending', 'processing'])) {
                $this->warn($line);
            } else {
                $this->error($line);
            }
        }

        $this->line('');
        $totalRequired = count($requiredForReport);
        $this->info("Resumen: {$processedCount}/{$totalRequired} fuentes requeridas procesadas.");

        if ($processedCount < $totalRequired) {
            $this->warn("⚠ Faltan " . ($totalRequired - $processedCount) . " fuentes por procesar para poder generar la radiografía.");
        } else {
            $this->info("✓ Todas las fuentes requeridas están procesadas.");
        }

        // ── Uploads no requeridos ──────────────────────────────────────────────
        if ($this->option('all')) {
            $nonRequired = $uploads->filter(fn($u) => !in_array($u->dataSource?->code, $requiredForReport));
            if ($nonRequired->isNotEmpty()) {
                $this->line('');
                $this->info('════ OTROS UPLOADS (no requeridos para radiografía) ════');
                $this->line('');
                foreach ($nonRequired as $upload) {
                    $code   = $upload->dataSource?->code ?? '?';
                    $name   = $upload->dataSource?->name ?? '?';
                    $status = (string) ($upload->status?->value ?? $upload->status ?? '?');
                    $this->line(str_pad(mb_substr($name, 0, 34), 36) . str_pad($code, 30) . $status);
                }
            }
        }

        // ── Historial de uploads por fuente ────────────────────────────────────
        $this->line('');
        $this->info('════ HISTORIAL DE UPLOADS (todos) ════');
        $this->line('');
        $this->line(
            str_pad('ID', 8) .
            str_pad('Fuente (código)', 36) .
            str_pad('Archivo', 40) .
            str_pad('Estado', 14) .
            str_pad('Subido', 18) .
            str_pad('Filas ins.', 12) .
            'Último uso'
        );
        $this->line(str_repeat('─', 135));

        foreach ($uploads->sortByDesc('id') as $upload) {
            $code     = $upload->dataSource?->code ?? '?';
            $filename = mb_substr($upload->original_name ?? '?', 0, 38);
            $status   = (string) ($upload->status?->value ?? $upload->status ?? '?');
            $uploadedAt = optional($upload->created_at)->format('d/m/Y H:i');
            $run = $upload->processRuns->sortByDesc('id')->first();
            $rows = $run?->rows_inserted ?? 0;
            $isLatestForSource = $uploads->filter(fn($u) => $u->dataSource?->code === $code)->sortByDesc('id')->first()?->id === $upload->id;

            $suffix = $isLatestForSource ? '← activo' : '';

            $line = str_pad($upload->id, 8) .
                str_pad($code, 36) .
                str_pad($filename, 40) .
                str_pad(strtoupper($status), 14) .
                str_pad($uploadedAt, 18) .
                str_pad(number_format($rows), 12) .
                $suffix;

            if ($isLatestForSource && in_array($code, $requiredForReport)) {
                if ($status === 'processed') {
                    $this->line($line);
                } else {
                    $this->warn($line);
                }
            } else {
                $this->line("  " . $line);
            }
        }

        $this->line('');
        return 0;
    }
}
