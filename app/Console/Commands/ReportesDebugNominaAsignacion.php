<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\EmployeeNameCanonicalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportesDebugNominaAsignacion extends Command
{
    protected $signature = 'reportes:debug-nomina-asignacion {period_id : ID del periodo mensual}';
    protected $description = 'Diagnóstico de asignación de nómina NOI por sucursal: muestra SIN ASIGNAR y por qué.';

    public function __construct(
        private readonly EmployeeNameCanonicalizer $canonicalizer,
        private readonly BranchResolverService     $resolver,
    ) {
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
        $this->info("══════ DEBUG NÓMINA ASIGNACIÓN — {$period->label} (ID #{$periodId}) ══════");
        $this->line('Data IDs: ' . implode(', ', $dataIds));
        $this->line('');

        // ── 1. Totales generales ────────────────────────────────────────────
        $this->info('── 1. Totales NOI');
        $totNoi = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->selectRaw('COUNT(DISTINCT employee_id) as empleados, SUM(amount) as total')
            ->first();

        $this->line(sprintf('   Empleados distintos : %d', (int)($totNoi?->empleados ?? 0)));
        $this->line(sprintf('   Total nómina NOI    : $%s', number_format((float)($totNoi?->total ?? 0), 2)));
        $this->line('');

        // ── 2. Con EBA (asignación directa) ────────────────────────────────
        $this->info('── 2. Empleados CON asignación de sucursal (EBA)');
        $withEba = DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('eba.period_id', $dataIds)
            ->selectRaw('b.name as branch, COUNT(DISTINCT eba.employee_id) as cnt')
            ->groupBy('b.name')
            ->orderByDesc('cnt')
            ->get();

        $totalWithEba = $withEba->sum('cnt');
        foreach ($withEba as $row) {
            $this->line(sprintf('   %-30s %d empleados', $row->branch, $row->cnt));
        }
        $this->line(sprintf('   TOTAL con EBA: %d', $totalWithEba));
        $this->line('');

        // ── 3. Sin EBA ─────────────────────────────────────────────────────
        $ebaIds = DB::table('employee_branch_assignments')
            ->whereIn('period_id', $dataIds)
            ->pluck('employee_id')
            ->unique()
            ->all();

        $noiAllIds = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->all();

        $sinEbaIds = array_values(array_diff($noiAllIds, $ebaIds));

        $this->info('── 3. Sin EBA → fallback a activity lookup');
        $this->line(sprintf('   Empleados sin EBA: %d', count($sinEbaIds)));
        $this->line('');

        // ── 4. Activity lookup ─────────────────────────────────────────────
        $activityMap = $this->buildActivityLookup($dataIds);
        $this->line(sprintf('   Activity lookup tiene %d entradas (nombres normalizados con sucursal)', count($activityMap)));
        $this->line('');

        // ── 5. Resolver vía canonical merge ────────────────────────────────
        if (!empty($sinEbaIds)) {
            $this->info('── 4. Resolución por alias + actividad (sin EBA)');

            $sinEbaRows = DB::table('employees')
                ->whereIn('id', $sinEbaIds)
                ->select('id', 'full_name', 'normalized_name', 'employee_code')
                ->get();

            $allNormNames   = $sinEbaRows->map(fn ($r) => $this->canonicalizer->normalize($r->full_name ?? ''))->all();
            $activityNorms  = array_keys($activityMap);
            $canonicalMap   = $this->canonicalizer->buildCanonicalMap(
                array_values(array_unique(array_merge($allNormNames, $activityNorms))),
                $allNormNames
            );

            $resolvedCount  = 0;
            $stillSin       = [];

            foreach ($sinEbaRows as $emp) {
                $norm      = $this->canonicalizer->normalize($emp->full_name ?? '');
                $canonical = $canonicalMap[$norm] ?? $norm;

                $branch = $activityMap[$norm] ?? $activityMap[$canonical] ?? null;
                if (!$branch) {
                    foreach ($canonicalMap as $alias => $can) {
                        if ($can === $canonical && isset($activityMap[$alias])) {
                            $branch = $activityMap[$alias];
                            break;
                        }
                    }
                }

                $realBranch = $branch ? ($this->resolver->resolveRealBranchFromRoute($branch) ?? null) : null;

                if ($realBranch) {
                    $resolvedCount++;
                } else {
                    // Get payroll total for this employee
                    $noiTotal = (float) DB::table('fact_noi_movements')
                        ->whereIn('period_id', $dataIds)
                        ->where('employee_id', $emp->id)
                        ->sum('amount');

                    $stillSin[] = [
                        'id'        => $emp->id,
                        'nombre'    => $emp->full_name,
                        'codigo'    => $emp->employee_code,
                        'norm'      => $norm,
                        'canonical' => $canonical,
                        'total'     => $noiTotal,
                        'en_alias'  => ($canonical !== $norm) ? 'SÍ → ' . $canonical : 'NO',
                    ];
                }
            }

            $this->line(sprintf('   Resueltos via activity : %d', $resolvedCount));
            $this->line(sprintf('   Aún sin resolver       : %d', count($stillSin)));
            $this->line('');

            // ── 6. Top SIN ASIGNAR ─────────────────────────────────────────
            if (!empty($stillSin)) {
                usort($stillSin, fn ($a, $b) => $b['total'] <=> $a['total']);
                $top = array_slice($stillSin, 0, 30);

                $this->info('── 5. Top empleados aún SIN ASIGNAR (máx 30)');
                $this->table(
                    ['#', 'Código', 'Nombre NOI', 'Normalizado', 'Canónico', 'Total', 'Alias'],
                    array_map(fn ($idx, $r) => [
                        $idx + 1,
                        $r['codigo'] ?? '—',
                        mb_substr($r['nombre'], 0, 40),
                        mb_substr($r['norm'], 0, 40),
                        mb_substr($r['canonical'], 0, 40),
                        '$' . number_format($r['total'], 2),
                        $r['en_alias'],
                    ], array_keys($top), $top)
                );

                $totalSinTotal = array_sum(array_column($stillSin, 'total'));
                $this->line(sprintf('   TOTAL nómina SIN ASIGNAR: $%s', number_format($totalSinTotal, 2)));
                $this->line('');
            }
        }

        // ── 6. Asignados por sucursal (EBA) ────────────────────────────────
        $this->info('── 6. Nómina asignada por sucursal (EBA exacta)');
        $byBranchNomina = DB::table('fact_noi_movements as n')
            ->join('employee_branch_assignments as eba', function ($j) use ($dataIds) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                  ->whereIn('eba.period_id', $dataIds);
            })
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('n.period_id', $dataIds)
            ->selectRaw('b.name as branch, SUM(n.amount) as total')
            ->groupBy('b.name')
            ->orderByDesc('total')
            ->get();

        foreach ($byBranchNomina as $row) {
            $this->line(sprintf('   %-30s $%s', $row->branch, number_format((float)$row->total, 2)));
        }
        $this->line('');

        // ── 7. Alias fusionados ────────────────────────────────────────────
        $this->info('── 7. Alias fusionados detectados (cross-fuente)');
        $allNameCandidates = $this->collectAllNames($dataIds);
        $allNormKeys       = array_keys($allNameCandidates);
        $mergeMap          = $this->canonicalizer->buildCanonicalMap($allNormKeys);
        $aliases           = array_filter($mergeMap, fn ($canonical, $key) => $key !== $canonical, ARRAY_FILTER_USE_BOTH);

        if (empty($aliases)) {
            $this->line('   <fg=green>Sin alias fusionados detectados.</>');
        } else {
            foreach ($aliases as $alias => $canonical) {
                $this->line(sprintf('   "%s" → "%s"',
                    mb_substr($allNameCandidates[$alias] ?? $alias, 0, 45),
                    mb_substr($allNameCandidates[$canonical] ?? $canonical, 0, 45)));
            }
        }
        $this->line('');

        $this->info('══════ FIN DEBUG NÓMINA ══════');
        return self::SUCCESS;
    }

    private function buildActivityLookup(array $dataIds): array
    {
        $lookup = [];

        // Placements
        DB::table('fact_placements as p')
            ->join('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $dataIds)
            ->whereNotNull('p.promoter_name')
            ->select('p.promoter_name', 'b.name as branch')
            ->distinct()
            ->get()
            ->each(function ($row) use (&$lookup) {
                $norm = $this->canonicalizer->normalize($row->promoter_name);
                $real = $this->resolver->resolveRealBranchFromRoute($row->branch);
                if ($real && !isset($lookup[$norm])) {
                    $lookup[$norm] = $real;
                }
            });

        // Portfolios
        DB::table('fact_portfolios as po')
            ->join('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $dataIds)
            ->whereNotNull('po.promoter_name')
            ->select('po.promoter_name', 'b.name as branch')
            ->distinct()
            ->get()
            ->each(function ($row) use (&$lookup) {
                $norm = $this->canonicalizer->normalize($row->promoter_name);
                $real = $this->resolver->resolveRealBranchFromRoute($row->branch);
                if ($real && !isset($lookup[$norm])) {
                    $lookup[$norm] = $real;
                }
            });

        // Recoveries
        DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->whereNotNull('r.promoter_name')
            ->select('r.promoter_name', 'b.name as branch')
            ->distinct()
            ->get()
            ->each(function ($row) use (&$lookup) {
                $norm = $this->canonicalizer->normalize($row->promoter_name);
                $real = $this->resolver->resolveRealBranchFromRoute($row->branch);
                if ($real && !isset($lookup[$norm])) {
                    $lookup[$norm] = $real;
                }
            });

        return $lookup;
    }

    private function collectAllNames(array $dataIds): array
    {
        $names = [];

        DB::table('employees as e')
            ->join('fact_noi_movements as n', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $dataIds)
            ->distinct('e.full_name')
            ->pluck('e.full_name')
            ->each(fn ($n) => $names[$this->canonicalizer->normalize($n)] = $n);

        DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('promoter_name')
            ->distinct('promoter_name')
            ->pluck('promoter_name')
            ->each(fn ($n) => $names[$this->canonicalizer->normalize($n)] ??= $n);

        DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('promoter_name')
            ->distinct('promoter_name')
            ->pluck('promoter_name')
            ->each(fn ($n) => $names[$this->canonicalizer->normalize($n)] ??= $n);

        DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('promoter_name')
            ->distinct('promoter_name')
            ->pluck('promoter_name')
            ->each(fn ($n) => $names[$this->canonicalizer->normalize($n)] ??= $n);

        return $names;
    }
}
