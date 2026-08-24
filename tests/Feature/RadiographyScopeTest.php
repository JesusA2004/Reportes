<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * RadiographySnapshotBuilder::build() no se puede correr completo bajo SQLite (usa
 * REGEXP/JSON_EXTRACT/JSON_UNQUOTE de MySQL en varios acumuladores — mismo motivo por
 * el que RecoveryReconciliationTest prueba sus piezas de forma aislada). applyScope()
 * en sí mismo es manipulación de arrays + un puñado de queries portables (WHERE/SUM/
 * LOWER), así que se prueba invocándolo directamente vía Reflection sobre un snapshot
 * sintético con la MISMA forma que produce build() — cubre la garantía real: un scope
 * nunca hereda silenciosamente los totales generales.
 */
function invokeScopeMethod(RadiographySnapshotBuilder $builder, string $method, array $args): array
{
    $ref = new ReflectionMethod($builder, $method);
    $ref->setAccessible(true);

    // applyBranchScope()/applyEmployeeScope() leen $this->dataIds / $this->branchCalculator
    // — normalmente los fija build(). Los fijamos a mano para la prueba aislada.
    $dataIdsProp = new ReflectionProperty($builder, 'dataIds');
    $dataIdsProp->setAccessible(true);
    $dataIdsProp->setValue($builder, $args['dataIds']);

    return $ref->invoke($builder, ...$args['args']);
}

function makeGeneralSnapshotFixture(int $periodId, array $branchRows, array $employeeRows): array
{
    return [
        'period' => ['id' => $periodId, 'label' => 'Junio 2026'],
        'generated_at' => '01/06/2026 10:00',
        'summary' => [
            'recovery_total' => 17000000.0, 'placement_total' => 12000000.0, 'portfolio_total' => 38000000.0,
            'overdue_portfolio' => 14000000.0, 'mora_index' => 36.8, 'expenses_total' => 758000.0,
            'nomina_capital_humano_total' => 2489000.0, 'ebitda_final' => 2058000.0, 'margen_ebitda' => 34.5,
            'unificacion_excluida' => 0.0, 'condonacion_excluida' => 0.0,
        ],
        'branch_radiography' => ['branches' => $branchRows, 'global' => ['recuperacion_total' => 17000000.0], 'unassigned' => []],
        'sections' => [
            'employees_gestores' => $employeeRows,
            'mora_by_gestor' => [],
            'active_loans' => [],
            'portfolio_by_branch_product' => [],
            'placement_by_branch_product' => [],
            'mora_by_branch_product' => [],
            'mora_by_branch' => [],
            'payroll_by_branch_concept' => ['data' => [], 'incidents' => []],
            'expenses_detail' => ['total' => 0, 'byBranch' => [], 'byEmployee' => [], 'byCategory' => [], 'byConcept' => [], 'bySource' => []],
            'products' => [['producto' => 'GENERAL_PRODUCT', 'operaciones' => 999, 'colocacion' => 12000000.0]],
            'interbranch_loans' => ['total' => 1000.0],
            'corporate_funding' => ['total' => 2000.0],
            'fondeo_detalle' => ['total' => 3000.0],
        ],
        'charts' => [
            'recovery_by_branch' => [['label' => 'ORIZABA', 'value' => 700000.0, 'pct' => 100.0], ['label' => 'TULA', 'value' => 300000.0, 'pct' => 43.0]],
        ],
    ];
}

it('branch scope never leaks the general totals into summary/sections', function () {
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-BR', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    $orizaba = Branch::query()->create(['code' => 'ORIZABA', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);

    $branchRow = ['sucursal' => 'ORIZABA', 'recuperacion_total' => 700000.0, 'colocacion' => 450000.0, 'valor_cartera' => 1080000.0, 'mora_0_30' => 1000.0, 'mora_31_60' => 500.0, 'mora_61_90' => 0.0, 'mora_91_120' => 0.0, 'mora_120_plus' => 0.0, 'gastos_operativos' => 50000.0, 'nomina_total' => 40000.0, 'comisiones' => 0.0, 'bonos' => 0.0];

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = makeGeneralSnapshotFixture($period->id, [$branchRow], []);

    $result = invokeScopeMethod($builder, 'applyBranchScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $orizaba->id, $period]]);

    expect($result['scope']['type'])->toBe('branch')
        ->and($result['scope']['available'])->toBeTrue()
        ->and((float) $result['summary']['recovery_total'])->toBe(700000.0)
        ->and((float) $result['summary']['recovery_total'])->not->toBe(17000000.0)
        ->and($result['branch_radiography']['branches'])->toHaveCount(1)
        ->and($result['sections']['interbranch_loans']['not_attributable'])->toBeTrue();

    // La gráfica "por sucursal" se reduce a la sucursal activa, nunca queda mostrando todas.
    expect($result['charts']['recovery_by_branch'])->toHaveCount(1)
        ->and($result['charts']['recovery_by_branch'][0]['label'])->toBe('ORIZABA');
});

it('employee scope never leaks the general totals and marks unattributable fields as null, not zero', function () {
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-EMP', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);

    $employee = Employee::query()->create(['full_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'normalized_name' => 'margarita jazmin nolasco dominguez', 'is_active' => true, 'source_system' => 'noi']);

    $employeeRow = [
        'name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'branch' => 'ORIZABA', 'pagos' => 40000.0, 'bonos' => 3546.46,
        'descuentos' => 7847.09, 'neto' => 43546.46, 'gastos' => 0.0, 'colocacion' => 445260.0, 'operaciones' => 12,
        'recuperacion' => 694885.0, 'cartera' => 1083144.70, 'vencida' => 1590.39, 'mora' => 0.15, 'ingreso_ebitda_base' => 190000.0,
    ];

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = makeGeneralSnapshotFixture($period->id, [], [$employeeRow]);

    $result = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$employeeRow], $period]]);

    expect($result['scope']['type'])->toBe('employee')
        ->and($result['scope']['employee_name'])->toBe('MARGARITA JAZMIN NOLASCO DOMINGUEZ')
        ->and($result['scope']['branch_name'])->toBe('ORIZABA')
        ->and((float) $result['summary']['recovery_total'])->toBe(694885.0)
        ->and((float) $result['summary']['placement_total'])->toBe(445260.0)
        ->and((float) $result['summary']['portfolio_total'])->toBe(1083144.70)
        // NUNCA los totales generales de la fixture (17M/12M/38M).
        ->and((float) $result['summary']['recovery_total'])->not->toBe(17000000.0)
        ->and((float) $result['summary']['placement_total'])->not->toBe(12000000.0)
        ->and((float) $result['summary']['portfolio_total'])->not->toBe(38000000.0)
        // No atribuible a un colaborador → null, JAMÁS 0 disfrazado de dato real.
        ->and($result['summary']['excedentes_total'])->toBeNull()
        ->and($result['summary']['fondeo_total'])->toBeNull()
        ->and($result['sections']['interbranch_loans']['not_attributable'])->toBeTrue()
        ->and($result['sections']['corporate_funding']['not_attributable'])->toBeTrue();

    // EBITDA del colaborador = ingreso_ebitda_base - (gastos + neto) = 190000 - (0 + 43546.46)
    expect(round((float) $result['summary']['ebitda_final'], 2))->toBe(round(190000.0 - 43546.46, 2));
});

