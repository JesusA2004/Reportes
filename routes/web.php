<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use App\Http\Controllers\PeriodController;
use App\Http\Controllers\ReportUploadController;
use App\Http\Controllers\EmployeeBranchAssignmentController;
use App\Http\Controllers\ValidationController;
use App\Http\Controllers\MonthlyReportController;

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

            // Déjalas solo cuando ya existan de verdad
            // Route::get('/{reportUpload}', [ReportUploadController::class, 'show'])->name('show');
            // Route::post('/{reportUpload}/reprocess', [ReportUploadController::class, 'reprocess'])->name('reprocess');
            // Route::get('/{reportUpload}/download', [ReportUploadController::class, 'download'])->name('download');
        });

    Route::prefix('periodos')
        ->name('periodos.')
        ->group(function () {
            Route::get('/', [PeriodController::class, 'index'])->name('index');
            Route::post('/', [PeriodController::class, 'store'])->name('store');
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

    Route::prefix('validaciones')
        ->name('validaciones.')
        ->group(function () {
            Route::get('/', [ValidationController::class, 'index'])->name('index');
        });

    Route::prefix('reportes-mensuales')
        ->name('reportes-mensuales.')
        ->group(function () {
            Route::get('/', [MonthlyReportController::class, 'index'])->name('index');
            Route::get('/{period}', [MonthlyReportController::class, 'show'])->name('show');
            Route::post('/{period}/consolidar', [MonthlyReportController::class, 'consolidate'])->name('consolidate');
            Route::get('/{period}/radiografia.xlsx', [MonthlyReportController::class, 'exportRadiography'])->name('export-radiography');
            Route::get('/{period}/consolidado.csv', [MonthlyReportController::class, 'exportSummary'])->name('export-summary');
        });
});

if (Features::enabled(Features::updatePasswords())) {
    // Espacio para lógica futura si la ocupas.
}

require __DIR__ . '/settings.php';
