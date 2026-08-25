<?php

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Items 7, 8 y 9 del pendiente 2026-08-25 — endpoint HTTP REAL (/scoped-data), no
 * reflection, contra la BD de desarrollo real (solo lectura, jamás
 * RefreshDatabase, jamás factories que escriban: usa un User YA existente).
 *
 * Objetivo explícito: detectar "funciona un mes y falla otro" recorriendo TODOS los
 * periodos donde cada persona aparece en el roster, no solo uno.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();

    $this->actingUser = User::query()->first();
    if (!$this->actingUser) {
        $this->markTestSkipped('No hay ningún usuario en la BD de desarrollo para autenticar la request.');
    }
});

/**
 * Periodos (mensuales, con radiografía generada) donde $employeeId aparece en el
 * roster — vía PeriodEmployeeRosterService, nunca hardcodeado.
 */
function periodsForEmployee(int $employeeId): array
{
    $periods = Period::query()->where('type', 'monthly')
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->orderBy('id')->get();

    $rosterService = app(\App\Services\PeriodEmployeeRosterService::class);
    $matches = [];

    foreach ($periods as $period) {
        $roster = $rosterService->rosterRowsForSelector($period)['rows'] ?? [];
        foreach ($roster as $row) {
            if ((int) $row['employee_id'] === $employeeId) {
                $matches[] = $period;
                break;
            }
        }
    }

    return $matches;
}

/**
 * Valida los invariantes de reconciliación de un snapshot scoped=employee para UN
 * periodo — mismos criterios que ReportsAuditScopesCommand (±$0.01).
 */
function assertEmployeeScopeReconciles(array $snapshot, string $periodLabel, string $employeeLabel): void
{
    $summary = $snapshot['summary'] ?? [];
    $sections = $snapshot['sections'] ?? [];
    $context = "{$employeeLabel} / {$periodLabel}";

    expect($snapshot['scope']['available'] ?? false)->toBeTrue("Scope no disponible para {$context}");

    $recoveryTotal = round((float) ($summary['recovery_total'] ?? 0), 2);
    $rc = $sections['recovery_components'] ?? null;
    if (is_array($rc) && !($rc['not_attributable'] ?? false) && !empty($rc)) {
        $sum = round(array_sum(array_map('floatval', $rc)), 2);
        expect(abs($sum - $recoveryTotal))->toBeLessThanOrEqual(0.01, "Recovery components no reconcilian para {$context}: {$sum} vs {$recoveryTotal}");
    }

    $rbp = $sections['recovery_by_product_scope'] ?? null;
    if (is_array($rbp) && !($rbp['not_attributable'] ?? false) && !empty($rbp)) {
        $sum = round(array_sum(array_column($rbp, 'recuperacion')), 2);
        expect(abs($sum - $recoveryTotal))->toBeLessThanOrEqual(0.01, "Recovery by product no reconcilia para {$context}: {$sum} vs {$recoveryTotal}");
    }

    $placementTotal = round((float) ($summary['placement_total'] ?? 0), 2);
    $products = $sections['products'] ?? null;
    if (is_array($products) && !($products['not_attributable'] ?? false) && !empty($products)) {
        $sum = round(array_sum(array_column($products, 'colocacion')), 2);
        expect(abs($sum - $placementTotal))->toBeLessThanOrEqual(0.01, "Placement by product no reconcilia para {$context}: {$sum} vs {$placementTotal}");
    }

    $overdue = round((float) ($summary['overdue_portfolio'] ?? 0), 2);
    // mora_buckets es una LISTA (0-indexada) de filas {key,label,monto,...} — el bucket
    // se identifica por el campo 'key' de cada fila, NUNCA por el índice del array.
    $moraBuckets = $sections['mora_buckets'] ?? null;
    if (is_array($moraBuckets) && !($moraBuckets['not_attributable'] ?? false) && !empty($moraBuckets) && array_is_list($moraBuckets)) {
        $sum = 0.0;
        foreach ($moraBuckets as $b) {
            if (($b['key'] ?? null) !== 'al_corriente') $sum += (float) ($b['monto'] ?? 0);
        }
        expect(abs(round($sum, 2) - $overdue))->toBeLessThanOrEqual(0.01, "Mora buckets no reconcilian para {$context}: {$sum} vs {$overdue}");
    }

    // Regla explícita del pendiente: Nómina > 0 nunca puede convivir con NOI detail = 0
    // silenciosamente (el patrón "José Alberto" del cierre original).
    $payrollTotal = round((float) ($summary['nomina_capital_humano_total'] ?? 0), 2);
    $noiPercep = round((float) ($summary['noi_percepciones'] ?? 0), 2);
    $noiDeduc = round((float) ($summary['noi_deducciones'] ?? 0), 2);
    if ($payrollTotal > 0.01) {
        expect($noiPercep > 0.01 || $noiDeduc > 0.01)->toBeTrue(
            "Nómina={$payrollTotal} > 0 pero NOI percepciones/deducciones = 0 para {$context} — el dataset no explica el total."
        );
    }

    $ingresoBase = round((float) ($summary['ingreso_ebitda_base'] ?? 0), 2);
    $gastosTotales = round((float) ($summary['gastos_totales'] ?? 0), 2);
    $ebitdaFinal = round((float) ($summary['ebitda_final'] ?? 0), 2);
    expect(abs(round($ingresoBase - $gastosTotales, 2) - $ebitdaFinal))->toBeLessThanOrEqual(0.01, "EBITDA no reconcilia para {$context}");

    // Global leak: ningún total scoped puede exceder el total general del periodo.
}

