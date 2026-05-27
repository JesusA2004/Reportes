<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodRadiographyRun;
use Illuminate\Console\Command;

class DebugGenerationProgressCommand extends Command
{
    protected $signature   = 'reportes:debug-generation-progress {period_id : ID del periodo}';
    protected $description = 'Muestra el estado y progreso de la generación de radiografía para un periodo.';

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
        $this->info("  DEBUG GENERATION PROGRESS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');

        // ── Últimos 5 runs del periodo ────────────────────────────────────────
        $runs = PeriodRadiographyRun::query()
            ->where('period_id', $periodId)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($runs->isEmpty()) {
            $this->warn('  No hay runs de generación para este periodo.');
            $this->line('');
            return self::SUCCESS;
        }

        $this->info('── Historial de runs (últimos 5) ────────────────────────────────────');
        $this->table(
            ['ID', 'Estado', 'Progreso', 'Paso actual', 'Log (truncado)', 'Inicio', 'Fin', 'Por'],
            $runs->map(fn ($run) => [
                $run->id,
                $run->status,
                isset($run->metadata['progress_percent']) ? $run->metadata['progress_percent'] . '%' : '—',
                $run->metadata['current_step'] ?? '—',
                mb_strimwidth((string) ($run->log ?? ''), 0, 50),
                optional($run->started_at)->format('d/m H:i'),
                optional($run->finished_at)->format('d/m H:i'),
                $run->created_by ?? '—',
            ])->all()
        );

        // ── Run más reciente — detalle completo ───────────────────────────────
        $latest = $runs->first();

        $this->line('');
        $this->info('── Run más reciente — detalle completo ──────────────────────────────');
        $this->line('');

        $statusColor = match ($latest->status) {
            'success'   => 'green',
            'running'   => 'cyan',
            'queued'    => 'yellow',
            'failed'    => 'red',
            'cancelled' => 'white',
            default     => 'white',
        };

        $this->line(sprintf('  Run ID:       <fg=white>#%d</>', $latest->id));
        $this->line(sprintf('  Estado:       <fg=%s>%s</>', $statusColor, strtoupper($latest->status ?? '?')));
        $this->line(sprintf('  En cola:      %s', optional($latest->queued_at ?? $latest->created_at)->format('d/m/Y H:i:s') ?? '—'));
        $this->line(sprintf('  Inicio:       %s', optional($latest->started_at)->format('d/m/Y H:i:s') ?? '—'));
        $this->line(sprintf('  Fin:          %s', optional($latest->finished_at)->format('d/m/Y H:i:s') ?? '—'));

        if ($latest->started_at && $latest->finished_at) {
            $elapsed = $latest->started_at->diffInSeconds($latest->finished_at);
            $mins    = (int) floor($elapsed / 60);
            $secs    = $elapsed % 60;
            $this->line(sprintf('  Duración:     %s', $mins > 0 ? "{$mins} min {$secs} seg" : "{$secs} seg"));
        } elseif ($latest->started_at && in_array($latest->status, ['queued', 'running'], true)) {
            $elapsed = now()->diffInSeconds($latest->started_at ?? $latest->queued_at ?? $latest->created_at);
            $mins    = (int) floor($elapsed / 60);
            $secs    = $elapsed % 60;
            $this->line(sprintf('  Transcurrido: <fg=yellow>%s</>', $mins > 0 ? "{$mins} min {$secs} seg" : "{$secs} seg"));
        }

        $this->line('');

        // Progress
        $meta = $latest->metadata ?? [];
        if (!empty($meta)) {
            $pct  = $meta['progress_percent'] ?? null;
            $step = $meta['current_step'] ?? null;

            if ($pct !== null) {
                $filled = (int) round($pct / 5);
                $bar    = str_repeat('█', $filled) . str_repeat('░', 20 - $filled);
                $this->line(sprintf('  Progreso:     <fg=cyan>[%s]</> <fg=white>%d%%</>', $bar, $pct));
            }
            if ($step) {
                $this->line(sprintf('  Paso actual:  <fg=cyan>%s</>', $step));
            }
            if (isset($meta['config'])) {
                $cfg = $meta['config'];
                $this->line(sprintf('  Tipo reporte: %s', $cfg['report_type'] ?? '—'));
                $this->line(sprintf('  Alcance:      %s', $cfg['scope'] ?? '—'));
                if (isset($cfg['compare_period_id'])) {
                    $this->line(sprintf('  Periodo comparado: #%d', $cfg['compare_period_id']));
                }
            }
        }

        $this->line('');
        $this->line(sprintf('  Log:          %s', $latest->log ?? '—'));

        if ($latest->error_message) {
            $this->line('');
            $this->line(sprintf('  <fg=red>Error:        %s</>', $latest->error_message));
        }

        // Rutas de exportación
        if ($latest->output_excel_path || $latest->output_pdf_path) {
            $this->line('');
            $this->info('── Archivos generados ───────────────────────────────────────────────');
            if ($latest->output_excel_path) {
                $this->line(sprintf('  Excel:  %s', $latest->output_excel_path));
            }
            if ($latest->output_pdf_path) {
                $this->line(sprintf('  PDF:    %s', $latest->output_pdf_path));
            }
        }

        // ── Resumen ejecutivo ─────────────────────────────────────────────────
        $this->line('');
        $this->info('── Resumen ejecutivo ─────────────────────────────────────────────────');

        if ($latest->status === 'success') {
            $this->line('  <fg=green>✓ Generación completada. Excel y PDF disponibles para descarga.</>');
        } elseif (in_array($latest->status, ['queued', 'running'], true)) {
            $progress = $meta['progress_percent'] ?? 0;
            $this->warn("  ⏳ Generación en curso ({$progress}%). Polling activo.");
        } elseif ($latest->status === 'failed') {
            $this->error("  ✗ Generación fallida. Consulta el error arriba.");
        } elseif ($latest->status === 'cancelled') {
            $this->line('  <fg=white>○ Generación cancelada manualmente.</>');
        }

        $this->line('');
        $this->line('  Diagnóstico rápido:');
        $this->line('    - Para reintentar: php artisan queue:work --tries=1 --timeout=0');
        $this->line('    - Para debug completo: php artisan reportes:debug-incidents ' . $periodId);
        $this->line('');

        return self::SUCCESS;
    }
}
