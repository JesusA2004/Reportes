<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\FinanciamientoMotosAssignmentService;
use App\Services\GastosExcelBranchResolverService;
use App\Services\PersonIdentityResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FASE 3A del cierre de Reportes — auditoría de UN periodo antes de intentar reparar nada.
 * No modifica datos (siempre corre los resolutores en modo dry-run). Reúne en un solo
 * lugar exactamente lo que GenerateRadiographyJob valida antes de exportar, para poder
 * diagnosticar por qué un periodo bloquea la generación sin tener que reproducir el job
 * completo (que puede tardar minutos y termina con una excepción genérica en el log).
 *
 * Reutiliza los MISMOS servicios que corren en producción — nunca reimplementa la
 * lógica de resolución en el comando:
 *   - GastosExcelBranchResolverService (gastos Excel sin sucursal → PDF Lendus / identidad)
 *   - FinanciamientoMotosAssignmentService (Financiamiento de Motos/Cascos → identidad)
 *   - PersonIdentityResolverService::evaluateNoiCandidateEvidence() (NOI $0 stale vs. real)
 *
 *   php artisan reports:audit-period 28
 *   php artisan reports:audit-period 28 --json
 */
class ReportsAuditPeriodCommand extends Command
{
    protected $signature = 'reports:audit-period {period : ID del periodo a auditar} {--json : Salida JSON en vez de texto}';

    protected $description = 'Audita gastos sin resolver, Financiamiento de Motos/Cascos sin resolver y NOI $0 stale de un periodo — sin modificar nada.';

