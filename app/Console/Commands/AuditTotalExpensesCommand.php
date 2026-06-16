<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditTotalExpensesCommand extends Command
{
    protected $signature   = 'reportes:audit-total-expenses
                                {period_id}
                                {--compare-reference : comparar contra referencia Abril 2026}
                                {--detail            : desglose de cada componente por fuente}';
    protected $description = 'Auditoría de gastos totales: operativos + nómina + créditos personal → total usado en utilidad';

    private const REF = [
        'gastos_operativos'  => 837_384.28,
        'nomina_capital'     => 2_501_589.43,
        'creditos_personal'  => 66_285.30,
        'gastos_totales'     => 3_222_315.50,
    ];

    private const EXCL_CATS = [
        'Nómina y Capital Humano',
        'Envío de utilidad a corporativo',
        'Préstamos Intersucursales',
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

        $this->line('');
        $this->info("════════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA GASTOS TOTALES — {$period->label} (ID {$period->id})");
        $this->info("  Gastos Totales = Gastos Operativos + Nómina y Capital Humano + Créditos Personal");
        $this->info("════════════════════════════════════════════════════════════════════");

        // ── A. Gastos operativos (de fact_expenses, excl. categorías especiales) ──
        $this->line('');
        $this->info('════ A. GASTOS OPERATIVOS (fact_expenses excl. Nómina/Envío/Fondeo) ════');

        $gastosOpBySource = DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->whereNotIn('fe.category', self::EXCL_CATS)
            ->select(
                'ds.code as fuente',
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total'),
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy('ds.code')
            ->get();

        $gastosOp = 0.0;
        foreach ($gastosOpBySource as $row) {
            $this->line("  {$row->fuente}: \$" . number_format((float)$row->total, 2) . " ({$row->cnt} regs)");
            $gastosOp += (float)$row->total;
        }

        $this->line("  TOTAL gastos operativos: \$" . number_format($gastosOp, 2));
        $this->printDiff('Gastos operativos', $gastosOp, self::REF['gastos_operativos']);

        // ── B. Nómina y Capital Humano (from NOI) ─────────────────────────────
        $this->line('');
        $this->info('════ B. NÓMINA Y CAPITAL HUMANO (fact_noi_movements) ════');

        $nomina = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) = 'percepcion'")
            ->sum('amount');

        $deducciones = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->whereRaw("LOWER(COALESCE(concept_type,'')) IN ('deduccion','descuento')")
            ->sum('amount');

        $this->line("  Percepciones (NOI):   \$" . number_format($nomina, 2));
        $this->line("  Deducciones (NOI):    \$" . number_format($deducciones, 2));
        $this->line("  Neto NOI:             \$" . number_format($nomina - $deducciones, 2));
        $this->printDiff('Nómina y Capital Humano', $nomina, self::REF['nomina_capital']);

        // Also check expenses labeled as Nómina y Capital Humano in fact_expenses
        $nominaExpenses = (float) DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->whereIn('fe.period_id', $dataIds)
            ->where('fe.category', 'Nómina y Capital Humano')
            ->sum(DB::raw('COALESCE(NULLIF(fe.paid_amount,0),fe.amount)'));

        if ($nominaExpenses > 0) {
            $this->line("  Nómina en fact_expenses: \$" . number_format($nominaExpenses, 2));
        }

        // ── C. Créditos personal ──────────────────────────────────────────────
        $this->line('');
        $this->info('════ C. CRÉDITOS PERSONAL ════');

        $creditosPersonal = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(operation,'')) LIKE '%CREDITO%PERSONAL%'
                     OR UPPER(COALESCE(concept,''))   LIKE '%CREDITO%PERSONAL%'")
            ->sum('total_amount');

        $this->line("  Créditos personal detectados: \$" . number_format($creditosPersonal, 2));
        $this->printDiff('Créditos personal', $creditosPersonal, self::REF['creditos_personal']);

        // ── D. TOTAL ─────────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ D. GASTOS TOTALES ════');

        $gastosTotal = $gastosOp + $nomina + $creditosPersonal;

        $this->line(str_pad('Componente', 32) . str_pad('Sistema', 18) . ($this->option('compare-reference') ? str_pad('Referencia', 18) . 'Diferencia' : ''));
        $this->line(str_repeat('─', 70));

        $rows = [
            ['Gastos operativos',     $gastosOp,         self::REF['gastos_operativos']],
            ['Nómina y Capital Humano',$nomina,           self::REF['nomina_capital']],
            ['Créditos personal',     $creditosPersonal, self::REF['creditos_personal']],
            ['TOTAL GASTOS',          $gastosTotal,      self::REF['gastos_totales']],
        ];

        foreach ($rows as [$lbl, $sys, $ref]) {
            $sysStr = '$' . number_format($sys, 2);
            if ($this->option('compare-reference')) {
                $diff  = $sys - $ref;
                $sign  = $diff >= 0 ? '+' : '';
                $match = abs($diff) < 1 ? '✓' : '✗';
                $line  = str_pad($lbl, 32) .
                    str_pad($sysStr, 18) .
                    str_pad('$' . number_format($ref, 2), 18) .
                    "{$sign}$" . number_format(abs($diff), 2) . " {$match}";
                if ($match === '✓') $this->info("  {$line}");
                else $this->warn("  {$line}");
            } else {
                $this->line("  " . str_pad($lbl, 32) . $sysStr);
            }
        }

        // ── E. Diagnóstico categorías problemáticas ───────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ E. DESGLOSE GASTOS OPERATIVOS POR CATEGORÍA ════');

            $byCat = DB::table('fact_expenses as fe')
                ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
                ->whereIn('fe.period_id', $dataIds)
                ->whereNotIn('fe.category', self::EXCL_CATS)
                ->select(
                    DB::raw('COALESCE(fe.category,"Sin categoría") as categoria'),
                    DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total'),
                    DB::raw('COUNT(*) as cnt')
                )
                ->groupBy('fe.category')
                ->orderByDesc('total')
                ->get();

            $refs = [
                'Renta Oficina'          => 159_792.01,
                'Pólizas'                => 197_200.00,
                'Emergentes'             => 290_800.80,
                'Recargas Telefónicas'   => 20_800.00,
                'Viáticos'               => 59_570.00,
                'Señora Limpieza'        => 41_800.00,
                'Gastos Operativos'      => 290_800.80,
            ];

            $this->line(str_pad('Categoría', 34) . str_pad('Sistema', 16) . str_pad('Referencia', 16) . 'Diferencia');
            $this->line(str_repeat('─', 80));
            foreach ($byCat as $row) {
                $cat    = (string)$row->categoria;
                $sys    = (float)$row->total;
                $ref    = $refs[$cat] ?? null;
                $refStr = $ref !== null ? '$' . number_format($ref, 2) : '—';
                $diffStr = $ref !== null ? (($sys - $ref) >= 0 ? '+' : '') . '$' . number_format(abs($sys - $ref), 2) : '';
                $this->line(
                    str_pad(mb_substr($cat, 0, 32), 34) .
                    str_pad('$' . number_format($sys, 2), 16) .
                    str_pad($refStr, 16) .
                    $diffStr
                );
            }

            $this->line('');
            $this->warn('  ⚠ Si "Emergentes" está inflado vs referencia:');
            $this->warn('  → Los gastos con concepto específico (RENTA, VIATICO, etc.) están cayendo al fallback "Emergentes".');
            $this->warn('  → Ejecutar: php artisan reportes:audit-expenses ' . $period->id . ' --by-concept --detail');
            $this->warn('  → para ver cuáles conceptos no matchean en canonicalGastoConcept().');
        }

        return 0;
    }

    private function printDiff(string $label, float $sys, float $ref): void
    {
        if (!$this->option('compare-reference')) return;
        $diff  = $sys - $ref;
        $sign  = $diff >= 0 ? '+' : '';
        $match = abs($diff) < 1 ? '✓ OK' : '✗ DIFERENCIA';
        $msg   = "  [{$match}] {$label}: Sistema \$" . number_format($sys, 2) . " | Ref \$" . number_format($ref, 2) . " | {$sign}\$" . number_format(abs($diff), 2);
        if (str_starts_with($match, '✓')) $this->info($msg);
        else $this->warn($msg);
    }
}
