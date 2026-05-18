<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugColocacionCommand extends Command
{
    protected $signature   = 'reportes:debug-colocacion {period_id : ID del periodo}';
    protected $description = 'Colocación por sucursal: resumen global, exclusiones, SJR.';

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
        $dataIds    = array_values(array_unique(array_merge($weeklyIds, [$period->id])));

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG COLOCACIÓN — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // Build operative map
        $operativeMap = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $this->resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            }
        }
        $operativeIds = array_keys($operativeMap);

        // ── 1. Totales brutos ─────────────────────────────────────────────────
        $this->info('── 1. Totales brutos (SIN filtros) ──');
        $bruto = DB::table('fact_placements')->whereIn('period_id', $dataIds)
            ->selectRaw('COUNT(*) as creditos, SUM(amount) as total')->first();
        $this->line(sprintf('  Créditos totales: %d   Total: $%s',
            $bruto->creditos, number_format((float) $bruto->total, 2)));
        $this->line('');

        // ── 2. Por sucursal operativa (incluyendo/excluyendo reestructuras) ──
        $this->info('── 2. Colocación por sucursal ──');

        $rows = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->selectRaw("branch_id,
                COUNT(*) as creditos_bruto,
                SUM(amount) as total_bruto,
                SUM(CASE WHEN product_name IS NULL OR product_name NOT REGEXP 'REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS' THEN amount ELSE 0 END) as colocacion_neta,
                SUM(CASE WHEN product_name REGEXP 'REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS' THEN amount ELSE 0 END) as excluido")
            ->groupBy('branch_id')
            ->get();

        $bySuc = [];
        foreach ($this->resolver->operativeFinancialBranches() as $suc) {
            $bySuc[$suc] = ['bruto' => 0.0, 'neta' => 0.0, 'excluido' => 0.0, 'creditos' => 0];
        }
        foreach ($rows as $row) {
            $suc = $operativeMap[(int) $row->branch_id] ?? null;
            if (!$suc || !isset($bySuc[$suc])) {
                continue;
            }
            $bySuc[$suc]['bruto']    += (float) $row->total_bruto;
            $bySuc[$suc]['neta']     += (float) $row->colocacion_neta;
            $bySuc[$suc]['excluido'] += (float) $row->excluido;
            $bySuc[$suc]['creditos'] += (int) $row->creditos_bruto;
        }

        $tableRows = [];
        $globalNeta = 0.0;
        $globalBruto = 0.0;
        $globalExcl = 0.0;
        foreach ($bySuc as $suc => $d) {
            $globalNeta  += $d['neta'];
            $globalBruto += $d['bruto'];
            $globalExcl  += $d['excluido'];
            $nota = '';
            if (strtoupper($suc) === 'SAN JUAN DEL RÍO') {
                $nota = $d['neta'] == 0 ? 'OK: sucursal cerrada, $0' : '⚠ VERIFICAR';
            }
            $tableRows[] = [
                $suc,
                '$' . number_format($d['bruto'], 2),
                '$' . number_format($d['neta'], 2),
                $d['excluido'] > 0 ? '$' . number_format($d['excluido'], 2) : '—',
                $nota,
            ];
        }
        $tableRows[] = ['GLOBAL (SUMA)', '$' . number_format($globalBruto, 2), '$' . number_format($globalNeta, 2), '$' . number_format($globalExcl, 2), ''];

        $this->table(
            ['Sucursal', 'Total bruto', 'Colocación neta', 'Reestructuras excl.', 'Nota'],
            $tableRows
        );

        // ── 3. Comparación vs target ──────────────────────────────────────────
        $target = 14_538_964.00;
        $diff   = $globalNeta - $target;
        $pct    = $target > 0 ? abs($diff / $target) * 100 : 0;

        $this->line('');
        $this->info('── 3. Comparación vs target ──');
        $this->line(sprintf('  Colocación neta calculada: $%s', number_format($globalNeta, 2)));
        $this->line(sprintf('  Target Abril 2026        : $%s', number_format($target, 2)));
        $this->line(sprintf('  Diferencia               : $%s (%.2f%%)', number_format($diff, 2), $pct));
        if (abs($diff) < 1) {
            $this->line('  <fg=green>✓ OK — exacto</>');
        } elseif ($pct < 0.5) {
            $this->line('  <fg=green>✓ CERCA — diferencia < 0.5%</>');
        } elseif ($pct < 2.0) {
            $this->line('  <fg=yellow>~ ACEPTABLE — diferencia < 2%</>');
        } else {
            $this->warn("  ✗ DIF significativa ({$pct}%)");
        }

        // ── 4. SAN JUAN DEL RÍO ──────────────────────────────────────────────
        $this->line('');
        $this->info('── 4. SAN JUAN DEL RÍO — sucursal cerrada ──');
        $sjrData = $bySuc['SAN JUAN DEL RÍO'] ?? [];
        if (($sjrData['neta'] ?? 0) == 0) {
            $this->line('  <fg=green>OK: sucursal cerrada, colocación nueva = $0. Cartera/recuperación/mora sí cuentan en GLOBAL.</>');
        } else {
            $this->warn(sprintf('  ⚠ SJR tiene colocación $%s — confirmar.', number_format($sjrData['neta'] ?? 0, 2)));
        }

        // ── 5. Colocación excluida (Norte/Corp) ───────────────────────────────
        $this->line('');
        $this->info('── 5. Colocación excluida del GLOBAL ──');
        $excl = DB::table('fact_placements as p')
            ->join('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $dataIds)
            ->whereNotIn('p.branch_id', $operativeIds)
            ->where(function ($q) {
                $q->whereNull('p.product_name')
                  ->orWhereRaw("p.product_name NOT REGEXP ?", ['REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS']);
            })
            ->selectRaw('b.name as branch, COUNT(*) as creditos, SUM(p.amount) as total')
            ->groupBy('p.branch_id', 'b.name')
            ->orderByDesc('total')
            ->get();

        if ($excl->isEmpty()) {
            $this->line('  <fg=green>✓ Sin colocación excluida.</>');
        } else {
            foreach ($excl as $r) {
                $this->line(sprintf('  %-40s  %d créditos  $%s', $r->branch, $r->creditos, number_format((float) $r->total, 2)));
            }
        }

        $this->line('');
        $this->line("  Tip: usa php artisan reportes:debug-colocacion-sucursal {$periodId} --branch=\"NOMBRE\" para detalle.");
        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }
}
