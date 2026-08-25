<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\FinanciamientoMotosAssignmentService;
use App\Services\GastosExcelBranchResolverService;
use App\Services\PersonIdentityResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * FASE 3 del cierre de Reportes — reparación segura de un periodo bloqueado.
 *
 * IMPORTANTE — estado actual de este comando: SOLO --dry-run está implementado. No
 * existe todavía un --apply que modifique datos. Motivo: aplicar cambios reales
 * requiere decidir el mecanismo de persistencia para "excluir del roster operativo"
 * (¿columna nueva en employees/fact_noi_movements?, ¿tabla de exclusiones?, ¿soft
 * delete?) — no existe hoy ninguna columna así en el esquema (ver
 * Schema::getColumnListing('employees') / ('fact_noi_movements')) y decidirlo sin
 * datos reales contaminados para validar contra sería adivinar, algo que el cierre de
 * este proyecto prohíbe explícitamente. Los dos resolutores de gastos (Excel/Motos) SÍ
 * escriben cambios reales (branch_id/employee_id) porque ese mecanismo ya existe y está
 * probado — ver GastosExcelBranchResolverServiceTest.
 *
 *   php artisan reports:repair-period 28 --dry-run     (hoy: idéntico a --apply en fact_expenses,
 *                                                        NOI queda solo diagnosticado)
 *   php artisan reports:repair-period 28 --apply       (resuelve fact_expenses; NOI sigue sin tocar)
 */
class ReportsRepairPeriodCommand extends Command
{
    protected $signature = 'reports:repair-period {period : ID del periodo} {--dry-run} {--apply}';

    protected $description = 'Reparación segura de gastos Excel / Financiamiento de Motos sin resolver de un periodo (transaccional). NOI stale legacy solo se diagnostica, no se modifica.';

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

        $apply = (bool) $this->option('apply');
        if (!$apply && !$this->option('dry-run')) {
            $this->comment('Ni --dry-run ni --apply indicados — corriendo en modo dry-run por seguridad.');
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $this->info(($apply ? 'APLICANDO' : 'DRY-RUN — sin escribir nada') . " sobre periodo {$period->label} (ID {$period->id})");
        $this->newLine();

        // ── Gastos Excel + Financiamiento de Motos: mismo mecanismo transaccional ──
        $exitCode = 0;
        DB::transaction(function () use ($period, $dataIds, $gastosResolver, $motosResolver, $apply, &$exitCode) {
            $gastosResults = $gastosResolver->resolveForPeriod($period, $dataIds, dryRun: !$apply);
            $gastosFixed   = array_values(array_filter($gastosResults, fn ($r) => $r['estado'] === 'resuelto'));
            $gastosStuck   = array_values(array_filter($gastosResults, fn ($r) => $r['estado'] === 'sin_resolver'));

            $motosResults = $motosResolver->assignForPeriod($period, $dataIds, dryRun: !$apply);
            $motosFixed   = array_values(array_filter($motosResults, fn ($r) => $r['estado'] === 'resuelto'));
            $motosStuck   = array_values(array_filter($motosResults, fn ($r) => $r['estado'] === 'sin_resolver'));

            $this->line('Gastos Excel — resueltos: ' . count($gastosFixed) . ' | sin resolver: ' . count($gastosStuck));
            foreach ($gastosFixed as $r) {
                $this->line("  + fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \$" . number_format($r['amount'], 2) . " → sucursal={$r['sucursal']} employee_id=" . ($r['employee_id'] ?? 'null') . " (método={$r['metodo']})");
            }
            foreach ($gastosStuck as $r) {
                $this->error("  ! fact_expenses.id={$r['fact_expense_id']} | {$r['category']} / {$r['concept']} | \$" . number_format($r['amount'], 2) . " | fecha={$r['expense_date']} — SIGUE SIN RESOLVER, requiere revisión manual (NEEDS_REVIEW).");
            }

            $this->newLine();
            $this->line('Financiamiento de Motos/Cascos — resueltos: ' . count($motosFixed) . ' | sin resolver: ' . count($motosStuck));
            foreach ($motosFixed as $r) {
                $this->line("  + fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \"{$r['nombre_original']}\" → {$r['empleado_encontrado']} @ {$r['sucursal']} (método={$r['metodo']})");
            }
            foreach ($motosStuck as $r) {
                $this->error("  ! fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \"{$r['nombre_original']}\" — SIGUE SIN RESOLVER, requiere revisión manual (NEEDS_REVIEW).");
            }

            if (!empty($gastosStuck) || !empty($motosStuck)) {
                $exitCode = 1;
            }
        });

        $this->newLine();

        // ── NOI stale legacy: SOLO diagnóstico, no se toca nada (ver docblock) ──
        $noiZero = DB::table('fact_noi_movements as m')
            ->join('employees as e', 'm.employee_id', '=', 'e.id')
            ->whereIn('m.period_id', $dataIds)
            ->select('m.employee_id', 'e.full_name')
            ->selectRaw('SUM(m.amount) as total_amount')
            ->groupBy('m.employee_id', 'e.full_name')
            ->havingRaw('SUM(m.amount) = 0')
            ->get();

        $staleZero = [];
        foreach ($noiZero as $row) {
            $evidence = $identityResolver->evaluateNoiCandidateEvidence($row->full_name);
            if (!$evidence['found']) {
                $staleZero[] = $row;
            }
        }

        $this->line('NOI $0 sin evidencia (candidatos a SKIPPED_STALE_ZERO retroactivo): ' . count($staleZero));
        foreach ($staleZero as $r) {
            $this->comment("  ? employee_id={$r->employee_id} | {$r->full_name} — NO se modifica automáticamente. Requiere decidir mecanismo de exclusión de roster antes de implementar --apply para este caso.");
        }

        if (!empty($staleZero)) {
            $this->newLine();
            $this->warn('No existe todavía --apply para NOI legacy — ver docblock del comando.');
        }

        return $exitCode;
    }
}
