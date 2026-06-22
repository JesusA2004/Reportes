<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditGastosErpCommand extends Command
{
    protected $signature = 'reportes:audit-gastos-erp
                                {period_id}
                                {--detail : mostrar cada fila con su motivo de inclusión/exclusión}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Gastos ERP: valida filtros de estatus, sucursal y monto para el período';

    // Statuses that are counted in EBITDA
    private const BILLABLE_NORM = [
        'COMPROBACION ACEPTADA',
        'PAGADA',
        'PAGADO',
        'PAGO AUTORIZADO',
        'POR COMPROBAR',
        'COMPROBADA',
        'AUTORIZADO',
    ];

    // Non-operative sucursales excluded from ERP total
    private const EXCLUDED_BRANCHES_UPPER = ['CORPORATIVO', 'CORP', 'CORPORATE'];

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

        $erpId = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');
        if (!$erpId) {
            $this->error('Fuente gastos_erp no encontrada en data_sources.');
            return 1;
        }

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA GASTOS ERP — {$period->label} (ID {$period->id})");
        $this->info('  Columna de monto: Total final requisición');
        $this->info('  Clasificación: por Concepto');
        $this->info('════════════════════════════════════════════════════════════');

        // ── Pull all ERP expense rows with branch info ──────────────────────
        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $erpId)
            ->select(
                'e.id',
                'b.name as sucursal',
                'e.branch_id',
                'e.category',
                'e.concept',
                'e.observations',
                'e.expense_date',
                'e.amount',
                'e.paid_amount',
                DB::raw("JSON_UNQUOTE(JSON_EXTRACT(e.raw_payload, '$.estatus')) as estatus_raw"),
            )
            ->orderBy('b.name')
            ->orderByDesc('e.amount')
            ->get();

        $totalCrudo       = 0.0;
        $totalIncluido    = 0.0;
        $totalExclCorp    = 0.0;
        $totalExclSinSuc  = 0.0;
        $totalExclStatus  = 0.0;
        $totalExclOtro    = 0.0;

        $conceptoTotales  = [];
        $statusAuditoria  = [];  // statuses not in BILLABLE_NORM and not CAPTURADA
        $detailRows       = [];

        foreach ($rows as $row) {
            $monto      = (float) ($row->paid_amount ?: $row->amount);
            $sucursal   = (string) ($row->sucursal ?? '');
            $sucUpper   = strtoupper(trim($sucursal));
            $estatus    = strtoupper(trim(str_replace(['-','_'], ' ', (string) ($row->estatus_raw ?? ''))));
            $concepto   = (string) ($row->concept ?? '');
            $categoria  = (string) ($row->category ?? '');

            $totalCrudo += $monto;

            $motivo     = '';
            $incluido   = true;

            if (in_array($sucUpper, self::EXCLUDED_BRANCHES_UPPER, true)) {
                $incluido = false;
                $totalExclCorp += $monto;
                $motivo = 'EXCLUIDO: Sucursal Corporativo';
            } elseif ($row->branch_id === null || $sucursal === '') {
                $incluido = false;
                $totalExclSinSuc += $monto;
                $motivo = 'EXCLUIDO: Sin sucursal asignada';
            } elseif ($estatus === 'CAPTURADA') {
                $incluido = false;
                $totalExclStatus += $monto;
                $motivo = 'EXCLUIDO: Estatus CAPTURADA';
            } elseif (!in_array($estatus, self::BILLABLE_NORM, true)) {
                // Unknown status → send to audit bucket
                $incluido = false;
                $totalExclOtro += $monto;
                $motivo = "AUDITORÍA: Estatus desconocido [{$estatus}]";
                $statusAuditoria[$estatus] = ($statusAuditoria[$estatus] ?? 0) + 1;
            } else {
                $totalIncluido += $monto;
                $motivo = 'INCLUIDO';
                $label  = $concepto ?: $categoria ?: 'Sin concepto';
                $conceptoTotales[$label] = ($conceptoTotales[$label] ?? 0.0) + $monto;
            }

            $detailRows[] = [
                'folio'     => $row->id,
                'sucursal'  => $sucursal ?: '(sin sucursal)',
                'estatus'   => $estatus,
                'concepto'  => $concepto,
                'categoria' => $categoria,
                'obs'       => mb_substr((string)($row->observations ?? ''), 0, 50),
                'fecha'     => $row->expense_date,
                'monto'     => $monto,
                'incluido'  => $incluido ? 'SÍ' : 'NO',
                'motivo'    => $motivo,
            ];
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN ════');
        $this->line(str_pad('Total crudo ERP',                    44) . '$' . number_format($totalCrudo, 2));
        $this->line(str_pad('(-) Excluido por Corporativo',       44) . '$' . number_format($totalExclCorp, 2));
        $this->line(str_pad('(-) Excluido sin sucursal',         44) . '$' . number_format($totalExclSinSuc, 2));
        $this->line(str_pad('(-) Excluido estatus CAPTURADA',    44) . '$' . number_format($totalExclStatus, 2));
        if ($totalExclOtro > 0) {
            $this->warn(str_pad('(-) En auditoría (estatus desconocido)', 44) . '$' . number_format($totalExclOtro, 2));
        }
        $this->line(str_repeat('─', 60));
        $this->info(str_pad('TOTAL INCLUIDO ERP',                 44) . '$' . number_format($totalIncluido, 2));

        $refERP = 586_820.71;
        $diff   = $totalIncluido - $refERP;
        $sign   = $diff >= 0 ? '+' : '';
        $match  = abs($diff) < 200 ? '✓ DENTRO DE RANGO' : (abs($diff) < 5_000 ? '≈ CERCANO' : '⚠ REVISAR');
        $this->line('');
        $this->line(str_pad('Referencia esperada',  44) . '$' . number_format($refERP, 2));
        $this->line(str_pad('Diferencia',            44) . $sign . '$' . number_format($diff, 2) . '  ' . $match);

        // ── Estatus en auditoría ─────────────────────────────────────────────
        if (!empty($statusAuditoria)) {
            $this->line('');
            $this->warn('════ ESTATUS EN AUDITORÍA (no en whitelist) ════');
            foreach ($statusAuditoria as $est => $cnt) {
                $this->warn("  [{$est}] → {$cnt} registro(s)");
            }
            $this->warn('  → Revisar y decidir si incluir o excluir estos estatus.');
        }

        // ── Por concepto ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ DESGLOSE POR CONCEPTO (incluidos) ════');
        arsort($conceptoTotales);
        $this->line(str_pad('Concepto', 45) . 'Monto');
        $this->line(str_repeat('─', 65));
        foreach ($conceptoTotales as $con => $amt) {
            $this->line(str_pad(mb_substr($con, 0, 43), 45) . '$' . number_format($amt, 2));
        }

        // ── Detalle fila por fila ─────────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE FILA A FILA ════');
            $this->line(
                str_pad('ID', 8) .
                str_pad('Sucursal', 22) .
                str_pad('Estatus', 24) .
                str_pad('Concepto', 32) .
                str_pad('Monto', 16) .
                str_pad('Inc', 5) .
                'Motivo'
            );
            $this->line(str_repeat('─', 140));
            foreach ($detailRows as $r) {
                $line = str_pad($r['folio'], 8) .
                        str_pad(mb_substr($r['sucursal'], 0, 20), 22) .
                        str_pad(mb_substr($r['estatus'], 0, 22), 24) .
                        str_pad(mb_substr($r['concepto'], 0, 30), 32) .
                        str_pad('$' . number_format($r['monto'], 2), 16) .
                        str_pad($r['incluido'], 5) .
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
        $file = "gastos_erp_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $lines   = [];
        $headers = ['ID', 'Sucursal', 'Estatus', 'Concepto', 'Categoría', 'Observación', 'Fecha', 'Monto', 'Incluido', 'Motivo'];
        $lines[] = implode(',', $headers);

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $r['folio'],
                '"' . str_replace('"', '""', $r['sucursal']) . '"',
                '"' . str_replace('"', '""', $r['estatus']) . '"',
                '"' . str_replace('"', '""', $r['concepto']) . '"',
                '"' . str_replace('"', '""', $r['categoria']) . '"',
                '"' . str_replace('"', '""', $r['obs']) . '"',
                $r['fecha'] ?? '',
                number_format($r['monto'], 2, '.', ''),
                $r['incluido'],
                '"' . str_replace('"', '""', $r['motivo']) . '"',
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
