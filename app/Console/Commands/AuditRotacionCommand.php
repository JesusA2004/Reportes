<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditRotacionCommand extends Command
{
    protected $signature = 'reportes:audit-rotacion
                                {period_id}
                                {--detail : mostrar fila a fila por sucursal}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Índice de Rotación de Personal: mes del periodo, sucursales, bajas, promedio, índice';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds, [$period->id]
        )));

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA ROTACIÓN DE PERSONAL — {$period->label} (ID {$period->id})");
        $this->info('  Fuente: fact_rotacion');
        $this->info('  Solo se usa el mes del periodo — no acumulado anual');
        $this->info('════════════════════════════════════════════════════════════');

        $rows = DB::table('fact_rotacion as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->leftJoin('report_uploads as ru', 'fr.report_upload_id', '=', 'ru.id')
            ->whereIn('fr.period_id', $dataIds)
            ->select(
                'fr.id',
                'fr.sucursal_nombre',
                'fr.branch_id',
                'fr.mes',
                'fr.bajas',
                'fr.promedio_personal',
                'fr.indice_rotacion',
                'fr.hoja_fuente',
                'b.name as branch_name',
                'ru.original_name as archivo',
            )
            ->orderBy('fr.mes')
            ->orderBy('fr.sucursal_nombre')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('  No se encontraron datos de rotación en el período.');
            $this->line('  Sube el archivo de Índice de Rotación en la gestión de archivos del período.');
            return 0;
        }

        // Group by mes (there should only be one)
        $meses   = $rows->pluck('mes')->unique()->values()->all();
        $detRows = [];

        $totalBajas    = 0;
        $totalPromedio = 0.0;
        $indiceGlobal  = 0.0;
        $countSucs     = 0;
        $sinSucursal   = [];

        foreach ($rows as $row) {
            $incluido = !empty($row->branch_id);
            $motivo   = $incluido
                ? 'Sucursal reconocida'
                : 'Sucursal no mapeada a branch_id — revisar normalizador';

            $totalBajas    += (int) $row->bajas;
            $totalPromedio += (float) $row->promedio_personal;
            if ($incluido) $countSucs++;
            if (!$incluido) $sinSucursal[] = $row->sucursal_nombre;

            $detRows[] = [
                'id'             => $row->id,
                'periodo'        => $period->label,
                'mes'            => $row->mes,
                'sucursal'       => $row->sucursal_nombre,
                'bajas'          => (int) $row->bajas,
                'promedio'       => (float) $row->promedio_personal,
                'indice'         => (float) $row->indice_rotacion,
                'hoja'           => $row->hoja_fuente ?? '',
                'archivo'        => $row->archivo ?? '',
                'incluido'       => $incluido ? 'Sí' : 'No',
                'motivo'         => $motivo,
            ];
        }

        // Global index
        if ($totalPromedio > 0 && $totalBajas > 0) {
            $indiceGlobal = round(($totalBajas / $totalPromedio) * 100, 2);
        } else {
            $indiceGlobal = round(collect($rows)->avg('indice_rotacion') ?? 0.0, 2);
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN ════');
        $this->line(str_pad('Periodo',          44) . $period->label);
        $this->line(str_pad('Mes(es) en datos', 44) . implode(', ', $meses));
        $this->line(str_pad('Sucursales con datos', 44) . count($rows));
        $this->line(str_pad('Total bajas',      44) . $totalBajas);
        $this->line(str_pad('Total promedio personal', 44) . number_format($totalPromedio, 2));
        $this->line(str_repeat('─', 60));
        $this->info(str_pad('Índice de rotación GLOBAL',44) . number_format($indiceGlobal, 2) . '%');

        if (!empty($sinSucursal)) {
            $this->line('');
            $this->warn('Sucursales no mapeadas: ' . implode(', ', array_unique($sinSucursal)));
        }

        // ── Por sucursal ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ ROTACIÓN POR SUCURSAL ════');
        $this->line(str_pad('Sucursal', 26) . str_pad('Bajas', 10) . str_pad('Promedio', 14) . str_pad('Índice %', 12) . 'Hoja');
        $this->line(str_repeat('─', 80));
        foreach ($detRows as $r) {
            $line = str_pad(mb_substr($r['sucursal'], 0, 24), 26) .
                    str_pad($r['bajas'], 10) .
                    str_pad(number_format($r['promedio'], 2), 14) .
                    str_pad(number_format($r['indice'], 2) . '%', 12) .
                    $r['hoja'];
            $r['incluido'] === 'Sí' ? $this->line($line) : $this->warn($line);
        }

        // ── Detalle extendido ────────────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE COMPLETO ════');
            $this->line(
                str_pad('ID', 8) .
                str_pad('Mes', 14) .
                str_pad('Sucursal', 26) .
                str_pad('Bajas', 8) .
                str_pad('Promedio', 12) .
                str_pad('Índice', 10) .
                str_pad('Incluido', 10) .
                'Motivo'
            );
            $this->line(str_repeat('─', 120));
            foreach ($detRows as $r) {
                $line = str_pad($r['id'], 8) .
                        str_pad($r['mes'], 14) .
                        str_pad(mb_substr($r['sucursal'], 0, 24), 26) .
                        str_pad($r['bajas'], 8) .
                        str_pad(number_format($r['promedio'], 2), 12) .
                        str_pad(number_format($r['indice'], 2) . '%', 10) .
                        str_pad($r['incluido'], 10) .
                        mb_substr($r['motivo'], 0, 50);
                $r['incluido'] === 'Sí' ? $this->line($line) : $this->warn($line);
            }
        }

        // ── Export ───────────────────────────────────────────────────────────
        if ($this->option('export')) {
            $this->exportCsv($period->id, $detRows, $meses, $totalBajas, $totalPromedio, $indiceGlobal);
        }

        return 0;
    }

    private function exportCsv(int $periodId, array $rows, array $meses, int $totalBajas, float $totalPromedio, float $indiceGlobal): void
    {
        $dir  = 'auditorias';
        $file = "rotacion_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $headers = ['ID', 'Periodo', 'Mes usado', 'Sucursal', 'Bajas', 'Promedio de personal', 'Índice de rotación %', 'Fuente / hoja', 'Incluido', 'Motivo'];
        $lines   = [implode(',', $headers)];
        $csv     = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $r['id'],
                $csv($r['periodo']),
                $csv($r['mes']),
                $csv($r['sucursal']),
                $r['bajas'],
                number_format($r['promedio'], 4, '.', ''),
                number_format($r['indice'], 4, '.', ''),
                $csv($r['hoja']),
                $csv($r['incluido']),
                $csv($r['motivo']),
            ]);
        }

        $lines[] = '';
        $lines[] = '"=== TOTALES ==="';
        $lines[] = '"Meses en datos",' . $csv(implode(', ', $meses));
        $lines[] = '"Total bajas",' . $totalBajas;
        $lines[] = '"Total promedio personal",' . number_format($totalPromedio, 2, '.', '');
        $lines[] = '"Índice GLOBAL",' . number_format($indiceGlobal, 2, '.', '') . '%';

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
