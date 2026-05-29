<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugProductsReportCommand extends Command
{
    protected $signature   = 'reportes:debug-products-report {period_id}';
    protected $description = 'Valida que RECURSOS PROPIOS no aparece como producto operativo en el reporte';

    // Matches RadiographySnapshotBuilder::PRODUCT_SPECIAL_PATTERN + PRODUCT_RESTRUCTURE_PATTERN
    private const EXCLUDE = 'A LA MEDIDA|DIARIO|CREDITO CONSUMO|REESTRUCTURA|UNIFICACION|MIGRACION|INSOLUTOS|SEGURO|RECURSOS PROPIOS';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $this->info("Periodo: {$period->label} (ID {$period->id})");

        $allPeriods = \App\Models\Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        // Check if RECURSOS PROPIOS appears in placements
        $rrpp = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("product_name REGEXP ?", ['RECURSOS PROPIOS'])
            ->selectRaw('product_name, COUNT(*) as ops, SUM(amount) as total')
            ->groupBy('product_name')
            ->get();

        $this->line('');
        if ($rrpp->isEmpty()) {
            $this->info('✓ RECURSOS PROPIOS: no aparece en ministraciones del periodo.');
        } else {
            $this->warn('! RECURSOS PROPIOS encontrado en ministraciones (esperado — debe estar excluido del reporte):');
            foreach ($rrpp as $row) {
                $this->line("  {$row->product_name}: {$row->ops} ops, $" . number_format((float)$row->total, 2));
            }
        }

        // Show what WILL appear as operativo products (after exclusion)
        $operativos = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("(product_name NOT REGEXP ? OR product_name IS NULL)", [self::EXCLUDE])
            ->selectRaw('COALESCE(NULLIF(product_name,""),"Sin producto") as producto, COUNT(*) as ops, SUM(amount) as total')
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        $this->line('');
        $this->info('Productos operativos que SÍ aparecerán en el reporte:');
        $this->line(str_pad('Producto', 40) . str_pad('Ops', 8) . 'Colocación');
        $this->line(str_repeat('-', 70));
        foreach ($operativos as $p) {
            $this->line(str_pad($p->producto, 40) . str_pad($p->ops, 8) . '$' . number_format((float)$p->total, 2));
        }

        return 0;
    }
}
