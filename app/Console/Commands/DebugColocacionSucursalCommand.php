<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugColocacionSucursalCommand extends Command
{
    protected $signature   = 'reportes:debug-colocacion-sucursal {period_id : ID del periodo} {--branch= : Nombre exacto o parcial de la sucursal}';
    protected $description = 'Colocación de una sucursal: brutos, excluidos, productos, rutas, conclusión.';

    public function __construct(private readonly BranchResolverService $resolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId   = (int) $this->argument('period_id');
        $branchName = (string) ($this->option('branch') ?? '');
        $period     = Period::find($periodId);

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
        $this->info("  DEBUG COLOCACIÓN POR SUCURSAL — Periodo #{$periodId}");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // ── Resolve branch filter ─────────────────────────────────────────────
        if ($branchName === '') {
            return $this->showAllBranches($dataIds, $periodId);
        }

        // Build operative map to understand branch resolution
        $operativeMap = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $this->resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            }
        }

        // Find matching branches (direct name match + routes that resolve to target)
        $targetNorm = $this->accentFold(strtoupper(trim($branchName)));

        // Find DB branch IDs whose resolved name matches (accent-insensitive)
        $matchedBranchIds = [];
        foreach ($operativeMap as $bid => $realBranch) {
            if (str_contains($this->accentFold(strtoupper($realBranch)), $targetNorm)) {
                $matchedBranchIds[] = $bid;
            }
        }

        // Also look up routes that map to target (sub-offices)
        $allBranches = DB::table('branches')->get(['id', 'name']);
        foreach ($allBranches as $b) {
            if (in_array((int) $b->id, $matchedBranchIds, true)) {
                continue;
            }
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if ($real && str_contains($this->accentFold(strtoupper($real)), $targetNorm)) {
                $matchedBranchIds[] = (int) $b->id;
            }
        }

        if (empty($matchedBranchIds)) {
            $this->warn("  No se encontraron branches para: '$branchName'");
            $this->line('  Branches disponibles:');
            foreach ($this->resolver->operativeFinancialBranches() as $name) {
                $this->line("    - $name");
            }
            return self::FAILURE;
        }

        $matchedBranchNames = DB::table('branches')
            ->whereIn('id', $matchedBranchIds)
            ->pluck('name', 'id');

        $this->info("  Sucursal buscada  : $branchName");
        $this->line('  Branches en BD que incluye:');
        foreach ($matchedBranchNames as $bid => $bname) {
            $resolved = $this->resolver->resolveRealBranchFromRoute($bname);
            $this->line(sprintf('    id=%d  %-30s  → %s', $bid, $bname, $resolved ?? '???'));
        }
        $this->line('');

        // ── Determine if this is a closed branch ─────────────────────────────
        $isSJR = str_contains($targetNorm, 'SAN JUAN') || str_contains($targetNorm, 'SJR');
        if ($isSJR) {
            $this->line('  <fg=yellow>⚠ SAN JUAN DEL RÍO — SUCURSAL CERRADA / CARTERA EN RECUPERACIÓN</>');
            $this->line('  Colocación $0 es válida. No se reporta como error.');
            $this->line('');
        }

        // ── 1. Brutos totales ─────────────────────────────────────────────────
        $this->info('── 1. Desembolsos brutos (TODAS las filas, sin filtros) ──');

        $bruto = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $matchedBranchIds)
            ->selectRaw('COUNT(*) as creditos, SUM(amount) as total')
            ->first();

        $this->line(sprintf('  Créditos brutos: %d   Total: $%s',
            (int) ($bruto->creditos ?? 0), number_format((float) ($bruto->total ?? 0), 2)));
        $this->line('');

        // ── 2. Por producto (todos, incluyendo excluidos) ─────────────────────
        $this->info('── 2. Por producto — todos (incluye excluidos) ──');

        $byProduct = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $matchedBranchIds)
            ->selectRaw("COALESCE(product_name,'(sin producto)') as producto, COUNT(*) as creditos, SUM(amount) as total")
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->get();

        if ($byProduct->isEmpty()) {
            $this->line('  <fg=green>(sin desembolsos — sucursal cerrada o sin actividad)</>');
        } else {
            foreach ($byProduct as $r) {
                $isExcluded = $this->resolver->isExcludedProduct($r->producto);
                $tag = $isExcluded ? ' <fg=red>[EXCLUIDO de colocación]</>' : ' <fg=green>[INCLUIDO]</>';
                $this->line(sprintf('  %-35s  %3d créditos  $%s%s',
                    mb_substr($r->producto, 0, 35),
                    $r->creditos, number_format((float) $r->total, 2), $tag));
            }
        }
        $this->line('');

        // ── 3. Desembolsos incluidos en colocación ────────────────────────────
        $this->info('── 3. Desembolsos incluidos en colocación (excluye reestructuras) ──');

        $included = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $matchedBranchIds)
            ->where(function ($q) {
                $q->whereNull('product_name')
                  ->orWhereRaw("product_name NOT REGEXP ?", ['REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS']);
            })
            ->selectRaw('COUNT(*) as creditos, SUM(amount) as colocacion')
            ->first();

        $colocacion = (float) ($included->colocacion ?? 0);
        $this->line(sprintf('  Colocación neta: %d créditos   $%s',
            (int) ($included->creditos ?? 0), number_format($colocacion, 2)));
        $this->line('');

        // ── 4. Desembolsos excluidos ──────────────────────────────────────────
        $this->info('── 4. Desembolsos excluidos (reestructuras/unificaciones/recursos propios) ──');

        $excluded = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $matchedBranchIds)
            ->whereNotNull('product_name')
            ->whereRaw("product_name REGEXP ?", ['REESTRUCTURA|UNIFICACION|UNIFICACIONES|RECURSOS PROPIOS'])
            ->selectRaw("product_name, COUNT(*) as creditos, SUM(amount) as total")
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->get();

        if ($excluded->isEmpty()) {
            $this->line('  (ningún desembolso excluido)');
        } else {
            foreach ($excluded as $r) {
                $this->line(sprintf('  %-35s  %3d créditos  $%s  <fg=red>[excluido]</>',
                    mb_substr($r->product_name, 0, 35),
                    $r->creditos, number_format((float) $r->total, 2)));
            }
        }
        $this->line('');

        // ── 5. Otras rutas/prefijos que podrían tener desembolsos ────────────
        $this->info('── 5. Otros branches (rutas/sucos) con prefix → esta sucursal ──');

        $prefix = $this->getBranchPrefix($targetNorm);
        if ($prefix) {
            $this->line("  Prefix de código acreditado: $prefix");
            $routeBranches = DB::table('branches')->get(['id', 'name']);
            $routeIds = [];
            foreach ($routeBranches as $rb) {
                $rreal = $this->resolver->resolveRealBranchFromRoute($rb->name);
                if ($rreal && str_contains($this->accentFold(strtoupper($rreal)), $targetNorm)
                    && !in_array((int) $rb->id, $matchedBranchIds, true)) {
                    $routeIds[] = (int) $rb->id;
                    $this->line(sprintf('    Branch id=%d "%s" → resuelve a %s', $rb->id, $rb->name, $rreal));
                }
            }

            // Check placements from those extra routes
            if (!empty($routeIds)) {
                $routeColocacion = DB::table('fact_placements')
                    ->whereIn('period_id', $dataIds)
                    ->whereIn('branch_id', $routeIds)
                    ->selectRaw('COUNT(*) as creditos, SUM(amount) as total')
                    ->first();
                $this->line(sprintf('  Desembolsos en rutas adicionales: %d créditos  $%s',
                    (int) ($routeColocacion->creditos ?? 0),
                    number_format((float) ($routeColocacion->total ?? 0), 2)));
            }

            // Check placements with null branch_id where promoter_code starts with prefix
            $nullBranch = DB::table('fact_placements')
                ->whereIn('period_id', $dataIds)
                ->whereNull('branch_id')
                ->whereRaw("UPPER(COALESCE(promoter_code,'')) LIKE ?", [$prefix . '%'])
                ->selectRaw('COUNT(*) as creditos, SUM(amount) as total')
                ->first();
            if ((int) ($nullBranch->creditos ?? 0) > 0) {
                $this->warn(sprintf('  ⚠ Desembolsos con branch_id NULL y promoter_code %s*: %d créditos $%s',
                    $prefix, $nullBranch->creditos, number_format((float) $nullBranch->total, 2)));
            } else {
                $this->line('  OK: sin desembolsos con branch_id NULL y prefix de esta sucursal.');
            }
        }
        $this->line('');

        // ── 6. Cartera y recuperación remanente ───────────────────────────────
        $this->info('── 6. Cartera y recuperación remanente ──');

        $cartera = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $matchedBranchIds)
            ->selectRaw('COUNT(*) as contratos, SUM(balance) as cartera, COUNT(CASE WHEN days_past_due>0 THEN 1 END) as con_mora, SUM(past_due_balance) as mora_total')
            ->first();

        $this->line(sprintf('  Contratos activos : %d', (int) ($cartera->contratos ?? 0)));
        $this->line(sprintf('  Cartera total     : $%s', number_format((float) ($cartera->cartera ?? 0), 2)));
        $this->line(sprintf('  Con mora (DPD>0)  : %d contratos', (int) ($cartera->con_mora ?? 0)));
        $this->line(sprintf('  Mora total        : $%s', number_format((float) ($cartera->mora_total ?? 0), 2)));
        $this->line('');

        $recup = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $matchedBranchIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->selectRaw('COUNT(*) as pagos, SUM(total_amount) as total')
            ->first();

        $this->line(sprintf('  Pagos recibidos   : %d', (int) ($recup->pagos ?? 0)));
        $this->line(sprintf('  Recuperación      : $%s', number_format((float) ($recup->total ?? 0), 2)));
        $this->line('');

        // ── 7. Conclusión ─────────────────────────────────────────────────────
        $this->info('── 7. Conclusión ──');

        if ($isSJR) {
            $hasCartera  = (float) ($cartera->cartera ?? 0) > 0;
            $hasRecupDir = (float) ($recup->total ?? 0) > 0;
            $hasRecupP2  = $sjrPass2Total = $this->getSjrPass2Total($dataIds, $matchedBranchIds) > 0;

            if ($colocacion == 0 && $hasCartera) {
                $this->line('  <fg=green>OK: San Juan del Río se mantiene dentro del GLOBAL como sucursal cerrada</>');
                $this->line('  <fg=green>    con cartera remanente. Colocación nueva = $0 es correcto y esperado.</> ');
                $this->line('  <fg=green>    Su cartera/recuperación/mora sí se incluyen en el GLOBAL.</>');
            } elseif ($colocacion > 0) {
                $this->warn('  ⚠ San Juan del Río tiene colocación nueva — confirmar si la sucursal realmente está cerrada.');
            }
        } else {
            if ($colocacion == 0) {
                $this->warn("  ⚠ $branchName tiene colocación $0. Verificar si es correcto o si hay desembolsos mal mapeados.");
            } else {
                $this->line(sprintf('  <fg=green>OK: Colocación $%s (%d créditos)</>', number_format($colocacion, 2), $included->creditos ?? 0));
            }
        }
        $this->line('');

        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function showAllBranches(array $dataIds, int $periodId): int
    {
        $this->info('── Colocación por sucursal (todas las operativas) ──');

        $rows = DB::table('fact_placements as p')
            ->join('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $dataIds)
            ->selectRaw("b.name as branch, SUM(p.amount) as total_bruto, COUNT(*) as creditos")
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('total_bruto')
            ->get();

        $this->table(
            ['Sucursal BD', 'Créditos brutos', 'Total bruto'],
            $rows->map(fn ($r) => [mb_substr($r->branch, 0, 35), $r->creditos, '$' . number_format((float) $r->total_bruto, 2)])->all()
        );

        $this->line('');
        $this->line("  Usa --branch=\"NOMBRE\" para ver detalle de una sucursal específica.");
        $this->line("  Ejemplo: php artisan reportes:debug-colocacion-sucursal $periodId --branch=\"SAN JUAN DEL RIO\"");
        $this->line('');
        return self::SUCCESS;
    }

    private function getBranchPrefix(string $branchNorm): ?string
    {
        $catalog = $this->resolver->getCatalog();
        foreach ($catalog as $prefix => $name) {
            if (str_contains($this->accentFold(strtoupper($name)), $branchNorm)) {
                return $prefix;
            }
        }
        return null;
    }

    private function accentFold(string $str): string
    {
        return strtr($str, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
            'À' => 'A', 'È' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U',
        ]);
    }

    private function getSjrPass2Total(array $dataIds, array $sjrBranchIds): float
    {
        // Pass2 SJR = PAGO rows where branch NOT in operative, accredited prefix=SJR
        $operativeMap = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $this->resolver->resolveRealBranchFromRoute($b->name);
            if ($real && $this->resolver->isSheetBranch($real)) {
                $operativeMap[(int) $b->id] = $real;
            }
        }
        $operativeIds = array_keys($operativeMap);

        return (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotIn('branch_id', $operativeIds)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.transaction')) = 'PAGO'")
            ->whereRaw("LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.accredited_name')), 3) = 'SJR'")
            ->sum('total_amount');
    }
}
