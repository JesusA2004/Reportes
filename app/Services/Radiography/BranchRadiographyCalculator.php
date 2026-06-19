<?php

namespace App\Services\Radiography;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Support\Facades\DB;

/**
 * Builds per-branch financial summaries for the 12 operative sucursales.
 * GLOBAL is always derived as the sum of those branch summaries,
 * never recalculated independently from all fact_* rows.
 */
class BranchRadiographyCalculator
{
    private const EXCEDENTES_CAT     = 'Envío de utilidad a corporativo';
    private const FONDEO_CAT         = 'Préstamos Intersucursales';
    private const NOMINA_CAT         = 'Nómina y Capital Humano';
    // Categories that appear as gastos but belong to the Nómina section (shown there, not in gastos)
    private const NOMINA_EXPENSE_CATS = ['Gasolina', 'Financiamiento Celular'];

    // Categories that appear in Lendus PDF — ERP rows with these categories are
    // excluded to avoid double-counting with Lendus.
    private const LENDUS_PRESENT_CATS = [
        'Gastos Operativos',
        'Pólizas',
        'Renta Oficina',
        'Recargas Telefónicas',
        'Gasolina',
        'Agua',
    ];

    public function __construct(private readonly BranchResolverService $resolver) {}

    /**
     * Resolves all DB branches to their real operative sucursal.
     *
     * Returns:
     *   'operative'   => [branch_id => real_sucursal_name]  (only the 12 operative branches)
     *   'corporativo' => [branch_id, ...]
     */
    public function buildBranchMap(): array
    {
        $operativeMap    = [];
        $corporativoIds  = [];

        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if (!$real) {
                continue;
            }
            if ($this->resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            } elseif (strtoupper(trim($real)) === 'CORPORATIVO') {
                $corporativoIds[] = (int) $b->id;
            }
        }

