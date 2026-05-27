<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\PersonIdentityResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanNonOperativeEmployeeBranchesCommand extends Command
{
    protected $signature   = 'reportes:clean-non-operative-employee-branches {period_id : ID del periodo}';
    protected $description = 'Detecta y corrige asignaciones de sucursal que apuntan a rutas/oficinas no operativas.';

    private const OPERATIVE_BRANCHES = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI',
        'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
    ];

    // Allowed non-operative branches (exclusions knowns)
    private const ALLOWED_BRANCHES = ['CORPORATIVO', 'SAN JUAN DEL RÍO', 'SAN JUAN DEL RIO'];

    public function __construct(
        private readonly BranchResolverService $branchResolver,
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
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$periodId])));

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  LIMPIEZA SUCURSALES NO OPERATIVAS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // Get operative branch IDs
        $operativeIds = DB::table('branches')
            ->whereIn(DB::raw('UPPER(TRIM(name))'), self::OPERATIVE_BRANCHES)
            ->pluck('id')
            ->toArray();

        $allowedIds = DB::table('branches')
            ->whereIn(DB::raw('UPPER(TRIM(name))'), array_merge(self::OPERATIVE_BRANCHES, self::ALLOWED_BRANCHES))
            ->pluck('id')
            ->toArray();

        // Find assignments with non-operative branches
        $nonOperative = DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->join('employees as e', 'eba.employee_id', '=', 'e.id')
            ->where('eba.period_id', $periodId)
            ->whereNotNull('eba.branch_id')
            ->whereNotIn('eba.branch_id', $allowedIds)
            ->select(
                'eba.id as assignment_id',
                'eba.employee_id',
                'e.full_name',
                'e.normalized_name',
                'e.employee_code',
                'eba.branch_id as current_branch_id',
                'b.name as current_branch_name',
                'eba.match_type',
            )
            ->get();

        if ($nonOperative->isEmpty()) {
            $this->info('  ✓ Sin asignaciones con rutas/oficinas no operativas.');
            $this->line('');
            $this->info('NON OPERATIVE ASSIGNMENTS: 0');
            return self::SUCCESS;
        }

        $this->warn("  Se encontraron {$nonOperative->count()} asignación(es) con sucursal no operativa:");
        $this->line('');

        $revisadas   = 0;
        $corregidas  = 0;
        $sinSucursal = 0;
        $ejemplos    = [];

        // Pre-load operative branch IDs from DB
        $operativeBranchMap = DB::table('branches')
            ->whereIn(DB::raw('UPPER(TRIM(name))'), self::OPERATIVE_BRANCHES)
            ->get(['id', 'name'])
            ->keyBy(fn ($b) => strtoupper(trim($b->name)));

        foreach ($nonOperative as $row) {
            $revisadas++;
            $resolved = false;

            // Paso 1: Directorio Lendus por CODIGO
            if ($row->employee_code) {
                $branchName = $this->branchResolver->resolveBranchNameFromCode($row->employee_code);
                if ($branchName) {
                    $branchId = $operativeBranchMap[strtoupper(trim($branchName))]?->id ?? null;
                    if ($branchId) {
                        DB::table('employee_branch_assignments')
                            ->where('id', $row->assignment_id)
                            ->update(['branch_id' => $branchId, 'notes' => 'Corregido: sucursal por código empleado. Antigua: ' . $row->current_branch_name]);
                        $ejemplos[] = "✓ {$row->full_name}: {$row->current_branch_name} → {$branchName} (por código {$row->employee_code})";
                        $corregidas++;
                        $resolved = true;
                    }
                }
            }

            // Paso 2: Lendus directory por nombre
            if (!$resolved) {
                $lendusRecord = DB::table('lendus_employee_directory')
                    ->where('normalized_name', $row->normalized_name)
                    ->whereNotNull('inferred_branch_id')
                    ->where('is_operational', true)
                    ->first(['inferred_branch_id']);

                if ($lendusRecord?->inferred_branch_id && in_array($lendusRecord->inferred_branch_id, $operativeIds, true)) {
                    $branchName = DB::table('branches')->where('id', $lendusRecord->inferred_branch_id)->value('name');
                    DB::table('employee_branch_assignments')
                        ->where('id', $row->assignment_id)
                        ->update(['branch_id' => $lendusRecord->inferred_branch_id, 'notes' => 'Corregido: directorio Lendus. Antigua: ' . $row->current_branch_name]);
                    $ejemplos[] = "✓ {$row->full_name}: {$row->current_branch_name} → {$branchName} (directorio Lendus)";
                    $corregidas++;
                    $resolved = true;
                }
            }

            // Paso 3: Colocación/ministraciones
            if (!$resolved) {
                $placementBranchId = DB::table('fact_placements as p')
                    ->join('branches as b', 'p.branch_id', '=', 'b.id')
                    ->where('p.normalized_promoter_name', $row->normalized_name)
                    ->whereIn('p.branch_id', $operativeIds)
                    ->orderByDesc('p.id')
                    ->value('p.branch_id');

                if ($placementBranchId) {
                    $branchName = DB::table('branches')->where('id', $placementBranchId)->value('name');
                    DB::table('employee_branch_assignments')
                        ->where('id', $row->assignment_id)
                        ->update(['branch_id' => $placementBranchId, 'notes' => 'Corregido: colocación. Antigua: ' . $row->current_branch_name]);
                    $ejemplos[] = "✓ {$row->full_name}: {$row->current_branch_name} → {$branchName} (colocación)";
                    $corregidas++;
                    $resolved = true;
                }
            }

            // Paso 4: Cobranza/código promotor
            if (!$resolved) {
                $cobranzaBranchId = DB::table('fact_recoveries as r')
                    ->join('branches as b', 'r.branch_id', '=', 'b.id')
                    ->whereRaw('LOWER(r.promoter_name) = ?', [$row->normalized_name])
                    ->whereIn('r.branch_id', $operativeIds)
                    ->orderByDesc('r.id')
                    ->value('r.branch_id');

                if ($cobranzaBranchId) {
                    $branchName = DB::table('branches')->where('id', $cobranzaBranchId)->value('name');
                    DB::table('employee_branch_assignments')
                        ->where('id', $row->assignment_id)
                        ->update(['branch_id' => $cobranzaBranchId, 'notes' => 'Corregido: cobranza. Antigua: ' . $row->current_branch_name]);
                    $ejemplos[] = "✓ {$row->full_name}: {$row->current_branch_name} → {$branchName} (cobranza)";
                    $corregidas++;
                    $resolved = true;
                }
            }

            // Paso 5: Sin resolución → dejar sin sucursal
            if (!$resolved) {
                DB::table('employee_branch_assignments')
                    ->where('id', $row->assignment_id)
                    ->update(['branch_id' => null, 'match_type' => 'unmatched', 'notes' => 'branch_id nulificado: era ruta/oficina no operativa (' . $row->current_branch_name . '). Requiere asignación manual.']);
                $ejemplos[] = "✗ {$row->full_name}: {$row->current_branch_name} → sin sucursal (sin resolución)";
                $sinSucursal++;
            }
        }

        // Show results
        $this->info('── Resultado ─────────────────────────────────────────────────────────');
        $this->line("  Asignaciones revisadas:          {$revisadas}");
        $this->line("  Con ruta detectada:              {$revisadas}");
        $this->line("  Corregidas a sucursal operativa: {$corregidas}");
        $this->line("  Dejadas sin sucursal:            {$sinSucursal}");
        $this->line('');

        if (!empty($ejemplos)) {
            $this->info('── Ejemplos ──────────────────────────────────────────────────────────');
            foreach (array_slice($ejemplos, 0, 20) as $ej) {
                $this->line("  {$ej}");
            }
            if (count($ejemplos) > 20) {
                $this->line("  ... y " . (count($ejemplos) - 20) . " más.");
            }
        }

        $this->line('');
        $this->info("NON OPERATIVE ASSIGNMENTS CLEANED: {$corregidas} corregidas, {$sinSucursal} nullificadas.");

        return self::SUCCESS;
    }
}
