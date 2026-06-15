<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditBranchesCommand extends Command
{
    protected $signature   = 'reportes:audit-branches {--json : Output as JSON}';
    protected $description = 'Classify every branch record as SUCURSAL_OFICIAL / EXCLUIDA / RUTA_ALIAS / DESCONOCIDA and show fact-table row counts.';

    private const EXCLUDED = ['SAN JUAN DEL RÍO', 'SAN JUAN DEL RIO', 'CORPORATIVO'];

    private BranchResolverService $resolver;

    public function __construct(BranchResolverService $resolver)
    {
        parent::__construct();
        $this->resolver = $resolver;
    }

    public function handle(): int
    {
        $branches = Branch::query()->orderBy('name')->get();

        $operative = collect($this->resolver->operativeFinancialBranches())
            ->map(fn ($n) => $this->normalize($n))
            ->flip();

        $rows   = [];
        $totals = ['SUCURSAL_OFICIAL' => 0, 'EXCLUIDA' => 0, 'RUTA_ALIAS' => 0, 'DESCONOCIDA' => 0];

        foreach ($branches as $branch) {
            $normName = $this->normalize($branch->name);

            $classification = $this->classify($branch->name, $normName, $operative);
            $mapsTo         = null;

            if ($classification === 'RUTA_ALIAS') {
                $mapsTo = $this->resolver->resolveRealBranchFromRoute($branch->name);
            }

            $counts = $this->factCounts($branch->id);
            $total  = array_sum($counts);

            $rows[] = [
                'id'             => $branch->id,
                'name'           => $branch->name,
                'active'         => $branch->is_active ? 'yes' : 'no',
                'classification' => $classification,
                'maps_to'        => $mapsTo ?? '—',
                'recoveries'     => $counts['recoveries'],
                'placements'     => $counts['placements'],
                'portfolios'     => $counts['portfolios'],
                'expenses'       => $counts['expenses'],
                'assignments'    => $counts['assignments'],
                'total_rows'     => $total,
            ];

            $totals[$classification]++;
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Nombre', 'Activa', 'Clasificación', 'Mapea a', 'Cobranzas', 'Colocs.', 'Cartera', 'Gastos', 'Asig.', 'Total filas'],
            array_map(fn ($r) => array_values($r), $rows),
        );

        $this->newLine();
        $this->info('Resumen:');
        foreach ($totals as $class => $count) {
            $this->line("  {$class}: {$count}");
        }

        $aliases = array_filter($rows, fn ($r) => $r['classification'] === 'RUTA_ALIAS');
        if ($aliases) {
            $this->newLine();
            $this->warn(count($aliases) . ' ruta(s) alias encontrada(s). Ejecuta `reportes:cleanup-branches --dry-run` para ver el plan de limpieza.');
        }

        return self::SUCCESS;
    }

    private function classify(string $name, string $normName, \Illuminate\Support\Collection $operative): string
    {
        if (isset($operative[$normName])) {
            return 'SUCURSAL_OFICIAL';
        }

        foreach (self::EXCLUDED as $excl) {
            if ($this->normalize($excl) === $normName) {
                return 'EXCLUIDA';
            }
        }

        $resolved = $this->resolver->resolveRealBranchFromRoute($name);
        if ($resolved && isset($operative[$this->normalize($resolved)])) {
            return 'RUTA_ALIAS';
        }

        return 'DESCONOCIDA';
    }

    private function factCounts(int $branchId): array
    {
        return [
            'recoveries'  => (int) DB::table('fact_recoveries')->where('branch_id', $branchId)->count(),
            'placements'  => (int) DB::table('fact_placements')->where('branch_id', $branchId)->count(),
            'portfolios'  => (int) DB::table('fact_portfolios')->where('branch_id', $branchId)->count(),
            'expenses'    => (int) DB::table('fact_expenses')->where('branch_id', $branchId)->count(),
            'assignments' => (int) DB::table('employee_branch_assignments')->where('branch_id', $branchId)->count(),
        ];
    }

    private function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );
    }
}
