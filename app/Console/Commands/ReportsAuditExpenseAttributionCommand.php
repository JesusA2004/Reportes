<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\ExpenseObservationAttributionService;
use Illuminate\Console\Command;

/**
 * Auditoría 27-ago-2026 — atribución de OPEX a colaboradores vía Observación/
 * Justificación (ver Services/ExpenseObservationAttributionService.php).
 * SOLO diagnóstico — nunca escribe (el propio servicio corre en dryRun=true).
 *
 *   php artisan reports:audit-expense-attribution 28
 *   php artisan reports:audit-expense-attribution 28 --examples=40
 */
class ReportsAuditExpenseAttributionCommand extends Command
{
    protected $signature = 'reports:audit-expense-attribution {period : ID del periodo} {--examples=20 : Cuántos ejemplos resueltos mostrar}';

    protected $description = 'Diagnóstico (dry-run) de atribución de OPEX a colaboradores vía Observación/Justificación del Excel de Gastos Lendus.';

    public function handle(ExpenseObservationAttributionService $service): int
    {
        $period = Period::find((int) $this->argument('period'));
        if (!$period) {
            $this->error('No existe el periodo ID=' . $this->argument('period'));
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $this->info("Auditando atribución de OPEX — periodo {$period->label} (ID {$period->id})");
        $this->newLine();

        $results = $service->attributeForPeriod($period, $dataIds, dryRun: true);

        if (empty($results)) {
            $this->comment('No hay gastos del Excel de Lendus con Observación/Justificación para evaluar en este periodo (o falta el roster de colaboradores del periodo).');
            return 0;
        }

        $yaCorrecto  = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ya_correcto'));
        $atribuido   = array_values(array_filter($results, fn ($r) => $r['estado'] === 'atribuido'));
        $porObs      = array_values(array_filter($atribuido, fn ($r) => $r['fuente'] === 'observation'));
        $porJust     = array_values(array_filter($atribuido, fn ($r) => $r['fuente'] === 'justification'));
        $conflictos  = array_values(array_filter($results, fn ($r) => $r['estado'] === 'conflicto'));
        $ambiguos    = array_values(array_filter($results, fn ($r) => $r['estado'] === 'ambiguo'));
        $noAtribuible = array_values(array_filter($results, fn ($r) => $r['estado'] === 'no_atribuible'));

        $montoAtribuido = collect($atribuido)->sum('amount') + collect($yaCorrecto)->sum('amount');

        $this->line('Gastos evaluados: ' . count($results));
        $this->line('Ya atribuidos correctamente: ' . count($yaCorrecto));
        $this->line('Resueltos por Observación: ' . count($porObs));
        $this->line('Resueltos por Justificación: ' . count($porJust));
        $this->line('Conflictos (Observación ≠ Justificación): ' . count($conflictos));
        $this->line('Ambiguos (candidato sin confianza suficiente): ' . count($ambiguos));
        $this->line('No atribuibles (texto no es persona): ' . count($noAtribuible));
        $this->line('Monto total atribuido a colaboradores (nuevo + ya correcto): $' . number_format($montoAtribuido, 2));
        $this->newLine();

        // ── Desglose por concepto ──
        $byConcept = [];
        foreach ($results as $r) {
            $key = $r['concept'] ?: 'Sin concepto';
            $byConcept[$key] ??= ['registros' => 0, 'atribuibles' => 0, 'monto' => 0.0];
            $byConcept[$key]['registros']++;
            if (in_array($r['estado'], ['atribuido', 'ya_correcto'], true)) {
                $byConcept[$key]['atribuibles']++;
                $byConcept[$key]['monto'] += $r['amount'];
            }
        }
        uasort($byConcept, fn ($a, $b) => $b['monto'] <=> $a['monto']);

        $this->comment('Por concepto:');
        foreach ($byConcept as $concept => $data) {
            $this->line("  {$concept}: registros={$data['registros']} atribuibles_employee={$data['atribuibles']} monto_atribuible=\$" . number_format($data['monto'], 2));
        }
        $this->newLine();

        // ── Ejemplos (mínimo pedido: varios conceptos distintos, no solo uno) ──
        $limit = max(1, (int) $this->option('examples'));
        $examples = collect($atribuido)->groupBy('concept')->flatMap(fn ($group) => $group->take(3))->take($limit);

        $this->comment("Ejemplos resueltos (hasta {$limit}):");
        foreach ($examples as $r) {
            $this->newLine();
            $this->line("fact_expenses.id={$r['fact_expense_id']}");
            $this->line("  Concepto: {$r['concept']}");
            if ($r['fuente'] === 'observation') {
                $this->line("  Observación: {$r['observation']}");
            } else {
                $this->line("  Justificación: {$r['justification']}");
            }
            $this->line("  → employee_id={$r['employee_id']} ({$r['employee_name']})");
            $this->line('  → sucursal histórica=' . ($r['branch_name'] ?? 'sin resolver'));
            $this->line("  → método={$r['fuente']}_{$r['metodo']} confianza=" . number_format($r['confianza'], 2));
            $this->line('  → monto=$' . number_format($r['amount'], 2));
        }

        if (!empty($conflictos)) {
            $this->newLine();
            $this->comment('Conflictos (requieren revisión manual — Observación y Justificación nombran personas distintas):');
            foreach (array_slice($conflictos, 0, 10) as $r) {
                $this->error("  fact_expenses.id={$r['fact_expense_id']} | {$r['concept']} | Observación=\"{$r['observation']}\" | Justificación=\"{$r['justification']}\" | \$" . number_format($r['amount'], 2));
            }
        }

        return 0;
    }
}
