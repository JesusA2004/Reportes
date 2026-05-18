<?php

namespace App\Services\Radiography;

use App\Models\Period;
use App\Services\BranchResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds per-branch financial summaries for the 13 operative sucursales.
 * GLOBAL is always derived as the sum of those branch summaries,
 * never recalculated independently from all fact_* rows.
 */
class BranchRadiographyCalculator
{
    private const EXCEDENTES_CAT = 'Envío de utilidad a corporativo';
    private const FONDEO_CAT     = 'Préstamos Intersucursales';
    private const NOMINA_CAT     = 'Nómina y Capital Humano';

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
     *   'operative'   => [branch_id => real_sucursal_name]  (only the 13 sheet branches)
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
     * Builds per-branch summaries for the 13 operative branches plus an unassigned bucket.
     *
     * Returns ['branches' => [...13 summaries...], 'unassigned' => [nómina/comisiones/bonos/gastos sin sucursal]]
     * GLOBAL must be computed via sumGlobal($branches, $unassigned) to include unassigned amounts.
     */
    public function buildBranches(Period $period, array $dataIds): array
    {
        $maps            = $this->buildBranchMap();
        $operativeMap    = $maps['operative'];
        $operativeIds    = array_keys($operativeMap);
        $corporativoIds  = $maps['corporativo'];

        $summaries = [];
        foreach ($this->resolver->operativeFinancialBranches() as $sucursal) {
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
        $daysDelta = $this->computeDpdDelta($period, $dataIds);
        $this->accumulateMora($dataIds, $operativeIds, $operativeMap, $summaries, $daysDelta);

        // Gastos: branch_id de Lendus/ERP; Corporativo → unassigned; Norte → excluido
        $this->accumulateGastos($dataIds, $operativeIds, $operativeMap, $summaries, $corporativoIds, $unassigned);

        // Nómina/comisiones/bonos: employee_branch_assignments; sin match → unassigned
        $this->accumulateNomina($dataIds, $operativeMap, $summaries, $unassigned);

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
                if ($key === 'sucursal') {
                    continue;
                }
                if (array_key_exists($key, $global)) {
                    $global[$key] += $val;
                }
            }
        }

        // Add unassigned EMPLOYEE amounts to GLOBAL (employees belong to the period regardless of branch).
        // gastos_operativos de Corporativo NO se suman al GLOBAL operativo de las 13 sucursales.
        foreach (['nomina_total', 'comisiones', 'bonos'] as $key) {
            $global[$key] += (float) ($unassigned[$key] ?? 0);
        }

