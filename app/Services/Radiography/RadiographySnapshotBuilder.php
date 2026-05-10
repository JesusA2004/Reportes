<?php

namespace App\Services\Radiography;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodSummary;
use App\Models\Placement;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Services\BranchResolverService;
use Illuminate\Support\Facades\DB;

/**
 * Builds a single comprehensive snapshot array from all DB tables.
 * Used as the single source of truth for Excel, PDF, web preview, and email.
 */
class RadiographySnapshotBuilder
{
    private array $branchCache = [];
    private array $dataIds     = [];

    public function build(Period $period, PeriodSummary $summary): array
    {
        $this->branchCache = [];
        $this->dataIds     = $this->resolveDataIds($period);

        $gm = $summary->global_metrics ?? [];

        // If global_metrics has zero overdue but days_past_due > 0 data exists, recalculate
        if (($gm['cartera_vencida_total'] ?? 0) == 0 && ($gm['valor_cartera_total'] ?? 0) > 0) {
            $hasOverdueDays = \App\Models\Portfolio::query()
                ->whereIn('period_id', $this->dataIds)
                ->where('days_past_due', '>', 0)
                ->exists();
            if ($hasOverdueDays) {
                $vencidaFallback = (float) \App\Models\Portfolio::query()
                    ->whereIn('period_id', $this->dataIds)
                    ->where('days_past_due', '>', 0)
                    ->sum('balance');
                $carteraTotal = (float) ($gm['valor_cartera_total'] ?? 0);
                $gm['cartera_vencida_total'] = $vencidaFallback;
                $gm['mora_porcentaje'] = $carteraTotal > 0 ? round($vencidaFallback / $carteraTotal * 100, 2) : 0;
            }
        }

        // If global_metrics totals are still zero but fact tables have data, recalculate inline
        if (($gm['valor_cartera_total'] ?? 0) == 0) {
            $gm['valor_cartera_total']   = (float) DB::table('fact_portfolios')->whereIn('period_id', $this->dataIds)->sum('balance');
            $gm['cartera_vencida_total'] = (float) DB::table('fact_portfolios')->whereIn('period_id', $this->dataIds)->where('days_past_due', '>', 0)->sum('balance');
            $ct = $gm['valor_cartera_total'];
            $cv = $gm['cartera_vencida_total'];
            $gm['mora_porcentaje'] = $ct > 0 ? round($cv / $ct * 100, 2) : 0;
        }
        if (($gm['colocacion_total'] ?? 0) == 0) {
            $gm['colocacion_total'] = (float) DB::table('fact_placements')->whereIn('period_id', $this->dataIds)->sum('amount');
        }
        if (($gm['recuperacion_total'] ?? 0) == 0) {
            $gm['recuperacion_total'] = (float) DB::table('fact_recoveries')->whereIn('period_id', $this->dataIds)->sum('total_amount');
        }
        if (($gm['gasto_total'] ?? 0) == 0) {
            $gm['gasto_total'] = (float) DB::table('fact_expenses')->whereIn('period_id', $this->dataIds)->sum('amount');
        }

        $payroll = $this->buildPayroll($period);

        return [
            'period' => [
                'id'         => $period->id,
                'label'      => $period->label,
                'code'       => $period->code,
                'start_date' => optional($period->start_date)->format('d/m/Y'),
                'end_date'   => optional($period->end_date)->format('d/m/Y'),
            ],
            'generated_at' => now('America/Mexico_City')->format('d/m/Y H:i'),
            'version'      => $summary->version ?? 1,
            'summary' => [
                'employees_count'       => $payroll['total_empleados'],
                'recovery_total'        => (float)($gm['recuperacion_total'] ?? 0),
                'placement_total'       => (float)($gm['colocacion_total'] ?? 0),
                'portfolio_total'       => (float)($gm['valor_cartera_total'] ?? 0),
                'overdue_portfolio'     => (float)($gm['cartera_vencida_total'] ?? 0),
                'mora_index'            => (float)($gm['mora_porcentaje'] ?? 0),
                'expenses_total'        => (float)($gm['gasto_total'] ?? 0),
                'payroll_total'         => $payroll['pagos'] + $payroll['bonos'],
                'net_payroll'           => $payroll['neto'],
            ],
            'sections' => [
                'payroll'            => $payroll,
                'products'           => $this->buildProducts($period),
                'branches'           => $this->buildBranches($period, $summary),
                'employees'          => $this->buildEmployees($period),
                'promoters'          => $this->buildPromoters($period),
                'employees_gestores' => $this->buildEmployeesGestores($period),
                'portfolio_buckets'  => $this->buildPortfolioBuckets($period),
                'expenses_detail'    => $this->buildExpensesDetail($period),
                'incidents'          => $this->buildIncidents($summary),
            ],
            'charts' => [
                'recovery_by_branch'      => $this->chartByBranch($period, 'recuperacion'),
                'placement_by_product'    => $this->chartPlacementByProduct($period),
                'mora_by_bucket'          => $this->chartMoraByBucket($period),
                'top_promoters_placement' => $this->chartTopPromoters($period),
                'portfolio_by_branch'     => $this->chartByBranch($period, 'cartera'),
            ],
        ];
    }

