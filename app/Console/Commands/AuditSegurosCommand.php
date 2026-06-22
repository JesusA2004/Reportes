<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditSegurosCommand extends Command
{
    protected $signature = 'reportes:audit-seguros
                                {period_id}
                                {--detail : mostrar cada movimiento de seguro/cobertura}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría seguros y coberturas: Savehearts, Crédito Grupal/Comadres, CRECE 30%/70%';

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
        $this->info("  AUDITORÍA SEGUROS / COBERTURAS — {$period->label} (ID {$period->id})");
        $this->info('  Fuentes: fact_recoveries (ingresos) + fact_expenses (gastos Lendus pólizas)');
        $this->info('════════════════════════════════════════════════════════════');

        // ── A. Seguros en INGRESOS (fact_recoveries) ──────────────────────
        $this->line('');
        $this->info('════ A. COBERTURAS / SEGUROS EN INGRESOS (cobranza) ════');

        $recovRows = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->where('r.is_savehearts', 1)
            ->select(
                'r.id',
                'b.name as sucursal',
                'r.concept',
                'r.operation',
                'r.total_amount',
                'r.savehearts_crece_share',
                DB::raw("CASE
                    WHEN r.savehearts_crece_share > 0 THEN 'CRECE'
                    WHEN UPPER(COALESCE(r.concept,'')) LIKE '%COMADRES%'
                      OR UPPER(COALESCE(r.concept,'')) LIKE '%GRUPAL%'
                      OR UPPER(COALESCE(r.operation,'')) LIKE '%COMADRES%'
                      OR UPPER(COALESCE(r.operation,'')) LIKE '%GRUPAL%'
                    THEN 'COMADRES/GRUPAL'
                    ELSE 'SAVEHEARTS'
                END as tipo_seguro"),
            )
            ->orderBy('tipo_seguro')
            ->orderByDesc('r.total_amount')
            ->get();

        $totSavehearts = 0.0;
        $totComadres   = 0.0;
        $totCreceBruto = 0.0;
        $totCreceRec   = 0.0;
        $detailRecov   = [];

        foreach ($recovRows as $row) {
            $monto = (float) $row->total_amount;
            $tipo  = (string) $row->tipo_seguro;
            $crece = (float) ($row->savehearts_crece_share ?? 0.0);

            switch ($tipo) {
                case 'CRECE':
                    $totCreceBruto += $monto;
                    $totCreceRec   += $crece;
                    $tratamiento   = sprintf('30%% reconocido=$%.2f | 70%% puente=$%.2f', $crece, $monto - $crece);
                    $reconocido    = $crece;
                    $noreconocido  = round($monto - $crece, 2);
                    break;
                case 'COMADRES/GRUPAL':
                    $totComadres   += $monto;
                    $tratamiento   = 'Monto puente — NO suma a ingresos ni gastos';
                    $reconocido    = 0.0;
                    $noreconocido  = $monto;
                    break;
                default:
                    $totSavehearts += $monto;
                    $tratamiento   = 'Monto puente — NO suma a ingresos ni gastos';
                    $reconocido    = 0.0;
                    $noreconocido  = $monto;
            }

            $detailRecov[] = [
                'fuente'       => 'fact_recoveries',
                'id'           => $row->id,
                'sucursal'     => (string) ($row->sucursal ?? '(sin suc)'),
                'concepto'     => (string) ($row->concept ?? ''),
                'operacion'    => (string) ($row->operation ?? ''),
                'tipo'         => $tipo,
                'monto'        => $monto,
                'tratamiento'  => $tratamiento,
                'reconocido'   => $reconocido,
                'noreconocido' => $noreconocido,
            ];
        }

        // ── B. Seguros en GASTOS Lendus (fact_expenses pólizas) ──────────
        $lendusId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        $gastoSeguros = 0.0;
        $detailGasto  = [];

        if ($lendusId) {
            $gastoRows = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ru.data_source_id', $lendusId)
                ->where('e.category', 'Pólizas')
                ->select(
                    'e.id',
                    'b.name as sucursal',
                    'e.concept',
                    'e.observations',
                    'e.amount',
                    'e.paid_amount',
                )
                ->orderByDesc('e.amount')
                ->get();

            foreach ($gastoRows as $row) {
                $monto = (float) ($row->paid_amount ?: $row->amount);
                $gastoSeguros += $monto;
                $detailGasto[] = [
                    'fuente'      => 'gastos_lendus',
                    'id'          => $row->id,
                    'sucursal'    => (string) ($row->sucursal ?? '(sin suc)'),
                    'concepto'    => (string) ($row->concept ?? ''),
                    'operacion'   => (string) ($row->observations ?? ''),
                    'tipo'        => 'PÓLIZA LENDUS (PUENTE)',
                    'monto'       => $monto,
                    'tratamiento' => 'Monto puente — NO suma a gastos operativos ni a EBITDA',
                    'reconocido'  => 0.0,
                    'noreconocido'=> $monto,
                ];
            }
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $totalPuente       = $totSavehearts + $totComadres + $gastoSeguros;
        $totCreceNoRec     = round($totCreceBruto - $totCreceRec, 2);
        $totalSegurosTotal = $totalPuente + $totCreceBruto;

        $this->line('');
        $this->info('════ RESUMEN SEGUROS / COBERTURAS ════');
        $this->line('');
        $this->line(str_pad('Cobertura Savehearts (ingresos):',           46) . '$' . number_format($totSavehearts, 2));
        $this->line(str_pad('Cobertura Crédito Grupal / Comadres (ing):', 46) . '$' . number_format($totComadres, 2));
        $this->line(str_pad('Pólizas Lendus / gastos (puente):',          46) . '$' . number_format($gastoSeguros, 2));
        $this->line(str_repeat('─', 62));
        $this->line(str_pad('Subtotal puente (no reconocido):',            46) . '$' . number_format($totalPuente, 2));
        $this->line('');
        $this->line(str_pad('Seguro CRECE bruto:',                         46) . '$' . number_format($totCreceBruto, 2));
        $this->info(str_pad('Seguro CRECE reconocido (30%):',              46) . '$' . number_format($totCreceRec, 2));
        $this->line(str_pad('Seguro CRECE no reconocido / puente (70%):', 46) . '$' . number_format($totCreceNoRec, 2));
        $this->line(str_repeat('─', 62));
        $this->info(str_pad('TOTAL SEGUROS / COBERTURAS (bruto):',         46) . '$' . number_format($totalSegurosTotal, 2));
        $this->info(str_pad('Monto reconocido como ingreso (CRECE 30%):',  46) . '$' . number_format($totCreceRec, 2));
        $this->line(str_pad('Monto puente / no reconocido:',               46) . '$' . number_format($totalSegurosTotal - $totCreceRec, 2));

        // ── Detalle ──────────────────────────────────────────────────────────
        if ($this->option('detail')) {
            $allDetail = array_merge($detailRecov, $detailGasto);
            $this->line('');
            $this->info('════ DETALLE FILA A FILA ════');
            $this->line(
                str_pad('Fuente', 18) .
                str_pad('Sucursal', 20) .
                str_pad('Tipo', 22) .
                str_pad('Monto bruto', 16) .
                str_pad('Reconocido', 14) .
                str_pad('Puente', 14) .
                'Tratamiento'
            );
            $this->line(str_repeat('─', 140));
            foreach ($allDetail as $r) {
                $line = str_pad(mb_substr($r['fuente'], 0, 16), 18) .
                        str_pad(mb_substr($r['sucursal'], 0, 18), 20) .
                        str_pad(mb_substr($r['tipo'], 0, 20), 22) .
                        str_pad('$' . number_format($r['monto'], 2), 16) .
                        str_pad('$' . number_format($r['reconocido'], 2), 14) .
                        str_pad('$' . number_format($r['noreconocido'], 2), 14) .
                        mb_substr($r['tratamiento'], 0, 50);
                if ($r['reconocido'] > 0) {
                    $this->info($line);
                } else {
                    $this->line($line);
                }
            }
        }

        // ── Export CSV ────────────────────────────────────────────────────────
        if ($this->option('export')) {
            $this->exportCsv($period->id, array_merge($detailRecov, $detailGasto));
        }

        return 0;
    }

    private function exportCsv(int $periodId, array $rows): void
    {
        $dir  = 'auditorias';
        $file = "seguros_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $lines   = [];
        $headers = ['Fuente', 'ID', 'Sucursal', 'Concepto', 'Operación', 'Tipo Seguro', 'Monto Bruto', 'Reconocido Ingreso', 'No Reconocido Puente', 'Tratamiento'];
        $lines[] = implode(',', $headers);

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['fuente']) . '"',
                $r['id'],
                '"' . str_replace('"', '""', $r['sucursal']) . '"',
                '"' . str_replace('"', '""', $r['concepto']) . '"',
                '"' . str_replace('"', '""', $r['operacion']) . '"',
                '"' . str_replace('"', '""', $r['tipo']) . '"',
                number_format($r['monto'], 2, '.', ''),
                number_format($r['reconocido'], 2, '.', ''),
                number_format($r['noreconocido'], 2, '.', ''),
                '"' . str_replace('"', '""', $r['tratamiento']) . '"',
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
