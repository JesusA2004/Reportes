<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditNominaPeriodoCommand extends Command
{
    protected $signature = 'reportes:audit-nomina-periodo
                                {period_id}
                                {--detail : Muestra el detalle por código/concepto}
                                {--export : Exporta CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría de Nómina y Capital Humano: valida uploads vigentes, detecta duplicados y explica cada peso incluido/excluido del total.';

    private const NOI_SOURCES = ['noi_nomina', 'noi_nomina_fiscal'];

    public function handle(BranchRadiographyCalculator $calc): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA NÓMINA Y CAPITAL HUMANO — {$period->label} (ID {$periodId})");
        $this->info('  dataIds: ' . implode(', ', $dataIds));
        $this->info('════════════════════════════════════════════════════════════');

        $ok = true;

        // ── 1. Uploads vigentes por fuente — cero duplicados, cero periodo incorrecto ──
        $this->line('');
        $this->info('════ 1. UPLOADS VIGENTES ════');
        $sourceCodes = ['noi_nomina', 'noi_nomina_fiscal', 'imss', 'gastos_erp', 'gastos_lendus', 'gastos_lendus_excel'];
        $vigentes = [];
        foreach ($sourceCodes as $code) {
            $uploads = ReportUpload::query()
                ->whereHas('dataSource', fn ($q) => $q->where('code', $code))
                ->where('period_id', $periodId)
                ->whereIn('status', ['processed', 'failed', 'processing'])
                ->orderByDesc('id')
                ->get();

            if ($uploads->isEmpty()) {
                $this->line("  [{$code}] sin upload en este periodo.");
                continue;
            }

            $vigente = $uploads->first();
            $vigentes[$code] = $vigente;
            $statusLabel = $vigente->status?->value ?? (string) $vigente->status;
            $this->line(sprintf(
                '  [%s] vigente=#%d (%s, status=%s)%s',
                $code, $vigente->id, $vigente->original_name, $statusLabel,
                $uploads->count() > 1 ? ' — ' . ($uploads->count() - 1) . ' upload(s) anterior(es) ignorado(s)' : '',
            ));
        }

        // ── 2. Auditoría por period_id / report_upload_id / data_source_id / concepto ──
        $this->line('');
        $this->info('════ 2. AGRUPADO POR UPLOAD (fact_noi_movements) ════');
        $byUpload = DB::table('fact_noi_movements as n')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->selectRaw("n.period_id, n.report_upload_id, ds.code, n.concept_type, SUM(n.amount) as total, COUNT(*) as cnt")
            ->groupBy('n.period_id', 'n.report_upload_id', 'ds.code', 'n.concept_type')
            ->orderBy('ds.code')
            ->get();

        $uploadsSeen = [];
        foreach ($byUpload as $row) {
            $this->line(sprintf(
                '  period=%d upload=#%d (%s) %s: $%s (%d filas)',
                $row->period_id, $row->report_upload_id, $row->code, $row->concept_type,
                number_format($row->total, 2), $row->cnt,
            ));
            if ($row->period_id !== $periodId) {
                $this->error("  ✗ PERIODO INCORRECTO: fila con period_id={$row->period_id}, se esperaba {$periodId}.");
                $ok = false;
            }
            $key = $row->code . ':' . $row->report_upload_id;
            $uploadsSeen[$row->code][$row->report_upload_id] = true;
        }
        foreach ($uploadsSeen as $code => $ids) {
            if (count($ids) > 1 && !in_array($code, ['gastos_lendus', 'gastos_lendus_excel'], true)) {
                $this->warn("  ⚠ [{$code}] tiene " . count($ids) . " uploads distintos contribuyendo en fact_noi_movements para este periodo — verificar que no sea duplicado.");
            }
        }

        // ── 3. Totales de control NOI normal / fiscal ─────────────────────────
        $this->line('');
        $this->info('════ 3. TOTALES DE CONTROL NOI ════');
        $controls = [];
        foreach (self::NOI_SOURCES as $code) {
            $upload = $vigentes[$code] ?? null;
            $perc = $ded = 0.0;
            if ($upload) {
                $perc = (float) DB::table('fact_noi_movements')->where('report_upload_id', $upload->id)->where('concept_type', 'percepcion')->sum('amount');
                $ded  = (float) DB::table('fact_noi_movements')->where('report_upload_id', $upload->id)->where('concept_type', 'deduccion')->sum('amount');
            }
            $controls[$code] = ['percepciones' => $perc, 'deducciones' => $ded];
            $this->line(sprintf('  [%s] Percepciones: $%s | Deducciones: $%s', $code, number_format($perc, 2), number_format($ded, 2)));
        }

        // ── 4. Clasificación exacta NOI (P001+D111-D137, comisiones, vacaciones, prima, bonos) ──
        $this->line('');
        $this->info('════ 4. CLASIFICACIÓN NOI (join corregido — sin duplicar por historial de asignaciones) ════');
        $branchResult = $calc->buildBranches($period, $dataIds);
        $branches     = $branchResult['branches'];
        $unassigned   = $branchResult['unassigned'];

        $sumField = function (string $field) use ($branches, $unassigned) {
            $total = (float) ($unassigned[$field] ?? 0);
            foreach ($branches as $b) {
                $total += (float) ($b[$field] ?? 0);
            }
            return $total;
        };

        $sueldos    = $sumField('nomina_total');
        $comisiones = $sumField('comisiones');
        $vacaciones = $sumField('vacaciones');
        $prima      = $sumField('prima_vacacional');
        $bonos      = $sumField('bonos') + $sumField('bonos_aceleradores');
        $subtotalNoi = $sueldos + $comisiones + $vacaciones + $prima + $bonos;

        $this->line(sprintf('  Sueldos (P001+D111-D137): $%s', number_format($sueldos, 2)));
        $this->line(sprintf('  Comisiones (P002+P119): $%s', number_format($comisiones, 2)));
        $this->line(sprintf('  Vacaciones (P009+P027+P113): $%s', number_format($vacaciones, 2)));
        $this->line(sprintf('  Prima vacacional (P010): $%s', number_format($prima, 2)));
        $this->line(sprintf('  Bonos (+ aceleradores): $%s', number_format($bonos, 2)));
        $this->info(sprintf('  SUBTOTAL CLASIFICADO NOI: $%s', number_format($subtotalNoi, 2)));

        // ── 5. Capital Humano adicional ────────────────────────────────────────
        $this->line('');
        $this->info('════ 5. CAPITAL HUMANO ADICIONAL (incluido) ════');
        $detailTotals = [];
        foreach ($branches as $b) {
            foreach (($b['nomina_detalle'] ?? []) as $k => $v) {
                $detailTotals[$k] = ($detailTotals[$k] ?? 0) + $v;
            }
        }
        foreach (($unassigned['nomina_detalle'] ?? []) as $k => $v) {
            $detailTotals[$k] = ($detailTotals[$k] ?? 0) + $v;
        }
        $capitalHumano = array_sum($detailTotals);
        foreach ($detailTotals as $label => $amt) {
            $this->line(sprintf('  %-30s $%s', $label, number_format($amt, 2)));
        }
        $this->info(sprintf('  SUBTOTAL CAPITAL HUMANO: $%s', number_format($capitalHumano, 2)));

        $total = $subtotalNoi + $capitalHumano;
        $this->line('');
        $this->info('════ TOTAL NÓMINA Y CAPITAL HUMANO ════');
        $this->info('  $' . number_format($total, 2));

        // ── 6. Excluidos (deben aparecer con monto $0 aportado) ────────────────
        $this->line('');
        $this->info('════ 6. EXCLUIDOS DEL TOTAL (auditoría — no deben sumar) ════');
        $lendusExcluded = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('ds.code', ['gastos_lendus', 'gastos_lendus_excel'])
            ->where('e.category', 'Nómina y Capital Humano')
            ->whereIn(DB::raw("UPPER(TRIM(COALESCE(e.concept,'')))"), ['NOMINA', 'DEDUCCIONES', 'DEDUCCIONES GENERALES', 'PAGO DE IMSS', 'PAGO PRESTAMO Z'])
            ->selectRaw("UPPER(TRIM(COALESCE(e.concept,''))) as concept, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('concept')
            ->get();
        foreach ($lendusExcluded as $row) {
            $reason = $row->concept === 'PAGO DE IMSS'
                ? 'Excluido: se usa el archivo oficial IMSS ($' . number_format((float) ($detailTotals['IMSS Patronal'] ?? 0), 2) . ')'
                : 'Excluido: la nómina oficial ya viene de NOI normal + NOI Fiscal';
            $this->line(sprintf('  Lendus "%s": $%s — %s [correctamente EXCLUIDO]', $row->concept, number_format($row->total, 2), $reason));
        }

        $dedNoSumadas = $unassigned['deducciones_no_sumadas'] ?? [];
        if (!empty($dedNoSumadas)) {
            $this->line('  Deducciones NOI detectadas pero NO sumadas como gasto (correctas):');
            foreach ($dedNoSumadas as $label => $amt) {
                $this->line(sprintf('    %-30s $%s', $label, number_format($amt, 2)));
            }
        }
        if (($unassigned['prestamo_personal_detectado'] ?? 0) > 0) {
            $this->line('  D004 Préstamo Personal (auditoría, no sumado): $' . number_format($unassigned['prestamo_personal_detectado'], 2));
        }
        if (($unassigned['pension_alimenticia_detectada'] ?? 0) > 0) {
            $this->line('  D010 Pensión Alimenticia (auditoría, no sumado): $' . number_format($unassigned['pension_alimenticia_detectada'], 2));
        }

        // ── 7. Duplicados de empleado NOI + NOI Fiscal por normalized_name ─────
        $this->line('');
        $this->info('════ 7. UNIFICACIÓN NOI + NOI FISCAL POR EMPLEADO ════');
        $dupCheck = DB::table('employee_branch_assignments as eba')
            ->join('employees as e', 'eba.employee_id', '=', 'e.id')
            ->where('eba.period_id', $periodId)
            ->whereNotNull('e.normalized_name')
            ->select('e.normalized_name')
            ->groupBy('e.normalized_name')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        if ($dupCheck->isEmpty()) {
            $this->line('  Sin nombres normalizados duplicados en asignaciones del periodo.');
        } else {
            $this->warn('  ⚠ ' . $dupCheck->count() . ' nombre(s) normalizado(s) con más de una fila de asignación — revisar unificación.');
        }

        // ── 8. Coherencia PDF (Lendus) vs Excel — motos/cascos/anticipo ────────
        // Blindaje para periodos futuros: el PDF de Lendus guarda estas transacciones
        // bajo etiquetas genéricas ("PAGO", "COMPRA DE", "ANTICIPO DE") y se excluyen de
        // OPEX porque ya están contadas vía el Excel (Financiamiento de Motos / Cascos /
        // Anticipo de nómina, dentro de nomina_detalle). Si un periodo futuro deja de
        // cuadrar aquí, es señal de que el formato del PDF cambió o de que el Excel dejó
        // de traer esas filas — hay que revisar antes de confiar en el total de OPEX.
        $this->line('');
        $this->info('════ 8. COHERENCIA PDF vs EXCEL — motos/cascos/anticipo ════');
        $global = $calc->sumGlobal($branches, $unassigned);
        $pdfExcluido = $global['gastos_lendus_pago_generico_excluido'] ?? [];
        $pdfExcluidoTotal = array_sum($pdfExcluido);
        // "Anticipo de nómina" (D003) vive en $dedNoSumadas, no en nomina_detalle —
        // es una deducción al trabajador, no un gasto de capital humano.
        $excelEquivalente = (float) ($detailTotals['Financiamiento de Motos'] ?? 0)
            + (float) ($detailTotals['Cascos'] ?? 0)
            + (float) ($dedNoSumadas['Anticipo de nómina'] ?? 0);

        foreach ($pdfExcluido as $concepto => $monto) {
            $this->line(sprintf('  PDF Lendus "%s" excluido de OPEX: $%s', $concepto, number_format($monto, 2)));
        }
        $this->line(sprintf('  Total excluido del PDF (genérico):      $%s', number_format($pdfExcluidoTotal, 2)));
        $this->line(sprintf('  Total equivalente en Excel (nómina):     $%s', number_format($excelEquivalente, 2)));

        $diff = abs($pdfExcluidoTotal - $excelEquivalente);
        if ($pdfExcluidoTotal == 0.0 && $excelEquivalente == 0.0) {
            $this->line('  Sin transacciones de motos/cascos/anticipo en este periodo — nada que conciliar.');
        } elseif ($diff > 1.0) {
            $this->warn(sprintf(
                '  ⚠ DIVERGENCIA de $%s entre lo excluido del PDF y lo sumado del Excel. ' .
                'Puede indicar: (a) el PDF trae conceptos genéricos nuevos no cubiertos por el ' .
                'filtro ["PAGO","COMPRA DE","ANTICIPO DE"], (b) el Excel dejó de traer alguna fila, ' .
                'o (c) hay transacciones reales de "Gastos Operativos" siendo excluidas por error. Revisar antes de publicar el reporte.',
                number_format($diff, 2),
            ));
            $ok = false;
        } else {
            $this->line('  ✓ Coincide dentro de tolerancia — la exclusión del PDF sigue representando las mismas transacciones que el Excel.');
        }

        // ── 9. Validación de controles ──────────────────────────────────────
        $this->line('');
        $this->info('════ 9. VALIDACIÓN DE TOTALES DE CONTROL ════');
        $checks = [
            'Subtotal clasificado NOI' => [$subtotalNoi, null],
            'Capital Humano adicional' => [$capitalHumano, null],
            'TOTAL Nómina y Capital Humano' => [$total, null],
        ];
        foreach ($checks as $label => [$value, $_]) {
            $this->line(sprintf('  %-35s $%s', $label, number_format($value, 2)));
        }

        if ($this->option('export')) {
            $this->exportCsv($periodId, $controls, $sueldos, $comisiones, $vacaciones, $prima, $bonos, $detailTotals, $lendusExcluded, $dedNoSumadas, $total);
        }

        $this->line('');
        $this->info($ok ? '  ✓ Sin anomalías de periodo detectadas.' : '  ✗ Se detectaron anomalías — revisar arriba.');
        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function exportCsv(
        int $periodId,
        array $controls,
        float $sueldos,
        float $comisiones,
        float $vacaciones,
        float $prima,
        float $bonos,
        array $detailTotals,
        $lendusExcluded,
        array $dedNoSumadas,
        float $total,
    ): void {
        $dir  = 'auditorias';
        $file = "nomina_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";
        $csv  = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';

        $lines = [implode(',', ['Sección', 'Concepto', 'Monto', 'Incluido', 'Motivo'])];

        foreach ($controls as $code => $vals) {
            $lines[] = implode(',', ['NOI Control', $csv("{$code} percepciones"), number_format($vals['percepciones'], 2, '.', ''), 'Referencia', $csv('Suma directa del upload')]);
            $lines[] = implode(',', ['NOI Control', $csv("{$code} deducciones"), number_format($vals['deducciones'], 2, '.', ''), 'Referencia', $csv('Suma directa del upload')]);
        }

        $lines[] = implode(',', ['NOI Clasificado', 'Sueldos (P001+D111-D137)', number_format($sueldos, 2, '.', ''), 'Sí', '']);
        $lines[] = implode(',', ['NOI Clasificado', 'Comisiones (P002+P119)', number_format($comisiones, 2, '.', ''), 'Sí', '']);
        $lines[] = implode(',', ['NOI Clasificado', 'Vacaciones', number_format($vacaciones, 2, '.', ''), 'Sí', '']);
        $lines[] = implode(',', ['NOI Clasificado', 'Prima vacacional', number_format($prima, 2, '.', ''), 'Sí', '']);
        $lines[] = implode(',', ['NOI Clasificado', 'Bonos', number_format($bonos, 2, '.', ''), 'Sí', '']);

        foreach ($detailTotals as $label => $amt) {
            $lines[] = implode(',', ['Capital Humano', $csv($label), number_format($amt, 2, '.', ''), 'Sí', '']);
        }

        foreach ($lendusExcluded as $row) {
            $lines[] = implode(',', ['Excluido', $csv('Lendus ' . $row->concept), number_format($row->total, 2, '.', ''), 'No', $csv('Ya cubierto por NOI/IMSS oficial')]);
        }

        foreach ($dedNoSumadas as $label => $amt) {
            $lines[] = implode(',', ['Excluido', $csv('Deducción NOI: ' . $label), number_format($amt, 2, '.', ''), 'No', $csv('Deducción al trabajador, no es gasto adicional')]);
        }

        $lines[] = implode(',', ['TOTAL', 'Nómina y Capital Humano', number_format($total, 2, '.', ''), 'Sí', '']);

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
