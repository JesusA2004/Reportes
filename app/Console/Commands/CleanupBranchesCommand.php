<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CleanupBranchesCommand extends Command
{
    protected $signature   = 'reportes:cleanup-branches
                                {--dry-run : Show what would change without touching the DB (default)}
                                {--apply   : Actually execute the remapping and deactivation}';
    protected $description = 'Remap fact-table rows from route/alias branches to official branches, then deactivate non-official records.';

    private const FACT_TABLES = [
        'fact_recoveries',
        'fact_placements',
        'fact_portfolios',
        'fact_expenses',
        'fact_period_employee_summary',
        'employee_branch_assignments',
    ];

    // Only CORPORATIVO is excluded from cleanup — SJR is now an official branch.
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
        $dryRun = !$apply;

        if ($dryRun) {
            $this->warn('Modo DRY-RUN — no se modifica nada. Pasa --apply para ejecutar.');
        } else {
            $this->warn('Modo APPLY — los cambios son irreversibles. Haz un backup antes de continuar.');
            if (!$this->confirm('¿Continuar con la limpieza?')) {
                return self::SUCCESS;
            }
        }

        $this->newLine();

        $branches  = Branch::query()->orderBy('name')->get();
        $operative = collect($this->resolver->operativeFinancialBranches())
            ->map(fn ($n) => $this->normalizeKey($n))
            ->flip();

        // Pre-build official branch ID map (name → id)
        $officialBranchIdMap = Branch::query()
            ->whereIn('name', $this->resolver->operativeFinancialBranches())
            ->get(['id', 'name'])
            ->mapWithKeys(fn ($b) => [$this->normalizeKey($b->name) => $b->id])
            ->all();

        $remapCount   = 0;
        $deactivated  = 0;
        $skipped      = 0;
        $totalRows    = 0;

        foreach ($branches as $branch) {
            $normName = $this->normalizeKey($branch->name);

            // Keep official branches and excluded branches untouched
            if (isset($operative[$normName])) continue;
            if ($this->isExcluded($normName)) continue;

            $resolvedName = $this->resolver->resolveRealBranchFromRoute($branch->name);

            if ($resolvedName && isset($operative[$this->normalizeKey($resolvedName)])) {
                // RUTA_ALIAS — remap rows to official branch
                $targetId   = $officialBranchIdMap[$this->normalizeKey($resolvedName)] ?? null;

                if (!$targetId) {
                    // Official branch not in DB yet — create it now (even in dry-run, we just note it)
                    $this->line("  [WARN] Sucursal oficial '{$resolvedName}' no existe en BD. Crea primero con BranchSeeder.");
                    $skipped++;
                    continue;
                }

                $rows = $this->remapFacts($branch->id, $targetId, $dryRun);
                $totalRows += $rows;

                $label = $dryRun ? '[DRY-RUN]' : '[APPLIED]';
                $this->line("  {$label} RUTA_ALIAS '{$branch->name}' → '{$resolvedName}' (branch_id {$branch->id} → {$targetId}): {$rows} filas");

                if ($apply && !$dryRun) {
                    $branch->update(['is_active' => false]);
                }

                $remapCount++;
            } else {
                // DESCONOCIDA — deactivate but cannot remap (no known mapping)
                $rows = $this->countAllFacts($branch->id);
                $totalRows += $rows;

                $label = $dryRun ? '[DRY-RUN]' : '[APPLIED]';
                $this->warn("  {$label} DESCONOCIDA '{$branch->name}' (id {$branch->id}): {$rows} fila(s) huérfana(s) — no se pueden reasignar.");

                if ($apply && !$dryRun && $rows === 0) {
                    $branch->update(['is_active' => false]);
                    $deactivated++;
                } elseif ($rows > 0) {
                    $this->warn("         ↳ Tiene {$rows} fila(s) de hechos — NO se desactiva automáticamente. Revisa manualmente.");
                    $skipped++;
                }
            }
        }

        $this->newLine();
        $this->info("Resumen:");
        $this->line("  Rutas alias remapeadas : {$remapCount}");
        $this->line("  Desconocidas sin datos  : {$deactivated}");
        $this->line("  Omitidas (con datos)    : {$skipped}");
        $this->line("  Total filas afectadas   : {$totalRows}");

        if ($dryRun) {
            $this->newLine();
            $this->warn('Ejecuta con --apply para aplicar los cambios.');
        }

        return self::SUCCESS;
    }

    private function remapFacts(int $fromId, int $toId, bool $dryRun): int
    {
        $total = 0;

        foreach (self::FACT_TABLES as $table) {
            if (!DB::getSchemaBuilder()->hasColumn($table, 'branch_id')) {
                continue;
            }

            $count = (int) DB::table($table)->where('branch_id', $fromId)->count();
            $total += $count;

            if ($count > 0 && !$dryRun) {
                DB::table($table)
                    ->where('branch_id', $fromId)
                    ->update(['branch_id' => $toId]);

                Log::info("CleanupBranches: remapped {$count} rows in {$table} from branch {$fromId} to {$toId}.");
            }
        }

        return $total;
    }

    private function countAllFacts(int $branchId): int
    {
        $total = 0;
        foreach (self::FACT_TABLES as $table) {
            if (DB::getSchemaBuilder()->hasColumn($table, 'branch_id')) {
                $total += (int) DB::table($table)->where('branch_id', $branchId)->count();
            }
        }
        return $total;
    }

    private function isExcluded(string $normName): bool
    {
        foreach (self::EXCLUDED_NAMES as $excl) {
            if ($this->normalizeKey($excl) === $normName) return true;
        }
        return false;
    }

    private function normalizeKey(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));
        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );
    }
}
