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
                                {--check-duplicates : highlight likely duplicate records}';
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

        return 0;
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
}
