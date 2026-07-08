<?php

use App\Models\Branch;
use App\Models\Period;
use App\Models\Recovery;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// accumulateRecuperacion() runs unconditional fallback passes (Pass 2/4/6) that resolve
// branch_id-less rows via LEFT(JSON_UNQUOTE(JSON_EXTRACT(raw_payload, ...))) — MySQL-only
// functions. SQLite has JSON_EXTRACT natively but not JSON_UNQUOTE/LEFT, and the query
// must still be preparable even when zero rows match, so every test needs this shim.
beforeEach(function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value, 1);
        $pdo->sqliteCreateFunction('LEFT', fn ($value, $len) => substr((string) $value, 0, (int) $len), 2);
    }
});

/**
 * Cubre el bug reportado en Junio 2026: el 30% reconocido de Seguro CRECE se sumaba
 * al total global y a la sucursal, pero quedaba oculto dentro del residual "otros"
 * y nunca llegaba al desglose por producto — global != componentes != productos.
 *
 * Estas pruebas llaman accumulateRecuperacion()/buildRecoveryByProduct() de forma
 * DIRIGIDA (sin pasar por buildBranches() completo) porque buildBranches() también
 * ejecuta accumulateColocacion() y otros accumuladores con funciones SQL MySQL-only
 * (JSON_EXTRACT, LEFT) no soportadas por SQLite, que es lo que usa esta suite de
 * pruebas (RefreshDatabase). Recuperación en sí no depende de ninguna de esas
 * funciones para el camino principal (branch_id ya mapeado), así que se puede probar
 * de forma aislada y genuina contra la MISMA fuente de verdad que usa producción.
 */
function makePeriodForRecoveryTest(): Period
{
    return Period::query()->create([
        'name' => 'Junio 2026',
        'code' => 'M-2026-06-TEST',
        'type' => 'monthly',
        'year' => 2026,
        'month' => 6,
        'sequence' => 1,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'is_closed' => false,
    ]);
}

function makeOperativeBranchForRecoveryTest(string $name = 'TULA'): Branch
{
    return Branch::query()->create([
        'code' => $name,
        'name' => $name,
        'normalized_name' => strtolower($name),
        'is_active' => true,
    ]);
}

/** Runs accumulateRecuperacion() for a single operative branch, returns its summary. */
function runRecuperacionForBranch(BranchRadiographyCalculator $calc, Period $period, Branch $branch): array
{
    $summaries = [$branch->name => $calc->emptyBranchSummary($branch->name)];
    $operativeMap = [$branch->id => $branch->name];
    $calc->accumulateRecuperacion([$period->id], [$branch->id], $operativeMap, $summaries, []);

    return $summaries[$branch->name];
}

it('recognizes only 30% of Seguro CRECE as recovered income', function () {
    $period = makePeriodForRecoveryTest();
    $branch = makeOperativeBranchForRecoveryTest();

    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0001',
        'product_name' => 'CRECE12', 'operation' => 'COBRO DE SEGURO', 'concept' => 'SEGURO CRECE',
        'transaction' => 'PAGO', 'capital' => 0, 'interest' => 0, 'tax' => 0, 'charges' => 0,
        'charges_due' => 0, 'excedente' => 0, 'total_amount' => 1000.00,
        'is_savehearts' => true, 'savehearts_crece_share' => 300.00,
    ]);

    $summary = runRecuperacionForBranch(app(BranchRadiographyCalculator::class), $period, $branch);

    expect((float) $summary['recuperacion_total'])->toBe(300.00)
        ->and((float) $summary['seguro_crece_reconocido'])->toBe(300.00)
        ->and((float) $summary['seguro_crece_bruto'])->toBe(1000.00);
});

it('never recognizes the 70% channeled portion of Seguro CRECE as income', function () {
    $period = makePeriodForRecoveryTest();
    $branch = makeOperativeBranchForRecoveryTest();

    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0002',
        'product_name' => 'CRECE24', 'operation' => 'COBRO DE SEGURO', 'concept' => 'SEGURO CRECE',
        'transaction' => 'PAGO', 'capital' => 0, 'interest' => 0, 'tax' => 0, 'charges' => 0,
        'charges_due' => 0, 'excedente' => 0, 'total_amount' => 2000.00,
        'is_savehearts' => true, 'savehearts_crece_share' => 600.00,
    ]);

    $summary = runRecuperacionForBranch(app(BranchRadiographyCalculator::class), $period, $branch);

    // recovery_total debe ser exactamente el 30% (600), NUNCA el 100% (2000) ni el 70% (1400)
    expect((float) $summary['recuperacion_total'])->toBe(600.00);
});

