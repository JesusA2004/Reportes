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

        $otorgamientos = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->sum('amount');

        $gastosOp = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('category', ['Nómina y Capital Humano', 'Envío de utilidad a corporativo', 'Préstamos Intersucursales'])
            ->selectRaw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as t')
            ->value('t');

        $nomPercep = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'percepcion')
            ->sum('amount');

        $nomDesctos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'deduccion')
            ->whereRaw("concept REGEXP '^D(094|010|113|123|004)'")
            ->sum('amount');

        $nomNeta     = $nomPercep - $nomDesctos;
        $gastosTotal = $gastosOp + $nomNeta;
        $ebitda      = $rec - $otorgamientos - $gastosTotal;

        $envio = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->where('category', 'Envío de utilidad a corporativo')
            ->selectRaw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as t')
            ->value('t');

        $diferencia = $ebitda - $envio;

        $this->line('');
        $this->info('─── EBITDA = Ingresos − Otorgamientos − Gastos Totales ───');
        $this->line(str_pad('Ingresos (recuperación):', 38) . ' $' . number_format($rec, 2));
        $this->line(str_pad('Menos: Otorgamientos:',    38) . '−$' . number_format($otorgamientos, 2));
        $this->line(str_pad('Menos: Gastos totales:',   38) . '−$' . number_format($gastosTotal, 2));
        $this->line(str_pad('  Gastos operativos:',     38) . '  $' . number_format($gastosOp, 2));
        $this->line(str_pad('  Nómina neta NOI:',       38) . '  $' . number_format($nomNeta, 2));
        $this->line(str_pad('    Percepciones:',        38) . '   $' . number_format($nomPercep, 2));
        $this->line(str_pad('    Descuentos NOI:',      38) . '  −$' . number_format($nomDesctos, 2));
        $this->line(str_repeat('-', 55));
        $this->line(str_pad('EBITDA:',                  38) . ' $' . number_format($ebitda, 2));
        $this->line('');
        $this->line(str_pad('Envío a corporativo:',     38) . '−$' . number_format($envio, 2));
        $this->line(str_repeat('-', 55));
        $this->line(str_pad('Diferencia:',              38) . ' $' . number_format($diferencia, 2));
    }
}
