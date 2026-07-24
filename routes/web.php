<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\ReportUploadController;
use App\Http\Controllers\EmployeeBranchAssignmentController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\ValidationController;
use App\Http\Controllers\MonthlyReportController;
use App\Http\Controllers\SystemGuideController;

Route::redirect('/', '/login');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('/dashboard', 'Dashboard')->name('dashboard');

    Route::prefix('historico-general')
        ->name('historico-general.')
        ->group(function () {
            Route::get('/', [ReportUploadController::class, 'index'])->name('index');
            Route::post('/', [ReportUploadController::class, 'store'])->name('store');
            Route::delete('/{reportUpload}', [ReportUploadController::class, 'destroy'])->name('destroy');
            Route::post('/{reportUpload}/analizar', [ReportUploadController::class, 'analyze'])->name('analyze');
            Route::post('/{period}/actualizar-bd', [ReportUploadController::class, 'updateDatabase'])->name('update-database');
            Route::post('/{period}/actualizacion-bd/cancelar', [ReportUploadController::class, 'cancelDatabaseUpdate'])->name('cancel-database-update');
            Route::post('/{period}/actualizacion-bd/limpiar', [ReportUploadController::class, 'clearStuckDatabaseUpdate'])->name('clear-stuck-database-update');
            Route::get('/{period}/actualizacion-bd/progreso', [ReportUploadController::class, 'loadProgressStatus'])->name('load-progress-status');
            Route::post('/{period}/actualizacion-bd/procesar-ahora', [ReportUploadController::class, 'processNow'])->name('process-now');
            Route::post('/{period}/actualizacion-bd/reencolar', [ReportUploadController::class, 'requeueRun'])->name('requeue-run');
            Route::get('/{period}/incidencias', [ReportUploadController::class, 'incidents'])->name('incidents');
            Route::post('/{period}/incidencias/{incident}/resolver', [ReportUploadController::class, 'resolveIncident'])->name('incidents.resolve');
            Route::post('/{period}/incidencias/refrescar', [ReportUploadController::class, 'refreshIncidents'])->name('incidents.refresh');
            Route::get('/{period}/personas-sin-sucursal', [ReportUploadController::class, 'personasSinSucursal'])->name('personas-sin-sucursal');
            Route::post('/{period}/personas-sin-sucursal/confirmar-coincidencia', [ReportUploadController::class, 'confirmarCoincidencia'])->name('personas-sin-sucursal.confirmar');
            Route::post('/{period}/coincidencias/descartar', [ReportUploadController::class, 'descartarCoincidencia'])->name('coincidencias.descartar');
            Route::post('/{period}/incidencias/ubicacion-pendiente/resolver', [ReportUploadController::class, 'resolvePendingLocation'])->name('incidents.resolve-location');
            Route::post('/{period}/generar-radiografia', [ReportUploadController::class, 'generateRadiography'])->name('generate-radiography');
            Route::get('/{period}/generar-reporte/progreso', [ReportUploadController::class, 'generationProgress'])->name('generation-progress');
            Route::post('/{period}/generar-reporte/cancelar', [ReportUploadController::class, 'cancelGeneration'])->name('cancel-generation');
            Route::post('/{period}/generar-reporte/procesar-ahora', [ReportUploadController::class, 'processGenerationNow'])->name('process-generation-now');
            Route::post('/{period}/procesar-fuentes-pendientes', [ReportUploadController::class, 'processPendingSources'])->name('process-pending-sources');
            Route::post('/{period}/reprocesar-fuentes-con-error', [ReportUploadController::class, 'reprocessFailedSources'])->name('reprocess-failed-sources');
            Route::post('/{period}/reprocesar-todo', [ReportUploadController::class, 'reprocessAll'])->name('reprocess-all');
        });

    Route::prefix('periodos')
        ->name('periodos.')
        ->group(function () {
            Route::get('/', [PeriodController::class, 'index'])->name('index');
            Route::post('/', [PeriodController::class, 'store'])->name('store');
            Route::delete('/{period}', [PeriodController::class, 'destroy'])->name('destroy');
            Route::post('/{period}/close', [PeriodController::class, 'close'])->name('close');
            Route::post('/{period}/open', [PeriodController::class, 'open'])->name('open');
        });

    Route::prefix('asignaciones-empleado-sucursal')
        ->name('asignaciones-empleado-sucursal.')
        ->group(function () {
            Route::get('/', [EmployeeBranchAssignmentController::class, 'index'])->name('index');
            Route::get('/pendientes', [EmployeeBranchAssignmentController::class, 'pending'])->name('pending');
            Route::post('/match-automatico', [EmployeeBranchAssignmentController::class, 'autoMatch'])->name('auto-match');
            Route::post('/{assignment}/match-manual', [EmployeeBranchAssignmentController::class, 'manualMatch'])->name('manual-match');
            Route::put('/{assignment}', [EmployeeBranchAssignmentController::class, 'update'])->name('update');
        });

    Route::prefix('empleados')
        ->name('empleados.')
        ->group(function () {
            Route::post('/batch-asignar-sucursal', [EmployeeController::class, 'batchAssignBranch'])->name('batch-assign-branch');
            Route::post('/{employee}/asignar-sucursal', [EmployeeController::class, 'assignBranch'])->name('assign-branch');
        });

    Route::prefix('validaciones')
        ->name('validaciones.')
        ->group(function () {
            Route::get('/', [ValidationController::class, 'index'])->name('index');
        });

    Route::prefix('guia-sistema')
        ->name('guia-sistema.')
        ->group(function () {
            Route::get('/', [SystemGuideController::class, 'index'])->name('index');
            Route::get('/pdf', [SystemGuideController::class, 'pdf'])->name('pdf');
        });

    Route::prefix('reportes-mensuales')
        ->name('reportes-mensuales.')
        ->group(function () {
            Route::get('/', [MonthlyReportController::class, 'index'])->name('index');
            Route::get('/{period}', [MonthlyReportController::class, 'show'])->name('show');
            Route::post('/{period}/consolidar', [MonthlyReportController::class, 'consolidate'])->name('consolidate');
            Route::get('/{period}/estado-radiografia', [MonthlyReportController::class, 'status'])->name('status');
            Route::get('/{period}/preview', [MonthlyReportController::class, 'previewPage'])->name('preview');
            Route::post('/{period}/saldo-inicial', [MonthlyReportController::class, 'updateSaldoInicial'])->name('update-saldo-inicial');
            Route::get('/{period}/radiografia.xlsx', [MonthlyReportController::class, 'exportRadiography'])->name('export-radiography');
            Route::get('/{period}/radiografia.pdf', [MonthlyReportController::class, 'exportRadiographyPdf'])->name('export-radiography-pdf');
            Route::get('/{period}/export-filtrado.xlsx', [MonthlyReportController::class, 'exportFilteredRadiography'])->name('export-filtered-radiography');
            Route::get('/{period}/export-filtrado.pdf', [MonthlyReportController::class, 'exportFilteredRadiographyPdf'])->name('export-filtered-radiography-pdf');
            Route::get('/{period}/filtrado-datos', [MonthlyReportController::class, 'filteredPreviewData'])->name('filtered-preview-data');
            Route::get('/{period}/consolidado.csv', [MonthlyReportController::class, 'exportSummary'])->name('export-summary');
        });
});

if (Features::enabled(Features::updatePasswords())) {
    // Espacio para lógica futura si la ocupas.
}

require __DIR__ . '/settings.php';
