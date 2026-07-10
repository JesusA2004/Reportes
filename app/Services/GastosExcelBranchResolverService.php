<?php

namespace App\Services;

use App\Models\Period;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and persists branch_id/employee_id on gastos_lendus_excel rows that import
 * with branch_id = NULL (that file has no sucursal column at all — confirmed: 672 of 848
 * rows for Junio 2026, $4,235,962.85).
 *
 * Root cause / fix: gastos_lendus (PDF) and gastos_lendus_excel (Excel) are two exports of
 * the EXACT SAME underlying transactions — verified for Junio 2026: identical row count (848)
 * and identical total ($4,393,164.00) across both sources. The PDF import already resolves
 * branch_id for 100% of its rows (via BranchResolverService at import time), while the Excel
 * import never does. So instead of inventing a new name-matching heuristic, this pairs each
 * Excel row to its PDF counterpart by (amount, expense_date) — verified 100% match rate with
 * zero group-size mismatches for Junio 2026 — and copies branch_id/employee_id across.
 *
 * Idempotent: rows already carrying a branch_id are left untouched and reported as-is.
 */
class GastosExcelBranchResolverService
{
    /**
     * @return array<int, array{
     *   fact_expense_id:int, category:string, concept:string, amount:float, expense_date:?string,
     *   period_id:int, report_upload_id:int, branch_id:?int, sucursal:?string, employee_id:?int,
     *   metodo:string, estado:string,
     * }>
     */
    public function resolveForPeriod(Period $period, array $dataIds, bool $dryRun = false): array
    {
        $operativeMap = app(\App\Services\Radiography\BranchRadiographyCalculator::class)
            ->buildBranchMap()['operative'];

        $pdfId   = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        $excelId = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');
        if (!$pdfId || !$excelId) {
            return [];
        }

        // PDF rows: 100% resolved branch_id (fully populated at import time). Group by
        // (amount, expense_date) preserving row id order within each group.
        $pdfRows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $pdfId)
            ->whereNotNull('e.branch_id')
            ->orderBy('e.id')
            ->get(['e.id', 'e.amount', 'e.expense_date', 'e.branch_id', 'e.employee_id']);

        $pdfGroups = [];
        foreach ($pdfRows as $r) {
            $key = $this->groupKey((float) $r->amount, $r->expense_date);
            $pdfGroups[$key][] = $r;
        }

        $excelRows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $excelId)
            ->whereNull('e.branch_id')
            ->orderBy('e.id')
            ->get(['e.id', 'e.category', 'e.concept', 'e.amount', 'e.expense_date', 'e.period_id', 'e.report_upload_id']);

        // Position counter per group so multiple same (amount, date) rows pair 1:1 in order.
        $cursor  = [];
        $results = [];

        foreach ($excelRows as $row) {
            $key = $this->groupKey((float) $row->amount, $row->expense_date);
            $pos = $cursor[$key] ?? 0;
            $cursor[$key] = $pos + 1;

            $pdfMatch = $pdfGroups[$key][$pos] ?? null;

            if ($pdfMatch && isset($operativeMap[(int) $pdfMatch->branch_id])) {
                if (!$dryRun) {
                    DB::table('fact_expenses')->where('id', $row->id)->update([
                        'branch_id'   => $pdfMatch->branch_id,
                        'employee_id' => $pdfMatch->employee_id,
                        'updated_at'  => now(),
                    ]);
                }
                $results[] = [
                    'fact_expense_id'  => $row->id,
                    'category'         => $row->category,
                    'concept'          => $row->concept,
                    'amount'           => (float) $row->amount,
                    'expense_date'     => $row->expense_date,
                    'period_id'        => $row->period_id,
                    'report_upload_id' => $row->report_upload_id,
                    'branch_id'        => (int) $pdfMatch->branch_id,
                    'sucursal'         => $operativeMap[(int) $pdfMatch->branch_id],
                    'employee_id'      => $pdfMatch->employee_id,
                    'metodo'           => 'pdf_amount_date_match',
                    'estado'           => 'resuelto',
                ];
            } elseif ($pdfMatch) {
                // Matched a PDF row, but that PDF row's own branch is non-operative
                // (Corporativo/Tulancingo/etc.) — real exclusion, not a resolution failure.
                $results[] = [
                    'fact_expense_id'  => $row->id,
                    'category'         => $row->category,
                    'concept'          => $row->concept,
                    'amount'           => (float) $row->amount,
                    'expense_date'     => $row->expense_date,
                    'period_id'        => $row->period_id,
                    'report_upload_id' => $row->report_upload_id,
                    'branch_id'        => null,
                    'sucursal'         => null,
                    'employee_id'      => null,
                    'metodo'           => 'pdf_amount_date_match_non_operative',
                    'estado'           => 'excluido_no_operativo',
                ];
            } else {
                $results[] = [
                    'fact_expense_id'  => $row->id,
                    'category'         => $row->category,
                    'concept'          => $row->concept,
                    'amount'           => (float) $row->amount,
                    'expense_date'     => $row->expense_date,
                    'period_id'        => $row->period_id,
                    'report_upload_id' => $row->report_upload_id,
                    'branch_id'        => null,
                    'sucursal'         => null,
                    'employee_id'      => null,
                    'metodo'           => 'sin_par_pdf',
                    'estado'           => 'sin_resolver',
                ];
            }
        }

        return $results;
    }

    /**
     * Same as resolveForPeriod() but throws with an actionable message if any row remains
     * genuinely unresolved (no PDF counterpart found by amount+date) — used by the generation
     * pipeline to stop before building the report instead of silently losing the amount.
     */
    public function resolveForPeriodOrFail(Period $period, array $dataIds): array
    {
        $results    = $this->resolveForPeriod($period, $dataIds);
        $unresolved = array_filter($results, fn (array $r) => $r['estado'] === 'sin_resolver');

        if (!empty($unresolved)) {
            $detail = collect($unresolved)->map(fn (array $r) => sprintf(
                '  - fact_expenses.id=%d | %s / %s | $%s | %s | report_upload_id=%d',
                $r['fact_expense_id'], $r['category'], $r['concept'], number_format($r['amount'], 2),
                $r['expense_date'] ?? 'sin fecha', $r['report_upload_id']
            ))->implode("\n");

            throw new \RuntimeException(
                "No se pudo emparejar contra el PDF de Lendus " . count($unresolved) .
                " registro(s) del Excel de gastos del periodo {$period->label} (sin fila de monto+fecha " .
                "equivalente en el PDF) — generación detenida:\n" . $detail
            );
        }

        return $results;
    }

    private function groupKey(float $amount, ?string $expenseDate): string
    {
        return number_format($amount, 4, '.', '') . '|' . ($expenseDate ?? '');
    }
}
