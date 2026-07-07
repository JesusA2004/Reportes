<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\ReportAnalysisService;
use Illuminate\Console\Command;

class ReprocesarImssCommand extends Command
{
    protected $signature = 'reportes:reprocesar-imss
                                {period_id : ID del periodo mensual}';

    protected $description = 'Elimina los gastos IMSS del periodo y re-importa desde el archivo ya cargado (PDF, XLS, XLSX o XLSM), sin afectar las demás fuentes ni reiniciar la base de datos.';

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
        $this->info("  REPROCESAR IMSS — {$period->name} (ID {$periodId})");
        $this->info('══════════════════════════════════════════════════');

        $upload = ReportUpload::query()
            ->whereHas('dataSource', fn ($q) => $q->where('code', 'imss'))
            ->where('period_id', $periodId)
            ->latest()
            ->first();

        if (!$upload) {
            $this->error("No existe un upload de IMSS para el periodo #{$periodId}.");
            $this->line('Sube primero el archivo desde Carga de archivos.');
            return self::FAILURE;
        }

        $this->line("  Archivo : {$upload->original_name}");
        $this->line("  Upload  : #{$upload->id}");
        $this->line('');

        $deleted = Expense::query()
            ->where('period_id', $periodId)
            ->where('category', 'IMSS')
            ->delete();
        $this->warn("  → Gastos IMSS eliminados para periodo {$periodId}: {$deleted} registros");

        $this->line('  → Iniciando importación…');

        try {
            $run = $analysisService->analyze($upload);
        } catch (\Throwable $e) {
            $this->error('Error al importar: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('  → Importación completada:');
        $this->line("      Insertadas : {$run->rows_inserted}");
        $this->line("      Omitidas   : {$run->rows_skipped}");
        $this->line("      Errores    : {$run->rows_with_errors}");
        $this->line("      Log        : {$run->log}");
        $this->line('');

        $rows = Expense::query()
            ->with('branch')
            ->where('period_id', $periodId)
            ->where('category', 'IMSS')
            ->orderByDesc('amount')
            ->get();

        $total = 0.0;
        $this->info("  ✓ Registros IMSS en BD ({$rows->count()}):");
        $this->line(sprintf('  %-25s %12s', 'SUCURSAL', 'CUOTA MENSUAL'));
        $this->line('  ' . str_repeat('─', 40));
        foreach ($rows as $r) {
            $suc = $r->branch?->name ?? ($r->observations ?: 'Sin sucursal');
            $this->line(sprintf('  %-25s %12s', mb_substr($suc, 0, 25), '$' . number_format((float) $r->amount, 2)));
            $total += (float) $r->amount;
        }
        $this->line('  ' . str_repeat('─', 40));
        $this->info(sprintf('  %-25s %12s', 'TOTAL', '$' . number_format($total, 2)));

        $sinSucursal = $rows->whereNull('branch_id')->count();
        if ($sinSucursal > 0) {
            $this->warn("  ✗ ATENCIÓN: {$sinSucursal} registros sin sucursal reconocida.");
        }

        $this->line('');
        $this->info('  ✓ IMSS reprocesado correctamente.');

        return self::SUCCESS;
    }
}
