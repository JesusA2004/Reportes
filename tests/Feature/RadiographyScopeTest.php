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

/**
 * Regresión real del bug reportado: "colocación por producto" (207,130) no reconciliaba
 * contra el KPI de colocación (445,260) porque buildProductsForPromoter() filtraba por
 * promoter_name EXACTO, perdiendo filas donde promoter_name es NULL y solo hay
 * promoter_code (fact_placements usa COALESCE(promoter_name, promoter_code) como
 * identidad del gestor). Prueba contra fact_placements REAL (buildEmployeesGestores()
 * no usa SQL MySQL-only, a diferencia de build() completo) para que esto no regrese.
 */
it('reconciles placement-by-product against the same colocacion total shown in the KPI, including promoter_code-only rows', function () {
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-PROD', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    $branch = Branch::query()->create(['code' => 'ORIZABA', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'normalized_name' => 'margarita jazmin nolasco dominguez', 'is_active' => true, 'source_system' => 'noi']);

    DB::table('fact_placements')->insert([
        // Fila normal: promoter_name presente.
        ['period_id' => $period->id, 'branch_id' => $branch->id, 'promoter_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'promoter_code' => 'G17', 'product_name' => 's12', 'amount' => 122000, 'created_at' => now(), 'updated_at' => now()],
        // Fila con promoter_name NULL — solo promoter_code (caso que antes se perdía).
        ['period_id' => $period->id, 'branch_id' => $branch->id, 'promoter_name' => null, 'promoter_code' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'product_name' => 'i30', 'amount' => 166000, 'created_at' => now(), 'updated_at' => now()],
        ['period_id' => $period->id, 'branch_id' => $branch->id, 'promoter_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'promoter_code' => 'G17', 'product_name' => 's16', 'amount' => 74000, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $builder = app(RadiographySnapshotBuilder::class);
    $dataIdsProp = new ReflectionProperty($builder, 'dataIds');
    $dataIdsProp->setAccessible(true);
    $dataIdsProp->setValue($builder, [$period->id]);
    $closingProp = new ReflectionProperty($builder, 'closingDataIds');
    $closingProp->setAccessible(true);
    $closingProp->setValue($builder, [$period->id]);

    $gestoresMethod = new ReflectionMethod($builder, 'buildEmployeesGestores');
    $gestoresMethod->setAccessible(true);
    $empGestoresResult = $gestoresMethod->invoke($builder, $period);
    $empGestores = $empGestoresResult['rows'];

    $row = collect($empGestores)->firstWhere('name', 'MARGARITA JAZMIN NOLASCO DOMINGUEZ');
    expect($row)->not->toBeNull();
    // colocacion ya suma las 3 filas, incluida la de promoter_code-only.
    expect((float) $row['colocacion'])->toBe(362000.0);

    $snapshot = makeGeneralSnapshotFixture($period->id, [], $empGestores);
    $result = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, $empGestores, $period]]);

    expect($result['summary']['placement_total'])->toEqualWithDelta(362000.0, 0.01);
    $productSum = array_sum(array_column($result['sections']['products'], 'colocacion'));
    // La garantía central: SUM(colocación por producto) == KPI de colocación, SIEMPRE.
    expect($productSum)->toEqualWithDelta((float) $result['summary']['placement_total'], 0.01)
        ->and($result['sections']['products'])->not->toBeEmpty()
        ->and(collect($result['sections']['products'])->pluck('producto')->all())->toEqualCanonicalizing(['s12', 'i30', 's16']);

    // Los campos internos nunca deben llegar a la fila expuesta en employees_gestores.
    expect($result['sections']['employees_gestores'][0])->not->toHaveKey('_norm_key')
        ->and($result['sections']['employees_gestores'][0])->not->toHaveKey('_placements_by_product');
});

/**
 * Regresión del bug reportado 2026-08-24: se marcaba "no disponible"/"no atribuible"
 * información que SÍ existe en BD (fact_recoveries/fact_portfolios/fact_noi_movements
 * tienen promoter_name/employee_id/days_past_due/concept). Esta prueba usa fact_recoveries
 * y fact_portfolios REALES (no fixtures sintéticas) para verificar que los 4 desgloses
 * nuevos (componentes de recuperación, recuperación por producto, buckets de mora,
 * categorías de nómina) reconcilian EXACTO contra los mismos totales que ya muestra el KPI
 * — nunca un "not_attributable" cuando la BD sí tiene la dimensión.
 */
it('computes real recovery components, recovery by product, mora buckets and payroll categories for an employee scope, all reconciling to the KPI totals', function () {
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-DETAIL', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    $branch   = Branch::query()->create(['code' => 'ORIZABA', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $employee = Employee::query()->create(['full_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'normalized_name' => 'margarita jazmin nolasco dominguez', 'is_active' => true, 'source_system' => 'noi']);

    // fact_recoveries: capital/interés/impuesto + una comisión de apertura + un residual sin
    // componente nombrado (para forzar un otros_recuperacion > 0), en dos productos distintos.
    // Nota: no se combina aquí con una fila ACUERDO CON CLIENTE con charges_due>0 porque esa
    // fila contribuye TANTO a 'charges' (Pass 1, sin filtro de operación) COMO a 'cargos_inicio'
    // (Pass 5, filtrado a ACUERDO CON CLIENTE) — el mismo comportamiento que
    // accumulateRecuperacion() replica intencionalmente a nivel sucursal; se prueba por
    // separado si se necesita, para no mezclar dos aserciones en un solo monto ambiguo.
    $recBase = ['period_id' => $period->id, 'branch_id' => $branch->id, 'promoter_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'is_savehearts' => 0, 'transaction' => 'PAGO', 'capital' => 0, 'interest' => 0, 'tax' => 0, 'charges_due' => 0, 'charges' => 0, 'excedente' => 0, 'savehearts_crece_share' => 0, 'created_at' => now(), 'updated_at' => now()];
    DB::table('fact_recoveries')->insert([
        array_merge($recBase, ['product_name' => 's12', 'operation' => 'PAGO A CONTRATO', 'capital' => 5000, 'interest' => 800, 'tax' => 100, 'total_amount' => 5900]),
        array_merge($recBase, ['product_name' => 'i30', 'operation' => 'COMISIÓN POR APERTURA', 'total_amount' => 50]),
        array_merge($recBase, ['product_name' => 'i30', 'operation' => 'GASTOS DE COBRANZA', 'total_amount' => 20]),
    ]);

    // fact_portfolios: un contrato al corriente y uno vencido (31-60), con las 5 columnas.
    $poBase = ['period_id' => $period->id, 'branch_id' => $branch->id, 'promoter_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'capital_due' => 0, 'interes_atrasado' => 0, 'impuesto_atrasado' => 0, 'saldo_interes_moratorio' => 0, 'saldo_impuesto_interes_moratorio' => 0, 'created_at' => now(), 'updated_at' => now()];
    DB::table('fact_portfolios')->insert([
        array_merge($poBase, ['contract' => 'C-1', 'days_past_due' => 0, 'balance' => 10000]),
        array_merge($poBase, ['contract' => 'C-2', 'days_past_due' => 45, 'balance' => 2000, 'capital_due' => 900, 'interes_atrasado' => 400, 'impuesto_atrasado' => 60, 'saldo_interes_moratorio' => 90, 'saldo_impuesto_interes_moratorio' => 10]),
    ]);

    // fact_noi_movements: percepciones clasificables (P001 Sueldo, P002 Comisiones) + una
    // deducción, para verificar buildEmployeePayrollDetail().
    DB::table('fact_noi_movements')->insert([
        ['period_id' => $period->id, 'employee_id' => $employee->id, 'concept' => 'P001 SUELDO', 'concept_type' => 'percepcion', 'amount' => 8000, 'quantity' => 1, 'payroll_type' => 'NOI', 'raw_row_hash' => 'p1', 'source_row_key' => 'p1', 'created_at' => now(), 'updated_at' => now()],
        ['period_id' => $period->id, 'employee_id' => $employee->id, 'concept' => 'P002 COMISIONES', 'concept_type' => 'percepcion', 'amount' => 3000, 'quantity' => 1, 'payroll_type' => 'NOI', 'raw_row_hash' => 'p2', 'source_row_key' => 'p2', 'created_at' => now(), 'updated_at' => now()],
        ['period_id' => $period->id, 'employee_id' => $employee->id, 'concept' => 'D004 PRESTAMO PERSONAL', 'concept_type' => 'deduccion', 'amount' => 500, 'quantity' => 1, 'payroll_type' => 'NOI', 'raw_row_hash' => 'd1', 'source_row_key' => 'd1', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $builder = app(RadiographySnapshotBuilder::class);
    $dataIdsProp = new ReflectionProperty($builder, 'dataIds');
    $dataIdsProp->setAccessible(true);
    $dataIdsProp->setValue($builder, [$period->id]);
    $closingProp = new ReflectionProperty($builder, 'closingDataIds');
    $closingProp->setAccessible(true);
    $closingProp->setValue($builder, [$period->id]);

    $gestoresMethod = new ReflectionMethod($builder, 'buildEmployeesGestores');
    $gestoresMethod->setAccessible(true);
    $empGestores = $gestoresMethod->invoke($builder, $period)['rows'];

    $row = collect($empGestores)->firstWhere('name', 'MARGARITA JAZMIN NOLASCO DOMINGUEZ');
    expect($row)->not->toBeNull();
    // recuperacion total = 5900 + 50 + 20 = 5970.
    expect((float) $row['recuperacion'])->toBe(5970.0);
    // vencida = suma de las 5 columnas del contrato C-2 = 900+400+60+90+10 = 1460.
    expect((float) $row['vencida'])->toBe(1460.0);

    $snapshot = makeGeneralSnapshotFixture($period->id, [], $empGestores);
    $result = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, $empGestores, $period]]);

    // ── Componentes de recuperación: NUNCA not_attributable cuando reconcilian ──────
    $rc = $result['sections']['recovery_components'];
    expect($rc)->not->toHaveKey('not_attributable');
    $rcSum = array_sum($rc);
    expect($rcSum)->toEqualWithDelta((float) $result['summary']['recovery_total'], 0.01)
        ->and((float) $rc['comision_apertura'])->toBe(50.0)
        ->and((float) $rc['cargos_inicio'])->toBe(0.0)
        // El monto de GASTOS DE COBRANZA (20) no tiene componente nombrado → cae en el residual.
        ->and((float) $rc['otros_recuperacion'])->toBe(20.0);

    // ── Recuperación por producto: reconcilia contra el mismo total, clave distinta de la
    //    sección general (recovery_by_product) que se marca not_attributable más abajo ────
    $rbp = $result['sections']['recovery_by_product_scope'];
    expect($rbp)->not->toHaveKey('not_attributable');
    $rbpSum = array_sum(array_column($rbp, 'recuperacion'));
    expect($rbpSum)->toEqualWithDelta((float) $result['summary']['recovery_total'], 0.01)
        ->and(collect($rbp)->pluck('producto')->all())->toEqualCanonicalizing(['s12', 'i30']);

    // ── Buckets de mora (5 columnas por bucket) — reconcilian contra 'vencida' ──────────
    $buckets = collect($result['sections']['mora_buckets']);
    expect($buckets)->toHaveCount(6); // al_corriente + 5 buckets de mora
    $bucket3160 = $buckets->firstWhere('key', 'mora_31_60');
    expect((float) $bucket3160['capital_due'])->toBe(900.0)
        ->and((float) $bucket3160['interes_atrasado'])->toBe(400.0)
        ->and((float) $bucket3160['monto'])->toBe(1460.0);
    $vencidaSum = $buckets->reject(fn ($b) => $b['key'] === 'al_corriente')->sum('monto');
    expect($vencidaSum)->toEqualWithDelta((float) $result['summary']['overdue_portfolio'], 0.01);

    // ── Categorías de nómina — reconcilian contra noi_percepciones/noi_deducciones ──────
    $payroll = $result['sections']['payroll_detail'];
    $percepSum = array_sum(array_column($payroll['percepciones'], 'monto'));
    expect($percepSum)->toEqualWithDelta((float) $result['summary']['noi_percepciones'], 0.01)
        ->and((float) $payroll['percepciones_total'])->toBe(11000.0)
        ->and((float) $payroll['deducciones_total'])->toBe(500.0)
        ->and(collect($payroll['percepciones'])->firstWhere('concepto', 'Sueldo')['monto'])->toBe(8000.0)
        ->and(collect($payroll['percepciones'])->firstWhere('concepto', 'Comisiones')['monto'])->toBe(3000.0);

    // ── Efectividad de cobranza — ahora calculada por colaborador, no marcada not_attributable ──
    expect($result['sections']['efectividad_cobranza'])->not->toHaveKey('not_attributable');
});

/**
 * "Fuga de datos generales bajo scope individual" — el bug encontrado 2026-08-24: varias
 * secciones quedaban SIN TOCAR bajo scope=employee y mostraban la distribución de TODA la
 * empresa (ej. portfolio_buckets con miles de contratos) en vez del colaborador. Esta prueba
 * verifica explícitamente que, bajo scope=employee, ninguna de esas secciones es idéntica al
 * array general de la fixture.
 */
it('never leaks the company-wide portfolio_buckets / recovery_by_product arrays under employee scope', function () {
    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-NOLEAK', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    $employee = Employee::query()->create(['full_name' => 'EMP SIN DATOS', 'normalized_name' => 'emp sin datos', 'is_active' => true, 'source_system' => 'noi']);

    $employeeRow = ['name' => 'EMP SIN DATOS', 'branch' => 'ORIZABA', 'pagos' => 1000.0, 'bonos' => 0.0, 'descuentos' => 0.0, 'neto' => 1000.0, 'gastos' => 0.0, 'colocacion' => 0.0, 'operaciones' => 0, 'recuperacion' => 0.0, 'cartera' => 0.0, 'vencida' => 0.0, 'mora' => 0.0, 'ingreso_ebitda_base' => 0.0];

    $builder = app(RadiographySnapshotBuilder::class);
    $dataIdsProp = new ReflectionProperty($builder, 'dataIds');
    $dataIdsProp->setAccessible(true);
    $dataIdsProp->setValue($builder, [$period->id]);
    $closingProp = new ReflectionProperty($builder, 'closingDataIds');
    $closingProp->setAccessible(true);
    $closingProp->setValue($builder, [$period->id]);

    $snapshot = makeGeneralSnapshotFixture($period->id, [], [$employeeRow]);
    // Simula secciones generales "grandes" (compañía completa) que un fallback silencioso
    // dejaría pasar tal cual bajo scope=employee.
    $snapshot['sections']['portfolio_buckets'] = [
        ['label' => 'Al corriente', 'contratos' => 4011, 'balance' => 24289499.0, 'vencida' => 0.0],
    ];
    $snapshot['sections']['recovery_by_product'] = ['rows' => [['product' => 'GENERAL', 'total' => 17000000.0]], 'total' => 17000000.0];

    $result = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, [$employeeRow], $period]]);

    expect($result['sections']['portfolio_buckets'])->not->toBe($snapshot['sections']['portfolio_buckets'])
        ->and($result['sections']['recovery_by_product'])->toHaveKey('not_attributable')
        ->and($result['sections']['recovery_by_product']['not_attributable'])->toBeTrue();

    // Con 0 cartera/recuperación este colaborador, el bucket "fugado" (4011 contratos) no
    // puede seguir presente bajo ningún nombre de sección expuesta.
    foreach ($result['sections']['portfolio_buckets'] as $bucket) {
        expect($bucket['contratos'])->not->toBe(4011);
    }
});

