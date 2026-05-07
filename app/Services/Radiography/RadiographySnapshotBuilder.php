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
use Illuminate\Support\Facades\DB;

/**
 * Builds a single comprehensive snapshot array from all DB tables.
 * Used as the single source of truth for Excel, PDF, web preview, and email.
 */
class RadiographySnapshotBuilder
{
    private array $branchCache = [];

    public function build(Period $period, PeriodSummary $summary): array
    {
        $this->branchCache = [];

        $gm      = $summary->global_metrics ?? [];

        // If global_metrics has zero overdue (old summary), recalculate with fallback
        if (($gm['cartera_vencida_total'] ?? 0) == 0 && ($gm['valor_cartera_total'] ?? 0) > 0) {
            $hasOverdueDays = \App\Models\Portfolio::query()->where('period_id', $period->id)->where('days_past_due', '>', 0)->exists();
            if ($hasOverdueDays) {
                $vencidaFallback = (float) \App\Models\Portfolio::query()->where('period_id', $period->id)->where('days_past_due', '>', 0)->sum('balance');
                $carteraTotal = (float) ($gm['valor_cartera_total'] ?? 0);
                $gm['cartera_vencida_total'] = $vencidaFallback;
                $gm['mora_porcentaje'] = $carteraTotal > 0 ? round($vencidaFallback / $carteraTotal * 100, 2) : 0;
            }
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
            'generated_at' => now()->format('d/m/Y H:i'),
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
                'payroll'          => $payroll,
                'products'         => $this->buildProducts($period),
                'branches'         => $this->buildBranches($period, $summary),
                'employees'        => $this->buildEmployees($period),
                'promoters'        => $this->buildPromoters($period),
                'portfolio_buckets' => $this->buildPortfolioBuckets($period),
                'expenses_detail'  => $this->buildExpensesDetail($period),
                'incidents'        => $this->buildIncidents($summary),
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

    // ── PAYROLL ─────────────────────────────────────────────────────────────

    private function buildPayroll(Period $period): array
    {
        // First try fact_monthly_employee_summary (post-consolidation)
        $mes = DB::table('fact_monthly_employee_summary')
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as cnt, SUM(total_payments) as pagos, SUM(total_bonuses) as bonos, SUM(total_discounts) as descuentos, SUM(total_expenses) as gastos, SUM(net_amount) as neto')
            ->first();

        $mesCount = (int)($mes?->cnt ?? 0);

        if ($mesCount > 0 && ((float)($mes?->pagos ?? 0) + (float)($mes?->bonos ?? 0)) > 0) {
            // Good data from consolidation
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

        // Fallback: aggregate directly from fact_noi_movements
        $empCount = (int) DB::table('fact_noi_movements')
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->distinct('employee_id')
            ->count('employee_id');

        // Percepciones (pagos): concept_type = 'percepcion' AND concept NOT LIKE '%bono%'
        $pagos = (float) DB::table('fact_noi_movements')
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->whereRaw("LOWER(COALESCE(concept,'')) NOT LIKE '%bono%'")
            ->sum('amount');

        $bonos = (float) DB::table('fact_noi_movements')
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->whereRaw("LOWER(COALESCE(concept,'')) LIKE '%bono%'")
            ->sum('amount');

        $descuentos = (float) DB::table('fact_noi_movements')
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) IN ('deduccion','descuento')")
            ->sum('amount');

        // If concept_type isn't classified at all, fallback to raw sum to at least show something
        if ($pagos === 0.0 && $bonos === 0.0 && $descuentos === 0.0) {
            $rawTotal = (float) DB::table('fact_noi_movements')
                ->where('period_id', $period->id)
                ->whereNotNull('employee_id')
                ->sum('amount');
            $pagos = $rawTotal;
        }

        // neto nómina = percepciones + bonos - deducciones (NOT subtracting operational expenses)
        $neto = $pagos + $bonos - $descuentos;

        $gastos = (float) DB::table('fact_expenses')
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->sum('amount');

        // If still no employees from NOI, count from branch assignments
        if ($empCount === 0) {
            $empCount = (int) DB::table('employee_branch_assignments')
                ->where('period_id', $period->id)
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
                'name'     => $r->employee?->full_name ?? 'Sin empleado',
                'branch'   => $r->branch?->name ?? '—',
                'pagos'    => (float)$r->total_payments,
                'bonos'    => (float)$r->total_bonuses,
                'descuentos' => (float)$r->total_discounts,
                'gastos'   => (float)$r->total_expenses,
                'neto'     => (float)$r->net_amount,
                'included' => (bool)$r->included_in_report,
            ])->values()->all();
        }

        // Fallback: build from NOI movements grouped by employee
        // Try to resolve branch from: 1) period assignments, 2) recovery/placement activity
        $rows = DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                  ->where('eba.period_id', '=', $period->id);
            })
            ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
            ->where('n.period_id', $period->id)
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

        // For employees with no branch yet, try to find branch from recovery/placement data
        $needsBranch = $rows->filter(fn ($r) => !$r->branch)->pluck('normalized_name')->values()->all();

        $activityBranches = [];
        if (!empty($needsBranch)) {
            $placements = DB::table('fact_placements as p')
                ->join('branches as b', 'p.branch_id', '=', 'b.id')
                ->where('p.period_id', $period->id)
                ->whereIn('p.normalized_promoter_name', $needsBranch)
                ->selectRaw('p.normalized_promoter_name, b.name as branch')
                ->distinct()
                ->get()
                ->groupBy('normalized_promoter_name')
                ->map(fn ($g) => $g->first()->branch);

            $recoveries = DB::table('fact_recoveries as r')
                ->join('branches as b', 'r.branch_id', '=', 'b.id')
                ->where('r.period_id', $period->id)
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

    private function buildProducts(Period $period): array
    {
        // Colocación agrupada por product_name, excluyendo reestructuras/seguros
        $placements = DB::table('fact_placements')
            ->where('period_id', $period->id)
            ->selectRaw('COALESCE(NULLIF(product_name, ""), "Sin producto") as producto, COUNT(*) as operaciones, SUM(amount) as colocacion')
            ->whereRaw('(product_name NOT REGEXP "REESTRUCTURA|UNIFICACION|SEGURO" OR product_name IS NULL)')
            ->groupBy('product_name')
            ->orderByDesc('colocacion')
            ->get()
            ->keyBy('producto');

        // Recuperación agrupada por product_name
        $recoveries = DB::table('fact_recoveries')
            ->where('period_id', $period->id)
            ->whereNotNull('product_name')
            ->where('product_name', '<>', '')
            ->whereRaw('product_name NOT REGEXP "REESTRUCTURA|UNIFICACION|SEGURO"')
            ->selectRaw('product_name as producto, SUM(total_amount) as recuperacion')
            ->groupBy('product_name')
            ->get()
            ->keyBy('producto');

        // Also aggregate from portfolios (normalized products per contract)
        $portfolioProducts = DB::table('fact_portfolios')
            ->where('period_id', $period->id)
            ->whereNotNull('product_name')
            ->where('product_name', '<>', '')
            ->whereRaw('product_name NOT REGEXP "REESTRUCTURA|UNIFICACION|SEGURO"')
            ->selectRaw('product_name as producto, COUNT(*) as contratos, SUM(balance) as cartera, SUM(CASE WHEN days_past_due > 0 THEN COALESCE(capital_due, past_due_balance, balance) ELSE 0 END) as vencida')
            ->groupBy('product_name')
            ->get()
            ->keyBy('producto');

        $allProducts = $placements->keys()
            ->merge($recoveries->keys())
            ->merge($portfolioProducts->keys())
            ->unique()
            ->values();

        if ($allProducts->isEmpty()) {
            return [];
        }

        $totalColocacion = $placements->sum('colocacion') ?: 1;

        return $allProducts->map(function (string $producto) use ($placements, $recoveries, $portfolioProducts, $totalColocacion) {
            $p = $placements->get($producto);
            $r = $recoveries->get($producto);
            $pp = $portfolioProducts->get($producto);
            $col = (float)($p?->colocacion ?? 0);
            return [
                'producto'     => $producto,
                'operaciones'  => (int)($p?->operaciones ?? 0),
                'colocacion'   => $col,
                'recuperacion' => (float)($r?->recuperacion ?? 0),
                'cartera'      => (float)($pp?->cartera ?? 0),
                'contratos'    => (int)($pp?->contratos ?? 0),
                'pct'          => round($col / $totalColocacion * 100, 1),
            ];
        })->sortByDesc('colocacion')->values()->all();
    }

    // ── BRANCHES ─────────────────────────────────────────────────────────────

    private function buildBranches(Period $period, PeriodSummary $summary): array
    {
        $fromSummary = $summary->branchSummaries->map(function (PeriodBranchSummary $bs) use ($period) {
            $branch  = $this->resolveBranch($bs->branch_id);
            $m       = $bs->metrics ?? [];
            $cartera = (float)($m['valor_cartera'] ?? 0);
            $vencida = (float)($m['cartera_vencida'] ?? 0);
            // Fallback: if past_due_balance was zero but days_past_due data exists, use overdue balance
            if ($vencida === 0.0 && $cartera > 0 && $bs->branch_id) {
                $vencida = (float) \App\Models\Portfolio::query()
                    ->where('period_id', $period->id)
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
        })->sortByDesc('cartera')->values()->all();

        if (!empty($fromSummary)) {
            return $fromSummary;
        }

        // Fallback: build from raw tables
        $branchIds = collect()
            ->merge(Recovery::query()->where('period_id', $period->id)->pluck('branch_id'))
            ->merge(Placement::query()->where('period_id', $period->id)->pluck('branch_id'))
            ->merge(Portfolio::query()->where('period_id', $period->id)->pluck('branch_id'))
            ->merge(Expense::query()->where('period_id', $period->id)->pluck('branch_id'))
            ->filter()->unique()->values();

        return $branchIds->map(function ($bId) use ($period) {
            $branch  = $this->resolveBranch($bId);
            $cartera = (float) Portfolio::query()->where('period_id', $period->id)->where('branch_id', $bId)->sum('balance');
            $vencida = (float) Portfolio::query()->where('period_id', $period->id)->where('branch_id', $bId)->sum('past_due_balance');
            return [
                'branch_id'    => $bId,
                'nombre'       => $branch?->name ?? "Sucursal #{$bId}",
                'recuperacion' => (float) Recovery::query()->where('period_id', $period->id)->where('branch_id', $bId)->sum('total_amount'),
                'colocacion'   => (float) Placement::query()->where('period_id', $period->id)->where('branch_id', $bId)->sum('amount'),
                'cartera'      => $cartera,
                'vencida'      => $vencida,
                'mora'         => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0,
                'gastos'       => (float) Expense::query()->where('period_id', $period->id)->where('branch_id', $bId)->sum('amount'),
            ];
        })->sortByDesc('cartera')->values()->all();
    }

    // ── PROMOTERS (from fact_placements + fact_portfolios) ──────────────────

    private function buildPromoters(Period $period): array
    {
        // Colocación por gestor (desde ministraciones)
        $placementRows = DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->where('p.period_id', $period->id)
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

        // Cartera por gestor (desde saldos — promoter_name tiene prioridad sobre promoter_code)
        $portfolioRows = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->where('po.period_id', $period->id)
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

        // Merge: colocación + cartera por gestor
        $allKeys = collect($placementRows->keys())
            ->merge($portfolioRows->keys())
            ->filter()
            ->unique()
            ->values();

        return $allKeys->map(function (string $key) use ($placementRows, $portfolioRows) {
            $p = $placementRows->get($key);
            $po = $portfolioRows->get($key);

            // Prefer the human name over code
            $nombre = $p?->gestor ?? $po?->gestor ?? $key;
            $codigo = ($p?->codigo ?: null) ?? ($po?->codigo ?: null);
            $sucursal = $p?->sucursal ?? $po?->sucursal ?? '—';
            $ruta = $po?->ruta ?? null;

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
            ['label' => 'Al corriente',  'min' => 0,   'max' => 0  ],
            ['label' => 'Mora 1-30',     'min' => 1,   'max' => 30 ],
            ['label' => 'Mora 31-60',    'min' => 31,  'max' => 60 ],
            ['label' => 'Mora 61-90',    'min' => 61,  'max' => 90 ],
            ['label' => 'Mora 91-120',   'min' => 91,  'max' => 120],
            ['label' => 'Mora 121-180',  'min' => 121, 'max' => 180],
            ['label' => 'Mora 180+',     'min' => 181, 'max' => 99999],
        ];

        $results = [];
        foreach ($defs as $d) {
            $rows = DB::table('fact_portfolios')
                ->where('period_id', $period->id)
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

            $balance  = (float)($rows?->balance ?? 0);
            $pastDue  = (float)($rows?->past_due ?? 0);
            $capitalDue = (float)($rows?->capital_due_sum ?? 0);

            // Vencida: prefer capital_due sum, then past_due_balance sum.
            // If both are zero but days_past_due > 0, use balance as proxy.
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
        return DB::table('fact_expenses as e')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->where('e.period_id', $period->id)
            ->selectRaw('COALESCE(e.category, "Sin categoría") as categoria, COALESCE(b.name, "Sin sucursal") as sucursal, COUNT(*) as cnt, SUM(e.amount) as total')
            ->groupBy('e.category', 'b.name')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(fn ($r) => [
                'categoria' => $r->categoria,
                'sucursal'  => $r->sucursal,
                'count'     => (int)$r->cnt,
                'total'     => (float)$r->total,
            ])->values()->all();
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
                ->where('r.period_id', $period->id)
                ->selectRaw('b.name as label, SUM(r.total_amount) as value')
                ->groupBy('b.id', 'b.name')
                ->orderByDesc('value')
                ->limit(10)
                ->get();
        } else {
            $rows = DB::table('fact_portfolios as p')
                ->join('branches as b', 'p.branch_id', '=', 'b.id')
                ->where('p.period_id', $period->id)
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
            ->where('period_id', $period->id)
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
            ->where('period_id', $period->id)
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
        if (!$id) {
            return null;
        }
        if (!array_key_exists($id, $this->branchCache)) {
            $this->branchCache[$id] = Branch::query()->find($id);
        }
        return $this->branchCache[$id];
    }
}
