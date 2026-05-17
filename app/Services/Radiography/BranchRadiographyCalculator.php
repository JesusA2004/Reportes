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
     * Builds per-branch summaries for the 13 operative branches.
     *
     * Returns an indexed array of branch_summary objects.
     */
    public function buildBranches(Period $period, array $dataIds): array
    {
        $maps            = $this->buildBranchMap();
        $operativeMap    = $maps['operative'];
        $operativeIds    = array_keys($operativeMap);

        // Initialize all 13 branches even if they have no data this period
        $summaries = [];
        foreach ($this->resolver->operativeFinancialBranches() as $sucursal) {
            $summaries[$sucursal] = $this->emptyBranchSummary($sucursal);
        }

        if (empty($operativeIds)) {
            return array_values($summaries);
        }

        $corporativoIds = $maps['corporativo'];

        $this->accumulateCartera($dataIds, $operativeIds, $operativeMap, $summaries);
        $this->accumulateColocacion($dataIds, $operativeIds, $operativeMap, $summaries);
        $this->accumulateRecuperacion($dataIds, $operativeIds, $operativeMap, $summaries, $corporativoIds);
        $daysDelta = $this->computeDpdDelta($period, $dataIds);
        $this->accumulateMora($dataIds, $operativeIds, $operativeMap, $summaries, $daysDelta);
        $this->accumulateGastos($dataIds, $operativeIds, $operativeMap, $summaries);
        $this->accumulateNomina($dataIds, $operativeIds, $operativeMap, $summaries);

        return array_values($summaries);
    }

    /**
     * Sums all branch metrics to produce the GLOBAL summary.
     * GLOBAL = SUM(branches) — never recalculated from raw facts independently.
     */
    public function sumGlobal(array $branches): array
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

    private function accumulateGastos(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        $sourceIds = DB::table('data_sources')
            ->whereIn('code', ['gastos_lendus', 'gastos_erp'])
            ->pluck('id', 'code');

        $lendusId = $sourceIds['gastos_lendus'] ?? null;
        $erpId    = $sourceIds['gastos_erp'] ?? null;

        $queries = [];

        // Lendus: all categories (excedentes/fondeo/nómina split in PHP below)
        if ($lendusId) {
            $queries[] = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.branch_id', $branchIds)
                ->where('ru.data_source_id', $lendusId)
                ->selectRaw("e.branch_id, COALESCE(e.category, 'Sin categoría') as category, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category')
                ->get();
        }

        // ERP: only categories NOT present in Lendus (avoids double-count)
        if ($erpId) {
            $queries[] = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('e.branch_id', $branchIds)
                ->where('ru.data_source_id', $erpId)
                ->whereNotIn('e.category', self::LENDUS_PRESENT_CATS)
                ->selectRaw("e.branch_id, COALESCE(e.category, 'Sin categoría') as category, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.branch_id', 'e.category')
                ->get();
        }

        foreach ($queries as $rows) {
            foreach ($rows as $row) {
                $suc = $operativeMap[(int) $row->branch_id] ?? null;
                if (!$suc || !isset($summaries[$suc])) {
                    continue;
                }

                $cat      = (string) $row->category;
                $catUpper = strtoupper($cat);
                $amt      = (float)  $row->total;

                if ($cat === self::EXCEDENTES_CAT || str_contains($catUpper, 'EXCEDENTE')) {
                    $summaries[$suc]['excedentes'] += $amt;
                } elseif ($cat === self::FONDEO_CAT || str_contains($catUpper, 'FONDEO') || str_contains($catUpper, 'INTERSUCURSAL')) {
                    $summaries[$suc]['prestamos_fondea'] += $amt;
                } elseif ($cat === self::NOMINA_CAT || str_contains($catUpper, 'NOMINA') || str_contains($catUpper, 'NÓMINA')) {
                    // skip — nómina is accumulated by accumulateNomina() from NOI data
                } else {
                    $summaries[$suc]['gastos_operativos'] += $amt;
                }
            }
        }
    }

    // ── Nómina (NOI P001 SUELDO, allocated via Lendus NOMINA proportions) ───
    //
    // GLOBAL nómina = SUM of NOI P001 SUELDO (base salary only, ~3% below target).
    // Per-branch allocation uses Lendus "NOMINA" concept amounts as weights,
    // then scales the entire branch distribution to match the NOI total.

    private function accumulateNomina(array $dataIds, array $branchIds, array $operativeMap, array &$summaries): void
    {
        $noiTotal = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->where('concept', 'P001 SUELDO')
            ->where('concept_type', 'percepcion')
            ->sum('amount');

        if ($noiTotal <= 0) {
            return;
        }

        $lendusId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');

        if (!$lendusId) {
            return;
        }

        // Lendus "NOMINA" concept amounts per operative branch (for proportional weights)
        $lendusRows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('e.branch_id', $branchIds)
            ->where('ru.data_source_id', $lendusId)
            ->where('e.concept', 'NOMINA')
            ->selectRaw('e.branch_id, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total')
            ->groupBy('e.branch_id')
            ->get();

        $lendusTotal = (float) $lendusRows->sum(fn ($r) => (float) $r->total);

        if ($lendusTotal <= 0) {
            return;
        }

        $scaleFactor = $noiTotal / $lendusTotal;

        foreach ($lendusRows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($summaries[$suc])) {
                continue;
            }
            $summaries[$suc]['nomina_total'] += (float) $row->total * $scaleFactor;
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
            'excedentes'          => 0.0,
            'prestamos_fondea'    => 0.0,
        ];
    }
}
