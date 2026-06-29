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

    // Categories excluded entirely from OPEX (assigned to a named bucket)
    private const EXCLUSION_MAP = [
        'Envío de utilidad a corporativo' => ['bucket' => 'excedentes',  'motivo' => 'EXCLUIDO: Excedentes / envío a corporativo'],
        'Préstamos Intersucursales'        => ['bucket' => 'fondeo',      'motivo' => 'EXCLUIDO: Fondeo entre sucursales (rastreo)'],
        'Pólizas'                          => ['bucket' => 'polizas',     'motivo' => 'EXCLUIDO: Seguros/Coberturas puente'],
        'Financiamiento Celular'           => ['bucket' => 'nomina',      'motivo' => 'EXCLUIDO: Reclasificado a Nómina (financiamiento celular)'],
        'Gasolina'                         => ['bucket' => 'nomina',      'motivo' => 'EXCLUIDO: Reclasificado a Nómina (gasolina)'],
    ];

    // Within 'Nómina y Capital Humano': true payroll items excluded from OPEX
    private const NOMINA_SKIP_CONCEPTS = [
        'NOMINA', 'PAGO DE IMSS', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO PRESTAMO Z',
    ];

    // Within 'Nómina y Capital Humano': employee-related items reclassified to Nómina (not OPEX)
    private const NOMINA_RECLASS_KEYWORDS = ['FINIQUITO', 'MEDICO', 'MÉDICO'];

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

        $totalCrudo              = 0.0;
        $totalIncluido           = 0.0;
        $exclFondeo              = 0.0;
        $exclExcedentes          = 0.0;
        $exclNomina              = 0.0;   // true payroll: NOMINA/IMSS/DEDUCCIONES
        $exclNominaExpense       = 0.0;   // GASOLINA/FINANCIAMIENTO CELULAR category
        $reclasificadoNomina     = 0.0;   // FINIQUITO/MEDICO reclassified
        $exclPolizas             = 0.0;
        $conceptoTotales         = [];
        $detailRows              = [];

        foreach ($rows as $row) {
            $monto    = (float) ($row->paid_amount ?: $row->amount);
            $cat      = (string) ($row->category ?? '');
            // Normalize unicode whitespace (incl. non-breaking spaces U+00A0) before matching
            $conceptoRaw = (string) ($row->concept ?? '');
            $concepto    = strtoupper(trim($conceptoRaw));
            $concepto    = preg_replace('/\s+/u', ' ', $concepto) ?? $concepto;

            $totalCrudo += $monto;

            $motivo   = '';
            $incluido = true;
            $destino  = 'OPEX';

            if ($cat === 'Nómina y Capital Humano') {
                if (in_array($concepto, self::NOMINA_SKIP_CONCEPTS, true)) {
                    $exclNomina += $monto;
                    $motivo   = "EXCLUIDO: {$concepto} (nómina real payroll)";
                    $destino  = 'Excluido / Nómina payroll';
                    $incluido = false;
                } elseif ($this->isNominaReclass($concepto)) {
                    $reclasificadoNomina += $monto;
                    $motivo   = "RECLASIFICADO a Nómina: {$concepto}";
                    $destino  = 'Nómina y Capital Humano';
                    $incluido = false;
                } else {
                    $totalIncluido += $monto;
                    $motivo = "INCLUIDO en OPEX (operativo: {$concepto})";
                    $label  = $conceptoRaw ?: $cat ?: 'Sin concepto';
                    $conceptoTotales[$label] = ($conceptoTotales[$label] ?? 0.0) + $monto;
                }
            } elseif (isset(self::EXCLUSION_MAP[$cat])) {
                $info    = self::EXCLUSION_MAP[$cat];
                $motivo  = $info['motivo'];
                $incluido = false;
                $destino  = match($info['bucket']) {
                    'excedentes' => 'Excluido / Excedentes',
                    'fondeo'     => 'Excluido / Fondeo',
                    'polizas'    => 'Excluido / Pólizas',
                    'nomina'     => 'Nómina y Capital Humano',
                    default      => 'Excluido',
                };
                match($info['bucket']) {
                    'excedentes' => ($exclExcedentes += $monto),
                    'fondeo'     => ($exclFondeo     += $monto),
                    'polizas'    => ($exclPolizas    += $monto),
                    'nomina'     => ($exclNominaExpense += $monto),
                    default      => null,
                };
            } else {
                $totalIncluido += $monto;
                $motivo = 'INCLUIDO en OPEX';
                $label  = $conceptoRaw ?: $cat ?: 'Sin concepto';
                $conceptoTotales[$label] = ($conceptoTotales[$label] ?? 0.0) + $monto;
            }

            $detailRows[] = [
                'id'        => $row->id,
                'sucursal'  => (string) ($row->sucursal ?? '(sin sucursal)'),
                'categoria' => $cat,
                'concepto'  => $conceptoRaw,
                'obs'       => mb_substr((string) ($row->observations ?? ''), 0, 50),
                'fecha'     => $row->expense_date,
                'monto'     => $monto,
                'incluido'  => $incluido ? 'SÍ' : 'NO',
                'motivo'    => $motivo,
                'destino'   => $destino,
            ];
        }

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN — INTEGRACIÓN LENDUS ════');
        $this->line(str_pad('Lendus total cargado (PDF)',                      52) . '$' . number_format($totalCrudo, 2));
        $this->line(str_pad('(-) Excluido: fondeos entre sucursales',          52) . '$' . number_format($exclFondeo, 2));
        $this->line(str_pad('(-) Excluido: excedentes / envíos a corporativo', 52) . '$' . number_format($exclExcedentes, 2));
        $this->line(str_pad('(-) Excluido: nómina real (NOMINA/IMSS/etc.)',    52) . '$' . number_format($exclNomina, 2));
        $this->line(str_pad('(-) Reclasificado a Nómina (Gasolina/Celular)',   52) . '$' . number_format($exclNominaExpense, 2));
        $this->line(str_pad('(-) Reclasificado a Nómina (Finiquito/Médico)',   52) . '$' . number_format($reclasificadoNomina, 2));
        $this->line(str_pad('(-) Excluido: pólizas / seguros puente',          52) . '$' . number_format($exclPolizas, 2));
        $this->line(str_repeat('─', 68));
        $this->info(str_pad('(=) LENDUS FINAL OPEX',                           52) . '$' . number_format($totalIncluido, 2));

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
                str_pad('Concepto', 30) .
                str_pad('Monto', 16) .
                str_pad('Destino', 28) .
                'Motivo'
            );
            $this->line(str_repeat('─', 140));
            foreach ($detailRows as $r) {
                $line = str_pad($r['id'], 8) .
                        str_pad(mb_substr($r['sucursal'], 0, 20), 22) .
                        str_pad(mb_substr($r['concepto'], 0, 28), 30) .
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
        $file = "gastos_lendus_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $lines   = [];
        $headers = ['ID', 'Sucursal', 'Categoría', 'Concepto', 'Observación', 'Fecha', 'Monto', 'Destino', 'Motivo'];
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
                '"' . str_replace('"', '""', $r['destino']) . '"',
                '"' . str_replace('"', '""', $r['motivo']) . '"',
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }

    private function isNominaReclass(string $conceptoUpper): bool
    {
        foreach (self::NOMINA_RECLASS_KEYWORDS as $keyword) {
            if (str_contains($conceptoUpper, $keyword)) {
                return true;
            }
        }
        return false;
    }
}
