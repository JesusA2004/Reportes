<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugPeriodCleanCommand extends Command
{
    protected $signature   = 'reportes:debug-period-clean {periodId : ID del periodo a diagnosticar}';
    protected $description = 'Diagnóstico de datos derivados de un periodo: conteos, productos sin movimiento, empleados sin sucursal, duplicados potenciales.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('periodId');

        $period = Period::find($periodId);
        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info("══════════ DEBUG PERIOD CLEAN — Periodo #{$periodId}: {$period->label} ══════════");
        $this->line('');

        // ── 1. CONTEOS POR TABLA ────────────────────────────────────────────
        $this->info('── 1. Conteos por tabla derivada ──');
        $tables = [
            'fact_noi_movements'          => 'Movimientos NOI',
            'fact_placements'             => 'Ministraciones/Colocación',
            'fact_recoveries'             => 'Recuperación/Cobranza',
            'fact_portfolios'             => 'Saldos/Cartera',
            'fact_expenses'               => 'Gastos',
            'fact_period_employee_summary' => 'Resumen empleados (consolidado)',
        ];

        foreach ($tables as $table => $label) {
            $cnt = DB::table($table)->where('period_id', $periodId)->count();
            $this->line(sprintf('  %-42s %s', $label . ':', $cnt > 0 ? "<fg=green>{$cnt}</>" : '<fg=red>0 — SIN DATOS</>'));
        }

        // Summaries
        $summaryIds = DB::table('period_summaries')->where('period_id', $periodId)->pluck('id');
        $this->line(sprintf('  %-42s %s', 'period_summaries:', count($summaryIds)));
        if ($summaryIds->isNotEmpty()) {
            $bsCount = DB::table('period_branch_summaries')->whereIn('period_summary_id', $summaryIds)->count();
            $inCount = DB::table('period_incidents')->whereIn('period_summary_id', $summaryIds)->count();
            $expCount = DB::table('period_radiography_exports')->whereIn('period_summary_id', $summaryIds)->count();
            $this->line(sprintf('  %-42s %s', 'period_branch_summaries:', $bsCount));
            $this->line(sprintf('  %-42s %s', 'period_incidents:', $inCount));
            $this->line(sprintf('  %-42s %s', 'radiography_exports (reportes generados):', $expCount));
        }

        $this->line('');

        // ── 2. PRODUCTOS VISIBLES SIN MOVIMIENTO ───────────────────────────
        $this->info('── 2. Productos visibles vs movimientos reales ──');
        $placementProducts = DB::table('fact_placements')
            ->where('period_id', $periodId)
            ->whereNotNull('product_name')
            ->where('product_name', '<>', '')
            ->selectRaw('product_name, COUNT(*) as ops, SUM(amount) as colocacion')
            ->groupBy('product_name')
            ->orderByDesc('colocacion')
            ->get();

        if ($placementProducts->isEmpty()) {
            $this->warn('  Sin productos con colocación en fact_placements.');
        } else {
            $this->line("  Productos con colocación ({$placementProducts->count()}):");
            foreach ($placementProducts as $p) {
                $this->line(sprintf('    %-25s ops=%-5d col=$%s', $p->product_name, $p->ops, number_format($p->colocacion, 2)));
            }
        }

        $portfolioProducts = DB::table('fact_portfolios')
            ->where('period_id', $periodId)
            ->whereNotNull('product_name')
            ->where('product_name', '<>', '')
            ->selectRaw('product_name, COUNT(*) as contratos, SUM(balance) as cartera')
            ->groupBy('product_name')
            ->orderByDesc('cartera')
            ->get();

        if ($portfolioProducts->isNotEmpty()) {
            $this->line("  Productos en cartera ({$portfolioProducts->count()}):");
            foreach ($portfolioProducts as $p) {
                $this->line(sprintf('    %-25s contratos=%-5d cartera=$%s', $p->product_name, $p->contratos, number_format($p->cartera, 2)));
            }
        }

        $this->line('');

        // ── 3. EMPLEADOS SIN SUCURSAL ────────────────────────────────────
        $this->info('── 3. Empleados sin sucursal asignada ──');
        $sinSucursal = DB::table('fact_period_employee_summary as s')
            ->join('employees as e', 's.employee_id', '=', 'e.id')
            ->where('s.period_id', $periodId)
            ->whereNull('s.branch_id')
            ->selectRaw('e.full_name, e.normalized_name')
            ->get();

        if ($sinSucursal->isEmpty()) {
            $this->line('  <fg=green>Todos los empleados consolidados tienen sucursal asignada.</>');
        } else {
            $this->warn("  {$sinSucursal->count()} empleado(s) sin sucursal en fact_period_employee_summary:");
            foreach ($sinSucursal as $e) {
                $this->line("    - {$e->full_name} (norm: {$e->normalized_name})");
            }
        }

        // También revisar fact_noi_movements
        $noiSinAsignacion = DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($periodId) {
                $j->on('eba.employee_id', '=', 'n.employee_id')->where('eba.period_id', '=', $periodId);
            })
            ->where('n.period_id', $periodId)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.branch_id')
            ->selectRaw('e.full_name, COUNT(*) as movimientos')
            ->groupBy('e.full_name')
            ->get();

        if ($noiSinAsignacion->isNotEmpty()) {
            $this->warn("  {$noiSinAsignacion->count()} empleado(s) en NOI sin employee_branch_assignments para este periodo:");
            foreach ($noiSinAsignacion as $e) {
                $this->line("    - {$e->full_name} ({$e->movimientos} mov)");
            }
        }

        $this->line('');

        // ── 4. GASTOS POR FUENTE ────────────────────────────────────────
        $this->info('── 4. Gastos por fuente ──');
        $gastosSrc = DB::table('fact_expenses as e')
            ->leftJoin('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('e.period_id', $periodId)
            ->selectRaw('COALESCE(ds.code,"sin_fuente") as fuente, COUNT(*) as registros, SUM(COALESCE(NULLIF(e.paid_amount,0),e.amount)) as total')
            ->groupBy('e.report_upload_id', 'ds.id', 'ds.code')
            ->get();

        if ($gastosSrc->isEmpty()) {
            $this->warn('  Sin gastos registrados en fact_expenses.');
        } else {
            foreach ($gastosSrc as $g) {
                $this->line(sprintf('  %-20s registros=%-5d total=$%s', $g->fuente, $g->registros, number_format($g->total, 2)));
            }
        }

        $this->line('');

        // ── 5. DUPLICADOS POTENCIALES ────────────────────────────────────
        $this->info('── 5. Duplicados potenciales en fact_placements ──');
        // fact_placements has no contract_number; detect by same promoter+client+amount+date
        $dups = DB::table('fact_placements')
            ->where('period_id', $periodId)
            ->selectRaw('promoter_code, normalized_client_name, amount, operation_date, COUNT(*) as cnt')
            ->groupBy('promoter_code', 'normalized_client_name', 'amount', 'operation_date')
            ->havingRaw('cnt > 1')
            ->orderByDesc('cnt')
            ->limit(10)
            ->get();

        if ($dups->isEmpty()) {
            $this->line('  <fg=green>Sin duplicados detectados en fact_placements.</>');
        } else {
            $this->warn("  {$dups->count()} combinación(es) con más de 1 fila (mismo promotor+cliente+monto+fecha):");
            foreach ($dups as $d) {
                $this->line(sprintf(
                    '    [%s] %s | %s | $%s  ×%d',
                    $d->promoter_code,
                    mb_substr($d->normalized_client_name ?? '—', 0, 25),
                    $d->operation_date,
                    number_format($d->amount, 2),
                    $d->cnt
                ));
            }
        }

        $this->line('');

        // ── 6. SNAPSHOTS/REPORTES OBSOLETOS ────────────────────────────
        $this->info('── 6. Estado de reportes generados ──');
        if ($summaryIds->isNotEmpty()) {
            $exports = DB::table('period_radiography_exports')
                ->whereIn('period_summary_id', $summaryIds)
                ->get();

            if ($exports->isEmpty()) {
                $this->line('  Sin reportes Excel/PDF generados para este periodo.');
            } else {
                foreach ($exports as $ex) {
                    $this->line(sprintf('  tipo=%-10s path=%s', $ex->export_type ?? 'N/A', $ex->export_path ?? '—'));
                }
            }
        } else {
            $this->warn('  Sin period_summary → no hay radiografía generada aún.');
        }

        $this->line('');
        $this->info('══════════ FIN DIAGNÓSTICO ══════════');
        $this->line('');

        return self::SUCCESS;
    }
}
