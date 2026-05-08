<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugPeriodosCommand extends Command
{
    protected $signature   = 'reportes:debug-periodos {year? : Año a diagnosticar (default: año actual)}';
    protected $description = 'Vista general de periodos: semanas, mensuales operativos y compuestos automáticos. Muestra composición y estado de archivos.';

    public function handle(): int
    {
        $year = (int) ($this->argument('year') ?? date('Y'));

        $this->line('');
        $this->info("══════════ DEBUG PERIODOS — Año {$year} ══════════");
        $this->line('');

        $allPeriods = Period::query()
            ->where('year', $year)
            ->orderBy('type')
            ->orderBy('month')
            ->orderBy('sequence')
            ->get();

        if ($allPeriods->isEmpty()) {
            $this->warn("  No hay periodos registrados para el año {$year}.");
            return self::SUCCESS;
        }

        // ── 1. SEMANAS (base) ─────────────────────────────────────────
        $this->info('── 1. Periodos semanales (base) ──');

        $weekly = $allPeriods->where('type', 'weekly')->values();

        if ($weekly->isEmpty()) {
            $this->warn('  No hay semanas para este año.');
        } else {
            $uploadCountsByPeriod = DB::table('report_uploads')
                ->whereIn('period_id', $weekly->pluck('id'))
                ->selectRaw('period_id, COUNT(DISTINCT data_source_id) as cnt')
                ->groupBy('period_id')
                ->get()
                ->keyBy('period_id');

            $processedCountsByPeriod = DB::table('report_uploads')
                ->whereIn('period_id', $weekly->pluck('id'))
                ->where('status', 'processed')
                ->selectRaw('period_id, COUNT(DISTINCT data_source_id) as cnt')
                ->groupBy('period_id')
                ->get()
                ->keyBy('period_id');

            $currentMonth = 0;
            foreach ($weekly as $w) {
                if ($w->month !== $currentMonth) {
                    $currentMonth = $w->month;
                    $monthName = $this->monthName($currentMonth);
                    $this->line("  ── {$monthName} ──");
                }

                $uploaded  = (int) ($uploadCountsByPeriod[$w->id]->cnt ?? 0);
                $processed = (int) ($processedCountsByPeriod[$w->id]->cnt ?? 0);
                $range     = optional($w->start_date)->format('d/m') . ' – ' . optional($w->end_date)->format('d/m');
                $closed    = $w->is_closed ? '<fg=gray>[CERRADO]</>' : '';

                $this->line(sprintf(
                    '  #%-4d %-30s %-14s archivos=%d / procesados=%d %s',
                    $w->id,
                    $w->name,
                    $range,
                    $uploaded,
                    $processed,
                    $closed
                ));
            }
        }

        $this->line('');

        // ── 2. MENSUALES (operativos) ─────────────────────────────────
        $this->info('── 2. Periodos mensuales (operativos) ──');

        $monthly = $allPeriods->where('type', 'monthly')->values();

        if ($monthly->isEmpty()) {
            $this->warn('  No hay periodos mensuales. Créalos en el módulo Periodos.');
        } else {
            foreach ($monthly as $m) {
                $componentIds = $m->component_period_ids ?? [];
                $compLabels   = $allPeriods->whereIn('id', $componentIds)->pluck('name')->join(', ');
                $range = optional($m->start_date)->format('d/m/Y') . ' – ' . optional($m->end_date)->format('d/m/Y');

                $this->line(sprintf(
                    '  #%-4d %-22s %-24s semanas=[%s]',
                    $m->id,
                    $m->name,
                    $range,
                    $compLabels ?: '—'
                ));
            }
        }

        $this->line('');

        // ── 3. COMPUESTOS AUTOMÁTICOS ─────────────────────────────────
        $this->info('── 3. Periodos compuestos automáticos ──');

        $compoundTypes = ['bimonthly' => 'Bimestres', 'quarterly' => 'Trimestres', 'semiannual' => 'Semestres', 'annual' => 'Anual'];

        foreach ($compoundTypes as $type => $label) {
            $group = $allPeriods->where('type', $type)->values();
            if ($group->isEmpty()) continue;

            $this->line("  {$label}:");
            foreach ($group as $p) {
                $componentIds = $p->component_period_ids ?? [];
                $compLabels   = $allPeriods->whereIn('id', $componentIds)->pluck('name')->join(', ');
                $range = optional($p->start_date)->format('d/m/Y') . ' – ' . optional($p->end_date)->format('d/m/Y');

                $this->line(sprintf(
                    '    #%-4d %-30s %-24s compone=[%s]',
                    $p->id,
                    $p->name,
                    $range,
                    $compLabels ?: '(date-range fallback)'
                ));
            }
            $this->line('');
        }

        // ── 4. RESUMEN ────────────────────────────────────────────────
        $this->info('── 4. Resumen ──');
        $this->line(sprintf('  Semanales: %d  |  Mensuales: %d  |  Bimestres: %d  |  Trimestres: %d  |  Semestres: %d  |  Anual: %d',
            $allPeriods->where('type', 'weekly')->count(),
            $allPeriods->where('type', 'monthly')->count(),
            $allPeriods->where('type', 'bimonthly')->count(),
            $allPeriods->where('type', 'quarterly')->count(),
            $allPeriods->where('type', 'semiannual')->count(),
            $allPeriods->where('type', 'annual')->count(),
        ));

        // Meses sin periodo mensual configurado
        $monthsWithWeekly  = $allPeriods->where('type', 'weekly')->pluck('month')->unique()->sort()->values();
        $monthsWithMonthly = $allPeriods->where('type', 'monthly')->pluck('month')->unique()->sort()->values();
        $monthsWithoutMonthly = $monthsWithWeekly->diff($monthsWithMonthly)->values();

        if ($monthsWithoutMonthly->isNotEmpty()) {
            $missing = $monthsWithoutMonthly->map(fn ($m) => $this->monthName($m))->join(', ');
            $this->warn("  ⚠ Meses sin periodo mensual configurado: {$missing}");
            $this->line('  <fg=cyan>→ Créalos en Periodos → tipo Mensual para habilitar reportes mensuales.</fg=cyan>');
        } else {
            $this->line('  <fg=green>✓ Todos los meses con semanas tienen periodo mensual configurado.</fg=green>');
        }

        $this->line('');
        $this->info('══════════ FIN DEBUG PERIODOS ══════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function monthName(int $month): string
    {
        $names = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',
                  7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        return $names[$month] ?? "Mes {$month}";
    }
}
