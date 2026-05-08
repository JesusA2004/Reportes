<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugEmpleadosCommand extends Command
{
    protected $signature   = 'reportes:debug-empleados {periodId : ID del periodo}';
    protected $description = 'Diagnóstico de asignación de sucursales a empleados: totales, fuente de resolución, sin match.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('periodId');

        $period = Period::find($periodId);
        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info("══════════ DEBUG EMPLEADOS — Periodo #{$periodId}: {$period->label} ══════════");
        $this->line('');

        // ── 1. RESUMEN fact_period_employee_summary ─────────────────────
        $this->info('── 1. fact_period_employee_summary ──');
        $total       = DB::table('fact_period_employee_summary')->where('period_id', $periodId)->count();
        $conSucursal = DB::table('fact_period_employee_summary')->where('period_id', $periodId)->whereNotNull('branch_id')->count();
        $sinSucursal = $total - $conSucursal;
        $incluidos   = DB::table('fact_period_employee_summary')->where('period_id', $periodId)->where('included_in_report', true)->count();

        $this->line(sprintf('  Total empleados consolidados:  %d', $total));
        $this->line(sprintf('  Con sucursal asignada:         %s', $conSucursal > 0 ? "<fg=green>{$conSucursal}</>" : '<fg=red>0</>'));
        $this->line(sprintf('  Sin sucursal:                  %s', $sinSucursal === 0 ? '<fg=green>0</>' : "<fg=red>{$sinSucursal}</>"));
        $this->line(sprintf('  Incluidos en reporte:          %d', $incluidos));

        $this->line('');

        // ── 2. EMPLOYEE_BRANCH_ASSIGNMENTS POR FUENTE ─────────────────────
        $this->info('── 2. Asignaciones por fuente (employee_branch_assignments) ──');
        $bySource = DB::table('employee_branch_assignments')
            ->where('period_id', $periodId)
            ->selectRaw('source_reference, match_type, COUNT(*) as cnt, SUM(CASE WHEN branch_id IS NOT NULL THEN 1 ELSE 0 END) as con_sucursal')
            ->groupBy('source_reference', 'match_type')
            ->orderByDesc('cnt')
            ->get();

        if ($bySource->isEmpty()) {
            $this->warn('  Sin registros en employee_branch_assignments para este periodo.');
        } else {
            $this->line(sprintf('  %-28s %-18s %6s %12s', 'FUENTE', 'MATCH TYPE', 'TOTAL', 'CON SUCURSAL'));
            $this->line('  ' . str_repeat('─', 68));
            foreach ($bySource as $s) {
                $this->line(sprintf(
                    '  %-28s %-18s %6d %12d',
                    $s->source_reference ?? '—',
                    $s->match_type ?? '—',
                    $s->cnt,
                    $s->con_sucursal
                ));
            }
        }

        $this->line('');

        // ── 3. NOI SIN ASIGNACIÓN ─────────────────────────────────────────
        $this->info('── 3. Empleados NOI sin asignación de sucursal ──');
        $noiSinAssign = DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($periodId) {
                $j->on('eba.employee_id', '=', 'n.employee_id')->where('eba.period_id', '=', $periodId);
            })
            ->where('n.period_id', $periodId)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.employee_id')
            ->selectRaw('e.id, e.employee_code, e.full_name, COUNT(DISTINCT n.id) as movs')
            ->groupBy('e.id', 'e.employee_code', 'e.full_name')
            ->orderByDesc('movs')
            ->get();

        if ($noiSinAssign->isEmpty()) {
            $this->line('  <fg=green>Todos los empleados NOI tienen asignación.</>');
        } else {
            $this->warn("  {$noiSinAssign->count()} empleado(s) sin asignación:");
            foreach ($noiSinAssign as $e) {
                // Check if resolvable via placements or portfolios
                $viaPlacement = DB::table('fact_placements')
                    ->where('period_id', $periodId)
                    ->where('normalized_promoter_name', $e->full_name ? mb_strtolower(preg_replace('/\s+/', ' ', $e->full_name)) : '')
                    ->whereNotNull('branch_id')
                    ->exists();

                $viaPortfolio = DB::table('fact_portfolios')
                    ->where('period_id', $periodId)
                    ->whereRaw('LOWER(promoter_name) = ?', [mb_strtolower(trim($e->full_name))])
                    ->whereNotNull('branch_id')
                    ->exists();

                $hint = match (true) {
                    $viaPlacement => '<fg=yellow>✓ resolvible vía colocación</>',
                    $viaPortfolio => '<fg=yellow>✓ resolvible vía cartera</>',
                    default       => '<fg=red>sin match</>',
                };

                $this->line(sprintf(
                    '    [%s] %-40s movs=%-3d %s',
                    $e->employee_code,
                    mb_substr($e->full_name, 0, 40),
                    $e->movs,
                    $hint
                ));
            }
        }

        $this->line('');

        // ── 4. DESGLOSE POR SUCURSAL (ASIGNADOS) ─────────────────────────
        $this->info('── 4. Empleados con sucursal (fact_period_employee_summary) ──');
        $bySucursal = DB::table('fact_period_employee_summary as s')
            ->join('branches as b', 's.branch_id', '=', 'b.id')
            ->where('s.period_id', $periodId)
            ->selectRaw('b.name as sucursal, COUNT(*) as empleados, SUM(s.total_payments) as nomina')
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('empleados')
            ->get();

        if ($bySucursal->isEmpty()) {
            $this->warn('  Sin empleados con sucursal asignada.');
        } else {
            foreach ($bySucursal as $b) {
                $this->line(sprintf('  %-30s empleados=%-4d nómina=$%s', $b->sucursal, $b->empleados, number_format((float) $b->nomina, 2)));
            }
        }

        $this->line('');

        // ── 5. EMPLEADOS SIN SUCURSAL EN RESUMEN ─────────────────────────
        $sinSucursalRows = DB::table('fact_period_employee_summary as s')
            ->join('employees as e', 's.employee_id', '=', 'e.id')
            ->where('s.period_id', $periodId)
            ->whereNull('s.branch_id')
            ->selectRaw('e.full_name, e.employee_code, s.exclusion_reason, s.total_payments')
            ->orderByDesc('s.total_payments')
            ->get();

        if ($sinSucursalRows->isNotEmpty()) {
            $this->info('── 5. Empleados SIN sucursal en resumen (requieren revisión) ──');
            foreach ($sinSucursalRows as $r) {
                $this->line(sprintf(
                    '    [%s] %-40s nómina=$%s  (%s)',
                    $r->employee_code,
                    mb_substr($r->full_name, 0, 40),
                    number_format((float) $r->total_payments, 2),
                    $r->exclusion_reason ?? '—'
                ));
            }
        } else {
            $this->info('── 5. Sin empleados sin sucursal ──');
            $this->line('  <fg=green>✓ Todos los empleados tienen sucursal en el resumen.</>');
        }

        $this->line('');
        $this->info('══════════ FIN DEBUG EMPLEADOS ══════════');
        $this->line('');

        return self::SUCCESS;
    }
}
