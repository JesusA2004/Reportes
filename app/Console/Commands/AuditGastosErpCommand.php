<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditGastosErpCommand extends Command
{
    protected $signature = 'reportes:audit-gastos-erp
                                {period_id}
                                {--detail : mostrar cada fila con su motivo de inclusión/exclusión}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Gastos ERP: filtro único por Sucursal (excluye Corporativo y sucursal vacía)';

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
        $this->info('  Filtro: únicamente Sucursal (excluye Corporativo y sucursal vacía)');
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

        // NOMINA_EXPENSE_CATS: same list as BranchRadiographyCalculator
        $nominaExpenseCats = ['Gasolina', 'Financiamiento Celular'];

        $totalCrudo                 = 0.0;
        $totalExclCorp              = 0.0;
        $totalExclSinSuc            = 0.0;
        $totalCargado               = 0.0;  // after branch filter, before Nómina reclassification
        $totalReclasificadoNomina   = 0.0;
        $totalOpex                  = 0.0;

        $conceptoTotales       = [];
        $nominaReclasDetalle   = [];
        $detailRows            = [];

        foreach ($rows as $row) {
            $monto      = (float) ($row->paid_amount ?: $row->amount);
            $sucursal   = (string) ($row->sucursal ?? '');
            $sucUpper   = strtoupper(trim($sucursal));
            $estatus    = strtoupper(trim(str_replace(['-','_'], ' ', (string) ($row->estatus_raw ?? ''))));
            $concepto   = (string) ($row->concept ?? '');
            $categoria  = (string) ($row->category ?? '');

            $totalCrudo += $monto;

            $motivo   = '';
            $incluido = true;
            $destino  = 'OPEX';

            if ($row->branch_id === null || $sucursal === '') {
                $incluido = false;
                $totalExclSinSuc += $monto;
                $motivo  = 'EXCLUIDO: Sin sucursal asignada';
                $destino = 'Excluido';
            } elseif ($sucUpper === 'CORPORATIVO') {
                $incluido = false;
                $totalExclCorp += $monto;
                $motivo  = 'EXCLUIDO: Sucursal Corporativo';
                $destino = 'Excluido';
            } elseif (in_array($categoria, $nominaExpenseCats, true)) {
                // Reclassified to Nómina y Capital Humano — counted in gastos_erp_cargado but not OPEX
                $totalCargado += $monto;
                $totalReclasificadoNomina += $monto;
                $motivo  = "RECLASIFICADO a Nómina: {$categoria}";
                $destino = 'Nómina y Capital Humano';
                $incluido = false;
                $nominaReclasDetalle[$categoria] = ($nominaReclasDetalle[$categoria] ?? 0.0) + $monto;
            } else {
                $totalCargado += $monto;
                $totalOpex    += $monto;
                $motivo = 'INCLUIDO en OPEX';
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
                'destino'   => $destino,
            ];
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN — INTEGRACIÓN ERP ════');
        $this->line(str_pad('ERP total crudo (todos los registros)',      48) . '$' . number_format($totalCrudo, 2));
        $this->line(str_pad('(-) Excluido: Sucursal Corporativo',         48) . '$' . number_format($totalExclCorp, 2));
        $this->line(str_pad('(-) Excluido: Sin sucursal asignada',        48) . '$' . number_format($totalExclSinSuc, 2));
        $this->line(str_repeat('─', 64));
        $this->line(str_pad('ERP total cargado (sucursales operativas)',   48) . '$' . number_format($totalCargado, 2));
        $this->line(str_pad('(-) Reclasificado a Nómina/Capital Humano',  48) . '$' . number_format($totalReclasificadoNomina, 2));
        foreach ($nominaReclasDetalle as $cat => $amt) {
            $this->line(str_pad("    → {$cat}", 48) . '$' . number_format($amt, 2));
        }
        $this->line(str_repeat('─', 64));
        $this->info(str_pad('(=) ERP FINAL OPEX',                         48) . '$' . number_format($totalOpex, 2));

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
                str_pad('Concepto', 32) .
                str_pad('Monto', 16) .
                str_pad('Destino', 28) .
                'Motivo'
            );
            $this->line(str_repeat('─', 140));
            foreach ($detailRows as $r) {
                $line = str_pad($r['folio'], 8) .
                        str_pad(mb_substr($r['sucursal'], 0, 20), 22) .
                        str_pad(mb_substr($r['concepto'], 0, 30), 32) .
                        str_pad('$' . number_format($r['monto'], 2), 16) .
                        str_pad(mb_substr($r['destino'], 0, 26), 28) .
                        $r['motivo'];
                if ($r['destino'] === 'OPEX') {
                    $this->line($line);
                } elseif ($r['destino'] === 'Nómina y Capital Humano') {
                    $this->comment($line);
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
        $headers = ['ID', 'Sucursal', 'Estatus', 'Concepto', 'Categoría', 'Observación', 'Fecha', 'Monto', 'Destino', 'Motivo'];
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
                '"' . str_replace('"', '""', $r['destino']) . '"',
                '"' . str_replace('"', '""', $r['motivo']) . '"',
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
