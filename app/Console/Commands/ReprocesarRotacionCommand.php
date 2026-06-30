<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\Imports\RotacionExcelImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReprocesarRotacionCommand extends Command
{
    protected $signature = 'reportes:reprocesar-rotacion
                                {period_id : ID del periodo mensual}';

    protected $description = 'Limpia fact_rotacion del periodo y re-importa desde el archivo ya cargado. Valida que no existan etiquetas de métrica como sucursal.';

    public function handle(BranchResolverService $branchResolver): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info("══════════════════════════════════════════════════");
        $this->info("  REPROCESAR ROTACIÓN — {$period->name} (ID {$periodId})");
        $this->info("══════════════════════════════════════════════════");

        // Find the rotación upload for this period
        $upload = ReportUpload::query()
            ->whereHas('dataSource', fn($q) => $q->where('code', 'rotacion'))
            ->where('period_id', $periodId)
            ->latest()
            ->first();

        if (!$upload) {
            $this->error("No existe un upload de rotación para el periodo #{$periodId}.");
            $this->line("Sube primero el archivo desde Carga de archivos.");
            return self::FAILURE;
        }

        $this->line("  Archivo : {$upload->original_name}");
        $this->line("  Upload  : #{$upload->id}");
        $this->line('');

        // Step 1: Delete all rotación for this period
        $deleted = DB::table('fact_rotacion')->where('period_id', $periodId)->delete();
        $this->warn("  → fact_rotacion eliminados para periodo {$periodId}: {$deleted} registros");

        // Step 2: Re-import
        $this->line("  → Iniciando importación…");
        $service = new RotacionExcelImportService($branchResolver);

        try {
            $result = $service->handle($upload);
        } catch (\Throwable $e) {
            $this->error("Error al importar: " . $e->getMessage());
            return self::FAILURE;
        }

        $this->info("  → Importación completada:");
        $this->line("      Insertadas : {$result['rows_inserted']}");
        $this->line("      Omitidas   : {$result['rows_skipped']}");
        $this->line("      Errores    : {$result['rows_with_errors']}");
        $this->line("      Log        : {$result['log']}");
        $this->line('');

        // Step 3: Validate — no metric labels as sucursal
        $this->line("  → Validando registros insertados…");
        $bad = DB::table('fact_rotacion')
            ->where('period_id', $periodId)
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(sucursal_nombre,'Á','A'),'É','E'),'Í','I'),'Ó','O'),'Ú','U')) REGEXP '^(ALTAS?|BAJAS?|PLANTILLA|ROTACI|PROMEDIO|INDICE)'")
            ->count();

        if ($bad > 0) {
            $this->error("  ✗ ATENCIÓN: existen {$bad} registros con etiquetas de métrica como sucursal_nombre.");
            $this->line("    Ejecuta: php artisan reportes:audit-rotacion {$periodId} --detail");
            return self::FAILURE;
        }

        // Step 4: Show what was inserted
        $rows = DB::table('fact_rotacion as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->where('fr.period_id', $periodId)
            ->select('fr.sucursal_nombre', 'b.name as branch', 'fr.mes', 'fr.bajas', 'fr.promedio_personal', 'fr.indice_rotacion')
            ->orderBy('fr.sucursal_nombre')
            ->get();

        $this->info("  ✓ Registros válidos en BD ({$rows->count()}):");
        $this->line(sprintf("  %-25s %-8s %5s %5s %8s", 'SUCURSAL', 'MES', 'BAJAS', 'PLANT', 'ÍNDICE%'));
        $this->line("  " . str_repeat('─', 58));
        foreach ($rows as $r) {
            $this->line(sprintf(
                "  %-25s %-8s %5s %5s %8s%%",
                mb_substr($r->sucursal_nombre, 0, 25),
                $r->mes,
                $r->bajas,
                number_format($r->promedio_personal, 0),
                number_format($r->indice_rotacion, 2)
            ));
        }

        $this->line('');
        $this->info("  ✓ Rotación reprocesada correctamente. No existen etiquetas de métrica como sucursal.");

        return self::SUCCESS;
    }
}
