<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditGastosLendusCommand extends Command
{
    protected $signature = 'reportes:audit-gastos-lendus
                                {period_id}
                                {--detail : mostrar cada fila con motivo de inclusión/exclusión}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Gastos Lendus: valida exclusiones (Excedentes, Nómina, IMSS, Deducciones, Fondeo, Seguros/Pólizas)';

    // Category → bucket for whole-category exclusions
    private const EXCLUSION_MAP = [
        'Envío de utilidad a corporativo' => 'EXCLUIDO: Excedentes (envío utilidad corporativo)',
        'Préstamos Intersucursales'        => 'EXCLUIDO: Fondeo a sucursal (rastreo entre sucursales)',
        'Pólizas'                          => 'EXCLUIDO: Seguros/Coberturas (monto puente)',
        'Financiamiento Celular'           => 'EXCLUIDO: Nómina (financiamiento celular)',
        'Gasolina'                         => 'EXCLUIDO: Nómina (gasolina)',
    ];

    // 'Nómina y Capital Humano' is handled at concept level:
    // Only these concepts are true nómina/payroll and are excluded. All other concepts
    // under this category (PAGO FINANCIAMIENTO MOTO, COMPRA DE CASCOS, GASTOS MEDICOS…)
    // are operational expenses → included in gastos_lendus_total.
    private const NOMINA_SKIP_CONCEPTS = [
        'NOMINA', 'PAGO DE IMSS', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO PRESTAMO Z',
    ];

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

        // Always use gastos_lendus (PDF) — XLS rows have all NULL branch_id and are filtered
        // out by the branch filter in BranchRadiographyCalculator::accumulateGastos().
        $lendusPdfId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        if (!$lendusPdfId) {
            $this->error('No se encontró fuente gastos_lendus (PDF) en data_sources.');
            return 1;
        }
        $lendusIds   = collect([$lendusPdfId]);
        $fuenteLabel = 'gastos_lendus (PDF)';

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA GASTOS LENDUS — {$period->label} (ID {$period->id})");
        $this->info("  Fuente activa: {$fuenteLabel}");
        $this->info('  Columna de monto: Monto aplicado en caja (o Monto pagado empresa)');
        $this->info('  Clasificación: por Concepto');
        $this->info('════════════════════════════════════════════════════════════');

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('ru.data_source_id', $lendusIds)
            ->select(
                'e.id',
                'b.name as sucursal',
                'e.category',
                'e.concept',
                'e.observations',
                'e.expense_date',
                'e.amount',
                'e.paid_amount',
            )
            ->orderBy('e.category')
            ->orderByDesc('e.amount')
            ->get();

        $totalCrudo          = 0.0;
        $totalIncluido       = 0.0;
        $exclBuckets         = [];
        $conceptoTotales     = [];
        $detailRows          = [];

        foreach ($rows as $row) {
            $monto    = (float) ($row->paid_amount ?: $row->amount);
            $cat      = (string) ($row->category ?? '');
            // Normalize unicode whitespace (incl. non-breaking spaces U+00A0) before matching
            $concepto = strtoupper(trim((string) ($row->concept ?? '')));
            $concepto = preg_replace('/\s+/u', ' ', $concepto) ?? $concepto;

            $totalCrudo += $monto;

            $motivo   = '';
            $incluido = true;

            if ($cat === 'Nómina y Capital Humano') {
                // Concept-level exclusion: only true payroll concepts are excluded.
                // Others (PAGO FINANCIAMIENTO MOTO, COMPRA DE CASCOS, GASTOS MEDICOS…)
                // are operational → included in gastos_lendus.
                if (in_array($concepto, self::NOMINA_SKIP_CONCEPTS, true)) {
                    $motivo   = "EXCLUIDO: {$concepto} (nómina)";
                    $incluido = false;
                    $exclBuckets['Nómina y Capital Humano'] = ($exclBuckets['Nómina y Capital Humano'] ?? 0.0) + $monto;
                } else {
                    $totalIncluido += $monto;
                    $motivo = "INCLUIDO (operativo: {$concepto})";
                    $label  = $row->concept ?: $cat ?: 'Sin concepto';
                    $conceptoTotales[$label] = ($conceptoTotales[$label] ?? 0.0) + $monto;
                }
            } elseif (isset(self::EXCLUSION_MAP[$cat])) {
                $motivo   = self::EXCLUSION_MAP[$cat];
                $incluido = false;
                $exclBuckets[$cat] = ($exclBuckets[$cat] ?? 0.0) + $monto;
            } else {
                $totalIncluido += $monto;
                $motivo = 'INCLUIDO';
                $label  = $row->concept ?: $cat ?: 'Sin concepto';
                $conceptoTotales[$label] = ($conceptoTotales[$label] ?? 0.0) + $monto;
            }

            $detailRows[] = [
                'id'        => $row->id,
                'sucursal'  => (string) ($row->sucursal ?? '(sin sucursal)'),
                'categoria' => $cat,
                'concepto'  => (string) ($row->concept ?? ''),
                'obs'       => mb_substr((string) ($row->observations ?? ''), 0, 50),
                'fecha'     => $row->expense_date,
                'monto'     => $monto,
                'incluido'  => $incluido ? 'SÍ' : 'NO',
                'motivo'    => $motivo,
            ];
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN ════');
        $this->line(str_pad('Total crudo Lendus',                      44) . '$' . number_format($totalCrudo, 2));

        $exclLabels = [
            'Envío de utilidad a corporativo' => 'Excedentes',
            'Nómina y Capital Humano'          => 'Nómina payroll (NOMINA/IMSS/DEDUCCIONES/PAGO PRESTAMO Z)',
            'Préstamos Intersucursales'        => 'Fondeo a sucursal',
            'Pólizas'                          => 'Seguros/Coberturas (puente)',
            'Financiamiento Celular'           => 'Financiamiento Celular (nómina)',
            'Gasolina'                         => 'Gasolina (nómina)',
        ];
        foreach ($exclLabels as $cat => $label) {
            $excAmt = $exclBuckets[$cat] ?? 0.0;
            if ($excAmt > 0) {
                $this->line(str_pad("(-) Excluido {$label}", 44) . '$' . number_format($excAmt, 2));
            }
        }
        $this->line(str_repeat('─', 60));
        $this->info(str_pad('TOTAL INCLUIDO LENDUS',                   44) . '$' . number_format($totalIncluido, 2));

        $refLendus = 557_561.29;
        $diff      = $totalIncluido - $refLendus;
        $sign      = $diff >= 0 ? '+' : '';
        $match     = abs($diff) < 200 ? '✓ DENTRO DE RANGO' : (abs($diff) < 5_000 ? '≈ CERCANO' : '⚠ REVISAR');
        $this->line('');
        $this->line(str_pad('Referencia esperada',  44) . '$' . number_format($refLendus, 2));
        $this->line(str_pad('Diferencia',            44) . $sign . '$' . number_format($diff, 2) . '  ' . $match);

        // ── Por concepto (incluidos) ──────────────────────────────────────────
        $this->line('');
        $this->info('════ DESGLOSE POR CONCEPTO (incluidos) ════');
        arsort($conceptoTotales);
        $this->line(str_pad('Concepto', 45) . 'Monto');
        $this->line(str_repeat('─', 65));
        foreach ($conceptoTotales as $con => $amt) {
            $this->line(str_pad(mb_substr($con, 0, 43), 45) . '$' . number_format($amt, 2));
        }

        // ── Detalle ──────────────────────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE FILA A FILA ════');
            $this->line(
                str_pad('ID', 8) .
                str_pad('Sucursal', 22) .
                str_pad('Categoría', 28) .
                str_pad('Concepto', 28) .
                str_pad('Monto', 16) .
                str_pad('Inc', 5) .
                'Motivo'
            );
            $this->line(str_repeat('─', 140));
            foreach ($detailRows as $r) {
                $line = str_pad($r['id'], 8) .
                        str_pad(mb_substr($r['sucursal'], 0, 20), 22) .
                        str_pad(mb_substr($r['categoria'], 0, 26), 28) .
                        str_pad(mb_substr($r['concepto'], 0, 26), 28) .
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
        $file = "gastos_lendus_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $lines   = [];
        $headers = ['ID', 'Sucursal', 'Categoría', 'Concepto', 'Observación', 'Fecha', 'Monto', 'Incluido', 'Motivo'];
        $lines[] = implode(',', $headers);

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                $r['id'],
                '"' . str_replace('"', '""', $r['sucursal']) . '"',
                '"' . str_replace('"', '""', $r['categoria']) . '"',
                '"' . str_replace('"', '""', $r['concepto']) . '"',
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
