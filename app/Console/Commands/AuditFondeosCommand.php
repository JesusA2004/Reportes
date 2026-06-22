<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditFondeosCommand extends Command
{
    protected $signature = 'reportes:audit-fondeos
                                {period_id}
                                {--detail : mostrar cada movimiento de fondeo}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría fondeos entre sucursales: origen, destino, responsable y monto';

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
        $this->info("  AUDITORÍA FONDEOS ENTRE SUCURSALES — {$period->label} (ID {$period->id})");
        $this->info('  Categoría: Préstamos Intersucursales');
        $this->info('  Nota: NO afecta EBITDA — solo rastreo de flujo entre sucursales');
        $this->info('════════════════════════════════════════════════════════════');

        $fondeos = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('e.category', 'Préstamos Intersucursales')
            ->select(
                'e.id',
                'b.name as sucursal_origen',
                'e.concept as concepto',
                'e.observations',
                'e.expense_date as fecha',
                'e.amount',
                'e.paid_amount',
                'ds.code as fuente',
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload, '$.branch_to_detected')) as sucursal_destino"),
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload, '$.solicitante')) as responsable"),
            )
            ->orderBy('b.name')
            ->orderByDesc('e.amount')
            ->get();

        if ($fondeos->isEmpty()) {
            $this->info('  No se encontraron movimientos de fondeo en el período.');
            return 0;
        }

        $totalFondeo = 0.0;
        $porOrigen   = [];
        $detailRows  = [];

        foreach ($fondeos as $row) {
            $monto    = (float) ($row->paid_amount ?: $row->amount);
            $origen   = (string) ($row->sucursal_origen ?? '(sin sucursal)');
            $destino  = (string) ($row->sucursal_destino ?? 'No detectado');
            $resp     = (string) ($row->responsable ?? '');
            $obs      = (string) ($row->observations ?? '');

            $totalFondeo += $monto;
            $porOrigen[$origen] = ($porOrigen[$origen] ?? 0.0) + $monto;

            $detailRows[] = [
                'id'      => $row->id,
                'origen'  => $origen,
                'destino' => $destino,
                'resp'    => $resp ?: mb_substr($obs, 0, 30),
                'monto'   => $monto,
                'obs'     => mb_substr($obs, 0, 60),
                'justif'  => '',
                'fecha'   => $row->fecha,
                'fuente'  => $row->fuente,
            ];
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN ════');
        $this->info(str_pad('TOTAL FONDEOS ENTRE SUCURSALES', 44) . '$' . number_format($totalFondeo, 2));
        $this->line('  (Este monto NO se suma ni resta en EBITDA — solo rastreo de flujo)');

        $this->line('');
        $this->info('════ POR SUCURSAL ORIGEN ════');
        arsort($porOrigen);
        $this->line(str_pad('Sucursal Origen', 30) . 'Total Fondeado');
        $this->line(str_repeat('─', 50));
        foreach ($porOrigen as $suc => $amt) {
            $this->line(str_pad($suc, 30) . '$' . number_format($amt, 2));
        }

        // ── Detalle ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ TABLA DE FONDEOS ════');
        $this->line(
            str_pad('Origen', 20) .
            str_pad('Destino', 20) .
            str_pad('Responsable', 28) .
            str_pad('Monto', 16) .
            str_pad('Fuente', 14) .
            'Observación'
        );
        $this->line(str_repeat('─', 140));

        foreach ($detailRows as $r) {
            $this->line(
                str_pad(mb_substr($r['origen'], 0, 18), 20) .
                str_pad(mb_substr($r['destino'], 0, 18), 20) .
                str_pad(mb_substr($r['resp'], 0, 26), 28) .
                str_pad('$' . number_format($r['monto'], 2), 16) .
                str_pad($r['fuente'], 14) .
                mb_substr($r['obs'], 0, 50)
            );
        }

        // ── Export CSV ────────────────────────────────────────────────────────
        if ($this->option('export')) {
            $this->exportCsv($period->id, $detailRows);
        }

        return 0;
    }

    private function exportCsv(int $periodId, array $rows): void
    {
        $dir  = 'auditorias';
        $file = "fondeos_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $lines   = [];
        $headers = ['ID', 'Sucursal Origen', 'Sucursal Destino', 'Responsable', 'Monto', 'Fuente', 'Observación', 'Fecha'];
        $lines[] = implode(',', $headers);

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $r['id'],
                '"' . str_replace('"', '""', $r['origen']) . '"',
                '"' . str_replace('"', '""', $r['destino']) . '"',
                '"' . str_replace('"', '""', $r['resp']) . '"',
                number_format($r['monto'], 2, '.', ''),
                '"' . str_replace('"', '""', $r['fuente']) . '"',
                '"' . str_replace('"', '""', $r['obs']) . '"',
                $r['fecha'] ?? '',
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
