<?php

namespace App\Services;

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Expense;
use App\Models\Period;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodCorporateSummary;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use App\Models\Placement;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PeriodRadiographyService
{
    public function __construct(
        protected ReportAnalysisService $reportAnalysisService,
    ) {
    }

    public function generate(Period $period, ?int $userId = null): PeriodSummary
    {
        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '1800');
        @set_time_limit(1800);

        /*
         * IMPORTANTE:
         * Este servicio ya NO crea PeriodRadiographyRun.
         * El run lo controla GenerateRadiographyJob, para evitar estados falsos
         * como "success" cuando todavía hay fuentes pendientes.
         */

        // Para periodos compuestos (mensual, bimestral, etc.) los datos viven
        // en los periodos semanales base. Resolvemos esos IDs una sola vez.
        $allPeriods = Period::all();
        $dataIds = $period->resolveBaseWeeklyIds($allPeriods);
        if (empty($dataIds)) {
            $dataIds = [$period->id];
        }
        // Include the period itself so uploads stored directly on monthly periods are found
        $dataIds = array_values(array_unique(array_merge($dataIds, [$period->id])));

        $uploads = $this->importSourcesForFinalRadiography($period, $dataIds, $allPeriods);

        return DB::transaction(function () use ($period, $userId, $uploads, $dataIds) {
            $summary = PeriodSummary::query()->updateOrCreate(
                ['period_id' => $period->id],
                [
                    'status' => 'generated',
                    'generated_at' => now(),
                    'generated_by' => $userId,
                    'invalidated_at' => null,
                    'invalidated_by' => null,
                    'invalidated_reason' => null,
                ],
            );

            $summary->update([
                'source_upload_ids' => $uploads->pluck('id')->values()->all(),
                'global_metrics' => $this->globalMetrics($period, $dataIds),
                'warnings' => [],
                'version' => (int) ($summary->version ?? 0) + 1,
            ]);

            PeriodBranchSummary::query()
                ->where('period_summary_id', $summary->id)
                ->delete();

            foreach ($this->branchMetrics($period, $dataIds) as $row) {
                PeriodBranchSummary::query()->create([
                    'period_summary_id' => $summary->id,
                    'branch_id' => $row['branch_id'],
                    'metrics' => $row,
                ]);
            }

            PeriodCorporateSummary::query()->updateOrCreate(
                ['period_summary_id' => $summary->id],
                ['metrics' => $this->corporateMetrics($period, $dataIds)],
            );

            PeriodIncident::query()
                ->where('period_summary_id', $summary->id)
                ->delete();

            foreach ($this->incidents($period, $dataIds) as $incident) {
                PeriodIncident::query()->create([
                    'period_summary_id' => $summary->id,
                    ...$incident,
                ]);
            }

            return $summary->fresh(['branchSummaries', 'corporateSummary', 'incidents']);
        });
    }

    public function invalidateForPeriod(Period $period, ?int $userId, string $reason): void
    {
        PeriodSummary::query()
            ->where('period_id', $period->id)
            ->whereNull('invalidated_at')
            ->update([
                'status' => 'invalidated',
                'invalidated_at' => now(),
                'invalidated_by' => $userId,
                'invalidated_reason' => $reason,
            ]);
    }

    private function importSourcesForFinalRadiography(Period $period, array $dataIds, Collection $allPeriods): Collection
    {
        $requiredSources = $this->requiredSourceCodes();

        $uploads = $this->uploadsForPeriod($period, $dataIds, $allPeriods)
            ->filter(fn (ReportUpload $upload) => $upload->dataSource && in_array($upload->dataSource->code, $requiredSources, true))
            ->sortByDesc('id')
            ->unique(fn (ReportUpload $upload) => $upload->dataSource->code)
            ->values();

        $uploadedCodes = $uploads
            ->pluck('dataSource.code')
            ->filter()
            ->values()
            ->all();

        $missing = collect($requiredSources)
            ->filter(fn (string $code) => !in_array($code, $uploadedCodes, true))
            ->values()
            ->all();

        if (!empty($missing)) {
            throw new \RuntimeException(
                'No se puede generar la Radiografía. Faltan fuentes: ' . implode(', ', $missing) . '.'
            );
        }

        foreach ($uploads as $upload) {
            if (!$upload->stored_path) {
                throw new \RuntimeException("El archivo {$upload->original_name} no tiene ruta de almacenamiento.");
            }

            $status = (string) ($upload->status?->value ?? $upload->status);

            if ($status !== ReportUploadStatus::Processed->value) {
                $this->reportAnalysisService->analyze($upload);
            }

            $upload->refresh()->load('dataSource');

            $freshStatus = (string) ($upload->status?->value ?? $upload->status);

            if ($freshStatus !== ReportUploadStatus::Processed->value) {
                $lastRun = $upload->processRuns()
                    ->latest('id')
                    ->first();

                $reason = $lastRun?->log
                    ?: 'El importador terminó sin dejar el archivo como procesado.';

                throw new \RuntimeException(
                    "La fuente {$upload->dataSource?->code} no quedó procesada. Archivo: {$upload->original_name}. Detalle: {$reason}"
                );
            }
        }

        return $uploads;
    }

    private function uploadsForPeriod(Period $period, array $dataIds, Collection $allPeriods): Collection
    {
        // For compound periods we search uploads belonging to any covered base weekly period.
        $searchIds = array_values(array_unique(array_merge($dataIds, [$period->id])));

        return ReportUpload::query()
            ->with('dataSource')
            ->get()
            ->filter(function (ReportUpload $upload) use ($searchIds) {
                if (in_array((int) $upload->period_id, $searchIds, true)) {
                    return true;
                }
                $coveredIds = collect($upload->covered_period_ids ?? [])
                    ->map(fn ($id) => (int) $id);
                foreach ($searchIds as $sid) {
                    if ($coveredIds->contains($sid)) {
                        return true;
                    }
                }
                return false;
            })
            ->values();
    }

    // Returns active source codes required to produce the radiography report.
    // Driven by the is_required_for_report flag in data_sources table.
    private function requiredSourceCodes(): array
    {
        return DataSource::query()
            ->where('is_active', true)
            ->where('is_required_for_report', true)
            ->pluck('code')
            ->all();
    }

    private function globalMetrics(Period $period, array $dataIds): array
    {
        $valorCartera = (float) Portfolio::query()
            ->whereIn('period_id', $dataIds)
            ->sum('balance');

        $carteraVencida = (float) Portfolio::query()
            ->whereIn('period_id', $dataIds)
            ->sum('past_due_balance');

        // Fallback: if past_due_balance is all zero but days_past_due > 0 exists, use balance of overdue records
        if ($carteraVencida === 0.0 && $valorCartera > 0) {
            $hasOverdueDays = Portfolio::query()->whereIn('period_id', $dataIds)->where('days_past_due', '>', 0)->exists();
            if ($hasOverdueDays) {
                $carteraVencida = (float) Portfolio::query()->whereIn('period_id', $dataIds)->where('days_past_due', '>', 0)->sum('balance');
            }
        }

        // ── Payroll aggregates (for preview cards) ──
        $payroll = $this->payrollMetrics($period, $dataIds);

        return [
            'gasto_total'           => (float) Expense::query()->whereIn('period_id', $dataIds)->sum('amount'),
            'recuperacion_total'    => (float) Recovery::query()->whereIn('period_id', $dataIds)->sum('total_amount'),
            'colocacion_total'      => (float) Placement::query()->whereIn('period_id', $dataIds)->sum('amount'),
            'valor_cartera_total'   => $valorCartera,
            'cartera_vencida_total' => $carteraVencida,
            'mora_porcentaje'       => $valorCartera > 0
                ? round(($carteraVencida / $valorCartera) * 100, 2)
                : 0,
            // Payroll (used by web preview cards)
            'total_empleados'  => $payroll['total_empleados'],
            'pagos_total'      => $payroll['pagos'],
            'bonos_total'      => $payroll['bonos'],
            'descuentos_total' => $payroll['descuentos'],
            'gastos_nomina'    => $payroll['gastos'],
            'neto_total'       => $payroll['neto'],
        ];
    }

    private function payrollMetrics(Period $period, array $dataIds): array
    {
        // Prefer consolidated summaries written for this exact period (populated by PeriodConsolidationService)
        $mes = DB::table('fact_period_employee_summary')
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as cnt, SUM(total_payments) as pagos, SUM(total_bonuses) as bonos, SUM(total_discounts) as descuentos, SUM(total_expenses) as gastos, SUM(net_amount) as neto')
            ->first();

        if ((int) ($mes?->cnt ?? 0) > 0 && ((float) ($mes?->pagos ?? 0) + (float) ($mes?->bonos ?? 0)) > 0) {
            return [
                'total_empleados' => (int) $mes->cnt,
                'pagos'           => round((float) $mes->pagos, 2),
                'bonos'           => round((float) $mes->bonos, 2),
                'descuentos'      => round((float) $mes->descuentos, 2),
                'gastos'          => round((float) $mes->gastos, 2),
                'neto'            => round((float) $mes->neto, 2),
            ];
        }

        // Fallback: aggregate from raw fact tables across all base weekly period IDs
        $empCount = (int) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->distinct('employee_id')
            ->count('employee_id');

        $pagos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->whereRaw("LOWER(COALESCE(concept,'')) NOT LIKE '%bono%'")
            ->sum('amount');

        $bonos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->whereRaw("LOWER(COALESCE(concept,'')) LIKE '%bono%'")
            ->sum('amount');

        $descuentos = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) IN ('deduccion','descuento')")
            ->sum('amount');

        $gastos = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->sum('amount');

        // Last resort: if concept_type not recognized, use raw total
        if ($pagos === 0.0 && $bonos === 0.0 && $descuentos === 0.0) {
            $pagos = (float) DB::table('fact_noi_movements')
                ->whereIn('period_id', $dataIds)
                ->whereNotNull('employee_id')
                ->sum('amount');
        }

        if ($empCount === 0) {
            $empCount = (int) DB::table('employee_branch_assignments')
                ->whereIn('period_id', $dataIds)
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
            'neto'            => round($pagos + $bonos - $descuentos, 2),
        ];
    }

    private function corporateMetrics(Period $period, array $dataIds): array
    {
        return $this->globalMetrics($period, $dataIds);
    }

    private function branchMetrics(Period $period, array $dataIds): array
    {
        $rows = [];

        $branchIds = collect()
            ->merge(Expense::query()->whereIn('period_id', $dataIds)->pluck('branch_id'))
            ->merge(Recovery::query()->whereIn('period_id', $dataIds)->pluck('branch_id'))
            ->merge(Placement::query()->whereIn('period_id', $dataIds)->pluck('branch_id'))
            ->merge(Portfolio::query()->whereIn('period_id', $dataIds)->pluck('branch_id'))
            ->filter()
            ->unique()
            ->values();

        foreach ($branchIds as $branchId) {
            $valor = (float) Portfolio::query()
                ->whereIn('period_id', $dataIds)
                ->where('branch_id', $branchId)
                ->sum('balance');

            $vencida = (float) Portfolio::query()
                ->whereIn('period_id', $dataIds)
                ->where('branch_id', $branchId)
                ->sum('past_due_balance');

            $rows[] = [
                'branch_id'          => $branchId,
                'gasto_total'        => (float) Expense::query()
                    ->whereIn('period_id', $dataIds)
                    ->where('branch_id', $branchId)
                    ->sum('amount'),
                'recuperacion_total' => (float) Recovery::query()
                    ->whereIn('period_id', $dataIds)
                    ->where('branch_id', $branchId)
                    ->sum('total_amount'),
                'colocacion_total'   => (float) Placement::query()
                    ->whereIn('period_id', $dataIds)
                    ->where('branch_id', $branchId)
                    ->sum('amount'),
                'valor_cartera'      => $valor,
                'cartera_vencida'    => $vencida,
                'mora_porcentaje'    => $valor > 0 ? round(($vencida / $valor) * 100, 2) : 0,
            ];
        }

        return $rows;
    }

    private function incidents(Period $period, array $dataIds): array
    {
        $items = [];

        $allPeriods = Period::all();
        if ($this->uploadsForPeriod($period, $dataIds, $allPeriods)->isEmpty()) {
            $items[] = [
                'type'     => 'fuentes_faltantes',
                'severity' => 'high',
                'message'  => 'No hay fuentes cargadas para el periodo.',
                'context'  => null,
            ];
        }

        $valorCartera = (float) Portfolio::query()
            ->whereIn('period_id', $dataIds)
            ->sum('balance');

        $carteraVencida = (float) Portfolio::query()
            ->whereIn('period_id', $dataIds)
            ->sum('past_due_balance');

        // Fallback: use days_past_due when past_due_balance is all zero
        if ($carteraVencida === 0.0 && $valorCartera > 0) {
            $carteraVencida = (float) Portfolio::query()
                ->whereIn('period_id', $dataIds)
                ->where('days_past_due', '>', 0)
                ->sum('balance');
        }

        if ($valorCartera > 0 && $carteraVencida > ($valorCartera * 0.25)) {
            $items[] = [
                'type'     => 'mora_alta',
                'severity' => 'warning',
                'message'  => 'La mora del periodo supera el 25%.',
                'context'  => [
                    'valor_cartera'   => $valorCartera,
                    'cartera_vencida' => $carteraVencida,
                ],
            ];
        }

        // Employees in NOI without branch assignment
        $sinSucursal = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period, $dataIds) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                  ->whereIn('eba.period_id', array_merge([$period->id], $dataIds));
            })
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.branch_id')
            ->distinct('n.employee_id')
            ->count('n.employee_id');

        if ($sinSucursal > 0) {
            $items[] = [
                'type'     => 'empleados_sin_sucursal',
                'severity' => 'warning',
                'message'  => "{$sinSucursal} empleado(s) del NOI no tienen sucursal asignada. Sus datos no aparecen en reportes por sucursal.",
                'context'  => ['count' => $sinSucursal],
            ];
        }

        // Promoter names in placements with no matching employee in employees table
        $gestoresSinMatch = DB::table('fact_placements as p')
            ->whereIn('p.period_id', $dataIds)
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
                'message'  => "{$gestoresSinMatch} gestor(es) de ministraciones no coinciden con ningún empleado NOI. Revisa los nombres en ambos archivos.",
                'context'  => ['count' => $gestoresSinMatch],
            ];
        }

        return $items;
    }
}
