<?php

use App\Services\Radiography\BranchRadiographyCalculator;

/**
 * Bug real 2026-08-26 (reportado por el usuario): en el Excel "por sucursal" el
 * desglose de "Nómina y Capital Humano" mostraba Sueldo/Bonos/Gasolina en $0 y la
 * suma de las filas visibles no cuadraba contra "Total Nómina y Capital Humano".
 * BranchRadiographyCalculator::nominaBreakdownFor() debe sumar EXACTO contra
 * nominaTotalFor() sin importar qué combinación de campos venga poblada — nunca
 * una fórmula/lista de categorías aparte que pueda desalinearse.
 */
it('sums to exactly nominaTotalFor() when every field is populated', function () {
    $branch = [
        'nomina_total'       => 100000.0,
        'comisiones'         => 347343.0,
        'bonos'              => 5000.0,
        'bonos_aceleradores' => 1200.0,
        'vacaciones'         => 5639.32,
        'prima_vacacional'   => 900.0,
        'otros_percepciones' => 300.0,
        'imss_patronal'      => 25000.0,
        'gastos_empleados_nomina' => 15000.0,
        'nomina_informativo' => [
            'IMSS'           => 25000.0, // mismo monto que imss_patronal — no debe duplicarse
            'Finiquito'      => 10000.0,
            'Gastos médicos' => 5000.0,
        ],
        // Deducciones — NO deben aparecer ni sumarse en el desglose (no forman parte del total).
        'nomina_detalle' => [
            'Descuentos Infonavit' => 8000.0,
            'Anticipo de nómina'   => 2000.0,
        ],
    ];

    $breakdown = BranchRadiographyCalculator::nominaBreakdownFor($branch);
    $total     = BranchRadiographyCalculator::nominaTotalFor($branch);

    expect(round(array_sum($breakdown), 2))->toBe(round($total, 2));
    // Las deducciones nunca deben colarse en el desglose reconciliable.
    expect($breakdown)->not->toHaveKey('Descuentos Infonavit');
    expect($breakdown)->not->toHaveKey('Anticipo de nómina');
    // 'IMSS' (crudo) no debe aparecer dos veces — solo 'IMSS patronal'.
    expect(array_key_exists('IMSS', $breakdown))->toBeFalse();
    expect($breakdown['IMSS patronal'])->toBe(25000.0);
});

it('sums to exactly nominaTotalFor() when the branch has no nomina_informativo/detalle at all', function () {
    $branch = [
        'nomina_total' => 50000.0, 'comisiones' => 10000.0, 'bonos' => 0.0,
        'bonos_aceleradores' => 0.0, 'vacaciones' => 0.0, 'prima_vacacional' => 0.0,
        'otros_percepciones' => 0.0, 'imss_patronal' => 0.0, 'gastos_empleados_nomina' => 0.0,
    ];

    $breakdown = BranchRadiographyCalculator::nominaBreakdownFor($branch);
    $total     = BranchRadiographyCalculator::nominaTotalFor($branch);

    expect(round(array_sum($breakdown), 2))->toBe(round($total, 2));
    expect(round($total, 2))->toBe(60000.0);
});

it('sums to exactly nominaTotalFor() for an empty branch array (all zero)', function () {
    $breakdown = BranchRadiographyCalculator::nominaBreakdownFor([]);
    $total     = BranchRadiographyCalculator::nominaTotalFor([]);

    expect(array_sum($breakdown))->toBe(0.0);
    expect($total)->toBe(0.0);
});
