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
    private array $closingDataIds     = [];
    private array $branchCalcGlobal   = [];
    private array $branchCalcBranches = [];

    public function __construct(
        private readonly EmployeeNameCanonicalizer $canonicalizer,
        private readonly BranchRadiographyCalculator $branchCalculator,
    ) {}

    public function build(Period $period, PeriodSummary $summary, array $config = []): array
    {
        $this->branchCache    = [];
        $this->dataIds        = $this->resolveDataIds($period);
        $this->closingDataIds = $this->resolveClosingDataIds($period);
        $includedBranchIds    = $config['included_branch_ids'] ?? [];

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

        // Empleados NOI activos sin sucursal resuelta afectan directamente IMSS y Rotación
        // derivados — nunca se ocultan en "sin asignar" silenciosamente (regla 2026-07-23).
        $rosterSinSucursal = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('is_active_for_period', true)
            ->whereNull('branch_id')
            ->count();
        if ($rosterSinSucursal > 0) {
            $mergedIncidents[] = [
                'type'     => 'roster_sin_sucursal',
                'severity' => 'critical',
                'message'  => "{$rosterSinSucursal} colaborador(es) activo(s) en NOI sin sucursal resuelta (ni periodo actual ni histórico) — excluidos de IMSS y Rotación derivados. Ver hoja IMSS / pestaña ROTACIÓN, sección auditoría.",
            ];
        }

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
        $branchCalcResult   = $this->branchCalculator->buildBranches($period, $this->dataIds, $includedBranchIds, $this->closingDataIds);
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
        $carteraGlobalSinFiltro = $this->branchCalculator->computeCarteraGlobalSinFiltro($this->closingDataIds);
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

        // ── EBITDA, Ingreso base EBITDA y Margen EBITDA — CRITERIO FINAL (2026-07) ──────
        // Percepciones/Deducciones/Neto pagado: cifra informativa de "lo que el trabajador
        // recibió", distinta de Nómina y Capital Humano (que es un concepto de gasto de la
        // empresa). Las deducciones se muestran para explicar el neto — nunca se vuelven a
        // sumar/restar como gasto.
        $noiPercepDeducc = $this->branchCalculator->computeNoiPercepcionesDeducciones($this->dataIds);
        // Fuentes únicas — ver BranchRadiographyCalculator::nominaTotalFor()/ingresoEbitdaBaseFor()/
        // gastosTotalesFor()/ebitdaFinalFor()/margenEbitdaFor(). EBITDA NUNCA usa Recuperación
        // total (incluye capital recuperado, que no es ingreso real) ni Colocación ni saldo de
        // caja — únicamente Ingreso base EBITDA (intereses+impuestos+moratorios+comisión de
        // apertura+cargos adicionales+excedentes+30% Seguro CRECE) menos Gastos Totales (OPEX +
        // Nómina y Capital Humano).
        $nominaParaEbitda       = BranchRadiographyCalculator::nominaTotalFor($branchCalcGlobal);
        $opexParaEbitda         = (float) ($branchCalcGlobal['gastos_operativos'] ?? 0);
        $totalGastosEbitda      = BranchRadiographyCalculator::gastosTotalesFor($branchCalcGlobal);
        $ingresoEbitdaBase      = BranchRadiographyCalculator::ingresoEbitdaBaseFor($branchCalcGlobal);
        $ebitdaGlobal           = BranchRadiographyCalculator::ebitdaFinalFor($branchCalcGlobal);
        $capitalRecuperado      = (float) ($branchCalcGlobal['capital_recuperado'] ?? 0);
        $impuestoRecuperado     = (float) ($branchCalcGlobal['impuesto_recuperado'] ?? 0);
        $interesRecuperado      = (float) ($branchCalcGlobal['interes_recuperado'] ?? 0);
        $cargosInicioComision   = (float) ($branchCalcGlobal['comision_apertura'] ?? 0);
        $cargosCalendario       = (float) ($branchCalcGlobal['cargos_adicionales'] ?? 0);
        $cargosVencimiento      = (float) ($branchCalcGlobal['charges'] ?? 0);
        $excedenteRecuperado    = (float) ($branchCalcGlobal['excedente_recuperado'] ?? 0);
        $seguroCreceReconocido  = (float) ($branchCalcGlobal['seguro_crece_reconocido'] ?? 0);
        // "Venta"/"ingreso base EBITDA" — mismo valor, dos nombres (venta_global se conserva
        // para no romper consumidores existentes; ingreso_ebitda_base es el nombre canónico).
        $ventaGlobal            = $ingresoEbitdaBase;
        $margenEbitda           = BranchRadiographyCalculator::margenEbitdaFor($branchCalcGlobal);

        $snapshot = [
            'period' => [
                'id'         => $period->id,
                'label'      => $period->label,
                'code'       => $period->code,
                'start_date' => optional($period->start_date)->format('d/m/Y'),
                'end_date'   => optional($period->end_date)->format('d/m/Y'),
                'composite'  => $this->buildPeriodCompositeMeta($period),
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
                // Percepciones/Deducciones/Neto pagado a trabajadores — informativo,
                // distinto de "Nómina y Capital Humano" (concepto de gasto de la empresa).
                'noi_percepciones'             => $noiPercepDeducc['percepciones'],
                'noi_deducciones'              => $noiPercepDeducc['deducciones'],
                'noi_neto_pagado'              => $noiPercepDeducc['neto_pagado'],
                'opex_total'                   => $opexParaEbitda,
                // "Nómina y Capital Humano" — nombre completo del KPI (gasto de la empresa,
                // NUNCA llamarlo solo "Nómina").
                'nomina_capital_humano_total'  => $nominaParaEbitda,
                'nomina_ebitda_total'          => $nominaParaEbitda,
                'total_gastos_ebitda'          => $totalGastosEbitda,
                'ebitda_global'                => $ebitdaGlobal,
                'venta_global'                 => $ventaGlobal,
                'margen_ebitda'                => $margenEbitda,
                'ebitda_categoria'             => $this->ebitdaCategory($ebitdaGlobal),
                // Nombres canónicos del criterio final (2026-07) — mismos valores que arriba,
                // expuestos con el nombre que UI/Excel/PDF deben mostrar literalmente:
                // "Ingreso base EBITDA" / "Gastos Totales" / "EBITDA" / "Margen EBITDA".
                'ingreso_ebitda_base'          => $ingresoEbitdaBase,
                'gastos_totales'               => $totalGastosEbitda,
                'ebitda_final'                 => $ebitdaGlobal,
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
                'recovery_by_product'        => $recoveryByProduct = $this->buildRecoveryByProduct($period),
                'portfolio_by_branch_product' => $this->buildPortfolioByBranchProduct($period),
                'mora_by_branch'             => $this->buildMoraByBranch($period),
                'mora_by_product'            => $this->buildMoraByProduct($period),
                'mora_by_gestor'             => $this->buildMoraByGestor($period),
                'mora_by_branch_product'     => $this->buildMoraByBranchProduct($period),
                'corporate_funding'          => $this->buildCorporateFunding($period),
                'fondeo_detalle'             => $this->buildFondeoDetalle(),
                'placement_by_branch_product'=> $this->buildPlacementByBranchProduct($period),
                'rotation'                   => $this->buildRotationData($period),
                'rotation_detail'            => $this->buildRotationDetail($period),
                'imss_meta'                  => $this->buildImssMeta($period),
                'imss'                       => $this->buildImssDetail($period),
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

        $this->assertRecoveryReconciles($branchCalcGlobal, $branchCalcBranches, $recoveryByProduct);

        // ── SCOPE (sucursal / colaborador) — proyección del snapshot ya calculado ──
        // Se aplica AL FINAL, después de que el snapshot general (y su guardia de cuadre)
        // ya están completos. Nunca reimporta ni recalcula desde cero: todo lo que sigue
        // reutiliza $branchCalcBranches/$empGestores/$this->dataIds ya resueltos arriba,
        // más un puñado de consultas puntuales ya acotadas a una sola sucursal/promotor
        // (equivalentes a lo que ya hacía RadiografiaExportService::resolveBranchRow()/
        // resolveEmployeeRow() para Excel/PDF — ahora es la MISMA función la que alimenta
        // Web/Excel/PDF, ver applyScope()). scope=general deja $snapshot intacto.
        $scope = $config['scope'] ?? 'general';
        if ($scope === 'branch' || $scope === 'employee') {
            $snapshot = $this->applyScope($snapshot, $config, $period, $empGestores, $gm);
        } else {
            $snapshot['scope'] = ['type' => 'general', 'branch_id' => null, 'branch_name' => null, 'employee_id' => null, 'employee_name' => null, 'available' => true];
        }

        return $snapshot;
    }

    // ════════════════════════════════════════════════════════════════════════════
    // SCOPE — proyecta el snapshot general ya construido a sucursal o colaborador.
    // Toda métrica NO atribuible con la granularidad disponible se expone como NULL
    // (nunca hereda silenciosamente el total general) y las secciones sin dimensión
    // de sucursal/colaborador se marcan explícitamente 'not_attributable' => true.
    // ════════════════════════════════════════════════════════════════════════════

    private function applyScope(array $snapshot, array $config, Period $period, array $empGestores, array $gm): array
    {
        $scope = $config['scope'];

        if ($scope === 'branch') {
            $branchId = (int) ($config['branch_id'] ?? 0);
            if (!$branchId) return $snapshot;
            return $this->applyBranchScope($snapshot, $branchId, $period);
        }

        if ($scope === 'employee') {
            $employeeId = (int) ($config['employee_id'] ?? 0);
            if (!$employeeId) return $snapshot;
            return $this->applyEmployeeScope($snapshot, $employeeId, $empGestores, $period);
        }

        return $snapshot;
    }

    private function eqName(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }

    /** Filtra un array de filas a las que coincidan (comparación insensible a mayúsculas) en cualquiera de los campos dados. */
    private function filterRowsByField(array $rows, array $fields, string $needle): array
    {
        $needle = $this->eqName($needle);
        return array_values(array_filter($rows, function ($row) use ($fields, $needle) {
            foreach ($fields as $field) {
                if (isset($row[$field]) && $this->eqName($row[$field]) === $needle) {
                    return true;
                }
            }
            return false;
        }));
    }

    private function notAttributable(string $reason = 'No se puede atribuir de forma inequívoca a este alcance.'): array
    {
        return ['not_attributable' => true, 'reason' => $reason];
    }

    /**
     * Alcance sin datos (sucursal/colaborador sin ningún movimiento este periodo). NUNCA se
     * deja pasar el summary/secciones generales — eso sería exactamente el "fallback
     * silencioso a total general" que la spec prohíbe. `scope.available=false` es la señal
     * que el frontend usa para mostrar un estado vacío en vez del dashboard.
     */
    private function emptyScopedSnapshot(array $snapshot): array
    {
        $snapshot['scope']['available'] = false;
        $snapshot['summary'] = array_map(fn () => null, $snapshot['summary']);
        $snapshot['branch_radiography']['global']     = $this->notAttributable('Sin datos para este alcance en el periodo.');
        $snapshot['branch_radiography']['branches']   = [];
        $snapshot['branch_radiography']['unassigned'] = $this->notAttributable();
        foreach (array_keys($snapshot['sections']) as $key) {
            $snapshot['sections'][$key] = is_array($snapshot['sections'][$key]) && array_is_list($snapshot['sections'][$key])
                ? []
                : $this->notAttributable('Sin datos para este alcance en el periodo.');
        }
        $snapshot['charts'] = array_map(fn () => [], $snapshot['charts']);

        return $snapshot;
    }

    private function applyBranchScope(array $snapshot, int $branchId, Period $period): array
    {
        $branch = Branch::find($branchId);
        if (!$branch) {
            $snapshot['scope'] = ['type' => 'branch', 'branch_id' => $branchId, 'branch_name' => null, 'employee_id' => null, 'employee_name' => null, 'available' => false];
            return $snapshot;
        }

        $name = $branch->name;
        $eq   = $this->eqName($name);

        $row = collect($snapshot['branch_radiography']['branches'] ?? [])
            ->first(fn ($b) => $this->eqName($b['sucursal'] ?? '') === $eq);

        $snapshot['scope'] = [
            'type' => 'branch', 'branch_id' => $branchId, 'branch_name' => $name,
            'employee_id' => null, 'employee_name' => null, 'available' => (bool) $row,
        ];

        if (!$row) {
            // Sucursal operativa válida pero sin ningún movimiento este periodo — NUNCA dejar
            // pasar el summary/secciones generales tal cual (eso sería el fallback silencioso
            // prohibido). Se limpia explícitamente para que el frontend muestre "sin datos".
            return $this->emptyScopedSnapshot($snapshot);
        }

        $percepDeducc = $this->branchCalculator->computeNoiPercepcionesDeduccionesForBranch($this->dataIds, $period->id, $branchId);

        $snapshot['summary'] = $this->summaryFromRow($row, $percepDeducc, null);
        $snapshot['branch_radiography']['global']   = $row;
        $snapshot['branch_radiography']['branches'] = [$row];
        $snapshot['branch_radiography']['unassigned'] = $this->notAttributable('Los montos "sin sucursal" son, por definición, ajenos a cualquier sucursal individual.');

        $s = $snapshot['sections'];
        $s['employees_gestores']         = $this->filterRowsByField($s['employees_gestores'] ?? [], ['branch'], $name);
        $s['mora_by_gestor']             = $this->filterRowsByField($s['mora_by_gestor'] ?? [], ['sucursal'], $name);
        $s['active_loans']               = $this->filterRowsByField($s['active_loans'] ?? [], ['sucursal'], $name);
        $s['portfolio_by_branch_product']= $this->filterRowsByField($s['portfolio_by_branch_product'] ?? [], ['branch'], $name);
        $s['placement_by_branch_product']= $this->filterRowsByField($s['placement_by_branch_product'] ?? [], ['branch'], $name);
        $s['mora_by_branch_product']     = $this->filterRowsByField($s['mora_by_branch_product'] ?? [], ['branch'], $name);
        $s['mora_by_branch']             = $this->filterRowsByField($s['mora_by_branch'] ?? [], ['sucursal', 'branch'], $name);

        $payrollData = $s['payroll_by_branch_concept']['data'] ?? [];
        $matchedPayrollKey = collect(array_keys($payrollData))->first(fn ($k) => $this->eqName($k) === $eq);
        $s['payroll_by_branch_concept'] = [
            'data'      => $matchedPayrollKey ? [$matchedPayrollKey => $payrollData[$matchedPayrollKey]] : [],
            'incidents' => [],
        ];

        $expByBranchRow = collect($s['expenses_detail']['byBranch'] ?? [])->first(fn ($r) => $this->eqName($r['sucursal'] ?? '') === $eq);
        $s['expenses_detail'] = [
            'total'      => (float) ($expByBranchRow['total'] ?? 0),
            'byBranch'   => $expByBranchRow ? [$expByBranchRow] : [],
            'byCategory' => null,
            'byConcept'  => null,
            'byEmployee' => null,
            'bySource'   => null,
            'detail_not_attributable' => 'El desglose por categoría/concepto/fuente solo está disponible a nivel general en este snapshot.',
        ];

        if (isset($s['imss']['colaboradores'])) {
            $s['imss'] = [
                'porSucursal'   => collect($s['imss']['porSucursal'] ?? [])->filter(fn ($r) => $this->eqName($r['sucursal'] ?? '') === $eq)->values()->all(),
                'colaboradores' => $this->filterRowsByField($s['imss']['colaboradores'], ['sucursal'], $name),
            ];
        }
        if (isset($s['rotation_detail'])) {
            foreach (['altas', 'bajas', 'activos', 'empleados_mes_actual', 'empleados_mes_anterior'] as $k) {
                if (isset($s['rotation_detail'][$k]) && is_array($s['rotation_detail'][$k])) {
                    $s['rotation_detail'][$k] = $this->filterRowsByField($s['rotation_detail'][$k], ['sucursal'], $name);
                }
            }
        }

        foreach (['interbranch_loans', 'corporate_funding', 'fondeo_detalle'] as $k) {
            if (isset($s[$k])) {
                $s[$k] = $this->notAttributable('Transferencia entre sucursales/corporativo — no se puede atribuir de forma inequívoca a una sola sucursal.');
            }
        }

        $snapshot['sections'] = $s;
        $snapshot['charts']   = $this->scopeChartsByLabel($snapshot['charts'], $name);

        return $snapshot;
    }

    private function applyEmployeeScope(array $snapshot, int $employeeId, array $empGestores, Period $period): array
    {
        $employee = Employee::find($employeeId);
        if (!$employee) {
            $snapshot['scope'] = ['type' => 'employee', 'branch_id' => null, 'branch_name' => null, 'employee_id' => $employeeId, 'employee_name' => null, 'available' => false];
            return $snapshot;
        }

        $target = $this->canonicalizer->normalize($employee->full_name ?? '');
        $row = null;
        foreach ($empGestores as $e) {
            if ($this->canonicalizer->normalize($e['name'] ?? '') === $target) { $row = $e; break; }
        }
        if (!$row) {
            foreach ($empGestores as $e) {
                $norm = $this->canonicalizer->normalize($e['name'] ?? '');
                if ($norm && (str_contains($norm, $target) || str_contains($target, $norm))) { $row = $e; break; }
            }
        }

        $branchName = $row['branch'] ?? null;
        $snapshot['scope'] = [
            'type' => 'employee', 'branch_id' => null, 'branch_name' => $branchName,
            'employee_id' => $employeeId, 'employee_name' => $employee->full_name, 'available' => (bool) $row,
        ];

        if (!$row) {
            return $this->emptyScopedSnapshot($snapshot);
        }

        $percepDeducc = $this->branchCalculator->computeNoiPercepcionesDeduccionesForEmployee($this->dataIds, $employeeId);

        $snapshot['summary'] = $this->summaryFromRow($row, $percepDeducc, $row);
        $snapshot['branch_radiography']['global']     = $this->notAttributable('El colaborador no representa una sucursal completa — ver sections.employees_gestores.');
        $snapshot['branch_radiography']['branches']   = [];
        $snapshot['branch_radiography']['unassigned'] = $this->notAttributable();

        $s = $snapshot['sections'];
        $s['employees_gestores'] = [$row];
        $s['mora_by_gestor']     = $this->filterRowsByField($s['mora_by_gestor'] ?? [], ['gestor'], $row['name']);

        // Colocación por producto — solo la del promotor (fact_placements), consulta puntual
        // acotada a un promoter_name, no un recálculo general.
        $s['products'] = $this->buildProductsForPromoter($employee->full_name);

        $expByEmployeeRow = collect($s['expenses_detail']['byEmployee'] ?? [])->first(fn ($r) => $this->eqName($r['empleado'] ?? '') === $this->eqName($employee->full_name));
        $s['expenses_detail'] = [
            'total'      => (float) ($expByEmployeeRow['total'] ?? 0),
            'byEmployee' => $expByEmployeeRow ? [$expByEmployeeRow] : [],
            'byCategory' => null, 'byConcept' => null, 'byBranch' => null, 'bySource' => null,
            'detail_not_attributable' => 'El desglose por categoría/concepto/sucursal/fuente solo está disponible a nivel general en este snapshot.',
        ];

        // Percepciones/deducciones por concepto (NOI), acotado a este empleado.
        $s['payroll_by_branch_concept'] = [
            'data' => [
                $employee->full_name => DB::table('fact_noi_movements')
                    ->whereIn('period_id', $this->dataIds)
                    ->where('employee_id', $employeeId)
                    ->selectRaw("COALESCE(concept, concept_type, 'Sin concepto') as concept, SUM(amount) as total")
                    ->groupBy('concept', 'concept_type')
                    ->pluck('total', 'concept')
                    ->all(),
            ],
            'incidents' => [],
        ];

        if (isset($s['imss']['colaboradores'])) {
            $s['imss'] = ['colaboradores' => $this->filterRowsByField($s['imss']['colaboradores'], ['nombre'], $employee->full_name)];
        }
        if (isset($s['rotation_detail'])) {
            foreach (['altas', 'bajas', 'activos', 'empleados_mes_actual', 'empleados_mes_anterior'] as $k) {
                if (isset($s['rotation_detail'][$k]) && is_array($s['rotation_detail'][$k])) {
                    $s['rotation_detail'][$k] = $this->filterRowsByField($s['rotation_detail'][$k], ['nombre'], $employee->full_name);
                }
            }
        }

        foreach ([
            'active_loans', 'portfolio_by_branch_product', 'placement_by_branch_product', 'mora_by_branch_product',
            'mora_by_branch', 'interbranch_loans', 'corporate_funding', 'fondeo_detalle',
        ] as $k) {
            if (isset($s[$k])) {
                $s[$k] = $this->notAttributable('No existe desglose por colaborador para este dato en el snapshot del periodo.');
            }
        }

        $snapshot['sections'] = $s;
        $snapshot['charts']   = $this->scopeChartsByLabel($snapshot['charts'], $row['name']);

        return $snapshot;
    }

    /**
     * Colocación por producto de UN promotor — misma tabla/exclusiones que buildProducts(),
     * acotada por promoter_name. No incluye cartera/recuperación por producto del colaborador
     * porque fact_portfolios/fact_recoveries no siempre traen promoter_name confiable a nivel
     * de contrato para ese cruce; se deja como colocación únicamente (dato real, no inventado).
     */
    private function buildProductsForPromoter(string $promoterName): array
    {
        $rows = DB::table('fact_placements')
            ->whereIn('period_id', $this->dataIds)
            ->whereRaw('LOWER(promoter_name) = ?', [mb_strtolower(trim($promoterName))])
            ->selectRaw('COALESCE(NULLIF(product_name, \'\'), \'Sin producto\') as producto, COUNT(*) as operaciones, SUM(amount) as colocacion')
            ->groupBy('product_name')
            ->orderByDesc('colocacion')
            ->get()
            ->map(fn ($r) => ['producto' => $r->producto, 'operaciones' => (int) $r->operaciones, 'colocacion' => (float) $r->colocacion, 'cartera' => null, 'recuperacion' => null])
            ->values()->all();

        return $rows;
    }

    /**
     * Reduce cada gráfica {label,value,pct}[] a la entrada cuyo label coincide con el
     * alcance activo (recalculando pct=100). Gráficas sin esa dimensión (ej. por producto)
     * quedan sin tocar — siguen siendo información general válida, no del colaborador/sucursal.
     */
    private function scopeChartsByLabel(array $charts, string $needle): array
    {
        $eq = $this->eqName($needle);
        foreach ($charts as $key => $rows) {
            if (!is_array($rows) || empty($rows) || !isset($rows[0]['label'])) continue;
            $match = collect($rows)->first(fn ($r) => $this->eqName($r['label'] ?? '') === $eq);
            $charts[$key] = $match ? [array_merge($match, ['pct' => 100.0])] : [];
        }
        return $charts;
    }

    /**
     * Construye el bloque `summary` (mismas llaves que el summary general) a partir de UNA
     * fila de sucursal (branchCalcBranches) o de gestor (employees_gestores). Reutiliza las
     * MISMAS fórmulas estáticas que el global (BranchRadiographyCalculator::nominaTotalFor()/
     * ingresoEbitdaBaseFor()/gastosTotalesFor()/ebitdaFinalFor()/margenEbitdaFor()) para que
     * "sucursal"/"colaborador" nunca diverja de "general" salvo en el dato de entrada.
     *
     * $branchRow: fila de branchCalcBranches (shape completo) para scope=branch, o null.
     * $employeeRow: fila de employees_gestores para scope=employee, o null.
     */
    private function summaryFromRow(array $row, array $percepDeducc, ?array $employeeRow): array
    {
        if ($employeeRow !== null) {
            // Alcance colaborador: la fila YA trae recuperacion/colocacion/cartera/vencida/mora/
            // gastos/pagos/bonos/descuentos/neto/ingreso_ebitda_base — mismos campos que usa
            // RadiografiaExportService::resolveEmployeeRow() para Excel/PDF.
            $pagos       = (float) ($employeeRow['pagos'] ?? 0);
            $bonos       = (float) ($employeeRow['bonos'] ?? 0);
            $descuentos  = (float) ($employeeRow['descuentos'] ?? 0);
            $gastos      = (float) ($employeeRow['gastos'] ?? 0);
            $neto        = (float) ($employeeRow['neto'] ?? ($pagos + $bonos - $descuentos));
            $ingresoBase = (float) ($employeeRow['ingreso_ebitda_base'] ?? 0);
            $cartera     = (float) ($employeeRow['cartera'] ?? 0);
            $vencida     = (float) ($employeeRow['vencida'] ?? 0);
            $ebitda      = $ingresoBase - ($gastos + $neto);

            return [
                'employees_count'              => 1,
                'recovery_total'                => (float) ($employeeRow['recuperacion'] ?? 0),
                'placement_total'               => (float) ($employeeRow['colocacion'] ?? 0),
                'portfolio_total'               => $cartera,
                'overdue_portfolio'             => $vencida,
                'mora_index'                    => $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0.0,
                'expenses_total'                => $gastos,
                'payroll_total'                 => $pagos + $bonos,
                'net_payroll'                   => $neto,
                'noi_percepciones'              => $percepDeducc['percepciones'],
                'noi_deducciones'               => $percepDeducc['deducciones'],
                'noi_neto_pagado'               => $percepDeducc['neto_pagado'],
                'opex_total'                    => $gastos,
                'nomina_capital_humano_total'   => $neto,
                'nomina_ebitda_total'           => $neto,
                'total_gastos_ebitda'           => $gastos + $neto,
                'ebitda_global'                 => $ebitda,
                'venta_global'                  => $ingresoBase,
                'margen_ebitda'                 => $ingresoBase > 0 ? round($ebitda / $ingresoBase * 100, 2) : 0.0,
                'ebitda_categoria'               => $this->ebitdaCategory($ebitda),
                'ingreso_ebitda_base'           => $ingresoBase,
                'gastos_totales'                => $gastos + $neto,
                'ebitda_final'                  => $ebitda,
                // No atribuible a un solo colaborador con la granularidad disponible:
                'unificacion_excluida'          => null,
                'condonacion_excluida'          => null,
                'excedentes_total'              => null,
                'fondeo_total'                  => null,
                'seguros_lendus_puente'         => null,
            ];
        }

        // Alcance sucursal: $row viene de branchCalcBranches, mismo shape que branchCalcGlobal.
        $nomina      = BranchRadiographyCalculator::nominaTotalFor($row);
        $gastosTot   = BranchRadiographyCalculator::gastosTotalesFor($row);
        $ingresoBase = BranchRadiographyCalculator::ingresoEbitdaBaseFor($row);
        $ebitda      = BranchRadiographyCalculator::ebitdaFinalFor($row);
        $margen      = BranchRadiographyCalculator::margenEbitdaFor($row);
        $moraTotal   = (float) ($row['mora_0_30'] ?? 0) + (float) ($row['mora_31_60'] ?? 0) + (float) ($row['mora_61_90'] ?? 0)
            + (float) ($row['mora_91_120'] ?? 0) + (float) ($row['mora_120_plus'] ?? 0);
        $cartera     = (float) ($row['valor_cartera'] ?? 0);

        return [
            'employees_count'              => null,
            'recovery_total'               => (float) ($row['recuperacion_total'] ?? 0),
            'recovery_bruta'               => (float) ($row['recuperacion_bruta'] ?? 0),
            'recovery_seguro_excluido'     => (float) ($row['seguro_excluido_bruto'] ?? 0),
            'recovery_savehearts_bruto'    => (float) ($row['seguro_savehearts_bruto'] ?? 0),
            'recovery_comadres_bruto'      => (float) ($row['seguro_comadres_bruto'] ?? 0),
            'recovery_crece_bruto'         => (float) ($row['seguro_crece_bruto'] ?? 0),
            'recovery_crece_reconocido'    => (float) ($row['seguro_crece_reconocido'] ?? 0),
            'recovery_crece_no_reconocido' => max(0.0, (float) ($row['seguro_crece_bruto'] ?? 0) - (float) ($row['seguro_crece_reconocido'] ?? 0)),
            'placement_total'              => (float) ($row['colocacion'] ?? 0),
            'portfolio_total'              => $cartera,
            'overdue_portfolio'            => $moraTotal,
            'mora_index'                   => $cartera > 0 ? round($moraTotal / $cartera * 100, 2) : 0.0,
            'expenses_total'               => (float) ($row['gastos_operativos'] ?? 0),
            'expenses_erp'                 => (float) ($row['gastos_erp_total'] ?? 0),
            'expenses_lendus'              => (float) ($row['gastos_lendus_total'] ?? 0),
            'seguros_lendus_puente'        => (float) ($row['seguros_lendus_puente'] ?? 0),
            'excedentes_total'             => (float) ($row['excedentes'] ?? 0),
            'fondeo_total'                 => (float) ($row['prestamos_fondea'] ?? 0),
            'payroll_total'                => $nomina,
            'net_payroll'                  => $nomina,
            'noi_percepciones'             => $percepDeducc['percepciones'],
            'noi_deducciones'              => $percepDeducc['deducciones'],
            'noi_neto_pagado'              => $percepDeducc['neto_pagado'],
            'opex_total'                   => (float) ($row['gastos_operativos'] ?? 0),
            'nomina_capital_humano_total'  => $nomina,
            'nomina_ebitda_total'          => $nomina,
            'total_gastos_ebitda'          => $gastosTot,
            'ebitda_global'                => $ebitda,
            'venta_global'                 => $ingresoBase,
            'margen_ebitda'                => $margen,
            'ebitda_categoria'             => $this->ebitdaCategory($ebitda),
            'ingreso_ebitda_base'          => $ingresoBase,
            'gastos_totales'               => $gastosTot,
            'ebitda_final'                 => $ebitda,
            'unificacion_excluida'         => (float) ($row['unificacion_excluida'] ?? 0),
            'condonacion_excluida'         => (float) ($row['condonacion_excluida'] ?? 0),
        ];
    }

    /**
     * Guardia de cuadre obligatoria: Recuperación global == suma por componentes ==
     * suma por sucursales == suma por productos, con tolerancia máxima $0.01. Se
     * ejecuta en cada generación de reporte (todos los periodos, no solo Junio) para
     * que un futuro archivo con formato distinto NUNCA produzca un reporte descuadrado
     * en silencio — si algo no cuadra, se detiene la generación aquí mismo.
     */
    private function assertRecoveryReconciles(array $global, array $branches, array $recoveryByProduct): void
    {
        $globalTotal = round((float) ($global['recuperacion_total'] ?? 0), 2);

        $componentSum = round(
            (float) ($global['capital_recuperado']      ?? 0)
            + (float) ($global['interes_recuperado']     ?? 0)
            + (float) ($global['impuesto_recuperado']    ?? 0)
            + (float) ($global['charges']                ?? 0)
            + (float) ($global['cargos_adicionales']     ?? 0)
            + (float) ($global['excedente_recuperado']   ?? 0)
            + (float) ($global['cargos_inicio']          ?? 0)
            + (float) ($global['comision_apertura']      ?? 0)
            + (float) ($global['seguro_crece_reconocido'] ?? 0)
            + (float) ($global['otros_recuperacion']     ?? 0),
            2
        );

        $branchSum = round(array_sum(array_map(
            fn ($b) => (float) ($b['recuperacion_total'] ?? 0),
            $branches
        )), 2);

        $productSum = round((float) ($recoveryByProduct['total'] ?? 0), 2);

        $tolerance = 0.01;
        $diffComponents = round($globalTotal - $componentSum, 2);
        $diffBranches   = round($globalTotal - $branchSum, 2);
        $diffProducts   = round($globalTotal - $productSum, 2);

        if (abs($diffComponents) > $tolerance || abs($diffBranches) > $tolerance || abs($diffProducts) > $tolerance) {
            throw new \RuntimeException(sprintf(
                'Recuperación descuadrada: global=%.2f, componentes=%.2f (diff %.2f), sucursales=%.2f (diff %.2f), productos=%.2f (diff %.2f). '
                . 'No se generó el reporte — revisar accumulateRecuperacion()/buildRecoveryByProduct() antes de reintentar.',
                $globalTotal, $componentSum, $diffComponents, $branchSum, $diffBranches, $productSum, $diffProducts
            ));
        }
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

    /**
     * Metadata legible del alcance real de un periodo automático (bimestral/trimestral/
     * semestral/anual): qué meses lo componen y qué rango de semanas/fechas cubre. Null
     * para un periodo mensual — ahí "el periodo" ya es autoexplicativo.
     */
    private function buildPeriodCompositeMeta(Period $period): ?array
    {
        if (!$period->isAutoGenerated() || empty($period->component_period_ids)) {
            return null;
        }

        $allPeriods = Period::all();
        $componentIds = collect($period->component_period_ids)->map(fn ($id) => (int) $id)->all();
        $componentMonths = $allPeriods->whereIn('id', $componentIds)
            ->sortBy(fn ($p) => sprintf('%04d%02d%03d', (int) $p->year, (int) $p->month, (int) $p->sequence))
            ->values();

        $weeklyIds = $period->resolveBaseWeeklyIds($allPeriods);
        $weeks = $allPeriods->whereIn('id', $weeklyIds)
            ->where('type', 'weekly')
            ->sortBy(fn ($w) => $w->start_date)
            ->values();

        return [
            'component_labels' => $componentMonths->pluck('label')->values()->all(),
            'component_range'  => $componentMonths->pluck('label')->implode(' + '),
            'week_start'       => $weeks->first()?->label,
            'week_end'         => $weeks->last()?->label,
            'week_range'       => $weeks->isNotEmpty() ? ($weeks->first()->label . ' a ' . $weeks->last()->label) : null,
            'date_start'       => optional($period->start_date)->format('d/m/Y'),
            'date_end'         => optional($period->end_date)->format('d/m/Y'),
        ];
    }

    /**
     * dataIds para métricas de CIERRE (cartera, mora, préstamo activo — un estado al corte,
     * no un flujo). Para un periodo mensual normal es idéntico a resolveDataIds(). Para un
     * periodo automático (bimestral/trimestral/semestral/anual) es SOLO el último mes
     * componente — ver Period::resolveLastMonthlyComponent().
     */
    private function resolveClosingDataIds(Period $period): array
    {
        if (!$period->isAutoGenerated()) {
            return $this->resolveDataIds($period);
        }

        $allPeriods  = Period::all();
        $lastMonthly = $period->resolveLastMonthlyComponent($allPeriods);

        return $lastMonthly ? $this->resolveDataIds($lastMonthly) : $this->resolveDataIds($period);
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
            ->whereIn('po.period_id', $this->closingDataIds)
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

        // Ingreso base EBITDA por empleado/gestor — mismos componentes y exclusiones que
        // BranchRadiographyCalculator::ingresoEbitdaBaseFor() a nivel sucursal/global, pero
        // agrupado por promoter_name. Nunca usar recuperación total ni colocación (ver
        // BranchRadiographyCalculator.php:94-113) — la utilidad por empleado debe salir de
        // aquí, no de "recuperación − colocación".
        $ingresoBaseByNorm = [];
        ['components' => $exclComponents] = \App\Services\Radiography\BranchRadiographyCalculator::recoveryExclusionSql();
        DB::table('fact_recoveries')
            ->whereIn('period_id', $this->dataIds)
            ->whereNotNull('promoter_name')
            ->selectRaw("
                promoter_name,
                SUM(CASE {$exclComponents} ELSE interest END) as interes_recuperado,
                SUM(CASE {$exclComponents} ELSE tax END) as impuesto_recuperado,
                SUM(CASE {$exclComponents} ELSE charges_due END) as charges,
                SUM(CASE {$exclComponents} ELSE charges END) as cargos_adicionales,
                SUM(CASE {$exclComponents} ELSE excedente END) as excedente_recuperado,
                SUM(CASE WHEN operation = 'COMISIÓN POR APERTURA' THEN total_amount ELSE 0 END) as comision_apertura,
                SUM(CASE WHEN `transaction` IN ('PAGO', 'DESCUENTO') THEN COALESCE(savehearts_crece_share, 0) ELSE 0 END) as seguro_crece_reconocido
            ")
            ->groupBy('promoter_name')
            ->get()
            ->each(function ($r) use (&$ingresoBaseByNorm, &$rawNameByNorm) {
                $norm = $this->canonicalizer->normalize($r->promoter_name);
                if (!$norm) return;
                $rawNameByNorm[$norm] ??= mb_strtoupper(trim($r->promoter_name));
                $ingresoBaseByNorm[$norm] = ($ingresoBaseByNorm[$norm] ?? 0.0)
                    + \App\Services\Radiography\BranchRadiographyCalculator::ingresoEbitdaBaseFor((array) $r);
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
            array_keys($ingresoBaseByNorm),
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

            if (isset($ingresoBaseByNorm[$key])) {
                $ingresoBaseByNorm[$canonical] = ($ingresoBaseByNorm[$canonical] ?? 0.0) + $ingresoBaseByNorm[$key];
                unset($ingresoBaseByNorm[$key]);
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

        $rows = $allKeys->map(function (string $key) use ($payroll, $gestorPlacements, $portfolioByNorm, $recoveryByNorm, $expensesByNorm, $ingresoBaseByNorm, $rawNameByNorm) {
            $emp    = $payroll[$key] ?? null;
            $ges    = $gestorPlacements[$key] ?? null;
            $po     = $portfolioByNorm[$key] ?? null;
            $rec    = $recoveryByNorm[$key] ?? 0.0;
            $gasEmp = $expensesByNorm[$key] ?? ($emp['gastos'] ?? 0.0);
            $ingresoBase = $ingresoBaseByNorm[$key] ?? 0.0;

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
                'ingreso_ebitda_base' => round($ingresoBase, 2),
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
            ->whereIn('period_id', $this->closingDataIds)
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
            ->whereIn('period_id', $this->closingDataIds)
            ->where('days_past_due', '>', 0)
            ->sum('balance');

        $globalVencida = (float) DB::table('fact_portfolios')
            ->whereIn('period_id', $this->closingDataIds)
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
                ->whereIn('p.period_id', $this->closingDataIds)
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
                  ->orWhere('e.category', 'Envío de utilidad a corporativo')
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
                COALESCE(e.category, '') as category,
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

        foreach ($pdfRows as $pdfRow) {
            $pdfAmount = (float) $pdfRow->amount;
            $pdfDate   = $pdfRow->expense_date
                ? \Carbon\Carbon::parse($pdfRow->expense_date)
                : null;
            $category  = (string) ($pdfRow->category ?? '');
            // Source of truth for fondeo vs excedente: the PDF category itself, not
            // destination-text guessing. 'Envío de utilidad a corporativo' is ALWAYS
            // excedente — it has no operative destination branch.
            $isExcedenteCat = $category === 'Envío de utilidad a corporativo';

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

            $toBranch      = $isExcedenteCat ? 'CORPORATIVO' : 'No identificada';
            $observation   = $pdfRow->observations ?? '';
            $justification = '';
            $source        = 'pdf';

            if ($matchedExcel) {
                $usedExcelIds[] = $matchedExcel->id;
                $payload        = $matchedExcel->decoded_payload;

                if (!$isExcedenteCat) {
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
                }

                $observation   = $matchedExcel->observations ?? $observation;
                $justification = $payload['justification'] ?? '';
                $source        = 'pdf+excel';
            } elseif (!$isExcedenteCat) {
                // No Excel match — try PDF concept/observation text
                $pdfText  = implode(' ', array_filter([$pdfRow->concept, $pdfRow->observations]));
                $detected = $this->detectBranchFromText($pdfText, $resolver);
                if ($detected) $toBranch = $detected;
            }

            if (!$isExcedenteCat && $toBranch === 'No identificada') {
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

            $detail[] = [
                'date'          => $pdfDate ? $pdfDate->format('d/m/Y') : '—',
                'from_branch'   => $fromBranch,
                'to_branch'     => $toBranch,
                'amount'        => $pdfAmount,
                'concept'       => $pdfRow->concept,
                'category'      => $category,
                'observation'   => $observation,
                'justification' => $justification,
                'source'        => $source,
            ];
        }

        // Nota: las filas de Excel sin par en el PDF NO se suman a los totales — el PDF
        // (gastos_lendus) es la única fuente para activos/pasivos/excedentes; el Excel
        // solo se usa arriba para enriquecer el destino de filas del PDF. Sumar filas de
        // Excel no pareadas duplicaba/abultaba "Pasivos (recibe)" frente a "Activos (fondea)".

        // ── D: Classify each row as fondeo operativo or excedente ───────────────
        // Fuente de verdad: la categoría del registro PDF (Préstamos Intersucursales
        // vs Envío de utilidad a corporativo). El texto de destino solo enriquece el
        // detalle visual — nunca decide si una fila es fondeo o excedente.
        // Regla: fondeos operativos deben cumplir fondea_total == recibe_total (neto=$0).
        $fondeaOperMap   = [];
        $recibeOperMap   = [];
        $excedenteByMap  = [];

        $detailFondeos   = [];
        $detailExcedentes = [];

        foreach ($detail as $row) {
            $isExcedente = ($row['category'] ?? '') === 'Envío de utilidad a corporativo';
            if ($isExcedente) {
                $excedenteByMap[$row['from_branch']] = ($excedenteByMap[$row['from_branch']] ?? 0.0) + $row['amount'];
                $row['type']      = 'excedente';
                $detailExcedentes[] = $row;
            } else {
                // Edge case: category is fondeo but destination text resolved to
                // 'CORPORATIVO' (text false-positive) — keep the row as fondeo operativo
                // (so totals match the category split) but never label a destino as
                // CORPORATIVO inside the operative section.
                if ($row['to_branch'] === 'CORPORATIVO') {
                    $row['to_branch'] = 'No identificada';
                }
                $fondeaOperMap[$row['from_branch']] = ($fondeaOperMap[$row['from_branch']] ?? 0.0) + $row['amount'];
                $recibeOperMap[$row['to_branch']]  = ($recibeOperMap[$row['to_branch']]   ?? 0.0) + $row['amount'];
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
            // Backward-compat aliases — SIEMPRE operativo puro (nunca mezclan excedentes).
            'total'     => $totalFondeoOper,
            'fondea'    => $fondeaOperRows,
            'recibe'    => $recibeOperRows,
            'by_branch' => $fondeaOperRows,
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
     *
     * Fuente autorizada: gastos_lendus_excel (tiene destino en branch_to_detected u observations).
     * El PDF (gastos_lendus) solo se usa para construir el mapa empleado→sucursal_origen,
     * ya que el PDF registra la sucursal de cargo (branch_id) pero no el destino.
     */
    private function buildFondeoDetalle(): array
    {
        // Alias map: texto normalizado (sin acentos) → nombre oficial de sucursal
        static $branchAliases = [
            'ATLACOMULCO'       => 'ATLACOMULCO',
            'ATLIXCO'           => 'ATLIXCO',
            'CORDOBA'           => 'CÓRDOBA',
            'CUERNAVACA'        => 'CUERNAVACA',
            'HUAMANTLA'         => 'HUAMANTLA',
            'IXTLAHUACA'        => 'IXTLAHUACA',
            'MIACATLAN'         => 'MIACATLÁN',
            'ORIZABA'           => 'ORIZABA',
            'SAN JUAN DEL RIO'  => 'SAN JUAN DEL RÍO',
            'SAN JUAN RIO'      => 'SAN JUAN DEL RÍO',
            'SAN JUAN'          => 'SAN JUAN DEL RÍO',
            'SAN LUIS POTOSI'   => 'SAN LUIS POTOSÍ',
            'SAN LUIS'          => 'SAN LUIS POTOSÍ',
            'TENANGO DEL VALLE' => 'TENANGO DEL VALLE',
            'TENANGO'           => 'TENANGO DEL VALLE',
            'TLAXCALA'          => 'TLAXCALA',
            'TULA'              => 'TULA',
        ];

        $normalize = static function (string $v): string {
            return strtr(mb_strtoupper(trim($v)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        };

        $resolveAlias = static function (string $text) use ($branchAliases, $normalize): string {
            $norm = $normalize($text);
            if (isset($branchAliases[$norm])) return $branchAliases[$norm];
            // Partial scan: useful for "FONDEO A CUERNAVACA | ..." texts
            foreach ($branchAliases as $keyword => $official) {
                if (str_contains($norm, $keyword)) return $official;
            }
            return $text;
        };

        // Step 1: Build employee-name → origin-branch map from PDF records.
        // PDF records have branch_id (origin) but no destination; observations = employee name.
        $pdfRows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->where('e.category', 'Préstamos Intersucursales')
            ->where('ds.code', 'gastos_lendus')
            ->whereNotNull('b.name')
            ->select('e.observations', 'b.name as branch_name')
            ->get();

        // Map normalized employee → origin branch
        $empToBranch = [];
        foreach ($pdfRows as $pdf) {
            if (!$pdf->branch_name || !$pdf->observations) continue;
            $empToBranch[$normalize($pdf->observations)] = $pdf->branch_name;
        }

        // Step 2: Load Excel fondeos — these have the destination.
        // Exclude EXCEDENTES (to CORPORATIVO) — those are tracked separately in buildCorporateFunding.
        $xlRows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $this->dataIds)
            ->where('e.category', 'Préstamos Intersucursales')
            ->where('ds.code', 'gastos_lendus_excel')
            ->whereRaw("UPPER(COALESCE(e.concept,'')) NOT LIKE '%EXCEDENTE%'")
            ->whereRaw("IFNULL(JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload,'$.branch_to_detected')),'') != 'CORPORATIVO'")
            ->select('e.id', 'e.amount', 'e.paid_amount', 'e.observations', 'e.expense_date', 'e.raw_payload')
            ->orderByDesc('e.amount')
            ->get();

        $detail = [];
        $total  = 0.0;

        foreach ($xlRows as $row) {
            $monto = (float) ($row->paid_amount ?: $row->amount);
            $total += $monto;

            $rp          = json_decode($row->raw_payload ?? '{}', true);
            $solicitante = trim((string) ($rp['solicitante'] ?? ''));
            $branchDet   = trim((string) ($rp['branch_to_detected'] ?? ''));

            // Resolve DESTINATION
            if ($branchDet !== '' && strtoupper($branchDet) !== 'CORPORATIVO') {
                $destino = $resolveAlias($branchDet);
            } else {
                // Parse from observations text (e.g. "FONDEO A CUERNAVACA | FONDEO A CUERNAVACA")
                $destino = $resolveAlias((string) ($row->observations ?? ''));
                if ($destino === $normalize((string) ($row->observations ?? ''))) {
                    $destino = '—'; // could not resolve
                }
            }

            // Resolve ORIGIN from solicitante → employee→branch map
            $origen = '—';
            if ($solicitante !== '') {
                $normSol = $normalize($solicitante);
                if (isset($empToBranch[$normSol])) {
                    $origen = $empToBranch[$normSol];
                } else {
                    // Starts-with match handles cases where PDF truncates the employee name
                    foreach ($empToBranch as $empKey => $branchName) {
                        if (str_starts_with($normSol, $empKey) || str_starts_with($empKey, $normSol)) {
                            $origen = $branchName;
                            break;
                        }
                    }
                }
                if ($origen !== '—') {
                    $norm = $normalize($origen);
                    $origen = $branchAliases[$norm] ?? $origen;
                }
            }

            $detail[] = [
                'sucursal_origen'  => $origen,
                'sucursal_destino' => $destino,
                'responsable'      => $solicitante,
                'monto'            => $monto,
                'observacion'      => (string) ($row->observations ?? ''),
                'fecha'            => $row->expense_date,
                'fuente'           => 'gastos_lendus_excel',
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

    /**
     * Recuperación por producto — delega en BranchRadiographyCalculator, que es dueño
     * de la única fuente de verdad de reglas de Recuperación (recoveryExclusionSql()).
     */
    private function buildRecoveryByProduct(Period $period): array
    {
        return $this->branchCalculator->buildRecoveryByProduct($this->dataIds);
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
    // Fuente ÚNICA y definitiva (cierre 2026-07-23): fact_rotacion con
    // source='derived_noi', poblado por RotacionDerivedFromNoiService (roster NOI
    // normal + fiscal). El archivo manual de rotación ya NO existe como fuente del
    // sistema — no hay fallback a source='file' en ningún caso; un periodo sin
    // derivado simplemente reporta "sin_datos" (nunca se resucita el archivo manual).
    private function buildRotationData(Period $period): array
    {
        $rows = DB::table('fact_rotacion as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->whereIn('fr.period_id', $this->dataIds)
            ->where('fr.source', 'derived_noi')
            ->select(
                'fr.period_id',
                'fr.sucursal_nombre',
                'fr.branch_id',
                'fr.mes',
                'fr.bajas',
                'fr.altas',
                'fr.promedio_personal',
                'fr.indice_rotacion',
                'fr.source',
                'b.name as branch_name',
            )
            ->orderBy('fr.period_id')
            ->orderBy('fr.sucursal_nombre')
            ->get();

        // Plantilla del periodo anterior (global y por sucursal) — mismo criterio que
        // AuditRotacionCierreCommand::sectionA()/sectionB(): resuelve el mes de cierre
        // real (para periodos automáticos, el último mes componente) y suma
        // promedio_personal de fact_rotacion (source=derived_noi) de su mes anterior.
        $allPeriods    = \App\Models\Period::all();
        $closingPeriod = $period->isAutoGenerated() ? $period->resolveLastMonthlyComponent($allPeriods) : $period;
        $prevMonthly   = $closingPeriod?->previousMonthly($allPeriods);

        $prevCount        = 0.0;
        $prevPorSucursal  = collect();
        if ($prevMonthly) {
            $prevGlobal = DB::table('fact_rotacion')
                ->where('period_id', $prevMonthly->id)
                ->where('source', 'derived_noi')
                ->selectRaw('SUM(promedio_personal) as plantilla')
                ->first();
            $prevCount = (float) ($prevGlobal->plantilla ?? 0);

            $prevPorSucursal = DB::table('fact_rotacion')
                ->where('period_id', $prevMonthly->id)
                ->where('source', 'derived_noi')
                ->get(['sucursal_nombre', 'promedio_personal'])
                ->keyBy(fn ($r) => $r->sucursal_nombre);
        }

        if ($rows->isEmpty()) {
            return [
                'fuente'          => 'sin_datos',
                'mes'             => '',
                'altas'           => 0,
                'bajas'           => 0,
                'promedio'        => 0,
                'indice'          => 0.0,
                'current_count'   => 0,
                'prev_count'      => round($prevCount, 2),
                'prev_mes'        => $prevMonthly?->label,
                'por_sucursal'    => [],
                'detalle_mensual' => [],
            ];
        }

        // Altas/Bajas: FLUJO — se suman entre todos los meses componentes (rows ya cubre
        // todo $this->dataIds, es decir todos los meses en un periodo automático).
        // Plantilla: CIERRE — solo el último mes componente (closingRows), nunca sumada.
        $closingIds  = $this->closingDataIds;
        $closingRows = $rows->filter(fn ($r) => in_array((int) $r->period_id, $closingIds, true));
        if ($closingRows->isEmpty()) {
            $closingRows = $rows;
        }

        $totalBajas    = (int) $rows->sum('bajas');
        $totalAltas    = (int) $rows->sum('altas');
        $totalPromedio = (float) $closingRows->sum('promedio_personal');
        $mesUsado      = $closingRows->pluck('mes')->filter()->unique()->first() ?? ($rows->pluck('mes')->filter()->unique()->first() ?? '');
        $indiceGlobal  = $totalPromedio > 0 ? round($totalBajas / $totalPromedio * 100, 2) : 0.0;

        // Altas/bajas acumuladas por sucursal (todos los meses)
        $altasBajasPorSucursal = [];
        foreach ($rows as $r) {
            $key = $r->sucursal_nombre;
            $altasBajasPorSucursal[$key] ??= ['altas' => 0, 'bajas' => 0];
            $altasBajasPorSucursal[$key]['altas'] += (int) $r->altas;
            $altasBajasPorSucursal[$key]['bajas'] += (int) $r->bajas;
        }

        $porSucursal = [];
        foreach ($closingRows as $r) {
            $ab        = $altasBajasPorSucursal[$r->sucursal_nombre] ?? ['altas' => (int) $r->altas, 'bajas' => (int) $r->bajas];
            $plantilla = (float) $r->promedio_personal;
            $plantillaAnterior = (float) ($prevPorSucursal->get($r->sucursal_nombre)->promedio_personal ?? 0);
            $porSucursal[] = [
                'sucursal'           => $r->sucursal_nombre,
                'altas'              => $ab['altas'],
                'bajas'              => $ab['bajas'],
                'promedio_personal'  => $plantilla,
                'plantilla_anterior' => $plantillaAnterior,
                'variacion_plantilla'=> round($plantilla - $plantillaAnterior, 2),
                'indice_rotacion'    => $plantilla > 0 ? round($ab['bajas'] / $plantilla * 100, 2) : 0.0,
                'mes'                => $r->mes,
            ];
        }

        // Detalle mensual (Mes | Altas | Bajas | Plantilla | Índice) — solo relevante para
        // periodos automáticos, donde varios meses se consolidan en un solo reporte.
        $detalleMensual = [];
        if ($period->isAutoGenerated()) {
            foreach ($rows->groupBy('period_id')->sortKeys() as $group) {
                $altasMes     = (int) $group->sum('altas');
                $bajasMes     = (int) $group->sum('bajas');
                $plantillaMes = (float) $group->sum('promedio_personal');
                $detalleMensual[] = [
                    'mes'       => $group->first()->mes,
                    'altas'     => $altasMes,
                    'bajas'     => $bajasMes,
                    'plantilla' => $plantillaMes,
                    'indice'    => $plantillaMes > 0 ? round($bajasMes / $plantillaMes * 100, 2) : 0.0,
                ];
            }
        }

        return [
            'fuente'          => 'derived_noi',
            'mes'             => $mesUsado,
            'altas'           => $totalAltas,
            'bajas'           => $totalBajas,
            'promedio'        => round($totalPromedio, 2),
            'indice'          => $indiceGlobal,
            'current_count'   => round($totalPromedio, 2),
            'prev_count'      => round($prevCount, 2),
            'prev_mes'        => $prevMonthly?->label,
            'variacion_neta'  => round($totalPromedio - $prevCount, 2),
            'por_sucursal'    => $porSucursal,
            'detalle_mensual' => $detalleMensual,
        ];
    }

    /**
     * Fuente ÚNICA (cierre 2026-07-23): derivado de NOI fiscal (fact_imss). El archivo
     * manual legado ya no se consulta aquí en ningún caso. Expuesto para que Excel/PDF/UI
     * muestren la fuente real sin duplicar la consulta en cada consumidor.
     */
    private function buildImssMeta(Period $period): array
    {
        $derivedExists = DB::table('fact_imss')->whereIn('period_id', $this->dataIds)->exists();

        return ['fuente' => $derivedExists ? 'derived_noi_fiscal' : 'sin_datos'];
    }

    // ── ROTACIÓN — DETALLE PARA PESTAÑA EXCEL / AUDITORÍA ───────────────────
    //
    // Listas empleado por empleado (altas, bajas, activos, sin sucursal) para la
    // pestaña Excel ROTACIÓN y para reportes:audit-rotacion-derivada --detail.
    // Fuente ÚNICA: period_employee_rosters (roster NOI normal + fiscal), nunca
    // Lendus ni archivo manual.
    private function buildRotationDetail(Period $period): array
    {
        $activeRows = DB::table('period_employee_rosters as per')
            ->leftJoin('employees as e', 'per.employee_id', '=', 'e.id')
            ->where('per.period_id', $period->id)
            ->where('per.is_active_for_period', true)
            ->orderBy('per.branch_name')
            ->orderBy('per.nombre_original')
            ->get(['per.branch_id', 'per.branch_name', 'per.nombre_original', 'per.source', 'per.movement_type', 'e.employee_code']);

        $bajaRows = DB::table('period_employee_rosters as per')
            ->leftJoin('employees as e', 'per.employee_id', '=', 'e.id')
            ->where('per.period_id', $period->id)
            ->where('per.is_active_for_period', false)
            ->where('per.movement_type', 'baja')
            ->orderBy('per.branch_name')
            ->orderBy('per.nombre_original')
            ->get(['per.branch_id', 'per.branch_name', 'per.nombre_original', 'per.source', 'e.employee_code']);

        $sourceLabel = fn (string $s) => match ($s) {
            'ambos'              => 'NOI Nómina + NOI Nómina Fiscal',
            'noi_nomina_fiscal'  => 'NOI Nómina Fiscal',
            default              => 'NOI Nómina',
        };

        $altas = $activeRows->where('movement_type', 'alta')->map(fn ($r) => [
            'sucursal' => $r->branch_name ?: 'SIN SUCURSAL',
            'clave'    => $r->employee_code ?: '—',
            'nombre'   => $r->nombre_original,
            'motivo'   => 'Aparece en el periodo actual y no en el periodo anterior',
        ])->values()->all();

        $bajas = $bajaRows->map(fn ($r) => [
            'sucursal' => $r->branch_name ?: 'SIN SUCURSAL',
            'clave'    => $r->employee_code ?: '—',
            'nombre'   => $r->nombre_original,
            'motivo'   => 'Aparecía en el periodo anterior y no aparece en el periodo actual',
        ])->values()->all();

        $activos = $activeRows->map(fn ($r) => [
            'sucursal'      => $r->branch_name ?: 'SIN SUCURSAL',
            'clave'         => $r->employee_code ?: '—',
            'nombre'        => $r->nombre_original,
            'fuente'        => $sourceLabel($r->source),
            'movement_type' => $r->movement_type,
        ])->values()->all();

        $sinSucursal = $activeRows->whereNull('branch_id')->map(fn ($r) => [
            'clave'  => $r->employee_code ?: '—',
            'nombre' => $r->nombre_original,
            'fuente' => $sourceLabel($r->source),
            'motivo' => 'Sin sucursal resuelta en employee_branch_assignments (ni periodo actual ni histórico)',
        ])->values()->all();

        // Lista del periodo anterior reconstruida a partir del roster actual: los que
        // siguen activos (movement_type='activo') más los que causaron baja. Evita una
        // consulta aparte — el periodo anterior ya no tiene por qué seguir en BD igual.
        $mesAnteriorLista = array_values(array_filter($activos, fn ($r) => $r['movement_type'] === 'activo'));
        foreach ($bajas as $b) {
            $mesAnteriorLista[] = [
                'sucursal' => $b['sucursal'],
                'clave'    => $b['clave'],
                'nombre'   => $b['nombre'],
                'fuente'   => '—',
            ];
        }
        usort($mesAnteriorLista, fn ($a, $b) => strcmp($a['nombre'], $b['nombre']));

        $prevPeriod = $period->previousMonthly(Period::all());

        return [
            'altas'            => $altas,
            'bajas'            => $bajas,
            'activos'          => $activos,
            'sin_sucursal'     => $sinSucursal,
            'mes_actual'       => strtoupper($period->label),
            'mes_anterior'     => $prevPeriod ? strtoupper($prevPeriod->label) : null,
            'empleados_mes_actual'   => $activos,
            'empleados_mes_anterior' => $mesAnteriorLista,
        ];
    }

    // ── IMSS — DETALLE PARA PESTAÑA EXCEL / AUDITORÍA ───────────────────────
    //
    // Expone el desglose completo del cálculo derivado (roster NOI fiscal × $3,500):
    // resumen por sucursal, lista de colaboradores incluidos/excluidos y totales
    // globales. Usado por la hoja Excel "IMSS" (RadiographyWorkbookBuilder) y
    // disponible para UI/PDF si se requiere mostrar el detalle completo.
    private function buildImssDetail(Period $period): array
    {
        $fee = \App\Services\ImssFromNoiFiscalService::MONTHLY_FEE;

        $rows = DB::table('period_employee_rosters as per')
            ->leftJoin('employees as e', 'per.employee_id', '=', 'e.id')
            ->where('per.period_id', $period->id)
            ->where('per.is_active_for_period', true)
            ->where('per.appears_in_nomina_fiscal', true)
            ->orderBy('per.branch_name')
            ->orderBy('per.nombre_original')
            ->get(['per.branch_id', 'per.branch_name', 'per.is_branch_operativa', 'per.nombre_original', 'e.employee_code']);

        $porSucursal   = [];
        $colaboradores = [];
        $totalIncluidos = 0;
        $totalExcluidos = 0;
        $montoIncluido  = 0.0;
        $montoExcluido  = 0.0;

        foreach ($rows as $r) {
            $branchName = $r->branch_name ?: 'SIN SUCURSAL';
            $incluido   = (bool) $r->branch_id && (bool) $r->is_branch_operativa;
            $motivo     = $incluido
                ? 'Sucursal operativa — incluida en el reporte'
                : (!$r->branch_id
                    ? 'Empleado sin sucursal resuelta (ver period_employee_rosters)'
                    : 'No operativa (Corporativo/Tulancingo/otra unidad fuera de las 13 sucursales oficiales)');
            $importe = $incluido ? $fee : 0.0;

            $key = (int) ($r->branch_id ?? 0);
            $porSucursal[$key] ??= [
                'sucursal'      => $branchName,
                'colaboradores' => 0,
                'cuota'         => $fee,
                'imss'          => 0.0,
                'incluido'      => $incluido,
                'motivo'        => $motivo,
            ];
            $porSucursal[$key]['colaboradores']++;
            $porSucursal[$key]['imss'] += $importe;

            $colaboradores[] = [
                'sucursal' => $branchName,
                'clave'    => $r->employee_code ?: '—',
                'nombre'   => $r->nombre_original,
                'fuente'   => 'NOI Nómina Fiscal',
                'incluido' => $incluido,
                'motivo'   => $motivo,
                'importe'  => $importe,
            ];

            if ($incluido) {
                $totalIncluidos++;
                $montoIncluido += $importe;
            } else {
                $totalExcluidos++;
                $montoExcluido += $fee;
            }
        }

        return [
            'fuente'                => 'derived_noi_fiscal',
            'cuota_por_colaborador' => $fee,
            'por_sucursal'          => array_values($porSucursal),
            'colaboradores'         => $colaboradores,
            'resumen'               => [
                'total_incluidos'         => $totalIncluidos,
                'monto_incluido'          => $montoIncluido,
                'total_excluidos'         => $totalExcluidos,
                'monto_excluido'          => $montoExcluido,
                'total_detectados_fiscal' => $totalIncluidos + $totalExcluidos,
            ],
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
            ->whereIn('po.period_id', $this->closingDataIds)
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
            ->whereIn('po.period_id', $this->closingDataIds)
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

    // Alias delgado — delega en RadiographyStyleHelper::ebitdaCategory() para que
    // exista una única fuente de umbrales (Excel GLOBAL, hoja CATEGORÍA EBITDA, PDF
    // y este resumen JAMÁS se desalineen).
    private function ebitdaCategory(float $ebitda): string
    {
        return RadiographyStyleHelper::ebitdaCategory($ebitda);
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

        // DPD autoritativo por contrato: estado de cierre, no flujo — usa closingDataIds
        // (último mes componente en periodos automáticos) para no mezclar el DPD de un
        // mismo contrato en distintos meses.
        $portfolioDpd = DB::table('fact_portfolios')
            ->whereIn('period_id', $this->closingDataIds)
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