        return $global;
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
        $rows = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where(function ($q) {
                $q->whereNull('product_name')
                  ->orWhereRaw("product_name NOT REGEXP ?", ['REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS']);
            })
            ->selectRaw('branch_id, SUM(amount) as colocacion, COUNT(*) as creditos')
            ->groupBy('branch_id')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['colocacion']         += (float) $row->colocacion;
            $summaries[$suc]['creditos_colocados'] += (int)   $row->creditos;
        }
    }

    // ── Recuperación (solo transacciones PAGO) ───────────────────────────────

    private function accumulateRecuperacion(array $dataIds, array $branchIds, array $operativeMap, array &$summaries, array $corporativoIds = []): void
    {
        // Pass 1: branches already mapped to an operative sucursal via branch_id
        $rows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('branch_id, SUM(capital) as capital, SUM(interest) as interest, SUM(tax) as tax, SUM(GREATEST(total_amount - capital - interest - tax, 0)) as charges, SUM(total_amount) as total')
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
        // Resolve them to an operative sucursal via the accredited_name prefix (first 3 chars).
        // CORPORATIVO branches are explicitly excluded even if their prefix maps to an operative sucursal.
        $fallback = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $branchIds)
            ->when(!empty($corporativoIds), fn ($q) => $q->whereNotIn('branch_id', $corporativoIds))
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw("
                branch_id,
                LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3) AS prefix3,
                SUM(capital)      AS capital,
                SUM(interest)     AS interest,
                SUM(tax)          AS tax,
                SUM(GREATEST(total_amount - capital - interest - tax, 0)) AS charges,
                SUM(total_amount) AS total
            ")
            ->groupByRaw("branch_id, LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3)")
            ->get();

        foreach ($fallback as $row) {
            $prefix3 = strtoupper(trim((string) $row->prefix3));
            $suc     = $this->resolver->resolveBranchNameFromCode($prefix3);
            if (!$suc || !$this->resolver->isSheetBranch($suc) || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['capital_recuperado']  += (float) $row->capital;
            $summaries[$suc]['interes_recuperado']  += (float) $row->interest;
            $summaries[$suc]['impuesto_recuperado'] += (float) $row->tax;
            $summaries[$suc]['charges']             += (float) $row->charges;
            $summaries[$suc]['recuperacion_total']  += (float) $row->total;
        }
    }

    // ── Mora por bucket ──────────────────────────────────────────────────────
    //
    // days_past_due in fact_portfolios reflects the DPD at the time the
    // Lendus saldos file was uploaded, which is typically 13-16 days after
    // the period's end_date. We subtract $daysDelta to get the DPD at
    // period-end, and skip contracts that were current at that cutoff.

    private function accumulateMora(array $dataIds, array $branchIds, array $operativeMap, array &$summaries, int $daysDelta = 0): void
    {
        $rows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $branchIds)
            ->where('days_past_due', '>', 0)
            ->selectRaw('branch_id, days_past_due, SUM(COALESCE(past_due_balance, 0)) as mora, SUM(balance) as balance')
            ->groupBy('branch_id', 'days_past_due')
            ->get();

        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }

            $adjDpd = max(0, (int) $row->days_past_due - $daysDelta);

            if ($adjDpd === 0) {
                continue; // current at period-end cutoff
            }

            // Prefer past_due_balance; fall back to full balance when not populated
            $mora = (float) $row->mora > 0 ? (float) $row->mora : (float) $row->balance;

            $bucket = match (true) {
                $adjDpd <= 30  => 'mora_0_30',
                $adjDpd <= 60  => 'mora_31_60',
                $adjDpd <= 90  => 'mora_61_90',
                $adjDpd <= 120 => 'mora_91_120',
                default        => 'mora_120_plus',
            };

            $summaries[$suc][$bucket] += $mora;
        }
    }

    // Returns the number of days between the period's end_date and the
    // lendus_saldos_cliente upload date. Returns 0 when data is unavailable.
    private function computeDpdDelta(Period $period, array $dataIds): int
    {
        if (!$period->end_date) {
            return 0;
        }

        $uploadedAt = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('ru.period_id', $dataIds)
            ->where('ds.code', 'lendus_saldos_cliente')
            ->orderByDesc('ru.uploaded_at')
            ->value('ru.uploaded_at');

        if (!$uploadedAt) {
            return 0;
        }

        $endDate    = Carbon::parse($period->end_date)->startOfDay();
        $uploadDate = Carbon::parse($uploadedAt)->startOfDay();

        return max(0, (int) $endDate->diffInDays($uploadDate));
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
                ->selectRaw("e.branch_id, COALESCE(e.category, 'Sin categoría') as category, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category')
                ->get();
        }

        if ($erpId) {
            $queries[] = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.branch_id', $queryIds)
                ->where('ru.data_source_id', $erpId)
                ->whereNotIn('e.category', self::LENDUS_PRESENT_CATS)
                ->selectRaw("e.branch_id, COALESCE(e.category, 'Sin categoría') as category, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category')
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

                if ($suc && isset($summaries[$suc])) {
                    // Operative branch
                    if ($isExcedente)   { $summaries[$suc]['excedentes']       += $amt; }
                    elseif ($isFondeo)  { $summaries[$suc]['prestamos_fondea'] += $amt; }
                    elseif ($isNomina)  { /* skip — accumulateNomina() handles this */ }
                    else                { $summaries[$suc]['gastos_operativos'] += $amt; }
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
        $rows = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', 'n.employee_id', '=', 'eba.employee_id')
            ->leftJoin('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $dataIds)
            ->where('n.concept_type', 'percepcion')
            ->whereRaw("n.concept REGEXP '^P(001|002|108|109|120|123)'")
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
                (bool) preg_match('/^P(108|109|120|123)/', $concept)       => 'bonos',
                default                                                     => null,
            };

            if ($bucket === null) {
                continue;
            }

            if ($branchId === -1) {
                // No assignment in employee_branch_assignments
                $unassigned[$bucket] += $amount;
                continue;
            }

            $suc = $operativeMap[$branchId] ?? null;
            if ($suc && isset($summaries[$suc])) {
                $summaries[$suc][$bucket] += $amount;
            } else {
                // Assigned to a non-operative branch (Corp/Norte/etc.) → unassigned
                $unassigned[$bucket] += $amount;
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

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function emptyBranchSummary(string $sucursal): array
    {
        return [
            'sucursal'            => $sucursal,
            'valor_cartera'       => 0.0,
            'contratos'           => 0,
            'colocacion'          => 0.0,
            'creditos_colocados'  => 0,
            'capital_recuperado'  => 0.0,
            'interes_recuperado'  => 0.0,
            'impuesto_recuperado' => 0.0,
            'charges'             => 0.0,
            'recuperacion_total'  => 0.0,
            'mora_0_30'           => 0.0,
            'mora_31_60'          => 0.0,
            'mora_61_90'          => 0.0,
            'mora_91_120'         => 0.0,
            'mora_120_plus'       => 0.0,
            'gastos_operativos'   => 0.0,
            'nomina_total'        => 0.0,
            'comisiones'          => 0.0,
            'bonos'               => 0.0,
            'excedentes'          => 0.0,
            'prestamos_fondea'    => 0.0,
        ];
    }

    private function emptyUnassigned(): array
    {
        return [
            'nomina_total'      => 0.0,
            'comisiones'        => 0.0,
            'bonos'             => 0.0,
            'gastos_operativos' => 0.0,
            'empleados'         => [],   // per-employee detail for SIN ASIGNAR sheet
            'gastos_items'      => [],   // per-gasto detail for SIN ASIGNAR sheet
        ];
    }
}