/**
 * Regresión de la causa raíz real del bug reportado (auditoría 2026-08-24, ver
 * applyEmployeeScope()): antes, la fila fusionada de un colaborador se ubicaba comparando
 * el nombre CANÓNICO del Employee contra el nombre de DISPLAY de la fila (igual o
 * substring). Cuando un periodo no tenía nómina para ese colaborador, el nombre de display
 * venía de un promoter_name crudo de cartera/colocación — si ese promoter_name era una
 * variante tipográfica (ej. "DOMIGUEZ" en vez de "DOMINGUEZ"), NO es substring literal del
 * nombre canónico aunque el fuzzy-merge (buildCanonicalMap) ya sepa que es la misma persona
 * — el lookup fallaba y el colaborador aparecía sin datos ("funciona un mes, falla otro").
 * Esta prueba reproduce EXACTAMENTE ese patrón en dos periodos distintos: en el periodo A
 * el nombre de display coincide con el canónico (vía nómina), en el B no (solo cartera, con
 * typo) — ambos deben resolver por employee_id, sin excepción de persona/mes.
 */
it('resolves the employee row by employee_id across periods even when the raw promoter name is a typo variant not literally contained in the canonical name', function () {
    $employee = Employee::query()->create(['full_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'normalized_name' => 'margarita jazmin nolasco dominguez', 'is_active' => true, 'source_system' => 'noi']);
    $branch   = Branch::query()->create(['code' => 'ORIZABA', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);

    $periodA = Period::query()->create(['name' => 'Mayo 2026', 'code' => 'M-SCOPE-XPER-A', 'type' => 'monthly', 'year' => 2026, 'month' => 5, 'sequence' => 1, 'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'is_closed' => false]);
    DB::table('fact_noi_movements')->insert([
        ['period_id' => $periodA->id, 'employee_id' => $employee->id, 'concept' => 'P001 SUELDO', 'concept_type' => 'percepcion', 'amount' => 8000, 'quantity' => 1, 'payroll_type' => 'NOI', 'raw_row_hash' => 'a-p1', 'source_row_key' => 'a-p1', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('fact_portfolios')->insert([
        ['period_id' => $periodA->id, 'branch_id' => $branch->id, 'promoter_name' => 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'contract' => 'A-1', 'days_past_due' => 0, 'balance' => 5000, 'capital_due' => 0, 'interes_atrasado' => 0, 'impuesto_atrasado' => 0, 'saldo_interes_moratorio' => 0, 'saldo_impuesto_interes_moratorio' => 0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Periodo B: SIN nómina, solo cartera con promoter_name con typo (no substring del canónico).
    $periodB = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-XPER-B', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    DB::table('fact_portfolios')->insert([
        ['period_id' => $periodB->id, 'branch_id' => $branch->id, 'promoter_name' => 'MARGARITA JAZMIN NOLASCO DOMIGUEZ', 'contract' => 'B-1', 'days_past_due' => 45, 'balance' => 3000, 'capital_due' => 900, 'interes_atrasado' => 100, 'impuesto_atrasado' => 10, 'saldo_interes_moratorio' => 5, 'saldo_impuesto_interes_moratorio' => 1, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $builder = app(RadiographySnapshotBuilder::class);

    foreach ([$periodA, $periodB] as $period) {
        $dataIdsProp = new ReflectionProperty($builder, 'dataIds');
        $dataIdsProp->setAccessible(true);
        $dataIdsProp->setValue($builder, [$period->id]);
        $closingProp = new ReflectionProperty($builder, 'closingDataIds');
        $closingProp->setAccessible(true);
        $closingProp->setValue($builder, [$period->id]);

        $gestoresMethod = new ReflectionMethod($builder, 'buildEmployeesGestores');
        $gestoresMethod->setAccessible(true);
        $empGestores = $gestoresMethod->invoke($builder, $period)['rows'];

        expect($empGestores)->toHaveCount(1);
        expect($empGestores[0]['_employee_ids'])->toContain($employee->id);

        $snapshot = makeGeneralSnapshotFixture($period->id, [], $empGestores);
        $result = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $employee->id, $empGestores, $period]]);

        expect($result['scope']['available'])->toBeTrue()
            ->and($result['scope']['identity_resolution'])->toBe('employee_id');
    }
});

/**
 * Regresión del patrón "Nómina y Capital Humano > 0 pero NOI = 0" (José Alberto Ameca
 * Rodríguez, ver spec del usuario): ocurre cuando una fuente resolvió un employee_id
 * DISTINTO al que trae fact_noi_movements para el mismo colaborador y periodo (fila
 * histórica duplicada sin fusionar). computeNoiPercepcionesDeduccionesForEmployees() debe
 * consultar TODO el grupo de employee_id fusionado, no solo el id que llegó por parámetro
 * — de lo contrario se pierde el NOI real de esa persona.
 */
it('finds NOI perceptions even when they were recorded under a different (duplicate) employee_id merged into the same gestor row', function () {
    $canonical = Employee::query()->create(['full_name' => 'JOSE ALBERTO AMECA RODRIGUEZ', 'normalized_name' => 'jose alberto ameca rodriguez', 'is_active' => true, 'source_system' => 'noi']);
    $duplicate = Employee::query()->create(['full_name' => 'JOSE ALBERTO AMECA RODRIGUEZ', 'normalized_name' => 'jose alberto ameca rodriguez', 'is_active' => true, 'source_system' => 'noi_fiscal']);

    $period = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-SCOPE-DUPID', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);

    // El NOI real quedó bajo el employee_id "duplicado", no el canónico que se usa para buscar.
    DB::table('fact_noi_movements')->insert([
        ['period_id' => $period->id, 'employee_id' => $duplicate->id, 'concept' => 'P001 SUELDO', 'concept_type' => 'percepcion', 'amount' => 7076.26, 'quantity' => 1, 'payroll_type' => 'NOI', 'raw_row_hash' => 'dup1', 'source_row_key' => 'dup1', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $builder = app(RadiographySnapshotBuilder::class);
    $dataIdsProp = new ReflectionProperty($builder, 'dataIds');
    $dataIdsProp->setAccessible(true);
    $dataIdsProp->setValue($builder, [$period->id]);
    $closingProp = new ReflectionProperty($builder, 'closingDataIds');
    $closingProp->setAccessible(true);
    $closingProp->setValue($builder, [$period->id]);

    $gestoresMethod = new ReflectionMethod($builder, 'buildEmployeesGestores');
    $gestoresMethod->setAccessible(true);
    $empGestores = $gestoresMethod->invoke($builder, $period)['rows'];

    $row = collect($empGestores)->firstWhere('name', 'JOSE ALBERTO AMECA RODRIGUEZ');
    expect($row)->not->toBeNull();
    // Ambos employee_id (canónico + duplicado histórico) quedan en el mismo grupo fusionado.
    expect($row['_employee_ids'])->toContain($canonical->id)->toContain($duplicate->id);

    $snapshot = makeGeneralSnapshotFixture($period->id, [], $empGestores);
    // Se busca por el employee_id CANÓNICO (el que el selector de la UI usaría).
    $result = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshot, $canonical->id, $empGestores, $period]]);

    expect($result['scope']['available'])->toBeTrue()
        // Antes de consultar el grupo fusionado, esto habría sido 0 (buscaba solo employee_id=canonical).
        ->and((float) $result['summary']['noi_percepciones'])->toBe(7076.26);
});
