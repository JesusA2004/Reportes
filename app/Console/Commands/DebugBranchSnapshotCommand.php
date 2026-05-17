<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugBranchSnapshotCommand extends Command
{
    protected $signature   = 'reportes:debug-branch-snapshot {period_id : ID del periodo mensual}';
    protected $description = 'Debug: construye snapshot por sucursal, suma GLOBAL y compara contra targets Abril 2026.';

    public function __construct(private readonly BranchRadiographyCalculator $calculator)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG BRANCH SNAPSHOT — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // ── Construir summaries ───────────────────────────────────────────────
        $this->line('  Construyendo branch summaries (resolviendo branch_id → sucursal real)...');
        $branches = $this->calculator->buildBranches($period, $dataIds);
        $global   = $this->calculator->sumGlobal($branches);
        $this->line('  OK — ' . count($branches) . ' sucursales operativas procesadas.');
        $this->line('');

        // ── Tabla principal por sucursal ──────────────────────────────────────
        $this->info('── Métricas por sucursal ──');
        $this->line('');

        $tableRows = [];
        foreach ($branches as $b) {
            $tableRows[] = [
                $b['sucursal'],
                $this->fmt($b['valor_cartera']),
                $this->fmt($b['colocacion']),
                $this->fmt($b['recuperacion_total']),
                $this->fmt($b['mora_0_30']),
                $this->fmt($b['mora_31_60']),
                $this->fmt($b['mora_61_90']),
                $this->fmt($b['mora_91_120']),
                $this->fmt($b['mora_120_plus']),
                $this->fmt($b['gastos_operativos']),
                $this->fmt($b['nomina_total']),
                $this->fmt($b['excedentes']),
                $this->fmt($b['prestamos_fondea']),
            ];
        }

        // Fila GLOBAL al final
        $tableRows[] = [
            'GLOBAL (SUMA)',
            $this->fmt($global['valor_cartera']),
            $this->fmt($global['colocacion']),
            $this->fmt($global['recuperacion_total']),
            $this->fmt($global['mora_0_30']),
            $this->fmt($global['mora_31_60']),
            $this->fmt($global['mora_61_90']),
            $this->fmt($global['mora_91_120']),
            $this->fmt($global['mora_120_plus']),
            $this->fmt($global['gastos_operativos']),
            $this->fmt($global['nomina_total']),
            $this->fmt($global['excedentes']),
            $this->fmt($global['prestamos_fondea']),
        ];

        $this->table(
            ['Sucursal', 'Cartera', 'Colocación', 'Recuperación', 'Mora 0-30', 'Mora 31-60', 'Mora 61-90', 'Mora 91-120', 'Mora 120+', 'Gastos Op.', 'Nómina', 'Excedentes', 'Fondeo'],
            $tableRows
        );
        $this->line('');

        // ── Detalle recuperación por sucursal ─────────────────────────────────
        $this->info('── Detalle recuperación por sucursal (PAGO) ──');
        $this->line('');

        $recRows = [];
        foreach ($branches as $b) {
            $recRows[] = [
                $b['sucursal'],
                $this->fmt($b['capital_recuperado']),
                $this->fmt($b['interes_recuperado']),
                $this->fmt($b['impuesto_recuperado']),
                $this->fmt($b['charges']),
                $this->fmt($b['recuperacion_total']),
            ];
        }
        $recRows[] = [
            'GLOBAL (SUMA)',
            $this->fmt($global['capital_recuperado']),
            $this->fmt($global['interes_recuperado']),
            $this->fmt($global['impuesto_recuperado']),
            $this->fmt($global['charges']),
            $this->fmt($global['recuperacion_total']),
        ];

        $this->table(
            ['Sucursal', 'Capital', 'Interés', 'Impuesto', 'Cargos+Multas', 'Total PAGO'],
            $recRows
        );
        $this->line('');

        // ── Comparación global vs targets Abril 2026 ──────────────────────────
        $this->info('── Comparación GLOBAL vs. targets Abril 2026 ──');
        $this->line('');

        // multas ($149,384.52) + cargos_inicio ($18,063.90) = $167,448.42
        $targets = [
            ['Cartera total',            'valor_cartera',       39_130_054.53],
            ['Colocación (sin restr.)',   'colocacion',          14_538_964.00],
            ['Capital recuperado',       'capital_recuperado',  12_883_858.49],
            ['Interés recuperado',       'interes_recuperado',   4_545_211.24],
            ['Impuesto recuperado',      'impuesto_recuperado',    727_231.40],
            ['Cargos+Multas (charges)',  'charges',               167_448.42],
            ['Recuperación total',       'recuperacion_total',  18_323_749.55],
            ['Mora 0-30',                'mora_0_30',            1_057_765.00],
            ['Mora 31-60',               'mora_31_60',             926_001.80],
            ['Mora 61-90',               'mora_61_90',             913_414.71],
            ['Mora 91-120',              'mora_91_120',            816_584.91],
            ['Mora 120+',                'mora_120_plus',       10_259_891.95],
            ['Gastos operativos',        'gastos_operativos',      837_384.28],
            ['Nómina total',             'nomina_total',         1_075_509.70],
            ['Excedentes (util. corp.)', 'excedentes',           3_076_800.00],
            ['Préstamos fondea',         'prestamos_fondea',       449_425.00],
        ];

        foreach ($targets as [$label, $key, $target]) {
            $val  = (float) ($global[$key] ?? 0);
            $diff = $val - $target;
            $pct  = $target > 0 ? abs($diff / $target) * 100 : 0;

            if (abs($diff) < 1) {
                $status = '<fg=green>✓ OK   </>';
                $diffStr = '<fg=green>' . number_format($diff, 2) . '</>';
            } elseif ($pct < 0.1) {
                $status = '<fg=yellow>~ CERCA</>';
                $diffStr = '<fg=yellow>' . number_format($diff, 2) . '</>';
            } else {
                $status = '<fg=red>✗ DIF  </>';
                $diffStr = '<fg=red>' . number_format($diff, 2) . '</>';
            }

            $this->line(sprintf(
                '  %s %-28s calc=$%-16s tgt=$%-16s dif=%s (%.2f%%)',
                $status,
                $label,
                number_format($val, 2),
                number_format($target, 2),
                $diffStr,
                $pct
            ));
        }

        $this->line('');

        // ── Sucursales con diferencia en cartera vs recuperación ──────────────
        $this->info('── Sucursales con algún valor (cartera > 0 o recuperación > 0) ──');
        $this->line('');

        foreach ($branches as $b) {
            if ($b['valor_cartera'] > 0 || $b['recuperacion_total'] > 0) {
                $this->line(sprintf(
                    '  %-30s  cartera=%-14s  colocacion=%-14s  recuperacion=%-14s  gastos=%-12s',
                    $b['sucursal'],
                    $this->fmt($b['valor_cartera']),
                    $this->fmt($b['colocacion']),
                    $this->fmt($b['recuperacion_total']),
                    $this->fmt($b['gastos_operativos'])
                ));
            }
        }

        $this->line('');

        // ── Branch IDs NO resueltos a las 13 sucursales (excluidos del global) ─
        $this->info('── Branch IDs excluidos del GLOBAL (no resueltos a las 13 sucursales) ──');
        $this->line('');

        $maps         = $this->calculator->buildBranchMap();
        $operativeIds = array_keys($maps['operative']);

        $unresolvedPortfolio = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $dataIds)
            ->when(!empty($operativeIds), fn ($q) => $q->whereNotIn('po.branch_id', $operativeIds))
            ->selectRaw('b.name as branch_name, SUM(po.balance) as cartera, COUNT(*) as filas')
            ->groupBy('po.branch_id', 'b.name')
            ->orderByDesc('cartera')
            ->get();

        if ($unresolvedPortfolio->isEmpty()) {
            $this->line('  <fg=green>✓ Todas las filas de cartera resuelven a sucursales operativas.</>');
        } else {
            $this->warn('  Cartera excluida:');
            foreach ($unresolvedPortfolio as $r) {
                $this->line(sprintf(
                    '    %-40s  $%s (%d filas)',
                    $r->branch_name,
                    number_format((float) $r->cartera, 2),
                    (int) $r->filas
                ));
            }
        }

        $this->line('');

        // Recovery: classify each non-operative branch as Pass2-included vs truly excluded
        $resolver = app(\App\Services\BranchResolverService::class);

        $unresolvedRecovery = DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->when(!empty($operativeIds), fn ($q) => $q->whereNotIn('r.branch_id', $operativeIds))
            ->selectRaw("b.name as branch_name, LEFT(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.accredited_name')), 3) as prefix3, SUM(r.total_amount) as total, COUNT(*) as filas")
            ->groupByRaw('r.branch_id, b.name, LEFT(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, \'$.accredited_name\')), 3)')
            ->orderByDesc('total')
            ->get();

        if ($unresolvedRecovery->isEmpty()) {
            $this->line('  <fg=green>✓ Todas las filas de recuperación resuelven a sucursales operativas.</>');
        } else {
            $pass2Rows   = [];
            $excludedRows = [];
            foreach ($unresolvedRecovery as $r) {
                $prefix3  = strtoupper(trim((string) $r->prefix3));
                $resolved = $resolver->resolveBranchNameFromCode($prefix3);
                $isSheet  = $resolved ? $resolver->isSheetBranch($resolved) : false;
                if ($isSheet) {
                    $pass2Rows[$r->branch_name . '→' . $resolved] = ($pass2Rows[$r->branch_name . '→' . $resolved] ?? 0) + (float) $r->total;
                } else {
                    $excludedRows[$r->branch_name . ' [' . ($resolved ?? '???') . ']'] = ($excludedRows[$r->branch_name . ' [' . ($resolved ?? '???') . ']'] ?? 0) + (float) $r->total;
                }
            }

            if (!empty($pass2Rows)) {
                arsort($pass2Rows);
                $this->line('  <fg=green>Recuperación incluida vía fallback (accredited_name prefix):</>');
                foreach ($pass2Rows as $label => $total) {
                    $this->line(sprintf('    <fg=green>✓</> %-45s  $%s', $label, number_format($total, 2)));
                }
                $this->line('');
            }

            if (!empty($excludedRows)) {
                arsort($excludedRows);
                $this->warn('  Recuperación verdaderamente excluida (Norte/Corp/desconocido):');
                foreach ($excludedRows as $label => $total) {
                    $this->line(sprintf('    %-50s  $%s', $label, number_format($total, 2)));
                }
            }
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function fmt(float|int $val): string
    {
        return '$' . number_format((float) $val, 2);
    }
}
