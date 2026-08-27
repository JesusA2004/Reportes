<?php

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeAlias;
use App\Models\EmployeeBranchAssignment;
use App\Models\Expense;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\ExpenseObservationAttributionService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Auditoría 27-ago-2026 — atribución de OPEX a colaboradores vía Observación/
 * Justificación del Excel de Gastos Lendus. Ver ExpenseObservationAttributionService.
 *
 * BUG REAL que motiva esto: fact_expenses.employee_id, para el Excel Lendus, se
 * copiaba de la fila espejo del PDF (mismo monto+fecha) — que a su vez viene de
 * la columna "Empleado" cruda del PDF (el capturista/administrador de sucursal,
 * NO necesariamente el beneficiario). Ejemplo real: Empleado=DIANA BAYRAN QUIROZ,
 * Concepto=RECARGAS TELEFONICAS, Observación=RAMSES LEONIDAS ROJAS — el gasto
 * debe atribuirse a RAMSES, no a DIANA.
 */
function makeAttribPeriodo(string $suffix = ''): Period
{
    static $seq = 0;
    $seq++;
    $month = (($seq - 1) % 12) + 1;

    return Period::query()->create([
        'name' => "Periodo atribución {$seq}{$suffix}", 'code' => "M-ATTR-2026-{$month}-{$seq}", 'type' => 'monthly',
        'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => sprintf('2026-%02d-01', $month), 'end_date' => sprintf('2026-%02d-28', $month), 'is_closed' => false,
    ]);
}

function makeAttribBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(
        ['normalized_name' => mb_strtolower($name)],
        ['code' => mb_substr(strtoupper($name), 0, 4), 'name' => strtoupper($name), 'is_active' => true],
    );
}

/** Roster: fact_noi_movements (presencia en el periodo) + employee_branch_assignments (sucursal). */
function makeAttribRosterEmployee(Period $period, string $fullName, Branch $branch): Employee
{
    static $seq = 0;
    $seq++;

    $parts = explode(' ', $fullName);
    $employee = Employee::query()->create([
        'employee_code'       => 'ATTR' . $seq,
        'full_name'           => $fullName,
        'normalized_name'     => mb_strtolower($fullName),
        'first_name'          => $parts[0] ?? $fullName,
        'paternal_last_name'  => $parts[1] ?? 'X',
        'is_active'           => true,
        'source_system'       => 'noi',
    ]);

    DB::table('fact_noi_movements')->insert([
        'period_id' => $period->id, 'employee_id' => $employee->id,
        'amount' => 1000, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id, 'period_id' => $period->id, 'branch_id' => $branch->id,
        'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
    ]);

    return $employee;
}

function makeAttribUpload(Period $period): ReportUpload
{
    $source = DataSource::query()->firstOrCreate(
        ['code' => 'gastos_lendus_excel'],
        ['name' => 'gastos_lendus_excel', 'description' => 'gastos_lendus_excel', 'is_active' => true],
    );

    return ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id,
        'original_name' => 'Gastos.xlsx', 'stored_path' => 'report_uploads/gastos.xlsx',
        'mime_type' => 'application/vnd.ms-excel', 'file_size' => 10, 'uploaded_at' => now(),
        'status' => \App\Enums\ReportUploadStatus::Processed, 'notes' => null,
    ]);
}

function makeAttribExpense(Period $period, ReportUpload $upload, array $overrides = []): Expense
{
    return Expense::query()->create(array_merge([
        'period_id'        => $period->id,
        'report_upload_id' => $upload->id,
        'category'         => 'Recargas Telefónicas',
        'concept'          => 'RECARGAS TELEFONICAS',
        'amount'           => 200.0,
        'paid_amount'      => 200.0,
        'expense_date'     => now()->format('Y-m-d'),
        'branch_id'        => null,
        'employee_id'      => null,
    ], $overrides));
}

