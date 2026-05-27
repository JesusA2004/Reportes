<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Period;
use App\Services\PersonIdentityResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugPersonResolutionCommand extends Command
{
    protected $signature   = 'reportes:debug-person-resolution {period_id : ID del periodo}';
    protected $description = 'Muestra resolución de identidad por persona del NOI (regular + fiscal). Para no-resueltos: explica motivo, matches y códigos revisados.';

    public function __construct(private readonly PersonIdentityResolverService $resolver)
    {
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
        $this->info("  DEBUG RESOLUCIÓN PERSONAS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        // Get employees + their assignment status
        $employees = DB::table('employees as e')
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
                'e.normalized_name',
                'e.source_system',
                'e.employee_code',
                DB::raw('COALESCE(b.name, NULL) AS branch_name'),
                'eba.match_type',
                DB::raw('COUNT(DISTINCT n.id) AS movements'),
                DB::raw('SUM(n.amount) AS total_amount'),
            )
            ->groupBy('e.id', 'e.full_name', 'e.normalized_name', 'e.source_system', 'e.employee_code', 'b.name', 'eba.match_type')
            ->orderBy('branch_name')
            ->orderBy('e.full_name')
            ->get();

        if ($employees->isEmpty()) {
            $this->warn('  Sin empleados en NOI para este periodo.');
            return self::SUCCESS;
        }

        // Source flags
        $fiscalEmployeeIds = $this->resolveFiscalEmployeeIds($dataIds);

        $resolved   = $employees->filter(fn ($e) => $e->branch_name !== null);
        $unresolved = $employees->filter(fn ($e) => $e->branch_name === null);

        $this->line("  Total: {$employees->count()}  |  Con sucursal: {$resolved->count()}  |  Sin sucursal: {$unresolved->count()}");
        $this->line("  De NOI fiscal: " . count($fiscalEmployeeIds));
        $this->line('');

        // ── Resolved summary ─────────────────────────────────────────────
        $this->info('── Resueltos ──────────────────────────────────────────────────────────');
        $rows = $resolved->map(fn ($e) => [
            mb_substr((string) $e->full_name, 0, 26),
            in_array($e->id, $fiscalEmployeeIds, true) ? 'FISCAL' : 'regular',
            mb_substr((string) $e->branch_name, 0, 18),
            mb_substr((string) ($e->match_type ?? '—'), 0, 12),
            (int) $e->movements,
            '$' . number_format((float) $e->total_amount, 2),
        ])->all();
        $this->table(['Nombre', 'Fuente', 'Sucursal', 'Método', 'Movs.', 'Total NOI'], $rows);
        $this->line('');

        // ── Unresolved detail ─────────────────────────────────────────────
        if ($unresolved->isEmpty()) {
            $this->info('  ✓ Todas las personas tienen sucursal asignada.');
            $this->line('');
            return self::SUCCESS;
        }

        $this->info('── No resueltos — Diagnóstico completo ───────────────────────────────');
        $this->line('');

        // Pre-load all employees for fuzzy matching
        $allEmployees = Employee::query()
            ->whereNotNull('normalized_name')
            ->get(['id', 'full_name', 'normalized_name']);

        // Sample movements
        $unresolvedIds = $unresolved->pluck('id')->all();
        $sampleMovs = DB::table('fact_noi_movements as n')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereIn('n.employee_id', $unresolvedIds)
            ->select('n.employee_id', 'n.movement_date', 'n.concept', 'n.amount', 'n.raw_payload', 'ds.code as source')
            ->orderByDesc('n.amount')
            ->get()
            ->groupBy('employee_id');

        // Historical branches
        $historical = DB::table('employee_branch_assignments as eba')
            ->join('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('eba.employee_id', $unresolvedIds)
            ->whereNotNull('eba.branch_id')
            ->orderByDesc('eba.period_id')
            ->select('eba.employee_id', 'b.name as branch_name')
            ->get()
            ->unique('employee_id')
            ->keyBy('employee_id');

        foreach ($unresolved as $emp) {
            $isFiscal = in_array($emp->id, $fiscalEmployeeIds, true);

            $trace = $this->resolver->buildResolutionTrace(
                employeeId:      (int) $emp->id,
                fullName:        (string) $emp->full_name,
                allEmployees:    $allEmployees,
                sampleMovs:      $sampleMovs[(int) $emp->id] ?? collect(),
                employeeCode:    $emp->employee_code ?? null,
                historicalBranch: $historical[(int) $emp->id]->branch_name ?? null,
                dataIds:         $dataIds,
            );

            $this->line(sprintf(
                '<fg=red>✗</> <fg=white;options=bold>%s</> %s',
                $emp->full_name,
                $isFiscal ? '<fg=magenta>[FISCAL]</>' : '[regular]'
            ));

            $this->line("  Normalizado:    {$trace['normalized_name']}");
            $this->line("  Movimientos:    {$emp->movements}  |  Monto: $" . number_format((float) $emp->total_amount, 2));

            // Detected codes
            $codes = $trace['detected_codes'];
            $this->line("  Código empl.:   " . ($codes['employee_code'] ?? 'N/D'));
            $this->line("  Prefijo contr.: " . ($codes['contract_prefix'] ?? 'N/D'));
            $this->line("  Sucursal hint:  " . ($codes['branch_hint'] ?? 'N/D'));
            if (!empty($codes['raw_clues'])) {
                foreach ($codes['raw_clues'] as $clue) {
                    $this->line("  Pista:          {$clue}");
                }
            }

            // Ministraciones check
            $pm = $trace['placement_match'] ?? null;
            if (!($trace['checked_ministraciones'] ?? false)) {
                $this->line("  Ministraciones: <fg=gray>no revisado</>");
            } elseif ($pm) {
                $methodLabel = $pm['method'] === 'exact' ? 'exacto' : "variante {$pm['score']}%";
                $this->line(sprintf(
                    "  Ministraciones: <fg=green>%s</> → sucursal %s  (%s, método=%s)",
                    $pm['code'], $codes['placement_branch'] ?? '?', $methodLabel, $pm['method']
                ));
            } else {
                $this->line("  Ministraciones: <fg=red>no encontrado</> — no aparece como promotor en colocación");
            }

            // Candidate matches
            if (!empty($trace['candidate_matches'])) {
                $this->line('  Posibles matches:');
                foreach ($trace['candidate_matches'] as $m) {
                    $flag = $m['score'] >= 80 ? '<fg=yellow>[SOBRE UMBRAL]</>' : '';
                    $this->line(sprintf("    · %-28s  score %s%%  %s  %s",
                        mb_substr($m['name'], 0, 28), $m['score'], $m['reason'], $flag));
                }
            } else {
                $this->line('  Posibles matches: ninguno con score ≥60%');
            }

            // Sample movements
            if (!empty($trace['sample_movements'])) {
                $this->line('  Muestra de movimientos:');
                foreach ($trace['sample_movements'] as $mov) {
                    $this->line(sprintf("    · [%s] %-30s  $%s  fuente=%s  código=%s",
                        $mov['date'] ?? '—',
                        mb_substr($mov['concept'], 0, 30),
                        number_format($mov['amount'], 2),
                        $mov['source'] ?? '—',
                        $mov['code'] ?? 'N/D'
                    ));
                }
            }

            // Resolution type classification
            $bestMatch = $trace['candidate_matches'][0] ?? null;
            if ($bestMatch && $bestMatch['score'] >= 100.0) {
                $resType = $bestMatch['branch'] ? 'exact_name_has_branch' : 'exact_name_no_branch';
            } elseif ($bestMatch && $bestMatch['score'] >= 80.0) {
                $resType = $bestMatch['branch'] ? 'fuzzy_match_has_branch' : 'fuzzy_match_no_branch';
            } elseif ($bestMatch && $bestMatch['score'] >= 60.0) {
                $resType = 'possible_match';
            } else {
                $resType = 'manual_branch_required';
            }

            $typeColor = match ($resType) {
                'exact_name_has_branch'   => 'fg=green',
                'exact_name_no_branch'    => 'fg=yellow',
                'fuzzy_match_has_branch'  => 'fg=green',
                'fuzzy_match_no_branch'   => 'fg=yellow',
                'possible_match'          => 'fg=yellow',
                'manual_branch_required'  => 'fg=red',
                default                   => 'fg=white',
            };
            $this->line("  <{$typeColor}>Tipo resolución: {$resType}</>");

            $this->line('  <fg=red>Motivo:</>     ' . $trace['reason']);
            $this->line('  <fg=cyan>Sugerencia:</> ' . $trace['suggested_action']);
            $this->line('');
        }

        // Summary by resolution type
        $this->info('── Resumen por tipo ──────────────────────────────────────────────────');
        $typeCounts = [];
        foreach ($unresolved as $emp) {
            $trace     = $this->resolver->buildResolutionTrace(
                employeeId:      (int) $emp->id,
                fullName:        (string) $emp->full_name,
                allEmployees:    $allEmployees,
                sampleMovs:      collect(),
                employeeCode:    $emp->employee_code ?? null,
                historicalBranch: null,
                dataIds:         $dataIds,
            );
            $best = $trace['candidate_matches'][0] ?? null;
            if ($best && $best['score'] >= 100.0) {
                $t = $best['branch'] ? 'exact_name_has_branch' : 'exact_name_no_branch';
            } elseif ($best && $best['score'] >= 80.0) {
                $t = $best['branch'] ? 'fuzzy_match_has_branch' : 'fuzzy_match_no_branch';
            } elseif ($best && $best['score'] >= 60.0) {
                $t = 'possible_match';
            } else {
                $t = 'manual_branch_required';
            }
            $typeCounts[$t] = ($typeCounts[$t] ?? 0) + 1;
        }
        foreach ($typeCounts as $type => $cnt) {
            $this->line("  {$type}: {$cnt}");
        }

        $this->info('── Resumen ───────────────────────────────────────────────────────────');
        $montoSin = $unresolved->sum('total_amount');
        $this->line("  Sin sucursal: {$unresolved->count()}  |  Monto sin sucursal: $" . number_format((float) $montoSin, 2));
        $this->line('');

        return self::SUCCESS;
    }

    private function resolveFiscalEmployeeIds(array $dataIds): array
    {
        $fiscalUploadIds = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('ds.code', 'noi_nomina_fiscal')
            ->whereIn('ru.period_id', $dataIds)
            ->pluck('ru.id')
            ->toArray();

        if (empty($fiscalUploadIds)) return [];

        return DB::table('fact_noi_movements')
            ->whereIn('report_upload_id', $fiscalUploadIds)
            ->whereNotNull('employee_id')
            ->pluck('employee_id')
            ->unique()
            ->toArray();
    }
}
