<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\EmployeeMatchRejection;
use App\Models\Period;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function makeEmployee(string $fullName, string $normalized): Employee
{
    return Employee::query()->create([
        'full_name' => $fullName,
        'normalized_name' => $normalized,
        'is_active' => true,
        'source_system' => 'noi',
    ]);
}

it('merges a one-letter typo duplicate into a single canonical employee with the correct branch (Margarita case)', function () {
    $period = Period::query()->create([
        'name' => 'Junio 2026', 'code' => 'M-2026-06', 'type' => 'monthly',
        'year' => 2026, 'month' => 6, 'sequence' => 0,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'is_closed' => false,
    ]);

    $orizaba = Branch::query()->create(['code' => 'ORIZABA', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    // "CENTRO C" simula una ruta guardada por error como si fuera sucursal — nunca
    // debe sobrevivir como el registro canónico frente a una sucursal operativa real.
    $centroC = Branch::query()->create(['code' => 'CENTRO_C', 'name' => 'CENTRO C', 'normalized_name' => 'centro c', 'is_active' => false]);

    $typo = makeEmployee('MARGARITA JAZMIN NOLASCO DOMIGUEZ', 'margarita jazmin nolasco domiguez');
    $good = makeEmployee('MARGARITA JAZMIN NOLASCO DOMINGUEZ', 'margarita jazmin nolasco dominguez');

    EmployeeBranchAssignment::query()->create([
        'period_id' => $period->id, 'employee_id' => $typo->id, 'branch_id' => $centroC->id,
        'source_type' => 'lendus', 'match_type' => 'fuzzy', 'confidence' => 0.5, 'was_manual_reviewed' => false,
    ]);
    EmployeeBranchAssignment::query()->create([
        'period_id' => $period->id, 'employee_id' => $good->id, 'branch_id' => $orizaba->id,
        'source_type' => 'lendus', 'match_type' => 'exact', 'confidence' => 1.0, 'was_manual_reviewed' => false,
    ]);

    $this->artisan('employees:normalize', ['--apply' => true])->assertExitCode(0);

    expect(Employee::query()->count())->toBe(1);
    $survivor = Employee::query()->first();
    expect($survivor->normalized_name)->toBe('margarita jazmin nolasco dominguez');

    $assignment = EmployeeBranchAssignment::query()->where('employee_id', $survivor->id)->where('period_id', $period->id)->first();
    expect($assignment->branch_id)->toBe($orizaba->id);

    // El alias queda registrado — un futuro import con la variante typo debe
    // reconocerse automáticamente sin volver a preguntar.
    expect(DB::table('employee_aliases')->where('employee_id', $survivor->id)->where('normalized_alias', 'margarita jazmin nolasco domiguez')->exists())->toBeTrue();
});

it('never merges a pair the user already rejected as different people', function () {
    $a = makeEmployee('JUAN PEREZ LOPEZ', 'juan perez lopez');
    $b = makeEmployee('JUAN PEREZ LOPES', 'juan perez lopes'); // typo-close but confirmed distinct

    EmployeeMatchRejection::query()->create([
        'employee_id_a' => min($a->id, $b->id),
        'employee_id_b' => max($a->id, $b->id),
        'pair_key' => EmployeeMatchRejection::pairKey($a->id, $b->id),
        'reason' => 'Confirmado: son dos personas distintas.',
    ]);

    $this->artisan('employees:normalize', ['--apply' => true])->assertExitCode(0);

    expect(Employee::query()->count())->toBe(2);
});

it('dry-run never modifies the database', function () {
    makeEmployee('DIEGO MARTINEZ ROMERO', 'diego martinez romero');
    makeEmployee('DIEGO MARTINEZ ROMERO', 'diego martinez romero');

    $this->artisan('employees:normalize')->assertExitCode(0);

    expect(Employee::query()->count())->toBe(2);
});
