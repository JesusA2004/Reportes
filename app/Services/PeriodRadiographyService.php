<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Period;
use App\Models\PeriodBranchSummary;
use App\Models\PeriodCorporateSummary;
use App\Models\PeriodIncident;
use App\Models\PeriodRadiographyRun;
use App\Models\PeriodSummary;
use App\Models\Placement;
use App\Models\Portfolio;
use App\Models\Recovery;
use Illuminate\Support\Facades\DB;

class PeriodRadiographyService
{
    public function generate(Period $period, ?int $userId = null): PeriodSummary
    {
        return DB::transaction(function () use ($period, $userId) {
            $run = PeriodRadiographyRun::query()->create([
                'period_id' => $period->id, 'status' => 'running', 'started_at' => now(), 'created_by' => $userId,
            ]);

            $summary = PeriodSummary::query()->updateOrCreate(
                ['period_id' => $period->id],
                ['status' => 'generated', 'generated_at' => now(), 'generated_by' => $userId, 'invalidated_at' => null, 'invalidated_by' => null, 'invalidated_reason' => null]
            );

            $uploadIds = $period->reportUploads()->pluck('id')->values()->all();
            $summary->update([
                'source_upload_ids' => $uploadIds,
                'global_metrics' => $this->globalMetrics($period),
                'warnings' => [],
                'version' => (int) ($summary->version ?? 0) + 1,
            ]);

            PeriodBranchSummary::query()->where('period_summary_id', $summary->id)->delete();
            foreach ($this->branchMetrics($period) as $row) {
                PeriodBranchSummary::query()->create(['period_summary_id' => $summary->id, 'branch_id' => $row['branch_id'], 'metrics' => $row]);
            }

            PeriodCorporateSummary::query()->updateOrCreate(['period_summary_id' => $summary->id], ['metrics' => $this->corporateMetrics($period)]);

            PeriodIncident::query()->where('period_summary_id', $summary->id)->delete();
            foreach ($this->incidents($period) as $incident) {
                PeriodIncident::query()->create(['period_summary_id' => $summary->id] + $incident);
            }

            $run->update(['status' => 'success', 'period_summary_id' => $summary->id, 'finished_at' => now(), 'log' => 'Consolidado generado correctamente.']);
            return $summary->fresh(['branchSummaries', 'corporateSummary', 'incidents']);
        });
    }

    public function invalidateForPeriod(Period $period, ?int $userId, string $reason): void
    {
        PeriodSummary::query()->where('period_id', $period->id)->whereNull('invalidated_at')->update([
            'status' => 'invalidated', 'invalidated_at' => now(), 'invalidated_by' => $userId, 'invalidated_reason' => $reason,
        ]);
    }

    private function globalMetrics(Period $period): array {
        return [
            'gasto_total' => (float) Expense::query()->where('period_id', $period->id)->sum('amount'),
            'recuperacion_total' => (float) Recovery::query()->where('period_id', $period->id)->sum('total_amount'),
            'colocacion_total' => (float) Placement::query()->where('period_id', $period->id)->sum('amount'),
            'valor_cartera_total' => (float) Portfolio::query()->where('period_id', $period->id)->sum('balance'),
            'cartera_vencida_total' => (float) Portfolio::query()->where('period_id', $period->id)->sum('past_due_balance'),
        ];
    }
    private function corporateMetrics(Period $period): array { $g=$this->globalMetrics($period); $g['mora_porcentaje']=$g['valor_cartera_total']>0?round(($g['cartera_vencida_total']/$g['valor_cartera_total'])*100,2):0; return $g; }
    private function branchMetrics(Period $period): array {
        $rows = [];
        $branchIds = collect()->merge(Expense::where('period_id',$period->id)->pluck('branch_id'))->merge(Recovery::where('period_id',$period->id)->pluck('branch_id'))->merge(Placement::where('period_id',$period->id)->pluck('branch_id'))->merge(Portfolio::where('period_id',$period->id)->pluck('branch_id'))->filter()->unique();
        foreach ($branchIds as $branchId) {
            $valor = (float) Portfolio::where('period_id',$period->id)->where('branch_id',$branchId)->sum('balance');
            $vencida = (float) Portfolio::where('period_id',$period->id)->where('branch_id',$branchId)->sum('past_due_balance');
            $rows[] = ['branch_id'=>$branchId,'gasto_total'=>(float)Expense::where('period_id',$period->id)->where('branch_id',$branchId)->sum('amount'),'recuperacion_total'=>(float)Recovery::where('period_id',$period->id)->where('branch_id',$branchId)->sum('total_amount'),'colocacion_total'=>(float)Placement::where('period_id',$period->id)->where('branch_id',$branchId)->sum('amount'),'valor_cartera'=>$valor,'cartera_vencida'=>$vencida,'mora_porcentaje'=>$valor>0?round($vencida/$valor*100,2):0];
        }
        return $rows;
    }
    private function incidents(Period $period): array {
        $items=[];
        if (!$period->reportUploads()->exists()) $items[]=['type'=>'fuentes_faltantes','severity'=>'high','message'=>'No hay fuentes cargadas para el periodo.','context'=>null];
        if (Portfolio::where('period_id',$period->id)->sum('past_due_balance')>Portfolio::where('period_id',$period->id)->sum('balance')*0.25) $items[]=['type'=>'mora_alta','severity'=>'warning','message'=>'La mora del periodo supera el 25%.','context'=>null];
        return $items;
    }
}
