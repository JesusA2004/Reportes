<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPayrollCommand extends Command
{
    protected $signature = 'reportes:audit-payroll
                                {period_id}
                                {--detail : show full breakdown by branch and concept}';
    protected $description = 'Auditoría de nómina NOI: percepciones, descuentos, neto por sucursal y concepto';

    private const NOI_DEDUCTION_CODES = ['D094', 'D010', 'D113', 'D123', 'D004', 'D111'];
    private const NOI_DEDUCTION_LABELS = [
        'Descuentos Infonavit', 'Pensión Alimenticia', 'Descuento Servicios Moto',
        'Financiamiento de Motos (desc.)', 'Préstamo Personal',
    ];

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

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA NÓMINA — {$period->label} (ID {$period->id})");
        $this->info("  Fuente: fact_noi_movements (NOI importado)");
        $this->info("════════════════════════════════════════════════════════════");

        // ── A. Totales globales ───────────────────────────────────────────────
        $this->line('');
        $this->info('════ A. TOTALES NOI ════');

        $totals = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('
                COUNT(DISTINCT employee_id) as empleados,
                COUNT(*) as movimientos,
                SUM(CASE WHEN concept_type = "percepcion" THEN amount ELSE 0 END) as percepciones,
                SUM(CASE WHEN concept_type = "deduccion" AND concept REGEXP "^D(094|010|113|123|004|111)" THEN amount ELSE 0 END) as desctos_noi,
                SUM(CASE WHEN concept_type = "deduccion" AND concept NOT REGEXP "^D(094|010|113|123|004)" THEN amount ELSE 0 END) as otras_deducciones,
                SUM(CASE WHEN concept_type = "percepcion" THEN amount ELSE -amount END) as neto
            ')
            ->first();

        $percep     = (float)($totals->percepciones ?? 0);
        $desctosNOI = (float)($totals->desctos_noi ?? 0);
        $otrasDedc  = (float)($totals->otras_deducciones ?? 0);
        $nomNeto    = $percep - $desctosNOI;
        $refNomina  = 1_928_818.31;

        $this->line(str_pad('Concepto', 45) . str_pad('Monto', 20) . 'Nota');
        $this->line(str_repeat('─', 80));
        $this->line(str_pad('Empleados (NOI)',              45) . str_pad(number_format($totals->empleados ?? 0), 20));
        $this->line(str_pad('Movimientos totales',          45) . str_pad(number_format($totals->movimientos ?? 0), 20));
        $this->line(str_pad('Percepciones (costo empresa)', 45) . str_pad('$' . number_format($percep, 2), 20) . '← P001, P002, P108+, P009, P010');
        $this->line(str_pad('Descuentos NOI (al empleado)', 45) . str_pad('−$' . number_format($desctosNOI, 2), 20) . '← D094, D010, D113, D123, D004');
        $this->line(str_pad('Otras deducciones (ISR, IMSS...)', 45) . str_pad('$' . number_format($otrasDedc, 2), 20) . '← retenciones, no gasto empresa');
        $this->line(str_repeat('─', 80));
        $this->line(str_pad('NÓMINA NETA (percep − desctos NOI)', 45) . str_pad('$' . number_format($nomNeto, 2), 20) . '← usado en EBITDA');
        $this->line('');
        $this->line(str_pad('REFERENCIA abril 2026',       45) . '$' . number_format($refNomina, 2));
        $this->line(str_pad('Diferencia vs referencia',    45) . '$' . number_format($nomNeto - $refNomina, 2));

        // ── B. Por concepto (percepciones) ────────────────────────────────────
        $this->line('');
        $this->info('════ B. PERCEPCIONES POR CONCEPTO (top 15) ════');

        $percepRows = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'percepcion')
            ->select('concept', DB::raw('COUNT(DISTINCT employee_id) as emps'), DB::raw('COUNT(*) as mvts'), DB::raw('SUM(amount) as total'))
            ->groupBy('concept')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        $this->line(str_pad('Concepto', 40) . str_pad('Empl', 7) . str_pad('Mvts', 7) . 'Total');
        $this->line(str_repeat('─', 70));
        foreach ($percepRows as $r) {
            $this->line(
                str_pad(mb_substr($r->concept ?? 'NULL', 0, 38), 40) .
                str_pad(number_format($r->emps), 7) .
                str_pad(number_format($r->mvts), 7) .
                '$' . number_format((float)$r->total, 2)
            );
        }

        // ── C. Descuentos NOI (al empleado) ──────────────────────────────────
        $this->line('');
        $this->info('════ C. DESCUENTOS NOI (deducciones al empleado — NO gasto empresa) ════');

        $desctRows = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept_type', 'deduccion')
            ->whereRaw("concept REGEXP '^D(094|010|113|123|004|111)'")
            ->select('concept', DB::raw('COUNT(DISTINCT employee_id) as emps'), DB::raw('COUNT(*) as mvts'), DB::raw('SUM(amount) as total'))
            ->groupBy('concept')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Concepto', 40) . str_pad('Empl', 7) . str_pad('Mvts', 7) . str_pad('Total', 17) . 'Tipo');
        $this->line(str_repeat('─', 80));
        foreach ($desctRows as $r) {
            $code = mb_substr($r->concept ?? '', 0, 4);
            $desc = match($code) {
                'D094' => 'Infonavit',
                'D010' => 'Pensión Alimenticia',
                'D113' => 'Serv Moto',
                'D123' => 'Préstamo Moto',
                'D004' => 'Préstamo Personal',
                default => 'Otro descuento NOI',
            };
            $this->line(
                str_pad(mb_substr($r->concept ?? 'NULL', 0, 38), 40) .
                str_pad(number_format($r->emps), 7) .
                str_pad(number_format($r->mvts), 7) .
                str_pad('−$' . number_format((float)$r->total, 2), 17) .
                $desc
            );
        }
        $this->line(str_repeat('─', 80));
        $this->line(str_pad('TOTAL DESCUENTOS NOI',        40) . str_pad('', 14) . '−$' . number_format($desctosNOI, 2));
        $this->line('');
        $this->info('  Estos descuentos NO son gasto adicional de la empresa.');
        $this->info('  Son montos que el empleado cede de su sueldo (préstamos, cuotas, etc.).');

        if ($this->option('detail')) {
            $this->showDetailByBranch($dataIds);
        }

        return 0;
    }

    private function showDetailByBranch(array $dataIds): void
    {
        $this->line('');
        $this->info('════ D. NÓMINA NETA POR SUCURSAL ════');

        $byBranch = DB::table('fact_noi_movements as nm')
            ->leftJoin('employees as e', 'nm.employee_id', '=', 'e.id')
            ->leftJoin('employee_branch_assignments as eba', 'eba.employee_id', '=', 'e.id')
            ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('nm.period_id', $dataIds)
            ->select(
                DB::raw('COALESCE(b.name, "Sin sucursal") as sucursal'),
                DB::raw('COUNT(DISTINCT nm.employee_id) as emps'),
                DB::raw('SUM(CASE WHEN nm.concept_type = "percepcion" THEN nm.amount ELSE 0 END) as percep'),
                DB::raw('SUM(CASE WHEN nm.concept_type = "deduccion" AND nm.concept REGEXP "^D(094|010|113|123|004|111)" THEN nm.amount ELSE 0 END) as desctos_noi'),
                DB::raw('SUM(CASE WHEN nm.concept_type = "percepcion" THEN nm.amount ELSE 0 END) - SUM(CASE WHEN nm.concept_type = "deduccion" AND nm.concept REGEXP "^D(094|010|113|123|004|111)" THEN nm.amount ELSE 0 END) as neto')
            )
            ->groupBy('b.name')
            ->orderByDesc('neto')
            ->get();

        $this->line(str_pad('Sucursal', 30) . str_pad('Empl', 7) . str_pad('Percep', 17) .
                    str_pad('Desctos NOI', 14) . 'Neto');
        $this->line(str_repeat('─', 80));

        foreach ($byBranch as $r) {
            $this->line(
                str_pad(mb_substr($r->sucursal, 0, 28), 30) .
                str_pad(number_format($r->emps), 7) .
                str_pad('$' . number_format((float)$r->percep, 0), 17) .
                str_pad('−$' . number_format((float)$r->desctos_noi, 0), 14) .
                '$' . number_format((float)$r->neto, 2)
            );
        }
    }
}
