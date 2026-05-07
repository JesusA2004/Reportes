<?php

namespace App\Services;

use App\Enums\DataSourceCode;
use App\Enums\ReportUploadStatus;
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
        $uploads = $this->importSourcesForFinalRadiography($period);

        return DB::transaction(function () use ($period, $userId, $uploads) {
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
                'global_metrics' => $this->globalMetrics($period),
                'warnings' => [],
                'version' => (int) ($summary->version ?? 0) + 1,
            ]);

            PeriodBranchSummary::query()
                ->where('period_summary_id', $summary->id)
                ->delete();

            foreach ($this->branchMetrics($period) as $row) {
                PeriodBranchSummary::query()->create([
                    'period_summary_id' => $summary->id,
                    'branch_id' => $row['branch_id'],
                    'metrics' => $row,
                ]);
            }

            PeriodCorporateSummary::query()->updateOrCreate(
                ['period_summary_id' => $summary->id],
                ['metrics' => $this->corporateMetrics($period)],
            );

            PeriodIncident::query()
                ->where('period_summary_id', $summary->id)
                ->delete();

            foreach ($this->incidents($period) as $incident) {
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

    private function importSourcesForFinalRadiography(Period $period): Collection
    {
        $requiredSources = $this->requiredSourceCodes();

        $uploads = $this->uploadsForPeriod($period)
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

    private function uploadsForPeriod(Period $period): Collection
    {
        return ReportUpload::query()
            ->with('dataSource')
            ->get()
            ->filter(function (ReportUpload $upload) use ($period) {
                $coveredIds = collect($upload->covered_period_ids ?? [])
                    ->map(fn ($id) => (int) $id);

                return (int) $upload->period_id === (int) $period->id
                    || $coveredIds->contains((int) $period->id);
            })
            ->values();
    }

    private function requiredSourceCodes(): array
    {
        return [
            DataSourceCode::NoiNomina->value,
            DataSourceCode::LendusIngresosCobranza->value,
            DataSourceCode::Gastos->value,
            DataSourceCode::LendusMinistraciones->value,
            DataSourceCode::LendusSaldosCliente->value,
        ];
    }

    private function globalMetrics(Period $period): array
    {
        $valorCartera = (float) Portfolio::query()
            ->where('period_id', $period->id)
            ->sum('balance');

        $carteraVencida = (float) Portfolio::query()
            ->where('period_id', $period->id)
            ->sum('past_due_balance');

        // Fallback: if past_due_balance is all zero but days_past_due > 0 exists, use balance of overdue records
        if ($carteraVencida === 0.0 && $valorCartera > 0) {
            $hasOverdueDays = Portfolio::query()->where('period_id', $period->id)->where('days_past_due', '>', 0)->exists();
            if ($hasOverdueDays) {
                $carteraVencida = (float) Portfolio::query()->where('period_id', $period->id)->where('days_past_due', '>', 0)->sum('balance');
            }
        }

        // ── Payroll aggregates (for preview cards) ──
        $payroll = $this->payrollMetrics($period);

        return [
            'gasto_total'          => (float) Expense::query()->where('period_id', $period->id)->sum('amount'),
            'recuperacion_total'   => (float) Recovery::query()->where('period_id', $period->id)->sum('total_amount'),
            'colocacion_total'     => (float) Placement::query()->where('period_id', $period->id)->sum('amount'),
            'valor_cartera_total'  => $valorCartera,
            'cartera_vencida_total' => $carteraVencida,
            'mora_porcentaje'      => $valorCartera > 0
                ? round(($carteraVencida / $valorCartera) * 100, 2)
                : 0,
            // Payroll (used by web preview cards)
            'total_empleados'      => $payroll['total_empleados'],
            'pagos_total'          => $payroll['pagos'],
            'bonos_total'          => $payroll['bonos'],
            'descuentos_total'     => $payroll['descuentos'],
            'gastos_nomina'        => $payroll['gastos'],
            'neto_total'           => $payroll['neto'],
        ];
    }

    private function payrollMetrics(Period $period): array
    {
        // Prefer consolidated summaries if they exist and have data
        $mes = DB::table('fact_monthly_employee_summary')
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as cnt, SUM(total_payments) as pagos, SUM(total_bonuses) as bonos, SUM(total_discounts) as descuentos, SUM(total_expenses) as gastos, SUM(net_amount) as neto')
            ->first();

        if ((int)($mes?->cnt ?? 0) > 0 && ((float)($mes?->pagos ?? 0) + (float)($mes?->bonos ?? 0)) > 0) {
            return [
                'total_empleados' => (int)$mes->cnt,
                'pagos'           => round((float)$mes->pagos, 2),
                'bonos'           => round((float)$mes->bonos, 2),
                'descuentos'      => round((float)$mes->descuentos, 2),
                'gastos'          => round((float)$mes->gastos, 2),
                'neto'            => round((float)$mes->neto, 2),
            ];
        }

        // Fallback: aggregate from fact_noi_movements
        $empCount = (int) DB::table('fact_noi_movements')
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->distinct('employee_id')
            ->count('employee_id');

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

        $gastos = (float) DB::table('fact_expenses')
            ->where('period_id', $period->id)
            ->whereNotNull('employee_id')
            ->sum('amount');

        // Last resort: if concept_type not recognized, use raw total
        if ($pagos === 0.0 && $bonos === 0.0 && $descuentos === 0.0) {
            $pagos = (float) DB::table('fact_noi_movements')
                ->where('period_id', $period->id)
                ->whereNotNull('employee_id')
                ->sum('amount');
        }

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
            'neto'            => round($pagos + $bonos - $descuentos, 2),
        ];
    }

    private function corporateMetrics(Period $period): array
    {
        return $this->globalMetrics($period);
    }

    private function branchMetrics(Period $period): array
    {
        $rows = [];

        $branchIds = collect()
            ->merge(Expense::query()->where('period_id', $period->id)->pluck('branch_id'))
            ->merge(Recovery::query()->where('period_id', $period->id)->pluck('branch_id'))
            ->merge(Placement::query()->where('period_id', $period->id)->pluck('branch_id'))
            ->merge(Portfolio::query()->where('period_id', $period->id)->pluck('branch_id'))
            ->filter()
            ->unique()
            ->values();

        foreach ($branchIds as $branchId) {
            $valor = (float) Portfolio::query()
                ->where('period_id', $period->id)
                ->where('branch_id', $branchId)
                ->sum('balance');

            $vencida = (float) Portfolio::query()
                ->where('period_id', $period->id)
                ->where('branch_id', $branchId)
                ->sum('past_due_balance');

            $rows[] = [
                'branch_id' => $branchId,
                'gasto_total' => (float) Expense::query()
                    ->where('period_id', $period->id)
                    ->where('branch_id', $branchId)
                    ->sum('amount'),
                'recuperacion_total' => (float) Recovery::query()
                    ->where('period_id', $period->id)
                    ->where('branch_id', $branchId)
                    ->sum('total_amount'),
                'colocacion_total' => (float) Placement::query()
                    ->where('period_id', $period->id)
                    ->where('branch_id', $branchId)
                    ->sum('amount'),
                'valor_cartera' => $valor,
                'cartera_vencida' => $vencida,
                'mora_porcentaje' => $valor > 0 ? round(($vencida / $valor) * 100, 2) : 0,
            ];
        }

        return $rows;
    }

    private function incidents(Period $period): array
    {
        $items = [];

        if ($this->uploadsForPeriod($period)->isEmpty()) {
            $items[] = [
                'type' => 'fuentes_faltantes',
                'severity' => 'high',
                'message' => 'No hay fuentes cargadas para el periodo.',
                'context' => null,
            ];
        }

        $valorCartera = (float) Portfolio::query()
            ->where('period_id', $period->id)
            ->sum('balance');

        $carteraVencida = (float) Portfolio::query()
            ->where('period_id', $period->id)
            ->sum('past_due_balance');

        if ($valorCartera > 0 && $carteraVencida > ($valorCartera * 0.25)) {
            $items[] = [
                'type' => 'mora_alta',
                'severity' => 'warning',
                'message' => 'La mora del periodo supera el 25%.',
                'context' => [
                    'valor_cartera' => $valorCartera,
                    'cartera_vencida' => $carteraVencida,
                ],
            ];
        }

        return $items;
    }
}