// ── 1. Observación exacta a empleado — nunca el "Empleado" administrativo ────
it('attributes to the employee named in Observación, never to the raw "Empleado" administrator column', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branchA = makeAttribBranch('Cuernavaca');
    $branchB = makeAttribBranch('Orizaba');

    $admin      = makeAttribRosterEmployee($period, 'DIANA BAYRAN QUIROZ', $branchA);
    $beneficiary = makeAttribRosterEmployee($period, 'RAMSES LEONIDAS ROJAS', $branchB);

    $expense = makeAttribExpense($period, $upload, [
        // Simula lo que hace GastosExcelBranchResolverService hoy: employee_id/branch_id
        // ya vienen copiados del PDF (el administrador, DIANA), ANTES de correr este servicio.
        'employee_id'  => $admin->id,
        'branch_id'    => $branchA->id,
        'observations' => 'RAMSES LEONIDAS ROJAS',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: false);

    expect($results)->toHaveCount(1);
    expect($results[0]['estado'])->toBe('atribuido');
    expect($results[0]['fuente'])->toBe('observation');

    $expense->refresh();
    expect($expense->employee_id)->toBe($beneficiary->id);
    expect($expense->employee_id)->not->toBe($admin->id);
    expect($expense->branch_id)->toBe($branchB->id);
    expect($expense->attribution_method)->toBe('exact_name');
    expect($expense->attribution_source)->toBe('observation');
    expect((float) $expense->attribution_confidence)->toBe(1.0);
    // El monto/categoría/concepto NUNCA cambian — solo la atribución.
    expect((float) $expense->amount)->toBe(200.0);
    expect($expense->concept)->toBe('RECARGAS TELEFONICAS');
});

// ── 2. Justificación resuelve cuando Observación no es una persona ───────────
it('falls back to Justificación when Observación does not resolve to a person', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Tula');
    $beneficiary = makeAttribRosterEmployee($period, 'EMPLEADO GAMA', $branch);

    $expense = makeAttribExpense($period, $upload, [
        'concept'      => 'GASTOS EMERGENTES',
        'observations' => 'RECARGA DE EXTINTOR | EMPLEADO GAMA',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: false);

    expect($results[0]['estado'])->toBe('atribuido');
    expect($results[0]['fuente'])->toBe('justification');
    expect($expense->fresh()->employee_id)->toBe($beneficiary->id);
    expect($expense->fresh()->attribution_source)->toBe('justification');
});