    // ── PERIOD IDS RESOLVER ───────────────────────────────────────────────────

    private function resolveDataIds(Period $period): array
    {
        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        if (empty($weeklyIds)) {
            return [$period->id];
        }
        // Include the period itself so uploads stored directly on monthly periods are covered
        return array_values(array_unique(array_merge($weeklyIds, [$period->id])));
    }

    // ── PAYROLL ─────────────────────────────────────────────────────────────

    private function buildPayroll(Period $period): array
    {
        // fact_period_employee_summary is always written for the exact monthly period id
        $mes = DB::table('fact_period_employee_summary')
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as cnt, SUM(total_payments) as pagos, SUM(total_bonuses) as bonos, SUM(total_discounts) as descuentos, SUM(total_expenses) as gastos, SUM(net_amount) as neto')
            ->first();

        $mesCount = (int)($mes?->cnt ?? 0);

        if ($mesCount > 0 && ((float)($mes?->pagos ?? 0) + (float)($mes?->bonos ?? 0)) > 0) {
            return [
                'total_empleados' => $mesCount,
                'pagos'           => round((float)($mes->pagos ?? 0), 2),
                'bonos'           => round((float)($mes->bonos ?? 0), 2),
                'descuentos'      => round((float)($mes->descuentos ?? 0), 2),
                'gastos'          => round((float)($mes->gastos ?? 0), 2),
                'neto'            => round((float)($mes->neto ?? 0), 2),
                'source'          => 'consolidation',
            ];
        }

        // Fallback: aggregate from fact_noi_movements using all relevant period IDs
        $empCount = (int) DB::table('fact_noi_movements')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('employee_id')
            ->distinct('employee_id')
            ->count('employee_id');

        $pagos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->whereRaw("LOWER(COALESCE(concept,'')) NOT LIKE '%bono%'")
            ->sum('amount');

        $bonos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->whereRaw("LOWER(COALESCE(concept,'')) LIKE '%bono%'")
            ->sum('amount');

        $descuentos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) IN ('deduccion','descuento')")
            ->sum('amount');

        if ($pagos === 0.0 && $bonos === 0.0 && $descuentos === 0.0) {
            $pagos = (float) DB::table('fact_noi_movements')
                ->whereIn('period_id', $this->dataIds)
                ->whereNotNull('employee_id')
                ->sum('amount');
        }

        $neto = $pagos + $bonos - $descuentos;

        $gastos = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('employee_id')
            ->sum('amount');

        if ($empCount === 0) {
            $empCount = (int) DB::table('employee_branch_assignments')
                ->whereIn('period_id', $this->dataIds)
                ->whereNotNull('branch_id')
                ->distinct('employee_id')
                ->count('employee_id');
        }