        return ['operative' => $operativeMap, 'corporativo' => $corporativoIds];
    }

    /**
     * Builds per-branch summaries for the 12 operative branches plus an unassigned bucket.
     *
     * Returns ['branches' => [...12 summaries...], 'unassigned' => [nómina/comisiones/bonos/gastos sin sucursal]]
     * GLOBAL must be computed via sumGlobal($branches, $unassigned) to include unassigned amounts.
     *
     * @param array $includedBranchIds When non-empty, only these branch IDs contribute to metrics.
     */
    public function buildBranches(Period $period, array $dataIds, array $includedBranchIds = []): array
    {
        $maps            = $this->buildBranchMap();
        $operativeMap    = $maps['operative'];
        $corporativoIds  = $maps['corporativo'];

        // Filter operativeMap to only the requested branches.
        // Routes that resolve to the same real branch are excluded when that branch is excluded.
        if (!empty($includedBranchIds)) {
            $includedNames = DB::table('branches')
                ->whereIn('id', $includedBranchIds)
                ->pluck('name')
                ->map(fn ($n) => strtoupper(trim((string) \Illuminate\Support\Str::ascii($n))))
                ->all();

            $operativeMap = array_filter(
                $operativeMap,
                fn ($resolvedName) => in_array(
                    strtoupper(trim((string) \Illuminate\Support\Str::ascii($resolvedName))),
                    $includedNames,
                    true
                )
            );
        }

        $operativeIds    = array_keys($operativeMap);

        $summaries = [];
        foreach ($this->resolver->operativeFinancialBranches() as $sucursal) {
            if (!empty($includedBranchIds)) {
                $sucNorm = strtoupper(trim((string) \Illuminate\Support\Str::ascii($sucursal)));
                if (!in_array($sucNorm, $includedNames, true)) {
                    continue;
                }
            }
            $summaries[$sucursal] = $this->emptyBranchSummary($sucursal);
        }

        $unassigned = $this->emptyUnassigned();

        if (empty($operativeIds)) {
            return ['branches' => array_values($summaries), 'unassigned' => $unassigned];
        }

        // Cartera / colocación / recuperación / mora: branch_id / ruta, NO empleados
        $this->accumulateCartera($dataIds, $operativeIds, $operativeMap, $summaries);
        $this->accumulateColocacion($dataIds, $operativeIds, $operativeMap, $summaries);
        $this->accumulateRecuperacion($dataIds, $operativeIds, $operativeMap, $summaries, $corporativoIds);
        $this->accumulateMora($dataIds, $operativeIds, $operativeMap, $summaries);

        // Gastos: branch_id de Lendus/ERP; Corporativo → unassigned; Norte → excluido
        $this->accumulateGastos($dataIds, $operativeIds, $operativeMap, $summaries, $corporativoIds, $unassigned);

        // Nómina/comisiones/bonos: employee_branch_assignments; sin match → unassigned
        $this->accumulateNomina($dataIds, $operativeMap, $summaries, $unassigned);

        // Pólizas CRECE: 30% share stored in fact_recoveries.savehearts_crece_share
        $this->accumulatePolizasCrece($dataIds, $operativeIds, $operativeMap, $summaries);

        return ['branches' => array_values($summaries), 'unassigned' => $unassigned];
    }

    /**
     * Sums all branch metrics to produce the GLOBAL summary.
     * GLOBAL = SUM(branches) + unassigned(nómina/comisiones/bonos/gastos).
     * Cartera / recuperación / colocación / mora come ONLY from branches (not unassigned).
     */
    public function sumGlobal(array $branches, array $unassigned = []): array
    {
        $global = $this->emptyBranchSummary('GLOBAL');

        foreach ($branches as $branch) {
            foreach ($branch as $key => $val) {
                if ($key === 'sucursal' || $key === 'gastos_detalle' || $key === 'nomina_detalle') {
                    continue; // array fields handled separately
                }
                if (array_key_exists($key, $global)) {
                    $global[$key] += $val;
                }
            }
        }

        // Add unassigned EMPLOYEE amounts to GLOBAL (employees belong to the period regardless of branch).
        // gastos_operativos de Corporativo NO se suman al GLOBAL operativo de las 13 sucursales.
        foreach (['nomina_total', 'comisiones', 'bonos', 'vacaciones', 'prima_vacacional'] as $key) {
            $global[$key] += (float) ($unassigned[$key] ?? 0);
        }

        // Sum gastos_detalle across all branches for GLOBAL breakdown
        $global['gastos_detalle'] = [];
        foreach ($branches as $branch) {
            foreach ($branch['gastos_detalle'] ?? [] as $concept => $amount) {
                $global['gastos_detalle'][$concept] = ($global['gastos_detalle'][$concept] ?? 0.0) + (float) $amount;
            }
        }

        // Sum nomina_detalle across all branches + unassigned for GLOBAL breakdown
        $global['nomina_detalle'] = [];
        foreach ($branches as $branch) {
            foreach ($branch['nomina_detalle'] ?? [] as $label => $amount) {
                $global['nomina_detalle'][$label] = ($global['nomina_detalle'][$label] ?? 0.0) + (float) $amount;
            }
        }
        foreach ($unassigned['nomina_detalle'] ?? [] as $label => $amount) {
            $global['nomina_detalle'][$label] = ($global['nomina_detalle'][$label] ?? 0.0) + (float) $amount;
        }

        return $global;
    }

    /**
     * Unified payroll data for ALL outputs: UI, Excel GLOBAL, hojas sucursal, NÓMINA, PDF.
     *
     * Returns a flat list of rows: [sucursal, concepto, monto, fuente, empleados].
     * "GLOBAL" row is the sum of branches + unassigned bucket.
     * All concepts (including D-codes shown in the report) are returned as positive amounts.
     */
    public function buildPayrollByBranchForRadiography(Period $period, array $dataIds): array
    {
        ['branches' => $branches, 'unassigned' => $unassigned] = $this->buildBranches($period, $dataIds);
        $global = $this->sumGlobal($branches, $unassigned);

        $rows    = [];
        $sources = [
            'nomina_total'     => ['label' => 'Nómina',           'fuente' => 'NOI P001+D111-D137'],
            'comisiones'       => ['label' => 'Comisiones',        'fuente' => 'NOI P002'],
            'bonos'            => ['label' => 'Bonos',             'fuente' => 'NOI P108/109/120/123'],
            'vacaciones'       => ['label' => 'Vacaciones',        'fuente' => 'NOI P009'],
            'prima_vacacional' => ['label' => 'Prima vacacional',  'fuente' => 'NOI P010'],
        ];

        $allBranches = array_merge($branches, ['GLOBAL' => $global, 'SIN ASIGNAR' => $unassigned]);

        foreach ($allBranches as $suc => $calc) {
            foreach ($sources as $key => ['label' => $label, 'fuente' => $fuente]) {
                $monto = (float) ($calc[$key] ?? 0);
                if ($monto == 0.0) continue;
                $rows[] = ['sucursal' => $suc, 'concepto' => $label, 'monto' => $monto, 'fuente' => $fuente, 'empleados' => 0];
            }
            foreach ($calc['nomina_detalle'] ?? [] as $label => $monto) {
                if ((float) $monto == 0.0) continue;
                $rows[] = ['sucursal' => $suc, 'concepto' => $label, 'monto' => (float) $monto, 'fuente' => 'fact_expenses / NOI D', 'empleados' => 0];
            }
        }

        return $rows;
    }

    // ── Cartera ──────────────────────────────────────────────────────────────

    private function accumulateCartera(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        $rows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->selectRaw('branch_id, SUM(balance) as cartera, COUNT(*) as contratos')
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['valor_cartera'] += (float) $row->cartera;
            $summaries[$suc]['contratos']     += (int)   $row->contratos;
        }
    }

    // ── Colocación ───────────────────────────────────────────────────────────

    private function accumulateColocacion(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        // Otorgamientos = SUM(Monto desembolsado) donde credit_origin IN ('DESEMBOLSO','REFINANCIAMIENTO').
        // amount siempre = Monto desembolsado (col 53). Excluir REESTRUCTURACIÓN y UNIFICACIÓN por credit_origin.
        // El Seguro CRECE/COMADRES NO se resta del KPI de colocación — se calcula aparte, solo informativo.
        $rows = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
            ->selectRaw("branch_id, SUM(amount) as colocacion, COUNT(*) as creditos,
                SUM(CASE
                    WHEN UPPER(COALESCE(product_name,'')) LIKE '%CRECE%' OR UPPER(COALESCE(product_name,'')) LIKE '%COMADRES%'
                    THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.seguro')) AS DECIMAL(14,2)), 0)
                    ELSE 0
                END) as seguro_informativo")
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['colocacion']         += (float) $row->colocacion;
            $summaries[$suc]['creditos_colocados'] += (int)   $row->creditos;
            $summaries[$suc]['seguro_crece_comadres_informativo'] += (float) $row->seguro_informativo;
        }
    }

    // ── Recuperación (PAGO + DESCUENTO, excluyendo CONDONACION / COBERTURA SAVEHEARTS / COMISIÓN POR APERTURA) ─

    private function accumulateRecuperacion(array $dataIds, array $branchIds, array $operativeMap, array &$summaries, array $corporativoIds = []): void
    {
        // Pass 1: branches already mapped to an operative sucursal via branch_id
        $rows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->whereIn('transaction', ['PAGO', 'DESCUENTO'])
            ->whereRaw("UPPER(COALESCE(concept, '')) NOT LIKE '%COBERTURA SAVEHEARTS%'")
            ->whereRaw("UPPER(COALESCE(operation, '')) NOT LIKE '%COMISION POR APERTURA%'")
            ->selectRaw('branch_id, SUM(capital) as capital, SUM(interest) as interest, SUM(tax) as tax, SUM(charges_due) as charges, SUM(total_amount) as total')
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['capital_recuperado']  += (float) $row->capital;
            $summaries[$suc]['interes_recuperado']  += (float) $row->interest;
            $summaries[$suc]['impuesto_recuperado'] += (float) $row->tax;
            $summaries[$suc]['charges']             += (float) $row->charges;
            $summaries[$suc]['recuperacion_total']  += (float) $row->total;
        }

        // Pass 2: route branches not in operativeMap (e.g. CUITLAHUAC, ATOTONILCO…)
        // Resolve to operative sucursal via contract prefix (supports legacy ORI09247 and new 25CUE0667939 formats).
        // CORPORATIVO branches are explicitly excluded.
        $fallback = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->whereIn('transaction', ['PAGO', 'DESCUENTO'])
            ->whereRaw("UPPER(COALESCE(concept, '')) NOT LIKE '%COBERTURA SAVEHEARTS%'")
            ->whereRaw("UPPER(COALESCE(operation, '')) NOT LIKE '%COMISION POR APERTURA%'")
            ->selectRaw('branch_id, contract, SUM(capital) AS capital, SUM(interest) AS interest, SUM(tax) AS tax, SUM(charges_due) AS charges, SUM(total_amount) AS total')
            ->groupBy('branch_id', 'contract')
            ->get();

        foreach ($fallback as $row) {
            $suc = $this->resolver->resolveBranchNameFromCode((string) $row->contract);
            if (!$suc || !$this->resolver->isSheetBranch($suc) || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['capital_recuperado']  += (float) $row->capital;
            $summaries[$suc]['interes_recuperado']  += (float) $row->interest;
            $summaries[$suc]['impuesto_recuperado'] += (float) $row->tax;
            $summaries[$suc]['charges']             += (float) $row->charges;
            $summaries[$suc]['recuperacion_total']  += (float) $row->total;
        }

        // Pass 3: COMISIÓN POR APERTURA (mapped branches)
        $comAp = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('operation', 'COMISIÓN POR APERTURA')
            ->selectRaw('branch_id, SUM(total_amount) as comision')
            ->groupBy('branch_id')
            ->get();

        foreach ($comAp as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) continue;
            $summaries[$suc]['comision_apertura'] += (float) $row->comision;
        }

        // Pass 4: COMISIÓN POR APERTURA (fallback route branches)
        $comApFb = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->where('operation', 'COMISIÓN POR APERTURA')
            ->selectRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3) AS prefix3, SUM(total_amount) AS comision")
            ->groupByRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3)")
            ->get();

        foreach ($comApFb as $row) {
            $prefix3 = strtoupper(trim((string) $row->prefix3));
            $suc     = $this->resolver->resolveBranchNameFromCode($prefix3);
            if (!$suc || !$this->resolver->isSheetBranch($suc) || !isset($summaries[$suc])) continue;
            $summaries[$suc]['comision_apertura'] += (float) $row->comision;
        }

        // Pass 5: ACUERDO CON CLIENTE — charges_due maps to cargos_inicio (mapped branches)
        $acuerdos = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('operation', 'ACUERDO CON CLIENTE')
            ->where('charges_due', '>', 0)
            ->selectRaw('branch_id, SUM(charges_due) as cargos')
            ->groupBy('branch_id')
            ->get();

        foreach ($acuerdos as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) continue;
            $summaries[$suc]['cargos_inicio'] += (float) $row->cargos;
        }

        // Pass 6: ACUERDO CON CLIENTE (fallback route branches)
        $acuerdosFb = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->where('operation', 'ACUERDO CON CLIENTE')
            ->where('charges_due', '>', 0)
            ->selectRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3) AS prefix3, SUM(charges_due) AS cargos")
            ->groupByRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3)")
            ->get();

        foreach ($acuerdosFb as $row) {
            $prefix3 = strtoupper(trim((string) $row->prefix3));
            $suc     = $this->resolver->resolveBranchNameFromCode($prefix3);
            if (!$suc || !$this->resolver->isSheetBranch($suc) || !isset($summaries[$suc])) continue;
            $summaries[$suc]['cargos_inicio'] += (float) $row->cargos;
        }
    }

    // ── Mora por bucket — FUENTE ÚNICA para cartera/mora (UI, Excel, PDF, dashboard) ──
    //
    // Columna de días: days_past_due (= "Días Vencido"). Sin ajustes/deltas de ningún tipo.
    // Monto: balance ("Saldo actual") — el contrato completo se bucketiza según su días vencido,
    // NO solo la porción atrasada. 0 días = al corriente (excluido de mora).
    // Buckets: 1-30 / 31-60 / 61-90 / 91-120 / 120+ (sin contaminar entre sí).

    private function accumulateMora(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        $rows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('days_past_due', '>', 0)
            ->selectRaw('branch_id, days_past_due, SUM(balance) as balance, COUNT(*) as cnt')
            ->groupBy('branch_id', 'days_past_due')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }

            $dpd     = (int) $row->days_past_due;
            $balance = (float) $row->balance;

            $bucket = match (true) {
                $dpd <= 30  => 'mora_0_30',
                $dpd <= 60  => 'mora_31_60',
                $dpd <= 90  => 'mora_61_90',
                $dpd <= 120 => 'mora_91_120',
                default     => 'mora_120_plus',
            };

            $summaries[$suc][$bucket]            += $balance;
            $summaries[$suc]["{$bucket}_cnt"]    += (int) $row->cnt;
        }
    }

    // ── Gastos por categoría (excedentes, fondeo, gastos_op) ────────────────
    //
    // Nómina is handled separately by accumulateNomina() using NOI payroll data.
    //
    // Source logic:
    //   • gastos_lendus → ALL categories (excl. nómina/excedentes/fondeo/nómina)
    //   • gastos_erp    → ONLY categories absent from Lendus (LENDUS_PRESENT_CATS)
    //                     to avoid double-counting overlapping categories.

    private function accumulateGastos(
        array $dataIds,
        array $branchIds,
        array $operativeMap,
        array &$summaries,
        array $corporativoIds = [],
        array &$unassigned = [],
    ): void {
        $sourceIds = DB::table('data_sources')
            ->whereIn('code', ['gastos_lendus', 'gastos_erp'])
            ->pluck('id', 'code');

        $lendusId = $sourceIds['gastos_lendus'] ?? null;
        $erpId    = $sourceIds['gastos_erp'] ?? null;

        // IDs to query: operative branches + corporativo (corporativo goes to unassigned)
        $queryIds = array_unique(array_merge($branchIds, $corporativoIds));

        $queries = [];

        if ($lendusId) {
            $queries[] = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.branch_id', $queryIds)
                ->where('ru.data_source_id', $lendusId)
                ->selectRaw("e.branch_id, COALESCE(e.category, 'Sin categoría') as category, COALESCE(e.concept, '') as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category', 'e.concept')
                ->get();
        }

        if ($erpId) {
            $queries[] = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.branch_id', $queryIds)
                ->where('ru.data_source_id', $erpId)
                ->whereNotIn('e.category', self::LENDUS_PRESENT_CATS)
                ->selectRaw("e.branch_id, COALESCE(e.category, 'Sin categoría') as category, COALESCE(e.concept, '') as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category', 'e.concept')
                ->get();
        }

        $corpIdSet = array_flip($corporativoIds);

        foreach ($queries as $rows) {
            foreach ($rows as $row) {
                $branchId = (int) $row->branch_id;
                $cat      = (string) $row->category;
                $catUpper = strtoupper($cat);
                $amt      = (float) $row->total;

                // Determine which bucket
                $isExcedente = $cat === self::EXCEDENTES_CAT || str_contains($catUpper, 'EXCEDENTE');
                $isFondeo    = $cat === self::FONDEO_CAT || str_contains($catUpper, 'FONDEO') || str_contains($catUpper, 'INTERSUCURSAL');
                $isNomina    = $cat === self::NOMINA_CAT || str_contains($catUpper, 'NOMINA') || str_contains($catUpper, 'NÓMINA');

                $suc = $operativeMap[$branchId] ?? null;
                $isCorp = isset($corpIdSet[$branchId]);

                $isNominaExpense = in_array($cat, self::NOMINA_EXPENSE_CATS, true);

                if ($suc && isset($summaries[$suc])) {
                    // Operative branch
                    if ($isExcedente)       { $summaries[$suc]['excedentes']       += $amt; }
                    elseif ($isFondeo)      { $summaries[$suc]['prestamos_fondea'] += $amt; }
                    elseif ($isNomina)      { /* skip — accumulateNomina() handles this */ }
                    elseif ($isNominaExpense){ /* skip — accumulateNomina() queries Gasolina/Celular separately */ }
                    else {
                        $summaries[$suc]['gastos_operativos'] += $amt;
                        $concept = (string)($row->concept ?? '');
                        $canonical = $this->canonicalGastoConcept($cat, $concept);
                        $summaries[$suc]['gastos_detalle'][$canonical] = ($summaries[$suc]['gastos_detalle'][$canonical] ?? 0.0) + $amt;
                    }
                } elseif ($isCorp) {
                    // CORPORATIVO gastos go to unassigned (excedentes/fondeo from corp still tracked on corp branch normally)
                    if (!$isExcedente && !$isFondeo && !$isNomina) {
                        $unassigned['gastos_operativos'] += $amt;
                        $unassigned['gastos_items'][] = [
                            'concepto' => $cat,
                            'monto'    => $amt,
                            'origen'   => 'CORPORATIVO (ERP/Lendus)',
                        ];
                    }
                }
                // Norte and other non-operative non-corp branches remain excluded
            }
        }
    }

    // ── Nómina / Comisiones / Bonos (via employee_branch_assignments) ───────
    //
    // Per-branch allocation uses employee_branch_assignments directly.
    // Employees WITHOUT a branch assignment → unassigned bucket.
    // Employees assigned to a non-operative branch → unassigned bucket.
    // GLOBAL = SUM(branches) + unassigned (all employees always counted).
    //
    // Sources: NOMINANUEVA (noi_nomina) + NOMINAF (noi_nomina_fiscal).
    // P001 SUELDO → nomina_total | P002 → comisiones | P108/109/120/123 → bonos.

    private function accumulateNomina(array $dataIds, array $operativeMap, array &$summaries, array &$unassigned): void
    {
        $operativeIds = array_keys($operativeMap);

        // ── Percepciones NOI: P001 Sueldo, P002 Comisiones, P009 Vacaciones, P010 Prima, P1xx Bonos
        $rows = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->leftJoin('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $dataIds)
            ->where('n.concept_type', 'percepcion')
            ->whereRaw("n.concept REGEXP '^P(001|002|009|010|108|109|120|123)'")
            ->selectRaw("
                COALESCE(eba.branch_id, -1) AS assigned_branch_id,
                n.concept,
                SUM(n.amount) AS total,
                COUNT(DISTINCT n.employee_id) AS emps
            ")
            ->groupByRaw("COALESCE(eba.branch_id, -1), n.concept")
            ->get();

        foreach ($rows as $row) {
            $branchId = (int) $row->assigned_branch_id;
            $concept  = (string) $row->concept;
            $amount   = (float) $row->total;

            $bucket = match (true) {
                str_starts_with($concept, 'P001')                          => 'nomina_total',
                str_starts_with($concept, 'P002')                          => 'comisiones',
                str_starts_with($concept, 'P009')                          => 'vacaciones',
                str_starts_with($concept, 'P010')                          => 'prima_vacacional',
                (bool) preg_match('/^P(108|109|120|123)/', $concept)       => 'bonos',
                default                                                     => null,
            };

            if ($bucket === null) {
                continue;
            }

            if ($branchId === -1) {
                $unassigned[$bucket] += $amount;
                continue;
            }

            $suc = $operativeMap[$branchId] ?? null;
            if ($suc && isset($summaries[$suc])) {
                $summaries[$suc][$bucket] += $amount;
            } else {
                $unassigned[$bucket] += $amount;
            }
        }

        // ── Deducciones NOI relevantes para nómina
        // D111 (Subsidio APL) y D137 (Diferencia NF) ajustan nomina_total, no se muestran por separado.
        // D004 (Préstamo Personal) se muestra como su propia línea (positiva, sumada al total
        // bruto de Nómina y Capital Humano, igual que D094/D113/D010/D123); el neto por
        // gestor/empleado es donde realmente se resta — ver NOI_DEDUCTION_LABELS en Excel/PDF/UI.
        $dedRows = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->whereIn('n.period_id', $dataIds)
            ->where('n.concept_type', 'deduccion')
            ->whereRaw("n.concept REGEXP '^D(094|010|113|123|111|137|004)'")
            ->selectRaw("COALESCE(eba.branch_id, -1) AS assigned_branch_id, n.concept, SUM(n.amount) AS total")
            ->groupByRaw("COALESCE(eba.branch_id, -1), n.concept")
            ->get();

        foreach ($dedRows as $row) {
            $branchId = (int) $row->assigned_branch_id;
            $concept  = (string) $row->concept;
            $amount   = (float) $row->total;

            // D111: Subsidio APL se integra en nomina_total (fórmula: P001 + D111 − D137)
            // D137: Diferencia NF reduce nomina_total
            if (str_starts_with($concept, 'D111') || str_starts_with($concept, 'D137')) {
                $adjust = str_starts_with($concept, 'D111') ? $amount : -$amount;
                if ($branchId === -1) {
                    $unassigned['nomina_total'] += $adjust;
                } else {
                    $suc = $operativeMap[$branchId] ?? null;
                    if ($suc && isset($summaries[$suc])) {
                        $summaries[$suc]['nomina_total'] += $adjust;
                    } else {
                        $unassigned['nomina_total'] += $adjust;
                    }
                }
                continue;
            }

            $label = match (true) {
                str_starts_with($concept, 'D094') => 'Descuentos Infonavit',
                str_starts_with($concept, 'D010') => 'Pensión Alimenticia',
                str_starts_with($concept, 'D113') => 'Descuento Servicios Moto',
                str_starts_with($concept, 'D123') => 'Financiamiento de Motos',
                str_starts_with($concept, 'D004') => 'Préstamo Personal',
                // Código NOI fuera del whitelist de arriba — no debería ocurrir porque la
                // query ya filtra por REGEXP, pero si algún día se agrega un código nuevo al
                // REGEXP sin agregar su línea aquí, lo identificamos por su propio código en
                // vez de esconderlo bajo una etiqueta genérica "Otros".
                default                           => "Deducción NOI {$concept}",
            };

            if ($branchId === -1) {
                $unassigned['nomina_detalle'][$label] = ($unassigned['nomina_detalle'][$label] ?? 0.0) + $amount;
                continue;
            }

            $suc = $operativeMap[$branchId] ?? null;
            if ($suc && isset($summaries[$suc])) {
                $summaries[$suc]['nomina_detalle'][$label] = ($summaries[$suc]['nomina_detalle'][$label] ?? 0.0) + $amount;
            } else {
                $unassigned['nomina_detalle'][$label] = ($unassigned['nomina_detalle'][$label] ?? 0.0) + $amount;
            }
        }

        // ── Gastos de nómina desde fact_expenses (por sucursal): Gasolina, Finiquito,
        //    Gastos Médicos, etc. PAGO DE IMSS excluido (no es gasto operativo del período).
        if (!empty($operativeIds)) {
            $sourceIds = DB::table('data_sources')
                ->whereIn('code', ['gastos_lendus', 'gastos_erp'])
                ->pluck('id');

            $expRows = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                // Antes restringía a whereIn('e.branch_id', $operativeIds), lo que excluía por
                // completo (ni siquiera a "unassigned") los gastos de nómina sin sucursal
                // asignada — se perdía el monto en silencio. Ahora se incluyen también las filas
                // sin sucursal; el loop de abajo ya las enruta a unassigned correctamente.
                ->where(function ($q) use ($operativeIds) {
                    $q->whereIn('e.branch_id', $operativeIds)->orWhereNull('e.branch_id');
                })
                ->whereIn('ru.data_source_id', $sourceIds)
                ->where(function ($q) {
                    $q->whereIn('e.category', ['Gasolina', 'Financiamiento Celular'])
                      ->orWhere(function ($q2) {
                          $q2->whereIn('e.category', ['Nómina y Capital Humano', 'Nomina y Capital Humano'])
                             ->whereNotIn('e.concept', [
                                 'NOMINA', 'DEDUCCIONES', 'DEDUCCIONES GENERALES',
                                 'PAGO DE IMSS', 'GASTOS EMERGENTES', 'GASTOS POR TRANSPORTE',
                             ]);
                      });
                })
                ->selectRaw("e.branch_id, e.category, COALESCE(e.concept,'') as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category', 'e.concept')
                ->get();

            foreach ($expRows as $row) {
                $branchId = (int) $row->branch_id;
                $cat      = (string) $row->category;
                $con      = strtoupper(trim((string) $row->concept));
                $amount   = (float) $row->total;

                $label = $this->canonicalNominaExpense($cat, $con);

                $suc = $operativeMap[$branchId] ?? null;
                if ($suc && isset($summaries[$suc])) {
                    $summaries[$suc]['nomina_detalle'][$label] = ($summaries[$suc]['nomina_detalle'][$label] ?? 0.0) + $amount;
                } else {
                    $unassigned['nomina_detalle'][$label] = ($unassigned['nomina_detalle'][$label] ?? 0.0) + $amount;
                }
            }

            // ── Conceptos gastos_lendus_excel (PAGO FINANCIAMIENTO MOTO, COMPRA DE CASCOS,
            //    PAGO FINANCIAMIENTO CELULAR): estos 3 son el ÚNICO detalle por sucursal/global
            //    de este archivo que no es un resumen duplicado de otra fuente — el resto de sus
            //    filas (NOMINA, IMSS, FINIQUITO, etc.) sí son resúmenes ya cubiertos por NOI o por
            //    gastos_lendus/gastos_erp y se excluyen a propósito (ver canonicalNominaExpense()).
            //    Con branch_id asignado → van al bucket de su sucursal.
            //    Sin branch_id (NULL) → van al bucket global (unassigned).
            $lendusExcelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
            if ($lendusExcelId) {
                $lendusRows = DB::table('fact_expenses as e')
                    ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                    ->whereIn('e.period_id', $dataIds)
                    ->where('ru.data_source_id', $lendusExcelId)
                    ->whereIn('e.category', ['Nómina y Capital Humano', 'Nomina y Capital Humano', 'Financiamiento Celular'])
                    ->whereIn(DB::raw('UPPER(TRIM(COALESCE(e.concept,\'\')))'), ['PAGO FINANCIAMIENTO MOTO', 'COMPRA DE CASCOS', 'PAGO FINANCIAMIENTO CELULAR'])
                    ->selectRaw("e.branch_id, UPPER(TRIM(COALESCE(e.concept,''))) as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                    ->groupBy('e.branch_id', 'e.concept')
                    ->get();

                foreach ($lendusRows as $row) {
                    $con    = (string) $row->concept;
                    $label  = match (true) {
                        str_contains($con, 'FINANCIAMIENTO MOTO')    => 'Financiamiento de Motos',
                        str_contains($con, 'CASCO')                  => 'Cascos',
                        str_contains($con, 'FINANCIAMIENTO CELULAR') => 'Financiamiento Celular',
                        default                                       => null,
                    };
                    if ($label === null) continue;
                    $amt = (float) $row->total;

                    if ($row->branch_id === null) {
                        $unassigned['nomina_detalle'][$label] = ($unassigned['nomina_detalle'][$label] ?? 0.0) + $amt;
                    } else {
                        $brId = (int) $row->branch_id;
                        $suc  = $operativeMap[$brId] ?? null;
                        if ($suc && isset($summaries[$suc])) {
                            $summaries[$suc]['nomina_detalle'][$label] = ($summaries[$suc]['nomina_detalle'][$label] ?? 0.0) + $amt;
                        } else {
                            $unassigned['nomina_detalle'][$label] = ($unassigned['nomina_detalle'][$label] ?? 0.0) + $amt;
                        }
                    }
                }
            }
        }

        // Collect per-employee detail for the SIN ASIGNAR sheet
        $unassignedEmps = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->leftJoin('employees as emp', 'n.employee_id', '=', 'emp.id')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->where('n.concept_type', 'percepcion')
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
            ->whereNull('eba.employee_id')
            ->selectRaw("
                COALESCE(emp.full_name, CONCAT('Emp#', n.employee_id)) AS nombre,
                n.employee_id,
                ds.code AS fuente,
                SUM(CASE WHEN n.concept LIKE 'P001%' THEN n.amount ELSE 0 END) AS p001,
                SUM(CASE WHEN n.concept LIKE 'P002%' THEN n.amount ELSE 0 END) AS p002,
                SUM(CASE WHEN n.concept REGEXP '^P(108|109|120|123)' THEN n.amount ELSE 0 END) AS bonos
            ")
            ->groupBy('n.employee_id', 'emp.full_name', 'ds.id', 'ds.code')
            ->orderByDesc('p001')
            ->get();

        foreach ($unassignedEmps as $emp) {
            $unassigned['empleados'][] = [
                'nombre'  => $emp->nombre,
                'emp_id'  => $emp->employee_id,
                'fuente'  => $emp->fuente,
                'p001'    => (float) $emp->p001,
                'p002'    => (float) $emp->p002,
                'bonos'   => (float) $emp->bonos,
            ];
        }
    }

    // ── Pólizas CRECE (savehearts_crece_share) ───────────────────────────────

    private function accumulatePolizasCrece(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        if (empty($branchIds)) {
            return;
        }

        $rows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('savehearts_crece_share', '>', 0)
            ->selectRaw('branch_id, SUM(savehearts_crece_share) as crece_share')
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['polizas_crece_30'] += (float) $row->crece_share;
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function emptyBranchSummary(string $sucursal): array
    {
        return [
            'sucursal'            => $sucursal,
            'valor_cartera'       => 0.0,
            'contratos'           => 0,
            'colocacion'          => 0.0,
            'creditos_colocados'  => 0,
            'seguro_crece_comadres_informativo' => 0.0,
            'capital_recuperado'  => 0.0,
            'interes_recuperado'  => 0.0,
            'impuesto_recuperado' => 0.0,
            'charges'             => 0.0,
            'cargos_inicio'       => 0.0,
            'comision_apertura'   => 0.0,
            'recuperacion_total'  => 0.0,
            'mora_0_30'           => 0.0,
            'mora_31_60'          => 0.0,
            'mora_61_90'          => 0.0,
            'mora_91_120'         => 0.0,
            'mora_120_plus'       => 0.0,
            'mora_0_30_cnt'       => 0,
            'mora_31_60_cnt'      => 0,
            'mora_61_90_cnt'      => 0,
            'mora_91_120_cnt'     => 0,
            'mora_120_plus_cnt'   => 0,
            'gastos_operativos'   => 0.0,
            'gastos_detalle'      => [],   // canonical_concept => amount (summed from source data)
            'nomina_total'        => 0.0,
            'comisiones'          => 0.0,
            'bonos'               => 0.0,
            'vacaciones'          => 0.0,
            'prima_vacacional'    => 0.0,
            'nomina_detalle'      => [],   // display_label => amount (IMSS, gasolina, finiquito, etc.)
            'excedentes'          => 0.0,
            'prestamos_fondea'    => 0.0,
            'polizas_crece_30'    => 0.0,
        ];
    }

    /**
     * Maps a fact_expenses category + concept to a canonical nómina display label.
     */
    /**
     * Maps a raw fact_expenses category/concept (Nómina y Capital Humano) to its canonical
     * display label. Never falls back to a generic "Otros conceptos nómina" bucket — an
     * unmapped concept is identified by its own real name (visible in
     * RadiographyCalculator::AuditNominaMapeoCommand) instead of being hidden under a vague
     * label, per the rule that every peso must be traceable to its source concept.
     */
    private function canonicalNominaExpense(string $category, string $concept): string
    {
        $cat = $this->normalizeConceptText($category);
        $con = $this->normalizeConceptText($concept);

        if ($cat === 'GASOLINA') {
            return 'Gasolina';
        }
        if ($cat === 'FINANCIAMIENTO CELULAR' || str_contains($con, 'CELULAR')) {
            return 'Financiamiento Celular';
        }
        return match (true) {
            str_contains($con, 'IMSS')                                                              => 'IMSS',
            str_contains($con, 'CASCO')                                                             => 'Cascos',
            str_contains($con, 'MEDICO') || str_contains($con, 'MÉDICO')                           => 'Gastos médicos',
            str_contains($con, 'FINIQUITO')                                                         => 'Finiquito',
            str_contains($con, 'FORMATERIA') || str_contains($con, 'FORMATERÍA')                   => 'Formatería',
            str_contains($con, 'FINANCIAMIENTO MOTO') || str_contains($con, 'MOTO')                => 'Financiamiento de Motos',
            str_contains($con, 'UNIFORME')                                                          => 'Descuento de uniformes',
            str_contains($con, 'INFONAVIT')                                                         => 'Descuentos Infonavit',
            str_contains($con, 'PENSION') || str_contains($con, 'PENSIÓN')                         => 'Pensión Alimenticia',
            str_contains($con, 'MR LANA') || str_contains($con, 'TIENDA')                          => 'Descuentos Tienda Mr Lana',
            str_contains($con, 'PRESTAMO Z') || str_contains($con, 'PRÉSTAMO Z')                   => 'Anticipo de nómina',
            default => $this->unmappedNominaLabel($category, $concept),
        };
    }

    /**
     * Collapses any whitespace variant (regular space, non-breaking space \u{00A0}, tabs) to a
     * single space before matching. Source files (Lendus exports) sometimes encode the spaces
     * between words as non-breaking spaces, which silently breaks str_contains() matching and
     * was the root cause of legitimate concepts (e.g. "PAGO·PRESTAMO·Z" with NBSPs) falling into
     * an unmapped/"Otros" bucket instead of their real label.
     */
    private function normalizeConceptText(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}]+/u', ' ', strtoupper($text)) ?? '');
    }

    /**
     * An expense concept under "Nómina y Capital Humano" that doesn't match any known label.
     * Logged for audit (see reportes:audit-nomina-mapeo) and shown in the report under its own
     * real concept name — never silently merged into a generic "Otros" line.
     */
    private function unmappedNominaLabel(string $category, string $concept): string
    {
        \Illuminate\Support\Facades\Log::warning('Concepto de nómina sin mapeo canónico — revisar canonicalNominaExpense().', [
            'category' => $category,
            'concept'  => $concept,
        ]);

        $clean = trim($concept) !== '' ? trim($concept) : trim($category);

        return 'Sin clasificar: ' . $clean;
    }

    /**
     * Maps a raw DB expense category + concept to the canonical display name used in the Excel.
     * Concept is used when category is generic (e.g. 'Gastos Operativos').
     * Unrecognized categories fall back to 'Emergentes' so no amount is lost.
     */
    private function canonicalGastoConcept(string $category, string $concept = ''): string
    {
        $cat  = strtoupper(trim($category));
        $con  = strtoupper(trim($concept));
        // Combined string for catch-all matching
        $both = $cat . ' ' . $con;

        // Exact category matches first (most specific)
        return match (true) {
            // --- Category-level direct matches ---
            str_contains($cat, 'INSUMOS DE CAFETERIA') || str_contains($cat, 'INSUMOS DE CAFETERÍA')        => 'Insumos de Cafetería',
            str_contains($cat, 'INSUMOS DE LIMPIEZA')                                                        => 'Insumos de Limpieza',
            str_contains($cat, 'INSUMOS DE PAPELERIA') || str_contains($cat, 'INSUMOS DE PAPELERÍA')        => 'Insumos de Papelería',
            str_contains($cat, 'SERVICIOS DE LIMPIEZA')                                                      => 'Señora Limpieza',
            str_contains($cat, 'SERVICIOS DE MOTOCICLETAS') || str_contains($cat, 'SERVICIOS MOTO')         => 'Servicios de Motocicletas',
            str_contains($cat, 'SOFTWARE PÓLIZA') || str_contains($cat, 'SOFTWARE POLIZA')                  => 'Software Póliza Anual',
            str_contains($cat, 'RENTA DE BODEGAS') || str_contains($cat, 'RENTAS BODEGAS')                  => 'Renta de Bodegas',
            str_contains($cat, 'RENTA OFICINA') || str_contains($cat, 'RENTAS OFICINAS')                    => 'Renta Oficina',
            str_contains($cat, 'RECARGAS TELEFÓNICAS') || str_contains($cat, 'RECARGAS TELEFONICAS')        => 'Recargas Telefónicas',
            str_contains($cat, 'COMISIONES OXXO')                                                            => 'Comisiones Oxxo',
            str_contains($cat, 'MULTAS E INFRACCIONES')                                                      => 'Multas e Infracciones',
            str_contains($cat, 'TRÁMITES GUBERNAMENTALES') || str_contains($cat, 'TRAMITES GUBERNAMENTALES') => 'Trámites Gubernamentales',
            str_contains($cat, 'MOBILIARIO Y EQUIPO')                                                        => 'Mobiliario y Equipo',
            str_contains($cat, 'MANTENIMIENTO')                                                              => 'Mantenimiento',
            str_contains($cat, 'PAQUETERÍA') || str_contains($cat, 'PAQUETERIA')                            => 'Paquetería',
            str_contains($cat, 'TELÉFONO E INTERNET') || str_contains($cat, 'TELEFONO E INTERNET')          => 'Teléfono e Internet',
            str_contains($cat, 'PÓLIZAS') || str_contains($cat, 'POLIZAS')                                  => 'Pólizas',
            str_contains($cat, 'PUBLICIDAD')                                                                  => 'Publicidad',
            str_contains($cat, 'MECÁNICOS') || str_contains($cat, 'MECANICOS')                              => 'Mecánicos',
            str_contains($cat, 'TRANSPORTES')                                                                => 'Transportes',
            str_contains($cat, 'PEGOTES')                                                                    => 'Pegotes',
            str_contains($cat, 'PERMISOS VEHICULARES')                                                       => 'Permisos Vehiculares',
            str_contains($cat, 'VIÁTICOS') || str_contains($cat, 'VIATICOS')                                => 'Viáticos',
            str_contains($cat, 'FLETES')                                                                     => 'Fletes',
            str_contains($cat, 'FORMATERÍA') || str_contains($cat, 'FORMATERIA')                            => 'Formatería',
            str_contains($cat, 'EVENTOS')                                                                    => 'Eventos',
            str_contains($cat, 'GASTOS LEGALES')                                                             => 'Gastos legales',
            $cat === 'LUZ'                                                                                    => 'Luz',
            $cat === 'AGUA'                                                                                   => 'Agua',
            // --- Concept-level matching for generic categories (e.g. 'Gastos Operativos') ---
            str_contains($both, 'CAFETERIA') || str_contains($both, 'CAFETERÍA')                            => 'Insumos de Cafetería',
            str_contains($both, 'LIMPIEZA') && str_contains($both, 'SEÑORA')                                 => 'Señora Limpieza',
            str_contains($both, 'LIMPIEZA')                                                                  => 'Insumos de Limpieza',
            str_contains($both, 'PAPELERIA') || str_contains($both, 'PAPELERÍA')                            => 'Insumos de Papelería',
            str_contains($both, 'RENTA') && str_contains($both, 'BODEGA')                                   => 'Renta de Bodegas',
            str_contains($both, 'RENTA') && !str_contains($both, 'BODEGA')                                  => 'Renta Oficina',
            str_contains($both, 'OXXO') || str_contains($both, 'TRANSACCION')                               => 'Comisiones Oxxo',
            str_contains($both, 'MOTOCICLETA') || str_contains($both, 'MOTO') && str_contains($both, 'SERVICIO') => 'Servicios de Motocicletas',
            str_contains($both, 'POLIZA ANUAL') || str_contains($both, 'PÓLIZA ANUAL') || str_contains($both, 'SOFTWARE') || str_contains($both, 'LICENCIA') => 'Software Póliza Anual',
            str_contains($both, 'POLIZA') || str_contains($both, 'PÓLIZA') || str_contains($both, 'SEGURO') => 'Pólizas',
            str_contains($both, 'RECARGA')                                                                   => 'Recargas Telefónicas',
            str_contains($both, 'INTERNET') || str_contains($both, 'TELEFON')                               => 'Teléfono e Internet',
            str_contains($both, 'LUZ') || str_contains($both, 'ELECTR')                                     => 'Luz',
            str_contains($both, 'AGUA')                                                                      => 'Agua',
            str_contains($both, 'MOBILIARIO') || str_contains($both, 'EQUIPO DE COMPUTO') || str_contains($both, 'ACCESORIOS EQUIPO') => 'Mobiliario y Equipo',
            str_contains($both, 'MANTENIMIENTO')                                                             => 'Mantenimiento',
            str_contains($both, 'PAQUETERIA') || str_contains($both, 'PAQUETERÍA') || str_contains($both, 'ENVIO') => 'Paquetería',
            str_contains($both, 'GUBERNAMENTAL') || str_contains($both, 'TRAMITE')                          => 'Trámites Gubernamentales',
            str_contains($both, 'PUBLICIDAD')                                                                => 'Publicidad',
            str_contains($both, 'MECANICO') || str_contains($both, 'MECÁNICO')                              => 'Mecánicos',
            str_contains($both, 'MULTA') || str_contains($both, 'INFRACCION')                               => 'Multas e Infracciones',
            str_contains($both, 'TRANSPORTE') || str_contains($both, 'TAXI') || str_contains($both, 'RUTA') || str_contains($both, 'DILIGENCIA') => 'Transportes',
            str_contains($both, 'PEGOTE')                                                                    => 'Pegotes',
            str_contains($both, 'PERMISO')                                                                   => 'Permisos Vehiculares',
            str_contains($both, 'VIATICO') || str_contains($both, 'VIÁTICO') || str_contains($both, 'SUPERVISION') || str_contains($both, 'CAPACITACION') => 'Viáticos',
            str_contains($both, 'FLETE')                                                                     => 'Fletes',
            str_contains($both, 'FORMATERIA') || str_contains($both, 'FORMATERÍA')                          => 'Formatería',
            str_contains($both, 'EVENTO')                                                                    => 'Eventos',
            str_contains($both, 'LEGAL') || str_contains($both, 'DOMINIO')                                  => 'Gastos legales',
            default                                                                                          => 'Emergentes',
        };
    }

    private function emptyUnassigned(): array
    {
        return [
            'nomina_total'      => 0.0,
            'comisiones'        => 0.0,
            'bonos'             => 0.0,
            'vacaciones'        => 0.0,
            'prima_vacacional'  => 0.0,
            'nomina_detalle'    => [],   // display_label => amount
            'gastos_operativos' => 0.0,
            'empleados'         => [],   // per-employee detail for SIN ASIGNAR sheet
            'gastos_items'      => [],   // per-gasto detail for SIN ASIGNAR sheet
        ];
    }
}
