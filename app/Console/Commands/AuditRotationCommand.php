<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditRotationCommand extends Command
{
    protected $signature   = 'reportes:audit-rotation
                                {period_id}
                                {--compare-reference : comparar contra referencia Abril 2026}
                                {--detail            : listar empleados con baja}';
    protected $description = 'Auditoría de rotación de personal: bajas, promedio, índice';

    private const REF_BAJAS    = 8;
    private const REF_PROMEDIO = 123;
    private const REF_INDICE   = 6.50;

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds, [$period->id]
        )));

        $this->line('');
        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA ROTACIÓN DE PERSONAL — {$period->label} (ID {$period->id})");
        $this->info("  Fórmula: Índice = Bajas ÷ Promedio × 100");
        $this->info("════════════════════════════════════════════════════════════════");

        // ── A. Empleados periodo actual ────────────────────────────────────────
        $currentEmps = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->toArray();
        $currentCount = count($currentEmps);

        $this->line('');
        $this->info('════ A. EMPLEADOS PERIODO ACTUAL ════');
        $this->line("  Empleados con movimientos NOI: {$currentCount}");

        // ── B. Empleados periodo anterior (para calcular bajas) ───────────────
        $prevPeriod = $allPeriods
            ->filter(fn ($p) => $p->id < $period->id)
            ->sortByDesc('id')
            ->first();

        $bajas     = 0;
        $prevCount = 0;
        $bajasEmps = [];

        if ($prevPeriod) {
            $prevWeeklyIds = $prevPeriod->resolveBaseWeeklyIds($allPeriods);
            $prevDataIds   = array_values(array_unique(array_merge(
                empty($prevWeeklyIds) ? [] : $prevWeeklyIds, [$prevPeriod->id]
            )));

            $prevEmpRows = DB::table('fact_noi_movements as n')
                ->leftJoin('employees as e', 'n.employee_id', '=', 'e.id')
                ->whereIn('n.period_id', $prevDataIds)
                ->whereNotNull('n.employee_id')
                ->select('n.employee_id', DB::raw('MAX(COALESCE(e.name,"—")) as nombre'))
                ->groupBy('n.employee_id')
                ->get();
            $prevCount  = $prevEmpRows->count();

            $currentSet = array_flip($currentEmps);
            foreach ($prevEmpRows as $emp) {
                if (!isset($currentSet[$emp->employee_id])) {
                    $bajas++;
                    $bajasEmps[] = [
                        'id'     => $emp->employee_id,
                        'nombre' => $emp->nombre,
                    ];
                }
            }

            $this->line('');
            $this->info("════ B. PERIODO ANTERIOR: {$prevPeriod->label} (ID {$prevPeriod->id}) ════");
            $this->line("  Empleados en periodo anterior: {$prevCount}");
            $this->line("  Bajas detectadas (no aparecen en actual): {$bajas}");
        } else {
            $this->warn("  ⚠ No se encontró un periodo anterior. No se puede calcular bajas.");
        }

        // ── C. Cálculo rotación ───────────────────────────────────────────────
        $promedio = $prevCount > 0 ? (int) round(($prevCount + $currentCount) / 2) : $currentCount;
        $indice   = $promedio > 0 ? round($bajas / $promedio * 100, 2) : 0.0;

        $this->line('');
        $this->info('════ C. ROTACIÓN CALCULADA ════');
        $this->line("  N° personas periodo anterior:     {$prevCount}");
        $this->line("  N° personas periodo actual:       {$currentCount}");
        $this->line("  Promedio = (prev + actual) / 2:   {$promedio}");
        $this->line("  Bajas:                            {$bajas}");
        $this->line("  Índice rotación = bajas/prom×100: {$indice}%");

        // ── D. Comparativo vs referencia ──────────────────────────────────────
        if ($this->option('compare-reference')) {
            $this->line('');
            $this->info('════ D. COMPARATIVO VS REFERENCIA ABRIL 2026 ════');

            $items = [
                ['Bajas',    self::REF_BAJAS,    $bajas,    ''],
                ['Promedio', self::REF_PROMEDIO, $promedio, ''],
                ['Índice %', self::REF_INDICE,   $indice,   '%'],
            ];
            $this->line(str_pad('Métrica', 14) . str_pad('Referencia', 14) . str_pad('Sistema', 14) . str_pad('Diferencia', 14) . 'Estado');
            $this->line(str_repeat('─', 60));
            foreach ($items as [$lbl, $ref, $sys, $suf]) {
                $diff  = $sys - $ref;
                $sign  = $diff >= 0 ? '+' : '';
                $match = abs($diff) < 0.05 ? '✓ EXACTO' : '≈ DIFERENTE';
                $line  = str_pad($lbl, 14) .
                    str_pad($ref . $suf, 14) .
                    str_pad($sys . $suf, 14) .
                    str_pad($sign . number_format(abs($diff), 2), 14) .
                    $match;
                if ($match === '✓ EXACTO') $this->info("  {$line}");
                else $this->warn("  {$line}");
            }

            if ($bajas !== self::REF_BAJAS || $promedio !== self::REF_PROMEDIO) {
                $this->line('');
                $this->warn('  ⚠ DIFERENCIAS DETECTADAS:');
                if ($promedio !== self::REF_PROMEDIO) {
                    $this->warn("  → Promedio calculado ({$promedio}) ≠ referencia (" . self::REF_PROMEDIO . ").");
                    $this->warn("  → Posibles causas: empleados regionales/CORPORATIVO incluidos en el conteo.");
                    $this->warn("  → El conteo debe excluir empleados sin sucursal operativa o de Región Norte.");
                }
                if ($bajas !== self::REF_BAJAS) {
                    $this->warn("  → Bajas calculadas ({$bajas}) ≠ referencia (" . self::REF_BAJAS . ").");
                    $this->warn("  → Verificar ACUMULADOS ABRIL SUCURSALES.xls para lista oficial de bajas.");
                }
            }
        }

        // ── E. Detalle empleados con baja ────────────────────────────────────
        if ($this->option('detail') && !empty($bajasEmps)) {
            $this->line('');
            $this->info('════ E. EMPLEADOS DETECTADOS COMO BAJA ════');
            $this->line(str_pad('ID', 8) . 'Nombre');
            $this->line(str_repeat('─', 50));
            foreach ($bajasEmps as $emp) {
                $this->line("  " . str_pad((string)$emp['id'], 8) . $emp['nombre']);
            }
            $this->line('');
            $this->line("  Fuente de verdad para bajas: ACUMULADOS ABRIL SUCURSALES.xls");
        }

        return 0;
    }
}