it('employee scope returns an explicitly empty (not general) snapshot when the employee has no data this period', function () {
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-EMPTY', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    $employee = Employee::query()->create(['full_name' => 'NADIE ESTE PERIODO', 'normalized_name' => 'nadie este periodo', 'is_active' => true, 'source_system' => 'noi']);

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = makeGeneralSnapshotFixture($period->id, [], []); // employees_gestores vacío — sin fila para este empleado

    $result = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [], $period]]);

    expect($result['scope']['available'])->toBeFalse()
        // El summary debe quedar en null, NUNCA en los totales generales de la fixture.
        ->and($result['summary']['recovery_total'])->toBeNull()
        ->and($result['summary']['placement_total'])->toBeNull()
        ->and($result['sections']['products'])->toBe([]);
});

it('branch scope resolves percepciones/deducciones scoped to employees assigned to that branch only', function () {
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-PD', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    $orizaba = Branch::query()->create(['code' => 'ORIZABA', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $tula    = Branch::query()->create(['code' => 'TULA', 'name' => 'TULA', 'normalized_name' => 'tula', 'is_active' => true]);

    $empOrizaba = Employee::query()->create(['full_name' => 'EMP ORIZABA', 'normalized_name' => 'emp orizaba', 'is_active' => true, 'source_system' => 'noi']);
    $empTula    = Employee::query()->create(['full_name' => 'EMP TULA', 'normalized_name' => 'emp tula', 'is_active' => true, 'source_system' => 'noi']);

    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $empOrizaba->id, 'branch_id' => $orizaba->id, 'source_type' => 'lendus', 'match_type' => 'exact', 'confidence' => 1, 'was_manual_reviewed' => false]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $empTula->id, 'branch_id' => $tula->id, 'source_type' => 'lendus', 'match_type' => 'exact', 'confidence' => 1, 'was_manual_reviewed' => false]);

    DB::table('fact_noi_movements')->insert([
        ['period_id' => $period->id, 'employee_id' => $empOrizaba->id, 'concept' => 'Sueldo', 'concept_type' => 'percepcion', 'amount' => 1000, 'quantity' => 1, 'payroll_type' => 'NOI', 'raw_row_hash' => 'a1', 'source_row_key' => 'a1', 'created_at' => now(), 'updated_at' => now()],
        ['period_id' => $period->id, 'employee_id' => $empTula->id, 'concept' => 'Sueldo', 'concept_type' => 'percepcion', 'amount' => 5000, 'quantity' => 1, 'payroll_type' => 'NOI', 'raw_row_hash' => 'a2', 'source_row_key' => 'a2', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $branchRow = ['sucursal' => 'ORIZABA', 'recuperacion_total' => 1.0, 'colocacion' => 1.0, 'valor_cartera' => 1.0, 'mora_0_30' => 0, 'mora_31_60' => 0, 'mora_61_90' => 0, 'mora_91_120' => 0, 'mora_120_plus' => 0, 'gastos_operativos' => 0, 'nomina_total' => 0, 'comisiones' => 0, 'bonos' => 0];

    $builder = app(RadiographySnapshotBuilder::class);
    $snapshot = makeGeneralSnapshotFixture($period->id, [$branchRow], []);

    $result = invokeScopeMethod($builder, 'applyBranchScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $orizaba->id, $period]]);

    // Solo el empleado de ORIZABA cuenta — nunca el de TULA (5000).
    expect((float) $result['summary']['noi_percepciones'])->toBe(1000.0);
});
