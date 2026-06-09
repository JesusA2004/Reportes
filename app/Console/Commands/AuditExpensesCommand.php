<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Full expense audit: detects duplicates, normalizes concepts, explains "sin sucursal".
 *
 * Detects:
 * - FONDEO A / FONDEO A SUCURSAL duplicate
 * - EXCEDENTES duplicate in EBITDA
 * - "Sin sucursal" breakdown by category and concept
 * - Which categories enter EBITDA and which are excluded
 */
class AuditExpensesCommand extends Command
{
    protected $signature = 'reportes:audit-expenses
                                {period_id}
                                {--concept= : filter by concept keyword}
                                {--category= : filter by category keyword}
                                {--without-branch : only show expenses without branch assignment}
                                {--check-duplicates : highlight likely duplicate records}
                                {--compare-reference= : referencia de gastos totales (ej. 2223315.50) — audita si nómina está incluida}
                                {--by-concept : desglose per-concepto con análisis de referencia (requiere --compare-reference)}
                                {--detail : show full source deduplication analysis}';
    protected $description = 'Auditoría de gastos: duplicados, sin-sucursal, normalización de conceptos, EBITDA';

    // Categories NOT included in EBITDA
    private const EBITDA_EXCLUDED_CATS = [
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

        $conceptFilter  = strtoupper(trim($this->option('concept') ?? ''));
        $categoryFilter = strtoupper(trim($this->option('category') ?? ''));
        $withoutBranch  = (bool) $this->option('without-branch');
        $checkDups      = (bool) $this->option('check-duplicates');

        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA GASTOS — {$period->label} (ID {$period->id})");
        $this->info("  Filtro concepto: " . ($conceptFilter ?: 'todos') . " | Categoría: " . ($categoryFilter ?: 'todas'));
        $this->info("  Solo sin sucursal: " . ($withoutBranch ? 'SÍ' : 'NO'));
        $this->info("════════════════════════════════════════════════════════════════");

        $baseQuery = DB::table('fact_expenses as fe')
            ->leftJoin('branches as b', 'fe.branch_id', '=', 'b.id')
            ->whereIn('fe.period_id', $dataIds);

        if ($conceptFilter) {
            $baseQuery->whereRaw("UPPER(COALESCE(fe.concept,'')) LIKE ?", ["%{$conceptFilter}%"]);
        }
        if ($categoryFilter) {
            $baseQuery->whereRaw("UPPER(COALESCE(fe.category,'')) LIKE ?", ["%{$categoryFilter}%"]);
        }
        if ($withoutBranch) {
            $baseQuery->whereNull('fe.branch_id');
        }

        // ── Resumen global ───────────────────────────────────────────────────
        if (!$withoutBranch && !$conceptFilter && !$categoryFilter) {
            $this->line('');
            $this->info('════ RESUMEN POR CATEGORÍA ════');
            $this->showByCategory($dataIds);
        }

        // ── Por concepto ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('════ DETALLE POR CONCEPTO ════');
        $this->showByConcept($baseQuery, $dataIds);

        // ── Sin sucursal ─────────────────────────────────────────────────────
        if ($withoutBranch || (!$conceptFilter && !$categoryFilter)) {
            $this->line('');
            $this->info('════ GASTOS SIN SUCURSAL ════');
            $this->showWithoutBranch($dataIds);
        }

        // ── Duplicados FONDEO ────────────────────────────────────────────────
        if (!$conceptFilter && !$categoryFilter || str_contains('FONDEO', $conceptFilter)) {
            $this->line('');
            $this->info('════ ANÁLISIS FONDEO (posible duplicado) ════');
            $this->analyzeFondeo($dataIds);
        }

        // ── EXCEDENTES / envío corporativo ───────────────────────────────────
        if (!$conceptFilter && !$categoryFilter || str_contains('EXCEDENTES', $conceptFilter) || str_contains('CORPORATIVO', $conceptFilter)) {
            $this->line('');
            $this->info('════ ANÁLISIS EXCEDENTES / ENVÍO CORPORATIVO ════');
            $this->analyzeExcedentes($dataIds);
        }

        // ── Análisis de fuentes (duplicación crítica) ────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ ANÁLISIS DE FUENTES — DEDUPLICACIÓN ════');
            $this->analyzeSourceDuplication($dataIds);
        }

        // ── Comparación vs referencia ─────────────────────────────────────────
        if ($this->option('compare-reference') !== null) {
            $this->showCompareReference($dataIds, (float) $this->option('compare-reference'));
        }

