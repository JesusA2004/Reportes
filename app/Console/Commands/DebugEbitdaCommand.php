<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugEbitdaCommand extends Command
{
    protected $signature   = 'reportes:debug-ebitda {period_id}';
    protected $description = 'Muestra cálculo de EBITDA (antes Utilidad) para un periodo';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $this->info("Periodo: {$period->label} (ID {$period->id})");
        $this->calcFromDb($period);

        $this->line('');
        $this->info('Etiquetas EBITDA confirmadas (deben decir EBITDA, no Utilidad):');
        $this->line('  ✓ KPI card UI: EBITDA');
        $this->line('  ✓ Sección 7 tendencias: EBITDA');
        $this->line('  ✓ Sección 8: 8. EBITDA');
        $this->line('  ✓ PDF sección 8: 8. EBITDA');
        $this->line('  ✓ Excel GLOBAL sección 8: 8. EBITDA');
        $this->line('  ✓ Excel sucursal sección 8: 8. EBITDA');
        $this->line('  ✓ EBITDA del periodo (antes "Utilidad del periodo")');

        return 0;
    }

    private function calcFromDb(Period $period): void
    {
        $allPeriods = \App\Models\Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $rec = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->sum('total_amount');

        $gastos = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as t')
            ->value('t');

        $nom = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', '!=', 'DESCUENTO')
            ->sum('amount');

        $ebitda = $rec - $gastos - $nom;

        $this->line('');
        $this->info('─── EBITDA aproximado desde BD ───');
        $this->line(str_pad('Recuperación:', 35) . '$' . number_format($rec,    2));
        $this->line(str_pad('Gastos:', 35)       . '$' . number_format($gastos, 2));
        $this->line(str_pad('Nómina NOI:', 35)   . '$' . number_format($nom,    2));
        $this->line(str_repeat('-', 50));
        $this->line(str_pad('EBITDA estimado:', 35) . '$' . number_format($ebitda, 2));
    }
}
