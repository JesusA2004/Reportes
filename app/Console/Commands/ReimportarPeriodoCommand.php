<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\PeriodDerivedDataCleaner;
use App\Services\ReportAnalysisService;
use Illuminate\Console\Command;

class ReimportarPeriodoCommand extends Command
{
    protected $signature   = 'reportes:reimportar-periodo {period_id : ID del periodo mensual}';
    protected $description = 'Limpia y re-importa todas las fuentes de un periodo de forma sincrónica (sin queue).';

    public function __construct(
        private readonly PeriodDerivedDataCleaner $cleaner,
        private readonly ReportAnalysisService    $service,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $uploads = ReportUpload::query()
            ->with('dataSource')
            ->where('period_id', $periodId)
            ->get();

        if ($uploads->isEmpty()) {
            $this->warn("No hay uploads para periodo #{$periodId}.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info("══════ REIMPORT — {$period->label} (ID #{$periodId}) ══════");
        $this->line("  Uploads encontrados: {$uploads->count()}");
        $this->line('');

        $this->info("── Limpiando datos previos del periodo...");
        $this->cleaner->clearForPeriod($period);
        $this->line("  <fg=green>Datos anteriores eliminados.</>");
        $this->line('');

        $order = [
            'lendus_saldos_cliente',
            'lendus_ministraciones',
            'lendus_ingresos_cobranza',
            'noi_nomina',
            'noi_nomina_fiscal',
            'gastos_lendus',
            'gastos_lendus_excel',
            'gastos_erp',
            'gastos',
        ];
        $sorted = $uploads->sortBy(
            fn ($u) => ($pos = array_search($u->dataSource?->code ?? '', $order)) !== false ? $pos : 99
        )->values();

        $errores = [];

        foreach ($sorted as $upload) {
            $source = $upload->dataSource?->name ?? "Upload #{$upload->id}";
            $code   = $upload->dataSource?->code ?? '?';
            $this->line("  Procesando: <fg=cyan>{$source}</> ({$code})");

            try {
                $run = $this->service->analyze($upload);
                $this->line(sprintf(
                    "    <fg=green>✓</> leídas=%d  insertadas=%d  omitidas=%d  errores=%d",
                    $run->rows_read ?? 0,
                    $run->rows_inserted ?? 0,
                    $run->rows_skipped ?? 0,
                    $run->rows_with_errors ?? 0,
                ));
                if ($run->log) {
                    $this->line("    Log: " . mb_strimwidth($run->log, 0, 150, '…'));
                }
            } catch (\Throwable $e) {
                $msg = mb_strimwidth($e->getMessage(), 0, 200);
                $this->error("    ✗ ERROR en {$source}: {$msg}");
                $errores[] = "{$source}: {$msg}";
            }

            $this->line('');
        }

        if (!empty($errores)) {
            $this->warn("══════ COMPLETADO CON " . count($errores) . " ERROR(ES) ══════");
            foreach ($errores as $e) {
                $this->line("  • {$e}");
            }
            return self::FAILURE;
        }

        $this->info("══════ REIMPORT COMPLETO — {$period->label} ══════");
        $this->line('  Ahora regenera la radiografía desde la UI o con el job correspondiente.');
        $this->line('');
        return self::SUCCESS;
    }
}
