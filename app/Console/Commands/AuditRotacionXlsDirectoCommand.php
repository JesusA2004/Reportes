<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Auditoría de Rotación leyendo los archivos NOI (.xls) ORIGINALES directamente
 * con PhpSpreadsheet — sin pasar por fact_noi_movements ni period_employee_rosters
 * — para verificar que la identidad de empleados (altas/bajas) que calcula el
 * sistema coincide con lo que el archivo fuente realmente dice.
 *
 * Contexto (2026-07-23): se encontraron y corrigieron 2 bugs reales en
 * NoiNominaImportService que corrompían la identidad de empleados entre
 * periodos ("Clave del trabajador" se reutiliza para personas distintas de
 * un mes a otro). Este comando existe para poder re-verificar, en cualquier
 * momento, que lo importado coincide con el archivo — sin depender de que el
 * importador esté libre de bugs.
 *
 * La sucursal SÍ se resuelve contra employee_branch_assignments (no viene en
 * el XLS de nómina) — se busca CUALQUIER employee_id que comparta el mismo
 * nombre_normalizado y tenga asignación para el periodo, igual criterio que
 * PeriodEmployeeRosterService.
 */
class AuditRotacionXlsDirectoCommand extends Command
{
    protected $signature = 'reportes:audit-rotacion-xls-directo
                                {period_id : Periodo actual (ej. Junio)}
                                {--previous= : Periodo anterior (ej. Mayo). Si se omite, se infiere.}
                                {--detail : listar empleado por empleado}';

    protected $description = 'Audita Rotación leyendo los XLS de NOI Normal + NOI Fiscal directamente (bypass BD), compara contra el sistema y contra una tabla de control opcional.';