it('excludes Savehearts (non-CRECE) coverage entirely from recovery', function () {
    $period = makePeriodForRecoveryTest();
    $branch = makeOperativeBranchForRecoveryTest();

    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0003',
        'product_name' => 'CREDITO PERSONAL', 'operation' => 'COBRO DE SEGURO', 'concept' => 'COBERTURA SAVEHEARTS',
        'transaction' => 'PAGO', 'capital' => 0, 'interest' => 0, 'tax' => 0, 'charges' => 0,
        'charges_due' => 0, 'excedente' => 0, 'total_amount' => 500.00,
        'is_savehearts' => true, 'savehearts_crece_share' => 0.00,
    ]);

    $summary = runRecuperacionForBranch(app(BranchRadiographyCalculator::class), $period, $branch);

    expect((float) $summary['recuperacion_total'])->toBe(0.00)
        ->and((float) $summary['seguro_savehearts_bruto'])->toBe(500.00);
});

it('excludes Cobertura Crédito Grupal / Comadres entirely from recovery', function () {
    $period = makePeriodForRecoveryTest();
    $branch = makeOperativeBranchForRecoveryTest();

    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0004',
        'product_name' => 'CREDITO GRUPAL', 'operation' => 'COBERTURA CREDITO GRUPAL', 'concept' => 'COMADRES',
        'transaction' => 'PAGO', 'capital' => 0, 'interest' => 0, 'tax' => 0, 'charges' => 0,
        'charges_due' => 0, 'excedente' => 0, 'total_amount' => 400.00,
        'is_savehearts' => true, 'savehearts_crece_share' => 0.00,
    ]);

    $summary = runRecuperacionForBranch(app(BranchRadiographyCalculator::class), $period, $branch);

    expect((float) $summary['recuperacion_total'])->toBe(0.00)
        ->and((float) $summary['seguro_comadres_bruto'])->toBe(400.00);
});

it('reconciles branch recovery exactly against the sum of its named components', function () {
    $period = makePeriodForRecoveryTest();
    $branch = makeOperativeBranchForRecoveryTest();

    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0005',
        'product_name' => 'CREDITO PERSONAL', 'operation' => 'PAGO A CONTRATO', 'concept' => 'ABONO',
        'transaction' => 'PAGO', 'capital' => 800.00, 'interest' => 150.00, 'tax' => 24.00,
        'charges' => 10.00, 'charges_due' => 5.00, 'excedente' => 3.00, 'total_amount' => 992.00,
        'is_savehearts' => false, 'savehearts_crece_share' => 0,
    ]);
    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0006',
        'product_name' => 'CRECE12', 'operation' => 'COBRO DE SEGURO', 'concept' => 'SEGURO CRECE',
        'transaction' => 'PAGO', 'capital' => 0, 'interest' => 0, 'tax' => 0, 'charges' => 0,
        'charges_due' => 0, 'excedente' => 0, 'total_amount' => 1000.00,
        'is_savehearts' => true, 'savehearts_crece_share' => 300.00,
    ]);
    // Condoned row — must NOT contribute anywhere
    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0007',
        'product_name' => 'CREDITO PERSONAL', 'operation' => 'UNIFICACION DE CARTERA', 'concept' => 'CONDONACION',
        'transaction' => 'CONDONACION', 'capital' => 500.00, 'interest' => 50.00, 'tax' => 8.00,
        'charges' => 0, 'charges_due' => 0, 'excedente' => 0, 'total_amount' => 558.00,
        'is_savehearts' => false, 'savehearts_crece_share' => 0,
    ]);
    // Unclassified "otros" row (all component columns zero, real total_amount)
    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0008',
        'product_name' => 'CREDITO PERSONAL', 'operation' => 'GASTOS DE COBRANZA', 'concept' => 'GASTOS DE COBRANZA',
        'transaction' => 'PAGO', 'capital' => 0, 'interest' => 0, 'tax' => 0, 'charges' => 0,
        'charges_due' => 0, 'excedente' => 0, 'total_amount' => 75.00,
        'is_savehearts' => false, 'savehearts_crece_share' => 0,
    ]);

    $summary = runRecuperacionForBranch(app(BranchRadiographyCalculator::class), $period, $branch);

    $total = round((float) $summary['recuperacion_total'], 2);
    $componentSum = round(
        $summary['capital_recuperado'] + $summary['interes_recuperado'] + $summary['impuesto_recuperado']
        + $summary['charges'] + $summary['cargos_adicionales'] + $summary['excedente_recuperado']
        + $summary['cargos_inicio'] + $summary['comision_apertura'] + $summary['seguro_crece_reconocido']
        + $summary['otros_recuperacion'],
        2
    );

    expect($total)->toBe(round(992.00 + 300.00 + 75.00, 2)) // condonación excluida por completo
        ->and($componentSum)->toBe($total);
});