// ── 3. Observación y Justificación coinciden en la misma persona ─────────────
it('attributes when Observación and Justificación both name the same person', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Atlixco');
    $beneficiary = makeAttribRosterEmployee($period, 'EMPLEADO DELTA', $branch);

    $expense = makeAttribExpense($period, $upload, [
        'observations' => 'EMPLEADO DELTA | EMPLEADO DELTA',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $service->attributeForPeriod($period, [$period->id], dryRun: false);

    expect($expense->fresh()->employee_id)->toBe($beneficiary->id);
});

// ── 4. Observación y Justificación nombran personas DISTINTAS → conflicto ────
it('marks CONFLICT and does not auto-attribute when Observación and Justificación name different people', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Huamantla');
    makeAttribRosterEmployee($period, 'EMPLEADO EPSILON', $branch);
    makeAttribRosterEmployee($period, 'EMPLEADO ZETA', $branch);

    $expense = makeAttribExpense($period, $upload, [
        'observations' => 'EMPLEADO EPSILON | EMPLEADO ZETA',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: false);

    expect($results[0]['estado'])->toBe('conflicto');
    $fresh = $expense->fresh();
    expect($fresh->employee_id)->toBeNull();
    expect($fresh->attribution_needs_review)->toBeTrue();
    expect($fresh->attribution_method)->toBe('conflict');
    // El gasto SIGUE existiendo intacto — nunca se descarta por conflicto.
    expect((float) $fresh->amount)->toBe(200.0);
});

// ── 5. Texto que no es persona → sin atribución, gasto preservado ────────────
it('leaves the expense unattributed (but intact) when the text is not a person', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    // Roster no vacío (con alguien que claramente NO coincide) — para probar que
    // "no hay roster en absoluto" y "el texto no es ninguno del roster" son casos
    // distintos; ambos deben terminar en no_atribuible, pero este cubre el segundo.
    makeAttribRosterEmployee($period, 'EMPLEADO IRRELEVANTE', makeAttribBranch('Atlacomulco'));

    $expense = makeAttribExpense($period, $upload, [
        'observations' => 'RECARGA DE EXTINTOR',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: false);

    expect($results[0]['estado'])->toBe('no_atribuible');
    $fresh = $expense->fresh();
    expect($fresh->employee_id)->toBeNull();
    expect((float) $fresh->amount)->toBe(200.0);
});

// ── 6. Alias canónico confirmado resuelve a la persona correcta ──────────────
it('resolves a confirmed alias variant to the canonical employee_id', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Ixtlahuaca');
    $employee = makeAttribRosterEmployee($period, 'MARGARITA JAZMIN NOLASCO DOMINGUEZ', $branch);

    EmployeeAlias::query()->create([
        'employee_id' => $employee->id,
        'alias_name' => 'MARGARITA JAZMIN NOLASCO DOMIGUEZ',
        'normalized_alias' => 'margarita jazmin nolasco domiguez', // typo variant confirmado
        'source' => 'confirmed_match',
        'confidence' => 1.0,
    ]);

    $expense = makeAttribExpense($period, $upload, [
        'observations' => 'MARGARITA JAZMIN NOLASCO DOMIGUEZ',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: false);

    expect($results[0]['metodo'])->toBe('alias');
    expect($expense->fresh()->employee_id)->toBe($employee->id);
});

// ── 7. Nombres parecidos/ambiguos → NUNCA auto-match ──────────────────────────
it('never auto-attributes when two roster employees are too similar to tell apart confidently', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Miacatlan');
    makeAttribRosterEmployee($period, 'JUAN CARLOS PEREZ TORRES', $branch);
    makeAttribRosterEmployee($period, 'JUAN CARLOS PEREZ FLORES', $branch);

    // Texto parcial que se parece por igual a ambos — ninguno debe ganar arbitrariamente.
    $expense = makeAttribExpense($period, $upload, [
        'observations' => 'JUAN CARLOS PEREZ',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: false);

    expect($results[0]['estado'])->not->toBe('atribuido');
    expect($expense->fresh()->employee_id)->toBeNull();
});

// ── 8. Sucursal HISTÓRICA del periodo, nunca la actual/futura ────────────────
it('uses the branch the employee was assigned to in the SAME period as the expense, not a later one', function () {
    $juniorPeriod = makeAttribPeriodo('-jun');
    $laterPeriod  = makeAttribPeriodo('-sep');
    $upload       = makeAttribUpload($juniorPeriod);

    $orizaba = makeAttribBranch('Orizaba');
    $tula    = makeAttribBranch('Tula');

    $employee = makeAttribRosterEmployee($juniorPeriod, 'EMPLEADO MOVIL', $orizaba);
    // Reasignado a otra sucursal en un periodo POSTERIOR — no debe afectar el gasto del periodo anterior.
    EmployeeBranchAssignment::query()->create([
        'employee_id' => $employee->id, 'period_id' => $laterPeriod->id, 'branch_id' => $tula->id,
        'source_type' => SourceType::Manual, 'match_type' => MatchType::Manual,
    ]);

    $expense = makeAttribExpense($juniorPeriod, $upload, [
        'observations' => 'EMPLEADO MOVIL',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $service->attributeForPeriod($juniorPeriod, [$juniorPeriod->id], dryRun: false);

    expect($expense->fresh()->branch_id)->toBe($orizaba->id);
    expect($expense->fresh()->branch_id)->not->toBe($tula->id);
});

// ── 9. INVARIANTE ABSOLUTA — el total general de gastos del periodo no cambia ─
it('never changes the total sum of fact_expenses for the period (dimensional attribution only)', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('San Luis Potosi');
    $e1 = makeAttribRosterEmployee($period, 'EMPLEADO UNO', $branch);
    $e2 = makeAttribRosterEmployee($period, 'EMPLEADO DOS', $branch);

    makeAttribExpense($period, $upload, ['amount' => 200, 'paid_amount' => 200, 'observations' => 'EMPLEADO UNO']);
    makeAttribExpense($period, $upload, ['amount' => 150, 'paid_amount' => 150, 'observations' => 'EMPLEADO DOS']);
    makeAttribExpense($period, $upload, ['amount' => 90,  'paid_amount' => 90,  'observations' => 'EMPLEADO UNO | EMPLEADO DOS']); // conflicto
    makeAttribExpense($period, $upload, ['amount' => 60,  'paid_amount' => 60,  'observations' => 'RECARGA DE EXTINTOR']); // no atribuible

    $totalAntes = (float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('paid_amount');

    $service = app(ExpenseObservationAttributionService::class);
    $service->attributeForPeriod($period, [$period->id], dryRun: false);

    $totalDespues = (float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('paid_amount');

    expect(round($totalDespues, 2))->toBe(round($totalAntes, 2));
    expect(round($totalAntes, 2))->toBe(500.0);
});

// ── 10. No doble conteo por sucursal cuando ya estaba correcto ───────────────
it('does not double-count an expense already correctly attributed to the employee and their branch', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Cordoba');
    $employee = makeAttribRosterEmployee($period, 'EMPLEADO YA CORRECTO', $branch);

    $expense = makeAttribExpense($period, $upload, [
        'employee_id'  => $employee->id,
        'branch_id'    => $branch->id,
        'observations' => 'EMPLEADO YA CORRECTO',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: false);

    expect($results[0]['estado'])->toBe('ya_correcto');
    expect($results[0]['changed'])->toBeFalse();

    $sumaPorSucursal = (float) DB::table('fact_expenses')
        ->where('period_id', $period->id)
        ->where('branch_id', $branch->id)
        ->sum('paid_amount');

    expect($sumaPorSucursal)->toBe(200.0); // una sola fila, un solo conteo
    expect($expense->fresh()->employee_id)->toBe($employee->id);
});

// ── 11. Employee scope: OPEX empleado = SUM(gastos atribuibles) ──────────────
it('flows the attributed amount into the employee OPEX aggregate consumed by Web/Excel/PDF (buildEmployeesGestores)', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Tlaxcala');
    $employee = makeAttribRosterEmployee($period, 'EMPLEADO REPORTEADO', $branch);

    makeAttribExpense($period, $upload, ['amount' => 200, 'paid_amount' => 200, 'observations' => 'EMPLEADO REPORTEADO']);
    makeAttribExpense($period, $upload, ['amount' => 75,  'paid_amount' => 75,  'observations' => 'EMPLEADO REPORTEADO']);

    $service = app(ExpenseObservationAttributionService::class);
    $service->attributeForPeriod($period, [$period->id], dryRun: false);

    // Misma query que RadiographySnapshotBuilder::buildEmployeesGestores() usa para
    // $expensesByNorm — la fuente ÚNICA que consumen Web (applyEmployeeScope), Excel
    // (RadiographyWorkbookBuilder::buildEmployeeFromSnapshot) y PDF (RadiografiaExportService::
    // resolveEmployeeRow) vía findEmployeeGestorRowByEmployeeId(). No se modificó ese
    // código — esta prueba confirma que la atribución nueva llega ahí sin cambios.
    $gastosEmpleado = (float) DB::table('fact_expenses as e')
        ->join('employees as emp', 'e.employee_id', '=', 'emp.id')
        ->where('e.period_id', $period->id)
        ->where('e.employee_id', $employee->id)
        ->selectRaw('SUM(COALESCE(NULLIF(e.paid_amount,0),e.amount)) as gastos')
        ->value('gastos');

    expect($gastosEmpleado)->toBe(275.0);
});

// ── BUG REAL detectado corriendo el audit contra Junio 2026 real: sin excluir
// estos conceptos, "NOMINA" (categoría 'Nómina y Capital Humano') se atribuía
// igual que un gasto OPEX cualquiera — $190,040.30 en un solo periodo real — pese
// a que BranchRadiographyCalculator::accumulateGastos() nunca lo suma a ningún
// KPI a propósito porque YA está cubierto por NOI. buildEmployeesGestores() no
// filtra por categoría, así que esto habría duplicado el gasto en el EBITDA del
// colaborador (que ya cuenta NOI vía $neto). PAGO FINIQUITO se mantiene elegible
// (precedente ya existente, pago puntual real — no duplica nómina recurrente).
it('never attributes NOMINA/PAGO DE IMSS/DEDUCCIONES/ANTICIPO DE NOMINA (already covered by NOI/IMSS — would double-count), but still attributes PAGO FINIQUITO', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Tenango del Valle');
    $employee = makeAttribRosterEmployee($period, 'EMPLEADO NOMINA DUP', $branch);

    $nomina = makeAttribExpense($period, $upload, [
        'category' => 'Nómina y Capital Humano', 'concept' => 'NOMINA',
        'amount' => 5000, 'paid_amount' => 5000, 'observations' => 'EMPLEADO NOMINA DUP',
    ]);
    $imss = makeAttribExpense($period, $upload, [
        'category' => 'Nómina y Capital Humano', 'concept' => 'PAGO DE IMSS',
        'amount' => 800, 'paid_amount' => 800, 'observations' => 'EMPLEADO NOMINA DUP',
    ]);
    $deducciones = makeAttribExpense($period, $upload, [
        'category' => 'Nómina y Capital Humano', 'concept' => 'DEDUCCIONES GENERALES',
        'amount' => 300, 'paid_amount' => 300, 'observations' => 'EMPLEADO NOMINA DUP',
    ]);
    $anticipo = makeAttribExpense($period, $upload, [
        'category' => 'Nómina y Capital Humano', 'concept' => 'ANTICIPO DE NOMINA',
        'amount' => 100, 'paid_amount' => 100, 'observations' => 'EMPLEADO NOMINA DUP',
    ]);
    $finiquito = makeAttribExpense($period, $upload, [
        'category' => 'Nómina y Capital Humano', 'concept' => 'PAGO FINIQUITO',
        'amount' => 7000, 'paid_amount' => 7000, 'observations' => 'EMPLEADO NOMINA DUP',
    ]);

    $service = app(ExpenseObservationAttributionService::class);
    $results = $service->attributeForPeriod($period, [$period->id], dryRun: false);

    // Solo el finiquito entra al universo evaluado por este servicio.
    expect($results)->toHaveCount(1);
    expect($results[0]['concept'])->toBe('PAGO FINIQUITO');
    expect($results[0]['estado'])->toBe('atribuido');

    expect($nomina->fresh()->employee_id)->toBeNull();
    expect($imss->fresh()->employee_id)->toBeNull();
    expect($deducciones->fresh()->employee_id)->toBeNull();
    expect($anticipo->fresh()->employee_id)->toBeNull();
    expect($finiquito->fresh()->employee_id)->toBe($employee->id);
});

// ── 12. EBITDA del colaborador cambia exactamente por el OPEX atribuido ──────
// Usa la MISMA fórmula canónica de RadiographySnapshotBuilder::applyEmployeeScope()
// (ebitda = ingreso_base - (gastos + neto)) — no se inventa ninguna fórmula nueva.
// ============================================================================
// ACLARACIÓN 27-ago-2026: OPEX DE EMPLEADO/GESTOR = AUTOMÁTICO + MANUAL
// ============================================================================
// La atribución automática (fact_expenses vía Observación/Justificación) NO
// reemplaza el input manual que ya existe en Etapa 4 ("Gasto general por
// gestor" / extra_employee_expense_amount+notes) — se SUMAN. Ver
// RadiographySnapshotBuilder::buildEmployeeExpenseDetail(), fuente única para
// Web (applyEmployeeScope), Excel (buildEmployeeFromSnapshot) y PDF
// (resolveEmployeeRow).
function makeEbitdaFixtureRow(int $employeeId): array
{
    return ['name' => 'EMPLEADO EBITDA', 'branch' => 'ORIZABA', 'pagos' => 40000.0, 'bonos' => 0.0, 'descuentos' => 0.0, 'neto' => 40000.0, 'gastos' => 0.0, 'colocacion' => 0.0, 'operaciones' => 0, 'recuperacion' => 0.0, 'cartera' => 0.0, 'vencida' => 0.0, 'mora' => 0.0, 'ingreso_ebitda_base' => 190000.0, '_employee_ids' => [$employeeId]];
}

// Test obligatorio 1: automatic=200, manual=0 → total=200 (nunca $0 solo porque no hay manual).
it('OPEX total = automatic only, when there is no manual input', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Orizaba');
    $employee = makeAttribRosterEmployee($period, 'EMPLEADO EBITDA A', $branch);
    makeAttribExpense($period, $upload, ['amount' => 200, 'paid_amount' => 200, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);

    // buildEmployeeExpenseDetail() lee $this->dataIds — en el flujo real (Web/Excel/PDF)
    // ya viene "calentado" por findEmployeeGestorRowByEmployeeId()/build() antes de
    // llegar aquí; se replica esa misma secuencia para la prueba directa del método.
    $builder = app(RadiographySnapshotBuilder::class);
    $builder->findEmployeeGestorRowByEmployeeId($period, $employee->id);
    $detail = $builder->buildEmployeeExpenseDetail([$employee->id], 0.0, '');

    expect($detail['automatic_total'])->toBe(200.0);
    expect($detail['manual_total'])->toBe(0.0);
    expect($detail['total'])->toBe(200.0);
});

// Test obligatorio 2: automatic=0, manual=1500 → total=1500 (nunca $0 porque no hay automático).
it('OPEX total = manual only, when there is no automatic attribution', function () {
    $detail = app(RadiographySnapshotBuilder::class)->buildEmployeeExpenseDetail([999999], 1500.0, 'Viáticos y comunicación');

    expect($detail['automatic_total'])->toBe(0.0);
    expect($detail['manual_total'])->toBe(1500.0);
    expect($detail['total'])->toBe(1500.0);
});

// Test obligatorio 3: automatic=200 + manual=1500 → total=1700. SUMAR, nunca reemplazar.
it('OPEX total = automatic + manual, SUMMED, never one replacing the other', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Orizaba');
    $employee = makeAttribRosterEmployee($period, 'EMPLEADO EBITDA B', $branch);
    makeAttribExpense($period, $upload, ['amount' => 200, 'paid_amount' => 200, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);

    $builder = app(RadiographySnapshotBuilder::class);
    $builder->findEmployeeGestorRowByEmployeeId($period, $employee->id);
    $detail = $builder->buildEmployeeExpenseDetail([$employee->id], 1500.0, 'Viáticos y comunicación');

    expect($detail['automatic_total'])->toBe(200.0);
    expect($detail['manual_total'])->toBe(1500.0);
    expect($detail['manual_notes'])->toBe('Viáticos y comunicación');
    expect($detail['total'])->toBe(1700.0);
    expect($detail['automatic_items'])->toHaveCount(1);
});

// Test obligatorio 4: EBITDA usa la fórmula canónica con el OPEX TOTAL (automático+manual),
// vía applyEmployeeScope() → summaryFromRow() → buildEmployeeExpenseDetail(). Nunca una
// fórmula nueva — misma resta que ya existía (ingreso_base - (gastos + neto)).
it('shifts employee EBITDA by exactly the combined automatic+manual OPEX, via the canonical formula', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Orizaba');
    $employee = makeAttribRosterEmployee($period, 'EMPLEADO EBITDA C', $branch);

    $builder = app(RadiographySnapshotBuilder::class);
    $rowSin = makeEbitdaFixtureRow($employee->id);
    $rowSin['name'] = 'EMPLEADO EBITDA C';

    // Sin ningún gasto (automático ni manual) — baseline.
    $snapshotSin = makeGeneralSnapshotFixture($period->id, [], [$rowSin]);
    $resultSin = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshotSin, $employee->id, [$rowSin], $period, []]]);

    // Automático $200 (fact_expenses real) + manual $1,500 (config de esta corrida).
    makeAttribExpense($period, $upload, ['amount' => 200, 'paid_amount' => 200, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);
    $snapshotCon = makeGeneralSnapshotFixture($period->id, [], [$rowSin]);
    $config = ['extra_employee_expense_amount' => 1500.0, 'extra_employee_expense_notes' => 'Viáticos y comunicación'];
    $resultCon = invokeScopeMethod($builder, 'applyEmployeeScope', ['dataIds' => [$period->id], 'args' => [$snapshotCon, $employee->id, [$rowSin], $period, $config]]);

    expect((float) $resultSin['summary']['opex_total'])->toBe(0.0);
    expect((float) $resultCon['summary']['opex_total'])->toBe(1700.0);
    expect((float) $resultCon['summary']['expenses_automatic_total'])->toBe(200.0);
    expect((float) $resultCon['summary']['expenses_manual_total'])->toBe(1500.0);

    $ebitdaSin = (float) $resultSin['summary']['ebitda_final'];
    $ebitdaCon = (float) $resultCon['summary']['ebitda_final'];

    // ebitda = ingreso_base - (gastos + neto) → EBITDA baja exactamente $1,700 (200+1500).
    expect(round($ebitdaSin - $ebitdaCon, 2))->toBe(1700.0);
});