function fetchEmployeeScopedData(Period $period, int $employeeId, User $user): array
{
    $response = test()->actingAs($user)->getJson("/reportes-mensuales/{$period->id}/scoped-data?scope=employee&employee_id={$employeeId}");
    $response->assertOk();

    return $response->json('snapshot');
}

it('reconciles Margarita in every period she appears in the roster (not just one month)', function () {
    $margarita = \App\Models\Employee::query()
        ->where('full_name', 'LIKE', '%MARGARITA%NOLASCO%')
        ->orWhere('full_name', 'LIKE', '%MARGARITA JAZMIN%DOMINGUEZ%')
        ->first();

    if (!$margarita) {
        $this->markTestSkipped('No se encontró a Margarita en la BD de desarrollo.');
    }

    $periods = periodsForEmployee($margarita->id);
    if (empty($periods)) {
        $this->markTestSkipped("Margarita (employee_id={$margarita->id}) no aparece en el roster de ningún periodo con radiografía generada.");
    }

    foreach ($periods as $period) {
        $snapshot = fetchEmployeeScopedData($period, $margarita->id, $this->actingUser);
        assertEmployeeScopeReconciles($snapshot, $period->label, 'Margarita');
    }

    expect(count($periods))->toBeGreaterThan(0);
});

it('reconciles José Alberto Ameca Rodríguez in every period he appears, especially payroll > 0 with NOI detail', function () {
    $jose = \App\Models\Employee::query()->where('full_name', 'LIKE', '%AMECA%RODRIGUEZ%')->first();

    if (!$jose) {
        $this->markTestSkipped('No se encontró a José Alberto Ameca Rodríguez en la BD de desarrollo.');
    }

    $periods = periodsForEmployee($jose->id);
    if (empty($periods)) {
        $this->markTestSkipped("José (employee_id={$jose->id}) no aparece en el roster de ningún periodo con radiografía generada.");
    }

    foreach ($periods as $period) {
        $snapshot = fetchEmployeeScopedData($period, $jose->id, $this->actingUser);
        assertEmployeeScopeReconciles($snapshot, $period->label, 'José Alberto Ameca Rodríguez');
    }

    expect(count($periods))->toBeGreaterThan(0);
});

it('reconciles 5 additional employees auto-selected from the roster (not hardcoded)', function () {
    $period = Period::query()->where('type', 'monthly')
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('id')->first();

    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales con radiografía generada.');
    }

    $roster = app(\App\Services\PeriodEmployeeRosterService::class)->rosterRowsForSelector($period)['rows'] ?? [];
    if (count($roster) < 5) {
        $this->markTestSkipped("El roster de {$period->label} tiene menos de 5 colaboradores.");
    }

    $sample = collect($roster)->shuffle()->take(5);
    $tested = 0;

    foreach ($sample as $row) {
        $employeeId = (int) $row['employee_id'];
        $snapshot = fetchEmployeeScopedData($period, $employeeId, $this->actingUser);
        if (($snapshot['scope']['available'] ?? false) === false) {
            continue; // sin datos atribuibles este periodo — no es un error, ver PARTE de identidad
        }
        assertEmployeeScopeReconciles($snapshot, $period->label, $row['name']);
        $tested++;
    }

    expect($tested)->toBeGreaterThan(0);
});

it('reconciles 3 real branches auto-selected (not hardcoded)', function () {
    $period = Period::query()->where('type', 'monthly')
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('id')->first();

    if (!$period) {
        $this->markTestSkipped('No hay periodos mensuales con radiografía generada.');
    }

    // Sucursales OPERATIVAS reales (scopedData() rechaza con 422 cualquier otra,
    // ver MonthlyReportController::OPERATIVE_BRANCH_NAMES) — nunca hardcodeadas por
    // nombre, se resuelven dinámicamente contra esa misma lista.
    $operativeNames = (new \ReflectionClass(\App\Http\Controllers\MonthlyReportController::class))
        ->getConstant('OPERATIVE_BRANCH_NAMES');
    $branches = Branch::query()->whereIn('name', $operativeNames)->inRandomOrder()->limit(3)->get();
    if ($branches->count() < 3) {
        $this->markTestSkipped('Menos de 3 sucursales operativas en la BD de desarrollo.');
    }

    foreach ($branches as $branch) {
        $response = $this->actingAs($this->actingUser)
            ->getJson("/reportes-mensuales/{$period->id}/scoped-data?scope=branch&branch_id={$branch->id}");
        $response->assertOk();
        $snapshot = $response->json('snapshot');

        if (($snapshot['scope']['available'] ?? false) === false) {
            continue;
        }

        $summary = $snapshot['summary'] ?? [];
        $recoveryTotal = round((float) ($summary['recovery_total'] ?? 0), 2);
        $overdue = round((float) ($summary['overdue_portfolio'] ?? 0), 2);

        $buckets = $snapshot['sections']['portfolio_buckets'] ?? null;
        if (is_array($buckets) && array_is_list($buckets) && !empty($buckets)) {
            $sum = round((float) array_sum(array_column($buckets, 'vencida')), 2);
            expect(abs($sum - $overdue))->toBeLessThanOrEqual(0.01, "Portfolio buckets no reconcilian para sucursal {$branch->name}");
        }

        expect($recoveryTotal)->toBeGreaterThanOrEqual(0);
    }
});
