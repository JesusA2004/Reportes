<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugLendusEmployeesCommand extends Command
{
    protected $signature   = 'reportes:debug-lendus-employees {period_id : ID del periodo}';
    protected $description = 'Muestra estadísticas del directorio de empleados Lendus: activos/inactivos, operativos, matches contra NOI, sucursales inferidas y personas resueltas.';

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
        $this->info("  DEBUG DIRECTORIO EMPLEADOS LENDUS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        // ── Directorio stats ──────────────────────────────────────────────────
        $total      = DB::table('lendus_employee_directory')->count();
        $activos    = DB::table('lendus_employee_directory')->where('estatus', 'ACTIVO')->count();
        $inactivos  = DB::table('lendus_employee_directory')->where('estatus', '!=', 'ACTIVO')->whereNotNull('estatus')->count();
        $operativos = DB::table('lendus_employee_directory')->where('is_operational', true)->count();
        $admins     = DB::table('lendus_employee_directory')->where('is_operational', false)->count();
        $conSucursal = DB::table('lendus_employee_directory')->whereNotNull('inferred_branch_id')->count();
        $sinSucursal = DB::table('lendus_employee_directory')->whereNull('inferred_branch_id')->count();

        $this->info('── Directorio ────────────────────────────────────────────────────────');
        $this->line("  Total registros:   {$total}");
        $this->line("  Activos:           {$activos}");
        $this->line("  Inactivos/otros:   {$inactivos}");
        $this->line("  Operativos:        {$operativos}  (coordinador, gerente, subgerente, gestor, promotor)");
        $this->line("  Administrativos:   {$admins}  (ignorados para resolución)");
        $this->line("  Con sucursal inf.: {$conSucursal}");
        $this->line("  Sin sucursal inf.: {$sinSucursal}  (código no reconocido)");
        $this->line('');

        if ($total === 0) {
            $this->warn('  Sin registros en el directorio. Carga primero el Reporte de empleados Lendus.');
            $this->line('');
            return self::SUCCESS;
        }

        // ── Sucursales inferidas ──────────────────────────────────────────────
        $this->info('── Sucursales inferidas ──────────────────────────────────────────────');
        $branchCounts = DB::table('lendus_employee_directory')
            ->whereNotNull('inferred_branch_id')
            ->select('inferred_branch_name', DB::raw('COUNT(*) as cnt'))
            ->groupBy('inferred_branch_name')
            ->orderByDesc('cnt')
            ->get();

        foreach ($branchCounts as $row) {
            $this->line(sprintf('  %-30s %d', $row->inferred_branch_name, $row->cnt));
        }
        $this->line('');

        // ── Matches against NOI for this period ──────────────────────────────
        $this->info('── Matches contra NOI del periodo ────────────────────────────────────');

        $noiEmployees = DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('e.normalized_name')
            ->select('e.id', 'e.full_name', 'e.normalized_name')
            ->distinct()
            ->get();

        $lendusNormalized = DB::table('lendus_employee_directory')
            ->where('is_operational', true)
            ->whereNotNull('inferred_branch_id')
            ->whereNotNull('normalized_name')
            ->pluck('normalized_name')
            ->unique()
            ->toArray();

        $lendusSet = array_flip($lendusNormalized);

        $exactMatches = 0;
        $fuzzyMatches = 0;
        $noMatch = 0;

        foreach ($noiEmployees as $emp) {
            if (isset($lendusSet[$emp->normalized_name])) {
                $exactMatches++;
            } else {
                // Check fuzzy
                $bestScore = 0.0;
                foreach ($lendusNormalized as $ln) {
                    similar_text($emp->normalized_name, $ln, $pct);
                    if ($pct > $bestScore) $bestScore = $pct;
                }
                if ($bestScore >= 85.0) {
                    $fuzzyMatches++;
                } else {
                    $noMatch++;
                }
            }
        }

        $this->line("  Empleados NOI en periodo: {$noiEmployees->count()}");
        $this->line("  Match exacto vs directorio:  {$exactMatches}");
        $this->line("  Match fuzzy ≥85%:            {$fuzzyMatches}");
        $this->line("  Sin match en directorio:     {$noMatch}");
        $this->line('');

        // ── Personas resueltas por directorio ─────────────────────────────────
        $this->info('── Personas resueltas gracias al directorio ──────────────────────────');

        $resolvedByLendus = DB::table('employee_branch_assignments as eba')
            ->whereIn('eba.period_id', $dataIds)
            ->where('eba.source_reference', 'lendus_employee_directory')
            ->whereNotNull('eba.branch_id')
            ->join('employees as e', 'eba.employee_id', '=', 'e.id')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->select('e.full_name', 'b.name as branch_name', 'eba.confidence', 'eba.notes')
            ->orderBy('b.name')
            ->orderBy('e.full_name')
            ->get();

        $this->line("  Total: {$resolvedByLendus->count()}");

        if ($resolvedByLendus->isNotEmpty()) {
            $rows = $resolvedByLendus->map(fn ($r) => [
                mb_substr($r->full_name, 0, 30),
                mb_substr($r->branch_name, 0, 20),
                number_format($r->confidence * 100, 0) . '%',
            ])->all();
            $this->table(['Nombre', 'Sucursal', 'Confianza'], $rows);
        } else {
            $this->line('  Ninguna asignación de directorio encontrada para este periodo.');
            $this->line('  (Ejecuta php artisan reportes:auto-match-branches para re-asignar.)');
        }

        $this->line('');

        // ── Sample unmatched NOI employees ────────────────────────────────────
        if ($noMatch > 0) {
            $this->info('── Muestra NOI sin match en directorio (top 10) ─────────────────────');

            $sampleNoMatch = [];
            $count = 0;
            foreach ($noiEmployees as $emp) {
                if ($count >= 10) break;
                if (isset($lendusSet[$emp->normalized_name])) continue;

                $bestScore = 0.0;
                foreach ($lendusNormalized as $ln) {
                    similar_text($emp->normalized_name, $ln, $pct);
                    if ($pct > $bestScore) $bestScore = $pct;
                }
                if ($bestScore < 85.0) {
                    $sampleNoMatch[] = [mb_substr($emp->full_name, 0, 32), $emp->normalized_name, round($bestScore, 1) . '%'];
                    $count++;
                }
            }

            if (!empty($sampleNoMatch)) {
                $this->table(['Nombre NOI', 'Normalizado', 'Mejor score Lendus'], $sampleNoMatch);
            }
            $this->line('');
        }

        return self::SUCCESS;
    }
}
