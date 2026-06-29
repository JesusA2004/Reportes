<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditGastosCommand extends Command
{
    protected $signature = 'reportes:audit-gastos
                                {period_id}
                                {--detail : mostrar cada fila con su clasificación}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría combinada Gastos ERP + Lendus: desglose trazable de OPEX, Nómina, Fondeo, Excedentes y Seguros';

    // Categorías Lendus excluidas de OPEX
    private const LENDUS_EXCLUSION_MAP = [
        'Envío de utilidad a corporativo' => ['clasificacion' => 'Envío a corporativo / excedente', 'motivo' => 'Excluido: Excedentes / envío a corporativo'],
        'Préstamos Intersucursales'        => ['clasificacion' => 'Fondeo entre sucursales',          'motivo' => 'Excluido: Fondeo entre sucursales (rastreo)'],
        'Pólizas'                          => ['clasificacion' => 'Seguro / cobertura puente',         'motivo' => 'Excluido: Seguros/Coberturas puente'],
        'Financiamiento Celular'           => ['clasificacion' => 'Nómina y Capital Humano',           'motivo' => 'Reclasificado a Nómina: Financiamiento celular'],
        'Gasolina'                         => ['clasificacion' => 'Nómina y Capital Humano',           'motivo' => 'Reclasificado a Nómina: Gasolina'],
    ];

    private const LENDUS_NOMINA_SKIP = ['NOMINA', 'PAGO DE IMSS', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO PRESTAMO Z'];
    private const LENDUS_NOMINA_RECLASS = ['FINIQUITO', 'MEDICO', 'MÉDICO'];

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

        $erpSourceId    = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');
        $lendusSourceId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA GASTOS — {$period->label} (ID {$period->id})");
        $this->info('  ERP: filtro único Sucursal (excluye Corporativo/vacío)');
        $this->info('  Lendus: excluye Fondeo, Excedentes, Nómina payroll, Seguros');
        $this->info('════════════════════════════════════════════════════════════');

        $rows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->where(function ($q) use ($erpSourceId, $lendusSourceId) {
                $ids = array_filter([$erpSourceId, $lendusSourceId]);
                if (!empty($ids)) {
                    $q->whereIn('ru.data_source_id', $ids);
                }
            })
            ->select(
                'e.id',
                'ds.code as fuente',
                'ru.original_name as archivo',
                'b.name as sucursal',
                'e.branch_id',
                'e.category',
                'e.concept',
                'e.observations',
                'e.expense_date',
                'e.amount',
                'e.paid_amount',
                'e.raw_payload',
            )
            ->orderBy('ds.code')
            ->orderBy('b.name')
            ->orderByDesc('e.amount')
            ->get();

        $totals = [
            'erp_total'     => 0.0,
            'erp_nomina'    => 0.0,
            'erp_opex'      => 0.0,
            'lendus_total'  => 0.0,
            'lendus_fondeo' => 0.0,
            'lendus_excedentes' => 0.0,
            'lendus_nomina' => 0.0,
            'lendus_seguros'=> 0.0,
            'lendus_opex'   => 0.0,
        ];

        $detailRows = [];

        foreach ($rows as $row) {
            $monto     = (float) ($row->paid_amount ?: $row->amount);
            $fuente    = (string) ($row->fuente ?? '');
            $cat       = (string) ($row->category ?? '');
            $concepto  = strtoupper(trim((string) ($row->concept ?? '')));
            $concepto  = (string) preg_replace('/\s+/u', ' ', $concepto);
            $payload   = is_string($row->raw_payload) ? (json_decode($row->raw_payload, true) ?? []) : (array)($row->raw_payload ?? []);

            $folio       = (string) ($payload['folio'] ?? '');
            $solicitante = (string) ($payload['solicitante'] ?? $payload['proveedor'] ?? '');
            $justificacion = (string) ($payload['justification'] ?? '');
            $sucOrigen   = (string) ($row->sucursal ?? '');
            $sucDestino  = '';

            $clasificacion = 'Sin asignar';
            $motivo        = '';
            $estado        = 'OK';

            if ($fuente === 'gastos_erp') {
                $totals['erp_total'] += $monto;
                $isCorporativo = empty($row->branch_id) || in_array(
                    strtolower(trim($sucOrigen)),
                    ['corporativo', 'corp', '']
                );

                if ($isCorporativo) {
                    $clasificacion = 'Excluido';
                    $motivo        = 'ERP: Sucursal Corporativo o vacía';
                    $estado        = 'EXCLUIDO';
                } elseif (in_array($cat, ['Gasolina', 'Financiamiento Celular'], true)) {
                    // Reclassified to Nómina by NOMINA_EXPENSE_CATS (same rule as BranchRadiographyCalculator)
                    $clasificacion = 'Nómina y Capital Humano';
                    $motivo        = "ERP: Reclasificado a Nómina ({$cat})";
                    $estado        = 'NOMINA';
                    $totals['erp_nomina'] += $monto;
                } else {
                    $clasificacion = 'OPEX ERP';
                    $motivo        = "ERP: Sucursal operativa ({$sucOrigen}, {$cat})";
                    $totals['erp_opex'] += $monto;
                }
            } elseif ($fuente === 'gastos_lendus') {
                $totals['lendus_total'] += $monto;

                // Sucursal destino (fondeos)
                $sucDestino = (string) ($payload['fondeo_destino_sucursal'] ?? $payload['branch_to_detected'] ?? '');
                if ($sucDestino === 'No detectado' || $sucDestino === 'null') {
                    $sucDestino = '';
                }

                if ($cat === 'Nómina y Capital Humano') {
                    if (in_array($concepto, self::LENDUS_NOMINA_SKIP, true)) {
                        $clasificacion = 'Nómina y Capital Humano';
                        $motivo        = "Lendus: nómina payroll excluida ({$concepto})";
                        $estado        = 'EXCLUIDO-NOMINA';
                        $totals['lendus_nomina'] += $monto;
                    } elseif ($this->isNominaReclass($concepto)) {
                        $clasificacion = 'Nómina y Capital Humano';
                        $motivo        = "Lendus: reclasificado a Nómina ({$concepto})";
                        $estado        = 'RECLASIFICADO';
                        $totals['lendus_nomina'] += $monto;
                    } else {
                        $clasificacion = 'OPEX Lendus';
                        $motivo        = "Lendus: operativo en Nómina y Capital Humano ({$concepto})";
                        $totals['lendus_opex'] += $monto;
                    }
                } elseif (isset(self::LENDUS_EXCLUSION_MAP[$cat])) {
                    $info          = self::LENDUS_EXCLUSION_MAP[$cat];
                    $clasificacion = $info['clasificacion'];
                    $motivo        = $info['motivo'];
                    $estado        = 'EXCLUIDO';

                    match ($cat) {
                        'Préstamos Intersucursales'        => ($totals['lendus_fondeo']     += $monto),
                        'Envío de utilidad a corporativo'  => ($totals['lendus_excedentes'] += $monto),
                        'Pólizas'                          => ($totals['lendus_seguros']    += $monto),
                        default                            => ($totals['lendus_nomina']     += $monto),
                    };
                } else {
                    $clasificacion = 'OPEX Lendus';
                    $motivo        = 'Lendus: categoría operativa';
                    $totals['lendus_opex'] += $monto;
                }
            } else {
                $clasificacion = 'Sin asignar';
                $motivo        = "Fuente desconocida: {$fuente}";
                $estado        = 'SIN ASIGNAR';
            }

            $detailRows[] = [
                'id'            => $row->id,
                'fuente'        => $fuente,
                'archivo'       => mb_substr((string) ($row->archivo ?? ''), 0, 40),
                'folio'         => $folio,
                'fecha'         => $row->expense_date ?? '',
                'empleado'      => mb_substr($solicitante, 0, 40),
                'suc_origen'    => $sucOrigen,
                'suc_destino'   => $sucDestino,
                'categoria'     => $cat,
                'concepto'      => (string) ($row->concept ?? ''),
                'observacion'   => mb_substr((string) ($row->observations ?? ''), 0, 60),
                'justificacion' => mb_substr($justificacion, 0, 60),
                'monto_orig'    => $monto,
                'monto_usado'   => in_array($estado, ['EXCLUIDO', 'EXCLUIDO-NOMINA']) ? 0.0 : $monto,
                'clasificacion' => $clasificacion,
                'destino_final' => $clasificacion,
                'motivo'        => $motivo,
                'estado'        => $estado,
            ];
        }

        $totals['erp_excluido'] = $totals['erp_total'] - $totals['erp_opex'] - $totals['erp_nomina'];
        $opexTotal = $totals['erp_opex'] + $totals['lendus_opex'];

        // ── Resumen ──────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESUMEN ERP ════');
        $this->line(str_pad('ERP total cargado',                         48) . '$' . number_format($totals['erp_total'], 2));
        $this->line(str_pad('(-) ERP excluido (Corporativo / vacío)',    48) . '$' . number_format($totals['erp_excluido'], 2));
        $this->line(str_pad('(-) ERP reclasificado a Nómina (Gasolina)', 48) . '$' . number_format($totals['erp_nomina'], 2));
        $this->line(str_repeat('─', 64));
        $this->info(str_pad('(=) ERP final OPEX',                        48) . '$' . number_format($totals['erp_opex'], 2));

        $this->line('');
        $this->info('════ RESUMEN LENDUS ════');
        $this->line(str_pad('Lendus total cargado (PDF)',                    48) . '$' . number_format($totals['lendus_total'], 2));
        $this->line(str_pad('(-) Excluido: fondeos entre sucursales',        48) . '$' . number_format($totals['lendus_fondeo'], 2));
        $this->line(str_pad('(-) Excluido: excedentes / corporativo',        48) . '$' . number_format($totals['lendus_excedentes'], 2));
        $this->line(str_pad('(-) Reclasificado a Nómina',                    48) . '$' . number_format($totals['lendus_nomina'], 2));
        $this->line(str_pad('(-) Excluido: pólizas / seguros puente',        48) . '$' . number_format($totals['lendus_seguros'], 2));
        $this->line(str_repeat('─', 64));
        $this->info(str_pad('(=) Lendus final OPEX',                         48) . '$' . number_format($totals['lendus_opex'], 2));

        $this->line('');
        $this->info('════ OPEX TOTAL ════');
        $this->line(str_pad('ERP final OPEX',     36) . '$' . number_format($totals['erp_opex'], 2));
        $this->line(str_pad('Lendus final OPEX',  36) . '$' . number_format($totals['lendus_opex'], 2));
        $this->line(str_repeat('─', 52));
        $this->info(str_pad('OPEX TOTAL',         36) . '$' . number_format($opexTotal, 2));

        // ── Detalle ──────────────────────────────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE FILA A FILA ════');
            $this->line(
                str_pad('ID', 8) .
                str_pad('Fuente', 14) .
                str_pad('Sucursal', 20) .
                str_pad('Categoría', 28) .
                str_pad('Monto', 16) .
                str_pad('Clasificación', 30) .
                'Motivo'
            );
            $this->line(str_repeat('─', 160));
            foreach ($detailRows as $r) {
                $line = str_pad($r['id'], 8) .
                        str_pad(mb_substr($r['fuente'], 0, 12), 14) .
                        str_pad(mb_substr($r['suc_origen'], 0, 18), 20) .
                        str_pad(mb_substr($r['categoria'], 0, 26), 28) .
                        str_pad('$' . number_format($r['monto_orig'], 2), 16) .
                        str_pad(mb_substr($r['clasificacion'], 0, 28), 30) .
                        mb_substr($r['motivo'], 0, 50);

                match (true) {
                    str_starts_with($r['clasificacion'], 'OPEX') => $this->line($line),
                    $r['clasificacion'] === 'Nómina y Capital Humano' => $this->comment($line),
                    default => $this->warn($line),
                };
            }
        }

        // ── Export CSV ────────────────────────────────────────────────────────
        if ($this->option('export')) {
            $this->exportCsv($period->id, $detailRows, $totals, $opexTotal);
        }

        return 0;
    }

    private function isNominaReclass(string $conceptoUpper): bool
    {
        foreach (self::LENDUS_NOMINA_RECLASS as $keyword) {
            if (str_contains($conceptoUpper, mb_strtoupper($keyword))) {
                return true;
            }
        }
        return false;
    }

    private function exportCsv(int $periodId, array $rows, array $totals, float $opexTotal): void
    {
        $dir  = 'auditorias';
        $file = "gastos_combinado_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $headers = [
            'Fuente', 'Archivo origen', 'Registro ID', 'Folio', 'Fecha pago',
            'Empleado / responsable', 'Sucursal origen', 'Sucursal destino',
            'Concepto', 'Categoría', 'Observación', 'Justificación',
            'Monto original', 'Monto usado', 'Clasificación final',
            'Destino final', 'Motivo', 'Estado',
        ];
        $lines = [implode(',', $headers)];

        foreach ($rows as $r) {
            $csv = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';
            $lines[] = implode(',', [
                $csv($r['fuente']),
                $csv($r['archivo']),
                $r['id'],
                $csv($r['folio']),
                $r['fecha'],
                $csv($r['empleado']),
                $csv($r['suc_origen']),
                $csv($r['suc_destino']),
                $csv($r['concepto']),
                $csv($r['categoria']),
                $csv($r['observacion']),
                $csv($r['justificacion']),
                number_format($r['monto_orig'], 2, '.', ''),
                number_format($r['monto_usado'], 2, '.', ''),
                $csv($r['clasificacion']),
                $csv($r['destino_final']),
                $csv($r['motivo']),
                $csv($r['estado']),
            ]);
        }

        // Append summary
        $lines[] = '';
        $lines[] = '"=== RESUMEN ==="';
        $lines[] = '"ERP total cargado",' . number_format($totals['erp_total'], 2, '.', '');
        $lines[] = '"ERP excluido (Corporativo)",' . number_format($totals['erp_total'] - $totals['erp_opex'], 2, '.', '');
        $lines[] = '"ERP final OPEX",' . number_format($totals['erp_opex'], 2, '.', '');
        $lines[] = '"Lendus total cargado",' . number_format($totals['lendus_total'], 2, '.', '');
        $lines[] = '"Lendus excluido fondeos",' . number_format($totals['lendus_fondeo'], 2, '.', '');
        $lines[] = '"Lendus excluido excedentes",' . number_format($totals['lendus_excedentes'], 2, '.', '');
        $lines[] = '"Lendus reclasificado Nómina",' . number_format($totals['lendus_nomina'], 2, '.', '');
        $lines[] = '"Lendus excluido seguros",' . number_format($totals['lendus_seguros'], 2, '.', '');
        $lines[] = '"Lendus final OPEX",' . number_format($totals['lendus_opex'], 2, '.', '');
        $lines[] = '"OPEX TOTAL",' . number_format($opexTotal, 2, '.', '');

        Storage::disk('local')->makedirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
