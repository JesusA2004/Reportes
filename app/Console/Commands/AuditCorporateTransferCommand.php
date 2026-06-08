<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditCorporateTransferCommand extends Command
{
    protected $signature = 'reportes:audit-corporate-transfer
                                {period_id}
                                {--diff-reference= : referencia para calcular diferencia (ej. 3076800)}
                                {--detail : show per-branch, per-period breakdown}';
    protected $description = 'Auditoría de Envío a Corporativo/Sobrante: fuentes, duplicados, conciliación';

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

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA ENVÍO CORPORATIVO — {$period->label} (ID {$period->id})");
        $this->info("════════════════════════════════════════════════════════════");

        // ── A. Por concepto y categoría ───────────────────────────────────────
        $this->line('');
        $this->info('════ A. TODOS LOS REGISTROS CATEGORÍA "Envío de utilidad a corporativo" ════');

        $byConcept = DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('fe.category', 'Envío de utilidad a corporativo')
            ->select(
                'ds.code as fuente',
                'fe.concept',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total'),
                DB::raw('SUM(CASE WHEN fe.branch_id IS NULL THEN COALESCE(NULLIF(fe.paid_amount,0),fe.amount) ELSE 0 END) as sin_suc'),
                DB::raw('SUM(CASE WHEN fe.branch_id IS NOT NULL THEN COALESCE(NULLIF(fe.paid_amount,0),fe.amount) ELSE 0 END) as con_suc')
            )
            ->groupBy('ds.code', 'fe.concept')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Fuente', 24) . str_pad('Concepto', 26) . str_pad('Regs', 7) .
                    str_pad('Total', 16) . str_pad('Sin Suc', 14) . 'Con Suc');
        $this->line(str_repeat('─', 95));

        $grandTotal = 0.0;
        foreach ($byConcept as $r) {
            $this->line(
                str_pad($r->fuente ?? 'NULL', 24) .
                str_pad(mb_substr($r->concept ?? 'NULL', 0, 24), 26) .
                str_pad(number_format($r->cnt), 7) .
                str_pad('$' . number_format((float)$r->total, 0), 16) .
                str_pad('$' . number_format((float)$r->sin_suc, 0), 14) .
                '$' . number_format((float)$r->con_suc, 0)
            );
            $grandTotal += (float)$r->total;
        }
        $this->line(str_repeat('─', 95));
        $this->line(str_pad('TOTAL ACTUAL (sistema)',  62) . '$' . number_format($grandTotal, 2));

        // ── B. Agrupado por fuente ─────────────────────────────────────────────
        $this->line('');
        $this->info('════ B. TOTAL POR FUENTE DE DATOS ════');

        $bySource = DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('fe.category', 'Envío de utilidad a corporativo')
            ->select(
                'ds.code',
                'ds.name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total')
            )
            ->groupBy('ds.code', 'ds.name')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Fuente (code)', 26) . str_pad('Regs', 7) . str_pad('Total', 18) . 'Nombre completo');
        $this->line(str_repeat('─', 80));
        foreach ($bySource as $s) {
            $this->line(
                str_pad($s->code ?? 'NULL', 26) .
                str_pad(number_format($s->cnt), 7) .
                str_pad('$' . number_format((float)$s->total, 2), 18) .
                ($s->name ?? '')
            );
        }

        // ── C. Detección de duplicados ────────────────────────────────────────
        $this->line('');
        $this->info('════ C. ANÁLISIS DE DUPLICACIÓN ════');
        $refEnvio = 3_076_800.00;

        $lendus    = $bySource->firstWhere('code', 'gastos_lendus');
        $lendusXls = $bySource->firstWhere('code', 'gastos_lendus_excel');

        if ($lendus && $lendusXls) {
            $isDup = abs((float)$lendus->total - (float)$lendusXls->total) < 100 ||
                     ($lendus->cnt === $lendusXls->cnt);

            if ($isDup || abs($grandTotal / $refEnvio - 2.0) < 0.1) {
                $this->warn('  ⚠ DUPLICACIÓN DETECTADA:');
                $this->warn('    gastos_lendus (PDF):   $' . number_format((float)$lendus->total, 2) . ' (' . $lendus->cnt . ' regs)');
                $this->warn('    gastos_lendus_excel:   $' . number_format((float)$lendusXls->total, 2) . ' (' . $lendusXls->cnt . ' regs)');
                $this->warn('    SUMA doble: $' . number_format((float)$lendus->total + (float)$lendusXls->total, 2));
                $this->warn('    REFERENCIA: $' . number_format($refEnvio, 2));
                $this->warn('    La diferencia es ~' . round($grandTotal / $refEnvio, 2) . 'x la referencia → duplicado clásico.');
                $this->line('');
                $this->line('  CAUSA: gastos_lendus (PDF) y gastos_lendus_excel (Excel) contienen');
                $this->line('         los MISMOS excedentes del mismo reporte Lendus de gastos.');
                $this->line('         Ambos se importan y ambos suman en fact_expenses.');
                $this->line('');
                $this->line('  FIX: Usar SOLO UNA fuente. Las opciones son:');
                $this->line('    1. Deshabilitar gastos_lendus_excel (Excel tiene mejor estructura)');
                $this->line('    2. Deshabilitar gastos_lendus (PDF)');
                $this->line('    3. Implementar deduplicación por (period_id, concept, amount, branch_id)');
            } else {
                $this->info('  ✓ gastos_lendus y gastos_lendus_excel tienen montos distintos — no parecen duplicados exactos.');
            }
        }

        // ── D. Comparación vs referencia ──────────────────────────────────────
        $this->line('');
        $this->info('════ D. CONCILIACIÓN VS REFERENCIA ════');
        $this->line(str_pad('Escenario', 52) . str_pad('Monto', 17) . str_pad('Diff vs Ref', 14) . 'Match');
        $this->line(str_repeat('─', 90));

        $scenarios = [
            ['Sistema actual (todas las fuentes)',   $grandTotal],
        ];

        foreach ($bySource as $s) {
            $scenarios[] = ['Solo ' . ($s->code ?? 'fuente') . ' (una fuente)', (float)$s->total];
        }
        $scenarios[] = ['REFERENCIA abril 2026', $refEnvio];

        foreach ($scenarios as [$lbl, $val]) {
            $diff  = $val - $refEnvio;
            $sign  = $diff >= 0 ? '+' : '';
            $match = abs($diff) < 50 ? '✓ EXACTO' : ($diff < 500 ? '≈ CERCANO' : '');
            $this->line(
                str_pad($lbl, 52) .
                str_pad('$' . number_format($val, 2), 17) .
                str_pad($sign . '$' . number_format($diff, 2), 14) .
                $match
            );
        }

        if ($this->option('diff-reference') !== null) {
            $this->showDiffReference($dataIds, (float) $this->option('diff-reference'));
        }

        if ($this->option('detail')) {
            $this->showDetailByBranchAndPeriod($dataIds);
        }

        return 0;
    }

    private function showDiffReference(array $dataIds, float $ref): void
    {
        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════');
        $this->info('  REGISTROS QUE EXPLICAN DIFERENCIA — Referencia: $' . number_format($ref, 2));
        $this->info('════════════════════════════════════════════════════════════════════════');

        // Get all envío records from the primary source (gastos_lendus only)
        $records = DB::table('fact_expenses as fe')
            ->leftJoin('branches as b', 'fe.branch_id', '=', 'b.id')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('fe.category', 'Envío de utilidad a corporativo')
            ->where('ds.code', 'gastos_lendus')
            ->select(
                'fe.id',
                'fe.period_id',
                'fe.expense_date',
                DB::raw('COALESCE(b.name, "Sin sucursal") as sucursal'),
                'fe.concept',
                'ds.code as fuente',
                DB::raw('COALESCE(NULLIF(fe.paid_amount,0), fe.amount) as monto'),
                'fe.branch_id'
            )
            ->orderByDesc(DB::raw('COALESCE(NULLIF(fe.paid_amount,0), fe.amount)'))
            ->get();

        $systemTotal = $records->sum('monto');
        $diff        = $systemTotal - $ref;

        $this->line('');
        $this->line('  Total sistema (gastos_lendus): $' . number_format($systemTotal, 2));
        $this->line('  Referencia:                    $' . number_format($ref, 2));
        $this->line('  Diferencia:                    $' . number_format($diff, 2) . ($diff > 0 ? ' (sistema > referencia)' : ' (sistema < referencia)'));
        $this->line('');

        // Show all records with running total, marking records that exceed reference
        $this->line(str_pad('Fecha', 12) . str_pad('Sucursal', 24) . str_pad('Concepto', 32) .
                    str_pad('Fuente', 18) . str_pad('Monto', 16) . str_pad('Incluido', 10) . 'Motivo');
        $this->line(str_repeat('─', 115));

        $running    = 0.0;
        $overRecords = [];

        foreach ($records as $r) {
            $running      += (float) $r->monto;
            $overRef       = $running > $ref;
            $includido     = !$overRef ? 'SÍ' : '⚠ EXCESO';
            $motivo        = '';

            if ($overRef && $running - (float) $r->monto < $ref) {
                $motivo = 'Parcialmente sobre ref ($' . number_format($running - $ref, 2) . ' exceso)';
                $overRecords[] = $r;
            } elseif ($overRef) {
                $motivo = 'Sobre la referencia ($' . number_format((float) $r->monto, 2) . ')';
                $overRecords[] = $r;
            }

            $line = str_pad($r->expense_date ?? '—', 12) .
                    str_pad(mb_substr($r->sucursal, 0, 22), 24) .
                    str_pad(mb_substr($r->concept ?? '—', 0, 30), 32) .
                    str_pad($r->fuente, 18) .
                    str_pad('$' . number_format((float) $r->monto, 2), 16) .
                    str_pad($includido, 10) .
                    $motivo;

            if ($overRef) {
                $this->warn($line);
            } else {
                $this->line($line);
            }
        }

        $this->line(str_repeat('─', 115));
        $this->line(str_pad('TOTAL', 86) . '$' . number_format($systemTotal, 2));
        $this->line(str_pad('REFERENCIA', 86) . '$' . number_format($ref, 2));
        $this->line(str_pad('DIFERENCIA', 86) . '$' . number_format($diff, 2));

        if (count($overRecords) > 0) {
            $this->line('');
            $this->warn('  ⚠ REGISTROS QUE GENERAN LA DIFERENCIA (total: ' . count($overRecords) . ' registro(s)):');
            foreach ($overRecords as $r) {
                $this->warn('    ID=' . $r->id . ' | ' . ($r->expense_date ?? '—') . ' | ' . $r->sucursal .
                            ' | ' . mb_substr($r->concept ?? '—', 0, 30) . ' | $' . number_format((float) $r->monto, 2));
            }
            $this->line('');
            $this->info('  DIAGNÓSTICO:');
            $this->info('  La diferencia de $' . number_format(abs($diff), 2) . ' puede deberse a:');
            $this->line('    1. Sucursal(es) con excedente real diferente al registrado en la referencia');
            $this->line('    2. Período de corte diferente (semanas incluidas en la acumulación)');
            $this->line('    3. Ajuste contable aplicado en la referencia pero no en el sistema');
            $this->line('    4. Redondeo/ajuste de centavos por tipo de cambio o cálculo');
        } else {
            $this->info('  ✓ Todos los registros están dentro de la referencia.');
        }

        // También mostrar si gastos_lendus_excel tiene registros adicionales
        $xlsRecords = DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('fe.category', 'Envío de utilidad a corporativo')
            ->where('ds.code', 'gastos_lendus_excel')
            ->selectRaw('COUNT(*) as cnt, SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total')
            ->first();

        if ($xlsRecords && $xlsRecords->cnt > 0) {
            $this->line('');
            $this->warn('  ⚠ gastos_lendus_excel también tiene ' . $xlsRecords->cnt . ' registros ($' .
                        number_format((float) $xlsRecords->total, 2) . ') — EXCLUIDOS del análisis arriba (duplicado del PDF).');
        }
    }

    private function showDetailByBranchAndPeriod(array $dataIds): void
    {
        $this->line('');
        $this->info('════ E. ENVÍO CORPORATIVO POR SUCURSAL ════');

        $byBranch = DB::table('fact_expenses as fe')
            ->leftJoin('branches as b', 'fe.branch_id', '=', 'b.id')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('fe.category', 'Envío de utilidad a corporativo')
            ->select(
                DB::raw('COALESCE(b.name, "Sin sucursal") as sucursal'),
                'ds.code as fuente',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total')
            )
            ->groupBy('b.name', 'ds.code')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Sucursal', 28) . str_pad('Fuente', 24) . str_pad('Regs', 7) . 'Total');
        $this->line(str_repeat('─', 70));
        foreach ($byBranch as $r) {
            $this->line(
                str_pad(mb_substr($r->sucursal, 0, 26), 28) .
                str_pad($r->fuente ?? 'NULL', 24) .
                str_pad(number_format($r->cnt), 7) .
                '$' . number_format((float)$r->total, 2)
            );
        }
    }
}
