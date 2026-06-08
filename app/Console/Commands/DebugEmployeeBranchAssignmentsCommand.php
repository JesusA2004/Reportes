<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugEmployeeBranchAssignmentsCommand extends Command
{
    protected $signature   = 'reportes:debug-employee-branch-assignments {period_id : ID del periodo}';
    protected $description = 'Muestra asignaciones de sucursal por empleado y diagnóstico detallado de personas sin sucursal.';

    private const OPERATIVE_BRANCHES = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI',
        'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
    ];

    private const ALLOWED_EXTRAS = ['CORPORATIVO', 'SAN JUAN DEL RÍO', 'SAN JUAN DEL RIO'];

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

        // Employees in NOI with their branch assignment
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
                'e.employee_code',
                'e.source_system',
                DB::raw('COALESCE(b.name, \'<sin sucursal>\') AS branch_name'),
                'eba.match_type',
                'eba.confidence',
                'eba.was_manual_reviewed',
                'eba.notes as eba_notes',
                DB::raw('COUNT(n.id) AS movements'),
                DB::raw('SUM(n.amount) AS total_amount'),
            )
            ->groupBy('e.id', 'e.full_name', 'e.employee_code', 'e.source_system', 'b.name', 'eba.match_type', 'eba.confidence', 'eba.was_manual_reviewed', 'eba.notes')
            ->orderBy('branch_name')
            ->orderBy('e.full_name')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('  Sin empleados en NOI para este periodo.');
            return self::SUCCESS;
        }

        // Fiscal vs regular source
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

        // ── A. Full assignments table ─────────────────────────────────────────
        $this->info('── A. ASIGNACIONES COMPLETAS ─────────────────────────────────────────');
        $headers   = ['Nombre', 'Fuente', 'Sucursal', 'Método', 'Conf.', 'Manual', 'Movs.', 'Total NOI'];
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

        // ── B. Summary ────────────────────────────────────────────────────────
        $total    = $rows->count();
        $conSuc   = $rows->filter(fn ($r) => $r->branch_name !== '<sin sucursal>')->count();
        $sinSuc   = $total - $conSuc;
        $manual   = $rows->filter(fn ($r) => $r->was_manual_reviewed)->count();
        $montoSin = $rows->filter(fn ($r) => $r->branch_name === '<sin sucursal>')->sum('total_amount');

        $this->info('── B. RESUMEN ────────────────────────────────────────────────────────');
        $this->line("  Total empleados en NOI: {$total}  |  Con sucursal: {$conSuc}  |  Sin sucursal: {$sinSuc}");
        $this->line("  Manuales: {$manual}  |  Monto expuesto sin sucursal: $" . number_format((float) $montoSin, 2));
        $this->line('');

        // ── C. Per-person diagnosis for sin-sucursal ──────────────────────────
        $sinSucRows = $rows->filter(fn ($r) => $r->branch_name === '<sin sucursal>');

        if ($sinSucRows->isEmpty()) {
            $this->info('── C. DIAGNÓSTICO SIN SUCURSAL ─────────────────────────── ✓ NINGUNA');
            $this->line('  Todos los empleados tienen sucursal asignada.');
            $this->line('');
        } else {
            $this->info("── C. DIAGNÓSTICO SIN SUCURSAL ({$sinSuc} personas) ─────────────────");
            $this->line('');

            // Normalized name helper (defined first — used by preloads below)
            $normalize = fn (?string $s): string => mb_strtolower(
                preg_replace('/\s+/', ' ', trim(
                    iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $s)
                ))
            );

            // Preload Lendus directory
            $lendusDir = DB::table('lendus_employee_directory')
                ->get(['id', 'codigo', 'nombre', 'normalized_name', 'inferred_branch_id', 'puesto'])
                ->keyBy('normalized_name');

            // Preload branch names
            $branchNames = DB::table('branches')->pluck('name', 'id');

            // Preload fact_placements employee matches (by normalized_promoter_name)
            $placementsByName = DB::table('fact_placements')
                ->whereIn('period_id', $dataIds)
                ->whereNotNull('normalized_promoter_name')
                ->groupBy('normalized_promoter_name')
                ->selectRaw('normalized_promoter_name, COUNT(*) as cnt, MAX(promoter_code) as promoter_code')
                ->pluck('cnt', 'normalized_promoter_name');

            // Preload fact_recoveries by normalized promoter_name (no employee_id column in this table)
            $recoveriesByNormName = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->whereNotNull('promoter_name')
                ->selectRaw('promoter_name, COUNT(*) as cnt')
                ->groupBy('promoter_name')
                ->get()
                ->mapWithKeys(fn ($row) => [$normalize($row->promoter_name) => (int) $row->cnt]);

            // Preload fact_portfolios employee matches
            $portfoliosByName = DB::table('fact_portfolios')
                ->whereIn('period_id', $dataIds)
                ->whereNotNull('promoter_name')
                ->groupBy('promoter_name')
                ->selectRaw('promoter_name, COUNT(*) as cnt')
                ->pluck('cnt', 'promoter_name');

            $diagHeaders = ['Nombre', 'emp_id', 'Fuente', 'Cód Emp', 'En Lendus Dir', 'Cód Lendus', 'Suc Inferida', 'En Plac.', 'En Cobr.', 'En Saldos', 'Razón no asignó'];
            $diagRows    = [];

            foreach ($sinSucRows as $r) {
                $normName = $normalize($r->full_name);

                // Lendus directory lookup
                $lendusRecord     = $lendusDir->get($normName);
                $enLendus         = $lendusRecord ? 'SÍ' : 'NO';
                $codigoLendus     = $lendusRecord ? ($lendusRecord->codigo ?? 'N/D') : 'N/D';
                $sucInferida      = 'N/D';
                if ($lendusRecord && $lendusRecord->inferred_branch_id) {
                    $sucInferida = $branchNames[$lendusRecord->inferred_branch_id] ?? "branch_id:{$lendusRecord->inferred_branch_id}";
                } elseif ($lendusRecord && $lendusRecord->codigo) {
                    $sucInferida = 'tiene código, sin branch_id';
                }

                // fact_placements lookup
                $enPlacements = $placementsByName->get($normName, 0);

                // fact_recoveries lookup by normalized promoter_name
                $enCobranza = $recoveriesByNormName->get($normName, 0);

                // fact_portfolios lookup
                $enSaldos = $portfoliosByName->get($r->full_name, 0)
                    ?: $portfoliosByName->get(mb_strtolower($r->full_name), 0);

                // Build diagnosis reason
                $reasons = [];
                if (!$lendusRecord) {
                    $reasons[] = 'No en directorio Lendus';
                } elseif (!$lendusRecord->inferred_branch_id && !$lendusRecord->codigo) {
                    $reasons[] = 'Lendus sin código ni sucursal';
                } elseif (!$lendusRecord->inferred_branch_id && $lendusRecord->codigo) {
                    $reasons[] = 'Código Lendus sin branch_id resuelto';
                }
                if ($enPlacements == 0) $reasons[] = 'Sin ministraciones';
                if ($enCobranza == 0)   $reasons[] = 'Sin cobranza';
                if ($enSaldos == 0)     $reasons[] = 'Sin saldos';

                $reason = empty($reasons) ? 'desconocida — revisar logs' : implode('; ', $reasons);

                $diagRows[] = [
                    mb_substr((string) $r->full_name, 0, 22),
                    $r->id,
                    in_array($r->id, $fiscalEmployeeIds, true) ? 'FISCAL' : 'regular',
                    $r->employee_code ?? 'N/D',
                    $enLendus,
                    mb_substr((string) $codigoLendus, 0, 10),
                    mb_substr((string) $sucInferida, 0, 16),
                    $enPlacements > 0 ? "SÍ ({$enPlacements})" : 'NO',
                    $enCobranza > 0   ? "SÍ ({$enCobranza})"  : 'NO',
                    $enSaldos > 0     ? "SÍ ({$enSaldos})"    : 'NO',
                    mb_substr($reason, 0, 40),
                ];
            }

            $this->table($diagHeaders, $diagRows);
            $this->line('');
        }

        // ── D. Non-operative branch check ─────────────────────────────────────
        $this->info('── D. VERIFICACIÓN SUCURSALES NO OPERATIVAS ──────────────────────────');
        $allowedNames = array_map('strtoupper', array_merge(self::OPERATIVE_BRANCHES, self::ALLOWED_EXTRAS));

        $nonOperative = $rows->filter(function ($r) use ($allowedNames) {
            if ($r->branch_name === '<sin sucursal>') return false;
            return !in_array(strtoupper(trim($r->branch_name)), $allowedNames, true);
        });

        $noCount = $nonOperative->count();
        if ($noCount === 0) {
            $this->line('  <fg=green>NON OPERATIVE ASSIGNMENTS: 0 ✓</>');
        } else {
            $this->error("NON OPERATIVE ASSIGNMENTS: {$noCount} — ERROR");
            foreach ($nonOperative as $r) {
                $this->line("    <fg=red>✗</> {$r->full_name} → {$r->branch_name}");
            }
            $this->line('  Ejecuta: php artisan reportes:clean-non-operative-employee-branches ' . $this->argument('period_id'));
        }
        $this->line('');

        return ($sinSuc > 0 || $noCount > 0) ? self::FAILURE : self::SUCCESS;
    }
}