    public function handle(BranchResolverService $branchResolver): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return self::FAILURE;
        }

        $prevPeriod = $this->option('previous')
            ? Period::find((int) $this->option('previous'))
            : $period->previousMonthly(Period::all());

        if (!$prevPeriod) {
            $this->error('No se pudo determinar el periodo anterior. Usa --previous=ID.');
            return self::FAILURE;
        }

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA ROTACIÓN — XLS DIRECTO: {$prevPeriod->label} → {$period->label}");
        $this->info('════════════════════════════════════════════════════════════');

        $currentFiles = $this->resolveNoiFiles($period->id);
        $previousFiles = $this->resolveNoiFiles($prevPeriod->id);

        if (!$currentFiles['normal'] || !$currentFiles['fiscal']) {
            $this->error("Faltan archivos NOI (normal/fiscal) para {$period->label}.");
            return self::FAILURE;
        }
        if (!$previousFiles['normal'] || !$previousFiles['fiscal']) {
            $this->error("Faltan archivos NOI (normal/fiscal) para {$prevPeriod->label}.");
            return self::FAILURE;
        }

        $this->line("  Junio actual  : {$currentFiles['normal']['name']} + {$currentFiles['fiscal']['name']}");
        $this->line("  Periodo previo: {$previousFiles['normal']['name']} + {$previousFiles['fiscal']['name']}");
        $this->line('');

        // A) / B) Parseo directo de los 4 archivos
        $curNormal  = $this->uniqueByName($this->parseNoiBlocks($currentFiles['normal']['path']));
        $curFiscal  = $this->uniqueByName($this->parseNoiBlocks($currentFiles['fiscal']['path']));
        $prevNormal = $this->uniqueByName($this->parseNoiBlocks($previousFiles['normal']['path']));
        $prevFiscal = $this->uniqueByName($this->parseNoiBlocks($previousFiles['fiscal']['path']));

        $curNames  = array_unique(array_merge(array_keys($curNormal), array_keys($curFiscal)));
        $prevNames = array_unique(array_merge(array_keys($prevNormal), array_keys($prevFiscal)));

        $this->line("  Roster {$period->label} (XLS directo, normal+fiscal, sin duplicar): " . count($curNames) . ' personas');
        $this->line("  Roster {$prevPeriod->label} (XLS directo): " . count($prevNames) . ' personas');
        $this->line('');

        // C) Comparación
        $altas    = array_values(array_diff($curNames, $prevNames));
        $bajas    = array_values(array_diff($prevNames, $curNames));
        $activos  = array_values(array_intersect($curNames, $prevNames));

        // D) Resumen por sucursal (branch se resuelve contra BD — no viene en el XLS)
        $operativas = ['ATLACOMULCO','ATLIXCO','CORDOBA','CUERNAVACA','HUAMANTLA','IXTLAHUACA','MIACATLAN','ORIZABA','SAN JUAN DEL RÍO','SAN LUIS POTOSI','TENANGO DEL VALLE','TLAXCALA','TULA'];
        $byBranch = [];
        foreach ($operativas as $op) { $byBranch[$op] = ['altas' => 0, 'bajas' => 0, 'plantilla' => 0]; }
        $sinSucursal = [];
        $altaRows = [];
        $bajaRows = [];

        foreach ($altas as $name) {
            $branch = $this->resolveOperativeBranch($name, $period->id, $branchResolver);
            if ($branch) {
                $byBranch[$branch]['altas']++;
                $byBranch[$branch]['plantilla']++;
            } else {
                $sinSucursal[] = ['nombre' => $name, 'tipo' => 'alta'];
            }
            $altaRows[] = ['nombre' => $name, 'sucursal' => $branch ?? '(sin sucursal)'];
        }
        foreach ($bajas as $name) {
            $branch = $this->resolveOperativeBranch($name, $prevPeriod->id, $branchResolver);
            if ($branch) {
                $byBranch[$branch]['bajas']++;
            } else {
                $sinSucursal[] = ['nombre' => $name, 'tipo' => 'baja'];
            }
            $bajaRows[] = ['nombre' => $name, 'sucursal_previa' => $branch ?? '(sin sucursal)'];
        }
        foreach ($activos as $name) {
            $branch = $this->resolveOperativeBranch($name, $period->id, $branchResolver);
            if ($branch) {
                $byBranch[$branch]['plantilla']++;
            } else {
                $sinSucursal[] = ['nombre' => $name, 'tipo' => 'activo'];
            }
        }

        $tableRows = [];
        $totalAltas = 0; $totalBajas = 0; $totalPlant = 0;
        foreach ($byBranch as $branch => $d) {
            $idx = $d['plantilla'] > 0 ? round($d['bajas'] / $d['plantilla'] * 100, 2) : 0.0;
            $tableRows[] = [$branch, $d['altas'], $d['bajas'], $d['plantilla'], $idx . '%'];
            $totalAltas += $d['altas']; $totalBajas += $d['bajas']; $totalPlant += $d['plantilla'];
        }
        $this->table(['Sucursal', 'Altas', 'Bajas', 'Plantilla', 'Índice'], $tableRows);

        $indiceGlobal = $totalPlant > 0 ? round($totalBajas / $totalPlant * 100, 2) : 0.0;
        $this->info("  TOTAL (XLS directo): Altas={$totalAltas} Bajas={$totalBajas} Plantilla={$totalPlant} Índice={$indiceGlobal}%");

        // Comparación contra lo que YA calcula el sistema (fact_rotacion source=derived_noi)
        $sistema = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')
            ->selectRaw('SUM(altas) as altas, SUM(bajas) as bajas, SUM(promedio_personal) as plantilla')->first();
        $this->line('');
        $this->line("  Sistema (BD, fact_rotacion derived_noi): Altas={$sistema->altas} Bajas={$sistema->bajas} Plantilla=" . (int) $sistema->plantilla);
        $coincide = (int) $sistema->altas === $totalAltas && (int) $sistema->bajas === $totalBajas && (int) $sistema->plantilla === $totalPlant;
        $this->line($coincide ? '  <fg=green>✓ XLS directo COINCIDE con el sistema.</>' : '  ⚠ XLS directo NO coincide con el sistema — revisar importador.');

        if (!empty($sinSucursal)) {
            $this->line('');
            $this->warn('  Empleados sin sucursal operativa resuelta (' . count($sinSucursal) . '):');
            foreach ($sinSucursal as $s) {
                $this->line("    · {$s['nombre']} ({$s['tipo']})");
            }
        }

        if ($this->option('detail')) {
            $this->line('');
            $this->info('  ALTAS DETECTADAS:');
            foreach ($altaRows as $a) $this->line("    + {$a['nombre']} -> {$a['sucursal']}");
            $this->line('');
            $this->info('  BAJAS DETECTADAS:');
            foreach ($bajaRows as $b) $this->line("    - {$b['nombre']} (última sucursal: {$b['sucursal_previa']})");
        }

        return self::SUCCESS;
    }

    /**
     * Localiza los ReportUpload de noi_nomina y noi_nomina_fiscal para un periodo,
     * y resuelve la ruta física en disco (stored_path).
     */
    private function resolveNoiFiles(int $periodId): array
    {
        $result = ['normal' => null, 'fiscal' => null];
        $uploads = ReportUpload::with('dataSource')
            ->where('period_id', $periodId)
            ->whereHas('dataSource', fn ($q) => $q->whereIn('code', ['noi_nomina', 'noi_nomina_fiscal']))
            ->get();

        foreach ($uploads as $u) {
            if (!$u->stored_path || !Storage::disk('public')->exists($u->stored_path)) {
                continue;
            }
            $entry = ['path' => Storage::disk('public')->path($u->stored_path), 'name' => $u->original_name];
            if ($u->dataSource->code === 'noi_nomina') {
                $result['normal'] = $entry;
            } elseif ($u->dataSource->code === 'noi_nomina_fiscal') {
                $result['fiscal'] = $entry;
            }
        }

        return $result;
    }

    /**
     * Parsea un .xls NOI por bloques ("Clave del trabajador : N", seguido de
     * filas [., NOMBRE, CONCEPTO, IMPORTE] hasta el siguiente bloque). Cada
     * bloque encontrado cuenta como 1 colaborador, tenga o no importes != 0
     * (regla 2026-07-23: cada clave única = 1 colaborador).
     */
    private function parseNoiBlocks(string $path): array
    {
        $ss = IOFactory::load($path);
        $sheet = $ss->getActiveSheet();
        $maxRow = $sheet->getHighestRow();

        $blocks = [];
        $currentClave = null;
        $currentName = null;

        $flush = function () use (&$blocks, &$currentClave, &$currentName) {
            if ($currentClave !== null && $currentName !== null) {
                $blocks[] = [
                    'clave' => $currentClave,
                    'nombre' => $currentName,
                    'nombre_normalizado' => $this->normalizeName($currentName),
                ];
            }
        };

        for ($r = 1; $r <= $maxRow; $r++) {
            $b = $sheet->getCell('B' . $r)->getValue();

            if (is_string($b) && preg_match('/Clave del trabajador\s*:\s*(\d+)/ui', $b, $m)) {
                $flush();
                $currentClave = $m[1];
                $currentName = null;
                continue;
            }

            if ($currentClave !== null && is_string($b) && trim($b) !== '' && $currentName === null) {
                $currentName = trim($b);
            }
        }
        $flush();

        return $blocks;
    }

    private function uniqueByName(array $blocks): array
    {
        $byName = [];
        foreach ($blocks as $b) {
            $byName[$b['nombre_normalizado']] ??= $b;
        }
        return $byName;
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)->ascii()->lower()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()->value();
    }

    /**
     * Resuelve la sucursal operativa (una de las 13) para un nombre_normalizado en
     * un periodo dado, considerando TODOS los employee_id que comparten ese nombre
     * (identidad fragmentada por el bug histórico de employee_code) — igual criterio
     * que PeriodEmployeeRosterService: prioriza asignación del periodo actual entre
     * cualquiera de los employee_id del grupo; si ninguno tiene, usa la asignación
     * histórica más reciente de cualquiera de ellos.
     */
    private function resolveOperativeBranch(string $normalizedName, int $periodId, BranchResolverService $branchResolver): ?string
    {
        $employeeIds = DB::table('employees')->where('normalized_name', $normalizedName)->pluck('id');
        if ($employeeIds->isEmpty()) {
            return null;
        }

        $current = DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('eba.employee_id', $employeeIds)
            ->where('eba.period_id', $periodId)
            ->pluck('b.name');

        foreach ($current as $name) {
            if ($branchResolver->isSheetBranch(strtoupper(trim($name)))) {
                return strtoupper(trim($name));
            }
        }

        $historical = DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('eba.employee_id', $employeeIds)
            ->orderByDesc('eba.period_id')
            ->pluck('b.name');

        foreach ($historical as $name) {
            if ($branchResolver->isSheetBranch(strtoupper(trim($name)))) {
                return strtoupper(trim($name));
            }
        }

        return null;
    }
}
