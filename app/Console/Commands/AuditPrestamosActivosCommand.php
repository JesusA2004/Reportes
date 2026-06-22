<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditPrestamosActivosCommand extends Command
{
    protected $signature = 'reportes:audit-prestamos-activos
                                {period_id}
                                {--detail : mostrar cada contrato con motivo de inclusión/exclusión}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Préstamos Activos: capital activo SIN vencidos (distinto a valor cartera). Incluye contrato, sucursal, producto, días vencido.';

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
        $this->info("  AUDITORÍA PRÉSTAMOS ACTIVOS — {$period->label} (ID {$period->id})");
        $this->info('  KPI = Capital activo (days_past_due = 0). NO es valor cartera.');
        $this->info('════════════════════════════════════════════════════════════');

        // Consulta principal: contratos activos con su estado
        $loans = DB::table('fact_active_loans as al')
            ->leftJoin('branches as b', 'al.branch_id', '=', 'b.id')
            ->whereIn('al.period_id', $dataIds)
            ->select(
                'al.contract_id',
                'b.name as sucursal',
                'al.product',
                'al.granted_amount',
                'al.current_balance as capital_activo',
                'al.days_past_due',
                'al.period_id',
            )
            ->orderBy('b.name')
            ->orderByDesc('al.current_balance')
            ->get();

        $totalIncluido      = 0.0;
        $totalExcluido      = 0.0;
        $contratosIncluidos = 0;
        $contratosExcluidos = 0;
        $porSucursal        = [];
        $porProducto        = [];
        $detailRows         = [];

        foreach ($loans as $loan) {
            $dias    = (int) ($loan->days_past_due ?? 0);
            $capital = (float) ($loan->capital_activo ?? 0);
            $suc     = (string) ($loan->sucursal ?? '(sin sucursal)');
            $prod    = (string) ($loan->product ?? 'Sin producto');

            if ($dias === 0) {
                $totalIncluido += $capital;
                $contratosIncluidos++;
                $incluido = 'SÍ';
                $motivo   = 'Al corriente (days_past_due = 0)';
                $porSucursal[$suc] = ($porSucursal[$suc] ?? 0.0) + $capital;
                $porProducto[$prod] = ($porProducto[$prod] ?? 0.0) + $capital;
            } else {
                $totalExcluido += $capital;
                $contratosExcluidos++;
                $incluido = 'NO';
                $motivo   = "Vencido ({$dias} días)";
            }

            $detailRows[] = [
                'contrato'        => (string) ($loan->contract_id ?? ''),
                'sucursal'        => $suc,
                'producto'        => $prod,
                'capital_otorgado'=> (float) ($loan->granted_amount ?? 0),
                'capital_activo'  => $capital,
                'dias_vencido'    => $dias,
                'incluido'        => $incluido,
                'motivo'          => $motivo,
            ];
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN KPI PRÉSTAMOS ACTIVOS ════');
        $this->line('');
        $this->info(str_pad('KPI Préstamo activo (at_corriente):',         46) . '$' . number_format($totalIncluido, 2));
        $this->line(str_pad('Contratos incluidos (at_corriente):',         46) . $contratosIncluidos);
        $this->line(str_pad('Capital excluido (vencidos):',                46) . '$' . number_format($totalExcluido, 2));
        $this->line(str_pad('Contratos excluidos (vencidos):',             46) . $contratosExcluidos);
        $this->line(str_pad('Total contratos:',                            46) . ($contratosIncluidos + $contratosExcluidos));

        $this->line('');
        $this->info('════ POR SUCURSAL (incluidos) ════');
        arsort($porSucursal);
        $this->line(str_pad('Sucursal', 30) . str_pad('Capital activo', 20) . '% del total');
        $this->line(str_repeat('─', 60));
        foreach ($porSucursal as $suc => $amt) {
            $pct = $totalIncluido > 0 ? round($amt / $totalIncluido * 100, 1) : 0;
            $this->line(str_pad($suc, 30) . str_pad('$' . number_format($amt, 2), 20) . $pct . '%');
        }

        $this->line('');
        $this->info('════ POR PRODUCTO (incluidos) ════');
        arsort($porProducto);
        $this->line(str_pad('Producto', 40) . str_pad('Capital activo', 20) . '% del total');
        $this->line(str_repeat('─', 70));
        foreach ($porProducto as $prod => $amt) {
            $pct = $totalIncluido > 0 ? round($amt / $totalIncluido * 100, 1) : 0;
            $this->line(str_pad(mb_substr($prod, 0, 38), 40) . str_pad('$' . number_format($amt, 2), 20) . $pct . '%');
        }

        // ── Detalle ──────────────────────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE POR CONTRATO ════');
            $this->line(
                str_pad('Contrato', 20) .
                str_pad('Sucursal', 22) .
                str_pad('Producto', 28) .
                str_pad('Capital otorg.', 18) .
                str_pad('Capital activo', 18) .
                str_pad('Días venc.', 12) .
                str_pad('Inc.', 6) .
                'Motivo'
            );
            $this->line(str_repeat('─', 150));

            foreach ($detailRows as $r) {
                $line = str_pad(mb_substr($r['contrato'], 0, 18), 20) .
                        str_pad(mb_substr($r['sucursal'], 0, 20), 22) .
                        str_pad(mb_substr($r['producto'], 0, 26), 28) .
                        str_pad('$' . number_format($r['capital_otorgado'], 2), 18) .
                        str_pad('$' . number_format($r['capital_activo'], 2), 18) .
                        str_pad($r['dias_vencido'], 12) .
                        str_pad($r['incluido'], 6) .
                        $r['motivo'];

                if ($r['incluido'] === 'SÍ') {
                    $this->line($line);
                } else {
                    $this->warn($line);
                }
            }
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
        $file = "prestamos_activos_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $lines   = [];
        $headers = ['Contrato', 'Sucursal', 'Producto', 'Capital Otorgado', 'Capital Activo', 'Días Vencido', 'Incluido KPI', 'Motivo'];
        $lines[] = implode(',', $headers);

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['contrato']) . '"',
                '"' . str_replace('"', '""', $r['sucursal']) . '"',
                '"' . str_replace('"', '""', $r['producto']) . '"',
                number_format($r['capital_otorgado'], 2, '.', ''),
                number_format($r['capital_activo'], 2, '.', ''),
                $r['dias_vencido'],
                $r['incluido'],
                '"' . str_replace('"', '""', $r['motivo']) . '"',
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
