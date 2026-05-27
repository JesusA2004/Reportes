<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodDatabaseUpdateRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugLoadProgressCommand extends Command
{
    protected $signature   = 'reportes:debug-load-progress {period_id : ID del periodo mensual}';
    protected $description = 'Muestra el estado actual y metadata del último run de carga de BD para un periodo.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $run = PeriodDatabaseUpdateRun::query()
            ->where('period_id', $periodId)
            ->orderByDesc('id')
            ->first();

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG LOAD PROGRESS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');

        if (!$run) {
            $this->warn('  No se encontró ningún run de carga para este periodo.');
            $this->line('');
            return self::SUCCESS;
        }

        $statusColor = match ($run->status) {
            'success'   => 'green',
            'failed'    => 'red',
            'running'   => 'yellow',
            'queued'    => 'cyan',
            'cancelled' => 'gray',
            default     => 'white',
        };

        $status  = $run->status;
        $running = in_array($status, ['queued', 'running'], true);

        $elapsedSeconds = null;
        if ($running) {
            $ref = $status === 'queued'
                ? ($run->queued_at ?? $run->created_at)
                : ($run->started_at ?? $run->queued_at ?? $run->created_at);
            $elapsedSeconds = $ref ? max(0, (int) now()->diffInSeconds($ref)) : null;
        } elseif ($run->started_at && $run->finished_at) {
            $elapsedSeconds = max(0, (int) $run->started_at->diffInSeconds($run->finished_at));
        }
        $elapsedStr = $elapsedSeconds !== null
            ? (($elapsedSeconds >= 60) ? floor($elapsedSeconds / 60) . ' min ' . ($elapsedSeconds % 60) . ' seg' : $elapsedSeconds . ' seg')
            : '—';

        $this->line('');
        $this->info('── Identificación del run ────────────────────────────────────────────');
        $this->line(sprintf('  Run ID       : %d', $run->id));
        $this->line(sprintf('  Estado       : <fg=%s>%s</>', $statusColor, strtoupper($run->status)));
        $this->line(sprintf('  En cola      : %s', optional($run->queued_at ?? $run->created_at)->format('Y-m-d H:i:s') ?? '—'));
        $this->line(sprintf('  Iniciado     : %s', $run->started_at?->format('Y-m-d H:i:s') ?? '—'));
        $this->line(sprintf('  Terminado    : %s', $run->finished_at?->format('Y-m-d H:i:s') ?? '—'));
        $this->line(sprintf('  Transcurrido : %s', $elapsedStr));

        if ($running && $elapsedSeconds !== null) {
            $isStuck = ($status === 'queued' && $elapsedSeconds >= 300) ||
                       ($status === 'running' && $elapsedSeconds >= 1800);
            if ($isStuck) {
                $this->line('  <fg=red>⚠ ATASCADO — worker posiblemente inactivo.</>');
            }
        }

        // Check if the job actually exists in the jobs table
        if (in_array($run->status, ['queued', 'running'], true)) {
            $jobExists = DB::table('jobs')
                ->where('payload', 'LIKE', '%UpdatePeriodDatabaseJob%')
                ->where('payload', 'LIKE', '%runId%i:' . $run->id . ';%')
                ->first(['id', 'queue', 'attempts', 'available_at', 'reserved_at']);

            $this->line('');
            $this->info('── Job en cola ───────────────────────────────────────────────────────');
            if ($jobExists) {
                $this->line(sprintf('  Job ID       : %d', $jobExists->id));
                $this->line(sprintf('  Queue        : %s', $jobExists->queue));
                $this->line(sprintf('  Attempts     : %d', $jobExists->attempts));
                $this->line(sprintf('  Available at : %s', date('Y-m-d H:i:s', $jobExists->available_at)));
                $this->line(sprintf('  Reserved at  : %s', $jobExists->reserved_at ? date('Y-m-d H:i:s', $jobExists->reserved_at) : '— (no ha sido tomado por worker)'));
                if (!$jobExists->reserved_at) {
                    $this->warn('  DIAGNÓSTICO: Job en cola pero no reservado. Worker inactivo.');
                    $this->line('  Solución: php artisan queue:work --tries=1 --timeout=0 -vvv');
                }
            } else {
                $this->error('  No existe job en la tabla jobs para este run.');
                $this->warn('  DIAGNÓSTICO: Run en cola pero sin job → run huérfano.');
                $this->line('  Solución: Reencolar o cancelar y volver a iniciar.');
                $this->line('  Comando:  php artisan queue:work --tries=1 --timeout=0 -vvv');
            }
        }

        // Check failed_jobs
        $failedJob = DB::table('failed_jobs')
            ->where('payload', 'LIKE', '%UpdatePeriodDatabaseJob%')
            ->where('payload', 'LIKE', '%runId%i:' . $run->id . ';%')
            ->first(['uuid', 'failed_at', 'exception']);
        if ($failedJob) {
            $this->line('');
            $this->error('  FAILED JOB DETECTADO:');
            $this->line(sprintf('  UUID       : %s', $failedJob->uuid));
            $this->line(sprintf('  Falló en   : %s', $failedJob->failed_at));
            $this->line(sprintf('  Excepción  : %s', mb_strimwidth($failedJob->exception ?? '', 0, 200)));
        }

        if ($run->error_message) {
            $this->line('');
            $this->error('  ERROR: ' . $run->error_message);
        }

        $meta = $run->metadata ?? [];

        $this->line('');
        $this->info('── Progreso ──────────────────────────────────────────────────────────');
        $pct  = $meta['progress_percent'] ?? null;
        $step = $meta['current_step']     ?? null;
        $this->line(sprintf('  Porcentaje    : %s%%', $pct !== null ? (int) $pct : '—'));
        $this->line(sprintf('  Paso actual   : %s', $step ?? '—'));
        $this->line(sprintf('  Fuente actual : %s', $meta['current_source'] ?? '—'));
        $this->line(sprintf('  Archivo actual: %s', $meta['current_file']   ?? '—'));

        $processedRows = $meta['processed_rows'] ?? null;
        $totalRows     = $meta['total_rows']     ?? null;
        if ($processedRows !== null || $totalRows !== null) {
            $this->line(sprintf(
                '  Filas         : %s / %s',
                $processedRows !== null ? number_format((int) $processedRows) : '—',
                $totalRows     !== null ? number_format((int) $totalRows)     : '—',
            ));
        }

        if (!empty($meta['stats'])) {
            $stats = $meta['stats'];
            $this->line('');
            $this->info('── Estadísticas finales ──────────────────────────────────────────────');
            $this->line(sprintf('  Personas detectadas  : %s', number_format((int) ($stats['persons_detected']  ?? 0))));
            $this->line(sprintf('  Registros cargados   : %s', number_format((int) ($stats['records_loaded']    ?? 0))));
            $this->line(sprintf('  Registros excluidos  : %s', number_format((int) ($stats['records_excluded']  ?? 0))));
            $this->line(sprintf('  Sucursales incluidas : %s', (int) ($stats['branches_included'] ?? 0)));
            $this->line(sprintf('  Sucursales excluidas : %s', (int) ($stats['branches_excluded'] ?? 0)));

            $pending = (int) ($stats['pending_locations'] ?? 0);
            if ($pending > 0) {
                $this->line(sprintf('  <fg=red>Ubicaciones pendientes: %d  ⚠ Bloquea generación de reporte</>', $pending));
            } else {
                $this->line(sprintf('  Ubicaciones pendientes: <fg=green>%d</>', $pending));
            }

            $critical = (int) ($stats['critical_incidents'] ?? 0);
            $warnings = (int) ($stats['warnings']           ?? 0);
            $critColor = $critical > 0 ? 'red' : 'green';
            $warnColor = $warnings > 0 ? 'yellow' : 'green';
            $this->line(sprintf('  Incidencias críticas : <fg=%s>%d</>', $critColor, $critical));
            $this->line(sprintf('  Advertencias         : <fg=%s>%d</>', $warnColor, $warnings));
        }

        // Raw metadata keys not shown above
        $knownKeys = ['progress_percent', 'current_step', 'current_source', 'current_file',
            'processed_rows', 'total_rows', 'stats'];
        $extra = array_diff_key($meta, array_flip($knownKeys));
        if (!empty($extra)) {
            $this->line('');
            $this->info('── Metadata adicional ────────────────────────────────────────────────');
            foreach ($extra as $key => $value) {
                $display = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value;
                $this->line(sprintf('  %-38s: %s', $key, $display));
            }
        }

        $this->line('');

        if ($run->status === 'success') {
            $this->info('<fg=green>✓ Carga completada correctamente.</>');
        } elseif ($run->status === 'failed') {
            $this->warn('El run terminó con error. Revisa el campo error_message arriba.');
        } elseif ($run->status === 'running') {
            $this->warn('El run está en progreso. Ejecuta este comando de nuevo para ver el avance.');
        } elseif ($run->status === 'queued') {
            $this->warn('El run está en cola. Verifica que el worker esté activo.');
        } elseif ($run->status === 'cancelled') {
            $this->line('<fg=gray>El run fue cancelado manualmente.</>');
        }

        $this->line('');
        return self::SUCCESS;
    }
}
