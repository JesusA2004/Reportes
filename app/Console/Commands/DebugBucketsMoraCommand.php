<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugBucketsMoraCommand extends Command
{
    protected $signature   = 'reportes:debug-buckets-mora {period_id}';
    protected $description = 'Valida buckets de mora para un periodo — confirma 120+ vs 121-180/180+';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $this->info("Periodo: {$period->label} (ID {$period->id})");

        // Resolve data IDs
        $allPeriods = \App\Models\Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $buckets = [
            ['label' => 'Al corriente', 'min' => 0,   'max' => 0],
            ['label' => 'Mora 1-30',    'min' => 1,   'max' => 30],
            ['label' => 'Mora 31-60',   'min' => 31,  'max' => 60],
            ['label' => 'Mora 61-90',   'min' => 61,  'max' => 90],
            ['label' => 'Mora 91-120',  'min' => 91,  'max' => 120],
            ['label' => 'Mora 120+',    'min' => 121, 'max' => 99999],
        ];

        $this->line('');
        $this->line(str_pad('Bucket', 20) . str_pad('Contratos', 12) . str_pad('Vencida', 20));
        $this->line(str_repeat('-', 52));

        $totalMora = 0.0;
        foreach ($buckets as $b) {
            $row = DB::table('fact_portfolios')
                ->whereIn('period_id', $dataIds)
                ->where('days_past_due', '>=', $b['min'])
                ->where('days_past_due', '<=', $b['max'])
                ->selectRaw('COUNT(*) as cnt, SUM(COALESCE(past_due_balance,0)) as vencida')
                ->first();

            $vencida = (float)($row?->vencida ?? 0);
            if ($b['min'] > 0) $totalMora += $vencida;

            $this->line(
                str_pad($b['label'], 20) .
                str_pad(number_format($row?->cnt ?? 0), 12) .
                '$' . number_format($vencida, 2)
            );
        }

        $this->line(str_repeat('-', 52));
        $this->line(str_pad('Mora total', 20) . str_pad('', 12) . '$' . number_format($totalMora, 2));
        $this->line('');

        // Verify no separate 121-180 / 180+ rows
        $rows121_180 = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->where('days_past_due', '>=', 121)
            ->where('days_past_due', '<=', 180)
            ->count();
        $rows180plus = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->where('days_past_due', '>', 180)
            ->count();
        $rows120plus = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->where('days_past_due', '>', 120)
            ->count();

        $this->info("Contratos 121-180 días (deben ir en Mora 120+): {$rows121_180}");
        $this->info("Contratos 180+ días  (deben ir en Mora 120+): {$rows180plus}");
        $this->info("Contratos 120+       (suma de ambos):          {$rows120plus}");

        if ($rows121_180 + $rows180plus === $rows120plus) {
            $this->info("✓ Los registros 121-180 y 180+ se agrupan correctamente en Mora 120+.");
        } else {
            $this->warn("! Discrepancia en conteo — revisar manualmente.");
        }

        return 0;
    }
}
