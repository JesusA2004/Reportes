<?php

use App\Models\Employee;
use App\Models\Period;
use App\Services\RadiografiaExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Item 10 del pendiente 2026-08-25: demostrar automáticamente que Web, Excel y PDF
 * consumen el MISMO dataset canónico para un scope real, no tres fórmulas.
 *
 * Solo lectura contra la BD de desarrollo real. Nunca RefreshDatabase, nunca escribe.
 *
 * Nota honesta encontrada en esta verificación: Web (scoped-data,
 * RadiografiaExportService::buildSnapshot()) construye el snapshot CON el config de
 * scope (aplica RadiographySnapshotBuilder::applyEmployeeScope()), mientras que
 * Excel/PDF (RadiografiaExportService::exportWithConfig()/exportPdfWithConfig())
 * construyen el snapshot SIN scope y extraen manualmente la fila del colaborador
 * (RadiographyWorkbookBuilder::buildEmployeeFromSnapshot() /
 * RadiografiaExportService::resolveEmployeeRow()). Ambos caminos leen los MISMOS
 * campos internos ("_employee_ids", "_recovery_components", etc.) de la MISMA fila
 * producida por buildEmployeesGestores() — por construcción no pueden divergir en
 * los componentes agregados — pero no pasan por el mismo método. Este test verifica
 * el resultado observable (los números), no la ruta de código.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();
});

it('produces the same recovery/placement/portfolio numbers in Web (scoped-data) and Excel for a real employee', function () {
    $period = Period::query()->where('type', 'monthly')->orderByDesc('id')->first();
    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales en la BD de desarrollo.');
    }

    $summary = \App\Models\PeriodSummary::query()
        ->where('period_id', $period->id)->where('status', 'generated')->first();
    if (!$summary) {
        $this->markTestSkipped("El periodo {$period->label} no tiene radiografía generada.");
    }

    $roster = app(\App\Services\PeriodEmployeeRosterService::class)->rosterRowsForSelector($period)['rows'] ?? [];
    if (empty($roster)) {
        $this->markTestSkipped("El periodo {$period->label} no tiene roster de colaboradores.");
    }

    // Primer colaborador del roster con recuperación > 0 real (para que la
    // comparación sea significativa, no 0 == 0).
    $employeeId = null;
    foreach ($roster as $row) {
        $employeeId = (int) $row['employee_id'];
        $exportService = app(RadiografiaExportService::class);
        $webSnapshot = $exportService->buildSnapshot($period, ['scope' => 'employee', 'employee_id' => $employeeId]);
        if (($webSnapshot['scope']['available'] ?? false) && (float) ($webSnapshot['summary']['recovery_total'] ?? 0) > 0) {
            break;
        }
        $employeeId = null;
    }

    if (!$employeeId) {
        $this->markTestSkipped("Ningún colaborador del roster de {$period->label} tiene recuperación > 0 para comparar.");
    }

    $exportService = app(RadiografiaExportService::class);
    $webSnapshot = $exportService->buildSnapshot($period, ['scope' => 'employee', 'employee_id' => $employeeId]);

    $webRecovery  = round((float) $webSnapshot['summary']['recovery_total'], 2);
    $webPlacement = round((float) $webSnapshot['summary']['placement_total'], 2);
    $webPortfolio = round((float) $webSnapshot['summary']['portfolio_total'], 2);

    $excelPath = $exportService->exportWithConfig($period, ['scope' => 'employee', 'employee_id' => $employeeId, 'report_type' => 'simple']);
    $spreadsheet = IOFactory::load($excelPath);
    $resumen = $spreadsheet->getSheetByName('RESUMEN');
    expect($resumen)->not->toBeNull();

    // La hoja RESUMEN lista MÉTRICA/VALOR fila por fila desde la fila 5 (ver
    // RadiographyWorkbookBuilder::buildEmployeeFromSnapshot()) — buscamos por label
    // en vez de una celda fija, para no depender de un layout exacto.
    $excelValues = [];
    for ($r = 5; $r <= 12; $r++) {
        $label = $resumen->getCell("A{$r}")->getValue();
        $value = $resumen->getCell("B{$r}")->getValue();
        if ($label) {
            $excelValues[$label] = (float) $value;
        }
    }

    $employee = Employee::find($employeeId);
    $this->assertNotNull($employee, "employee_id={$employeeId} debe existir");

    expect(round($excelValues['Recuperación'] ?? -1, 2))->toBe($webRecovery)
        ->and(round($excelValues['Colocación'] ?? -1, 2))->toBe($webPlacement)
        ->and(round($excelValues['Cartera'] ?? -1, 2))->toBe($webPortfolio);

    @unlink($excelPath);
});
