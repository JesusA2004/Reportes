<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\EmployeeNameCanonicalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugEmpleadosCommand extends Command
{
    protected $signature = 'reportes:debug-empleados
                            {periodId : ID del periodo}
                            {--name= : Filtrar por fragmento de nombre (ej. "MARGARITA")}';

    protected $description = 'Diagnóstico de normalización, merge fuzzy y datos consolidados de empleados/gestores.';

    public function __construct(private readonly EmployeeNameCanonicalizer $canonicalizer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId  = (int) $this->argument('periodId');
        $nameFilter = strtoupper(trim($this->option('name') ?? ''));

        $period = Period::find($periodId);
        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        // Resolve weekly data IDs (same logic as SnapshotBuilder)
        $allPeriods = Period::all();
        $dataIds    = $period->resolveBaseWeeklyIds($allPeriods);
        if (empty($dataIds)) {
            $dataIds = [$period->id];
        }
        $dataIds = array_values(array_unique(array_merge($dataIds, [$period->id])));

        $this->line('');
        $this->info("══════════ DEBUG EMPLEADOS — {$period->label} (ID #{$periodId}) ══════════");
        $this->line("Data IDs usados: " . implode(', ', $dataIds));
        $this->line('');

        if ($nameFilter) {
            $this->debugByName($periodId, $dataIds, $nameFilter);
        } else {
            $this->debugGeneral($periodId, $dataIds);
        }

        return self::SUCCESS;
    }

    // ── DETALLE POR NOMBRE ───────────────────────────────────────────────────

    private function debugByName(int $periodId, array $dataIds, string $nameFilter): void
    {
        $this->info("── Buscando: \"{$nameFilter}\" ──");
        $this->line('');

        // 1. NOI
        $this->info('[NOI] Movimientos en fact_noi_movements:');
        $noiRows = DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereRaw('UPPER(e.full_name) LIKE ?', ["%{$nameFilter}%"])
            ->selectRaw("e.id, e.full_name, e.normalized_name, e.employee_code,
                SUM(n.amount) as total,
                SUM(CASE WHEN LOWER(COALESCE(n.concept_type,''))='percepcion' AND LOWER(COALESCE(n.concept,'')) NOT LIKE '%bono%' THEN n.amount
                         WHEN LOWER(COALESCE(n.concept,'')) LIKE '%comisi%' THEN n.amount ELSE 0 END) as pagos,
                COUNT(*) as movs")
            ->groupBy('e.id', 'e.full_name', 'e.normalized_name', 'e.employee_code')
            ->get();

        if ($noiRows->isEmpty()) {
            $this->warn('  Sin registros en NOI.');
        } else {
            foreach ($noiRows as $r) {
                $norm = $this->canonicalizer->normalize($r->full_name);
                $this->line(sprintf('  [%s] full_name="%s"', $r->employee_code ?? '—', $r->full_name));
                $this->line(sprintf('       normalized_name (DB)=%s', $r->normalized_name));
                $this->line(sprintf('       normalized_name (nuevo)=%s', $norm));
                $this->line(sprintf('       pagos=$%s  movs=%d',
                    number_format((float)$r->pagos, 2), $r->movs));

                // Branch assignment
                $eba = DB::table('employee_branch_assignments as eba')
                    ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
                    ->where('eba.employee_id', $r->id)
                    ->where('eba.period_id', $periodId)
                    ->select('b.name as branch', 'eba.match_type', 'eba.confidence')
                    ->first();
                if ($eba) {
                    $this->line(sprintf('       sucursal=%s  match=%s  confidence=%s',
                        $eba->branch ?? 'NULL', $eba->match_type ?? '—',
                        $eba->confidence !== null ? number_format((float)$eba->confidence, 2) : '—'));
                } else {
                    $this->warn('       SIN asignación de sucursal.');
                }
                $this->line('');
            }
        }

        // 2. Lendus Ministraciones / colocación
        $this->info('[MINISTRACIONES] fact_placements:');
        $placRows = DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $dataIds)
            ->whereRaw('UPPER(COALESCE(p.promoter_name, p.promoter_code)) LIKE ?', ["%{$nameFilter}%"])
            ->selectRaw("p.promoter_name, p.normalized_promoter_name, b.name as sucursal,
                COUNT(*) as ops, SUM(p.amount) as colocacion")
            ->groupBy('p.promoter_name', 'p.normalized_promoter_name', 'b.name')
            ->get();

        if ($placRows->isEmpty()) {
            $this->line('  Sin registros en ministraciones.');
        } else {
            foreach ($placRows as $r) {
                $norm = $this->canonicalizer->normalize($r->promoter_name ?? '');
                $this->line(sprintf('  promoter_name="%s"', $r->promoter_name));
                $this->line(sprintf('    normalized (DB)=%s', $r->normalized_promoter_name));
                $this->line(sprintf('    normalized (nuevo)=%s', $norm));
                $this->line(sprintf('    sucursal=%s  ops=%d  colocacion=$%s',
                    $r->sucursal ?? 'NULL', $r->ops, number_format((float)$r->colocacion, 2)));
                $this->line('');
            }
        }

        // 3. Cartera
        $this->info('[CARTERA] fact_portfolios:');
        $portRows = DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $dataIds)
            ->whereRaw('UPPER(COALESCE(po.promoter_name, po.promoter_code)) LIKE ?', ["%{$nameFilter}%"])
            ->selectRaw("COALESCE(po.promoter_name, po.promoter_code) as nombre, b.name as sucursal,
                SUM(po.balance) as cartera,
                SUM(CASE WHEN po.days_past_due>0 THEN po.balance ELSE 0 END) as vencida,
                COUNT(*) as contratos")
            ->groupBy('po.promoter_name', 'po.promoter_code', 'b.name')
            ->get();

        if ($portRows->isEmpty()) {
            $this->line('  Sin registros en cartera.');
        } else {
            foreach ($portRows as $r) {
                $norm = $this->canonicalizer->normalize($r->nombre ?? '');
                $this->line(sprintf('  nombre="%s"', $r->nombre));
                $this->line(sprintf('    normalized (nuevo)=%s', $norm));
                $this->line(sprintf('    sucursal=%s  cartera=$%s  vencida=$%s  contratos=%d',
                    $r->sucursal ?? 'NULL',
                    number_format((float)$r->cartera, 2),
                    number_format((float)$r->vencida, 2),
                    $r->contratos));
                $this->line('');
            }
        }

        // 4. Recuperación
        $this->info('[RECUPERACION] fact_recoveries:');
        $recRows = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereRaw('UPPER(promoter_name) LIKE ?', ["%{$nameFilter}%"])
            ->selectRaw('promoter_name, SUM(total_amount) as recuperacion')
            ->groupBy('promoter_name')
            ->get();

        if ($recRows->isEmpty()) {
            $this->line('  Sin registros en recuperación.');
        } else {
            foreach ($recRows as $r) {
                $norm = $this->canonicalizer->normalize($r->promoter_name ?? '');
                $this->line(sprintf('  promoter_name="%s"  norm=%s  recuperacion=$%s',
                    $r->promoter_name,
                    $norm,
                    number_format((float)$r->recuperacion, 2)));
            }
        }
        $this->line('');

        // 5. Similitud entre todos los nombres encontrados
        $allNames = collect()
            ->merge($noiRows->pluck('full_name'))
            ->merge($placRows->pluck('promoter_name'))
            ->merge($portRows->pluck('nombre'))
            ->merge($recRows->pluck('promoter_name'))
            ->filter()->unique()->values();

        $this->info('[SIMILITUD] Comparación de variantes encontradas:');
        if ($allNames->count() < 2) {
            $this->line('  Solo se encontró una variante de nombre, no hay comparación necesaria.');
        } else {
            $normalized = $allNames->map(fn($n) => ['original' => $n, 'norm' => $this->canonicalizer->normalize($n)])->values();
            foreach ($normalized as $idxA => $a) {
                foreach ($normalized as $idxB => $b) {
                    if ($idxB <= $idxA) continue;
                    $lev   = levenshtein($a['norm'], $b['norm']);
                    $tokA  = array_filter(explode(' ', $a['norm']), fn($t) => strlen($t) >= 3);
                    $tokB  = array_filter(explode(' ', $b['norm']), fn($t) => strlen($t) >= 3);
                    $common = count(array_intersect($tokA, $tokB));
                    $merge  = $lev <= 2 && $common >= 3;
                    $status = $merge ? '<fg=green>FUSIONABLE</>' : '<fg=red>NO fusionar</>';
                    $this->line(sprintf(
                        '  "%s" ↔ "%s"',
                        mb_substr($a['original'], 0, 35),
                        mb_substr($b['original'], 0, 35)
                    ));
                    $this->line(sprintf(
                        '    levenshtein=%d  tokens_comunes=%d  resultado=%s',
                        $lev, $common, $status
                    ));
                }
            }
        }
        $this->line('');
        $this->info('══════════ FIN DETALLE ══════════');
    }

    // ── DIAGNÓSTICO GENERAL ──────────────────────────────────────────────────

    private function debugGeneral(int $periodId, array $dataIds): void
    {
        // 1. Summary table
        $this->info('── 1. fact_period_employee_summary ──');
        $total       = DB::table('fact_period_employee_summary')->where('period_id', $periodId)->count();
        $conSucursal = DB::table('fact_period_employee_summary')->where('period_id', $periodId)->whereNotNull('branch_id')->count();
        $sinSucursal = $total - $conSucursal;

        $this->line(sprintf('  Total:          %d', $total));
        $this->line(sprintf('  Con sucursal:   %s', $conSucursal > 0 ? "<fg=green>{$conSucursal}</>" : '<fg=red>0</>'));
        $this->line(sprintf('  Sin sucursal:   %s', $sinSucursal === 0 ? '<fg=green>0</>' : "<fg=red>{$sinSucursal}</>"));
        $this->line('');

        // 2. Employees without branch
        $sinRows = DB::table('fact_period_employee_summary as s')
            ->join('employees as e', 's.employee_id', '=', 'e.id')
            ->where('s.period_id', $periodId)
            ->whereNull('s.branch_id')
            ->selectRaw('e.full_name, e.employee_code, s.total_payments')
            ->orderByDesc('s.total_payments')
            ->limit(20)
            ->get();

        if ($sinRows->isNotEmpty()) {
            $this->info('── 2. Empleados sin sucursal (top 20) ──');
            foreach ($sinRows as $r) {
                $this->line(sprintf('  [%s] %-45s $%s',
                    $r->employee_code ?? '—',
                    mb_substr($r->full_name, 0, 45),
                    number_format((float)$r->total_payments, 2)));
            }
            $this->line('');
        }

        // 3. Fuzzy merge candidates (names with levenshtein distance ≤ 2 and ≥ 3 common tokens)
        $this->info('── 3. Candidatos a fusión por similitud de nombre ──');
        $allNormNames = $this->collectAllNormalizedNames($dataIds);
        $normKeys     = array_keys($allNormNames);
        $mergeMap     = $this->canonicalizer->buildCanonicalMap($normKeys);
        $aliases      = array_filter($mergeMap, fn ($canonical, $key) => $key !== $canonical, ARRAY_FILTER_USE_BOTH);

        if (empty($aliases)) {
            $this->line('  <fg=green>Sin candidatos a fusión detectados.</>');
        } else {
            foreach ($aliases as $alias => $canonical) {
                $explain = $this->canonicalizer->explainMerge($alias, $canonical);
                $this->line(sprintf('  "%s" → "%s"  lev=%d  tokens=%d',
                    $allNormNames[$alias] ?? $alias,
                    $allNormNames[$canonical] ?? $canonical,
                    $explain['levenshtein'],
                    $explain['common_tokens']));
            }
        }
        $this->line('');

        // 4. By branch breakdown
        $this->info('── 4. Resumen por sucursal (fact_period_employee_summary) ──');
        $bySucursal = DB::table('fact_period_employee_summary as s')
            ->join('branches as b', 's.branch_id', '=', 'b.id')
            ->where('s.period_id', $periodId)
            ->selectRaw('b.name as sucursal, COUNT(*) as empleados, SUM(s.total_payments) as nomina')
            ->groupBy('b.id', 'b.name')
            ->orderByDesc('nomina')
            ->get();

        foreach ($bySucursal as $b) {
            $this->line(sprintf('  %-30s empleados=%-4d nómina=$%s',
                $b->sucursal, $b->empleados, number_format((float)$b->nomina, 2)));
        }
        $this->line('');
        $this->info('══════════ FIN DEBUG EMPLEADOS ══════════');
    }

    // ── HELPERS ─────────────────────────────────────────────────────────────

    private function collectAllNormalizedNames(array $dataIds): array
    {
        $names = [];

        DB::table('employees as e')
            ->join('fact_noi_movements as n', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $dataIds)
            ->distinct('e.full_name')
            ->pluck('e.full_name')
            ->each(fn($n) => $names[$this->canonicalizer->normalize($n)] = $n);

        DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('promoter_name')
            ->distinct('promoter_name')
            ->pluck('promoter_name')
            ->each(fn($n) => $names[$this->canonicalizer->normalize($n)] ??= $n);

        DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('promoter_name')
            ->distinct('promoter_name')
            ->pluck('promoter_name')
            ->each(fn($n) => $names[$this->canonicalizer->normalize($n)] ??= $n);

        DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('promoter_name')
            ->distinct('promoter_name')
            ->pluck('promoter_name')
            ->each(fn($n) => $names[$this->canonicalizer->normalize($n)] ??= $n);

        return $names; // normalized => original
    }

}
