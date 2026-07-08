<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\FinanciamientoMotosAssignmentService;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Console\Command;

class AuditFinanciamientoMotosCommand extends Command
{
    protected $signature = 'reportes:audit-financiamiento-motos
                                {period_id}
                                {--fix : Persiste employee_id/branch_id en fact_expenses (por defecto solo muestra, no modifica)}
                                {--detail : Muestra la tabla completa fila por fila (por defecto solo muestra recién resueltos + sin resolver)}';

    protected $description = 'Auditoría de Financiamiento de Motos/Cascos: empleado encontrado, sucursal asignada, método y confianza por registro. Por defecto NO modifica datos.';

    public function handle(
        FinanciamientoMotosAssignmentService $motosAssignment,
        RadiographySnapshotBuilder $snapshotBuilder,
        BranchRadiographyCalculator $branchCalculator,
    ): int {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $dataIds = $snapshotBuilder->resolveDataIdsPublic($period);
        $dryRun  = !$this->option('fix');

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA FINANCIAMIENTO DE MOTOS/CASCOS — {$period->label} (ID {$periodId})");
        $this->info($dryRun ? '  Modo: solo lectura (usa --fix para persistir)' : '  Modo: --fix (persistiendo employee_id/branch_id)');
        $this->info('════════════════════════════════════════════════════════════');
        $this->line('');

        $results = $motosAssignment->assignForPeriod($period, $dataIds, dryRun: $dryRun);

        if (empty($results)) {
            $this->line('Sin registros de Financiamiento de Motos/Cascos para este periodo.');
            return self::SUCCESS;
        }

        $showAll   = (bool) $this->option('detail');
        $toDisplay = $showAll
            ? $results
            : array_filter($results, fn (array $r) => $r['estado'] !== 'ya_asignado');

        $rows = [];
        foreach ($toDisplay as $r) {
            $rows[] = [
                $r['fact_expense_id'],
                mb_strimwidth($r['nombre_original'], 0, 42, '…'),
                $r['empleado_encontrado'] ? mb_strimwidth($r['empleado_encontrado'], 0, 32, '…') : '—',
                $r['employee_id'] ?? '—',
                $r['sucursal'] ?? '—',
                number_format($r['amount'], 2),
                $r['metodo'],
                $r['confianza'] > 0 ? round($r['confianza'] * 100, 1) . '%' : '—',
                strtoupper($r['estado']),
            ];
        }

        if (!$showAll && count($rows) < count($results)) {
            $this->line('(' . (count($results) - count($rows)) . ' registro(s) ya asignado(s) en corridas anteriores, ocultos — usa --detail para verlos todos)');
        }
        $this->table(
            ['ID', 'Empleado original', 'Empleado encontrado', 'employee_id', 'Sucursal', 'Monto', 'Método', 'Confianza', 'Estado'],
            $rows
        );

        // ── Resumen final ──────────────────────────────────────────────────────
        $sinEmpleado  = array_filter($results, fn (array $r) => $r['employee_id'] === null && $r['estado'] !== 'ya_asignado');
        $sinSucursal  = array_filter($results, fn (array $r) => $r['branch_id'] === null);
        $montoSin     = array_sum(array_map(fn (array $r) => $r['branch_id'] === null ? $r['amount'] : 0.0, $results));

        $porEmpleado = [];
        $porSucursal = [];
        $totalGlobal = 0.0;
        foreach ($results as $r) {
            $totalGlobal += $r['amount'];
            if ($r['employee_id'] !== null) {
                $porEmpleado[$r['employee_id']] = ($porEmpleado[$r['employee_id']] ?? 0.0) + $r['amount'];
            }
            if ($r['sucursal'] !== null) {
                $porSucursal[$r['sucursal']] = ($porSucursal[$r['sucursal']] ?? 0.0) + $r['amount'];
            }
        }
        $sumEmpleados = array_sum($porEmpleado);
        $sumSucursales = array_sum($porSucursal);

        // Duplicados: mismo employee_id + mismo monto + mismo periodo + mismo concepto más
        // de una vez con exactamente los mismos datos de origen (misma fact_expenses.id ya
        // excluye falsos positivos — esto solo detecta filas realmente repetidas).
        $seen = [];
        $duplicados = 0;
        foreach ($results as $r) {
            $key = $r['fact_expense_id'];
            if (isset($seen[$key])) { $duplicados++; }
            $seen[$key] = true;
        }

        $this->line('');
        $this->info('── RESUMEN ──');
        $this->line('Total registros:              ' . count($results));
        $this->line('Registros sin empleado:        ' . count($sinEmpleado));
        $this->line('Registros sin sucursal:        ' . count($sinSucursal));
        $this->line('Monto sin asignar:             $' . number_format($montoSin, 2));
        $this->line('Registros duplicados:          ' . $duplicados);
        $this->line('Total global:                  $' . number_format($totalGlobal, 2));
        $this->line('Suma por empleado:              $' . number_format($sumEmpleados, 2));
        $this->line('Suma por sucursal:               $' . number_format($sumSucursales, 2));
        $this->line('Diferencia global vs sucursales: $' . number_format(abs($totalGlobal - $sumSucursales), 2));

        $allGood = count($sinEmpleado) === 0
            && count($sinSucursal) === 0
            && abs($montoSin) < 0.01
            && $duplicados === 0
            && abs($totalGlobal - $sumSucursales) < 0.01;

        $this->line('');
        if ($allGood) {
            $this->info('✓ Financiamiento de Motos/Cascos: todo resuelto, sin diferencias.');
        } else {
            $this->error('✗ Quedan puntos sin resolver — no cerrar el periodo hasta corregirlos.');
        }

        return $allGood ? self::SUCCESS : self::FAILURE;
    }
}
