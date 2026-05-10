<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DebugRadiografiaCommand extends Command
{
    protected $signature   = 'reportes:debug-radiografia {periodId : ID del periodo a diagnosticar}';
    protected $description = 'Diagnóstico completo de la radiografía: periodo, IDs de datos, global_metrics, totales por sección, archivos generados.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('periodId');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::query()->get();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        if (empty($weeklyIds)) {
            $weeklyIds = [$period->id];
        }
        $dataIds = array_values(array_unique(array_merge($weeklyIds, [$period->id])));

        $this->line('');
        $this->info("══════════════════════════════════════════════════════════════");
        $this->info("  DEBUG RADIOGRAFÍA — Periodo #{$periodId}: {$period->label}");
        $this->info("══════════════════════════════════════════════════════════════");
        $this->line('');

        // ── 1. Información del periodo ─────────────────────────────────
        $this->info('── 1. Periodo ──');
        $this->line("  ID             : {$period->id}");
        $this->line("  Código         : {$period->code}");
        $this->line("  Tipo           : {$period->type}");
        $this->line("  Label          : {$period->label}");
        $this->line("  Año/Mes/Seq    : {$period->year}-{$period->month} seq={$period->sequence}");
        $this->line("  Fechas         : " . optional($period->start_date)->format('d/m/Y') . " → " . optional($period->end_date)->format('d/m/Y'));
        $this->line("  is_closed      : " . ($period->is_closed ? 'SÍ' : 'no'));
        $this->line('');

        // ── 2. component_period_ids ────────────────────────────────────
        $componentIds = collect($period->component_period_ids ?? [])->map(fn ($id) => (int) $id)->all();
        if (!empty($componentIds)) {
            $componentLabels = $allPeriods->whereIn('id', $componentIds)
                ->sortBy('sequence')
                ->map(fn ($p) => "#{$p->id} {$p->label}")
                ->join(', ');
            $this->info('── 2. Semanas componente (component_period_ids) ──');
            $this->line("  IDs    : [" . implode(', ', $componentIds) . "]");
            $this->line("  Labels : {$componentLabels}");
        } else {
            $this->warn('── 2. component_period_ids: vacío.');
        }
        $this->line('');

        // ── 3. IDs de datos resueltos ──────────────────────────────────
        $this->info('── 3. IDs de datos resueltos (dataIds) ──');
        $this->line("  resolveBaseWeeklyIds → [" . implode(', ', $weeklyIds) . "]");
        $this->line("  dataIds finales      → [" . implode(', ', $dataIds) . "]");
        $this->line('');

        // ── 4. PeriodSummary en BD ────────────────────────────────────
        $this->info('── 4. PeriodSummary ──');
        $summary = PeriodSummary::query()
            ->with(['branchSummaries', 'incidents'])
            ->where('period_id', $periodId)
            ->latest('id')
            ->first();

        if (!$summary) {
            $this->warn("  Sin PeriodSummary para este periodo.");
        } else {
            $this->line("  ID              : {$summary->id}");
            $this->line("  Status          : {$summary->status}");
            $this->line("  Version         : {$summary->version}");
            $this->line("  Generated at    : " . optional($summary->generated_at)->format('d/m/Y H:i'));
            $this->line("  Invalidated at  : " . (optional($summary->invalidated_at)->format('d/m/Y H:i') ?: '(ninguna)'));
            $this->line("  Branch summaries: {$summary->branchSummaries->count()}");
            $this->line("  Incidents       : {$summary->incidents->count()}");
            $this->line('');

            $gm = $summary->global_metrics ?? [];
            $this->info('── 4b. global_metrics almacenados en BD ──');
            $fields = [
                'recuperacion_total', 'colocacion_total', 'valor_cartera_total',
                'cartera_vencida_total', 'mora_porcentaje', 'gasto_total',
                'total_empleados', 'pagos_total', 'bonos_total', 'neto_total',
            ];
            foreach ($fields as $f) {
                $val = $gm[$f] ?? null;
                $fmt = is_null($val) ? '<fg=red>NULL</>' : (is_numeric($val) && $f !== 'total_empleados' && $f !== 'mora_porcentaje'
                    ? '$' . number_format((float) $val, 2)
                    : $val);
                $ok  = !is_null($val) && $val != 0 ? '<fg=green>✓</>' : '<fg=yellow>⚠</>';
                $this->line("  {$ok} {$f}: {$fmt}");
            }
        }
        $this->line('');

        // ── 5. Totales reales en fact_* usando dataIds ────────────────
        $this->info('── 5. Totales reales en fact_* (con dataIds) ──');

        $carteraTotal = (float) DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->sum('balance');
        $vencidaTotal = (float) DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->where('days_past_due', '>', 0)->sum('balance');
        $colocacion   = (float) DB::table('fact_placements')->whereIn('period_id', $dataIds)->sum('amount');
        $recuperacion = (float) DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->sum('total_amount');
        $gastos       = (float) DB::table('fact_expenses')->whereIn('period_id', $dataIds)->sum('amount');
        $nContratos   = (int)   DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->count();
        $nMinistr     = (int)   DB::table('fact_placements')->whereIn('period_id', $dataIds)->count();
        $nNoi         = (int)   DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->distinct('employee_id')->count('employee_id');

        $this->line(sprintf("  %-28s $%s  (%d contratos)", 'Cartera total',     number_format($carteraTotal, 2), $nContratos));
        $this->line(sprintf("  %-28s $%s",                  'Cartera vencida',   number_format($vencidaTotal, 2)));
        $this->line(sprintf("  %-28s %.2f%%",               'Mora %',            $carteraTotal > 0 ? round($vencidaTotal / $carteraTotal * 100, 2) : 0));
        $this->line(sprintf("  %-28s $%s  (%d ops)",        'Colocación',        number_format($colocacion, 2), $nMinistr));
        $this->line(sprintf("  %-28s $%s",                  'Recuperación',      number_format($recuperacion, 2)));
        $this->line(sprintf("  %-28s $%s",                  'Gastos',            number_format($gastos, 2)));
        $this->line(sprintf("  %-28s %d",                   'Empleados (NOI)',   $nNoi));
        $this->line('');

        // ── 6. Totales por section (debug secciones) ──────────────────
        $this->info('── 6. Diferencias summary vs. fact_* ──');
        if ($summary) {
            $gm = $summary->global_metrics ?? [];
            $diffs = [
                ['colocacion_total',      $gm['colocacion_total'] ?? 0,      $colocacion],
                ['recuperacion_total',    $gm['recuperacion_total'] ?? 0,    $recuperacion],
                ['valor_cartera_total',   $gm['valor_cartera_total'] ?? 0,   $carteraTotal],
                ['cartera_vencida_total', $gm['cartera_vencida_total'] ?? 0, $vencidaTotal],
                ['gasto_total',           $gm['gasto_total'] ?? 0,           $gastos],
            ];
            foreach ($diffs as [$label, $stored, $real]) {
                $diff = (float)$stored - $real;
                $ok   = abs($diff) < 1.0 ? '<fg=green>✓</>' : '<fg=red>✗</>';
                $this->line(sprintf(
                    "  %s %-28s BD=$%-14s Real=$%-14s Dif=%s",
                    $ok, $label,
                    number_format((float)$stored, 2),
                    number_format($real, 2),
                    abs($diff) < 1.0 ? '<fg=green>0.00</>' : '<fg=red>' . number_format($diff, 2) . '</>'
                ));
            }
        } else {
            $this->warn('  Sin PeriodSummary — no hay qué comparar.');
        }
        $this->line('');

        // ── 7. Archivos generados ──────────────────────────────────────
        $this->info('── 7. Archivos Excel / PDF generados ──');
        if ($summary) {
            $exports = PeriodRadiographyExport::query()
                ->where('period_summary_id', $summary->id)
                ->orderByDesc('id')
                ->get();

            if ($exports->isEmpty()) {
                $this->warn('  Sin exports registrados en period_radiography_exports.');
            } else {
                foreach ($exports as $exp) {
                    $exists   = $exp->export_path && File::exists($exp->export_path);
                    $marker   = $exists ? '<fg=green>✓ EXISTE</>' : '<fg=red>✗ NO EXISTE</>';
                    $this->line(sprintf(
                        "  [%s] %s — %s — %s",
                        strtoupper($exp->file_type ?? '?'),
                        $marker,
                        optional($exp->exported_at)->format('d/m/Y H:i') ?? '?',
                        $exp->export_path ?? '—'
                    ));
                }
            }
        }
        $this->line('');

        // ── 8. Estado del run ──────────────────────────────────────────
        $this->info('── 8. Último PeriodRadiographyRun ──');
        $run = PeriodRadiographyRun::query()->where('period_id', $periodId)->latest('id')->first();
        if (!$run) {
            $this->warn('  Sin run registrado.');
        } else {
            $this->line("  ID          : {$run->id}");
            $this->line("  Status      : {$run->status}");
            $this->line("  Started at  : " . optional($run->started_at)->format('d/m/Y H:i'));
            $this->line("  Finished at : " . optional($run->finished_at)->format('d/m/Y H:i'));
            $this->line("  Log         : " . mb_strimwidth($run->log ?? '', 0, 120, '...'));
        }
        $this->line('');

        // ── 9. Ruta del endpoint Excel ─────────────────────────────────
        $this->info('── 9. Endpoint Excel ──');
        try {
            $url = route('reportes-mensuales.export-radiography', $periodId);
            $this->line("  URL: {$url}");
            if ($summary && $summary->status === 'generated' && !$summary->invalidated_at) {
                $latestExport = PeriodRadiographyExport::query()
                    ->where('period_summary_id', $summary->id)
                    ->where('file_type', 'excel')
                    ->latest('id')
                    ->first();
                if ($latestExport && File::exists($latestExport->export_path)) {
                    $this->line('  <fg=green>✓ El archivo existe — descarga debe funcionar sin 302.</>');
                } else {
                    $this->warn('  ⚠ No hay archivo Excel — se generará on-demand al hacer clic.');
                }
            } else {
                $this->warn('  ⚠ Radiografía no está en estado generated — el endpoint retornará back() con error.');
            }
        } catch (\Throwable $e) {
            $this->warn("  No se pudo resolver la ruta: {$e->getMessage()}");
        }
        $this->line('');

        $this->info("══════════════════════════════════════════════════════════════");
        $this->line('');

        return self::SUCCESS;
    }
}