// Test obligatorio 5: el input manual NUNCA toca fact_expenses ni el OPEX general oficial —
// sigue siendo semántica de ESTA configuración de reporte, no un dato persistido.
it('never writes the manual amount to fact_expenses or changes the official general OPEX', function () {
    $period = makeAttribPeriodo();
    $upload = makeAttribUpload($period);
    $branch = makeAttribBranch('Orizaba');
    $employee = makeAttribRosterEmployee($period, 'EMPLEADO EBITDA D', $branch);
    makeAttribExpense($period, $upload, ['amount' => 200, 'paid_amount' => 200, 'employee_id' => $employee->id, 'branch_id' => $branch->id]);

    $totalFactExpensesAntes = (float) DB::table('fact_expenses')->where('period_id', $period->id)->count();
    $sumaAntes = (float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('paid_amount');

    app(RadiographySnapshotBuilder::class)->buildEmployeeExpenseDetail([$employee->id], 1500.0, 'Ajuste manual');

    expect((float) DB::table('fact_expenses')->where('period_id', $period->id)->count())->toBe($totalFactExpensesAntes);
    expect((float) DB::table('fact_expenses')->where('period_id', $period->id)->sum('paid_amount'))->toBe($sumaAntes);
    expect($sumaAntes)->toBe(200.0); // el manual ($1,500) nunca aparece en fact_expenses
});
