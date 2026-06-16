<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Unified branch audit + cleanup command.
 *
 * php artisan reportes:branches              → audit table (classify every branch)
 * php artisan reportes:branches --dry-run    → show remap plan, no DB changes
 * php artisan reportes:branches --apply      → remap fact rows + deactivate route branches
 */
class BranchesCommand extends Command
{
    protected $signature = 'reportes:branches
                                {--dry-run : Show remap plan without touching the DB}
                                {--apply   : Execute remapping and deactivation}
                                {--json    : Output audit as JSON (audit mode only)}';

    protected $description = 'Audit and clean up branch records. Default = audit. --dry-run = preview cleanup. --apply = execute.';

    private const FACT_TABLES = [
        'fact_recoveries',
        'fact_placements',
        'fact_portfolios',
        'fact_expenses',
        'fact_period_employee_summary',
        'employee_branch_assignments',
    ];

    // Only CORPORATIVO is permanently excluded — it is not operational but is intentionally kept.
    private const EXCLUDED_NAMES = ['CORPORATIVO'];

    private BranchResolverService $resolver;

    public function __construct(BranchResolverService $resolver)
    {
        parent::__construct();
        $this->resolver = $resolver;
    }

    public function handle(): int
    {
        $apply  = $this->option('apply');
        $dryRun = $this->option('dry-run');

        if ($apply) {
            return $this->runCleanup(apply: true);
        }

        if ($dryRun) {
            return $this->runCleanup(apply: false);
        }

        return $this->runAudit();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // AUDIT MODE
    // ──────────────────────────────────────────────────────────────────────────

    private function runAudit(): int
    {
        $branches  = Branch::query()->orderBy('name')->get();
        $operative = $this->operativeSet();

        $rows   = [];
        $totals = ['SUCURSAL_OFICIAL' => 0, 'CORPORATIVO_EXCLUIDO' => 0, 'RUTA_ALIAS' => 0, 'DESCONOCIDA' => 0];

        foreach ($branches as $branch) {
            $normName = $this->normalize($branch->name);
            $classification = $this->classify($branch->name, $normName, $operative);
            $mapsTo = $classification === 'RUTA_ALIAS'
                ? ($this->resolver->resolveRealBranchFromRoute($branch->name) ?? '—')
                : '—';

            $counts = $this->factCounts($branch->id);
            $total  = array_sum($counts);

            $rows[] = [
                'id'             => $branch->id,
                'name'           => $branch->name,
                'active'         => $branch->is_active ? 'yes' : 'no',
                'classification' => $classification,
                'maps_to'        => $mapsTo,
                'recoveries'     => $counts['recoveries'],
                'placements'     => $counts['placements'],
                'portfolios'     => $counts['portfolios'],
                'expenses'       => $counts['expenses'],
                'emp_summary'    => $counts['emp_summary'],
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
            ['ID', 'Nombre', 'Activa', 'Clasificación', 'Mapea a', 'Cobr.', 'Coloc.', 'Cartera', 'Gastos', 'Nómina', 'Asig.', 'Total'],
            array_map(fn ($r) => array_values($r), $rows),
        );

        $this->newLine();
        $this->info('Resumen:');
        foreach ($totals as $class => $count) {
            $this->line("  {$class}: {$count}");
        }

        $aliases    = array_filter($rows, fn ($r) => $r['classification'] === 'RUTA_ALIAS');
        $desconocidas = array_filter($rows, fn ($r) => $r['classification'] === 'DESCONOCIDA');

        if ($aliases || $desconocidas) {
            $this->newLine();
            $this->warn(count($aliases) . ' ruta(s) alias + ' . count($desconocidas) . ' desconocida(s) encontradas.');
            $this->warn('Ejecuta `reportes:branches --dry-run` para ver el plan de limpieza.');
            $this->warn('Ejecuta `reportes:branches --apply` para aplicar la limpieza.');
        } else {
            $this->newLine();
            $this->info('branches solo contiene sucursales oficiales y CORPORATIVO. Sin contaminación.');
        }

        return self::SUCCESS;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CLEANUP MODE (dry-run or apply)
    // ──────────────────────────────────────────────────────────────────────────

    private function runCleanup(bool $apply): int
    {
        if (!$apply) {
            $this->warn('Modo DRY-RUN — no se modifica nada. Pasa --apply para ejecutar.');
        } else {
            $this->warn('Modo APPLY — los cambios son irreversibles. Haz un backup antes.');
            if (!$this->confirm('¿Continuar con la limpieza?')) {
                return self::SUCCESS;
            }
        }

        $this->newLine();

        $branches  = Branch::query()->orderBy('name')->get();
        $operative = $this->operativeSet();

        $officialBranchIdMap = Branch::query()
            ->whereIn('name', $this->resolver->operativeFinancialBranches())
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($b) => [$this->normalize($b->name) => $b->id])
            ->all();

        $this->table(
            ['Rama actual', 'ID', 'Tipo', 'Sucursal destino', 'Cobr.', 'Coloc.', 'Cartera', 'Gastos', 'Nómina', 'Asig.', 'Acción'],
            $this->buildCleanupRows($branches, $operative, $officialBranchIdMap, $apply),
        );

        if (!$apply) {
            $this->newLine();
            $this->warn('Ejecuta con --apply para aplicar los cambios.');
        }

        return self::SUCCESS;
    }

    private function buildCleanupRows(
        \Illuminate\Database\Eloquent\Collection $branches,
        array $operative,
        array $officialBranchIdMap,
        bool  $apply,
    ): array {
        $rows = [];

        foreach ($branches as $branch) {
            $normName = $this->normalize($branch->name);
            $label    = $apply ? '[APPLIED]' : '[DRY-RUN]';

            // Official branches — skip
            if (isset($operative[$normName])) {
                continue;
            }

            // CORPORATIVO — skip
            if ($this->isExcluded($normName)) {
                continue;
            }

            $resolvedName = $this->resolver->resolveRealBranchFromRoute($branch->name);
            $counts       = $this->factCounts($branch->id);
            $totalRows    = array_sum($counts);

            if ($resolvedName && isset($operative[$this->normalize($resolvedName)])) {
                $targetId = $officialBranchIdMap[$this->normalize($resolvedName)] ?? null;

                if (!$targetId) {
                    $rows[] = [$branch->name, $branch->id, 'RUTA_ALIAS', $resolvedName,
                        $counts['recoveries'], $counts['placements'], $counts['portfolios'],
                        $counts['expenses'], $counts['emp_summary'], $counts['assignments'],
                        'SKIP — oficial no en BD'];
                    continue;
                }

                if ($apply && $totalRows > 0) {
                    $this->remapFacts($branch->id, $targetId);
                }
                if ($apply) {
                    $branch->update(['is_active' => false]);
                }

                $rows[] = [$branch->name, $branch->id, 'RUTA_ALIAS', $resolvedName,
                    $counts['recoveries'], $counts['placements'], $counts['portfolios'],
                    $counts['expenses'], $counts['emp_summary'], $counts['assignments'],
                    "{$label} → remap + desactivar"];
            } else {
                $action = $totalRows > 0
                    ? "SKIP — {$totalRows} filas huérfanas (revisar manual)"
                    : ($apply ? ($branch->update(['is_active' => false]) ? '[APPLIED] desactivada' : 'error') : '[DRY-RUN] desactivar');

                $rows[] = [$branch->name, $branch->id, 'DESCONOCIDA', '—',
                    $counts['recoveries'], $counts['placements'], $counts['portfolios'],
                    $counts['expenses'], $counts['emp_summary'], $counts['assignments'],
                    $action];
            }
        }

        return $rows;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function remapFacts(int $fromId, int $toId): void
    {
        foreach (self::FACT_TABLES as $table) {
            if (!DB::getSchemaBuilder()->hasColumn($table, 'branch_id')) {
                continue;
            }
            $count = (int) DB::table($table)->where('branch_id', $fromId)->count();
            if ($count > 0) {
                DB::table($table)->where('branch_id', $fromId)->update(['branch_id' => $toId]);
                Log::info("BranchesCommand: remapped {$count} rows in {$table} from branch {$fromId} to {$toId}.");
            }
        }
    }

    private function operativeSet(): array
    {
        return collect($this->resolver->operativeFinancialBranches())
            ->mapWithKeys(fn ($n) => [$this->normalize($n) => true])
            ->all();
    }

    private function classify(string $name, string $normName, array $operative): string
    {
        if (isset($operative[$normName])) {
            return 'SUCURSAL_OFICIAL';
        }
        if ($this->isExcluded($normName)) {
            return 'CORPORATIVO_EXCLUIDO';
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
            'emp_summary' => (int) DB::table('fact_period_employee_summary')->where('branch_id', $branchId)->count(),
            'assignments' => (int) DB::table('employee_branch_assignments')->where('branch_id', $branchId)->count(),
        ];
    }

    private function isExcluded(string $normName): bool
    {
        foreach (self::EXCLUDED_NAMES as $excl) {
            if ($this->normalize($excl) === $normName) {
                return true;
            }
        }
        return false;
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
