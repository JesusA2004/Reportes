<?php

use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\GastosExcelBranchResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Cubre el bug real de julio/VPS: GastosExcelBranchResolverService::resolveForPeriodOrFail()
 * tiraba una RuntimeException (y detenía TODA la generación) para filas que en realidad SÍ
 * eran resolubles — solo que no por el mecanismo de "monto+fecha idéntico en el PDF de
 * Lendus". Ver [[project_...]] / GenerateRadiographyJob::handle().
 */
function makePeriodo(string $label = 'Julio 2026'): Period
{
    return Period::query()->create([
        'name' => $label,
        'code' => 'M-2026-07',
        'type' => 'monthly',
        'year' => 2026,
        'month' => 7,
        'sequence' => 1,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'is_closed' => false,
    ]);
}

function makeUpload(Period $period, string $sourceCode): ReportUpload
{
    $source = DataSource::query()->firstOrCreate(
        ['code' => $sourceCode],
        ['name' => $sourceCode, 'description' => $sourceCode, 'is_active' => true],
    );

    return ReportUpload::query()->create([
        'period_id' => $period->id,
        'data_source_id' => $source->id,
        'original_name' => "{$sourceCode}.xlsx",
        'stored_path' => "report_uploads/{$sourceCode}.xlsx",
        'mime_type' => 'application/vnd.ms-excel',
        'file_size' => 10,
        'uploaded_at' => now(),
        'status' => ReportUploadStatus::Processed,
        'notes' => null,
    ]);
}

it('never blocks generation on a Financiamiento de Motos/Cascos row — that concept is delegated to its own resolver', function () {
    $period = makePeriodo();
    $excelUpload = makeUpload($period, 'gastos_lendus_excel');

    // Sin fila PDF gemela a propósito (ninguna en gastos_lendus) — antes del fix esto
    // habría lanzado RuntimeException porque no hay (monto, fecha) equivalente.
    Expense::query()->create([
        'period_id' => $period->id,
        'report_upload_id' => $excelUpload->id,
        'category' => 'Nómina y Capital Humano',
        'concept' => 'PAGO FINANCIAMIENTO MOTO',
        'amount' => 749.00,
        'expense_date' => '2026-07-11',
        'observations' => 'ALGUIEN QUE NO EXISTE EN NINGÚN LADO',
        'branch_id' => null,
        'employee_id' => null,
    ]);

    $service = app(GastosExcelBranchResolverService::class);

    $results = $service->resolveForPeriodOrFail($period, [$period->id]);

    expect($results)->toBeEmpty(); // delegado — ni siquiera entra al universo de este resolver
});

it('resolves a PAGO FINIQUITO without a PDF twin via employee identity (historical/baja included), instead of blocking generation', function () {
    $period = makePeriodo();
    $excelUpload = makeUpload($period, 'gastos_lendus_excel');

    $branch = Branch::query()->create([
        'code' => 'CUER', 'name' => 'Cuernavaca', 'normalized_name' => 'cuernavaca', 'is_active' => true,
    ]);

    $employee = Employee::query()->create([
        'employee_code' => 'EMP900',
        'full_name' => 'Hugo Eduardo Perez Davila',
        'normalized_name' => 'hugo eduardo perez davila',
        'first_name' => 'Hugo Eduardo',
        'paternal_last_name' => 'Perez',
        'maternal_last_name' => 'Davila',
        'is_active' => false, // dado de baja — el finiquito sigue siendo un gasto real
        'source_system' => 'noi',
    ]);

    \App\Models\EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id,
        'period_id' => $period->id,
        'branch_id' => $branch->id,
        'source_type' => \App\Enums\SourceType::Manual,
        'match_type' => \App\Enums\MatchType::Manual,
    ]);

    $expense = Expense::query()->create([
        'period_id' => $period->id,
        'report_upload_id' => $excelUpload->id,
        'category' => 'Nómina y Capital Humano',
        'concept' => 'PAGO FINIQUITO',
        'amount' => 6910.02,
        'expense_date' => '2026-07-08',
        'observations' => 'HUGO EDUARDO PEREZ DAVILA',
        'branch_id' => null,
        'employee_id' => null,
    ]);

    $service = app(GastosExcelBranchResolverService::class);

    $results = $service->resolveForPeriodOrFail($period, [$period->id]);

    expect($results)->toHaveCount(1);
    expect($results[0]['estado'])->toBe('resuelto');
    expect($results[0]['employee_id'])->toBe($employee->id);
    expect($results[0]['branch_id'])->toBe($branch->id);

    expect($expense->fresh()->branch_id)->toBe($branch->id);
    expect($expense->fresh()->employee_id)->toBe($employee->id);
});

it('still throws (safety net) for a genuinely unresolvable expense — no PDF twin and no identity match', function () {
    $period = makePeriodo();
    $excelUpload = makeUpload($period, 'gastos_lendus_excel');

    Expense::query()->create([
        'period_id' => $period->id,
        'report_upload_id' => $excelUpload->id,
        'category' => 'Gastos Operativos',
        'concept' => 'GASTO SIN IDENTIFICAR',
        'amount' => 1234.56,
        'expense_date' => '2026-07-15',
        'observations' => 'NOMBRE QUE NO EXISTE EN NINGÚN DIRECTORIO',
        'branch_id' => null,
        'employee_id' => null,
    ]);

    $service = app(GastosExcelBranchResolverService::class);

    expect(fn () => $service->resolveForPeriodOrFail($period, [$period->id]))
        ->toThrow(RuntimeException::class);
});
