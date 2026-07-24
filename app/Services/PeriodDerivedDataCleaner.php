<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\MonthlyEmployeeSummary;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodCorporateSummary;
use App\Models\PeriodIncident;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodSummary;
use App\Models\LendusEmployeeDirectory;
use App\Models\Placement;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Models\ReportUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PeriodDerivedDataCleaner
{
    /**
     * Delete ALL derived data for a period so it can be reprocessed cleanly.
     * Does NOT touch: employees, branches, users, catalogs, audit runs, uploads.
     */
    public function clearForPeriod(Period $period): void
    {
        Log::info('PeriodDerivedDataCleaner: clearForPeriod start', ['period_id' => $period->id]);

        // ── 1. Fact tables (raw imported data) ──────────────────────────────
        NoiMovement::query()->where('period_id', $period->id)->delete();
        Placement::query()->where('period_id', $period->id)->delete();
        Recovery::query()->where('period_id', $period->id)->delete();
        Portfolio::query()->where('period_id', $period->id)->delete();
        Expense::query()->where('period_id', $period->id)->delete();
        DB::table('fact_rotacion')->where('period_id', $period->id)->delete();

        // ── 2. Consolidated employee summaries ──────────────────────────────
        MonthlyEmployeeSummary::query()->where('period_id', $period->id)->delete();

        // ── 3. Generated reports (files + records) ──────────────────────────
        $this->clearGeneratedReports($period);

        // ── 4. Period summaries and children ────────────────────────────────
        $summaryIds = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->pluck('id');

        if ($summaryIds->isNotEmpty()) {
            PeriodBranchSummary::query()->whereIn('period_summary_id', $summaryIds)->delete();
            PeriodCorporateSummary::query()->whereIn('period_summary_id', $summaryIds)->delete();
            PeriodIncident::query()->whereIn('period_summary_id', $summaryIds)->delete();
            PeriodSummary::query()->whereIn('id', $summaryIds)->delete();
        }

        // ── 5. Invalidate uploads so UI shows them as needing reprocess ─────
        \App\Models\ReportUpload::query()
            ->where('period_id', $period->id)
            ->update(['status' => 'pending']);

        Log::info('PeriodDerivedDataCleaner: clearForPeriod done', ['period_id' => $period->id]);
    }

    /**
     * Delete only the data rows imported by a single ReportUpload.
     * Leaves consolidated summaries untouched (they'll be stale but not duplicated).
     * Use before re-running a single upload.
     */
    public function clearForUpload(ReportUpload $upload): void
    {
        Log::info('PeriodDerivedDataCleaner: clearForUpload start', ['upload_id' => $upload->id]);

        NoiMovement::query()->where('report_upload_id', $upload->id)->delete();
        Placement::query()->where('report_upload_id', $upload->id)->delete();
        Recovery::query()->where('report_upload_id', $upload->id)->delete();
        Portfolio::query()->where('report_upload_id', $upload->id)->delete();
        Expense::query()->where('report_upload_id', $upload->id)->delete();
        LendusEmployeeDirectory::query()->where('report_upload_id', $upload->id)->delete();
        DB::table('fact_rotacion')->where('report_upload_id', $upload->id)->delete();

        Log::info('PeriodDerivedDataCleaner: clearForUpload done', ['upload_id' => $upload->id]);
    }

    /**
     * Delete only the generated report artifacts (Excel/PDF files + export records).
     * Leaves raw fact data and period summaries intact.
     * Use before regenerating a radiography without full reprocess.
     */
    public function clearGeneratedReports(Period $period): void
    {
        $summaryIds = PeriodSummary::query()
            ->where('period_id', $period->id)
            ->pluck('id');

        if ($summaryIds->isEmpty()) {
            return;
        }

        $exports = PeriodRadiographyExport::query()
            ->whereIn('period_summary_id', $summaryIds)
            ->get();

        foreach ($exports as $export) {
            if ($export->export_path && Storage::exists($export->export_path)) {
                Storage::delete($export->export_path);
            }
        }

        PeriodRadiographyExport::query()
            ->whereIn('period_summary_id', $summaryIds)
            ->delete();

        Log::info('PeriodDerivedDataCleaner: clearGeneratedReports done', [
            'period_id'     => $period->id,
            'deleted_files' => $exports->count(),
        ]);
    }

    /**
     * Delete only the prior run(s)/export(s)/file(s) that share the EXACT SAME
     * identity being (re)generated now (period_id + report_type + scope +
     * comparison_period_id + branch_id + employee_id). A simple report and a
     * comparativo mes vs mes for the same period must coexist — this replaces the
     * old clearGeneratedReports() call inside GenerateRadiographyJob, which wiped
     * every export for the period regardless of report kind.
     */
    public function clearGeneratedReportsForIdentity(Period $period, array $identity, ?int $excludeRunId = null): void
    {
        $query = \App\Models\PeriodRadiographyRun::query()
            ->where('period_id', $period->id)
            ->where('report_type', $identity['report_type'] ?? 'simple')
            ->where('scope', $identity['scope'] ?? 'general')
            ->where('comparison_period_id', $identity['comparison_period_id'] ?? null)
            ->where('branch_id', $identity['branch_id'] ?? null)
            ->where('employee_id', $identity['employee_id'] ?? null);

        if ($excludeRunId) {
            $query->where('id', '!=', $excludeRunId);
        }

        $priorRuns = $query->get();

        if ($priorRuns->isEmpty()) {
            return;
        }

        $exports = PeriodRadiographyExport::query()
            ->whereIn('run_id', $priorRuns->pluck('id'))
            ->get();

        foreach ($exports as $export) {
            if ($export->export_path && Storage::exists($export->export_path)) {
                Storage::delete($export->export_path);
            }
        }

        PeriodRadiographyExport::query()->whereIn('run_id', $priorRuns->pluck('id'))->delete();
        \App\Models\PeriodRadiographyRun::query()->whereIn('id', $priorRuns->pluck('id'))->delete();

        Log::info('PeriodDerivedDataCleaner: clearGeneratedReportsForIdentity done', [
            'period_id'     => $period->id,
            'identity'      => $identity,
            'deleted_runs'  => $priorRuns->count(),
            'deleted_files' => $exports->count(),
        ]);
    }
}