it('reconciles recovery exactly against the by-product breakdown, including CRECE 30% as its own column', function () {
    $period = makePeriodForRecoveryTest();
    $branch = makeOperativeBranchForRecoveryTest();

    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0009',
        'product_name' => 'CREDITO PERSONAL', 'operation' => 'PAGO A CONTRATO', 'concept' => 'ABONO',
        'transaction' => 'PAGO', 'capital' => 800.00, 'interest' => 150.00, 'tax' => 24.00,
        'charges' => 10.00, 'charges_due' => 0, 'excedente' => 0, 'total_amount' => 984.00,
        'is_savehearts' => false, 'savehearts_crece_share' => 0,
    ]);
    Recovery::create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => 'TUL-0010',
        'product_name' => 'CRECE12 SAC', 'operation' => 'COBRO DE SEGURO', 'concept' => 'SEGURO CRECE',
        'transaction' => 'PAGO', 'capital' => 0, 'interest' => 0, 'tax' => 0, 'charges' => 0,
        'charges_due' => 0, 'excedente' => 0, 'total_amount' => 1000.00,
        'is_savehearts' => true, 'savehearts_crece_share' => 300.00,
    ]);

    $calc = app(BranchRadiographyCalculator::class);
    $summary = runRecuperacionForBranch($calc, $period, $branch);
    $byProduct = $calc->buildRecoveryByProduct([$period->id]);

    $branchTotal = round((float) $summary['recuperacion_total'], 2);
    $productSum  = round((float) $byProduct['total'], 2);

    expect($productSum)->toBe($branchTotal)
        ->and($productSum)->toBe(984.00 + 300.00);

    // The CRECE12 SAC product row must carry its own 30% share, not lump it into "otros"
    $creceRow = collect($byProduct['rows'])->firstWhere('product', 'CRECE12 SAC');
    expect($creceRow)->not->toBeNull()
        ->and((float) $creceRow['seguro_crece_reconocido'])->toBe(300.00)
        ->and((float) $creceRow['total'])->toBe(300.00);
});

it('production guard throws when recovery totals do not reconcile', function () {
    // Pure array math — no DB involved — exercising the ACTUAL production guard
    // (RadiographySnapshotBuilder::assertRecoveryReconciles) used before every report
    // generation, for every period, so a future data-format regression stops the
    // report instead of silently shipping a descuadre.
    $global = [
        'recuperacion_total' => 1000.00,
        'capital_recuperado' => 1.00, // tampered: should be 900 to reconcile
        'interes_recuperado' => 90.00,
        'impuesto_recuperado' => 10.00,
        'charges' => 0.0, 'cargos_adicionales' => 0.0, 'excedente_recuperado' => 0.0,
        'cargos_inicio' => 0.0, 'comision_apertura' => 0.0, 'seguro_crece_reconocido' => 0.0,
        'otros_recuperacion' => 0.0,
    ];
    $branches = [['recuperacion_total' => 1000.00]];
    $byProduct = ['total' => 1000.00];

    $snapshotBuilder = app(\App\Services\Radiography\RadiographySnapshotBuilder::class);
    $guard = new ReflectionMethod($snapshotBuilder, 'assertRecoveryReconciles');
    $guard->setAccessible(true);

    expect(fn () => $guard->invoke($snapshotBuilder, $global, $branches, $byProduct))
        ->toThrow(RuntimeException::class, 'Recuperación descuadrada');

    // Sanity check: the SAME guard does NOT throw once components are corrected.
    $global['capital_recuperado'] = 900.00;
    $guard->invoke($snapshotBuilder, $global, $branches, $byProduct);
});