        return 0;
    }

    private function showCompareReference(array $dataIds, float $ref): void
    {
        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════');
        $this->info('  COMPARACIÓN VS REFERENCIA GASTOS: $' . number_format($ref, 2));
        $this->info('  Objetivo: determinar si la referencia incluye nómina o no, y qué fuentes usa');
        $this->info('════════════════════════════════════════════════════════════════════════');

        $lendusId = DB::table('data_sources')->where('code', 'gastos_lendus')->value('id');
        $erpId    = DB::table('data_sources')->where('code', 'gastos_erp')->value('id');

        // Exclusion categories (same as BranchCalc)
        $excCatsEbitda  = ['Nómina y Capital Humano', 'Envío de utilidad a corporativo', 'Préstamos Intersucursales'];
        $lendusPresentCats = ['Gastos Operativos', 'Pólizas', 'Renta Oficina', 'Recargas Telefónicas', 'Gasolina', 'Agua'];

        $makeSum = function (string $srcCode, ?array $catExclude = [], ?array $catIncludeOnly = null) use ($dataIds) {
            $srcId = DB::table('data_sources')->where('code', $srcCode)->value('id');
            if (!$srcId) return 0.0;
            $q = DB::table('fact_expenses as fe')
                ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
                ->whereIn('fe.period_id', $dataIds)
                ->where('ru.data_source_id', $srcId);
            if ($catIncludeOnly !== null) {
                $q->whereIn('fe.category', $catIncludeOnly);
            } elseif (!empty($catExclude)) {
                $q->whereNotIn('fe.category', $catExclude);
            }
            return (float) $q->selectRaw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as t')->value('t');
        };

        // Key sub-totals
        $lendusAll      = $makeSum('gastos_lendus');
        $lendusOp       = $makeSum('gastos_lendus', $excCatsEbitda);
        $erpAll         = $makeSum('gastos_erp');
        $erpOpNoDup     = $makeSum('gastos_erp', array_merge($excCatsEbitda, $lendusPresentCats));
        $erpAllCats     = $makeSum('gastos_erp', $excCatsEbitda);
        $lendusNomina   = $makeSum('gastos_lendus', [], ['Nómina y Capital Humano']);
        $erpNomina      = $makeSum('gastos_erp',    [], ['Nómina y Capital Humano']);

        // NOI from fact_noi_movements (used in BranchCalc for nomina_total)
        $noiPercep = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('concept', ['P001','P002','P009','P010','P108','P109','P120','P123'])
            ->sum('amount');

        // Scenarios to reproduce reference
        $scenarios = [
            ['A) gastos_lendus (todo)',                                   $lendusAll],
            ['B) gastos_lendus (excl. Envío + Nómina + Fondeo)',         $lendusOp],
            ['C) gastos_erp (todo)',                                      $erpAll],
            ['D) gastos_erp (excl. categorías EBITDA)',                   $erpAllCats],
            ['E) gastos_erp (BranchCalc: sin dup lendus cats)',           $erpOpNoDup],
            ['F) gastos_lendus(op) + gastos_erp(op)',                     $lendusOp + $erpAllCats],
            ['G) gastos_lendus(op) + gastos_erp(BranchCalc)',             $lendusOp + $erpOpNoDup],
            ['H) gastos_lendus(all) + gastos_erp(BranchCalc)',            $lendusAll + $erpOpNoDup],
            ['I) Sólo gastos_lendus(nómina en gastos)',                   $lendusNomina],
            ['J) gastos_lendus(op) + NOI percepciones',                   $lendusOp + $noiPercep],
            ['K) gastos_lendus(op) + gastos_erp(BranchCalc) + NOI perc', $lendusOp + $erpOpNoDup + $noiPercep],
            ['L) gastos_lendus(all excl envío)',                          $makeSum('gastos_lendus', ['Envío de utilidad a corporativo'])],
        ];

        $this->line('');
        $this->line(str_pad('Escenario', 54) . str_pad('Monto', 18) . str_pad('Diff vs Ref', 16) . 'Match');
        $this->line(str_repeat('─', 100));

        foreach ($scenarios as [$lbl, $val]) {
            $diff  = $val - $ref;
            $sign  = $diff >= 0 ? '+' : '';
            $match = abs($diff) < 100 ? '✓ EXACTO' : (abs($diff) < 10000 ? '≈ CERCANO' : '');
            $line  = str_pad($lbl, 54) .
                     str_pad('$' . number_format($val, 2), 18) .
                     str_pad($sign . '$' . number_format($diff, 2), 16) .
                     $match;
            if ($match === '✓ EXACTO') {
                $this->info($line);
            } elseif ($match === '≈ CERCANO') {
                $this->warn($line);
            } else {
                $this->line($line);
            }
        }

        $this->line(str_repeat('─', 100));
        $this->line(str_pad('REFERENCIA', 54) . '$' . number_format($ref, 2));

        // Summary: does reference include nómina?
        $this->line('');
        $this->info('════ DIAGNÓSTICO: ¿La referencia incluye nómina? ════');
        $this->line('');
        $this->line(str_pad('Componente', 50) . 'Monto');
        $this->line(str_repeat('─', 72));
        $this->line(str_pad('gastos_lendus (solo operativos, excl. envío/nómina/fondeo)', 50) . '$' . number_format($lendusOp, 2));
        $this->line(str_pad('gastos_erp (BranchCalc, sin duplicar cats lendus)',          50) . '$' . number_format($erpOpNoDup, 2));
        $this->line(str_pad('= Gastos Operativos (BranchCalc)',                            50) . '$' . number_format($lendusOp + $erpOpNoDup, 2));
        $this->line('');
        $this->line(str_pad('Nómina en gastos_lendus (cat. "Nómina y Capital Humano")',   50) . '$' . number_format($lendusNomina, 2));
        $this->line(str_pad('Nómina en gastos_erp (cat. "Nómina y Capital Humano")',      50) . '$' . number_format($erpNomina, 2));
        $this->line(str_pad('Percepciones NOI (P001/P002/P009/P010/Bonos)',               50) . '$' . number_format($noiPercep, 2));
        $this->line('');

        $gastosOpSolo = $lendusOp + $erpOpNoDup;
        $difConNomGastos  = abs(($gastosOpSolo + $lendusNomina + $erpNomina) - $ref);
        $difSinNom        = abs($gastosOpSolo - $ref);
        $difConNoi        = abs($gastosOpSolo + $noiPercep - $ref);

        $this->info('  Hipótesis A: Referencia = solo gastos operativos (SIN nómina)');
        $this->line('    → Diferencia: $' . number_format($difSinNom, 2) . ($difSinNom < 5000 ? ' ≈ PLAUSIBLE' : ''));
        $this->line('');
        $this->info('  Hipótesis B: Referencia = gastos op + nómina en gastos_lendus/erp');
        $this->line('    → Diferencia: $' . number_format($difConNomGastos, 2) . ($difConNomGastos < 5000 ? ' ≈ PLAUSIBLE' : ''));
        $this->line('');
        $this->info('  Hipótesis C: Referencia = gastos op + NOI percepciones brutas');
        $this->line('    → Diferencia: $' . number_format($difConNoi, 2) . ($difConNoi < 5000 ? ' ≈ PLAUSIBLE' : ''));
        $this->line('');

        $best = collect([
            ['sin nómina',        $difSinNom],
            ['con nómina gastos', $difConNomGastos],
            ['con NOI',           $difConNoi],
        ])->sortBy(1)->first();

        $this->info('  CONCLUSIÓN MÁS PROBABLE: Referencia ' . $best[0] . ' (menor diferencia: $' . number_format($best[1], 2) . ')');

        if ($this->option('by-concept')) {
            $this->showByConceptDetail($dataIds, $ref);
        }
    }

    private function showByConceptDetail(array $dataIds, float $ref): void
    {
        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════════════');
        $this->info('  DESGLOSE POR CONCEPTO — ¿Qué suma la referencia $' . number_format($ref, 2) . '?');
        $this->info('════════════════════════════════════════════════════════════════════════════════');

        // Constants (mirror BranchRadiographyCalculator)
        $nominaExpCats    = ['Gasolina', 'Financiamiento Celular'];
        $lendusPresentCats = ['Gastos Operativos', 'Pólizas', 'Renta Oficina', 'Recargas Telefónicas', 'Gasolina', 'Agua'];
        $ebitdaExcludedCats = ['Nómina y Capital Humano', 'Envío de utilidad a corporativo', 'Préstamos Intersucursales'];

        // All concepts with source, category, amount
        $rows = DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->select(
                'fe.concept',
                'fe.category',
                'ds.code as src',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total')
            )
            ->groupBy('fe.concept', 'fe.category', 'ds.code')
            ->orderByDesc('total')
            ->get();

        // Classify each row
        $buckets = [
            'gastos_op'  => 0.0,
            'nomina'     => 0.0,
            'excedentes' => 0.0,
            'fondeo'     => 0.0,
            'otro'       => 0.0,
        ];

        $classified = [];
        foreach ($rows as $r) {
            $cat      = $r->category ?? '';
            $concept  = $r->concept ?? '';
            $src      = $r->src ?? '';
            $amt      = (float) $r->total;

            // Determine bucket
            $isEnvio   = $cat === 'Envío de utilidad a corporativo';
            $isFondeo  = $cat === 'Préstamos Intersucursales';
            $isNomCat  = $cat === 'Nómina y Capital Humano';
            $isNomExp  = in_array($cat, $nominaExpCats, true);
            $isLendDup = $src === 'gastos_erp' && in_array($cat, $lendusPresentCats, true);

            if ($isEnvio) {
                $bucket = 'excedentes';
                $inEbitda = 'NO (envío)';
                $note = 'Distribución de utilidad — fuera de EBITDA';
            } elseif ($isFondeo) {
                $bucket = 'fondeo';
                $inEbitda = 'NO (fondeo)';
                $note = 'Préstamo intersucursal — fuera de EBITDA';
            } elseif ($isNomCat) {
                $bucket = 'nomina';
                $inEbitda = 'NO→nómina';
                $note = 'Va a nómina (NOI), excluida de gastos_op';
            } elseif ($isNomExp) {
                $bucket = 'nomina';
                $inEbitda = 'NO→nómina';
                $note = "NOMINA_EXPENSE_CAT ({$cat}) — va a nómina_detalle";
            } elseif ($isLendDup) {
                $bucket = 'gastos_op';
                $inEbitda = 'NO (dup ERP)';
                $note = "Cat presente en Lendus — excluida del ERP para evitar duplicado";
            } else {
                $bucket = 'gastos_op';
                $inEbitda = 'SÍ';
                $note = 'En gastos operativos EBITDA';
            }

            $buckets[$bucket] += $amt;
            $classified[] = [
                'concept'  => mb_substr($concept ?: 'NULL', 0, 30),
                'category' => mb_substr($cat ?: 'Sin categoría', 0, 28),
                'src'      => mb_substr($src, 0, 14),
                'cnt'      => $r->cnt,
                'total'    => $amt,
                'inEbitda' => $inEbitda,
                'bucket'   => $bucket,
                'note'     => $note,
            ];
        }

        $this->line('');
        $this->line(
            str_pad('Concepto', 32) .
            str_pad('Categoría', 30) .
            str_pad('Fuente', 16) .
            str_pad('Regs', 6) .
            str_pad('Monto', 16) .
            str_pad('En EBITDA', 14) .
            'Nota'
        );
        $this->line(str_repeat('─', 140));

        foreach ($classified as $r) {
            $line = str_pad($r['concept'], 32) .
                    str_pad($r['category'], 30) .
                    str_pad($r['src'], 16) .
                    str_pad(number_format($r['cnt']), 6) .
                    str_pad('$' . number_format($r['total'], 0), 16) .
                    str_pad($r['inEbitda'], 14) .
                    $r['note'];

            if ($r['bucket'] === 'excedentes' || $r['bucket'] === 'fondeo') {
                $this->line($line);
            } elseif ($r['inEbitda'] === 'SÍ') {
                $this->line($line);
            } else {
                $this->warn($line);
            }
        }

        // ── Subtotales por bucket ────────────────────────────────────────────
        $this->line('');
        $this->info('════ SUBTOTALES POR BUCKET ════');
        $this->line('');

        $gastosOp  = $buckets['gastos_op'];
        $nomina    = $buckets['nomina'];
        $exc       = $buckets['excedentes'];
        $fondeo    = $buckets['fondeo'];
        $total     = $gastosOp + $nomina + $exc + $fondeo;

        $this->line(str_pad('Bucket', 42) . str_pad('Monto', 18) . str_pad('Diff vs Ref', 14) . 'En EBITDA?');
        $this->line(str_repeat('─', 85));

        foreach ([
            ['Gastos operativos (en EBITDA)',        $gastosOp,             'SÍ'],
            ['Nómina (NOI+IMSS+Gasolina+Finiquito)', $nomina,               'SÍ (nomina_total)'],
            ['Excedentes/Envío corporativo',         $exc,                  'NO'],
            ['Fondeo/Préstamos Intersucursales',     $fondeo,               'NO'],
            ['TOTAL GENERAL (todos los buckets)',    $total,                '—'],
        ] as [$lbl, $val, $ebitdaNote]) {
            $diff = $val - $ref;
            $sign = $diff >= 0 ? '+' : '';
            $this->line(
                str_pad($lbl, 42) .
                str_pad('$' . number_format($val, 2), 18) .
                str_pad($sign . '$' . number_format($diff, 2), 14) .
                $ebitdaNote
            );
        }

        // ── Hipótesis de qué es la referencia ───────────────────────────────
        $this->line('');
        $this->info('════ HIPÓTESIS: ¿Qué combinación suma la referencia $' . number_format($ref, 2) . '? ════');
        $this->line('');

        // NOI data for hypotheses
        $noiPercep = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('concept', ['P001','P002','P009','P010','P108','P109','P120','P123'])
            ->sum('amount');
        $noiDed = (float) DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('concept', ['D094','D010','D113','D123','D004','D111'])
            ->sum('amount');
        $noiNeta = $noiPercep - abs($noiDed);

        $hypotheses = [
            ['Solo gastos operativos',                                     $gastosOp],
            ['Gastos op + NOI neta',                                       $gastosOp + $noiNeta],
            ['Gastos op + NOI bruta (percepciones)',                       $gastosOp + $noiPercep],
            ['Gastos op + nómina Lendus (cat. "Nómina y Cap Humano")',     $gastosOp + $nomina],
            ['Gastos op + nómina sin IMSS ($' . number_format($gastosOp + $nomina, 0) . ' − IMSS)',
             $gastosOp + $nomina - (float) DB::table('fact_expenses as fe')
                 ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
                 ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
                 ->whereIn('fe.period_id', $dataIds)
                 ->whereRaw("UPPER(COALESCE(fe.concept,'')) LIKE '%IMSS%'")
                 ->selectRaw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as t')
                 ->value('t')],
        ];

        $this->line(str_pad('Hipótesis', 52) . str_pad('Monto', 18) . str_pad('Diff vs Ref', 14) . 'Plausible?');
        $this->line(str_repeat('─', 95));
        foreach ($hypotheses as [$lbl, $val]) {
            $diff     = $val - $ref;
            $sign     = $diff >= 0 ? '+' : '';
            $plaus    = abs($diff) < 100 ? '✓ EXACTO' : (abs($diff) < 10_000 ? '≈ CERCANO' : '');
            $line = str_pad(mb_substr($lbl, 0, 50), 52) .
                    str_pad('$' . number_format($val, 2), 18) .
                    str_pad($sign . '$' . number_format($diff, 2), 14) .
                    $plaus;
            if ($plaus === '✓ EXACTO') {
                $this->info($line);
            } elseif ($plaus === '≈ CERCANO') {
                $this->warn($line);
            } else {
                $this->line($line);
            }
        }

        $this->line('');
        $this->line('  NOI percepciones brutas: $' . number_format($noiPercep, 2));
        $this->line('  NOI deducciones:         −$' . number_format(abs($noiDed), 2));
        $this->line('  NOI neta:                $' . number_format($noiNeta, 2));
        $this->warn('  → Para identificar la referencia exacta: abre el Excel de referencia');
        $this->warn('    e indica qué líneas suman $' . number_format($ref, 2) . '.');
    }

    private function showByCategory(array $dataIds): void
    {
        $cats = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->select(
                'category',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as total'),
                DB::raw('SUM(CASE WHEN branch_id IS NULL THEN COALESCE(NULLIF(paid_amount,0),amount) ELSE 0 END) as sin_suc'),
                DB::raw('SUM(CASE WHEN branch_id IS NOT NULL THEN COALESCE(NULLIF(paid_amount,0),amount) ELSE 0 END) as con_suc')
            )
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Categoría', 36) . str_pad('Regs', 7) . str_pad('Total', 17) .
                    str_pad('Sin Suc', 17) . str_pad('Con Suc', 17) . str_pad('En EBITDA', 11) . 'Fuente');
        $this->line(str_repeat('─', 115));

        $totalEbitda = 0.0;
        $totalNoEbitda = 0.0;

        foreach ($cats as $c) {
            $cat       = $c->category ?? 'Sin categoría';
            $excluded  = in_array($cat, self::EBITDA_EXCLUDED_CATS, true);
            $amt       = (float)$c->total;
            $ebitdaStr = $excluded ? 'NO' : 'SÍ';

            if ($excluded) {
                $totalNoEbitda += $amt;
            } else {
                $totalEbitda += $amt;
            }

            $this->line(
                str_pad(mb_substr($cat, 0, 34), 36) .
                str_pad(number_format($c->cnt), 7) .
                str_pad('$' . number_format($amt, 0), 17) .
                str_pad('$' . number_format((float)$c->sin_suc, 0), 17) .
                str_pad('$' . number_format((float)$c->con_suc, 0), 17) .
                str_pad($ebitdaStr, 11) .
                'fact_expenses'
            );
        }

        $this->line(str_repeat('─', 115));
        $this->line(str_pad('TOTAL EN EBITDA (gastos op)', 44) . '$' . number_format($totalEbitda, 2));
        $this->line(str_pad('TOTAL EXCLUIDO DE EBITDA', 44) . '$' . number_format($totalNoEbitda, 2));
        $this->line(str_pad('TOTAL GENERAL', 44) . '$' . number_format($totalEbitda + $totalNoEbitda, 2));
    }

    private function showByConcept(object $baseQuery, array $dataIds): void
    {
        $rows = (clone $baseQuery)
            ->select(
                'fe.concept',
                'fe.category',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total'),
                DB::raw('COUNT(DISTINCT COALESCE(fe.branch_id,-1)) as num_branches'),
                DB::raw('SUM(CASE WHEN fe.branch_id IS NULL THEN 1 ELSE 0 END) as sin_suc_regs'),
                DB::raw('GROUP_CONCAT(DISTINCT COALESCE(b.name,"Sin suc") ORDER BY b.name SEPARATOR ", ") as branches')
            )
            ->groupBy('fe.concept', 'fe.category')
            ->orderByDesc('total')
            ->limit(40)
            ->get();

        $this->line(str_pad('Concepto', 35) . str_pad('Categoría', 30) . str_pad('Regs', 7) .
                    str_pad('Total', 17) . str_pad('SinSuc', 8) . str_pad('En EBITDA', 10) . 'Sucursales');
        $this->line(str_repeat('─', 125));

        foreach ($rows as $r) {
            $cat      = $r->category ?? 'Sin categoría';
            $excluded = in_array($cat, self::EBITDA_EXCLUDED_CATS, true);
            $ebitdaStr = $excluded ? 'NO' : 'SÍ';
            $branches = mb_substr($r->branches ?? '', 0, 40);
            $sinSuc   = $r->sin_suc_regs > 0 ? "⚠{$r->sin_suc_regs}" : '';

            $this->line(
                str_pad(mb_substr($r->concept ?? 'NULL', 0, 33), 35) .
                str_pad(mb_substr($cat, 0, 28), 30) .
                str_pad(number_format($r->cnt), 7) .
                str_pad('$' . number_format((float)$r->total, 0), 17) .
                str_pad($sinSuc, 8) .
                str_pad($ebitdaStr, 10) .
                $branches
            );
        }
    }

    private function showWithoutBranch(array $dataIds): void
    {
        $sinSuc = DB::table('fact_expenses as fe')
            ->whereIn('fe.period_id', $dataIds)
            ->whereNull('fe.branch_id')
            ->select(
                'fe.category',
                'fe.concept',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total')
            )
            ->groupBy('fe.category', 'fe.concept')
            ->orderByDesc('total')
            ->get();

        if ($sinSuc->isEmpty()) {
            $this->info("  ✓ No hay gastos sin sucursal.");
            return;
        }

        $totalSinSuc = $sinSuc->sum('total');
        $this->warn("  TOTAL gastos sin sucursal: $" . number_format((float)$totalSinSuc, 2));
        $this->line('');
        $this->line(str_pad('Categoría', 32) . str_pad('Concepto', 30) . str_pad('Regs', 7) .
                    str_pad('Monto', 17) . str_pad('En EBITDA', 10) . 'Diagnóstico');
        $this->line(str_repeat('─', 110));

        foreach ($sinSuc as $r) {
            $cat      = $r->category ?? 'Sin categoría';
            $concept  = $r->concept ?? 'NULL';
            $excluded = in_array($cat, self::EBITDA_EXCLUDED_CATS, true);
            $ebitdaStr = $excluded ? 'NO' : 'SÍ';
            $diag     = $this->diagnoseSinSucursal($cat, $concept);

            $this->line(
                str_pad(mb_substr($cat, 0, 30), 32) .
                str_pad(mb_substr($concept, 0, 28), 30) .
                str_pad(number_format($r->cnt), 7) .
                str_pad('$' . number_format((float)$r->total, 0), 17) .
                str_pad($ebitdaStr, 10) .
                $diag
            );
        }

        $this->line('');
        $this->info('── DIAGNÓSTICO CONSOLIDADO ──');
        $this->line('  ¿Por qué hay gastos sin sucursal?');
        $this->line('  1. Gastos corporativos/globales que no pertenecen a ninguna sucursal.');
        $this->line('     → EXCEDENTES, nómina corporativa → NO deben aparecer por sucursal.');
        $this->line('     → Solución: excluir de reportes por sucursal, mantener solo en global.');
        $this->line('  2. Gastos que sí pertenecen a sucursal pero el archivo no identificó el gestor.');
        $this->line('     → Revisar archivo fuente y asignar manualmente o por código de oficina.');
        $this->line('  3. Fondeo / Préstamos Intersucursales → excluir de EBITDA de cualquier sucursal.');
        $this->line('');
        $this->line('  ACCIÓN: Solo los gastos de categoría "Gastos Operativos" sin sucursal');
        $this->line('          deberían revisarse para asignación. El resto son corporativos/globales.');
    }

    private function diagnoseSinSucursal(string $category, string $concept): string
    {
        $catUp = strtoupper($category);
        $conUp = strtoupper($concept);

        if (str_contains($catUp, 'ENVÍO') || str_contains($catUp, 'ENVIO') || str_contains($conUp, 'EXCEDENTES') || str_contains($conUp, 'EXCEDENTE')) {
            return 'Distribución corporativa → NO en EBITDA ni por sucursal';
        }
        if (str_contains($catUp, 'NÓMINA') || str_contains($catUp, 'NOMINA') || str_contains($catUp, 'CAPITAL HUMANO')) {
            return 'Nómina corporativa → en EBITDA global, NO por sucursal';
        }
        if (str_contains($catUp, 'PRÉSTAMOS') || str_contains($catUp, 'PRESTAMOS') || str_contains($catUp, 'INTERSUCURSAL')) {
            return 'Fondeo/préstamo intersucursal → NO en EBITDA';
        }
        if (str_contains($conUp, 'PÓLIZA') || str_contains($conUp, 'POLIZA') || str_contains($conUp, 'SEGURO')) {
            return 'Póliza corporativa → revisar si aplica a sucursal o global';
        }

        return 'Gasto operativo sin asignar → revisar archivo fuente';
    }

    private function analyzeFondeo(array $dataIds): void
    {
        $fondeoRows = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(concept,'')) LIKE '%FONDEO%'")
            ->select(
                'concept', 'category',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as total'),
                DB::raw('COUNT(DISTINCT branch_id) as branches'),
                DB::raw('SUM(CASE WHEN branch_id IS NULL THEN 1 ELSE 0 END) as sin_suc')
            )
            ->groupBy('concept', 'category')
            ->orderByDesc('total')
            ->get();

        if ($fondeoRows->isEmpty()) {
            $this->info("  No se encontraron conceptos FONDEO.");
            return;
        }

        $this->line(str_pad('Concepto', 35) . str_pad('Categoría', 28) . str_pad('Regs', 7) .
                    str_pad('Monto', 17) . 'Suc. / SinSuc');
        $this->line(str_repeat('─', 100));

        $seen = [];
        foreach ($fondeoRows as $r) {
            $this->line(
                str_pad(mb_substr($r->concept ?? 'NULL', 0, 33), 35) .
                str_pad(mb_substr($r->category ?? 'NULL', 0, 26), 28) .
                str_pad(number_format($r->cnt), 7) .
                str_pad('$' . number_format((float)$r->total, 0), 17) .
                "suc={$r->branches} | sinSuc={$r->sin_suc}"
            );
            $seen[$r->concept] = (float)$r->total;
        }

        // Detect exact duplicates by amount
        $this->line('');
        if (count($fondeoRows) > 1) {
            $amounts = $fondeoRows->pluck('total', 'concept')->toArray();
            $unique  = array_unique(array_map(fn($v) => round((float)$v, 2), $amounts));
            if (count($unique) < count($amounts)) {
                $this->warn("  ⚠ DUPLICADO DETECTADO:");
                $this->warn("    'FONDEO A' y 'FONDEO A SUCURSAL' tienen el mismo número de registros");
                $this->warn("    y el mismo monto total. Son la misma fuente contabilizada dos veces.");
                $this->line("    CAUSA: El archivo ERP o de gastos puede tener dos hojas/conceptos");
                $this->line("           que representan el mismo flujo de fondeo.");
                $this->line("    IMPACTO: Préstamos Intersucursales está duplicado en la suma global.");
                $this->line("    FIX: Normalizar ambos conceptos a 'FONDEO A' en el importador,");
                $this->line("         o excluir uno de los dos conceptos del cálculo.");
                $this->line("    Nota: 'Préstamos Intersucursales' está EXCLUIDO de EBITDA,");
                $this->line("          por lo que el duplicado no afecta EBITDA pero SÍ el total de gastos.");
            } else {
                $this->info("  ✓ No se detectó duplicación exacta de montos en FONDEO.");
            }
        }
    }

    private function analyzeExcedentes(array $dataIds): void
    {
        $excRows = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->where(function ($q) {
                $q->whereRaw("UPPER(COALESCE(concept,'')) LIKE '%EXCEDENTE%'")
                  ->orWhereRaw("UPPER(COALESCE(category,'')) LIKE '%UTILIDAD%'")
                  ->orWhereRaw("UPPER(COALESCE(category,'')) LIKE '%CORPORATIVO%'");
            })
            ->select(
                'concept', 'category',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(paid_amount,0),amount)) as total'),
                DB::raw('SUM(CASE WHEN branch_id IS NULL THEN COALESCE(NULLIF(paid_amount,0),amount) ELSE 0 END) as sin_suc_total')
            )
            ->groupBy('concept', 'category')
            ->orderByDesc('total')
            ->get();

        if ($excRows->isEmpty()) {
            $this->info("  No se encontraron conceptos EXCEDENTES/Corporativo.");
            return;
        }

        $this->line(str_pad('Concepto', 35) . str_pad('Categoría', 35) . str_pad('Regs', 7) .
                    str_pad('Monto', 17) . 'Sin Sucursal');
        $this->line(str_repeat('─', 100));

        foreach ($excRows as $r) {
            $this->line(
                str_pad(mb_substr($r->concept ?? 'NULL', 0, 33), 35) .
                str_pad(mb_substr($r->category ?? 'NULL', 0, 33), 35) .
                str_pad(number_format($r->cnt), 7) .
                str_pad('$' . number_format((float)$r->total, 0), 17) .
                '$' . number_format((float)$r->sin_suc_total, 0)
            );
        }

        $this->line('');
        $this->info('── DEFINICIÓN DE EXCEDENTES/ENVÍO CORPORATIVO ──');
        $this->line('  • EXCEDENTES = "Envío de utilidad a corporativo".');
        $this->line('  • Es distribución de utilidades, NO gasto operativo.');
        $this->line('  • NO debe incluirse en EBITDA (ya está en categoría excluida).');
        $this->line('  • NO debe duplicarse: solo debe aparecer en "Distribución corporativa".');
        $this->line('  • Si aparece como concepto Y como categoría separada → duplicado.');
        $this->line('  • Impacto en reporte: solo informativo, nunca suma en EBITDA global.');

        $inEbitda = DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(concept,'')) LIKE '%EXCEDENTE%'")
            ->where('category', '!=', 'Envío de utilidad a corporativo')
            ->count();

        if ($inEbitda > 0) {
            $this->warn("  ⚠ {$inEbitda} filas con concepto EXCEDENTES pero categoría diferente.");
            $this->warn("    → Podrían estar incluyéndose en EBITDA incorrectamente.");
        } else {
            $this->info("  ✓ EXCEDENTES solo aparece en categoría 'Envío de utilidad a corporativo' → correctamente excluido.");
        }
    }

    private function analyzeSourceDuplication(array $dataIds): void
    {
        $this->line('  Comparando gastos por fuente de datos (data_source.code)...');
        $this->line('');

        $bySrc = DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->select(
                'ds.code',
                'ds.name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total'),
                DB::raw('SUM(CASE WHEN fe.category NOT IN ("Nómina y Capital Humano","Envío de utilidad a corporativo","Préstamos Intersucursales") THEN COALESCE(NULLIF(fe.paid_amount,0),fe.amount) ELSE 0 END) as gastos_op'),
                DB::raw('SUM(CASE WHEN fe.category = "Envío de utilidad a corporativo" THEN COALESCE(NULLIF(fe.paid_amount,0),fe.amount) ELSE 0 END) as excedentes'),
                DB::raw('SUM(CASE WHEN fe.category = "Nómina y Capital Humano" THEN COALESCE(NULLIF(fe.paid_amount,0),fe.amount) ELSE 0 END) as nomina_exp')
            )
            ->groupBy('ds.code', 'ds.name')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Fuente (code)', 26) . str_pad('Regs', 7) . str_pad('Total', 17) .
                    str_pad('Gastos Op', 17) . str_pad('Excedentes', 14) . str_pad('Nómina Exp', 14) . 'Descripción');
        $this->line(str_repeat('─', 110));

        foreach ($bySrc as $s) {
            $this->line(
                str_pad($s->code ?? 'NULL', 26) .
                str_pad(number_format($s->cnt), 7) .
                str_pad('$' . number_format((float)$s->total, 0), 17) .
                str_pad('$' . number_format((float)$s->gastos_op, 0), 17) .
                str_pad('$' . number_format((float)$s->excedentes, 0), 14) .
                str_pad('$' . number_format((float)$s->nomina_exp, 0), 14) .
                ($s->name ?? '')
            );
        }

        $this->line('');
        $this->info('════ DIAGNÓSTICO DE DUPLICACIÓN ════');

        // Detect duplicate sources (same row count + similar total)
        $sources = $bySrc->keyBy('code');
        $lendus    = $sources['gastos_lendus'] ?? null;
        $lendusXls = $sources['gastos_lendus_excel'] ?? null;

        if ($lendus && $lendusXls) {
            $cntMatch  = $lendus->cnt === $lendusXls->cnt;
            $totDiff   = abs((float)$lendus->total - (float)$lendusXls->total);
            $isDup     = $cntMatch && $totDiff < 1.0;

            if ($isDup) {
                $this->warn('  ⚠ DUPLICACIÓN CRÍTICA DETECTADA:');
                $this->warn('    gastos_lendus  = ' . number_format($lendus->cnt) . ' filas, $' . number_format((float)$lendus->total, 2));
                $this->warn('    gastos_lendus_excel = ' . number_format($lendusXls->cnt) . ' filas, $' . number_format((float)$lendusXls->total, 2));
                $this->warn('    → MISMO conteo, MISMO total. Son el mismo archivo (PDF vs Excel del mismo reporte).');
                $this->warn('    → Los gastos están siendo SUMADOS DOBLE. Impacto: +$' . number_format((float)$lendus->total, 2) . ' inflado.');
                $this->line('');
                $this->line('  IMPACTO POR CATEGORÍA (gastos_lendus_excel duplicado sobrante):');
                $this->line('    Excedentes/Envío Corp duplicado:  +$' . number_format((float)$lendusXls->excedentes, 0));
                $this->line('    Nómina en gastos duplicada:       +$' . number_format((float)$lendusXls->nomina_exp, 0));
                $this->line('    Gastos operativos duplicados:     +$' . number_format((float)$lendusXls->gastos_op, 0));
                $this->line('');
                $this->line('  REFERENCIA COMPARATIVA:');
                $refGastosOp   = 2_223_315.50;
                $refEnvio      = 3_076_800.00;
                $erp = $sources['gastos_erp'] ?? null;
                $erpTotal = $erp ? (float)$erp->total : 0.0;

                $singleLendus    = (float)$lendus->total;
                $singleTotal     = $singleLendus + $erpTotal;
                $currentTotal    = (float)$bySrc->sum('total');

                $this->line(str_pad('Escenario', 50) . 'Total gastos');
                $this->line(str_repeat('─', 70));
                $this->line(str_pad('ACTUAL (gastos_lendus + gastos_lendus_excel + ERP)', 50) . '$' . number_format($currentTotal, 2));
                $this->line(str_pad('SIN DUPLICADO (solo gastos_lendus + ERP)', 50) . '$' . number_format($singleTotal, 2));
                $this->line(str_pad('SIN DUPLICADO (solo gastos_lendus_excel + ERP)', 50) . '$' . number_format((float)$lendusXls->total + $erpTotal, 2));
                $this->line(str_pad('REFERENCIA (gastos totales aprox)', 50) . '$' . number_format($refGastosOp, 2) . ' (gastos op solamente)');
                $this->line('');
                $this->warn('  → FIX REQUERIDO: Eliminar uno de los dos fuentes (gastos_lendus O gastos_lendus_excel)');
                $this->warn('    del proceso de importación, o implementar deduplicación por código de fuente.');
            } else {
                $this->info('  ✓ gastos_lendus y gastos_lendus_excel difieren en contenido — pueden ser fuentes distintas.');
                $this->line('    gastos_lendus: ' . $lendus->cnt . ' filas / $' . number_format((float)$lendus->total, 2));
                $this->line('    gastos_lendus_excel: ' . $lendusXls->cnt . ' filas / $' . number_format((float)$lendusXls->total, 2));
            }
        }

        // Envío corporativo por fuente
        $this->line('');
        $this->info('════ EXCEDENTES / ENVÍO CORPORATIVO POR FUENTE ════');
        $excSrc = DB::table('fact_expenses as fe')
            ->join('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(fe.concept,'')) LIKE '%EXCEDENTE%'")
            ->select('ds.code', 'fe.category', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total'))
            ->groupBy('ds.code', 'fe.category')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Fuente', 26) . str_pad('Categoría', 38) . str_pad('Regs', 7) . 'Monto');
        $this->line(str_repeat('─', 85));
        $excTotal = 0.0;
        foreach ($excSrc as $r) {
            $this->line(
                str_pad($r->code ?? 'NULL', 26) .
                str_pad($r->category ?? 'NULL', 38) .
                str_pad(number_format($r->cnt), 7) .
                '$' . number_format((float)$r->total, 2)
            );
            if ($r->category === 'Envío de utilidad a corporativo') {
                $excTotal += (float)$r->total;
            }
        }
        $this->line(str_repeat('─', 85));
        $this->line(str_pad('TOTAL categoría Envío corporativo (actual)', 70) . '$' . number_format($excTotal, 2));
        $this->line(str_pad('REFERENCIA abril 2026',                        70) . '$' . number_format(3_076_800.00, 2));
        $this->line(str_pad('Diferencia',                                   70) . '$' . number_format($excTotal - 3_076_800.00, 2));
    }
}
