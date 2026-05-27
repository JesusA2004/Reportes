<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugEmployeeBranchAssignmentsCommand extends Command
{
    protected $signature   = 'reportes:debug-employee-branch-assignments {period_id : ID del periodo}';
    protected $description = 'Muestra asignaciones de sucursal por empleado: nombre, fuente, sucursal final, movimientos NOI, monto, auto/manual.';

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
        $this->info("  DEBUG EMPLOYEE BRANCH ASSIGNMENTS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // Get employees with movements, joined to their assignment
        $rows = DB::table('employees as e')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($dataIds, $periodId) {
                $j->on('eba.employee_id', '=', 'e.id')
                  ->whereIn('eba.period_id', array_merge([$periodId], $dataIds));
            })
            ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
            ->join('fact_noi_movements as n', function ($j) use ($dataIds) {
                $j->on('n.employee_id', '=', 'e.id')->whereIn('n.period_id', $dataIds);
            })
            ->whereIn('n.period_id', $dataIds)
            ->select(
                'e.id',
                'e.full_name',
                'e.source_system',
                DB::raw('COALESCE(b.name, \'<sin sucursal>\') AS branch_name'),
                'eba.match_type',
                'eba.confidence',
                'eba.was_manual_reviewed',
                DB::raw('COUNT(n.id) AS movements'),
                DB::raw('SUM(n.amount) AS total_amount'),
            )
            ->groupBy('e.id', 'e.full_name', 'e.source_system', 'b.name', 'eba.match_type', 'eba.confidence', 'eba.was_manual_reviewed')
            ->orderBy('branch_name')
            ->orderBy('e.full_name')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('  Sin empleados en NOI para este periodo.');
            return self::SUCCESS;
        }

        // Source (fiscal vs regular)
        $fiscalUploadIds = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('ds.code', 'noi_nomina_fiscal')
            ->whereIn('ru.period_id', $dataIds)
            ->pluck('ru.id')
            ->toArray();

        $fiscalEmployeeIds = empty($fiscalUploadIds) ? [] : DB::table('fact_noi_movements')
            ->whereIn('report_upload_id', $fiscalUploadIds)
            ->whereNotNull('employee_id')
            ->pluck('employee_id')
            ->unique()
            ->toArray();

        $headers = ['Nombre', 'Fuente', 'Sucursal', 'Método', 'Conf.', 'Manual', 'Movs.', 'Total NOI'];
        $tableRows = [];

        foreach ($rows as $r) {
            $tableRows[] = [
                mb_substr((string) $r->full_name, 0, 24),
                in_array($r->id, $fiscalEmployeeIds, true) ? 'FISCAL' : 'regular',
                mb_substr((string) $r->branch_name, 0, 18),
                mb_substr((string) ($r->match_type ?? '—'), 0, 14),
                $r->confidence !== null ? number_format((float) $r->confidence, 2) : '—',
                $r->was_manual_reviewed ? 'SÍ' : 'no',
                (int) $r->movements,
                '$' . number_format((float) $r->total_amount, 2),
            ];
        }

        $this->table($headers, $tableRows);
        $this->line('');

        // Summary
        $total      = $rows->count();
        $conSuc     = $rows->filter(fn ($r) => $r->branch_name !== '<sin sucursal>')->count();
        $sinSuc     = $total - $conSuc;
        $manual     = $rows->filter(fn ($r) => $r->was_manual_reviewed)->count();
        $montoSin   = $rows->filter(fn ($r) => $r->branch_name === '<sin sucursal>')->sum('total_amount');

        $this->info('── Resumen ───────────────────────────────────────────────────────────');
        $this->line("  Total empleados: {$total}  |  Con sucursal: {$conSuc}  |  Sin sucursal: {$sinSuc}");
        $this->line("  Manuales: {$manual}  |  Monto sin sucursal: $" . number_format((float) $montoSin, 2));
        $this->line('');

        return self::SUCCESS;
    }
}
