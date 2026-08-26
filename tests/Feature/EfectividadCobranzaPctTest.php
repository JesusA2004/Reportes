<?php

use App\Models\Branch;
use App\Models\Period;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * % real de efectividad de cobranza (2026-08-26, a petición explícita del usuario):
 * el desglose vigente/atrasado/vencido ya existente es una COMPOSICIÓN del dinero
 * cobrado, no una tasa de desempeño. Este KPI nuevo sí es un ratio real:
 *   recuperado de cartera en mora este periodo (atrasado + vencido)
 *   ÷ cartera en mora (DPD>0) al CIERRE del mes anterior
 * Ver RadiographySnapshotBuilder::buildEfectividadCobranza()['efectividad'].
 */
it('computes efectividad_pct as recovered-from-mora over prior month closing mora, scoped by gestor', function () {
    $prevPeriod = Period::query()->create(['name' => 'Mayo 2026', 'code' => 'M-EF-05', 'type' => 'monthly', 'year' => 2026, 'month' => 5, 'sequence' => 1, 'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'is_closed' => false]);
    $currPeriod = Period::query()->create(['name' => 'Junio 2026', 'code' => 'M-EF-06', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    $branch = Branch::query()->create(['code' => 'ORIZABA', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);

    // Cartera en mora al CIERRE del mes anterior (Mayo), para este gestor: un contrato
    // con DPD=45, suma de las 5 columnas vencidas = 900+400+60+90+10 = 1460.
    DB::table('fact_portfolios')->insert([
        'period_id' => $prevPeriod->id, 'branch_id' => $branch->id, 'promoter_name' => 'GESTOR X',
        'contract' => 'C-PREV', 'days_past_due' => 45, 'balance' => 2000,
        'capital_due' => 900, 'interes_atrasado' => 400, 'impuesto_atrasado' => 60,
        'saldo_interes_moratorio' => 90, 'saldo_impuesto_interes_moratorio' => 10,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // Cartera en mora de OTRO gestor en el mismo mes anterior — nunca debe mezclarse.
    DB::table('fact_portfolios')->insert([
        'period_id' => $prevPeriod->id, 'branch_id' => $branch->id, 'promoter_name' => 'OTRO GESTOR',
        'contract' => 'C-OTHER', 'days_past_due' => 45, 'balance' => 5000,
        'capital_due' => 5000, 'interes_atrasado' => 0, 'impuesto_atrasado' => 0,
        'saldo_interes_moratorio' => 0, 'saldo_impuesto_interes_moratorio' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Recuperado de cartera en mora DURANTE Junio, para este gestor: $500 de un contrato
    // con DPD=45 al momento del cobro (bucket "atrasado").
    DB::table('fact_recoveries')->insert([
        'period_id' => $currPeriod->id, 'branch_id' => $branch->id, 'promoter_name' => 'GESTOR X',
        'contract' => 'C-CURR', 'days_past_due' => 45, 'is_savehearts' => 0, 'transaction' => 'PAGO',
        'capital' => 500, 'interest' => 0, 'tax' => 0, 'charges_due' => 0, 'total_amount' => 500,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $builder = app(RadiographySnapshotBuilder::class);
    $builder->warmDataIds($currPeriod);

    $result = $builder->buildEfectividadCobranza($currPeriod, 'GESTOR X');

    expect($result['atrasado']['total'])->toBe(500.0);
    expect($result['efectividad']['recuperado_de_mora'])->toBe(500.0);
    expect($result['efectividad']['cartera_mora_periodo_anterior'])->toBe(1460.0);
    expect($result['efectividad']['periodo_anterior_label'])->toBe($prevPeriod->label);
    // 500 / 1460 * 100 = 34.2465... → 34.25
    expect($result['efectividad']['efectividad_pct'])->toBe(34.25);
});

it('returns null (not 0%) when there is no previous month to use as denominator', function () {
    $onlyPeriod = Period::query()->create(['name' => 'Enero 2020', 'code' => 'M-EF-FIRST', 'type' => 'monthly', 'year' => 2020, 'month' => 1, 'sequence' => 1, 'start_date' => '2020-01-01', 'end_date' => '2020-01-31', 'is_closed' => false]);
    $branch = Branch::query()->create(['code' => 'ORIZABA2', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);

    DB::table('fact_recoveries')->insert([
        'period_id' => $onlyPeriod->id, 'branch_id' => $branch->id, 'promoter_name' => 'GESTOR X',
        'contract' => 'C-CURR', 'days_past_due' => 45, 'is_savehearts' => 0, 'transaction' => 'PAGO',
        'capital' => 500, 'interest' => 0, 'tax' => 0, 'charges_due' => 0, 'total_amount' => 500,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $builder = app(RadiographySnapshotBuilder::class);
    $builder->warmDataIds($onlyPeriod);

    $result = $builder->buildEfectividadCobranza($onlyPeriod, 'GESTOR X');

    expect($result['efectividad']['cartera_mora_periodo_anterior'])->toBeNull();
    expect($result['efectividad']['efectividad_pct'])->toBeNull();
});

it('scopes the prior-month mora denominator by branch, not just by the current recovery rows', function () {
    $prevPeriod = Period::query()->create(['name' => 'Mayo 2026 B', 'code' => 'M-EF-05B', 'type' => 'monthly', 'year' => 2026, 'month' => 5, 'sequence' => 2, 'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'is_closed' => false]);
    $currPeriod = Period::query()->create(['name' => 'Junio 2026 B', 'code' => 'M-EF-06B', 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 2, 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false]);
    $branchA = Branch::query()->create(['code' => 'BR-A', 'name' => 'SUCURSAL A', 'normalized_name' => 'sucursal a', 'is_active' => true]);
    $branchB = Branch::query()->create(['code' => 'BR-B', 'name' => 'SUCURSAL B', 'normalized_name' => 'sucursal b', 'is_active' => true]);

    DB::table('fact_portfolios')->insert([
        ['period_id' => $prevPeriod->id, 'branch_id' => $branchA->id, 'contract' => 'A-1', 'days_past_due' => 45, 'balance' => 1000, 'capital_due' => 1000, 'interes_atrasado' => 0, 'impuesto_atrasado' => 0, 'saldo_interes_moratorio' => 0, 'saldo_impuesto_interes_moratorio' => 0, 'created_at' => now(), 'updated_at' => now()],
        ['period_id' => $prevPeriod->id, 'branch_id' => $branchB->id, 'contract' => 'B-1', 'days_past_due' => 45, 'balance' => 9000, 'capital_due' => 9000, 'interes_atrasado' => 0, 'impuesto_atrasado' => 0, 'saldo_interes_moratorio' => 0, 'saldo_impuesto_interes_moratorio' => 0, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('fact_recoveries')->insert([
        'period_id' => $currPeriod->id, 'branch_id' => $branchA->id, 'contract' => 'A-1', 'days_past_due' => 45,
        'is_savehearts' => 0, 'transaction' => 'PAGO', 'capital' => 1000, 'interest' => 0, 'tax' => 0,
        'charges_due' => 0, 'total_amount' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $builder = app(RadiographySnapshotBuilder::class);
    $builder->warmDataIds($currPeriod);

    $result = $builder->buildEfectividadCobranza($currPeriod, null, $branchA->id);

    // Denominador debe ser SOLO la mora de la sucursal A (1000), no 1000+9000.
    expect($result['efectividad']['cartera_mora_periodo_anterior'])->toBe(1000.0);
    expect($result['efectividad']['efectividad_pct'])->toBe(100.0);
});
