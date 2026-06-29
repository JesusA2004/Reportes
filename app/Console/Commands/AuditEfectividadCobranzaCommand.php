<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditEfectividadCobranzaCommand extends Command
{
    protected $signature = 'reportes:audit-efectividad-cobranza
                                {period_id}
                                {--detail : mostrar cada movimiento con su contrato y estatus}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría efectividad de cobranza: clasifica cobros por estatus del crédito (Vigente/Atrasado/Vencido)';

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
        $this->info("  EFECTIVIDAD DE COBRANZA — {$period->label} (ID {$period->id})");
        $this->info('  Estatus: Vigente (DPD=0) / Atrasado (1-90) / Vencido (>90)');
        $this->info('  Exclusiones: Seguros, Condonaciones, Coberturas');
        $this->info('════════════════════════════════════════════════════════════');

        // Portfolio DPD by contract (authoritative)
        $portfolioDpd = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('contract')
            ->selectRaw('contract, MAX(days_past_due) as dpd')
            ->groupBy('contract')
            ->pluck('dpd', 'contract')
            ->all();

        // Recoveries excluding insurance/condonation
        $recoveries = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->where(function ($q) {
                $q->where('r.is_savehearts', '!=', 1)
                  ->where('r.transaction', '!=', 'CONDONACION')
                  ->where(function ($q2) {
                      $q2->whereRaw("NOT (r.is_savehearts = 0 AND (
                          UPPER(COALESCE(r.concept,'')) LIKE '%COBERTURA%'
                          OR UPPER(COALESCE(r.operation,'')) LIKE '%COBERTURA%'
                          OR UPPER(COALESCE(r.concept,'')) LIKE '%SEGURO%'
                          OR UPPER(COALESCE(r.operation,'')) LIKE '%SEGURO%'
                      ))");
                  });
            })
            ->whereNotNull('r.contract')
            ->select(
                'r.id',
                'r.contract',
                'r.days_past_due as recovery_dpd',
                'b.name as sucursal',
                'r.concept',
                'r.operation',
                'r.transaction',
                DB::raw('COALESCE(r.capital, 0) as capital'),
                DB::raw('COALESCE(r.interest, 0) as interes'),
                DB::raw('COALESCE(r.tax, 0) as impuesto'),
                DB::raw('COALESCE(r.charges_due, 0) as moratorios'),
                DB::raw('COALESCE(r.total_amount, 0) as total'),
            )
            ->get();

        if ($recoveries->isEmpty()) {
            $this->warn('  No se encontraron cobros para el período.');
            return 0;
        }

        $empty = fn () => ['capital' => 0.0, 'interes' => 0.0, 'impuesto' => 0.0, 'moratorios' => 0.0, 'total' => 0.0, 'contratos' => 0, 'movimientos' => 0];
        $buckets = ['Vigente' => $empty(), 'Atrasado' => $empty(), 'Vencido' => $empty(), 'Sin status' => $empty()];
        $contractsSeen = ['Vigente' => [], 'Atrasado' => [], 'Vencido' => [], 'Sin status' => []];
        $dpds = [];
        $detailRows = [];

        foreach ($recoveries as $row) {
            $contract = (string) $row->contract;
            $dpd = $row->recovery_dpd !== null
                ? (int) $row->recovery_dpd
                : ($portfolioDpd[$contract] ?? null);

            $status = match (true) {
                $dpd === null => 'Sin status',
                $dpd === 0    => 'Vigente',
                $dpd <= 90    => 'Atrasado',
                default       => 'Vencido',
            };

            $buckets[$status]['capital']    += (float) $row->capital;
            $buckets[$status]['interes']    += (float) $row->interes;
            $buckets[$status]['impuesto']   += (float) $row->impuesto;
            $buckets[$status]['moratorios'] += (float) $row->moratorios;
            $buckets[$status]['total']      += (float) $row->total;
            $buckets[$status]['movimientos']++;
            if (!isset($contractsSeen[$status][$contract])) {
                $contractsSeen[$status][$contract] = true;
                $buckets[$status]['contratos']++;
            }

            $dpds[$contract] = $dpd;

            $detailRows[] = [
                'id'          => $row->id,
                'contract'    => $contract,
                'sucursal'    => (string) ($row->sucursal ?? '(sin sucursal)'),
                'status'      => $status,
                'dpd'         => $dpd ?? '—',
                'dpd_fuente'  => $row->recovery_dpd !== null ? 'cobro' : (($portfolioDpd[$contract] ?? null) !== null ? 'cartera' : 'desconocido'),
                'concept'     => (string) ($row->concept ?? ''),
                'operation'   => (string) ($row->operation ?? ''),
                'capital'     => (float) $row->capital,
                'interes'     => (float) $row->interes,
                'impuesto'    => (float) $row->impuesto,
                'moratorios'  => (float) $row->moratorios,
                'total'       => (float) $row->total,
            ];
        }

        $grandTotal = $empty();
        foreach ($buckets as $b) {
            $grandTotal['capital']    += $b['capital'];
            $grandTotal['interes']    += $b['interes'];
            $grandTotal['impuesto']   += $b['impuesto'];
            $grandTotal['moratorios'] += $b['moratorios'];
            $grandTotal['total']      += $b['total'];
            $grandTotal['contratos']  += $b['contratos'];
            $grandTotal['movimientos'] += $b['movimientos'];
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN — EFECTIVIDAD DE COBRANZA ════');
        $this->line(
            str_pad('Estatus', 14) .
            str_pad('Contratos', 12) .
            str_pad('Movs.', 8) .
            str_pad('Capital', 18) .
            str_pad('Interés', 18) .
            str_pad('Impuesto', 18) .
            str_pad('Moratorios', 18) .
            str_pad('Total', 18) .
            '% Total'
        );
        $this->line(str_repeat('─', 130));

        $gtTotal = $grandTotal['total'] ?: 1;
        foreach ($buckets as $status => $b) {
            $pct = round($b['total'] / $gtTotal * 100, 1);
            $line = str_pad($status, 14) .
                    str_pad($b['contratos'], 12) .
                    str_pad($b['movimientos'], 8) .
                    str_pad('$' . number_format($b['capital'],    2), 18) .
                    str_pad('$' . number_format($b['interes'],    2), 18) .
                    str_pad('$' . number_format($b['impuesto'],   2), 18) .
                    str_pad('$' . number_format($b['moratorios'], 2), 18) .
                    str_pad('$' . number_format($b['total'],      2), 18) .
                    "{$pct}%";
            match ($status) {
                'Vigente'  => $this->info($line),
                'Atrasado' => $this->comment($line),
                'Vencido'  => $this->warn($line),
                default    => $this->line($line),
            };
        }
        $this->line(str_repeat('─', 130));
        $this->line(
            str_pad('TOTAL', 14) .
            str_pad($grandTotal['contratos'], 12) .
            str_pad($grandTotal['movimientos'], 8) .
            str_pad('$' . number_format($grandTotal['capital'],    2), 18) .
            str_pad('$' . number_format($grandTotal['interes'],    2), 18) .
            str_pad('$' . number_format($grandTotal['impuesto'],   2), 18) .
            str_pad('$' . number_format($grandTotal['moratorios'], 2), 18) .
            '$' . number_format($grandTotal['total'], 2)
        );

        // ── Efectividad real (Vencido recuperado) ────────────────────────────
        $this->line('');
        $this->info('════ EFECTIVIDAD ════');
        $totalVencidoYAtrasado = $buckets['Vencido']['total'] + $buckets['Atrasado']['total'];
        $pctRecuperadoEnMora = $grandTotal['total'] > 0
            ? round($totalVencidoYAtrasado / $grandTotal['total'] * 100, 1)
            : 0;
        $this->line(str_pad('Cobros en mora (Atrasado + Vencido)', 52) . '$' . number_format($totalVencidoYAtrasado, 2) . " ({$pctRecuperadoEnMora}% del total cobrado)");
        $this->line(str_pad('Cobros al corriente (Vigente)', 52) . '$' . number_format($buckets['Vigente']['total'], 2));

        // ── Detalle ──────────────────────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE MOVIMIENTO A MOVIMIENTO ════');
            $this->line(
                str_pad('ID', 8) .
                str_pad('Contrato', 22) .
                str_pad('Sucursal', 22) .
                str_pad('Estatus', 12) .
                str_pad('DPD', 8) .
                str_pad('Fuente', 10) .
                str_pad('Total', 16) .
                'Concepto'
            );
            $this->line(str_repeat('─', 140));
            foreach ($detailRows as $r) {
                $line = str_pad($r['id'], 8) .
                        str_pad(mb_substr($r['contract'], 0, 20), 22) .
                        str_pad(mb_substr($r['sucursal'], 0, 20), 22) .
                        str_pad($r['status'], 12) .
                        str_pad((string) $r['dpd'], 8) .
                        str_pad($r['dpd_fuente'], 10) .
                        str_pad('$' . number_format($r['total'], 2), 16) .
                        mb_substr($r['concept'], 0, 40);
                match ($r['status']) {
                    'Vigente'    => $this->info($line),
                    'Atrasado'   => $this->comment($line),
                    'Vencido'    => $this->warn($line),
                    default      => $this->line($line),
                };
            }
        }

        // ── Export CSV ────────────────────────────────────────────────────────
        if ($this->option('export')) {
            $this->exportCsv($period->id, $detailRows, $buckets, $grandTotal);
        }

        return 0;
    }

    private function exportCsv(int $periodId, array $rows, array $buckets, array $grandTotal): void
    {
        $dir  = 'auditorias';
        $file = "efectividad_cobranza_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $headers = ['ID', 'Contrato', 'Sucursal', 'Estatus', 'DPD', 'Fuente DPD', 'Capital', 'Interés', 'Impuesto', 'Moratorios', 'Total', 'Concepto', 'Operación'];
        $lines   = [implode(',', $headers)];

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $r['id'],
                '"' . str_replace('"', '""', $r['contract']) . '"',
                '"' . str_replace('"', '""', $r['sucursal']) . '"',
                '"' . str_replace('"', '""', $r['status']) . '"',
                $r['dpd'] === '—' ? '' : $r['dpd'],
                '"' . str_replace('"', '""', $r['dpd_fuente']) . '"',
                number_format($r['capital'],    2, '.', ''),
                number_format($r['interes'],    2, '.', ''),
                number_format($r['impuesto'],   2, '.', ''),
                number_format($r['moratorios'], 2, '.', ''),
                number_format($r['total'],      2, '.', ''),
                '"' . str_replace('"', '""', $r['concept']) . '"',
                '"' . str_replace('"', '""', $r['operation']) . '"',
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
