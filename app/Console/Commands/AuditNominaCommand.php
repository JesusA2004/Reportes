<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditNominaCommand extends Command
{
    protected $signature = 'reportes:audit-nomina
                                {period_id}
                                {--detail : mostrar el detalle por código/concepto de cada fuente}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Nómina y Capital Humano: concepto por concepto, NOI fiscal/no fiscal + Lendus + ERP, vs target validado';

    // Cada fila: [label, fuente, codigos_noi_percepcion, codigos_noi_deduccion, gasto_categoria, gasto_concepto_like, target]
    // gasto_concepto_like: null = toda la categoría; array = concept debe contener alguno de estos substrings (UPPER)
    private const CONCEPTOS = [
        ['Nómina',                                   'NOI',         ['P001'], ['D111' => 1, 'D137' => -1], null, null, 1_075_509.70],
        ['Comisiones',                                'NOI',         ['P002', 'P119'], [], null, null, 716_885.13],
        ['Vacaciones',                                'NOI',         ['P009', 'P027', 'P113'], [], null, null, 20_988.43],
        ['Prima vacacional',                          'NOI',         ['P010'], [], null, null, 5_562.16],
        ['Bonos',                                     'NOI',         ['P108', 'P109', 'P110', 'P112', 'P114', 'P115', 'P120', 'P123', 'P124'], [], null, null, 291_655.85],
        ['Bonos Aceleradores',                        'NOI',         ['P118'], [], null, null, 0.0],
        ['IMSS',                                      'NOI',         [], ['D002' => 1, 'D009' => 1], null, null, 0.0],
        ['Descuentos Infonavit',                      'NOI',         [], ['D092' => 1, 'D094' => 1, 'D105' => 1, 'D129' => 1], null, null, 28_686.48],
        ['Descuento Servicios Moto',                  'NOI',         [], ['D113' => 1], null, null, 1_662.06],
        ['Financiamiento Celular',                    'NOI',         [], ['D125' => 1], null, null, 0.0],
        ['Descuento de uniformes',                    'NOI',         [], ['D112' => 1], null, null, 0.0],
        ['Descuento gastos sin comprobar',             'NOI',         [], ['D119' => 1], null, null, 0.0],
        ['Descuento extravío tarjeta de circulación',  'NOI',         [], ['D117' => 1], null, null, 0.0],
        ['Descuentos Tienda Mr Lana',                  'NOI',         [], ['D122' => 1, 'D126' => 1, 'D127' => 1], null, null, 0.0],
        ['Descuento Servicios Automóvil',              'NOI',         [], ['D114' => 1], null, null, 0.0],
        ['Descuento faltante en caja',                 'NOI',         [], ['D118' => 1], null, null, 0.0],
        ['Anticipo de nómina',                         'NOI',         [], ['D003' => 1], null, null, 0.0],
        ['Formatería',                                 'GASTO',       [], [], null, ['FORMATER'], 0.0],
        ['Finiquito',                                  'LENDUS',      [], [], 'Nómina y Capital Humano', ['FINIQUITO'], 22_920.75],
        ['Gastos médicos',                             'LENDUS',      [], [], 'Nómina y Capital Humano', ['MEDICO', 'MÉDICO', 'MEDIC'], 3_561.00],
        ['Gasolina',                                   'ERP',         [], [], 'Gasolina', null, 159_600.00],
        ['Financiamiento De Motos',                    'MIXTO',       [], ['D123' => 1, 'D128' => 1], null, ['FINANCIAMIENTO MOTO', 'MOTO', 'MOTOCICLETA'], 154_624.37],
        ['Cascos',                                     'LENDUS_XLS',  [], [], null, ['CASCOS', 'COMPRA DE CASCOS', 'CASCO'], 19_933.50],
        ['Pensión Alimenticia',                        'NOI',         [], ['D010' => 1], null, null, 0.0],
        ['Préstamo Personal (no incluido en total)',   'NOI',         [], ['D004' => 1], null, null, 0.0],
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

        $noiFiscalId   = DB::table('data_sources')->where('code', 'noi_nomina_fiscal')->value('id');
        $noiNoFiscalId = DB::table('data_sources')->where('code', 'noi_nomina')->value('id');
        $erpId         = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');
        $lendusId      = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        $lendusXlsId   = DB::table('data_sources')->where('code', 'gastos_lendus_excel')->value('id');

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA NÓMINA Y CAPITAL HUMANO — {$period->label} (ID {$period->id})");
        $this->info('  NOI (fiscal/no fiscal) + Lendus PDF + Lendus Excel + ERP, concepto por concepto');
        $this->info('════════════════════════════════════════════════════════════════════════════');

        $rows         = [];
        $grandTotal   = 0.0;
        $grandTarget  = 0.0;

        foreach (self::CONCEPTOS as [$label, $fuente, $percCodes, $dedCodes, $gastoCat, $gastoConceptLike, $target]) {
            $noiFiscal   = $this->sumNoi($dataIds, $noiFiscalId, $percCodes, $dedCodes);
            $noiNoFiscal = $this->sumNoi($dataIds, $noiNoFiscalId, $percCodes, $dedCodes);

            $lendusAmt = 0.0;
            $erpAmt    = 0.0;
            $xlsAmt    = 0.0;

            if ($gastoCat !== null || $gastoConceptLike !== null) {
                if (in_array($fuente, ['LENDUS', 'MIXTO', 'GASTO'], true)) {
                    $lendusAmt = $this->sumGasto($dataIds, $lendusId, $gastoCat, $gastoConceptLike);
                }
                if (in_array($fuente, ['ERP', 'MIXTO', 'GASTO'], true)) {
                    $erpAmt = $this->sumGasto($dataIds, $erpId, $gastoCat, $gastoConceptLike);
                }
                if (in_array($fuente, ['LENDUS_XLS', 'MIXTO', 'GASTO'], true)) {
                    $xlsAmt = $this->sumGasto($dataIds, $lendusXlsId, $gastoCat, $gastoConceptLike, excludeConcepts: ['PAGO FINANCIAMIENTO CELULAR']);
                }
            }

            $isExcludedFromTotal = str_contains($label, 'no incluido en total') || $label === 'Pensión Alimenticia';
            $total = $noiFiscal + $noiNoFiscal + $lendusAmt + $erpAmt + $xlsAmt;
            $diff  = $total - $target;

            $estado = match (true) {
                $isExcludedFromTotal && $total > 0 => 'Detectado pero no incluido',
                abs($diff) < 1.0                    => 'OK',
                $target > 0 && $total == 0.0         => 'Falta en NOI/Lendus/Gastos',
                $total > 0 && $total < $target        => 'Viene parcial',
                default                               => 'Diferencia',
            };

            if (!$isExcludedFromTotal) {
                $grandTotal  += $total;
                $grandTarget += $target;
            }

            $rows[] = [
                'label'        => $label,
                'fuente'       => $fuente,
                'codigos'      => trim(implode('+', $percCodes) . ' ' . implode('+', array_keys($dedCodes))),
                'noi_fiscal'   => $noiFiscal,
                'noi_no_fiscal'=> $noiNoFiscal,
                'lendus'       => $lendusAmt + $xlsAmt,
                'erp'          => $erpAmt,
                'total'        => $total,
                'target'       => $target,
                'diff'         => $diff,
                'estado'       => $estado,
            ];
        }

        $this->line('');
        $this->info('════ DESGLOSE POR CONCEPTO ════');
        $this->line(
            str_pad('Concepto', 28) . str_pad('Fuente', 11) . str_pad('Códigos', 16) .
            str_pad('NOI Fiscal', 13) . str_pad('NOI NoFiscal', 13) . str_pad('Lendus', 13) .
            str_pad('ERP', 11) . str_pad('Total', 13) . str_pad('Target', 13) .
            str_pad('Diff', 11) . 'Estado'
        );
        $this->line(str_repeat('─', 175));

        foreach ($rows as $r) {
            $line =
                str_pad(mb_substr($r['label'], 0, 26), 28) .
                str_pad($r['fuente'], 11) .
                str_pad(mb_substr($r['codigos'], 0, 14), 16) .
                str_pad(number_format($r['noi_fiscal'], 2), 13) .
                str_pad(number_format($r['noi_no_fiscal'], 2), 13) .
                str_pad(number_format($r['lendus'], 2), 13) .
                str_pad(number_format($r['erp'], 2), 11) .
                str_pad(number_format($r['total'], 2), 13) .
                str_pad(number_format($r['target'], 2), 13) .
                str_pad(number_format($r['diff'], 2), 11) .
                $r['estado'];

            match ($r['estado']) {
                'OK' => $this->info($line),
                'Detectado pero no incluido' => $this->comment($line),
                default => abs($r['diff']) < 1000 ? $this->warn($line) : $this->error($line),
            };
        }

        $this->line(str_repeat('─', 175));
        $diffTotal = $grandTotal - $grandTarget;
        $this->line('');
        $this->info(str_pad('TOTAL NÓMINA Y CAPITAL HUMANO', 60) . '$' . number_format($grandTotal, 2));
        $this->line(str_pad('Target', 60) . '$' . number_format($grandTarget, 2));
        $match = abs($diffTotal) < 1 ? '✓ EXACTO' : (abs($diffTotal) < 500 ? '≈ CERCANO' : '⚠ REVISAR');
        $this->line(str_pad('Diferencia', 60) . ($diffTotal >= 0 ? '+' : '') . '$' . number_format($diffTotal, 2) . '  ' . $match);

        if ($this->option('export')) {
            $this->exportCsv($period->id, $rows);
        }

        return 0;
    }

    private function sumNoi(array $dataIds, ?int $sourceId, array $percCodes, array $dedCodesWithSign): float
    {
        if (!$sourceId) {
            return 0.0;
        }

        $total = 0.0;

        if (!empty($percCodes)) {
            $pattern = '^(' . implode('|', $percCodes) . ')';
            $total += (float) DB::table('fact_noi_movements as n')
                ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
                ->where('ru.data_source_id', $sourceId)
                ->whereIn('n.period_id', $dataIds)
                ->where('n.concept_type', 'percepcion')
                ->whereRaw('n.concept REGEXP ?', [$pattern])
                ->sum('n.amount');
        }

        foreach ($dedCodesWithSign as $code => $sign) {
            $amt = (float) DB::table('fact_noi_movements as n')
                ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
                ->where('ru.data_source_id', $sourceId)
                ->whereIn('n.period_id', $dataIds)
                ->where('n.concept_type', 'deduccion')
                ->whereRaw('n.concept REGEXP ?', ['^' . $code])
                ->sum('n.amount');
            $total += $amt * $sign;
        }

        return $total;
    }

    private function sumGasto(array $dataIds, ?int $sourceId, ?string $category, ?array $conceptLike, array $excludeConcepts = []): float
    {
        if (!$sourceId) {
            return 0.0;
        }

        $q = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->where('ru.data_source_id', $sourceId)
            ->whereIn('e.period_id', $dataIds);

        if ($category !== null) {
            $q->where('e.category', $category);
        }

        if ($conceptLike !== null) {
            $q->where(function ($q2) use ($conceptLike) {
                foreach ($conceptLike as $needle) {
                    $q2->orWhereRaw("UPPER(COALESCE(e.concept,'')) LIKE ?", ['%' . strtoupper($needle) . '%']);
                }
            });
        }

        if (!empty($excludeConcepts)) {
            $q->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), $excludeConcepts);
        }

        return (float) $q->selectRaw('SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as t')->value('t');
    }

    private function exportCsv(int $periodId, array $rows): void
    {
        $dir  = 'auditorias';
        $file = "nomina_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";

        $lines   = [];
        $headers = ['Concepto', 'Fuente', 'Códigos', 'NOI Fiscal', 'NOI No Fiscal', 'Lendus', 'ERP', 'Total', 'Target', 'Diferencia', 'Estado'];
        $lines[] = implode(',', $headers);

        foreach ($rows as $r) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $r['label']) . '"',
                $r['fuente'],
                '"' . str_replace('"', '""', $r['codigos']) . '"',
                number_format($r['noi_fiscal'], 2, '.', ''),
                number_format($r['noi_no_fiscal'], 2, '.', ''),
                number_format($r['lendus'], 2, '.', ''),
                number_format($r['erp'], 2, '.', ''),
                number_format($r['total'], 2, '.', ''),
                number_format($r['target'], 2, '.', ''),
                number_format($r['diff'], 2, '.', ''),
                $r['estado'],
            ]);
        }

        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
