<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\ReportAnalysisService;
use Illuminate\Console\Command;

class ReprocessFailedSourcesCommand extends Command
{
    protected $signature = 'reportes:reprocess-failed-sources
                                {period_id : ID del periodo mensual}';

    protected $description = 'Reprocesa únicamente NOI Nómina, NOI Fiscal y Rotación vigentes que estén en error, e IMSS solo si su auditoría resulta inconsistente. No toca Cobranza, Cartera, Colocación ni Gastos, ni reinicia la base de datos.';

    private const TARGET_CODES = ['noi_nomina', 'noi_nomina_fiscal', 'rotacion'];

    public function handle(ReportAnalysisService $analysisService): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info('══════════════════════════════════════════════════');
        $this->info("  REPROCESAR FUENTES CON ERROR — {$period->label} (ID {$periodId})");
        $this->info('══════════════════════════════════════════════════');

        $anyReprocessed = false;

        foreach (self::TARGET_CODES as $code) {
            $upload = $this->latestUpload($periodId, $code);

            if (!$upload) {
                $this->line("  [{$code}] sin archivo cargado — omitido.");
                continue;
            }

            $status = (string) ($upload->status?->value ?? $upload->status);
            if ($status !== 'failed') {
                $this->line("  [{$code}] status={$status} — no requiere reproceso.");
                continue;
            }

            $anyReprocessed = true;
            $this->warn("  [{$code}] status=failed — reprocesando upload #{$upload->id} ({$upload->original_name})…");

            try {
                $run = $analysisService->analyze($upload);
                $this->info("    → OK. Insertadas: {$run->rows_inserted}, omitidas: {$run->rows_skipped}, errores: {$run->rows_with_errors}.");
            } catch (\Throwable $e) {
                $this->error('    → Error: ' . mb_strimwidth($e->getMessage(), 0, 300));
            }
        }

        // IMSS: solo se reprocesa si está en error, o si figura "processed" pero su
        // auditoría resulta inconsistente (sin facts o suma en cero).
        $imssUpload = $this->latestUpload($periodId, 'imss');

        if (!$imssUpload) {
            $this->line('  [imss] sin archivo cargado — omitido.');
        } else {
            $imssStatus     = (string) ($imssUpload->status?->value ?? $imssUpload->status);
            $imssQuery      = Expense::query()->where('period_id', $periodId)->where('category', 'IMSS');
            $imssSum        = (float) (clone $imssQuery)->sum('amount');
            $hasUnassigned  = (clone $imssQuery)->whereNull('branch_id')->exists();

            $inconsistent = $imssStatus === 'failed'
                || ($imssStatus === 'processed' && ($imssSum <= 0 || $hasUnassigned));

            if (!$inconsistent) {
                $this->line("  [imss] status={$imssStatus}, suma=\$" . number_format($imssSum, 2) . ' — consistente, no requiere reproceso.');
            } else {
                $anyReprocessed = true;
                $this->warn("  [imss] status={$imssStatus}, suma=\$" . number_format($imssSum, 2) . " — inconsistente, reprocesando upload #{$imssUpload->id}…");

                Expense::query()->where('period_id', $periodId)->where('category', 'IMSS')->delete();

                try {
                    $run = $analysisService->analyze($imssUpload);
                    $this->info("    → OK. Insertadas: {$run->rows_inserted}, omitidas: {$run->rows_skipped}, errores: {$run->rows_with_errors}.");
                } catch (\Throwable $e) {
                    $this->error('    → Error: ' . mb_strimwidth($e->getMessage(), 0, 300));
                }
            }
        }

        $this->line('');
        if (!$anyReprocessed) {
            $this->info('  No había fuentes en error. Nada que reprocesar.');
        } else {
            $this->info('  Reproceso finalizado.');
        }

        return self::SUCCESS;
    }

    private function latestUpload(int $periodId, string $code): ?ReportUpload
    {
        return ReportUpload::query()
            ->whereHas('dataSource', fn ($q) => $q->where('code', $code))
            ->where('period_id', $periodId)
            ->latest('id')
            ->first();
    }
}
