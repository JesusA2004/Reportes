<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugGastosSourcesCommand extends Command
{
    protected $signature   = 'reportes:debug-gastos-sources {period_id : ID del periodo mensual}';
    protected $description = 'Gastos: Lendus vs ERP categoría por categoría, deduplicación y totales vs targets Abril 2026.';

    private const TARGET_GASTOS_OP  = 837_384.28;
    private const TARGET_EXCEDENTES = 3_076_800.00;
    private const TARGET_FONDEO     = 449_425.00;

    // Categories present in Lendus → ERP rows with these cats are EXCLUDED to avoid double-count
    private const LENDUS_PRESENT_CATS = [
        'Gastos Operativos',
        'Pólizas',
        'Renta Oficina',
        'Recargas Telefónicas',
        'Gasolina',
        'Agua',
    ];

    private const SKIP_CATS = [
        'Envío de utilidad a corporativo',
        'Préstamos Intersucursales',
        'Nómina y Capital Humano',
    ];

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG GASTOS SOURCES — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // ── Fuentes disponibles ───────────────────────────────────────────────
        $sourceIds = DB::table('data_sources')
            ->whereIn('code', ['gastos_lendus', 'gastos_lendus_excel', 'gastos_erp'])
            ->pluck('id', 'code');

        // gastos_lendus_excel is the same data as gastos_lendus (PDF) — use only PDF for analysis
        // to match what BranchRadiographyCalculator uses (gastos_lendus only).
        $lendusIds = array_values(array_filter([
            $sourceIds['gastos_lendus'] ?? null,
        ]));
        $erpId = $sourceIds['gastos_erp'] ?? null;

        // ── 1. Totales brutos por fuente ─────────────────────────────────────
        $this->info('── 1. Totales brutos por fuente (incluyendo nómina/excedentes/fondeo) ──');
        $bySource = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('ds.code', ['gastos_lendus', 'gastos_lendus_excel', 'gastos_erp'])
            ->selectRaw("ds.code, COUNT(*) as filas, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('ds.id', 'ds.code')
            ->orderByDesc('total')
            ->get();

        foreach ($bySource as $s) {
            $this->line(sprintf('  %-25s : %4d filas  $%s', $s->code, $s->filas, number_format((float)$s->total, 2)));
        }
        $this->line('');

        // ── 2. Lendus: todas las categorías ──────────────────────────────────
        $this->info('── 2. Lendus PDF — todas las categorías (calculador usa solo PDF, no Excel duplicado) ──');
        $lendusCats = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('ru.data_source_id', $lendusIds)
            ->selectRaw("COALESCE(e.category,'(sin cat)') as cat, COUNT(*) as filas, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('e.category')
            ->orderByDesc('total')
            ->get();

        $lendusGastosOp   = 0.0;
        $lendusExcedentes = 0.0;
        $lendusFondeo     = 0.0;
        $lendusNomina     = 0.0;

        foreach ($lendusCats as $r) {
            $cat    = (string) $r->cat;
            $catUp  = mb_strtoupper($cat, 'UTF-8');
            $total  = (float) $r->total;

            if ($cat === 'Envío de utilidad a corporativo' || str_contains($catUp, 'EXCEDENTE')) {
                $tag = ' [excedentes]';
                $lendusExcedentes += $total;
            } elseif ($cat === 'Préstamos Intersucursales' || str_contains($catUp, 'INTERSUCURSAL') || str_contains($catUp, 'FONDEO')) {
                $tag = ' [fondeo]';
                $lendusFondeo += $total;
            } elseif ($cat === 'Nómina y Capital Humano' || str_contains($catUp, 'NOMINA') || str_contains($catUp, 'NÓMINA')) {
                $tag = ' [nómina — omitida en cálc]';
                $lendusNomina += $total;
            } else {
                $tag = '';
                $lendusGastosOp += $total;
            }

            $this->line(sprintf('  %-40s : %4d filas  $%s%s',
                mb_substr($cat, 0, 40), $r->filas, number_format($total, 2), $tag));
        }
        $this->line(sprintf('  → Gastos op de Lendus (sin nóm/exc/fondeo)   : $%s', number_format($lendusGastosOp, 2)));
        $this->line(sprintf('  → Excedentes de Lendus                       : $%s', number_format($lendusExcedentes, 2)));
        $this->line(sprintf('  → Fondeo de Lendus                           : $%s', number_format($lendusFondeo, 2)));
        $this->line(sprintf('  → Nómina Lendus (omitida, usamos NOI)        : $%s', number_format($lendusNomina, 2)));
        $this->line('');

        // ── 3. ERP: todas las categorías con status ───────────────────────────
        $this->info('── 3. ERP — todas las categorías (estado: INCLUIDA o EXCLUIDA por solapamiento) ──');
        $erpCats = [];
        if ($erpId) {
            $erpCats = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ru.data_source_id', $erpId)
                ->selectRaw("COALESCE(e.category,'(sin cat)') as cat, COUNT(*) as filas, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->groupBy('e.category')
                ->orderByDesc('total')
                ->get();
        }

        $erpGastosOp    = 0.0;
        $erpExcedentes  = 0.0;
        $erpFondeo      = 0.0;
        $erpNomina      = 0.0;
        $erpExcluido    = 0.0;

        foreach ($erpCats as $r) {
            $cat   = (string) $r->cat;
            $catUp = mb_strtoupper($cat, 'UTF-8');
            $total = (float) $r->total;

            $isNomina = in_array($cat, self::SKIP_CATS, true)
                     || str_contains($catUp, 'NOMINA') || str_contains($catUp, 'NÓMINA');

            $isLendusOverlap = in_array($cat, self::LENDUS_PRESENT_CATS, true);
            $isSkip = $isNomina
                   || str_contains($catUp, 'EXCEDENTE')
                   || str_contains($catUp, 'INTERSUCURSAL') || str_contains($catUp, 'FONDEO');

            if ($isLendusOverlap) {
                $tag = '<fg=red> [EXCLUIDA — ya en Lendus]</>';
                $erpExcluido += $total;
            } elseif ($isSkip) {
                if ($isNomina) {
                    $tag = '<fg=yellow> [nómina — omitida]</>';
                    $erpNomina += $total;
                } elseif (str_contains($catUp, 'EXCEDENTE')) {
                    $tag = ' [excedentes]';
                    $erpExcedentes += $total;
                } elseif (str_contains($catUp, 'INTERSUCURSAL') || str_contains($catUp, 'FONDEO')) {
                    $tag = ' [fondeo]';
                    $erpFondeo += $total;
                } else {
                    $tag = ' [skip]';
                }
            } else {
                $tag = '<fg=green> [INCLUIDA — solo en ERP]</>';
                $erpGastosOp += $total;
            }

            $this->line(sprintf('  %-40s : %4d filas  $%s%s',
                mb_substr($cat, 0, 40), $r->filas, number_format($total, 2), $tag));
        }
        $this->line(sprintf('  → Gastos op de ERP (no solapados)            : $%s', number_format($erpGastosOp, 2)));
        $this->line(sprintf('  → ERP excluido por solapamiento con Lendus   : $%s', number_format($erpExcluido, 2)));
        $this->line('');

        // ── 4. Resumen combinado ──────────────────────────────────────────────
        $this->info('── 4. Resumen — deduplicado final ──');
        $totalGastosOp   = $lendusGastosOp + $erpGastosOp;
        $totalExcedentes = $lendusExcedentes + $erpExcedentes;
        $totalFondeo     = $lendusFondeo + $erpFondeo;

        $this->printDiff('Gastos operativos (Lendus+ERP dedupl.)', $totalGastosOp,   self::TARGET_GASTOS_OP);
        $this->printDiff('Excedentes (utilidad corp.)',              $totalExcedentes, self::TARGET_EXCEDENTES);
        $this->printDiff('Fondeo (préstamos intersuc.)',             $totalFondeo,     self::TARGET_FONDEO);
        $this->line('');

        // ── 5. Solapamiento Lendus/ERP en categorías compartidas ─────────────
        $this->info('── 5. Solapamiento — montos Lendus vs ERP para categorías compartidas ──');
        foreach (self::LENDUS_PRESENT_CATS as $cat) {
            $lAmt = DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->whereIn('ru.data_source_id', $lendusIds)
                ->where('e.category', $cat)
                ->selectRaw("SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->value('total');
            $eAmt = $erpId ? DB::table('fact_expenses as e')
                ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->whereIn('e.period_id', $dataIds)
                ->where('ru.data_source_id', $erpId)
                ->where('e.category', $cat)
                ->selectRaw("SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
                ->value('total') : 0;

            $this->line(sprintf('  %-35s  Lendus=$%-12s  ERP=$%-12s  (ERP excluido)',
                mb_substr($cat, 0, 35),
                number_format((float)($lAmt ?? 0), 2),
                number_format((float)($eAmt ?? 0), 2)));
        }
        $this->line('');

        // ── 6. Gastos op por sucursal (deduplicado) ───────────────────────────
        $this->info('── 6. Gastos operativos por sucursal (deduplicado) ──');
        $lendusRows = $lendusIds ? DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->whereIn('ru.data_source_id', $lendusIds)
            ->whereNotIn('e.category', array_merge(self::SKIP_CATS, ['']))
            ->selectRaw("b.name as sucursal, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('b.id', 'b.name')
            ->get()
            ->keyBy('sucursal') : collect();

        $erpRows = $erpId ? DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('branches as b', 'e.branch_id', '=', 'b.id')
            ->whereIn('e.period_id', $dataIds)
            ->where('ru.data_source_id', $erpId)
            ->whereNotIn('e.category', array_merge(self::LENDUS_PRESENT_CATS, self::SKIP_CATS))
            ->selectRaw("b.name as sucursal, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('b.id', 'b.name')
            ->get()
            ->keyBy('sucursal') : collect();

        $allBranches = array_unique(array_merge(
            $lendusRows->keys()->all(),
            $erpRows->keys()->all()
        ));
        sort($allBranches);

        $sucRows = [];
        foreach ($allBranches as $suc) {
            $l = (float)($lendusRows->get($suc)?->total ?? 0);
            $e = (float)($erpRows->get($suc)?->total ?? 0);
            $sucRows[] = [mb_substr($suc, 0, 30), '$' . number_format($l, 2), '$' . number_format($e, 2), '$' . number_format($l + $e, 2)];
        }
        $this->table(['Sucursal', 'Lendus', 'ERP (excl. solapados)', 'Total'], $sucRows);
        $this->line('');

        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function printDiff(string $label, float $calc, float $target): void
    {
        $diff  = $calc - $target;
        $pct   = $target > 0 ? abs($diff / $target) * 100 : 0;
        $color = $pct < 1 ? 'green' : ($pct < 5 ? 'yellow' : 'red');
        $this->line(sprintf("  %-38s  calc=\$%-13s  tgt=\$%-13s  <fg={$color}>dif=%+.2f (%.2f%%)</>",
            $label,
            number_format($calc, 2),
            number_format($target, 2),
            $diff, $pct));
    }
}
