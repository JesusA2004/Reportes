<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

/*
|--------------------------------------------------------------------------
| Controllers
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportUploadController;

// Futuro / cuando los crees
// use App\Http\Controllers\PeriodController;
// use App\Http\Controllers\DataSourceController;
// use App\Http\Controllers\ProcessRunController;
// use App\Http\Controllers\EmployeeController;
// use App\Http\Controllers\BranchController;
// use App\Http\Controllers\EmployeeBranchAssignmentController;
// use App\Http\Controllers\NoiMovementController;
// use App\Http\Controllers\PlacementController;
// use App\Http\Controllers\RecoveryController;
// use App\Http\Controllers\PortfolioController;
// use App\Http\Controllers\ExpenseController;
// use App\Http\Controllers\MonthlyReportController;
// use App\Http\Controllers\ExportController;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::redirect('/', '/login');

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'verified'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */

    // Si luego haces controller real, usa esta:
    // Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Por ahora puedes dejar inertia simple:
    Route::inertia('/dashboard', 'Dashboard')->name('dashboard');

    /*
    |--------------------------------------------------------------------------
    | Histórico General
    |--------------------------------------------------------------------------
    | Aquí entra la subida de archivos por periodo y fuente
    */

    Route::prefix('historico-general')
        ->name('historico-general.')
        ->group(function () {
            Route::get('/', [ReportUploadController::class, 'index'])->name('index');
            Route::post('/', [ReportUploadController::class, 'store'])->name('store');

            // Futuro:
            // Route::get('/{reportUpload}', [ReportUploadController::class, 'show'])->name('show');
            // Route::delete('/{reportUpload}', [ReportUploadController::class, 'destroy'])->name('destroy');
            // Route::post('/{reportUpload}/reprocess', [ReportUploadController::class, 'reprocess'])->name('reprocess');
            // Route::get('/{reportUpload}/download', [ReportUploadController::class, 'download'])->name('download');
        });

    /*
    |--------------------------------------------------------------------------
    | Periodos
    |--------------------------------------------------------------------------
    */

    // Route::prefix('periodos')
    //     ->name('periodos.')
    //     ->group(function () {
    //         Route::get('/', [PeriodController::class, 'index'])->name('index');
    //         Route::get('/create', [PeriodController::class, 'create'])->name('create');
    //         Route::post('/', [PeriodController::class, 'store'])->name('store');
    //         Route::get('/{period}', [PeriodController::class, 'show'])->name('show');
    //         Route::get('/{period}/edit', [PeriodController::class, 'edit'])->name('edit');
    //         Route::put('/{period}', [PeriodController::class, 'update'])->name('update');
    //         Route::delete('/{period}', [PeriodController::class, 'destroy'])->name('destroy');
    //         Route::post('/{period}/close', [PeriodController::class, 'close'])->name('close');
    //         Route::post('/{period}/open', [PeriodController::class, 'open'])->name('open');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Fuentes de datos
    |--------------------------------------------------------------------------
    */

    // Route::prefix('fuentes')
    //     ->name('fuentes.')
    //     ->group(function () {
    //         Route::get('/', [DataSourceController::class, 'index'])->name('index');
    //         Route::get('/create', [DataSourceController::class, 'create'])->name('create');
    //         Route::post('/', [DataSourceController::class, 'store'])->name('store');
    //         Route::get('/{dataSource}/edit', [DataSourceController::class, 'edit'])->name('edit');
    //         Route::put('/{dataSource}', [DataSourceController::class, 'update'])->name('update');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Procesos / bitácora de importación
    |--------------------------------------------------------------------------
    */

    // Route::prefix('procesos')
    //     ->name('procesos.')
    //     ->group(function () {
    //         Route::get('/', [ProcessRunController::class, 'index'])->name('index');
    //         Route::get('/{processRun}', [ProcessRunController::class, 'show'])->name('show');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Empleados
    |--------------------------------------------------------------------------
    */

    // Route::prefix('empleados')
    //     ->name('empleados.')
    //     ->group(function () {
    //         Route::get('/', [EmployeeController::class, 'index'])->name('index');
    //         Route::get('/{employee}', [EmployeeController::class, 'show'])->name('show');
    //         Route::get('/{employee}/edit', [EmployeeController::class, 'edit'])->name('edit');
    //         Route::put('/{employee}', [EmployeeController::class, 'update'])->name('update');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Sucursales
    |--------------------------------------------------------------------------
    */

    // Route::prefix('sucursales')
    //     ->name('sucursales.')
    //     ->group(function () {
    //         Route::get('/', [BranchController::class, 'index'])->name('index');
    //         Route::get('/create', [BranchController::class, 'create'])->name('create');
    //         Route::post('/', [BranchController::class, 'store'])->name('store');
    //         Route::get('/{branch}', [BranchController::class, 'show'])->name('show');
    //         Route::get('/{branch}/edit', [BranchController::class, 'edit'])->name('edit');
    //         Route::put('/{branch}', [BranchController::class, 'update'])->name('update');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Match NOI ↔ Lendus / asignación sucursal
    |--------------------------------------------------------------------------
    */

    // Route::prefix('asignaciones-empleado-sucursal')
    //     ->name('asignaciones-empleado-sucursal.')
    //     ->group(function () {
    //         Route::get('/', [EmployeeBranchAssignmentController::class, 'index'])->name('index');
    //         Route::get('/pendientes', [EmployeeBranchAssignmentController::class, 'pending'])->name('pending');
    //         Route::post('/match-automatico', [EmployeeBranchAssignmentController::class, 'autoMatch'])->name('auto-match');
    //         Route::post('/{assignment}/match-manual', [EmployeeBranchAssignmentController::class, 'manualMatch'])->name('manual-match');
    //         Route::put('/{assignment}', [EmployeeBranchAssignmentController::class, 'update'])->name('update');
    //     });

    /*
    |--------------------------------------------------------------------------
    | NOI Movimientos
    |--------------------------------------------------------------------------
    */

    // Route::prefix('noi-movimientos')
    //     ->name('noi-movimientos.')
    //     ->group(function () {
    //         Route::get('/', [NoiMovementController::class, 'index'])->name('index');
    //         Route::get('/{noiMovement}', [NoiMovementController::class, 'show'])->name('show');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Colocación
    |--------------------------------------------------------------------------
    */

    // Route::prefix('colocaciones')
    //     ->name('colocaciones.')
    //     ->group(function () {
    //         Route::get('/', [PlacementController::class, 'index'])->name('index');
    //         Route::get('/{placement}', [PlacementController::class, 'show'])->name('show');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Recuperación / cobranza
    |--------------------------------------------------------------------------
    */

    // Route::prefix('recuperaciones')
    //     ->name('recuperaciones.')
    //     ->group(function () {
    //         Route::get('/', [RecoveryController::class, 'index'])->name('index');
    //         Route::get('/{recovery}', [RecoveryController::class, 'show'])->name('show');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Cartera
    |--------------------------------------------------------------------------
    */

    // Route::prefix('cartera')
    //     ->name('cartera.')
    //     ->group(function () {
    //         Route::get('/', [PortfolioController::class, 'index'])->name('index');
    //         Route::get('/{portfolio}', [PortfolioController::class, 'show'])->name('show');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Gastos
    |--------------------------------------------------------------------------
    */

    // Route::prefix('gastos')
    //     ->name('gastos.')
    //     ->group(function () {
    //         Route::get('/', [ExpenseController::class, 'index'])->name('index');
    //         Route::get('/{expense}', [ExpenseController::class, 'show'])->name('show');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Reportes mensuales / resultado final
    |--------------------------------------------------------------------------
    */

    // Route::prefix('reportes-mensuales')
    //     ->name('reportes-mensuales.')
    //     ->group(function () {
    //         Route::get('/', [MonthlyReportController::class, 'index'])->name('index');
    //         Route::get('/{period}', [MonthlyReportController::class, 'show'])->name('show');
    //         Route::post('/{period}/consolidar', [MonthlyReportController::class, 'consolidate'])->name('consolidate');
    //     });

    /*
    |--------------------------------------------------------------------------
    | Exportaciones
    |--------------------------------------------------------------------------
    */

    // Route::prefix('exportaciones')
    //     ->name('exportaciones.')
    //     ->group(function () {
    //         Route::get('/periodo/{period}/excel-final', [ExportController::class, 'monthlyExcel'])->name('monthly-excel');
    //         Route::get('/periodo/{period}/dashboard-pdf', [ExportController::class, 'dashboardPdf'])->name('dashboard-pdf');
    //     });
});

/*
|--------------------------------------------------------------------------
| Fortify extras opcionales
|--------------------------------------------------------------------------
| Si luego quieres condicionar vistas según features.
*/

if (Features::enabled(Features::updatePasswords())) {
    // Puedes dejar lógica adicional aquí si algún día la ocupas.
}

/*
|--------------------------------------------------------------------------
| Settings
|--------------------------------------------------------------------------
*/

require __DIR__ . '/settings.php';
