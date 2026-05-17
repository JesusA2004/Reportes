<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugRecuperacionRawCommand extends Command
{
    protected $signature   = 'reportes:debug-recuperacion-raw {period_id : ID del periodo mensual}';
    protected $description = 'Recuperación fila a fila: bruto en BD → filtro Norte → filtro Corporativo → Pass1+Pass2 → vs target.';

    private const TARGET = 18_323_749.55;

    public function __construct(private readonly BranchResolverService $resolver)
    {
        parent::__construct();
    }

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
        $this->info("  DEBUG RECUPERACIÓN RAW — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('  Target recuperación Abril 2026: $' . number_format(self::TARGET, 2));
        $this->line('');

        // ── 1. Bruto total en BD ───────────────────────────────────────────────
        $this->info('── 1. Bruto total en fact_recoveries ──');
        $bruto = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)
            ->selectRaw("
                JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) as tx,
                COUNT(*) as filas, SUM(total_amount) as total
            ")
            ->groupByRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction'))")
            ->orderByDesc('total')
            ->get();

        $pagoBruto = 0.0;
        foreach ($bruto as $r) {
            $this->line(sprintf('  %-20s : %6d filas  $%s', $r->tx ?: '(null)', $r->filas, number_format((float)$r->total, 2)));
            if (strtoupper(trim($r->tx ?? '')) === 'PAGO') {
                $pagoBruto = (float) $r->total;
            }
        }
        $this->line('');

        // ── 2. Clasificación de PAGO por tipo de sucursal ────────────────────
        $this->info('── 2. PAGO por tipo de sucursal ──');
        $byBranch = DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('b.id, b.name, b.normalized_name, SUM(r.total_amount) as total, COUNT(*) as filas')
            ->groupBy('b.id', 'b.name', 'b.normalized_name')
            ->orderByDesc('total')
            ->get();

        $operativeMap   = [];
        $corporativoIds = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if (!$real) {
                continue;
            }
            if ($this->resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            } elseif (strtoupper(trim($real)) === 'CORPORATIVO') {
                $corporativoIds[] = (int) $b->id;
            }
        }
        $operativeIds = array_keys($operativeMap);

        // ── Pre-compute Pass 2 via accredited_name prefix resolution ─────────
        // (needed to get accurate funnel totals before displaying Section 2)
        $fallback = DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereNotIn('r.branch_id', $operativeIds)
            ->whereNotIn('r.branch_id', $corporativoIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw("
                b.name as branch_name,
                LEFT(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.accredited_name')), 3) as prefix3,
                COUNT(*) as filas,
                SUM(r.total_amount) as total
            ")
            ->groupByRaw("r.branch_id, b.name, LEFT(JSON_UNQUOTE(JSON_EXTRACT(r.raw_payload, '$.accredited_name')), 3)")
            ->orderByDesc('total')
            ->get();

        $pass2Total     = 0.0;
        $pass2Excluded  = 0.0;
        foreach ($fallback as $fb) {
            $prefix3  = strtoupper(trim((string) $fb->prefix3));
            $resolved = $this->resolver->resolveBranchNameFromCode($prefix3);
            if ($resolved && $this->resolver->isSheetBranch($resolved)) {
                $pass2Total += (float) $fb->total;
            } else {
                $pass2Excluded += (float) $fb->total;
            }
        }

        $totOperativo   = 0.0;
        $totNorte       = 0.0;
        $totCorporativo = 0.0;

        $norteNames = ['chihuahua','durango','aguascalientes'];

        foreach ($byBranch as $row) {
            $bid   = (int) $row->id;
            $real  = $this->resolver->resolveRealBranchFromRoute($row->name);
            $upper = strtoupper(trim($real ?? ''));

            if (in_array($bid, $operativeIds, true)) {
                $totOperativo += (float) $row->total;
                $tag = '<fg=green>operativo (Pass1)</>';
            } elseif (in_array($bid, $corporativoIds, true)) {
                $totCorporativo += (float) $row->total;
                $tag = '<fg=cyan>corporativo (excluido)</>';
            } elseif ($real && in_array(strtolower($upper), $norteNames, true)) {
                $totNorte += (float) $row->total;
                $tag = '<fg=red>norte (excluido)</>';
            } else {
                // These are branches NOT in operativeMap — their amounts are
                // split by Pass2 (if prefix resolves) or excluded.
                // We show 'Pass2/excluido' — Pass4 below shows the detail per prefix.
                $tag = '<fg=yellow>Pass2/excluido — ver §4 para detalle</>';
            }

            $this->line(sprintf("  %-38s %s  filas=%d  \$%s",
                mb_substr($row->name, 0, 38),
                $tag,
                $row->filas,
                number_format((float)$row->total, 2)));
        }
        $this->line('');

        // ── 3. Funnel: bruto → filtros → operativo ────────────────────────────
        $this->info('── 3. Funnel PAGO ──');
        $pass1Total = $totOperativo;
        $calcTotal  = $pass1Total + $pass2Total;
        $this->line(sprintf('  PAGO bruto total              : $%s', number_format($pagoBruto, 2)));
        $this->line(sprintf('  - Norte (exclud.)             : -$%s', number_format($totNorte, 2)));
        $this->line(sprintf('  - Corporativo (exclud.)       : -$%s', number_format($totCorporativo, 2)));
        $this->line(sprintf('  - Pass2 excluido (Norte/desc.): -$%s', number_format($pass2Excluded, 2)));
        $this->line(sprintf('  ──────────────────────────────────────────────────────────'));
        $this->line(sprintf('  Pass1 (branch_id operativo)   :  $%s', number_format($pass1Total, 2)));
        $this->line(sprintf('  Pass2 (prefix accred. name)   :  $%s', number_format($pass2Total, 2)));
        $this->line(sprintf('  ──────────────────────────────────────────────────────────'));
        $this->line(sprintf('  CALC TOTAL (Pass1+Pass2)       :  $%s', number_format($calcTotal, 2)));
        $this->line(sprintf('  Target                        :  $%s', number_format(self::TARGET, 2)));
        $diff  = $calcTotal - self::TARGET;
        $pct   = abs($diff / self::TARGET) * 100;
        $color = $pct < 1 ? 'green' : ($pct < 5 ? 'yellow' : 'red');
        $this->line(sprintf("  Diferencia                    :  <fg={$color}>%+.2f (%.2f%%)</>", $diff, $pct));
        $this->line('');

        // ── 4. Pass 2 detail: rutas → sucursal por prefix ────────────────────
        $this->info('── 4. Pass 2 detail — rutas resueltas vía accredited_name prefix ──');

        if ($fallback->isEmpty()) {
            $this->line('  (ninguna fila en Pass2)');
        } else {
            foreach ($fallback as $row) {
                $prefix3  = strtoupper(trim((string) $row->prefix3));
                $resolved = $this->resolver->resolveBranchNameFromCode($prefix3);
                $isSheet  = $resolved ? $this->resolver->isSheetBranch($resolved) : false;
                $color    = $isSheet ? 'green' : 'red';
                $sucursal = $resolved ?? '???';
                $action   = $isSheet ? 'INCLUIDO' : 'EXCLUIDO';
                $this->line(sprintf("  %-35s  prefix=%s → %-22s  [<fg={$color}>%s</>]  filas=%d  \$%s",
                    mb_substr($row->branch_name, 0, 35),
                    str_pad($prefix3, 4),
                    $sucursal,
                    $action,
                    $row->filas,
                    number_format((float)$row->total, 2)));
            }
        }
        $this->line('');

        // ── 5. Gap analysis ────────────────────────────────────────────────────
        $this->info('── 5. Diagnóstico del gap ──');
        $gap = self::TARGET - $calcTotal;
        if ($gap > 0) {
            $this->warn(sprintf('  Falta $%s en recuperación.', number_format($gap, 2)));
            $this->line('  Posibles causas:');
            $this->line('    A) Archivos de cobranza NO importados (semanas adicionales, segundo mensual)');
            $this->line('    B) Registros PAGO en branches con branch_id NULL');
            $this->line('    C) Registros excluidos con transaction type distinto a PAGO');

            // Check for PAGO with null branch
            $nullBranch = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->whereNull('branch_id')
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
                ->selectRaw('COUNT(*) as filas, SUM(total_amount) as total')
                ->first();
            if ((int)($nullBranch->filas ?? 0) > 0) {
                $this->warn(sprintf('  ⚠ PAGO con branch_id NULL: %d filas, $%s', $nullBranch->filas, number_format((float)$nullBranch->total, 2)));
            } else {
                $this->line('  ✓ Sin PAGO con branch_id NULL.');
            }

            // Count uploads for this source
            $uploads = DB::table('report_uploads as ru')
                ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
                ->whereIn('ru.period_id', $dataIds)
                ->where('ds.code', 'lendus_ingresos_cobranza')
                ->select('ru.id', 'ru.original_name', 'ru.uploaded_at')
                ->get();
            $this->line(sprintf('  Archivos de cobranza importados: %d', $uploads->count()));
            foreach ($uploads as $u) {
                $this->line(sprintf('    - Upload #%d: %s (subido %s)', $u->id, $u->original_name ?? '—', substr($u->uploaded_at ?? '—', 0, 10)));
            }
        } else {
            $this->line(sprintf('  Exceso $%s vs target.', number_format(-$gap, 2)));
        }
        $this->line('');

        $this->info('── 6. Desglose capital/interés/impuesto/charges (PAGO operativo, Pass1) ──');
        $pass1Detail = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('SUM(capital) as capital, SUM(interest) as interest, SUM(tax) as tax, SUM(GREATEST(total_amount - capital - interest - tax, 0)) as charges, SUM(total_amount) as total')
            ->first();

        $this->line(sprintf('  Capital    : $%s  (target: $12,883,858.49)', number_format((float)($pass1Detail->capital ?? 0), 2)));
        $this->line(sprintf('  Interés    : $%s  (target: $4,545,211.24)', number_format((float)($pass1Detail->interest ?? 0), 2)));
        $this->line(sprintf('  Impuesto   : $%s  (target: $727,231.40)', number_format((float)($pass1Detail->tax ?? 0), 2)));
        $this->line(sprintf('  Charges    : $%s  (target: $167,448.42)', number_format((float)($pass1Detail->charges ?? 0), 2)));
        $this->line(sprintf('  Total PAGO : $%s', number_format((float)($pass1Detail->total ?? 0), 2)));
        $this->line('');

        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }
}