        return [
            'total_empleados' => $empCount,
            'pagos'           => round($pagos, 2),
            'bonos'           => round($bonos, 2),
            'descuentos'      => round($descuentos, 2),
            'gastos'          => round($gastos, 2),
            'neto'            => round($neto, 2),
            'source'          => 'noi_direct',
        ];
    }

    // ── EMPLOYEES (from MonthlyEmployeeSummary + NOI fallback) ──────────────

    private function buildEmployees(Period $period): array
    {
        $rows = MonthlyEmployeeSummary::query()
            ->with(['employee:id,full_name', 'branch:id,name'])
            ->where('period_id', $period->id)
            ->orderByDesc('total_payments')
            ->get();

        if ($rows->isNotEmpty()) {
            return $rows->map(fn ($r) => [
                'name'       => $r->employee?->full_name ?? 'Sin empleado',
                'branch'     => $r->branch?->name ?? '—',
                'pagos'      => (float)$r->total_payments,
                'bonos'      => (float)$r->total_bonuses,
                'descuentos' => (float)$r->total_discounts,
                'gastos'     => (float)$r->total_expenses,
                'neto'       => (float)$r->net_amount,
                'included'   => (bool)$r->included_in_report,
            ])->values()->all();
        }

        $rows = DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                  ->where('eba.period_id', '=', $period->id);
            })
            ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('n.period_id', $this->dataIds)
            ->whereNotNull('n.employee_id')
            ->selectRaw('
                n.employee_id,
                e.full_name as name,
                e.normalized_name as normalized_name,
                b.name as branch,
                SUM(n.amount) as pagos,
                SUM(n.amount) as neto
            ')
            ->groupBy('n.employee_id', 'e.full_name', 'e.normalized_name', 'b.name')
            ->orderByDesc('pagos')
            ->get();

        $needsBranch = $rows->filter(fn ($r) => !$r->branch)->pluck('normalized_name')->values()->all();

        $activityBranches = [];
        if (!empty($needsBranch)) {
            $placements = DB::table('fact_placements as p')
                ->join('branches as b', 'p.branch_id', '=', 'b.id')
                ->whereIn('p.period_id', $this->dataIds)
                ->whereIn('p.normalized_promoter_name', $needsBranch)
                ->selectRaw('p.normalized_promoter_name, b.name as branch')
                ->distinct()
                ->get()
                ->groupBy('normalized_promoter_name')
                ->map(fn ($g) => $g->first()->branch);

            $recoveries = DB::table('fact_recoveries as r')
                ->join('branches as b', 'r.branch_id', '=', 'b.id')
                ->whereIn('r.period_id', $this->dataIds)
                ->selectRaw('r.promoter_name, b.name as branch')
                ->whereNotNull('r.promoter_name')
                ->distinct()
                ->get()
                ->groupBy(fn ($r) => $this->normalizeHumanName($r->promoter_name))
                ->map(fn ($g) => $g->first()->branch);

            $activityBranches = array_merge($placements->all(), $recoveries->all());
        }

        return $rows->map(function ($r) use ($activityBranches) {
            $branch = $r->branch
                ?? $activityBranches[$r->normalized_name ?? '']
                ?? null;

            return [
                'name'       => $r->name,
                'branch'     => $branch ?? 'Sin asignar',
                'pagos'      => (float)$r->pagos,
                'bonos'      => 0.0,
                'descuentos' => 0.0,
                'gastos'     => 0.0,
                'neto'       => (float)$r->neto,
                'included'   => true,
            ];
        })->values()->all();
    }

    // ── PRODUCTS ─────────────────────────────────────────────────────────────

    private const PRODUCT_SPECIAL_PATTERN    = 'CRECE|A LA MEDIDA|DIARIO|CREDITO CONSUMO';
    private const PRODUCT_RESTRUCTURE_PATTERN = 'REESTRUCTURA|UNIFICACION|MIGRACION|INSOLUTOS';
    // Excludes multi-option grouped product names like "S12 / S16" or "I20 / I30"
    private const PRODUCT_GROUP_PATTERN = '[Ss][0-9]+\\s*/\\s*[Ss][0-9]+|[Ii][0-9]+\\s*/\\s*[Ii][0-9]+';

    private function buildProducts(Period $period): array
    {
        $excludeAll = self::PRODUCT_SPECIAL_PATTERN . '|' . self::PRODUCT_RESTRUCTURE_PATTERN . '|SEGURO';

        $placements = DB::table('fact_placements')
            ->whereIn('period_id', $this->dataIds)
            ->whereRaw("(product_name NOT REGEXP ? OR product_name IS NULL)", [$excludeAll])
            ->whereRaw("(product_name NOT REGEXP ? OR product_name IS NULL)", [self::PRODUCT_GROUP_PATTERN])
            ->selectRaw('COALESCE(NULLIF(product_name, ""), "Sin producto") as producto, COUNT(*) as operaciones, SUM(amount) as colocacion')
            ->groupBy('product_name')
            ->orderByDesc('colocacion')
            ->get()
            ->keyBy('producto');

        $recoveries = DB::table('fact_recoveries')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('product_name')
            ->where('product_name', '<>', '')
            ->whereRaw("product_name NOT REGEXP ?", [$excludeAll])
            ->whereRaw("product_name NOT REGEXP ?", [self::PRODUCT_GROUP_PATTERN])
            ->selectRaw('product_name as producto, SUM(total_amount) as recuperacion')
            ->groupBy('product_name')
            ->get()
            ->keyBy('producto');

        $portfolioMain = DB::table('fact_portfolios')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('product_name')
            ->where('product_name', '<>', '')
            ->whereRaw("product_name NOT REGEXP ?", [$excludeAll])
            ->whereRaw("product_name NOT REGEXP ?", [self::PRODUCT_GROUP_PATTERN])
            ->selectRaw('product_name as producto, COUNT(*) as contratos, SUM(balance) as cartera, SUM(CASE WHEN days_past_due > 0 THEN COALESCE(capital_due, past_due_balance, balance) ELSE 0 END) as vencida')
            ->groupBy('product_name')
            ->get()
            ->keyBy('producto');

        $portfolioOtros = DB::table('fact_portfolios')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('product_name')
            ->where('product_name', '<>', '')
            ->whereRaw("product_name REGEXP ? AND product_name NOT REGEXP ?", [self::PRODUCT_SPECIAL_PATTERN, self::PRODUCT_RESTRUCTURE_PATTERN])
            ->selectRaw('product_name as producto, COUNT(*) as contratos, SUM(balance) as cartera, SUM(CASE WHEN days_past_due > 0 THEN COALESCE(capital_due, past_due_balance, balance) ELSE 0 END) as vencida')
            ->groupBy('product_name')
            ->orderByDesc('cartera')
            ->get();

        $portfolioReestructuras = DB::table('fact_portfolios')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('product_name')
            ->where('product_name', '<>', '')
            ->whereRaw("product_name REGEXP ?", [self::PRODUCT_RESTRUCTURE_PATTERN])
            ->selectRaw('product_name as producto, COUNT(*) as contratos, SUM(balance) as cartera, SUM(CASE WHEN days_past_due > 0 THEN COALESCE(capital_due, past_due_balance, balance) ELSE 0 END) as vencida')
            ->groupBy('product_name')
            ->orderByDesc('cartera')
            ->get();

        $allProducts = $placements->keys()
            ->merge($recoveries->keys())
            ->merge($portfolioMain->keys())
            ->unique()
            ->values();

        $totalColocacion = $placements->sum('colocacion') ?: 1;

        $buildRow = function (string $producto, string $tipo) use ($placements, $recoveries, $portfolioMain, $portfolioOtros, $portfolioReestructuras, $totalColocacion) {
            $p  = $placements->get($producto);
            $r  = $recoveries->get($producto);
            $pp = match ($tipo) {
                'otro_cartera' => $portfolioOtros->firstWhere('producto', $producto),
                'reestructura' => $portfolioReestructuras->firstWhere('producto', $producto),
                default        => $portfolioMain->get($producto),
            };
            $col = (float)($p?->colocacion ?? 0);
            return [
                'producto'     => $producto,
                'tipo'         => $tipo,
                'operaciones'  => (int)($p?->operaciones ?? 0),
                'colocacion'   => $col,
                'recuperacion' => (float)($r?->recuperacion ?? 0),
                'cartera'      => (float)($pp?->cartera ?? 0),
                'contratos'    => (int)($pp?->contratos ?? 0),
                'pct'          => round($col / $totalColocacion * 100, 1),
            ];
        };

        // Only show products with at least one positive monetary metric
        $operativos = $allProducts->map(fn (string $p) => $buildRow($p, 'operativo'))
            ->filter(fn ($p) => $p['colocacion'] > 0 || $p['recuperacion'] > 0 || $p['cartera'] > 0)
            ->sortByDesc('colocacion')
            ->values();

        $otrosCartera = $portfolioOtros
            ->map(fn ($row) => $buildRow($row->producto, 'otro_cartera'))
            ->filter(fn ($p) => $p['cartera'] > 0)
            ->sortByDesc('cartera')
            ->values();

        $reestructuras = $portfolioReestructuras
            ->map(fn ($row) => $buildRow($row->producto, 'reestructura'))
            ->filter(fn ($p) => $p['cartera'] > 0)
            ->sortByDesc('cartera')
            ->values();

        return $operativos->concat($otrosCartera)->concat($reestructuras)->all();
    }

    // ── BRANCHES ─────────────────────────────────────────────────────────────

    private function buildBranches(Period $period, PeriodSummary $summary): array
    {
        $realBranchNames = $this->resolveRealBranchNormalizedNames();

        $fromSummary = $summary->branchSummaries->map(function (PeriodBranchSummary $bs) use ($period, $realBranchNames) {
            $branch  = $this->resolveBranch($bs->branch_id);
            $m       = $bs->metrics ?? [];
            $cartera = (float)($m['valor_cartera'] ?? 0);
            $vencida = (float)($m['cartera_vencida'] ?? 0);

            // Skip branches that are routes/offices, not real sucursales
            if (!empty($realBranchNames) && $branch && !in_array($this->normalizeText($branch->name), $realBranchNames, true)) {
                return null;
            }

            if ($vencida === 0.0 && $cartera > 0 && $bs->branch_id) {
                $vencida = (float) \App\Models\Portfolio::query()
                    ->whereIn('period_id', $this->dataIds)
                    ->where('branch_id', $bs->branch_id)
                    ->where('days_past_due', '>', 0)
                    ->sum('balance');
            }
            $mora = $cartera > 0 ? round($vencida / $cartera * 100, 2) : (float)($m['mora_porcentaje'] ?? 0);
            return [
                'branch_id'    => $bs->branch_id,
                'nombre'       => $branch?->name ?? "Sucursal #{$bs->branch_id}",
                'recuperacion' => (float)($m['recuperacion_total'] ?? 0),
                'colocacion'   => (float)($m['colocacion_total'] ?? 0),
                'cartera'      => $cartera,
                'vencida'      => $vencida,
                'mora'         => $mora,
                'gastos'       => (float)($m['gasto_total'] ?? 0),
            ];
        })->filter()->sortByDesc('cartera')->values()->all();

        if (!empty($fromSummary)) {
            return $fromSummary;
        }

        // Fallback: build from raw tables, filtered to real branches only
        $branchIds = collect()
            ->merge(Recovery::query()->whereIn('period_id', $this->dataIds)->pluck('branch_id'))
            ->merge(Placement::query()->whereIn('period_id', $this->dataIds)->pluck('branch_id'))
            ->merge(Portfolio::query()->whereIn('period_id', $this->dataIds)->pluck('branch_id'))
            ->merge(Expense::query()->whereIn('period_id', $this->dataIds)->pluck('branch_id'))
            ->filter()->unique()->values();

        return $branchIds->map(function ($bId) use ($realBranchNames) {
            $branch  = $this->resolveBranch($bId);

            // Skip routes/offices
            if (!empty($realBranchNames) && $branch && !in_array($this->normalizeText($branch->name), $realBranchNames, true)) {
                return null;
            }

            $cartera = (float) Portfolio::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->sum('balance');
            $vencida = (float) Portfolio::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->where('days_past_due', '>', 0)->sum('balance');
            return [
                'branch_id'    => $bId,
                'nombre'       => $branch?->name ?? "Sucursal #{$bId}",
                'recuperacion' => (float) Recovery::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->sum('total_amount'),
                'colocacion'   => (float) Placement::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->sum('amount'),
                'cartera'      => $cartera,
                'vencida'      => $vencida,
                'mora'         => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0,
                'gastos'       => (float) Expense::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->sum('amount'),
            ];
        })->filter()->sortByDesc('cartera')->values()->all();
    }

    // ── PROMOTERS ──────────────────────────────────────────────────────────

    private function buildPromoters(Period $period): array
    {
        $placementRows = DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $this->dataIds)
            ->where(function ($q) {
                $q->whereNotNull('p.promoter_name')->orWhereNotNull('p.promoter_code');
            })
            ->selectRaw('
                p.normalized_promoter_name as norm_key,
                COALESCE(p.promoter_name, p.promoter_code, "Sin nombre") as gestor,
                COALESCE(p.promoter_code, "") as codigo,
                b.name as sucursal,
                COUNT(*) as operaciones,
                SUM(p.amount) as colocacion
            ')
            ->groupBy('p.normalized_promoter_name', 'p.promoter_name', 'p.promoter_code', 'b.name')
            ->orderByDesc('colocacion')
            ->limit(100)
            ->get()
            ->keyBy('norm_key');

        $portfolioRows = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->where(function ($q) {
                $q->whereNotNull('po.promoter_name')->orWhereNotNull('po.promoter_code');
            })
            ->selectRaw('
                COALESCE(po.promoter_name, po.promoter_code) as norm_key,
                COALESCE(po.promoter_name, po.promoter_code, "Sin nombre") as gestor,
                COALESCE(po.promoter_code, "") as codigo,
                b.name as sucursal,
                po.route_name as ruta,
                SUM(po.balance) as cartera,
                SUM(CASE WHEN po.days_past_due > 0 THEN COALESCE(po.capital_due, po.past_due_balance, po.balance) ELSE 0 END) as vencida
            ')
            ->groupBy('po.promoter_name', 'po.promoter_code', 'b.name', 'po.route_name')
            ->orderByDesc('cartera')
            ->limit(100)
            ->get()
            ->keyBy('norm_key');

        $allKeys = collect($placementRows->keys())
            ->merge($portfolioRows->keys())
            ->filter()
            ->unique()
            ->values();

        return $allKeys->map(function (string $key) use ($placementRows, $portfolioRows) {
            $p = $placementRows->get($key);
            $po = $portfolioRows->get($key);

            $nombre   = $p?->gestor ?? $po?->gestor ?? $key;
            $codigo   = ($p?->codigo ?: null) ?? ($po?->codigo ?: null);
            $sucursal = $p?->sucursal ?? $po?->sucursal ?? '—';
            $ruta     = $po?->ruta ?? null;

            $cartera = (float)($po?->cartera ?? 0);
            $vencida = (float)($po?->vencida ?? 0);

            return [
                'gestor'      => $nombre,
                'codigo'      => $codigo,
                'sucursal'    => $sucursal,
                'ruta'        => $ruta,
                'operaciones' => (int)($p?->operaciones ?? 0),
                'colocacion'  => (float)($p?->colocacion ?? 0),
                'cartera'     => $cartera,
                'mora'        => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0,
            ];
        })
        ->sortByDesc('colocacion')
        ->values()
        ->all();
    }

    // ── PORTFOLIO BUCKETS ────────────────────────────────────────────────────

    private function buildPortfolioBuckets(Period $period): array
    {
        $defs = [
            ['label' => 'Al corriente',  'min' => 0,   'max' => 0     ],
            ['label' => 'Mora 1-30',     'min' => 1,   'max' => 30    ],
            ['label' => 'Mora 31-60',    'min' => 31,  'max' => 60    ],
            ['label' => 'Mora 61-90',    'min' => 61,  'max' => 90    ],
            ['label' => 'Mora 91-120',   'min' => 91,  'max' => 120   ],
            ['label' => 'Mora 121-180',  'min' => 121, 'max' => 180   ],
            ['label' => 'Mora 180+',     'min' => 181, 'max' => 99999 ],
        ];

        $results = [];
        foreach ($defs as $d) {
            $rows = DB::table('fact_portfolios')
                ->whereIn('period_id', $this->dataIds)
                ->where('days_past_due', '>=', $d['min'])
                ->where('days_past_due', '<=', $d['max'])
                ->selectRaw('
                    COUNT(*) as contratos,
                    SUM(balance) as balance,
                    SUM(past_due_balance) as past_due,
                    SUM(COALESCE(capital_due, 0)) as capital_due_sum
                ')
                ->first();

            $contratos = (int)($rows?->contratos ?? 0);
            if ($contratos === 0) {
                continue;
            }

            $balance    = (float)($rows?->balance ?? 0);
            $pastDue    = (float)($rows?->past_due ?? 0);
            $capitalDue = (float)($rows?->capital_due_sum ?? 0);

            $vencida = $capitalDue > 0 ? $capitalDue : $pastDue;
            if ($vencida === 0.0 && $d['min'] > 0 && $balance > 0) {
                $vencida = $balance;
            }

            $results[] = [
                'label'     => $d['label'],
                'contratos' => $contratos,
                'balance'   => $balance,
                'vencida'   => $vencida,
            ];
        }

        return $results;
    }

    // ── EXPENSES DETAIL ──────────────────────────────────────────────────────

    private function buildExpensesDetail(Period $period): array
    {
        $amtExpr = 'COALESCE(NULLIF(e.paid_amount, 0), e.amount)';

        $total = (float) DB::table('fact_expenses as e')
            ->whereIn('e.period_id', $this->dataIds)
            ->selectRaw("SUM($amtExpr) as t")
            ->value('t');

        $byCategory = DB::table('fact_expenses as e')
            ->whereIn('e.period_id', $this->dataIds)
            ->selectRaw("COALESCE(e.category,'Sin categoría') as categoria, COUNT(*) as cnt, SUM($amtExpr) as total")
            ->groupBy('e.category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['categoria' => $r->categoria, 'count' => (int)$r->cnt, 'total' => (float)$r->total])
            ->values()->all();

        $byConcept = DB::table('fact_expenses as e')
            ->whereIn('e.period_id', $this->dataIds)
            ->selectRaw("COALESCE(e.concept, e.category,'Sin concepto') as concepto, COUNT(*) as cnt, SUM($amtExpr) as total")
            ->groupBy('e.concept', 'e.category')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(fn ($r) => ['concepto' => $r->concepto, 'count' => (int)$r->cnt, 'total' => (float)$r->total])
            ->values()->all();

        $byBranch = DB::table('fact_expenses as e')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->selectRaw("COALESCE(b.name,'Sin sucursal') as sucursal, COUNT(*) as cnt, SUM($amtExpr) as total")
            ->groupBy('e.branch_id', 'b.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['sucursal' => $r->sucursal, 'count' => (int)$r->cnt, 'total' => (float)$r->total])
            ->values()->all();

        $byEmployee = DB::table('fact_expenses as e')
            ->join('employees as emp', 'e.employee_id', '=', 'emp.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->whereNotNull('e.employee_id')
            ->selectRaw("emp.full_name as empleado, COALESCE(b.name,'Sin sucursal') as sucursal, SUM($amtExpr) as total")
            ->groupBy('e.employee_id', 'emp.full_name', 'b.name')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(fn ($r) => ['empleado' => $r->empleado, 'sucursal' => $r->sucursal, 'total' => (float)$r->total])
            ->values()->all();

        $bySource = DB::table('fact_expenses as e')
            ->leftJoin('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->selectRaw("COALESCE(ds.code,'Desconocida') as fuente, COUNT(*) as cnt, SUM($amtExpr) as total")
            ->groupBy('e.report_upload_id', 'ds.id', 'ds.code')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['fuente' => $r->fuente, 'count' => (int)$r->cnt, 'total' => (float)$r->total])
            ->values()->all();

        return compact('total', 'byCategory', 'byConcept', 'byBranch', 'byEmployee', 'bySource');
    }

    // ── EMPLOYEES + GESTORES ─────────────────────────────────────────────────

    private function buildEmployeesGestores(Period $period): array
    {
        $payroll = [];
        $mesRows = MonthlyEmployeeSummary::query()
            ->with(['employee:id,full_name,normalized_name', 'branch:id,name'])
            ->where('period_id', $period->id)
            ->get();

        if ($mesRows->isNotEmpty()) {
            foreach ($mesRows as $mes) {
                $norm = $mes->employee?->normalized_name
                    ?? $this->normalizeHumanName($mes->employee?->full_name ?? '');
                if (!$norm) continue;
                $payroll[$norm] = [
                    'name'       => $mes->employee?->full_name ?? 'Sin empleado',
                    'code'       => null,
                    'branch_src' => $mes->branch?->name ?? null,
                    'pagos'      => (float)$mes->total_payments,
                    'bonos'      => (float)$mes->total_bonuses,
                    'descuentos' => (float)$mes->total_discounts,
                    'gastos'     => (float)$mes->total_expenses,
                    'neto'       => (float)$mes->net_amount,
                ];
            }
        } else {
            $noiRows = DB::table('fact_noi_movements as n')
                ->join('employees as e', 'n.employee_id', '=', 'e.id')
                ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period) {
                    $j->on('eba.employee_id', '=', 'n.employee_id')
                      ->where('eba.period_id', '=', $period->id);
                })
                ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
                ->whereIn('n.period_id', $this->dataIds)
                ->whereNotNull('n.employee_id')
                ->selectRaw("
                    e.normalized_name as norm_key, e.full_name as name, b.name as branch,
                    SUM(CASE WHEN LOWER(COALESCE(n.concept_type,''))='percepcion' AND LOWER(COALESCE(n.concept,'')) NOT LIKE '%bono%' THEN n.amount ELSE 0 END) as pagos,
                    SUM(CASE WHEN LOWER(COALESCE(n.concept_type,''))='percepcion' AND LOWER(COALESCE(n.concept,'')) LIKE '%bono%' THEN n.amount ELSE 0 END) as bonos,
                    SUM(CASE WHEN LOWER(COALESCE(n.concept_type,'')) IN ('deduccion','descuento') THEN n.amount ELSE 0 END) as descuentos
                ")
                ->groupBy('e.normalized_name', 'e.full_name', 'b.name')
                ->get();

            foreach ($noiRows as $row) {
                $norm = $row->norm_key ?? $this->normalizeHumanName($row->name ?? '');
                if (!$norm) continue;
                $neto = (float)$row->pagos + (float)$row->bonos - (float)$row->descuentos;
                $payroll[$norm] = [
                    'name'       => $row->name,
                    'code'       => null,
                    'branch_src' => $row->branch ?? null,
                    'pagos'      => (float)$row->pagos,
                    'bonos'      => (float)$row->bonos,
                    'descuentos' => (float)$row->descuentos,
                    'gastos'     => 0.0,
                    'neto'       => $neto,
                ];
            }
        }

        $gestorPlacements = DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $this->dataIds)
            ->where(fn ($q) => $q->whereNotNull('p.promoter_name')->orWhereNotNull('p.promoter_code'))
            ->selectRaw("
                p.normalized_promoter_name as norm_key,
                COALESCE(p.promoter_name, p.promoter_code,'Sin nombre') as gestor,
                COALESCE(p.promoter_code,'') as codigo,
                b.name as sucursal,
                COUNT(*) as operaciones,
                SUM(p.amount) as colocacion
            ")
            ->groupBy('p.normalized_promoter_name', 'p.promoter_name', 'p.promoter_code', 'b.name')
            ->get()
            ->keyBy('norm_key');

        $portfolioByNorm = [];
        $poRows = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->where(fn ($q) => $q->whereNotNull('po.promoter_name')->orWhereNotNull('po.promoter_code'))
            ->selectRaw("
                COALESCE(po.promoter_name, po.promoter_code) as raw_name,
                b.name as sucursal, po.route_name as ruta,
                SUM(po.balance) as cartera,
                SUM(CASE WHEN po.days_past_due>0 THEN COALESCE(po.capital_due,po.past_due_balance,po.balance) ELSE 0 END) as vencida
            ")
            ->groupBy('po.promoter_name', 'po.promoter_code', 'b.name', 'po.route_name')
            ->get();

        foreach ($poRows as $po) {
            $norm = $this->normalizeHumanName($po->raw_name ?? '');
            if (!$norm) continue;
            if (!isset($portfolioByNorm[$norm])) {
                $portfolioByNorm[$norm] = ['cartera' => 0.0, 'vencida' => 0.0, 'sucursal' => null, 'ruta' => null];
            }
            $portfolioByNorm[$norm]['cartera'] += (float)$po->cartera;
            $portfolioByNorm[$norm]['vencida'] += (float)$po->vencida;
            $portfolioByNorm[$norm]['sucursal'] ??= $po->sucursal;
            $portfolioByNorm[$norm]['ruta']     ??= $po->ruta;
        }

        $recoveryByNorm = [];
        DB::table('fact_recoveries')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('promoter_name')
            ->selectRaw('promoter_name, SUM(total_amount) as recuperacion')
            ->groupBy('promoter_name')
            ->get()
            ->each(function ($r) use (&$recoveryByNorm) {
                $norm = $this->normalizeHumanName($r->promoter_name);
                if ($norm) {
                    $recoveryByNorm[$norm] = ($recoveryByNorm[$norm] ?? 0.0) + (float)$r->recuperacion;
                }
            });

        $expensesByNorm = [];
        DB::table('fact_expenses as e')
            ->join('employees as emp', 'e.employee_id', '=', 'emp.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->whereNotNull('e.employee_id')
            ->selectRaw('emp.normalized_name as norm_key, SUM(COALESCE(NULLIF(e.paid_amount,0),e.amount)) as gastos')
            ->groupBy('emp.normalized_name')
            ->get()
            ->each(function ($ex) use (&$expensesByNorm) {
                if ($ex->norm_key) {
                    $expensesByNorm[$ex->norm_key] = (float)$ex->gastos;
                }
            });

        $allKeys = collect(array_keys($payroll))
            ->merge($gestorPlacements->keys())
            ->merge(array_keys($portfolioByNorm))
            ->unique()
            ->filter(fn ($k) => $k !== '')
            ->values();

        return $allKeys->map(function (string $key) use ($payroll, $gestorPlacements, $portfolioByNorm, $recoveryByNorm, $expensesByNorm) {
            $emp    = $payroll[$key] ?? null;
            $ges    = $gestorPlacements->get($key);
            $po     = $portfolioByNorm[$key] ?? null;
            $rec    = $recoveryByNorm[$key] ?? 0.0;
            $gasEmp = $expensesByNorm[$key] ?? ($emp['gastos'] ?? 0.0);

            $name   = $emp['name'] ?? $ges?->gestor ?? $key;
            $code   = ($emp['code'] ?? null) ?: ($ges?->codigo ?: null);
            $branch = ($emp['branch_src'] ?? null) ?? ($ges?->sucursal ?? null) ?? ($po['sucursal'] ?? null);
            $route  = $po['ruta'] ?? null;

            $cartera = (float)($po['cartera'] ?? 0);
            $vencida = (float)($po['vencida'] ?? 0);

            return [
                'name'         => $name,
                'code'         => $code,
                'branch'       => $branch ?? 'Sin sucursal',
                'route'        => $route,
                'pagos'        => $emp['pagos'] ?? 0.0,
                'bonos'        => $emp['bonos'] ?? 0.0,
                'descuentos'   => $emp['descuentos'] ?? 0.0,
                'neto'         => $emp['neto'] ?? 0.0,
                'gastos'       => round($gasEmp, 2),
                'colocacion'   => (float)($ges?->colocacion ?? 0),
                'operaciones'  => (int)($ges?->operaciones ?? 0),
                'recuperacion' => round($rec, 2),
                'cartera'      => $cartera,
                'vencida'      => $vencida,
                'mora'         => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0.0,
            ];
        })->sortByDesc(fn ($r) => $r['colocacion'] + $r['pagos'])->values()->all();
    }

    // ── INCIDENTS ────────────────────────────────────────────────────────────

    private function buildIncidents(PeriodSummary $summary): array
    {
        if (!$summary->relationLoaded('incidents')) {
            $summary->load('incidents');
        }
        return $summary->incidents->map(fn ($i) => [
            'type'     => $i->type,
            'severity' => $i->severity,
            'message'  => $i->message,
        ])->values()->all();
    }

    // ── CHART DATA ───────────────────────────────────────────────────────────

    private function chartByBranch(Period $period, string $metric): array
    {
        if ($metric === 'recuperacion') {
            $rows = DB::table('fact_recoveries as r')
                ->join('branches as b', 'r.branch_id', '=', 'b.id')
                ->whereIn('r.period_id', $this->dataIds)
                ->selectRaw('b.name as label, SUM(r.total_amount) as value')
                ->groupBy('b.id', 'b.name')
                ->orderByDesc('value')
                ->limit(10)
                ->get();
        } else {
            $rows = DB::table('fact_portfolios as p')
                ->join('branches as b', 'p.branch_id', '=', 'b.id')
                ->whereIn('p.period_id', $this->dataIds)
                ->selectRaw('b.name as label, SUM(p.balance) as value')
                ->groupBy('b.id', 'b.name')
                ->orderByDesc('value')
                ->limit(10)
                ->get();
        }

        $max = $rows->max('value') ?: 1;
        return $rows->map(fn ($r) => [
            'label' => $r->label,
            'value' => (float)$r->value,
            'pct'   => min(100, round((float)$r->value / $max * 100, 1)),
        ])->values()->all();
    }

    private function chartPlacementByProduct(Period $period): array
    {
        $rows = DB::table('fact_placements')
            ->whereIn('period_id', $this->dataIds)
            ->whereRaw("(product_name NOT REGEXP ? OR product_name IS NULL)", [self::PRODUCT_GROUP_PATTERN])
            ->selectRaw('COALESCE(NULLIF(product_name, ""), "Sin producto") as label, SUM(amount) as value')
            ->groupBy('product_name')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        $max = $rows->max('value') ?: 1;
        return $rows->map(fn ($r) => [
            'label' => $r->label,
            'value' => (float)$r->value,
            'pct'   => min(100, round((float)$r->value / $max * 100, 1)),
        ])->values()->all();
    }

    private function chartMoraByBucket(Period $period): array
    {
        $buckets = $this->buildPortfolioBuckets($period);
        $max = collect($buckets)->max('vencida') ?: 1;
        return array_map(fn ($b) => [
            'label' => $b['label'],
            'value' => $b['vencida'],
            'pct'   => min(100, round($b['vencida'] / $max * 100, 1)),
        ], $buckets);
    }

    private function chartTopPromoters(Period $period): array
    {
        $rows = DB::table('fact_placements')
            ->whereIn('period_id', $this->dataIds)
            ->where(fn ($q) => $q->whereNotNull('promoter_name')->orWhereNotNull('promoter_code'))
            ->selectRaw('COALESCE(promoter_name, promoter_code, "Sin nombre") as label, SUM(amount) as value')
            ->groupBy('normalized_promoter_name', 'promoter_name', 'promoter_code')
            ->orderByDesc('value')
            ->limit(10)
            ->get();

        $max = $rows->max('value') ?: 1;
        return $rows->map(fn ($r) => [
            'label' => $r->label,
            'value' => (float)$r->value,
            'pct'   => min(100, round((float)$r->value / $max * 100, 1)),
        ])->values()->all();
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    private function resolveRealBranchNormalizedNames(): array
    {
        static $names = null;
        if ($names === null) {
            try {
                $resolver = app(BranchResolverService::class);
                $names = array_map(
                    fn ($n) => $this->normalizeText($n),
                    array_values($resolver->getCatalog())
                );
                // Always allow CORPORATIVO
                $names[] = 'corporativo';
                $names   = array_values(array_unique($names));
            } catch (\Throwable $e) {
                $names = [];
            }
        }
        return $names;
    }

    private function normalizeText(string $value): string
    {
        $value = trim(mb_strtolower($value));
        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );
    }

    private function normalizeHumanName(?string $value): string
    {
        $value = trim(mb_strtolower((string) $value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );
        $value = preg_replace('/[^a-z0-9\s]/', ' ', $value) ?? $value;
        return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    }

    private function resolveBranch(?int $id): ?Branch
    {
        if (!$id) return null;
        if (!array_key_exists($id, $this->branchCache)) {
            $this->branchCache[$id] = Branch::query()->find($id);
        }
        return $this->branchCache[$id];
    }
}
