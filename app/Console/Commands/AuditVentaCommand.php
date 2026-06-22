<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditVentaCommand extends Command
{
    protected $signature = 'reportes:audit-venta
                                {period_id}
                                {--detail : mostrar desglose fila a fila de componentes de venta}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Venta real: Intereses + Moratorios + Comisión apertura (excluye Capital, Impuestos, Seguros, Unificación, Condonaciones)';

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
        $this->info("  AUDITORÍA VENTA REAL — {$period->label} (ID {$period->id})");
        $this->info('  Venta = Intereses + Moratorios + Comisión apertura');
        $this->info('  Excluye: Capital, Impuestos, Seguros, Unificación, Condonaciones');
        $this->info('════════════════════════════════════════════════════════════');

        // Recuperación filtrada (misma regla que BranchRadiographyCalculator)
        $totales = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->selectRaw("
                SUM(CASE
                    WHEN is_savehearts = 1 THEN COALESCE(savehearts_crece_share, 0)
                    WHEN is_savehearts = 0 AND (
                        UPPER(COALESCE(concept,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(operation,'')) LIKE '%COBERTURA%'
                        OR UPPER(COALESCE(concept,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(operation,'')) LIKE '%SEGURO%'
                        OR UPPER(COALESCE(concept,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(operation,'')) LIKE '%UNIFICACION%'
                        OR UPPER(COALESCE(concept,'')) LIKE '%CONDONACION%'
                        OR UPPER(COALESCE(operation,'')) LIKE '%CONDONACION%'
                    ) THEN 0
                    ELSE total_amount
                END) as recuperacion_filtrada,
                SUM(total_amount) as recuperacion_bruta,
                SUM(capital) as capital_total,
                SUM(interest) as interes_total,
                SUM(tax) as impuesto_total,
                SUM(charges_due) as cargos_total,
                SUM(CASE WHEN is_savehearts = 1 AND savehearts_crece_share > 0 THEN COALESCE(savehearts_crece_share, 0) ELSE 0 END) as crece_reconocido,
                SUM(CASE WHEN is_savehearts = 0 AND (UPPER(COALESCE(concept,'')) LIKE '%UNIFICACION%' OR UPPER(COALESCE(operation,'')) LIKE '%UNIFICACION%') THEN total_amount ELSE 0 END) as unificacion_excluida,
                SUM(CASE WHEN is_savehearts = 0 AND (UPPER(COALESCE(concept,'')) LIKE '%CONDONACION%' OR UPPER(COALESCE(operation,'')) LIKE '%CONDONACION%') THEN total_amount ELSE 0 END) as condonacion_excluida,
                SUM(CASE WHEN is_savehearts = 1 AND COALESCE(savehearts_crece_share, 0) = 0 THEN total_amount ELSE 0 END) as seguros_puente
            ")
            ->first();

        $recuperacionFiltrada = (float) ($totales->recuperacion_filtrada ?? 0);
        $recuperacionBruta    = (float) ($totales->recuperacion_bruta ?? 0);
        $capitalTotal         = (float) ($totales->capital_total ?? 0);
        $interesTotal         = (float) ($totales->interes_total ?? 0);
        $impuestoTotal        = (float) ($totales->impuesto_total ?? 0);
        $cargosTotal          = (float) ($totales->cargos_total ?? 0);
        $creceReconocido      = (float) ($totales->crece_reconocido ?? 0);
        $unificacionExcluida  = (float) ($totales->unificacion_excluida ?? 0);
        $condonacionExcluida  = (float) ($totales->condonacion_excluida ?? 0);
        $segurosPuente        = (float) ($totales->seguros_puente ?? 0);

        // Venta = recuperacion_filtrada - capital - impuesto
        $ventaCalculada = max(0.0, $recuperacionFiltrada - $capitalTotal - $impuestoTotal);

        $this->line('');
        $this->info('════ DESGLOSE RECUPERACIÓN / INGRESOS ════');
        $this->line('');
        $this->line(str_pad('Recuperación bruta (total cobrado):',         46) . '$' . number_format($recuperacionBruta, 2));
        $this->line(str_pad('(-) Seguros puente (Savehearts/Comadres):',   46) . '-$' . number_format($segurosPuente, 2));
        $this->line(str_pad('(-) Unificación cartera excluida:',           46) . '-$' . number_format($unificacionExcluida, 2));
        $this->line(str_pad('(-) Condonaciones excluidas:',                46) . '-$' . number_format($condonacionExcluida, 2));
        $this->line(str_pad('(+) CRECE 30% reconocido:',                   46) . '+$' . number_format($creceReconocido, 2));
        $this->line(str_repeat('─', 62));
        $this->info(str_pad('Recuperación final (filtrada):',              46) . '$' . number_format($recuperacionFiltrada, 2));
        $this->line('');
        $this->info('════ CÁLCULO DE VENTA (base Margen EBITDA) ════');
        $this->line('');
        $this->line(str_pad('Recuperación filtrada:',                      46) . '$' . number_format($recuperacionFiltrada, 2));
        $this->line(str_pad('(-) Capital recuperado:',                     46) . '-$' . number_format($capitalTotal, 2));
        $this->line(str_pad('(-) Impuesto/IVA recuperado:',                46) . '-$' . number_format($impuestoTotal, 2));
        $this->line(str_repeat('─', 62));
        $this->info(str_pad('VENTA REAL (para Margen EBITDA):',            46) . '$' . number_format($ventaCalculada, 2));
        $this->line('');
        $this->line(str_pad('Desglose componentes:',                       46));
        $this->line(str_pad('  Intereses recuperados:',                    46) . '$' . number_format($interesTotal, 2));
        $this->line(str_pad('  Moratorios / cargos (charges_due):',        46) . '$' . number_format($cargosTotal, 2));
        $this->line(str_pad('  Verificar: Interés + Cargos =',             46) . '$' . number_format($interesTotal + $cargosTotal, 2));

        // Detalle por operación
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DESGLOSE POR TIPO DE OPERACIÓN ════');

            $byOp = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->selectRaw("
                    COALESCE(operation, 'SIN OPERACIÓN') as operacion,
                    COUNT(*) as registros,
                    SUM(total_amount) as total_bruto,
                    SUM(capital) as capital,
                    SUM(interest) as interes,
                    SUM(tax) as impuesto,
                    SUM(charges_due) as cargos
                ")
                ->groupBy('operacion')
                ->orderByDesc('total_bruto')
                ->get();

            $this->line(
                str_pad('Operación', 34) .
                str_pad('Registros', 12) .
                str_pad('Total bruto', 18) .
                str_pad('Capital', 18) .
                str_pad('Interés', 18) .
                str_pad('Impuesto', 14) .
                'Cargos'
            );
            $this->line(str_repeat('─', 130));

            $detailRows = [];
            foreach ($byOp as $row) {
                $this->line(
                    str_pad(mb_substr((string)$row->operacion, 0, 32), 34) .
                    str_pad((int)$row->registros, 12) .
                    str_pad('$' . number_format((float)$row->total_bruto, 2), 18) .
                    str_pad('$' . number_format((float)$row->capital, 2), 18) .
                    str_pad('$' . number_format((float)$row->interes, 2), 18) .
                    str_pad('$' . number_format((float)$row->impuesto, 2), 14) .
                    '$' . number_format((float)$row->cargos, 2)
                );
                $detailRows[] = [
                    'operacion' => (string)$row->operacion,
                    'registros' => (int)$row->registros,
                    'total_bruto' => (float)$row->total_bruto,
                    'capital' => (float)$row->capital,
                    'interes' => (float)$row->interes,
                    'impuesto' => (float)$row->impuesto,
                    'cargos' => (float)$row->cargos,
                ];
            }

            if ($this->option('export')) {
                $this->exportCsv($period->id, $detailRows);
            }
        } elseif ($this->option('export')) {
            $this->exportCsv($period->id, []);
        }

        $this->line('');
        $this->info("Venta real para Margen EBITDA: \$" . number_format($ventaCalculada, 2));

        return 0;
    }

    private function exportCsv(int $periodId, array $rows): void
    {
        $dir  = 'auditorias';
        $file = "venta_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $lines   = [];
        $headers = ['Operación', 'Registros', 'Total Bruto', 'Capital', 'Interés', 'Impuesto', 'Cargos'];
        $lines[] = implode(',', $headers);

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['operacion']) . '"',
                $r['registros'],
                number_format($r['total_bruto'], 2, '.', ''),
                number_format($r['capital'], 2, '.', ''),
                number_format($r['interes'], 2, '.', ''),
                number_format($r['impuesto'], 2, '.', ''),
                number_format($r['cargos'], 2, '.', ''),
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
