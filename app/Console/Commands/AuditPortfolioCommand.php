<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPortfolioCommand extends Command
{
    protected $signature   = 'reportes:audit-portfolio
                                {period_id}
                                {--compare-reference : comparar cartera total contra referencia confirmada}
                                {--by-branch          : comparativo por sucursal oficial (resuelta, sin rutas, sin Corporativo)}
                                {--detail              : detalle adicional por producto/sucursal}';
    protected $description = 'Auditoría completa de cartera y mora — misma fuente única que reportes:audit-moras (BranchRadiographyCalculator)';

    private const REF_CARTERA = 39_130_054.53;

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
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA CARTERA/MORA — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("  Fuente única: BranchRadiographyCalculator (igual que audit-moras, Excel, PDF, dashboard)");
        $this->info("════════════════════════════════════════════════════════════");

        // ── Totales generales crudos (diagnóstico, NO el KPI oficial) ────────
        $tot = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('COUNT(*) as contratos, SUM(balance) as cartera')
            ->first();

        $this->line('');
        $this->info('── TOTALES GENERALES (sin exclusiones — vista cruda, solo diagnóstico) ──');
        $this->line("  Contratos:     " . number_format($tot->contratos));
        $this->line("  Cartera:       $" . number_format((float) $tot->cartera, 2));

        // ── Fuente única: BranchRadiographyCalculator (13 sucursales oficiales) ──
        $calc   = app(BranchRadiographyCalculator::class);
        $result = $calc->buildBranches($period, $dataIds, []);
        $global = $calc->sumGlobal($result['branches'], $result['unassigned']);

        $carteraOficial = (float) $global['valor_cartera'];
        $moraTotal = (float) $global['mora_0_30'] + (float) $global['mora_31_60']
            + (float) $global['mora_61_90'] + (float) $global['mora_91_120'] + (float) $global['mora_120_plus'];
        $moraPct = $carteraOficial > 0 ? round($moraTotal / $carteraOficial * 100, 2) : 0;

        if ($this->option('compare-reference')) {
            $this->line('');
            $this->info('════ A. VALOR CARTERA OFICIAL vs REFERENCIA ════');
            $this->line('  (13 sucursales oficiales resueltas — sin rutas, sin Corporativo, sin Región Norte)');
            $diff = $carteraOficial - self::REF_CARTERA;
            $sign = $diff >= 0 ? '+' : '';
            $match = abs($diff) < 1 ? '✓ EXACTO' : (abs($diff) < 50_000 ? '≈ CERCANO' : '✗ DIFERENCIA');
            $this->line('  Sistema:     $' . number_format($carteraOficial, 2));
            $this->line('  Referencia:  $' . number_format(self::REF_CARTERA, 2));
            $line = "  Diferencia:  {$sign}\$" . number_format(abs($diff), 2) . "  {$match}";
            if ($match === '✓ EXACTO') $this->info($line);
            elseif ($match === '✗ DIFERENCIA') $this->warn($line);
            else $this->line($line);
        }

        $this->line('');
        $this->info("── MORA POR BUCKET (fuente única: BranchRadiographyCalculator) ──");
        $this->line("  Mora 1-30:     $" . number_format((float) $global['mora_0_30'], 2));
        $this->line("  Mora 31-60:    $" . number_format((float) $global['mora_31_60'], 2));
        $this->line("  Mora 61-90:    $" . number_format((float) $global['mora_61_90'], 2));
        $this->line("  Mora 91-120:   $" . number_format((float) $global['mora_91_120'], 2));
        $this->line("  Mora 120+:     $" . number_format((float) $global['mora_120_plus'], 2));
        $this->line("  ─────────────────────────────────");
        $this->line("  Mora Total:    $" . number_format($moraTotal, 2) . "  ({$moraPct}%)");
        $this->line('');
        $this->info('  Ver detalle completo (por sucursal, contrato a contrato, export CSV) con: reportes:audit-moras');

        // ── B) POR SUCURSAL OFICIAL ─────────────────────────────────────────
        if ($this->option('by-branch')) {
            $this->line('');
            $this->info('════ B. CARTERA POR SUCURSAL OFICIAL (fuente única) ════');

            $branches = collect($result['branches'])->sortByDesc('valor_cartera');

            $this->line(str_pad('Sucursal', 22) . str_pad('Cartera', 18) . str_pad('Mora', 18) . str_pad('Mora %', 10) . 'Acción');
            $this->line(str_repeat('─', 80));
            $totCartera = 0.0;
            foreach ($branches as $b) {
                $cartera = (float) $b['valor_cartera'];
                $mora = (float) $b['mora_0_30'] + (float) $b['mora_31_60'] + (float) $b['mora_61_90']
                    + (float) $b['mora_91_120'] + (float) $b['mora_120_plus'];
                $pct = $cartera > 0 ? round($mora / $cartera * 100, 2) : 0;
                $accion = $pct > 50 ? 'Revisar mora alta' : '';
                $this->line(
                    str_pad(mb_substr($b['sucursal'], 0, 20), 22) .
                    str_pad('$' . number_format($cartera, 2), 18) .
                    str_pad('$' . number_format($mora, 2), 18) .
                    str_pad($pct . '%', 10) .
                    $accion
                );
                $totCartera += $cartera;
            }
            $this->line(str_repeat('─', 80));
            $this->line(str_pad('TOTAL', 22) . '$' . number_format($totCartera, 2));
        }

        // ── Por producto (diagnóstico, vista cruda) ───────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('── POR PRODUCTO (top 20, vista cruda — solo diagnóstico) ──');

            $byProd = DB::table('fact_portfolios')
                ->whereIn('period_id', $dataIds)
                ->select('product_name',
                    DB::raw('COUNT(*) as contratos'),
                    DB::raw('SUM(balance) as cartera'),
                    DB::raw('SUM(CASE WHEN days_past_due > 0 THEN balance ELSE 0 END) as vencida'))
                ->groupBy('product_name')
                ->orderByDesc('cartera')
                ->limit(20)
                ->get();

            $this->line(str_pad('Producto', 40) . str_pad('Contratos', 12) .
                        str_pad('Cartera', 20) . str_pad('Vencida', 18) . 'Clasificación');
            $this->line(str_repeat('-', 100));
            foreach ($byProd as $p) {
                $name  = $p->product_name ?? 'NULL';
                $upper = strtoupper($name);
                $clasif = 'OPERATIVO';
                if (str_contains($upper, 'RECURSOS PROPIOS')) $clasif = 'FONDEO';
                elseif (str_contains($upper, 'REESTRUCTURA') || str_contains($upper, 'UNIFICACION')) $clasif = 'REESTRUCTURA';
                elseif (str_contains($upper, 'CRECE')) $clasif = 'CRECE (OPERATIVO)';
                elseif (str_contains($upper, 'SEGURO')) $clasif = 'SEGURO (excluido)';

                $this->line(
                    str_pad(mb_substr($name, 0, 38), 40) .
                    str_pad(number_format($p->contratos), 12) .
                    str_pad('$' . number_format((float)$p->cartera, 0), 20) .
                    str_pad('$' . number_format((float)$p->vencida, 0), 18) .
                    $clasif
                );
            }
        }

        // ── Verificación columna días vencidos ────────────────────────────────
        $this->line('');
        $this->info('── VERIFICACIÓN COLUMNA DÍAS VENCIDOS ──');

        $maxDpd = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->max('days_past_due');
        $minDpd = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->min('days_past_due');
        $nullDpd = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->whereNull('days_past_due')->count();

        $this->line("  Columna en BD:    days_past_due (alias: 'dias_vencido', 'dias_vencidos')");
        $this->line("  Rango:            min={$minDpd}  max={$maxDpd}");
        $this->line("  Nulls:            {$nullDpd}");
        $this->line("  ✓ Se usa 'días vencidos' (no atraso/mora) para buckets — correcto.");

        return 0;
    }
}
