<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditPrestamosActivosCommand extends Command
{
    protected $signature = 'reportes:audit-prestamos-activos
                                {period_id}
                                {--detail : mostrar cada contrato con motivo de inclusión/exclusión}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Préstamos Activos: capital activo SIN vencidos, excluyendo Aguascalientes (distinto a valor cartera). Incluye contrato, sucursal, producto, días vencido.';

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

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA PRÉSTAMOS ACTIVOS — {$period->label} (ID {$period->id})");
        $this->info('  KPI = Capital activo (days_past_due = 0), excluye Aguascalientes. NO es valor cartera.');
        $this->info('════════════════════════════════════════════════════════════');

        $agsIds = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && strtoupper(trim($real)) === 'AGUASCALIENTES') {
                $agsIds[] = (int) $b->id;
            }
        }

        // Consulta principal: todos los contratos del periodo (fact_portfolios es la fuente de cartera)
        $loans = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $dataIds)
            ->select(
                'po.contract',
                'po.client_name',
                'b.name as sucursal',
                'po.branch_id',
                'po.product_name',
                'po.balance as saldo_actual',
                'po.capital_activo',
                'po.days_past_due',
                'po.period_id',
            )
            ->orderBy('b.name')
            ->orderByDesc('po.capital_activo')
            ->get();

        $totalIncluido      = 0.0;
        $totalExcluido      = 0.0;
        $totalExcluidoAgs   = 0.0;
        $contratosIncluidos = 0;
        $contratosExcluidos = 0;
        $porSucursal        = [];
        $porProducto        = [];
        $detailRows         = [];

        foreach ($loans as $loan) {
            $dias    = (int) ($loan->days_past_due ?? 0);
            $capital = (float) ($loan->capital_activo ?? 0);
            $saldo   = (float) ($loan->saldo_actual ?? 0);
            $suc     = (string) ($loan->sucursal ?? '(sin sucursal)');
            $prod    = (string) ($loan->product_name ?? 'Sin producto');
            $isAgs   = in_array((int) ($loan->branch_id ?? 0), $agsIds, true);

            if ($isAgs) {
                $totalExcluidoAgs += $capital;
                $contratosExcluidos++;
                $incluido = 'NO';
                $motivo   = 'EXCLUIDO: Aguascalientes';
            } elseif ($dias === 0) {
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
                'contrato'        => (string) ($loan->contract ?? ''),
                'cliente'         => (string) ($loan->client_name ?? ''),
                'sucursal'        => $suc,
                'producto'        => $prod,
                'saldo_actual'    => $saldo,
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
        $this->line(str_pad('Capital excluido (Aguascalientes):',          46) . '$' . number_format($totalExcluidoAgs, 2));
        $this->line(str_pad('Contratos excluidos (vencidos+AGS):',         46) . $contratosExcluidos);
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
                str_pad('Contrato', 18) .
                str_pad('Cliente', 24) .
                str_pad('Sucursal', 20) .
                str_pad('Producto', 26) .
                str_pad('Saldo actual', 16) .
                str_pad('Capital', 16) .
                str_pad('Días venc.', 12) .
                str_pad('Inc.', 6) .
                'Motivo'
            );
            $this->line(str_repeat('─', 160));

            foreach ($detailRows as $r) {
                $line = str_pad(mb_substr($r['contrato'], 0, 16), 18) .
                        str_pad(mb_substr($r['cliente'] ?? '', 0, 22), 24) .
                        str_pad(mb_substr($r['sucursal'], 0, 18), 20) .
                        str_pad(mb_substr($r['producto'], 0, 24), 26) .
                        str_pad('$' . number_format($r['saldo_actual'], 2), 16) .
                        str_pad('$' . number_format($r['capital_activo'], 2), 16) .
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
        $headers = ['Contrato', 'Cliente', 'Sucursal', 'Producto', 'Saldo actual', 'Capital', 'Días Vencido', 'Incluido KPI', 'Motivo'];
        $lines[] = implode(',', $headers);

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['contrato']) . '"',
                '"' . str_replace('"', '""', ($r['cliente'] ?? '')) . '"',
                '"' . str_replace('"', '""', $r['sucursal']) . '"',
                '"' . str_replace('"', '""', $r['producto']) . '"',
                number_format($r['saldo_actual'], 2, '.', ''),
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
