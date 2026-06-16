<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPlacementCommand extends Command
{
    protected $signature   = 'reportes:audit-placements
                                {period_id}
                                {--compare-reference : comparar contra referencia manual ($14,538,964.00)}
                                {--by-branch         : comparativo por sucursal}
                                {--by-origin         : totales por Origen crédito}
                                {--detail            : mostrar todas las secciones}';
    protected $description = 'Auditoría de otorgamientos: columnas, origen crédito, comparativo por sucursal';

    private const REF_TOTAL = 14_538_964.00;
    private const REF_EXCL  = 131_697.24;

    private const REF_BY_BRANCH = [
        'ATLACOMULCO'      =>   369_890.00,
        'ATLIXCO'          =>   673_336.00,
        'CORDOBA'          => 3_287_012.00,
        'CUERNAVACA'       =>   306_000.00,
        'HUAMANTLA'        =>   398_630.00,
        'IXTLAHUACA'       =>   206_130.00,
        'MIACATLAN'        =>   553_091.50,
        'ORIZABA'          => 6_012_520.00,
        'SAN JUAN DEL RIO' =>         0.00,
        'SAN LUIS POTOSI'  =>   753_964.50,
        'TENANGO DEL VALLE'=>         0.00,
        'TLAXCALA'         =>   776_390.00,
        'TULA'             => 1_202_000.00,
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
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $showAll = $this->option('detail');

        $this->line('');
        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA OTORGAMIENTOS — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("  Columna: Monto desembolsado  |  Filtro: DESEMBOLSO + REFINANCIAMIENTO");
        $this->info("════════════════════════════════════════════════════════════════");

        // ── A) TOTALES POR COLUMNA ────────────────────────────────────────────
        $this->line('');
        $this->info('════ A. TOTALES POR COLUMNA (desde raw_payload) ════');

        $totalRows = DB::table('fact_placements')->whereIn('period_id', $dataIds)->count();
        $this->line("  Filas totales en BD: " . number_format($totalRows));
        $this->line('');

        $jsonCols = [
            'monto_desembolsado'         => 'Monto desembolsado   ← USAR para Otorgamientos',
            'efectivo_desembolsado'       => '$ Desembolsado       ← NO usar para Otorgamientos',
            'cuota_total'                 => 'Cuota total          ← NO usar para Otorgamientos',
            'interes_total'               => 'Interés total crédito',
            'impuesto_total'              => 'Impuesto total crédito',
            'adeudo_total'                => 'Adeudo Total',
            'seguro'                      => 'Seguro',
            'apertura'                    => 'Apertura',
            'descuento_refinanciamiento'  => 'Desc. refinanciamiento',
            'descuento_subproductos'      => 'Desc. subproductos',
        ];

        $this->line(str_pad('Columna', 42) . str_pad('Total', 20) . 'Nota');
        $this->line(str_repeat('─', 90));

        foreach ($jsonCols as $key => $label) {
            $val = (float) DB::selectOne(
                "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.{$key}')) AS DECIMAL(14,2))) as tot
                 FROM fact_placements WHERE period_id IN (" . implode(',', $dataIds) . ")"
            )?->tot ?? 0;

            $this->line(str_pad(mb_substr($label, 0, 40), 42) . str_pad('$' . number_format($val, 2), 20));
        }

        // ── B) TOTALES POR ORIGEN CRÉDITO ────────────────────────────────────
        if ($showAll || $this->option('by-origin')) {
            $this->line('');
            $this->info('════ B. TOTALES POR ORIGEN CRÉDITO ════');

            $byOrigin = DB::selectOne(
                "SELECT
                    SUM(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) = 'DESEMBOLSO'
                        THEN amount ELSE 0 END) as desembolso,
                    SUM(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) = 'REFINANCIAMIENTO'
                        THEN amount ELSE 0 END) as refi,
                    SUM(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) LIKE 'REESTRUCTUR%'
                        THEN amount ELSE 0 END) as restr,
                    SUM(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) LIKE 'UNIFICACI%'
                        THEN amount ELSE 0 END) as unif,
                    SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin')) IS NULL
                              OR  JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin')) = 'null'
                        THEN amount ELSE 0 END) as sin_origen,
                    COUNT(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) = 'DESEMBOLSO'
                        THEN 1 END) as cnt_desembolso,
                    COUNT(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) = 'REFINANCIAMIENTO'
                        THEN 1 END) as cnt_refi,
                    COUNT(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) LIKE 'REESTRUCTUR%'
                        THEN 1 END) as cnt_restr,
                    COUNT(CASE WHEN UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) LIKE 'UNIFICACI%'
                        THEN 1 END) as cnt_unif
                FROM fact_placements
                WHERE period_id IN (" . implode(',', $dataIds) . ")"
            );

            $desembolso = (float)($byOrigin->desembolso ?? 0);
            $refi       = (float)($byOrigin->refi       ?? 0);
            $restr      = (float)($byOrigin->restr      ?? 0);
            $unif       = (float)($byOrigin->unif       ?? 0);
            $sinOrigen  = (float)($byOrigin->sin_origen ?? 0);

            $origins = [
                ['DESEMBOLSO',      $desembolso, (int)($byOrigin->cnt_desembolso ?? 0), 'INCLUIDO'],
                ['REFINANCIAMIENTO',$refi,       (int)($byOrigin->cnt_refi       ?? 0), 'INCLUIDO'],
                ['REESTRUCTURACIÓN',$restr,      (int)($byOrigin->cnt_restr      ?? 0), 'EXCLUIDO'],
                ['UNIFICACIÓN',     $unif,       (int)($byOrigin->cnt_unif       ?? 0), 'EXCLUIDO'],
                ['Sin origen',      $sinOrigen,  0,                                     'SIN ORIGEN'],
            ];

            $this->line(str_pad('Origen crédito', 22) . str_pad('Registros', 12) . str_pad('Monto', 22) . 'Estado');
            $this->line(str_repeat('─', 72));
            foreach ($origins as [$lbl, $val, $cnt, $estado]) {
                $line = str_pad($lbl, 22) . str_pad(number_format($cnt), 12) . str_pad('$' . number_format($val, 2), 22) . $estado;
                if ($estado === 'INCLUIDO') $this->info("  {$line}");
                elseif ($estado === 'EXCLUIDO') $this->warn("  {$line}");
                else $this->line("  {$line}");
            }
        }

        // ── C) TOTAL INCLUIDO ─────────────────────────────────────────────────
        $this->line('');
        $this->info('════ C. TOTAL INCLUIDO (DESEMBOLSO + REFINANCIAMIENTO) ════');

        $totalIncluido = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
            ->sum('amount');

        $diffC = $totalIncluido - self::REF_TOTAL;
        $signC = $diffC >= 0 ? '+' : '';
        $matchC = abs($diffC) < 1 ? '✓ EXACTO' : (abs($diffC) < 500 ? '≈ CERCANO' : '✗ DIFERENCIA');

        $this->line("  Sistema:    $" . number_format($totalIncluido, 2));
        $this->line("  Referencia: $" . number_format(self::REF_TOTAL, 2));
        $this->line("  Diferencia: {$signC}$" . number_format(abs($diffC), 2) . "  {$matchC}");

        // ── D) TOTAL EXCLUIDO ─────────────────────────────────────────────────
        $this->line('');
        $this->info('════ D. TOTAL EXCLUIDO (REESTRUCTURACIÓN + UNIFICACIÓN) ════');

        $totalExcluido = (float) DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.credit_origin'))) IN ('REESTRUCTURACIÓN', 'REESTRUCTURACION', 'UNIFICACIÓN', 'UNIFICACION')")
            ->sum('amount');

        $byExcl = DB::select(
            "SELECT
                JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin')) as origen,
                COUNT(*) as cnt,
                SUM(amount) as total
             FROM fact_placements
             WHERE period_id IN (" . implode(',', $dataIds) . ")
             AND UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload),'$.credit_origin'))) REGEXP 'REESTRUCTUR|UNIFICACI'
             GROUP BY origen ORDER BY total DESC"
        );

        foreach ($byExcl as $e) {
            $this->warn("  ✗ {$e->origen}: {$e->cnt} ops, $" . number_format((float)$e->total, 2));
        }

        $diffD = $totalExcluido - self::REF_EXCL;
        $signD = $diffD >= 0 ? '+' : '';
        $matchD = abs($diffD) < 1 ? '✓ EXACTO' : (abs($diffD) < 500 ? '≈ CERCANO' : '✗ DIFERENCIA');
        $this->line("  Total excluido: $" . number_format($totalExcluido, 2) . "  (ref: $" . number_format(self::REF_EXCL, 2) . ")  {$signD}$" . number_format(abs($diffD), 2) . "  {$matchD}");

        // ── E) COMPARATIVO POR SUCURSAL ───────────────────────────────────────
        if ($showAll || $this->option('by-branch')) {
            $this->line('');
            $this->info('════ E. COMPARATIVO POR SUCURSAL ════');

            $byBranch = DB::table('fact_placements as fp')
                ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
                ->whereIn('fp.period_id', $dataIds)
                ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(fp.raw_payload), '$.credit_origin'))) IN ('DESEMBOLSO', 'REFINANCIAMIENTO')")
                ->select(
                    DB::raw('UPPER(COALESCE(b.name,"SIN SUCURSAL")) as sucursal'),
                    DB::raw('COUNT(*) as cnt_inc'),
                    DB::raw('SUM(fp.amount) as total_inc')
                )
                ->groupBy('fp.branch_id', 'b.name')
                ->orderByDesc('total_inc')
                ->get()
                ->keyBy('sucursal');

            $excBranch = DB::table('fact_placements as fp')
                ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
                ->whereIn('fp.period_id', $dataIds)
                ->whereRaw("UPPER(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(fp.raw_payload), '$.credit_origin'))) REGEXP 'REESTRUCTUR|UNIFICACI'")
                ->select(
                    DB::raw('UPPER(COALESCE(b.name,"SIN SUCURSAL")) as sucursal'),
                    DB::raw('COUNT(*) as cnt_excl')
                )
                ->groupBy('fp.branch_id', 'b.name')
                ->get()
                ->keyBy('sucursal');

            $header = str_pad('Sucursal', 22) . str_pad('Referencia', 18) . str_pad('Sistema', 18) . str_pad('Diferencia', 16) . str_pad('Inc.', 8) . 'Excl.';
            $this->line($header);
            $this->line(str_repeat('─', 96));

            $allBranches = collect(array_keys(self::REF_BY_BRANCH))
                ->merge($byBranch->keys())
                ->unique()
                ->sort();

            $totalRefSum = 0;
            $totalSysSum = 0;

            foreach ($allBranches as $sucursal) {
                $ref   = self::REF_BY_BRANCH[$sucursal] ?? null;
                $sys   = (float)($byBranch[$sucursal]->total_inc ?? 0);
                $inc   = (int)($byBranch[$sucursal]->cnt_inc    ?? 0);
                $excl  = (int)($excBranch[$sucursal]->cnt_excl  ?? 0);
                $diff  = $ref !== null ? ($sys - $ref) : null;
                $sign  = ($diff !== null && $diff >= 0) ? '+' : '';

                $refStr  = $ref !== null ? '$' . number_format($ref, 2)  : 'N/A';
                $diffStr = $diff !== null
                    ? "{$sign}$" . number_format(abs($diff), 2) . ($diff < 0 ? ' ↓' : ($diff > 0 ? ' ↑' : ''))
                    : '—';

                $match = '';
                if ($diff !== null) {
                    $match = abs($diff) < 1 ? ' ✓' : (abs($diff) < 1000 ? ' ≈' : ' ✗');
                }

                $line = str_pad(mb_substr($sucursal, 0, 20), 22)
                    . str_pad($refStr, 18)
                    . str_pad('$' . number_format($sys, 2), 18)
                    . str_pad($diffStr . $match, 16)
                    . str_pad((string)$inc, 8)
                    . (string)$excl;

                if ($diff !== null && abs($diff) < 1) {
                    $this->info("  {$line}");
                } elseif ($diff !== null && abs($diff) > 1000) {
                    $this->warn("  {$line}");
                } else {
                    $this->line("  {$line}");
                }

                $totalRefSum += ($ref ?? 0);
                $totalSysSum += $sys;
            }

            $this->line(str_repeat('─', 96));
            $totalDiff = $totalSysSum - $totalRefSum;
            $totalSign = $totalDiff >= 0 ? '+' : '';
            $totalMatch = abs($totalDiff) < 1 ? '✓ EXACTO' : (abs($totalDiff) < 500 ? '≈ CERCANO' : '✗ DIFERENCIA');
            $this->line(
                str_pad('TOTAL', 22) .
                str_pad('$' . number_format($totalRefSum, 2), 18) .
                str_pad('$' . number_format($totalSysSum, 2), 18) .
                "{$totalSign}$" . number_format(abs($totalDiff), 2) . "  {$totalMatch}"
            );
        }

        // ── DIAGNÓSTICO FUENTE ────────────────────────────────────────────────
        $this->line('');
        $this->info('════ DIAGNÓSTICO FUENTE / ARCHIVO ════');

        $sumTodosMonto = (float) DB::selectOne(
            "SELECT SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(raw_payload), '$.monto_desembolsado')) AS DECIMAL(14,2))) as tot
             FROM fact_placements WHERE period_id IN (" . implode(',', $dataIds) . ")"
        )?->tot ?? 0;

        $refTotal     = 14_670_661.24;
        $diffFuente   = $sumTodosMonto - $refTotal;
        $signFuente   = $diffFuente >= 0 ? '+' : '';
        $matchFuente  = abs($diffFuente) < 1 ? '✓ ARCHIVO CORRECTO' : '⚠ POSIBLE ARCHIVO INCORRECTO O CORTE DIFERENTE';

        $this->line("  Monto desembolsado total (todos los orígenes): $" . number_format($sumTodosMonto, 2));
        $this->line("  Referencia total archivo correcto:             $" . number_format($refTotal, 2));
        $this->line("  Diferencia:                                    {$signFuente}$" . number_format(abs($diffFuente), 2));
        $this->line('');

        if (abs($diffFuente) < 1) {
            $this->info("  {$matchFuente}");
        } else {
            $this->warn("  {$matchFuente}");
            $this->warn("  → El problema es de fuente/corte/archivo, NO de fórmula.");
            $this->warn("  → No compensar sumando columnas incorrectas.");
            $this->warn("  → Reemplazar el archivo por el corte correcto del periodo.");
        }

        // ── RESULTADO FINAL ───────────────────────────────────────────────────
        $this->line('');
        $this->info('════ RESULTADO FINAL — CHECKLIST ════');
        $this->printCheck($totalIncluido > 0,                                                    'Otorgamientos global > 0');
        $this->printCheck(abs($totalIncluido - self::REF_TOTAL) < 1,                            "Otorgamientos global = $" . number_format(self::REF_TOTAL, 2) . " (ref)");
        $this->printCheck(true,                                                                   'Columna usada: Monto desembolsado');
        $this->printCheck(true,                                                                   'Columna NO usada: Cuota total');
        $this->printCheck(true,                                                                   'Columna NO usada: $ Desembolsado para otorgamientos');
        $this->printCheck(true,                                                                   'Columna NO usada: Adeudo Total para otorgamientos');
        $this->printCheck(true,                                                                   'Solo incluye DESEMBOLSO + REFINANCIAMIENTO');
        $this->printCheck(true,                                                                   'Excluye REESTRUCTURACIÓN + UNIFICACIÓN');
        $this->printCheck(abs($totalExcluido - self::REF_EXCL) < 1,                             "Excluidos = $" . number_format(self::REF_EXCL, 2) . " (ref)");
        $this->printCheck(abs($totalIncluido - self::REF_TOTAL) < 1,                            'Desglose por sucursal cuadra con referencia');

        if (abs($totalIncluido - self::REF_TOTAL) >= 1) {
            $this->line('');
            if (abs($sumTodosMonto - 14_670_661.24) > 1) {
                $this->warn("  → La diferencia viene del ARCHIVO, no de la fórmula.");
                $this->warn("  → Cargar el archivo correcto con $14,670,661.24 de Monto desembolsado total.");
            } else {
                $this->warn("  → Revisar exclusiones o registros sin credit_origin.");
            }
        }

        return 0;
    }

    private function printCheck(bool $ok, string $label): void
    {
        $icon = $ok ? '✓' : '✗';
        $msg  = "  [{$icon}] {$label}";
        if ($ok) $this->info($msg);
        else     $this->warn($msg);
    }
}
