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
use App\Services\EmployeeNameCanonicalizer;
use App\Support\OperationalExclusion;
use App\Support\RegionNorteFilter;
use Illuminate\Support\Facades\DB;

/**
 * Builds a single comprehensive snapshot array from all DB tables.
 * Used as the single source of truth for Excel, PDF, web preview, and email.
 */
class RadiographySnapshotBuilder
{
    private array $branchCache        = [];
    private array $dataIds            = [];
    private array $branchCalcGlobal   = [];
    private array $branchCalcBranches = [];

    public function __construct(
        private readonly EmployeeNameCanonicalizer $canonicalizer,
        private readonly BranchRadiographyCalculator $branchCalculator,
    ) {}

    public function build(Period $period, PeriodSummary $summary, array $config = []): array
    {
        $this->branchCache = [];
        $this->dataIds     = $this->resolveDataIds($period);
        $includedBranchIds = $config['included_branch_ids'] ?? [];

        $gm = $summary->global_metrics ?? [];

        // Cartera, mora (por bucket) y colocación se recalculan más abajo a partir de
        // BranchRadiographyCalculator::sumGlobal() — ÚNICA fuente para estas métricas
        // (cartera total, mora total, buckets, mora%, por sucursal, Excel, PDF, dashboard).
        // No se ejecutan queries propias aquí para evitar dos lógicas distintas.

        // Seguro CRECE/COMADRES — informativo, NO se resta de colocacion_total.
        $gm['seguro_crece_comadres_informativo'] = (float)(DB::table('fact_placements as p')
            ->whereIn('p.period_id', $this->dataIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(p.raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
            ->whereRaw("UPPER(COALESCE(p.product_name,'')) LIKE '%CRECE%' OR UPPER(COALESCE(p.product_name,'')) LIKE '%COMADRES%'")
            ->selectRaw("SUM(COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(p.raw_payload), '$.seguro')) AS DECIMAL(14,2)), 0)) as tot")
            ->value('tot') ?? 0);

        // Recuperación: suma total del archivo, excluye seguros + unificación + condonación.
        // Seguros (is_savehearts=1): aportan savehearts_crece_share (30% CRECE, 0 resto).
        // No-savehearts con COBERTURA/SEGURO/UNIFICACION/CONDONACION: aportan 0.
        if (($gm['recuperacion_total'] ?? 0) == 0) {
            $gm['recuperacion_total'] = (float) DB::table('fact_recoveries')
                ->whereIn('period_id', $this->dataIds)
                ->selectRaw("SUM(CASE
                    WHEN is_savehearts = 1 THEN COALESCE(savehearts_crece_share, 0)
                    WHEN is_savehearts = 0 AND (
                        UPPER(COALESCE(concept,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(operation,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(concept,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(operation,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(concept,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(operation,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(concept,'')) LIKE '%CONDONACION%'
                        OR UPPER(COALESCE(operation,'')) LIKE '%CONDONACION%'
                    ) THEN 0
                    ELSE total_amount
                END) as total")
                ->value('total') ?? 0;
        }

        // gasto_total is resolved AFTER branchCalcGlobal is built below (see override section)

        $payroll           = $this->buildPayroll($period);
        $empGestoresResult = $this->buildEmployeesGestores($period);
        $empGestores       = $empGestoresResult['rows'];
        $mergedIncidents   = $empGestoresResult['merged_incidents'];

        $interbranchLoans     = $this->buildInterbranchLoans($period);
        $mergedIncidents      = array_merge($mergedIncidents, $interbranchLoans['incidents'] ?? []);

        $payrollByBranchResult = $this->buildPayrollByBranchConceptResolved($period);
        $mergedIncidents       = array_merge($mergedIncidents, $payrollByBranchResult['incidents'] ?? []);

        // Reconcile payroll total: if buildPayroll returned 0 but employees_gestores has pagos, derive from it
        if (($payroll['pagos'] + $payroll['bonos']) == 0.0) {
            $egPagos = collect($empGestores)->sum('pagos');
            $egBonos = collect($empGestores)->sum('bonos');
            if (($egPagos + $egBonos) > 0) {
                $payroll['pagos']      = round($egPagos, 2);
                $payroll['bonos']      = round($egBonos, 2);
                $payroll['descuentos'] = round(collect($empGestores)->sum('descuentos'), 2);
                $payroll['neto']       = round(collect($empGestores)->sum('neto'), 2);
                if ($payroll['total_empleados'] === 0) {
                    $payroll['total_empleados'] = collect($empGestores)->filter(fn ($r) => ($r['pagos'] + $r['bonos']) > 0)->count();
                }
            }
        }

        // Build per-branch and GLOBAL data using BranchRadiographyCalculator.
        // Returns ['branches' => [...13 summaries...], 'unassigned' => [...]]
        // GLOBAL = suma de 13 sucursales + unassigned (solo nómina/comisiones/bonos/gastos).
        // Cartera / recuperación / colocación / mora ONLY from branches (not unassigned).
        $branchCalcResult   = $this->branchCalculator->buildBranches($period, $this->dataIds, $includedBranchIds);
        $branchCalcBranches = $branchCalcResult['branches'];
        $branchCalcUnassigned = $branchCalcResult['unassigned'];
        $branchCalcGlobal   = $this->branchCalculator->sumGlobal($branchCalcBranches, $branchCalcUnassigned);
        $this->branchCalcGlobal   = $branchCalcGlobal;   // fuente única (global) para cartera/mora
        $this->branchCalcBranches = $branchCalcBranches; // fuente única (por sucursal) para cartera/mora

        // Prefer BranchRadiographyCalculator totals for the primary summary metrics
        $calcRecuperacion= (float) $branchCalcGlobal['recuperacion_total'];
        $calcColocacion  = (float) $branchCalcGlobal['colocacion'];

        // Valor cartera / Cartera vencida: fuente "Saldos Por Cliente" (Saldo actual), único
        // filtro = excluir Aguascalientes/AGS. Incluye TODAS las sucursales/rutas, no solo las
        // 13 oficiales — distinto del resto de KPIs (cartera/colocación/recuperación/mora por
        // sucursal), que sí están limitados a las 13 sucursales operativas.
        $carteraGlobalSinFiltro = $this->branchCalculator->computeCarteraGlobalSinFiltro($this->dataIds);
        $calcCartera   = (float) $carteraGlobalSinFiltro['valor_cartera'];
        $calcMoraTotal = (float) $carteraGlobalSinFiltro['cartera_vencida'];

        if ($calcCartera > 0) {
            $gm['valor_cartera_total']   = $calcCartera;
            $gm['cartera_vencida_total'] = $calcMoraTotal;
            $gm['mora_porcentaje']       = $calcCartera > 0 ? round($calcMoraTotal / $calcCartera * 100, 2) : 0;
        }
        if ($calcRecuperacion > 0) {
            $gm['recuperacion_total'] = $calcRecuperacion;
        }
        if ($calcColocacion > 0) {
            $gm['colocacion_total'] = $calcColocacion;
        }

        // Gastos: authoritative source is BranchRadiographyCalculator.
        // gastos_operativos = gastos_erp_total + gastos_lendus_total (Pólizas Lendus excluded — in seguros_puente).
        $calcGastosOp = (float) ($branchCalcGlobal['gastos_operativos'] ?? 0);
        if ($calcGastosOp > 0 || ($gm['gasto_total'] ?? 0) == 0) {
            $gm['gasto_total']        = $calcGastosOp;
            $gm['gasto_erp']          = (float) ($branchCalcGlobal['gastos_erp_total'] ?? 0);
            $gm['gasto_lendus']       = (float) ($branchCalcGlobal['gastos_lendus_total'] ?? 0);
            $gm['seguros_lendus_puente'] = (float) ($branchCalcGlobal['seguros_lendus_puente'] ?? 0);
            $gm['excedentes_total']   = (float) ($branchCalcGlobal['excedentes'] ?? 0);
            $gm['fondeo_total']       = (float) ($branchCalcGlobal['prestamos_fondea'] ?? 0);
        }

        // ── EBITDA, Venta y Margen EBITDA ────────────────────────────────────
        $saldoInicialParaEbitda = (float) ($period->saldo_inicial_caja ?? 0);
        $nominaDetalleSuma      = array_sum(array_values((array) ($branchCalcGlobal['nomina_detalle'] ?? [])));
        $nominaParaEbitda       = (float) ($branchCalcGlobal['nomina_total'] ?? 0)
                                + (float) ($branchCalcGlobal['comisiones'] ?? 0)
                                + (float) ($branchCalcGlobal['bonos'] ?? 0)
                                + (float) ($branchCalcGlobal['vacaciones'] ?? 0)
                                + (float) ($branchCalcGlobal['prima_vacacional'] ?? 0)
                                + $nominaDetalleSuma;
        $opexParaEbitda         = (float) ($branchCalcGlobal['gastos_operativos'] ?? 0);
        $totalGastosEbitda      = $opexParaEbitda + $nominaParaEbitda;
        $ebitdaGlobal           = $saldoInicialParaEbitda + $calcRecuperacion + $calcColocacion - $totalGastosEbitda;
        $capitalRecuperado      = (float) ($branchCalcGlobal['capital_recuperado'] ?? 0);
        $impuestoRecuperado     = (float) ($branchCalcGlobal['impuesto_recuperado'] ?? 0);
        $ventaGlobal            = max(0.0, $calcRecuperacion - $capitalRecuperado - $impuestoRecuperado);
        $margenEbitda           = $ventaGlobal > 0 ? round($ebitdaGlobal / $ventaGlobal * 100, 2) : 0.0;

        return [
            'period' => [
                'id'         => $period->id,
                'label'      => $period->label,
                'code'       => $period->code,
                'start_date' => optional($period->start_date)->format('d/m/Y'),
                'end_date'   => optional($period->end_date)->format('d/m/Y'),
            ],
            'saldo_inicial_caja' => (float) ($period->saldo_inicial_caja ?? 0),
            'saldo_final_caja'   => ($period->saldo_final_caja !== null) ? (float) $period->saldo_final_caja : null,
            'generated_at' => now('America/Mexico_City')->format('d/m/Y H:i'),
            'version'      => $summary->version ?? 1,
            'branch_radiography' => [
                'branches'   => $branchCalcBranches,
                'global'     => $branchCalcGlobal,
                'unassigned' => $branchCalcUnassigned,
            ],
            'summary' => [
                'employees_count'              => $payroll['total_empleados'],
                'recovery_total'               => (float)($gm['recuperacion_total'] ?? 0),
                'recovery_bruta'               => (float)($branchCalcGlobal['recuperacion_bruta'] ?? 0),
                'recovery_seguro_excluido'     => (float)($branchCalcGlobal['seguro_excluido_bruto'] ?? 0),
                'recovery_savehearts_bruto'    => (float)($branchCalcGlobal['seguro_savehearts_bruto'] ?? 0),
                'recovery_comadres_bruto'      => (float)($branchCalcGlobal['seguro_comadres_bruto'] ?? 0),
                'recovery_crece_bruto'         => (float)($branchCalcGlobal['seguro_crece_bruto'] ?? 0),
                'recovery_crece_reconocido'    => (float)($branchCalcGlobal['seguro_crece_reconocido'] ?? 0),
                'recovery_crece_no_reconocido' => max(0.0, (float)($branchCalcGlobal['seguro_crece_bruto'] ?? 0) - (float)($branchCalcGlobal['seguro_crece_reconocido'] ?? 0)),
                'placement_total'              => (float)($gm['colocacion_total'] ?? 0),
                'portfolio_total'              => (float)($gm['valor_cartera_total'] ?? 0),
                'overdue_portfolio'            => (float)($gm['cartera_vencida_total'] ?? 0),
                'mora_index'                   => (float)($gm['mora_porcentaje'] ?? 0),
                'expenses_total'               => (float)($gm['gasto_total'] ?? 0),
                'expenses_erp'                 => (float)($gm['gasto_erp'] ?? 0),
                'expenses_lendus'              => (float)($gm['gasto_lendus'] ?? 0),
                'seguros_lendus_puente'        => (float)($gm['seguros_lendus_puente'] ?? 0),
                'excedentes_total'             => (float)($gm['excedentes_total'] ?? 0),
                'fondeo_total'                 => (float)($gm['fondeo_total'] ?? 0),
                'payroll_total'                => $payroll['pagos'] + $payroll['bonos'],
                'net_payroll'                  => $payroll['neto'],
                'opex_total'                   => $opexParaEbitda,
                'nomina_ebitda_total'          => $nominaParaEbitda,
                'total_gastos_ebitda'          => $totalGastosEbitda,
                'ebitda_global'                => $ebitdaGlobal,
                'venta_global'                 => $ventaGlobal,
                'margen_ebitda'                => $margenEbitda,
                'ebitda_categoria'             => $this->ebitdaCategory($ebitdaGlobal),
                'unificacion_excluida'         => (float)($branchCalcGlobal['unificacion_excluida'] ?? 0),
                'condonacion_excluida'         => (float)($branchCalcGlobal['condonacion_excluida'] ?? 0),
            ],
            'sections' => [
                'payroll'                    => $payroll,
                'products'                   => $this->buildProducts($period),
                'branches'                   => $this->buildBranches($period, $summary),
                'employees'                  => $this->buildEmployees($period),
                'promoters'                  => $this->buildPromoters($period),
                'employees_gestores'         => $empGestores,
                'portfolio_buckets'          => $this->buildPortfolioBuckets($period),
                'expenses_detail'            => $this->buildExpensesDetail($period),
                'incidents'                  => $this->buildIncidents($summary, $period, $mergedIncidents),
                'payroll_by_branch_concept'  => $payrollByBranchResult['data'],
                'expenses_matrix'            => $this->buildExpensesMatrix($period),
                'interbranch_loans'          => $interbranchLoans,
                'recovery_detail'            => $this->buildRecoveryDetail($period),
                'portfolio_by_branch_product' => $this->buildPortfolioByBranchProduct($period),
                'mora_by_branch'             => $this->buildMoraByBranch($period),
                'mora_by_product'            => $this->buildMoraByProduct($period),
                'mora_by_gestor'             => $this->buildMoraByGestor($period),
                'mora_by_branch_product'     => $this->buildMoraByBranchProduct($period),
                'corporate_funding'          => $this->buildCorporateFunding($period),
                'fondeo_detalle'             => $this->buildFondeoDetalle(),
                'placement_by_branch_product'=> $this->buildPlacementByBranchProduct($period),
                'rotation'                   => $this->buildRotationData($period),
                'active_loans'               => $this->buildActiveLoans($period),
                'efectividad_cobranza'       => $this->buildEfectividadCobranza($period),
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

    public function resolveDataIdsPublic(Period $period): array
    {
        return $this->resolveDataIds($period);
    }

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
                ->groupBy(fn ($r) => $this->canonicalizer->normalize($r->promoter_name))
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

    private const PRODUCT_SPECIAL_PATTERN    = 'A LA MEDIDA|DIARIO|CREDITO CONSUMO';
    private const PRODUCT_RESTRUCTURE_PATTERN = 'REESTRUCTURA|UNIFICACION|MIGRACION|INSOLUTOS';
    // Excludes multi-option grouped product names like "S12 / S16" or "I20 / I30"
    private const PRODUCT_GROUP_PATTERN = '[Ss][0-9]+\\s*/\\s*[Ss][0-9]+|[Ii][0-9]+\\s*/\\s*[Ii][0-9]+';

    private function buildProducts(Period $period): array
    {
        $excludeAll = self::PRODUCT_SPECIAL_PATTERN . '|' . self::PRODUCT_RESTRUCTURE_PATTERN . '|SEGURO|RECURSOS PROPIOS';

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

        $realBranchNamesForProducts = $this->resolveRealBranchNormalizedNames();
        $vencidaExpr = "SUM(CASE WHEN days_past_due > 0 THEN
            COALESCE(capital_due, 0) + COALESCE(interes_atrasado, 0) + COALESCE(impuesto_atrasado, 0)
            + COALESCE(saldo_interes_moratorio, 0) + COALESCE(saldo_impuesto_interes_moratorio, 0)
        ELSE 0 END) as vencida";

        $portfolioMain = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->whereNotNull('po.product_name')
            ->where('po.product_name', '<>', '')
            ->whereRaw("po.product_name NOT REGEXP ?", [$excludeAll])
            ->whereRaw("po.product_name NOT REGEXP ?", [self::PRODUCT_GROUP_PATTERN])
            ->when(!empty($realBranchNamesForProducts), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realBranchNamesForProducts))
            ->selectRaw("po.product_name as producto, COUNT(*) as contratos, SUM(po.balance) as cartera, {$vencidaExpr}")
            ->groupBy('po.product_name')
            ->get()
            ->keyBy('producto');

        $portfolioOtros = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->whereNotNull('po.product_name')
            ->where('po.product_name', '<>', '')
            ->whereRaw("po.product_name REGEXP ? AND po.product_name NOT REGEXP ?", [self::PRODUCT_SPECIAL_PATTERN, self::PRODUCT_RESTRUCTURE_PATTERN])
            ->when(!empty($realBranchNamesForProducts), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realBranchNamesForProducts))
            ->selectRaw("po.product_name as producto, COUNT(*) as contratos, SUM(po.balance) as cartera, {$vencidaExpr}")
            ->groupBy('po.product_name')
            ->orderByDesc('cartera')
            ->get();

        $portfolioReestructuras = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->whereNotNull('po.product_name')
            ->where('po.product_name', '<>', '')
            ->whereRaw("po.product_name REGEXP ?", [self::PRODUCT_RESTRUCTURE_PATTERN])
            ->when(!empty($realBranchNamesForProducts), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realBranchNamesForProducts))
            ->selectRaw("po.product_name as producto, COUNT(*) as contratos, SUM(po.balance) as cartera, {$vencidaExpr}")
            ->groupBy('po.product_name')
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
        $realBranchNames  = $this->resolveRealBranchNormalizedNames();
        $recoveryByBranch = $this->getRecoveryByRealBranch();

        $fromSummary = $summary->branchSummaries->map(function (PeriodBranchSummary $bs) use ($period, $realBranchNames, $recoveryByBranch) {
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
                    ->sum('past_due_balance');
            }
            $mora   = $cartera > 0 ? round($vencida / $cartera * 100, 2) : (float)($m['mora_porcentaje'] ?? 0);
            $nombre = $branch?->name ?? "Sucursal #{$bs->branch_id}";
            // Real recovery: map all route-level recoveries to this real branch
            $recuperacion = $recoveryByBranch[$this->normalizeText($nombre)]
                ?? (float)($m['recuperacion_total'] ?? 0);

            return [
                'branch_id'    => $bs->branch_id,
                'nombre'       => $nombre,
                'recuperacion' => $recuperacion,
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
            ->merge(Placement::query()->whereIn('period_id', $this->dataIds)->pluck('branch_id'))
            ->merge(Portfolio::query()->whereIn('period_id', $this->dataIds)->pluck('branch_id'))
            ->merge(Expense::query()->whereIn('period_id', $this->dataIds)->pluck('branch_id'))
            ->filter()->unique()->values();

        return $branchIds->map(function ($bId) use ($realBranchNames, $recoveryByBranch) {
            $branch  = $this->resolveBranch($bId);

            // Skip routes/offices
            if (!empty($realBranchNames) && $branch && !in_array($this->normalizeText($branch->name), $realBranchNames, true)) {
                return null;
            }

            $nombre  = $branch?->name ?? "Sucursal #{$bId}";
            $cartera = (float) Portfolio::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->sum('balance');
            $vencidaPdb = (float) Portfolio::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->where('days_past_due', '>', 0)->sum('past_due_balance');
            $vencida = $vencidaPdb > 0 ? $vencidaPdb : (float) Portfolio::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->where('days_past_due', '>', 0)->sum('balance');
            // Use mapped recovery; fall back to direct branch query only if not found in map
            $recuperacion = $recoveryByBranch[$this->normalizeText($nombre)]
                ?? (float) Recovery::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->sum('total_amount');

            return [
                'branch_id'    => $bId,
                'nombre'       => $nombre,
                'recuperacion' => $recuperacion,
                'colocacion'   => (float) Placement::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->sum('amount'),
                'cartera'      => $cartera,
                'vencida'      => $vencida,
                'mora'         => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0,
                'gastos'       => (float) Expense::query()->whereIn('period_id', $this->dataIds)->where('branch_id', $bId)->sum('amount'),
            ];
        })->filter()->sortByDesc('cartera')->values()->all();
    }

    /**
     * Aggregates fact_recoveries by real branch, mapping route/office branch names
     * to their parent real sucursal via BranchResolverService::resolveRealBranchFromRoute().
     * Returns [normalizedRealBranchName => totalRecovery].
     */
    private function getRecoveryByRealBranch(): array
    {
        $resolver = app(BranchResolverService::class);

        $rows = DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $this->dataIds)
            ->selectRaw('b.name as branch_name, SUM(r.total_amount) as total')
            ->groupBy('r.branch_id', 'b.name')
            ->get();

        $byRealBranch = [];
        foreach ($rows as $r) {
            // Skip routes that can't be mapped to a real branch — never expose route names
            $real = $resolver->resolveRealBranchFromRoute($r->branch_name);
            if (!$real) {
                continue;
            }
            $key = $this->normalizeText($real);
            $byRealBranch[$key] = ($byRealBranch[$key] ?? 0.0) + (float) $r->total;
        }

        return $byRealBranch;
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
                SUM(CASE WHEN po.days_past_due > 0 THEN
                    COALESCE(po.capital_due, 0) + COALESCE(po.interes_atrasado, 0) + COALESCE(po.impuesto_atrasado, 0)
                    + COALESCE(po.saldo_interes_moratorio, 0) + COALESCE(po.saldo_impuesto_interes_moratorio, 0)
                ELSE 0 END) as vencida
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

    // Fuente ÚNICA de cartera/mora: deriva de BranchRadiographyCalculator::sumGlobal()
    // (mismo cálculo que alimenta cartera total, mora total, mora%, por sucursal, Excel y PDF).
    // No ejecuta una query propia — evita que buckets y totales salgan de filtros distintos.
    private function buildPortfolioBuckets(Period $period): array
    {
        $g = $this->branchCalcGlobal;
        if (empty($g)) {
            return [];
        }

        $defs = [
            ['key' => 'mora_0_30',     'label' => 'Mora 1-30'],
            ['key' => 'mora_31_60',    'label' => 'Mora 31-60'],
            ['key' => 'mora_61_90',    'label' => 'Mora 61-90'],
            ['key' => 'mora_91_120',   'label' => 'Mora 91-120'],
            ['key' => 'mora_120_plus', 'label' => 'Mora 120+'],
        ];

        $carteraTotal = (float) ($g['valor_cartera'] ?? 0);
        $moraTotal    = 0.0;
        $cntMoraTotal = 0;
        $results      = [];

        foreach ($defs as $d) {
            $balance = (float) ($g[$d['key']] ?? 0);
            $cnt     = (int) ($g["{$d['key']}_cnt"] ?? 0);
            $moraTotal    += $balance;
            $cntMoraTotal += $cnt;
            if ($cnt === 0) {
                continue;
            }
            $results[] = [
                'label'     => $d['label'],
                'contratos' => $cnt,
                'balance'   => $balance,
                'vencida'   => $balance, // el contrato completo (Saldo actual) se bucketiza según días vencido
            ];
        }

        $cntAlCorriente = (int) ($g['contratos'] ?? 0) - $cntMoraTotal;
        $balAlCorriente = $carteraTotal - $moraTotal;
        if ($cntAlCorriente > 0) {
            array_unshift($results, [
                'label'     => 'Al corriente',
                'contratos' => $cntAlCorriente,
                'balance'   => $balAlCorriente,
                'vencida'   => 0.0,
            ]);
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
            ->whereIn('e.period_id', $this->dataIds)
            ->whereNotNull('e.employee_id')
            ->selectRaw("emp.full_name as empleado, SUM($amtExpr) as total")
            ->groupBy('e.employee_id', 'emp.full_name')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(fn ($r) => ['empleado' => $r->empleado, 'total' => (float)$r->total])
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
        $rawNameByNorm = []; // UPPERCASE display name per normalized lowercase key

        $payroll = [];
        $mesRows = MonthlyEmployeeSummary::query()
            ->with(['employee:id,full_name,normalized_name', 'branch:id,name'])
            ->where('period_id', $period->id)
            ->get();

        $mesHasPayments = $mesRows->isNotEmpty()
            && ($mesRows->sum('total_payments') + $mesRows->sum('total_bonuses')) > 0;

        if ($mesHasPayments) {
            foreach ($mesRows as $mes) {
                $norm = $this->canonicalizer->normalize($mes->employee?->full_name ?? '');
                if (!$norm) continue;
                $rawNameByNorm[$norm] = $mes->employee?->full_name ?? '';
                if (isset($payroll[$norm])) {
                    // Same employee in both fiscal + non-fiscal: accumulate, don't overwrite
                    $payroll[$norm]['pagos']      += (float)$mes->total_payments;
                    $payroll[$norm]['bonos']      += (float)$mes->total_bonuses;
                    $payroll[$norm]['descuentos'] += (float)$mes->total_discounts;
                    $payroll[$norm]['gastos']     += (float)$mes->total_expenses;
                    $payroll[$norm]['neto']       += (float)$mes->net_amount;
                } else {
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
                    SUM(CASE WHEN LOWER(COALESCE(n.concept_type,''))='percepcion' AND LOWER(COALESCE(n.concept,'')) NOT LIKE '%bono%' THEN n.amount
                             WHEN LOWER(COALESCE(n.concept,'')) LIKE '%comisi%' THEN n.amount
                             ELSE 0 END) as pagos,
                    SUM(CASE WHEN LOWER(COALESCE(n.concept_type,''))='percepcion' AND LOWER(COALESCE(n.concept,'')) LIKE '%bono%' THEN n.amount ELSE 0 END) as bonos,
                    SUM(CASE WHEN LOWER(COALESCE(n.concept_type,'')) IN ('deduccion','descuento') THEN n.amount ELSE 0 END) as descuentos
                ")
                ->groupBy('e.normalized_name', 'e.full_name', 'b.name')
                ->get();

            foreach ($noiRows as $row) {
                $norm = $this->canonicalizer->normalize($row->name ?? '');
                if (!$norm) continue;
                $rawNameByNorm[$norm] ??= $row->name ?? '';
                $neto = (float)$row->pagos + (float)$row->bonos - (float)$row->descuentos;
                if (isset($payroll[$norm])) {
                    $payroll[$norm]['pagos']      += (float)$row->pagos;
                    $payroll[$norm]['bonos']      += (float)$row->bonos;
                    $payroll[$norm]['descuentos'] += (float)$row->descuentos;
                    $payroll[$norm]['neto']       += $neto;
                } else {
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
        }

        $gestorPlacements = [];
        DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $this->dataIds)
            ->where(fn ($q) => $q->whereNotNull('p.promoter_name')->orWhereNotNull('p.promoter_code'))
            ->selectRaw("
                COALESCE(p.promoter_name, p.promoter_code,'Sin nombre') as gestor,
                COALESCE(p.promoter_code,'') as codigo,
                b.name as sucursal,
                COUNT(*) as operaciones,
                SUM(p.amount) as colocacion
            ")
            ->groupBy('p.promoter_name', 'p.promoter_code', 'b.name')
            ->get()
            ->each(function ($row) use (&$gestorPlacements, &$rawNameByNorm) {
                $norm = $this->canonicalizer->normalize($row->gestor ?? '');
                if (!$norm) return;
                $rawNameByNorm[$norm] ??= mb_strtoupper(trim($row->gestor ?? ''));
                if (!isset($gestorPlacements[$norm])) {
                    $gestorPlacements[$norm] = [
                        'gestor'      => $row->gestor,
                        'codigo'      => $row->codigo,
                        'sucursal'    => $row->sucursal,
                        'operaciones' => (int)$row->operaciones,
                        'colocacion'  => (float)$row->colocacion,
                    ];
                } else {
                    $gestorPlacements[$norm]['operaciones'] += (int)$row->operaciones;
                    $gestorPlacements[$norm]['colocacion']  += (float)$row->colocacion;
                    $gestorPlacements[$norm]['sucursal'] ??= $row->sucursal;
                }
            });

        $portfolioByNorm = [];
        $poRows = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->where(fn ($q) => $q->whereNotNull('po.promoter_name')->orWhereNotNull('po.promoter_code'))
            ->selectRaw("
                COALESCE(po.promoter_name, po.promoter_code) as raw_name,
                b.name as sucursal, po.route_name as ruta,
                SUM(po.balance) as cartera,
                SUM(CASE WHEN po.days_past_due > 0 THEN
                    COALESCE(po.capital_due, 0) + COALESCE(po.interes_atrasado, 0) + COALESCE(po.impuesto_atrasado, 0)
                    + COALESCE(po.saldo_interes_moratorio, 0) + COALESCE(po.saldo_impuesto_interes_moratorio, 0)
                ELSE 0 END) as vencida
            ")
            ->groupBy('po.promoter_name', 'po.promoter_code', 'b.name', 'po.route_name')
            ->get();

        foreach ($poRows as $po) {
            $norm = $this->canonicalizer->normalize($po->raw_name ?? '');
            if (!$norm) continue;
            $rawNameByNorm[$norm] ??= mb_strtoupper(trim($po->raw_name ?? ''));
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
            ->each(function ($r) use (&$recoveryByNorm, &$rawNameByNorm) {
                $norm = $this->canonicalizer->normalize($r->promoter_name);
                if ($norm) {
                    $rawNameByNorm[$norm] ??= mb_strtoupper(trim($r->promoter_name));
                    $recoveryByNorm[$norm] = ($recoveryByNorm[$norm] ?? 0.0) + (float)$r->recuperacion;
                }
            });

        $expensesByNorm = [];
        DB::table('fact_expenses as e')
            ->join('employees as emp', 'e.employee_id', '=', 'emp.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->whereNotNull('e.employee_id')
            ->selectRaw('emp.full_name as full_name, SUM(COALESCE(NULLIF(e.paid_amount,0),e.amount)) as gastos')
            ->groupBy('emp.id', 'emp.full_name')
            ->get()
            ->each(function ($ex) use (&$expensesByNorm, &$rawNameByNorm) {
                $norm = $this->canonicalizer->normalize($ex->full_name ?? '');
                if ($norm) {
                    $rawNameByNorm[$norm] ??= mb_strtoupper(trim($ex->full_name ?? ''));
                    $expensesByNorm[$norm] = ($expensesByNorm[$norm] ?? 0.0) + (float)$ex->gastos;
                }
            });

        // ── FUZZY MERGE: unify near-duplicate names across sources ───────────
        $allUniqueKeys = array_values(array_unique(array_merge(
            array_keys($payroll),
            array_keys($gestorPlacements),
            array_keys($portfolioByNorm),
            array_keys($recoveryByNorm),
            array_keys($expensesByNorm),
        )));

        $mergeMap        = $this->canonicalizer->buildCanonicalMap($allUniqueKeys, array_keys($payroll));
        $mergedIncidents = [];

        foreach ($mergeMap as $key => $canonical) {
            if ($key === $canonical) continue;

            $mergedIncidents[] = [
                'type'     => 'nombre_fusionado',
                'severity' => 'info',
                'message'  => 'Colaborador fusionado por variante de escritura: "' . mb_strtoupper($key) . '" → "' . mb_strtoupper($canonical) . '".',
            ];

            if (isset($payroll[$key]) && !isset($payroll[$canonical])) {
                $payroll[$canonical] = $payroll[$key];
            }
            unset($payroll[$key]);

            if (isset($gestorPlacements[$key])) {
                if (isset($gestorPlacements[$canonical])) {
                    $gestorPlacements[$canonical]['operaciones'] += $gestorPlacements[$key]['operaciones'];
                    $gestorPlacements[$canonical]['colocacion']  += $gestorPlacements[$key]['colocacion'];
                    $gestorPlacements[$canonical]['sucursal']    ??= $gestorPlacements[$key]['sucursal'];
                } else {
                    $gestorPlacements[$canonical] = $gestorPlacements[$key];
                }
                unset($gestorPlacements[$key]);
            }

            if (isset($portfolioByNorm[$key])) {
                if (isset($portfolioByNorm[$canonical])) {
                    $portfolioByNorm[$canonical]['cartera'] += $portfolioByNorm[$key]['cartera'];
                    $portfolioByNorm[$canonical]['vencida']  += $portfolioByNorm[$key]['vencida'];
                    $portfolioByNorm[$canonical]['sucursal'] ??= $portfolioByNorm[$key]['sucursal'];
                } else {
                    $portfolioByNorm[$canonical] = $portfolioByNorm[$key];
                }
                unset($portfolioByNorm[$key]);
            }

            if (isset($recoveryByNorm[$key])) {
                $recoveryByNorm[$canonical] = ($recoveryByNorm[$canonical] ?? 0.0) + $recoveryByNorm[$key];
                unset($recoveryByNorm[$key]);
            }

            if (isset($expensesByNorm[$key])) {
                $expensesByNorm[$canonical] = ($expensesByNorm[$canonical] ?? 0.0) + $expensesByNorm[$key];
                unset($expensesByNorm[$key]);
            }

            $rawNameByNorm[$canonical] ??= $rawNameByNorm[$key] ?? mb_strtoupper($key);
            unset($rawNameByNorm[$key]);
        }

        $allKeys = collect(array_keys($payroll))
            ->merge(array_keys($gestorPlacements))
            ->merge(array_keys($portfolioByNorm))
            ->unique()
            ->filter(fn ($k) => $k !== '')
            ->values();

        $rows = $allKeys->map(function (string $key) use ($payroll, $gestorPlacements, $portfolioByNorm, $recoveryByNorm, $expensesByNorm, $rawNameByNorm) {
            $emp    = $payroll[$key] ?? null;
            $ges    = $gestorPlacements[$key] ?? null;
            $po     = $portfolioByNorm[$key] ?? null;
            $rec    = $recoveryByNorm[$key] ?? 0.0;
            $gasEmp = $expensesByNorm[$key] ?? ($emp['gastos'] ?? 0.0);

            $name   = $emp['name'] ?? $ges['gestor'] ?? $rawNameByNorm[$key] ?? mb_strtoupper($key);
            $code   = ($emp['code'] ?? null) ?: ($ges['codigo'] ?? null) ?: null;
            $branch = ($emp['branch_src'] ?? null) ?? ($ges['sucursal'] ?? null) ?? ($po['sucursal'] ?? null);
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
                'colocacion'   => (float)($ges['colocacion'] ?? 0),
                'operaciones'  => (int)($ges['operaciones'] ?? 0),
                'recuperacion' => round($rec, 2),
                'cartera'      => $cartera,
                'vencida'      => $vencida,
                'mora'         => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0.0,
            ];
        })->sortByDesc(fn ($r) => $r['colocacion'] + $r['pagos'])->values()->all();

        return ['rows' => $rows, 'merged_incidents' => $mergedIncidents];
    }

    // ── INCIDENTS ────────────────────────────────────────────────────────────

    private function buildIncidents(PeriodSummary $summary, Period $period, array $extraIncidents = []): array
    {
        if (!$summary->relationLoaded('incidents')) {
            $summary->load('incidents');
        }

        $stored = $summary->incidents->map(fn ($i) => [
            'type'     => $i->type,
            'severity' => $i->severity,
            'message'  => $i->message,
        ])->values()->all();

        $live = $this->detectLiveIncidents($period);

        // Merge: stored incidents first, then live ones not already present by type
        $storedTypes = array_column($stored, 'type');
        foreach ($live as $inc) {
            if (!in_array($inc['type'], $storedTypes, true)) {
                $stored[] = $inc;
            }
        }

        // Append merge-by-similarity incidents (each has unique message)
        foreach ($extraIncidents as $inc) {
            $stored[] = $inc;
        }

        return $stored;
    }

    private function detectLiveIncidents(Period $period): array
    {
        $items = [];

        // Employees in NOI without branch assignment
        $sinSucursal = DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                  ->whereIn('eba.period_id', array_merge([$period->id], $this->dataIds));
            })
            ->whereIn('n.period_id', $this->dataIds)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.branch_id')
            ->distinct('n.employee_id')
            ->count('n.employee_id');

        if ($sinSucursal > 0) {
            $items[] = [
                'type'     => 'empleados_sin_sucursal',
                'severity' => 'warning',
                'message'  => "{$sinSucursal} empleado(s) del archivo NOI no tienen sucursal asignada. Asígnalos en el módulo Empleados para que aparezcan correctamente en el reporte.",
            ];
        }

        // Promoter names in placements that do not match any employee in employees table
        $gestoresSinMatch = DB::table('fact_placements as p')
            ->whereIn('p.period_id', $this->dataIds)
            ->whereNotNull('p.normalized_promoter_name')
            ->where('p.normalized_promoter_name', '<>', '')
            ->whereNotExists(function ($q) {
                $q->from('employees as e')
                  ->whereColumn('e.normalized_name', 'p.normalized_promoter_name');
            })
            ->distinct('p.normalized_promoter_name')
            ->count('p.normalized_promoter_name');

        if ($gestoresSinMatch > 0) {
            $items[] = [
                'type'     => 'gestores_sin_match_noi',
                'severity' => 'warning',
                'message'  => "{$gestoresSinMatch} gestor(es) de ministraciones no coinciden con ningún empleado del archivo NOI. Revisa la normalización de nombres.",
            ];
        }

        // Portfolios with no product name
        $sinProducto = DB::table('fact_portfolios')
            ->whereIn('period_id', $this->dataIds)
            ->where(fn ($q) => $q->whereNull('product_name')->orWhere('product_name', ''))
            ->count();

        if ($sinProducto > 0) {
            $items[] = [
                'type'     => 'cartera_sin_producto',
                'severity' => 'warning',
                'message'  => "{$sinProducto} contrato(s) de cartera no tienen producto identificado.",
            ];
        }

        // Summary cartera vs bucket sum mismatch (indicates stale global_metrics)
        $bucketSum = (float) DB::table('fact_portfolios')
            ->whereIn('period_id', $this->dataIds)
            ->where('days_past_due', '>', 0)
            ->sum('balance');

        $globalVencida = (float) DB::table('fact_portfolios')
            ->whereIn('period_id', $this->dataIds)
            ->sum('past_due_balance');

        if ($bucketSum > 0 && $globalVencida === 0.0) {
            $items[] = [
                'type'     => 'cartera_vencida_recalculada',
                'severity' => 'info',
                'message'  => 'La cartera vencida fue calculada usando días vencidos porque la columna de saldo vencido estaba vacía.',
            ];
        }

        return $items;
    }

    // ── CHART DATA ───────────────────────────────────────────────────────────

    private function chartByBranch(Period $period, string $metric): array
    {
        // Only show real branches (not routes/offices); CORPORATIVO excluded from cartera/recuperacion
        $realNames = array_values(array_filter(
            $this->resolveRealBranchNormalizedNames(),
            fn ($n) => $n !== 'corporativo'
        ));

        if ($metric === 'recuperacion') {
            $rows = DB::table('fact_recoveries as r')
                ->join('branches as b', 'r.branch_id', '=', 'b.id')
                ->whereIn('r.period_id', $this->dataIds)
                ->when(!empty($realNames), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realNames))
                ->selectRaw('b.name as label, SUM(r.total_amount) as value')
                ->groupBy('b.id', 'b.name')
                ->orderByDesc('value')
                ->limit(10)
                ->get();
        } else {
            $rows = DB::table('fact_portfolios as p')
                ->join('branches as b', 'p.branch_id', '=', 'b.id')
                ->whereIn('p.period_id', $this->dataIds)
                ->when(!empty($realNames), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realNames))
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
            ->whereRaw("(product_name NOT REGEXP ? OR product_name IS NULL)", [self::PRODUCT_RESTRUCTURE_PATTERN . '|RECURSOS PROPIOS'])
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

    // ── NEW SECTIONS FOR EXCEL TABS ─────────────────────────────────────────

    /**
     * NOI movements grouped as: branch → concept → total amount.
     * Used for the NOMINAS Excel tab.
     */
    private function buildPayrollByBranchConceptResolved(Period $period): array
    {
        $resolver = app(BranchResolverService::class);

        // ── 1. EBA map: employee_id → real branch (using all dataIds, not just monthly) ──
        //    LEFT JOIN so employees with orphaned/empty branch_id are still found.
        //    For each employee, prefer an EBA that resolves to a real branch.
        $ebaMap = [];
        DB::table('employee_branch_assignments as eba')
            ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('eba.period_id', $this->dataIds)
            ->select('eba.employee_id', 'b.name as branch')
            ->get()
            ->each(function ($r) use (&$ebaMap, $resolver) {
                $empId = $r->employee_id;
                if (!$r->branch) return; // orphaned/empty branch_id → skip, fall to activity lookup
                $real     = $resolver->resolveRealBranchFromRoute($r->branch);
                $resolved = $real ?? $r->branch;
                // Keep if not set yet, or upgrade from non-real to real branch
                if (!isset($ebaMap[$empId]) || (!$resolver->isRealReportBranch($ebaMap[$empId]) && $real)) {
                    $ebaMap[$empId] = $resolved;
                }
            });

        // ── 2. Activity lookup: normalized name → real branch from placements/portfolios/recoveries ──
        $activityMap = $this->buildPayrollActivityLookup($resolver);

        // ── 3. NOI rows pre-aggregated by (employee, concept) ────────────────
        $noiRows = DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $this->dataIds)
            ->whereNotNull('n.employee_id')
            ->selectRaw("
                n.employee_id,
                e.full_name,
                COALESCE(n.concept, n.concept_type, 'Sin concepto') as concept,
                SUM(n.amount) as total
            ")
            ->groupBy('n.employee_id', 'e.full_name', 'n.concept', 'n.concept_type')
            ->get();

        // ── 4. Canonical map for alias resolution ────────────────────────────
        $employeeNorms = $noiRows->pluck('full_name')->unique()
            ->map(fn ($n) => $this->canonicalizer->normalize($n))->values()->all();
        $allNorms     = array_values(array_unique(array_merge($employeeNorms, array_keys($activityMap))));
        $canonicalMap = $this->canonicalizer->buildCanonicalMap($allNorms, $employeeNorms);

        // ── 5. Resolve branch per row ─────────────────────────────────────────
        $data     = [];
        $sinTotal = 0.0;
        $sinCount = [];

        foreach ($noiRows as $row) {
            // Priority A: EBA (manual assignment)
            $branch = $ebaMap[$row->employee_id] ?? null;

            // Priority B/C/D: activity lookup by norm, canonical, or any alias
            if (!$branch) {
                $norm      = $this->canonicalizer->normalize($row->full_name ?? '');
                $canonical = $canonicalMap[$norm] ?? $norm;
                $branch    = $activityMap[$norm] ?? $activityMap[$canonical] ?? null;

                if (!$branch) {
                    foreach ($canonicalMap as $alias => $can) {
                        if ($can === $canonical && isset($activityMap[$alias])) {
                            $branch = $activityMap[$alias];
                            break;
                        }
                    }
                }

                // Resolve route → real branch; discard if not resolvable
                if ($branch) {
                    $branch = $resolver->resolveRealBranchFromRoute($branch);
                }
            }

            $branchKey  = $branch ?? 'Sin asignar';
            $conceptKey = $row->concept ?? 'Sin concepto';
            $amount     = (float) $row->total;

            $data[$branchKey][$conceptKey] = ($data[$branchKey][$conceptKey] ?? 0.0) + $amount;

            if ($branchKey === 'Sin asignar') {
                $sinTotal                         += $amount;
                $sinCount[$row->employee_id]       = true;
            }
        }

        ksort($data);

        // ── 6. Incident for truly unresolvable employees (UI only) ───────────
        $incidents  = [];
        $unresolved = count($sinCount);
        if ($unresolved > 0) {
            $incidents[] = [
                'type'     => 'nomina_sin_sucursal',
                'severity' => 'warning',
                'message'  => "Hay {$unresolved} colaborador(es) de NOI pendientes de asignar a sucursal. "
                            . 'Monto sin asignar: $' . number_format($sinTotal, 2) . '. '
                            . 'Revísalos en el módulo Empleados / Gestores.',
            ];
        }

        return ['data' => $data, 'incidents' => $incidents];
    }

    private function buildPayrollActivityLookup(BranchResolverService $resolver): array
    {
        $lookup = [];

        DB::table('fact_placements as p')
            ->join('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $this->dataIds)
            ->whereNotNull('p.promoter_name')
            ->select('p.promoter_name', 'b.name as branch')
            ->distinct()
            ->get()
            ->each(function ($row) use (&$lookup, $resolver) {
                $norm = $this->canonicalizer->normalize($row->promoter_name);
                $real = $resolver->resolveRealBranchFromRoute($row->branch);
                if ($real && !isset($lookup[$norm])) {
                    $lookup[$norm] = $real;
                }
            });

        DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->whereNotNull('po.promoter_name')
            ->select('po.promoter_name', 'b.name as branch')
            ->distinct()
            ->get()
            ->each(function ($row) use (&$lookup, $resolver) {
                $norm = $this->canonicalizer->normalize($row->promoter_name);
                $real = $resolver->resolveRealBranchFromRoute($row->branch);
                if ($real && !isset($lookup[$norm])) {
                    $lookup[$norm] = $real;
                }
            });

        DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $this->dataIds)
            ->whereNotNull('r.promoter_name')
            ->select('r.promoter_name', 'b.name as branch')
            ->distinct()
            ->get()
            ->each(function ($row) use (&$lookup, $resolver) {
                $norm = $this->canonicalizer->normalize($row->promoter_name);
                $real = $resolver->resolveRealBranchFromRoute($row->branch);
                if ($real && !isset($lookup[$norm])) {
                    $lookup[$norm] = $real;
                }
            });

        return $lookup;
    }

    /**
     * Expense categories × real branches matrix.
     * Used for the GASTOS Excel tab.
     */
    private function buildExpensesMatrix(Period $period): array
    {
        $realBranchNames = $this->resolveRealBranchNormalizedNames();

        $rows = DB::table('fact_expenses as e')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->selectRaw("
                COALESCE(e.category, 'Sin categoría') as category,
                COALESCE(b.name, 'Sin sucursal') as branch,
                SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total
            ")
            ->groupBy('e.category', 'e.branch_id', 'b.name')
            ->get();

        $categories = [];
        $branches   = [];
        $matrix     = [];

        foreach ($rows as $row) {
            $branch = $row->branch;
            // Normalize to real branches only; lump unrecognized into 'Otras'
            $normalizedBranch = $this->normalizeText($branch);
            if (!empty($realBranchNames) && !in_array($normalizedBranch, $realBranchNames, true)) {
                $branch = 'Otras';
            }

            $categories[$row->category] = true;
            $branches[$branch]          = true;
            $matrix[$row->category][$branch] = ($matrix[$row->category][$branch] ?? 0.0) + (float) $row->total;
        }

        $categoryList = array_keys($categories);
        $branchList   = array_keys($branches);
        sort($categoryList);
        sort($branchList);

        $totalsByCategory = [];
        $totalsByBranch   = [];

        foreach ($categoryList as $cat) {
            $totalsByCategory[$cat] = 0.0;
            foreach ($branchList as $br) {
                $v = $matrix[$cat][$br] ?? 0.0;
                $totalsByCategory[$cat] += $v;
                $totalsByBranch[$br]     = ($totalsByBranch[$br] ?? 0.0) + $v;
            }
        }

        return [
            'categories'         => $categoryList,
            'branches'           => $branchList,
            'matrix'             => $matrix,
            'totals_by_category' => $totalsByCategory,
            'totals_by_branch'   => $totalsByBranch,
            'grand_total'        => array_sum($totalsByCategory),
        ];
    }

    /**
     * Interbranch loan expenses — cross-references PDF Lendus with Excel complementario.
     * PDF is the primary source; Excel supplies the destination branch via raw_payload.branch_to_detected.
     * Used for the P. INTERSUC. Excel tab.
     */
    private function buildInterbranchLoans(Period $period): array
    {
        $resolver = app(BranchResolverService::class);

        // ── A: PDF Lendus intersucursal records (individual rows) ────────────
        $pdfRows = DB::table('fact_expenses as e')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->leftJoin('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->where(function ($q) {
                $q->whereRaw("UPPER(COALESCE(e.category,'')) LIKE '%FONDEO%'")
                  ->orWhereRaw("UPPER(COALESCE(e.category,'')) LIKE '%INTERSUCURSAL%'")
                  ->orWhereRaw("UPPER(COALESCE(e.concept,'')) LIKE '%PRESTAMO INTERSUC%'")
                  ->orWhereRaw("UPPER(COALESCE(e.concept,'')) LIKE '%FONDEO%'");
            })
            ->where(function ($q) {
                $q->whereNull('ds.code')
                  ->orWhereIn('ds.code', ['gastos_lendus', 'gastos']);
            })
            ->selectRaw("
                e.id,
                COALESCE(b.name, 'Sin sucursal') as branch,
                COALESCE(e.concept, e.category, 'Préstamo intersucursal') as concept,
                e.observations,
                e.expense_date,
                COALESCE(NULLIF(e.paid_amount, 0), e.amount) as amount
            ")
            ->orderBy('e.expense_date')
            ->orderByDesc('amount')
            ->get();

        // ── B: Excel complementario — only intersucursal rows ────────────────
        // The Excel covers potentially different months than the PDF.
        // We filter to only category='Préstamos Intersucursales' to reduce false matches,
        // then prefer rows with branch_to_detected when crossing by amount.
        $excelRows = DB::table('fact_expenses as e')
            ->leftJoin('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->where('ds.code', 'gastos_lendus_excel')
            ->where('e.category', 'Préstamos Intersucursales')
            ->selectRaw("
                e.id,
                COALESCE(b.name, '') as branch,
                COALESCE(e.concept, e.category, 'Sin concepto') as concept,
                e.observations,
                e.expense_date,
                COALESCE(NULLIF(e.paid_amount, 0), e.amount) as amount,
                e.raw_payload
            ")
            ->get()
            ->map(function ($row) {
                $row->decoded_payload = is_string($row->raw_payload)
                    ? (json_decode($row->raw_payload, true) ?? [])
                    : (array)($row->raw_payload ?? []);
                return $row;
            })
            ->all();

        // Index Excel intersucursal records by rounded amount.
        // No date restriction — PDF and Excel may cover different months.
        // Rows with branch_to_detected are prepended so they are preferred in matching.
        $excelIndex  = [];
        $usedExcelIds = [];
        foreach ($excelRows as $ex) {
            $key = (int) round((float) $ex->amount);
            $excelIndex[$key] ??= [];
            if (($ex->decoded_payload['branch_to_detected'] ?? null) !== null) {
                array_unshift($excelIndex[$key], $ex);
            } else {
                $excelIndex[$key][] = $ex;
            }
        }

        $detail    = [];
        $unmatched = [];
        $incidents = [];
        $fondeaMap = [];
        $recibeMap = [];

        foreach ($pdfRows as $pdfRow) {
            $pdfAmount = (float) $pdfRow->amount;
            $pdfDate   = $pdfRow->expense_date
                ? \Carbon\Carbon::parse($pdfRow->expense_date)
                : null;

            // Resolve from_branch — never expose routes
            $fromBranch = $resolver->resolveRealBranchFromRoute($pdfRow->branch) ?? 'No identificada';

            // ── Match PDF row against Excel intersucursal records ─────────
            // Match by amount ±1 peso only — no date restriction because
            // PDF and Excel may legitimately cover different months.
            // Excel index is pre-sorted: rows with branch_to_detected come first.
            $matchedExcel = null;
            $amtKey       = (int) round($pdfAmount);
            foreach ([$amtKey, $amtKey - 1, $amtKey + 1] as $key) {
                if (!isset($excelIndex[$key])) continue;
                foreach ($excelIndex[$key] as $ex) {
                    if (in_array($ex->id, $usedExcelIds, true)) continue;
                    $matchedExcel = $ex;
                    break 2;
                }
            }

            $toBranch      = 'No identificada';
            $observation   = $pdfRow->observations ?? '';
            $justification = '';
            $source        = 'pdf';

            if ($matchedExcel) {
                $usedExcelIds[] = $matchedExcel->id;
                $payload        = $matchedExcel->decoded_payload;

                // 1. Use branch_to_detected set by the Excel importer
                $toBranchRaw = $payload['branch_to_detected'] ?? null;
                if ($toBranchRaw) {
                    $toBranch = $resolver->resolveRealBranchFromRoute($toBranchRaw) ?? $toBranchRaw;
                }

                // 2. Fallback: pattern-detect from Excel text
                if ($toBranch === 'No identificada') {
                    $exText  = implode(' ', array_filter([$matchedExcel->concept, $matchedExcel->observations]));
                    $detected = $this->detectBranchFromText($exText, $resolver);
                    if ($detected) $toBranch = $detected;
                }

                $observation   = $matchedExcel->observations ?? $observation;
                $justification = $payload['justification'] ?? '';
                $source        = 'pdf+excel';
            } else {
                // No Excel match — try PDF concept/observation text
                $pdfText  = implode(' ', array_filter([$pdfRow->concept, $pdfRow->observations]));
                $detected = $this->detectBranchFromText($pdfText, $resolver);
                if ($detected) $toBranch = $detected;
            }

            if ($toBranch === 'No identificada') {
                $unmatched[] = [
                    'date'        => $pdfDate ? $pdfDate->format('d/m/Y') : '—',
                    'from_branch' => $fromBranch,
                    'amount'      => $pdfAmount,
                    'concept'     => $pdfRow->concept,
                    'source'      => $source,
                ];
                $incidents[] = [
                    'type'     => 'prestamo_intersucursal_sin_destino',
                    'severity' => 'warning',
                    'message'  => sprintf(
                        'Préstamo intersucursal sin sucursal destino identificada: %s — $%s el %s.',
                        $fromBranch,
                        number_format($pdfAmount, 2),
                        $pdfDate ? $pdfDate->format('d/m/Y') : 'fecha desconocida'
                    ),
                ];
            }

            $fondeaMap[$fromBranch] = ($fondeaMap[$fromBranch] ?? 0.0) + $pdfAmount;
            $recibeMap[$toBranch]   = ($recibeMap[$toBranch] ?? 0.0) + $pdfAmount;

            $detail[] = [
                'date'          => $pdfDate ? $pdfDate->format('d/m/Y') : '—',
                'from_branch'   => $fromBranch,
                'to_branch'     => $toBranch,
                'amount'        => $pdfAmount,
                'concept'       => $pdfRow->concept,
                'observation'   => $observation,
                'justification' => $justification,
                'source'        => $source,
            ];
        }

        // ── C: Excel rows not matched to any PDF row ──────────────────────────
        // These represent intersucursal transactions from months not covered by the PDF.
        // They have a known destination (branch_to_detected) but unknown from_branch.
        foreach ($excelRows as $ex) {
            if (in_array($ex->id, $usedExcelIds, true)) {
                continue;
            }
            $payload   = $ex->decoded_payload;
            $exAmount  = (float) $ex->amount;
            $exDate    = $ex->expense_date ? \Carbon\Carbon::parse($ex->expense_date) : null;

            $toBranch  = 'No identificada';
            $toBranchRaw = $payload['branch_to_detected'] ?? null;
            if ($toBranchRaw) {
                $toBranch = $resolver->resolveRealBranchFromRoute($toBranchRaw) ?? $toBranchRaw;
            }
            if ($toBranch === 'No identificada') {
                $exText   = implode(' ', array_filter([$ex->concept, $ex->observations]));
                $detected = $this->detectBranchFromText($exText, $resolver);
                if ($detected) $toBranch = $detected;
            }

            if ($ex->branch && $ex->branch !== '') {
                $fromBranch = $resolver->resolveRealBranchFromRoute($ex->branch) ?? $ex->branch;
            } else {
                $fromBranch = 'Sin identificar';
                $observ = trim($ex->observations ?? '');
                if ($observ !== '') {
                    $normName   = $this->canonicalizer->normalize($observ);
                    $empBranch  = DB::table('employees as e')
                        ->join('employee_branch_assignments as eba', 'e.id', '=', 'eba.employee_id')
                        ->join('branches as b', 'eba.branch_id', '=', 'b.id')
                        ->where('e.normalized_name', $normName)
                        ->where('eba.period_id', $period->id)
                        ->value('b.name');
                    if ($empBranch) {
                        $fromBranch = strtoupper($empBranch);
                    }
                }
            }

            $fondeaMap[$fromBranch] = ($fondeaMap[$fromBranch] ?? 0.0) + $exAmount;
            $recibeMap[$toBranch]   = ($recibeMap[$toBranch]   ?? 0.0) + $exAmount;

            $detail[] = [
                'date'          => $exDate ? $exDate->format('d/m/Y') : '—',
                'from_branch'   => $fromBranch,
                'to_branch'     => $toBranch,
                'amount'        => $exAmount,
                'concept'       => $ex->concept,
                'observation'   => $ex->observations ?? '',
                'justification' => '',
                'source'        => 'excel',
            ];
        }

        // ── D: Classify each row as fondeo operativo or excedente ───────────────
        // "Excedente" = to_branch is CORPORATIVO (money sent to corporate office).
        // "Fondeo operativo" = everything else (operative-to-operative).
        // Rule: operative fondeos must satisfy fondea_total == recibe_total (neto=$0).
        $EXCEDENTE_DESTINATIONS = ['CORPORATIVO'];

        $fondeaOperMap   = [];
        $recibeOperMap   = [];
        $excedenteByMap  = [];

        $detailFondeos   = [];
        $detailExcedentes = [];

        foreach ($detail as $row) {
            $tob = $row['to_branch'];
            if (in_array($tob, $EXCEDENTE_DESTINATIONS, true)) {
                $excedenteByMap[$row['from_branch']] = ($excedenteByMap[$row['from_branch']] ?? 0.0) + $row['amount'];
                $row['type']      = 'excedente';
                $detailExcedentes[] = $row;
            } else {
                $fondeaOperMap[$row['from_branch']] = ($fondeaOperMap[$row['from_branch']] ?? 0.0) + $row['amount'];
                $recibeOperMap[$tob]               = ($recibeOperMap[$tob]               ?? 0.0) + $row['amount'];
                $row['type']      = 'fondeo';
                $detailFondeos[]  = $row;
            }
        }

        $totalFondeoOper = array_sum($fondeaOperMap);
        $totalExcedentes = array_sum($excedenteByMap);
        $total           = $totalFondeoOper + $totalExcedentes;

        $fondeaOperRows = collect($fondeaOperMap)
            ->map(fn ($v, $k) => ['branch' => $k, 'total' => $v])
            ->sortByDesc('total')->values()->all();

        $recibeOperRows = collect($recibeOperMap)
            ->map(fn ($v, $k) => ['branch' => $k, 'total' => $v])
            ->sortByDesc('total')->values()->all();

        $excedenteRows = collect($excedenteByMap)
            ->map(fn ($v, $k) => ['branch' => $k, 'total' => $v])
            ->sortByDesc('total')->values()->all();

        // Backward-compat: combined fondea/recibe (all rows)
        $fondeaRows = collect($fondeaMap)
            ->map(fn ($v, $k) => ['branch' => $k, 'total' => $v])
            ->sortByDesc('total')->values()->all();
        $recibeRows = collect($recibeMap)
            ->map(fn ($v, $k) => ['branch' => $k, 'total' => $v])
            ->sortByDesc('total')->values()->all();

        return [
            // Operative fondeos section (fondea = recibe, neto = $0)
            'operative_fondeos' => [
                'fondea'        => $fondeaOperRows,
                'recibe'        => $recibeOperRows,
                'fondea_total'  => $totalFondeoOper,
                'recibe_total'  => $totalFondeoOper, // = fondea (neto=0 by design)
                'detail'        => $detailFondeos,
            ],
            // Excedentes / envío a CORPORATIVO section
            'excedentes' => [
                'by_branch'     => $excedenteRows,
                'total'         => $totalExcedentes,
                'detail'        => $detailExcedentes,
            ],
            // Totals and combined detail (all rows)
            'total'     => $total,
            'fondea'    => $fondeaRows,
            'recibe'    => $recibeRows,
            'by_branch' => $fondeaRows,
            'detail'    => array_merge($detailFondeos, $detailExcedentes),
            'unmatched' => $unmatched,
            'incidents' => $incidents,
        ];
    }

    /**
     * Extract a real branch name from free text using pattern matching.
     * Looks for patterns like "FONDEO A ORIZABA", "PRÉSTAMO PARA TULA", etc.
     */
    private function detectBranchFromText(string $text, BranchResolverService $resolver): ?string
    {
        if (empty(trim($text))) return null;

        // Split on pipe (|) and try each segment independently so "FONDEO | A CUERNAVACA"
        // resolves "A CUERNAVACA" correctly.
        $segments = array_map('trim', explode('|', $text));
        foreach ($segments as $segment) {
            $result = $this->detectBranchFromSegment($segment, $resolver);
            if ($result) return $result;
        }

        return null;
    }

    private function detectBranchFromSegment(string $text, BranchResolverService $resolver): ?string
    {
        if (empty(trim($text))) return null;

        $upper = mb_strtoupper(trim($text));

        $patterns = [
            '/FOND(?:EA|EO)\s+(?:A|PARA|DE)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/FOND(?:EA|EO)\s+(?:A|PARA|DE)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/FOND(?:EA|EO)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/FOND(?:EA|EO)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/PR[EÉ]STAMO\s+(?:A|PARA|INTER)?\s*SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/PR[EÉ]STAMO\s+(?:A|PARA|INTER)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/INTERSUCURSAL\s+(?:A|PARA)?\s*SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/INTERSUCURSAL\s+(?:A|PARA)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/(?:A|PARA)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/\bSUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/DEP[OÓ]SITO\s+(?:A|PARA)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
            '/APOYO\s+(?:A|PARA)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.|]|$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $upper, $matches)) {
                // Take the LAST match group — handles "FONDEO FONDEO IXTLAHUACA" double-prefix
                $lastIdx   = count($matches[1]) - 1;
                $candidate = $this->stripBranchNoise(trim($matches[1][$lastIdx]));
                if (mb_strlen($candidate) >= 3) {
                    $resolved = $resolver->resolveRealBranchFromRoute($candidate);
                    if ($resolved) return $resolved;
                }
            }
        }

        // Fallback: strip all noise from entire segment and try direct resolution.
        // Covers "SAN JUAN DEL RIO", "SUCURSAL CORDOBA", "A SUC TENANGO", etc.
        $stripped = $this->stripBranchNoise($upper);
        if (mb_strlen($stripped) >= 3) {
            $resolved = $resolver->resolveRealBranchFromRoute($stripped);
            if ($resolved) return $resolved;
        }

        return null;
    }

    private function stripBranchNoise(string $candidate): string
    {
        static $noiseLeading = ['FONDEO', 'FONDEA', 'DEPOSITO', 'DEPÓSITO', 'A', 'PARA', 'DE', 'SUC', 'SUCURSAL'];
        $c = mb_strtoupper(trim($candidate));
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($noiseLeading as $noise) {
                if (str_starts_with($c, $noise . ' ')) {
                    $c       = trim(mb_substr($c, mb_strlen($noise)));
                    $changed = true;
                    break;
                }
            }
        }
        return $c;
    }

    /**
     * Recovery totals by real branch and by gestor.
     * Routes/offices are folded into their parent real sucursal.
     * Used for the RECUP. Excel tab.
     */
    private function buildRecoveryDetail(Period $period): array
    {
        $resolver = app(BranchResolverService::class);

        // Apply same exclusion logic as BranchRadiographyCalculator::accumulateRecuperacion()
        // so total matches the global recuperacion KPI ($18,324,971.76).
        // Excluded rows (savehearts + CONDONACION + COBERTURA/SEGURO/UNIFICACION patterns)
        // are surfaced as separate informational columns for display in the Ingresos tab.
        $rawRows = DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $this->dataIds)
            ->selectRaw("
                b.name as branch,
                SUM(CASE
                    WHEN r.is_savehearts = 1 THEN 0
                    WHEN r.transaction = 'CONDONACION' THEN 0
                    WHEN r.is_savehearts = 0 AND (
                        UPPER(COALESCE(r.concept,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%UNIFICACION%'
                    ) THEN 0
                    ELSE r.total_amount
                END) as total,
                SUM(CASE
                    WHEN r.is_savehearts = 1 THEN 0
                    WHEN r.transaction = 'CONDONACION' THEN 0
                    WHEN r.is_savehearts = 0 AND (
                        UPPER(COALESCE(r.concept,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%UNIFICACION%'
                    ) THEN 0
                    ELSE r.capital
                END) as capital,
                SUM(CASE
                    WHEN r.is_savehearts = 1 THEN 0
                    WHEN r.transaction = 'CONDONACION' THEN 0
                    WHEN r.is_savehearts = 0 AND (
                        UPPER(COALESCE(r.concept,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%UNIFICACION%'
                    ) THEN 0
                    ELSE r.interest
                END) as interest,
                SUM(CASE
                    WHEN r.is_savehearts = 1 THEN 0
                    WHEN r.transaction = 'CONDONACION' THEN 0
                    WHEN r.is_savehearts = 0 AND (
                        UPPER(COALESCE(r.concept,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%UNIFICACION%'
                    ) THEN 0
                    ELSE r.tax
                END) as tax,
                SUM(CASE
                    WHEN r.is_savehearts = 1 THEN 0
                    WHEN r.transaction = 'CONDONACION' THEN 0
                    WHEN r.is_savehearts = 0 AND (
                        UPPER(COALESCE(r.concept,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(r.concept,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(r.operation,'')) LIKE '%UNIFICACION%'
                    ) THEN 0
                    ELSE r.charges
                END) as charges,
                SUM(CASE WHEN r.is_savehearts = 1 THEN r.total_amount ELSE 0 END) as seguros_excluidos,
                SUM(CASE WHEN r.transaction = 'CONDONACION' THEN r.total_amount ELSE 0 END) as condonacion_excluida,
                SUM(CASE WHEN r.transaction = 'CONDONACION'
                    AND UPPER(COALESCE(r.operation,'')) LIKE '%UNIFICACION%'
                    THEN r.total_amount ELSE 0 END) as unificacion_excluida,
                SUM(r.total_amount) as bruto,
                COUNT(*) as operaciones
            ")
            ->groupBy('r.branch_id', 'b.name')
            ->orderByDesc('total')
            ->get();

        $grouped = [];
        foreach ($rawRows as $r) {
            // Never fall back to the raw route name — skip unmapped routes silently
            $real = $resolver->resolveRealBranchFromRoute($r->branch);
            if (!$real) {
                continue;
            }
            if (!isset($grouped[$real])) {
                $grouped[$real] = [
                    'branch'               => $real,
                    'capital'              => 0.0,
                    'interest'             => 0.0,
                    'tax'                  => 0.0,
                    'charges'              => 0.0,
                    'moratorios'           => 0.0,
                    'total'                => 0.0,
                    'bruto'                => 0.0,
                    'seguros_excluidos'    => 0.0,
                    'condonacion_excluida' => 0.0,
                    'unificacion_excluida' => 0.0,
                    'operaciones'          => 0,
                ];
            }
            $grouped[$real]['capital']              += (float)($r->capital              ?? 0);
            $grouped[$real]['interest']             += (float)($r->interest             ?? 0);
            $grouped[$real]['tax']                  += (float)($r->tax                  ?? 0);
            $grouped[$real]['charges']              += (float)($r->charges              ?? 0);
            $grouped[$real]['total']                += (float)($r->total                ?? 0);
            $grouped[$real]['bruto']                += (float)($r->bruto                ?? 0);
            $grouped[$real]['seguros_excluidos']    += (float)($r->seguros_excluidos    ?? 0);
            $grouped[$real]['condonacion_excluida'] += (float)($r->condonacion_excluida ?? 0);
            $grouped[$real]['unificacion_excluida'] += (float)($r->unificacion_excluida ?? 0);
            $grouped[$real]['operaciones']          += (int)($r->operaciones            ?? 0);
        }

        usort($grouped, fn ($a, $b) => $b['total'] <=> $a['total']);
        $byBranch = array_values($grouped);

        $byGestor = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $this->dataIds)
            ->whereNotNull('r.promoter_name')
            ->selectRaw('
                r.promoter_name as gestor,
                COALESCE(b.name,\'Sin sucursal\') as branch,
                SUM(r.total_amount) as total,
                COUNT(*) as operaciones
            ')
            ->groupBy('r.promoter_name', 'r.branch_id', 'b.name')
            ->orderByDesc('total')
            ->limit(100)
            ->get()
            ->map(fn ($r) => [
                'gestor'      => $r->gestor,
                'branch'      => $r->branch,
                'total'       => (float) $r->total,
                'operaciones' => (int) $r->operaciones,
            ])
            ->values()->all();

        return ['by_branch' => $byBranch, 'by_gestor' => $byGestor];
    }

    /**
     * Portfolio cartera and vencida broken down by real branch and product.
     * Used for the VAL. CART Excel tab.
     */
    private function buildPortfolioByBranchProduct(Period $period): array
    {
        $realBranchNames = $this->resolveRealBranchNormalizedNames();

        // vencida = SUM de las 5 columnas vencidas (misma fórmula que BranchRadiographyCalculator),
        // solo donde days_past_due > 0. NO usar balance ni past_due_balance.
        $rows = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->when(!empty($realBranchNames), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realBranchNames))
            ->selectRaw("
                b.name as branch,
                COALESCE(NULLIF(po.product_name,''), 'Sin producto') as product,
                COUNT(*) as contratos,
                SUM(po.balance) as cartera,
                SUM(CASE WHEN po.days_past_due > 0 THEN
                    COALESCE(po.capital_due, 0)
                    + COALESCE(po.interes_atrasado, 0)
                    + COALESCE(po.impuesto_atrasado, 0)
                    + COALESCE(po.saldo_interes_moratorio, 0)
                    + COALESCE(po.saldo_impuesto_interes_moratorio, 0)
                ELSE 0 END) as vencida,
                SUM(CASE WHEN po.days_past_due > 0 THEN COALESCE(po.capital_due, 0) ELSE 0 END) as capital_atrasado,
                SUM(CASE WHEN po.days_past_due > 0 THEN COALESCE(po.interes_atrasado, 0) ELSE 0 END) as interes_atrasado,
                SUM(CASE WHEN po.days_past_due > 0 THEN COALESCE(po.impuesto_atrasado, 0) ELSE 0 END) as impuesto_atrasado,
                SUM(CASE WHEN po.days_past_due > 0 THEN COALESCE(po.saldo_interes_moratorio, 0) ELSE 0 END) as saldo_interes_moratorio,
                SUM(CASE WHEN po.days_past_due > 0 THEN COALESCE(po.saldo_impuesto_interes_moratorio, 0) ELSE 0 END) as saldo_impuesto_interes_moratorio
            ")
            ->groupBy('po.branch_id', 'b.name', 'po.product_name')
            ->orderBy('b.name')
            ->orderByDesc('cartera')
            ->get();

        return $rows->map(fn ($r) => [
            'branch'                        => $r->branch,
            'product'                       => $r->product,
            'contratos'                     => (int) $r->contratos,
            'cartera'                       => (float) $r->cartera,
            'vencida'                       => (float) $r->vencida,
            'capital_atrasado'              => (float) $r->capital_atrasado,
            'interes_atrasado'              => (float) $r->interes_atrasado,
            'impuesto_atrasado'             => (float) $r->impuesto_atrasado,
            'saldo_interes_moratorio'       => (float) $r->saldo_interes_moratorio,
            'saldo_impuesto_interes_moratorio' => (float) $r->saldo_impuesto_interes_moratorio,
            'mora'                          => (float) $r->cartera > 0 ? round((float) $r->vencida / (float) $r->cartera * 100, 2) : 0.0,
        ])->values()->all();
    }

    /**
     * One row per active loan/contract (fact_portfolios), for the PRESTAMOS
     * ACTIVOS Excel sheet. Only real fact_portfolios columns are used — there
     * is no fecha_otorgamiento/fecha_vencimiento anywhere in the schema, so
     * those are intentionally not included. Bucketing reuses moraBucketDefs()
     * so it is identical to every other mora bucket in the workbook.
     */
    private function buildActiveLoans(Period $period): array
    {
        $realBranchNames = $this->resolveRealBranchNormalizedNames();
        $defs = $this->moraBucketDefs();

        $rows = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->when(!empty($realBranchNames), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realBranchNames))
            ->selectRaw("
                b.name as branch,
                po.client_name,
                po.contract,
                po.product_name,
                COALESCE(NULLIF(po.promoter_name,''), NULLIF(po.promoter_code,''), 'Sin gestor') as gestor,
                po.route_name,
                po.periodicity,
                po.days_past_due,
                po.balance,
                po.capital_activo,
                po.past_due_balance,
                po.capital_due
            ")
            ->orderBy('b.name')
            ->orderByDesc('po.days_past_due')
            ->orderByDesc('po.balance')
            ->get();

        return $rows->map(function ($r) use ($defs) {
            $dpd    = (int) $r->days_past_due;
            $bucket = 'Al corriente';
            foreach ($defs as $d) {
                if ($dpd >= $d['min'] && $dpd <= $d['max']) {
                    $bucket = $d['label'];
                    break;
                }
            }
            return [
                'sucursal'        => $r->branch,
                'cliente'         => $r->client_name,
                'contrato'        => $r->contract,
                'producto'        => $r->product_name,
                'gestor'          => $r->gestor,
                'ruta'            => $r->route_name,
                'periodicidad'    => $r->periodicity,
                'dias_vencidos'   => $dpd,
                'bucket_mora'     => $bucket,
                'saldo_activo'    => (float) $r->balance,
                'capital_activo'  => (float) ($r->capital_activo ?? 0),
                'vencido'         => (float) ($r->past_due_balance ?? 0),
                'capital_vencido' => (float) ($r->capital_due ?? 0),
                'estado'          => $dpd === 0 ? 'Al corriente' : 'En mora',
            ];
        })->values()->all();
    }

    /**
     * Portfolio mora buckets broken down per real branch.
     * Used for the MORA Excel tab.
     */
    // Fuente ÚNICA: deriva de BranchRadiographyCalculator (mismas 13 sucursales,
    // mismo balance/Saldo actual, mismos buckets que cartera total y mora total).
    private function buildMoraByBranch(Period $period): array
    {
        $result = [];
        foreach ($this->branchCalcBranches as $branch) {
            // cartera_vencida = SUM(5 columnas vencidas) donde days_past_due>0 (fórmula definitiva)
            $vencida = (float) ($branch['cartera_vencida'] ?? 0);
            $cartera = (float) ($branch['valor_cartera']   ?? 0);
            if ($cartera == 0.0 && $vencida == 0.0) {
                continue;
            }
            $result[] = [
                'branch'         => $branch['sucursal'],
                'cartera_total'  => $cartera,
                'vencida_total'  => $vencida,
                'al_corriente'   => $cartera - $vencida,
                'mora_1_30'      => (float) ($branch['mora_0_30']    ?? 0),
                'mora_31_60'     => (float) ($branch['mora_31_60']   ?? 0),
                'mora_61_90'     => (float) ($branch['mora_61_90']   ?? 0),
                'mora_91_120'    => (float) ($branch['mora_91_120']  ?? 0),
                'mora_120_plus'  => (float) ($branch['mora_120_plus'] ?? 0),
            ];
        }

        usort($result, fn ($a, $b) => $b['cartera_total'] <=> $a['cartera_total']);
        return $result;
    }

    /**
     * Fondeos entre sucursales (Préstamos Intersucursales): solo rastreo de flujo, NO afecta EBITDA.
     * Mismo query que reportes:audit-fondeos.
     */
    private function buildFondeoDetalle(): array
    {
        // Alias map: texto normalizado (sin acentos) → nombre oficial
        static $branchAliases = [
            'CORDOBA'           => 'CÓRDOBA',
            'SAN JUAN DEL RIO'  => 'SAN JUAN DEL RÍO',
            'SAN JUAN'          => 'SAN JUAN DEL RÍO',
            'SAN LUIS POTOSI'   => 'SAN LUIS POTOSÍ',
            'TENANGO'           => 'TENANGO DEL VALLE',
            'MIACATLAN'         => 'MIACATLÁN',
            'ATLACOMULCO'       => 'ATLACOMULCO',
            'ATLIXCO'           => 'ATLIXCO',
            'CUERNAVACA'        => 'CUERNAVACA',
            'HUAMANTLA'         => 'HUAMANTLA',
            'IXTLAHUACA'        => 'IXTLAHUACA',
            'ORIZABA'           => 'ORIZABA',
            'TENANGO DEL VALLE' => 'TENANGO DEL VALLE',
            'TLAXCALA'          => 'TLAXCALA',
            'TULA'              => 'TULA',
        ];

        $normalize = static function (string $v): string {
            return strtr(mb_strtoupper(trim($v)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        };

        // Solo fuente PDF (gastos_lendus). El Excel complementario (gastos_lendus_excel)
        // se usa en buildInterbranchLoans() para el cruce destino. Aquí mostramos un registro
        // por movimiento con origen ya resuelto, sin duplicados PDF+Excel.
        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->where('e.category', 'Préstamos Intersucursales')
            ->where('ds.code', 'gastos_lendus')
            ->select(
                'e.id',
                // Origen: branch resuelto en import; fallback a branch_origen del bloque PDF
                DB::raw("COALESCE(b.name, JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload, '$.branch_origen'))) as sucursal_origen"),
                'e.observations',
                'e.expense_date as fecha',
                'e.amount',
                'e.paid_amount',
                'ds.code as fuente',
                // Destino: PDF usa fondeo_destino_sucursal; fallback al concepto raw si ambos fallan
                DB::raw("COALESCE(
                    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload, '$.fondeo_destino_sucursal')), 'No detectado'), 'null'),
                    NULLIF(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload, '$.branch_to_detected')), 'No detectado'), 'null')
                ) as sucursal_destino_raw"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload, '$.fondeo_destino_texto')) as destino_texto"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload, '$.justification')) as justification"),
            )
            ->orderBy('b.name')
            ->orderByDesc('e.amount')
            ->get();

        $detail = [];
        $total  = 0.0;
        foreach ($rows as $row) {
            $monto = (float) ($row->paid_amount ?: $row->amount);
            $total += $monto;

            // Normalize origen
            $origen = (string) ($row->sucursal_origen ?? '');
            if ($origen === '' || $origen === 'null') {
                $origen = '(sin sucursal)';
            } else {
                $norm = $normalize($origen);
                $origen = $branchAliases[$norm] ?? $origen;
            }

            // Normalize destino — try raw field first, then scan free text
            $destino = (string) ($row->sucursal_destino_raw ?? '');
            if ($destino === '' || $destino === 'null') {
                $textRaw = $normalize(
                    (string)($row->destino_texto ?? '') . ' ' .
                    (string)($row->observations ?? '') . ' ' .
                    (string)($row->justification ?? '')
                );
                $resolved = null;
                foreach ($branchAliases as $keyword => $official) {
                    if (str_contains($textRaw, $keyword)) {
                        $resolved = $official;
                        break;
                    }
                }
                $destino = $resolved ?? '—';
            } else {
                $norm = $normalize($destino);
                $destino = $branchAliases[$norm] ?? $destino;
            }

            // El empleado/responsable del PDF viene en observations (campo employee del PDF)
            $responsable = (string) ($row->observations ?? '');

            $detail[] = [
                'sucursal_origen'  => $origen,
                'sucursal_destino' => $destino,
                'responsable'      => $responsable,
                'monto'            => $monto,
                'observacion'      => (string) ($row->destino_texto ?? ''),
                'fecha'            => $row->fecha,
                'fuente'           => $row->fuente,
            ];
        }

        return ['total' => $total, 'detalle' => $detail];
    }

    private function buildCorporateFunding(Period $period): array
    {
        $realBranchNames = $this->resolveRealBranchNormalizedNames();

        $rows = DB::table('fact_expenses as e')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->whereRaw("LOWER(COALESCE(e.concept,'')) LIKE '%excedente%'")
            ->whereNotNull('e.expense_date')
            ->when(!empty($realBranchNames), fn ($q) => $q->whereIn(DB::raw("LOWER(COALESCE(b.name,''))"), $realBranchNames))
            ->selectRaw("b.name as branch, e.expense_date, SUM(e.amount) as total")
            ->groupBy('b.name', 'e.expense_date')
            ->get();

        $month       = (int)($period->month ?? (int)date('n'));
        $year        = (int)($period->year  ?? (int)date('Y'));
        $daysInMonth = (int)date('t', mktime(0, 0, 0, $month, 1, $year));
        $dayNames    = ['DOMINGO', 'LUNES', 'MARTES', 'MIÉRCOLES', 'JUEVES', 'VIERNES', 'SÁBADO'];

        $amountByDay = [];
        $byBranch    = [];
        foreach ($rows as $row) {
            $day = (int)date('j', strtotime($row->expense_date));
            $amountByDay[$day] = ($amountByDay[$day] ?? 0.0) + (float)$row->total;
            if ($row->branch) {
                $byBranch[$row->branch] = ($byBranch[$row->branch] ?? 0.0) + (float)$row->total;
            }
        }

        $byDay = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $ts      = mktime(0, 0, 0, $month, $d, $year);
            $byDay[] = [
                'day'     => $d,
                'weekday' => $dayNames[(int)date('w', $ts)],
                'total'   => $amountByDay[$d] ?? 0.0,
            ];
        }

        $byBranchResult = [];
        foreach ($byBranch as $branch => $total) {
            $byBranchResult[] = ['branch' => $branch, 'total' => $total];
        }
        usort($byBranchResult, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'total'     => array_sum($amountByDay),
            'by_day'    => $byDay,
            'by_branch' => $byBranchResult,
        ];
    }

    private function buildPlacementByBranchProduct(Period $period): array
    {
        // Use the same operative branch map as BranchRadiographyCalculator so that
        // routes (CUITLAHUAC → TEPIC, etc.) are resolved and the total matches the KPI.
        $maps        = $this->branchCalculator->buildBranchMap();
        $operativeMap = $maps['operative'];  // branch_id => real_sucursal_name

        if (empty($operativeMap)) {
            return [];
        }

        $operativeIds = array_keys($operativeMap);

        // Misma regla que BranchRadiographyCalculator::accumulateColocacion():
        // solo DESEMBOLSO y REFINANCIAMIENTO (excluye REESTRUCTURACIÓN, UNIFICACIÓN).
        $rows = DB::table('fact_placements as p')
            ->whereIn('p.period_id', $this->dataIds)
            ->whereIn('p.branch_id', $operativeIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
            ->selectRaw("
                p.branch_id,
                COALESCE(NULLIF(TRIM(p.product_name), ''), 'Sin producto') as product,
                COUNT(*) as creditos,
                SUM(p.amount) as monto
            ")
            ->groupBy('p.branch_id', 'p.product_name')
            ->having('monto', '>', 0)
            ->orderByDesc('monto')
            ->get();

        return $rows->map(fn ($r) => [
            'branch'   => $operativeMap[(int)$r->branch_id] ?? 'Sin sucursal',
            'product'  => $r->product,
            'creditos' => (int)$r->creditos,
            'monto'    => (float)$r->monto,
            'apertura' => 0.0,
        ])->values()->all();
    }

    // ── ROTACIÓN DE PERSONAL ─────────────────────────────────────────────────
    //
    // Prioridad: datos del archivo XLSX importado (fact_rotacion).
    // Fallback: cálculo derivado de NOI (comparación entre periodos).

    private function buildRotationData(Period $period): array
    {
        // Try uploaded Excel data first
        $xlsxRows = DB::table('fact_rotacion as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->whereIn('fr.period_id', $this->dataIds)
            ->select(
                'fr.sucursal_nombre',
                'fr.branch_id',
                'fr.mes',
                'fr.bajas',
                'fr.promedio_personal',
                'fr.indice_rotacion',
                'fr.hoja_fuente',
                'fr.raw_payload',
                'b.name as branch_name',
            )
            ->orderBy('fr.sucursal_nombre')
            ->get();

        if ($xlsxRows->isNotEmpty()) {
            $totalBajas    = $xlsxRows->sum('bajas');
            $totalPromedio = $xlsxRows->sum('promedio_personal');
            $mesUsado      = $xlsxRows->pluck('mes')->unique()->first() ?? '';
            $indiceGlobal  = $totalPromedio > 0
                ? round($totalBajas / $totalPromedio * 100, 2)
                : round($xlsxRows->avg('indice_rotacion') ?? 0.0, 2);

            $porSucursal = $xlsxRows->map(function ($r) {
                $payload = json_decode((string)($r->raw_payload ?? '{}'), true) ?? [];
                return [
                    'sucursal'          => $r->sucursal_nombre,
                    'altas'             => (int) ($payload['altas'] ?? 0),
                    'bajas'             => (int) $r->bajas,
                    'promedio_personal' => (float) $r->promedio_personal,
                    'indice_rotacion'   => (float) $r->indice_rotacion,
                    'mes'               => $r->mes,
                ];
            })->values()->all();

            $totalAltas = array_sum(array_column($porSucursal, 'altas'));

            return [
                'fuente'        => 'xlsx',
                'mes'           => $mesUsado,
                'altas'         => (int) $totalAltas,
                'bajas'         => (int) $totalBajas,
                'promedio'      => round((float) $totalPromedio, 2),
                'indice'        => $indiceGlobal,
                'current_count' => 0,
                'prev_count'    => 0,
                'por_sucursal'  => $porSucursal,
            ];
        }

        // Fallback: derive from NOI movements (compare prev vs current period)
        $allPeriods = Period::all();

        $currentEmps = DB::table('fact_noi_movements')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->toArray();
        $currentCount = count($currentEmps);

        $prevPeriod = $allPeriods
            ->filter(fn ($p) => $p->id < $period->id)
            ->sortByDesc('id')
            ->first();

        $bajas     = 0;
        $prevCount = 0;

        if ($prevPeriod) {
            $prevWeeklyIds = $prevPeriod->resolveBaseWeeklyIds($allPeriods);
            $prevDataIds   = array_values(array_unique(array_merge(
                empty($prevWeeklyIds) ? [] : $prevWeeklyIds,
                [$prevPeriod->id]
            )));

            $prevEmps = DB::table('fact_noi_movements')
                ->whereIn('period_id', $prevDataIds)
                ->whereNotNull('employee_id')
                ->distinct()
                ->pluck('employee_id')
                ->toArray();
            $prevCount  = count($prevEmps);
            $currentSet = array_flip($currentEmps);
            foreach ($prevEmps as $emp) {
                if (!isset($currentSet[$emp])) {
                    $bajas++;
                }
            }
        }

        $promedio = $prevCount > 0 ? (int) round(($prevCount + $currentCount) / 2) : $currentCount;
        $indice   = $promedio > 0 ? round($bajas / $promedio * 100, 2) : 0.0;

        return [
            'fuente'        => 'noi',
            'mes'           => '',
            'bajas'         => $bajas,
            'promedio'      => $promedio,
            'indice'        => $indice,
            'current_count' => $currentCount,
            'prev_count'    => $prevCount,
            'por_sucursal'  => [],
        ];
    }

    // ── HELPERS ──────────────────────────────────────────────────────────────

    private function resolveRealBranchNormalizedNames(): array
    {
        static $names = null;
        if ($names === null) {
            try {
                $resolver = app(BranchResolverService::class);
                // Only the 13 operative financial branches — excludes AGUASCALIENTES,
                // CHIHUAHUA, DURANGO, CORPORATIVO from cartera/mora/colocación/recuperación.
                $names = array_map(
                    fn ($n) => $this->normalizeText($n),
                    $resolver->operativeFinancialBranches()
                );
                $names = array_values(array_unique($names));
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

    private function resolveBranch(?int $id): ?Branch
    {
        if (!$id) return null;
        if (!array_key_exists($id, $this->branchCache)) {
            $this->branchCache[$id] = Branch::query()->find($id);
        }
        return $this->branchCache[$id];
    }

    // ── MORA DETALLADA ───────────────────────────────────────────────────────

    private function moraBucketDefs(): array
    {
        return [
            ['key' => 'al_corriente', 'label' => 'Al corriente',  'min' => 0,   'max' => 0     ],
            ['key' => 'mora_1_30',    'label' => 'Mora 1-30',     'min' => 1,   'max' => 30    ],
            ['key' => 'mora_31_60',   'label' => 'Mora 31-60',    'min' => 31,  'max' => 60    ],
            ['key' => 'mora_61_90',   'label' => 'Mora 61-90',    'min' => 61,  'max' => 90    ],
            ['key' => 'mora_91_120',  'label' => 'Mora 91-120',   'min' => 91,  'max' => 120   ],
            ['key' => 'mora_120_plus','label' => 'Mora 120+',     'min' => 121, 'max' => 99999 ],
        ];
    }

    private function buildMoraByProduct(Period $period): array
    {
        $realBranchNames = $this->resolveRealBranchNormalizedNames();
        $defs = $this->moraBucketDefs();

        $rows = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->whereNotNull('po.product_name')
            ->when(!empty($realBranchNames), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realBranchNames))
            ->selectRaw('
                po.product_name as product,
                po.days_past_due,
                COUNT(*) as contratos,
                SUM(po.balance) as balance,
                SUM(CASE WHEN po.days_past_due > 0 THEN
                    COALESCE(po.capital_due, 0) + COALESCE(po.interes_atrasado, 0) + COALESCE(po.impuesto_atrasado, 0)
                    + COALESCE(po.saldo_interes_moratorio, 0) + COALESCE(po.saldo_impuesto_interes_moratorio, 0)
                ELSE 0 END) as vencida_5col
            ')
            ->groupBy('po.product_name', 'po.days_past_due')
            ->get();

        $byProduct = [];
        foreach ($rows as $row) {
            $product    = $row->product;
            $dpd        = (int) $row->days_past_due;
            $bal        = (float) $row->balance;
            $vencida5   = (float) $row->vencida_5col;
            $cnt        = (int) $row->contratos;

            foreach ($defs as $def) {
                if ($dpd >= $def['min'] && $dpd <= $def['max']) {
                    $amount = $def['key'] === 'al_corriente' ? $bal : $vencida5;
                    $byProduct[$product]['contratos']        = ($byProduct[$product]['contratos']        ?? 0) + $cnt;
                    $byProduct[$product][$def['key']]        = ($byProduct[$product][$def['key']]        ?? 0.0) + $amount;
                    $byProduct[$product]['balance_total']    = ($byProduct[$product]['balance_total']    ?? 0.0) + $bal;
                    break;
                }
            }
        }

        $result = [];
        foreach ($byProduct as $product => $data) {
            $vencida = array_sum(array_filter($data, fn ($v, $k) => !in_array($k, ['al_corriente', 'contratos', 'balance_total'], true), ARRAY_FILTER_USE_BOTH));
            $cartera = (float) ($data['balance_total'] ?? 0);
            $row = [
                'product'   => $product,
                'contratos' => (int) ($data['contratos'] ?? 0),
                'cartera'   => $cartera,
                'vencida'   => $vencida,
                'mora_pct'  => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0,
            ];
            foreach ($defs as $def) {
                $row[$def['key']] = $data[$def['key']] ?? 0.0;
            }
            $result[] = $row;
        }

        usort($result, fn ($a, $b) => $b['cartera'] <=> $a['cartera']);
        return $result;
    }

    private function buildMoraByGestor(Period $period): array
    {
        $realBranchNames = $this->resolveRealBranchNormalizedNames();
        $defs = $this->moraBucketDefs();

        $rows = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->where(fn ($q) => $q->whereNotNull('po.promoter_name')->orWhereNotNull('po.promoter_code'))
            ->when(!empty($realBranchNames), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realBranchNames))
            ->selectRaw('
                COALESCE(po.promoter_name, po.promoter_code, \'Sin nombre\') as gestor,
                b.name as sucursal,
                po.days_past_due,
                COUNT(*) as contratos,
                SUM(po.balance) as balance,
                SUM(CASE WHEN po.days_past_due > 0 THEN
                    COALESCE(po.capital_due, 0) + COALESCE(po.interes_atrasado, 0) + COALESCE(po.impuesto_atrasado, 0)
                    + COALESCE(po.saldo_interes_moratorio, 0) + COALESCE(po.saldo_impuesto_interes_moratorio, 0)
                ELSE 0 END) as vencida_5col
            ')
            ->groupBy('po.promoter_name', 'po.promoter_code', 'b.name', 'po.days_past_due')
            ->get();

        $byGestor = [];
        foreach ($rows as $row) {
            $key      = $row->gestor . '|||' . ($row->sucursal ?? '');
            $dpd      = (int) $row->days_past_due;
            $bal      = (float) $row->balance;
            $vencida5 = (float) $row->vencida_5col;
            $cnt      = (int) $row->contratos;

            if (!isset($byGestor[$key])) {
                $byGestor[$key] = ['gestor' => $row->gestor, 'sucursal' => $row->sucursal, 'contratos' => 0, 'balance_total' => 0.0];
            }

            foreach ($defs as $def) {
                if ($dpd >= $def['min'] && $dpd <= $def['max']) {
                    $amount = $def['key'] === 'al_corriente' ? $bal : $vencida5;
                    $byGestor[$key][$def['key']]     = ($byGestor[$key][$def['key']]     ?? 0.0) + $amount;
                    $byGestor[$key]['contratos']     += $cnt;
                    $byGestor[$key]['balance_total'] += $bal;
                    break;
                }
            }
        }

        $result = [];
        foreach ($byGestor as $data) {
            $vencida = array_sum(array_filter($data, fn ($v, $k) => !in_array($k, ['al_corriente', 'contratos', 'balance_total', 'gestor', 'sucursal'], true), ARRAY_FILTER_USE_BOTH));
            $cartera = (float) ($data['balance_total'] ?? 0);
            $row = [
                'gestor'    => $data['gestor'],
                'sucursal'  => $data['sucursal'],
                'contratos' => (int) ($data['contratos'] ?? 0),
                'cartera'   => $cartera,
                'vencida'   => $vencida,
                'mora_pct'  => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0,
            ];
            foreach ($defs as $def) {
                $row[$def['key']] = $data[$def['key']] ?? 0.0;
            }
            $result[] = $row;
        }

        usort($result, fn ($a, $b) => strcmp($a['sucursal'] ?? '', $b['sucursal'] ?? '') ?: $b['cartera'] <=> $a['cartera']);
        return $result;
    }

    private function buildMoraByBranchProduct(Period $period): array
    {
        $realBranchNames = $this->resolveRealBranchNormalizedNames();
        $defs = $this->moraBucketDefs();

        $rows = DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $this->dataIds)
            ->whereNotNull('po.product_name')
            ->when(!empty($realBranchNames), fn ($q) => $q->whereIn(DB::raw('LOWER(b.name)'), $realBranchNames))
            ->selectRaw('
                b.name as branch,
                po.product_name as product,
                po.days_past_due,
                COUNT(*) as contratos,
                SUM(po.balance) as balance,
                SUM(COALESCE(po.past_due_balance, 0)) as past_due_balance
            ')
            ->groupBy('b.id', 'b.name', 'po.product_name', 'po.days_past_due')
            ->get();

        $byBranchProduct = [];
        foreach ($rows as $row) {
            $key = $row->branch . '|||' . $row->product;
            $dpd = (int) $row->days_past_due;
            $bal = (float) $row->balance;
            $pdb = (float) $row->past_due_balance;
            $cnt = (int) $row->contratos;

            if (!isset($byBranchProduct[$key])) {
                $byBranchProduct[$key] = ['branch' => $row->branch, 'product' => $row->product, 'contratos' => 0, 'balance_total' => 0.0];
            }

            foreach ($defs as $def) {
                if ($dpd >= $def['min'] && $dpd <= $def['max']) {
                    $amount = $def['key'] === 'al_corriente' ? $bal : ($pdb > 0 ? $pdb : $bal);
                    $byBranchProduct[$key][$def['key']]     = ($byBranchProduct[$key][$def['key']]     ?? 0.0) + $amount;
                    $byBranchProduct[$key]['contratos']     += $cnt;
                    $byBranchProduct[$key]['balance_total'] += $bal;
                    break;
                }
            }
        }

        $result = [];
        foreach ($byBranchProduct as $data) {
            $vencida = array_sum(array_filter($data, fn ($v, $k) => !in_array($k, ['al_corriente', 'contratos', 'balance_total', 'branch', 'product'], true), ARRAY_FILTER_USE_BOTH));
            $cartera = (float) ($data['balance_total'] ?? 0);
            $row = [
                'branch'    => $data['branch'],
                'product'   => $data['product'],
                'contratos' => (int) ($data['contratos'] ?? 0),
                'cartera'   => $cartera,
                'vencida'   => $vencida,
                'mora_pct'  => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0,
            ];
            foreach ($defs as $def) {
                $row[$def['key']] = $data[$def['key']] ?? 0.0;
            }
            $result[] = $row;
        }

        usort($result, fn ($a, $b) => strcmp($a['branch'], $b['branch']) ?: $b['cartera'] <=> $a['cartera']);
        return $result;
    }

    private function ebitdaCategory(float $ebitda): string
    {
        return match (true) {
            $ebitda >= 1_000_000 => 'DIAMANTE',
            $ebitda >= 600_000   => 'MASTER',
            $ebitda >= 300_000   => 'SENIOR',
            $ebitda >= 100_000   => 'JUNIOR',
            default              => 'MANTENIDO',
        };
    }

    // ── Efectividad de Cobranza ───────────────────────────────────────────────
    //
    // Clasifica los cobros del período por estatus del crédito:
    //   Vigente   → days_past_due = 0 (o sin cartera conocida)
    //   Atrasado  → days_past_due 1-90
    //   Vencido   → days_past_due > 90
    //
    // Fuentes: fact_recoveries (cobros) + fact_portfolios (DPD del crédito).
    // Se aplican las mismas exclusiones que en recuperacion_total:
    //   - is_savehearts = 1 (seguros)
    //   - transaction = 'CONDONACION'
    //   - Conceptos COBERTURA/SEGURO en is_savehearts=0

    private function buildEfectividadCobranza(Period $period): array
    {
        $dataIds = $this->dataIds;

        // DPD autoritativo por contrato (máximo en el período — si hay varios registros)
        $portfolioDpd = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('contract')
            ->selectRaw('contract, MAX(days_past_due) as dpd')
            ->groupBy('contract')
            ->pluck('dpd', 'contract')
            ->all();

        // Recoveries del período, con las mismas exclusiones que recuperacion_total
        $recoveries = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->where(function ($q) {
                $q->where('r.is_savehearts', '!=', 1)
                  ->where('r.transaction', '!=', 'CONDONACION')
                  ->where(function ($q2) {
                      $q2->whereRaw("NOT (r.is_savehearts = 0 AND (
                          UPPER(COALESCE(r.concept,'')) LIKE '%COBERTURA%'
                          OR UPPER(COALESCE(r.operation,'')) LIKE '%COBERTURA%'
                          OR UPPER(COALESCE(r.concept,'')) LIKE '%SEGURO%'
                          OR UPPER(COALESCE(r.operation,'')) LIKE '%SEGURO%'
                      ))");
                  });
            })
            ->whereNotNull('r.contract')
            ->select(
                'r.contract',
                'r.days_past_due as recovery_dpd',
                'b.name as sucursal',
                DB::raw('COALESCE(r.capital, 0) as capital'),
                DB::raw('COALESCE(r.interest, 0) as interes'),
                DB::raw('COALESCE(r.tax, 0) as impuesto'),
                DB::raw('COALESCE(r.charges_due, 0) as moratorios'),
                DB::raw('COALESCE(r.total_amount, 0) as total'),
            )
            ->get();

        $empty = fn () => ['capital' => 0.0, 'interes' => 0.0, 'impuesto' => 0.0, 'moratorios' => 0.0, 'total' => 0.0, 'contratos' => 0];
        $buckets = [
            'vigente'  => $empty(),
            'atrasado' => $empty(),
            'vencido'  => $empty(),
        ];

        $contractsSeen = ['vigente' => [], 'atrasado' => [], 'vencido' => []];

        foreach ($recoveries as $row) {
            $contract = (string) $row->contract;
            // Prefer recovery's own DPD, fall back to portfolio DPD
            $dpd = $row->recovery_dpd !== null
                ? (int) $row->recovery_dpd
                : ($portfolioDpd[$contract] ?? null);

            // DPD null → tratar como vigente (sin evidencia de atraso).
            // Estos registros quedan en auditoría audit-efectividad-cobranza.
            $status = match (true) {
                $dpd === null => 'vigente',
                $dpd === 0    => 'vigente',
                $dpd <= 90    => 'atrasado',
                default       => 'vencido',
            };

            $buckets[$status]['capital']    += (float) $row->capital;
            $buckets[$status]['interes']    += (float) $row->interes;
            $buckets[$status]['impuesto']   += (float) $row->impuesto;
            $buckets[$status]['moratorios'] += (float) $row->moratorios;
            $buckets[$status]['total']      += (float) $row->total;
            if (!isset($contractsSeen[$status][$contract])) {
                $contractsSeen[$status][$contract] = true;
                $buckets[$status]['contratos']++;
            }
        }

        $total = $empty();
        foreach ($buckets as $b) {
            $total['capital']    += $b['capital'];
            $total['interes']    += $b['interes'];
            $total['impuesto']   += $b['impuesto'];
            $total['moratorios'] += $b['moratorios'];
            $total['total']      += $b['total'];
            $total['contratos']  += $b['contratos'];
        }

        return array_merge($buckets, ['total' => $total]);
    }
}