    public function handle(
        GastosExcelBranchResolverService $gastosResolver,
        FinanciamientoMotosAssignmentService $motosResolver,
        PersonIdentityResolverService $identityResolver,
    ): int {
        $period = Period::find((int) $this->argument('period'));
        if (!$period) {
            $this->error('No existe el periodo ID=' . $this->argument('period'));
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $report = [
            'period_id'    => $period->id,
            'period_label' => $period->label,
            'data_ids'     => $dataIds,
        ];

        // ── 1. Gastos Excel sin resolver (dry-run) ──────────────────────────────
        $gastosResults          = $gastosResolver->resolveForPeriod($period, $dataIds, dryRun: true);
        $gastosUnresolved       = array_values(array_filter($gastosResults, fn ($r) => $r['estado'] === 'sin_resolver'));
        $gastosResolvedIdentity = array_values(array_filter($gastosResults, fn ($r) => str_starts_with($r['metodo'], 'identity_')));
        $report['gastos_excel'] = [
            'evaluados'                 => count($gastosResults),
            'sin_resolver'               => $gastosUnresolved,
            'resueltos_por_identidad'    => $gastosResolvedIdentity,
        ];

        // ── 2. Financiamiento de Motos/Cascos sin resolver (dry-run) ────────────
        $motosResults    = $motosResolver->assignForPeriod($period, $dataIds, dryRun: true);
        $motosUnresolved = array_values(array_filter($motosResults, fn ($r) => $r['estado'] === 'sin_resolver'));
        $report['financiamiento_motos'] = [
            'evaluados'    => count($motosResults),
            'sin_resolver' => $motosUnresolved,
        ];

        // ── 3. NOI $0 por empleado — stale vs. real (mismo criterio que la importación) ──
        $noiZero = DB::table('fact_noi_movements as m')
            ->join('employees as e', 'm.employee_id', '=', 'e.id')
            ->whereIn('m.period_id', $dataIds)
            ->select('m.employee_id', 'e.full_name')
            ->selectRaw('SUM(m.amount) as total_amount')
            ->groupBy('m.employee_id', 'e.full_name')
            ->havingRaw('SUM(m.amount) = 0')
            ->get();

        $staleZero = [];
        $realZeroWithEvidence = [];
        foreach ($noiZero as $row) {
            $evidence = $identityResolver->evaluateNoiCandidateEvidence($row->full_name);
            $entry = [
                'employee_id' => $row->employee_id,
                'full_name'   => $row->full_name,
                'evidence'    => $evidence['found'] ? ($evidence['is_non_portfolio_role'] ? 'rol_sin_cartera' : 'encontrado') : 'sin_evidencia',
            ];
            if ($evidence['found']) {
                $realZeroWithEvidence[] = $entry;
            } else {
                $staleZero[] = $entry;
            }
        }
        $report['noi_zero'] = [
            'total'                  => count($noiZero),
            'stale_sin_evidencia'    => $staleZero,
            'legitimo_con_evidencia' => $realZeroWithEvidence,
        ];

        // ── 4. Cobertura de asignación de sucursal para empleados con movimiento NOI > 0 ──
        $employeesWithNoi = DB::table('fact_noi_movements')
            ->whereIn('period_id', $dataIds)
            ->whereNotNull('employee_id')
            ->distinct()
            ->pluck('employee_id');

        $assigned = DB::table('employee_branch_assignments')
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $employeesWithNoi)
            ->pluck('employee_id')
            ->unique();

        $missingAssignment = $employeesWithNoi->diff($assigned)->values();
        $report['branch_assignment'] = [
            'empleados_con_noi'        => $employeesWithNoi->count(),
            'con_asignacion_sucursal'  => $assigned->count(),
            'sin_asignacion_sucursal'  => $missingAssignment->all(),
        ];

        // ── Salida ───────────────────────────────────────────────────────────────
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        $this->info("Periodo: {$period->label} (ID {$period->id})");
        $this->line('data_ids: ' . implode(', ', $dataIds));
        $this->newLine();

        $this->line('── Gastos Excel (gastos_lendus_excel) ──');
        $this->line('  Evaluados (excluye Financiamiento de Motos/Cascos, delegado aparte): ' . count($gastosResults));
        $this->line('  Resueltos por identidad (sin espejo exacto en PDF): ' . count($gastosResolvedIdentity));
        foreach ($gastosResolvedIdentity as $r) {
            $this->line("    - fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \$" . number_format($r['amount'], 2) . " | método={$r['metodo']} | sucursal={$r['sucursal']}");
        }
        $this->line('  SIN RESOLVER (bloquearían la generación): ' . count($gastosUnresolved));
        foreach ($gastosUnresolved as $r) {
            $this->error("    - fact_expenses.id={$r['fact_expense_id']} | {$r['category']} / {$r['concept']} | \$" . number_format($r['amount'], 2) . " | fecha={$r['expense_date']} | upload={$r['report_upload_id']} | método={$r['metodo']}");
        }
        $this->newLine();

        $this->line('── Financiamiento de Motos/Cascos ──');
        $this->line('  Evaluados: ' . count($motosResults));
        $this->line('  SIN RESOLVER (bloquearían la generación): ' . count($motosUnresolved));
        foreach ($motosUnresolved as $r) {
            $this->error("    - fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \"{$r['nombre_original']}\" | \$" . number_format($r['amount'], 2));
        }
        $this->newLine();

        $this->line('── NOI $0 ──');
        $this->line('  Total empleados con NOI = $0 este periodo: ' . count($noiZero));
        $this->line('  Stale (SKIPPED_STALE_ZERO — sin evidencia fuera de NOI): ' . count($staleZero));
        foreach ($staleZero as $r) {
            $this->line("    - employee_id={$r['employee_id']} | {$r['full_name']}");
        }
        $this->line('  Legítimo con $0 (tiene evidencia — NO debe descartarse): ' . count($realZeroWithEvidence));
        $this->newLine();

        $this->line('── Cobertura de sucursal (empleados con NOI > 0 en el periodo) ──');
        $this->line('  Con asignación: ' . $assigned->count() . ' / ' . $employeesWithNoi->count());
        if ($missingAssignment->isNotEmpty()) {
            $this->error('  SIN asignación de sucursal: ' . $missingAssignment->implode(', '));
        }

        $this->newLine();
        $blocking = count($gastosUnresolved) + count($motosUnresolved);
        if ($blocking > 0) {
            $this->error("TOTAL de registros que bloquearían la generación: {$blocking}");
        } else {
            $this->info('No hay registros que bloqueen la generación (gastos Excel / Financiamiento de Motos ya resuelven).');
        }

        return 0;
    }
}
