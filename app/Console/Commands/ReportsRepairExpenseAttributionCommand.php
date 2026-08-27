<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\ExpenseObservationAttributionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría 27-ago-2026 — aplica (o simula) la atribución de OPEX a
 * colaboradores vía Observación/Justificación para un periodo YA importado, sin
 * necesidad de volver a subir el Excel de Gastos Lendus. Mismo patrón que
 * reports:repair-period: --dry-run por seguridad si no se indica --apply,
 * transaccional, nunca borra/duplica gastos, nunca cambia amount/category/concept.
 *
 *   php artisan reports:repair-expense-attribution 28 --dry-run
 *   php artisan reports:repair-expense-attribution 28 --apply
 */
class ReportsRepairExpenseAttributionCommand extends Command
{
    protected $signature = 'reports:repair-expense-attribution {period : ID del periodo} {--dry-run} {--apply}';

    protected $description = 'Aplica (o simula) la atribución de OPEX a colaboradores vía Observación/Justificación para un periodo ya importado.';

    public function handle(ExpenseObservationAttributionService $service): int
    {
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

        // ── Invariante OPEX general: se mide ANTES y DESPUÉS de aplicar (nunca debe
        // moverse — esto es una reatribución dimensional, no una reclasificación de
        // gasto ni un cambio de monto). Se calcula directo sobre fact_expenses del
        // periodo (suma bruta de todas las fuentes) — no requiere que el snapshot esté
        // generado, y es agnóstica de categoría a propósito: solo prueba que ningún
        // monto se creó/perdió/duplicó, no reproduce la clasificación OPEX completa.
        $totalAntes = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as t')
            ->value('t');

        $results = [];
        DB::transaction(function () use ($period, $dataIds, $service, $apply, &$results) {
            $results = $service->attributeForPeriod($period, $dataIds, dryRun: !$apply);
        });

        $totalDespues = (float) DB::table('fact_expenses')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as t')
            ->value('t');

        $atribuido  = array_values(array_filter($results, fn ($r) => $r['estado'] === 'atribuido'));
        $yaCorrecto = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ya_correcto'));
        $conflictos = array_values(array_filter($results, fn ($r) => $r['estado'] === 'conflicto'));
        $ambiguos   = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ambiguo'));

        $this->line('Evaluados: ' . count($results)
            . ' | Atribuidos ahora: ' . count($atribuido)
            . ' | Ya correctos: ' . count($yaCorrecto)
            . ' | Conflictos (NEEDS_REVIEW): ' . count($conflictos)
            . ' | Ambiguos (NEEDS_REVIEW): ' . count($ambiguos));
        $this->newLine();

        foreach ($atribuido as $r) {
            $this->line("  + fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \$" . number_format($r['amount'], 2)
                . " → employee_id={$r['employee_id']} ({$r['employee_name']}) @ " . ($r['branch_name'] ?? 'sin sucursal')
                . " (método={$r['fuente']}_{$r['metodo']})");
        }

        if (!empty($conflictos)) {
            $this->newLine();
            $this->comment('Conflictos — requieren revisión manual, NO se tocaron:');
            foreach ($conflictos as $r) {
                $this->error("  ! fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | Observación=\"{$r['observation']}\" | Justificación=\"{$r['justification']}\"");
            }
        }
        if (!empty($ambiguos)) {
            $this->newLine();
            $this->comment('Ambiguos — requieren revisión manual, NO se tocaron:');
            foreach ($ambiguos as $r) {
                $texto = $r['fuente'] === 'justification' ? $r['justification'] : $r['observation'];
                $this->error("  ! fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | \"{$texto}\" — candidato sin confianza suficiente.");
            }
        }

        $this->newLine();
        $diff = round($totalDespues - $totalAntes, 2);
        if (abs($diff) > 0.01) {
            $this->error("INVARIANTE VIOLADA: el total de fact_expenses del periodo cambió de \$" . number_format($totalAntes, 2) . ' a $' . number_format($totalDespues, 2) . " (diferencia \${$diff}). Esto NUNCA debe pasar — revisa antes de confiar en el resultado.");
            return 1;
        }
        $this->info('Invariante OK — total de gastos del periodo sin cambios: $' . number_format($totalDespues, 2));

        // Conflictos/ambiguos NUNCA son un código de salida distinto de 0 — son un
        // estado final válido y esperado (el gasto sigue existiendo en OPEX general/
        // sucursal, solo sin colaborador atribuido), no un fallo del comando.
        return 0;
    }
}
