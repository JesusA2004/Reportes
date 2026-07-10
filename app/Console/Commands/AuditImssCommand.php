<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditImssCommand extends Command
{
    protected $signature = 'reportes:audit-imss
                                {period_id}
                                {--detail : mostrar cada fila con empleado, NSS y sucursal}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría IMSS por sucursal: cuota patronal mensual, total global, incluido/excluido';

    public function handle(BranchResolverService $resolver): int
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

        // Build operative map to identify included vs excluded branches
        $operativeMap = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = strtoupper(trim($real));
            }
        }

        $imssSourceId = DB::table('data_sources')->where('code', 'imss')->value('id');

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA IMSS — {$period->label} (ID {$period->id})");
        $this->info('  Fuente: fact_expenses con data_source = imss');
        $this->info('  Clasificación: Capital Humano / Nómina (costo patronal)');
        $this->info('════════════════════════════════════════════════════════════');

        if (!$imssSourceId) {
            $this->warn('  No se encontró data_source con código "imss".');
            $this->warn('  Ejecuta la migración: php artisan migrate');
            return 1;
        }

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $imssSourceId)
            ->select(
                'e.id',
                'b.name as sucursal_nombre',
                'e.branch_id',
                'e.category',
                'e.concept',
                'e.observations',
                'e.amount',
                'e.paid_amount',
                'e.raw_payload',
            )
            ->orderBy('b.name')
            ->orderByDesc('e.amount')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('  No se encontraron filas de IMSS en el período.');
            $this->line('  Sube el archivo IMSS en la gestión de archivos del período.');
            return 0;
        }

        $totalIncluido = 0.0;
        $totalExcluido = 0.0;
        $porSucursal   = [];
        $excluidos     = [];
        $sinSucursal   = 0.0;
        $detailRows    = [];

        foreach ($rows as $row) {
            $monto      = (float) ($row->paid_amount ?: $row->amount);
            $suc        = (string) ($row->sucursal_nombre ?? 'Sin sucursal');
            $branchId   = (int) ($row->branch_id ?? 0);
            $payload    = is_string($row->raw_payload) ? (json_decode($row->raw_payload, true) ?? []) : (array)($row->raw_payload ?? []);

            $isOperative = $branchId > 0 && isset($operativeMap[$branchId]);

            if ($isOperative) {
                $incluido = 'Sí';
                $motivo   = "Incluido — sucursal operativa ({$suc})";
                $totalIncluido += $monto;
                $porSucursal[$suc] = ($porSucursal[$suc] ?? 0.0) + $monto;
            } elseif ($branchId > 0) {
                // Has branch_id but NOT operative (CORPORATIVO, TULANCINGO, etc.)
                $incluido = 'No';
                $motivo   = "Excluido — sucursal no operativa en el reporte ({$suc})";
                $totalExcluido += $monto;
                $excluidos[$suc] = ($excluidos[$suc] ?? 0.0) + $monto;
            } else {
                $incluido = 'No';
                $motivo   = 'Sin branch_id — sucursal no reconocida en el archivo';
                $sinSucursal += $monto;
            }

            $detailRows[] = [
                'id'             => $row->id,
                'sucursal'       => $suc,
                'branch_id'      => $branchId ?: null,
                'nss'            => $payload['nss_ejemplo'] ?? '',
                'empleado'       => $payload['empleado_ejemplo'] ?? (string)($row->observations ?? ''),
                'cuota_mensual'  => $monto,
                'hoja'           => $payload['hoja'] ?? '',
                'incluido'       => $incluido,
                'motivo'         => $motivo,
                'suc_normalizada' => $suc,
            ];
        }

        $totalArchivo = $totalIncluido + $totalExcluido + $sinSucursal;

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN ════');
        $this->line(str_pad('Total filas IMSS',              44) . count($rows));
        $this->line(str_pad('Sucursales operativas',         44) . count($porSucursal));
        $this->line(str_repeat('─', 60));
        $this->line(str_pad('Total archivo IMSS',            44) . '$' . number_format($totalArchivo, 2));
        $this->info(str_pad('IMSS incluido en reporte',      44) . '$' . number_format($totalIncluido, 2));
        if ($totalExcluido > 0) {
            $this->warn(str_pad('IMSS excluido (no operativas)', 44) . '$' . number_format($totalExcluido, 2));
            foreach ($excluidos as $excSuc => $excAmt) {
                $this->warn('  → ' . str_pad($excSuc, 40) . '$' . number_format($excAmt, 2));
            }
        }
        if ($sinSucursal > 0) {
            $this->warn(str_pad('Sin sucursal reconocida',    44) . '$' . number_format($sinSucursal, 2));
        }

        // ── Por sucursal ─────────────────────────────────────────────────────
        if (!empty($porSucursal)) {
            $this->line('');
            $this->info('════ IMSS POR SUCURSAL OPERATIVA ════');
            $this->line(str_pad('Sucursal', 30) . 'Cuota mensual');
            $this->line(str_repeat('─', 50));
            arsort($porSucursal);
            foreach ($porSucursal as $suc => $amt) {
                $this->line(str_pad($suc, 30) . '$' . number_format($amt, 2));
            }
            $this->line(str_repeat('─', 50));
            $this->info(str_pad('TOTAL INCLUIDO', 30) . '$' . number_format($totalIncluido, 2));
        }

        // ── Detalle ──────────────────────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE FILA A FILA ════');
            $this->line(
                str_pad('ID', 8) .
                str_pad('Sucursal', 26) .
                str_pad('Empleado/NSS', 36) .
                str_pad('Cuota mensual', 18) .
                str_pad('Incluido', 10) .
                'Motivo'
            );
            $this->line(str_repeat('─', 140));

            foreach ($detailRows as $r) {
                $empStr = $r['empleado'] ?: ($r['nss'] ? "NSS {$r['nss']}" : '—');
                $line   = str_pad($r['id'], 8) .
                          str_pad(mb_substr($r['sucursal'], 0, 24), 26) .
                          str_pad(mb_substr($empStr, 0, 34), 36) .
                          str_pad('$' . number_format($r['cuota_mensual'], 2), 18) .
                          str_pad($r['incluido'], 10) .
                          mb_substr($r['motivo'], 0, 60);

                $r['incluido'] === 'Sí' ? $this->line($line) : $this->warn($line);
            }
        }

        // ── Export ───────────────────────────────────────────────────────────
        if ($this->option('export')) {
            $this->exportCsv($period->id, $detailRows, $porSucursal, $totalIncluido, $totalExcluido, $excluidos);
        }

        return 0;
    }

    private function exportCsv(int $periodId, array $rows, array $porSucursal, float $totalIncluido, float $totalExcluido = 0.0, array $excluidos = []): void
    {
        $dir  = 'auditorias';
        $file = "imss_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $headers = ['ID', 'Sucursal', 'NSS', 'Empleado', 'Cuota mensual', 'Hoja fuente', 'Incluido', 'Motivo', 'Sucursal normalizada'];
        $lines   = [implode(',', $headers)];
        $csv     = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $r['id'],
                $csv($r['sucursal']),
                $csv($r['nss']),
                $csv($r['empleado']),
                number_format($r['cuota_mensual'], 2, '.', ''),
                $csv($r['hoja']),
                $csv($r['incluido']),
                $csv($r['motivo']),
                $csv($r['suc_normalizada']),
            ]);
        }

        $lines[] = '';
        $lines[] = '"=== INCLUIDAS (OPERATIVAS) ==="';
        foreach ($porSucursal as $suc => $amt) {
            $lines[] = $csv($suc) . ',' . number_format($amt, 2, '.', '');
        }
        $lines[] = '"TOTAL INCLUIDO",' . number_format($totalIncluido, 2, '.', '');
        if (!empty($excluidos)) {
            $lines[] = '';
            $lines[] = '"=== EXCLUIDAS (NO OPERATIVAS) ==="';
            foreach ($excluidos as $suc => $amt) {
                $lines[] = $csv($suc) . ',' . number_format($amt, 2, '.', '');
            }
            $lines[] = '"TOTAL EXCLUIDO",' . number_format($totalExcluido, 2, '.', '');
        }

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
