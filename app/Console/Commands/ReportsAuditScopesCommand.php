<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\PeriodEmployeeRosterService;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría automática de conciliación por (periodo × alcance) — GENERAL, cada sucursal
 * operativa y cada colaborador del roster de cada periodo. Recorre el MISMO pipeline
 * canónico (RadiographySnapshotBuilder::build()/projectScope()) que usan Web/Excel/PDF —
 * nunca recalcula por un camino aparte — y evalúa los invariantes de reconciliación
 * (recuperación, colocación, cartera/mora, nómina, EBITDA) más señales de identidad y
 * fuga de datos generales bajo un alcance individual.
 *
 * Objetivo explícito: dejar de validar "Margarita mayo" a mano. Este comando encuentra
 * TODOS los periodos/sucursales/colaboradores rotos de una sola corrida.
 *
 *   php artisan reports:audit-scopes                       // todo
 *   php artisan reports:audit-scopes --period=21            // un periodo
 *   php artisan reports:audit-scopes --period=21 --employee=17
 *   php artisan reports:audit-scopes --errors-only
 *   php artisan reports:audit-scopes --json > audit.json
 */
class ReportsAuditScopesCommand extends Command
{
    protected $signature = 'reports:audit-scopes
                                {--period= : ID del periodo a auditar (default: todos)}
                                {--employee= : ID de un colaborador específico}
                                {--branch= : ID de una sucursal específica}
                                {--errors-only : Solo mostrar filas con algún ERROR_*}
                                {--json : Salida JSON en vez de texto}';

    protected $description = 'Auditoría de conciliación por periodo/sucursal/colaborador — recuperación, colocación, cartera/mora, nómina, EBITDA, identidad y fugas de datos.';

    private const TOLERANCE = 0.01;

    public function handle(RadiographySnapshotBuilder $builder, PeriodEmployeeRosterService $rosterService): int
    {
        $periods = $this->option('period')
            ? Period::whereKey((int) $this->option('period'))->get()
            : Period::orderBy('id')->get();

        if ($periods->isEmpty()) {
            $this->error('No se encontró el periodo indicado.');
            return 1;
        }

        $employeeFilter = $this->option('employee') ? (int) $this->option('employee') : null;
        $branchFilter   = $this->option('branch') ? (int) $this->option('branch') : null;
        $errorsOnly     = (bool) $this->option('errors-only');
        $asJson         = (bool) $this->option('json');

        $allBranchesByName = Branch::query()->get(['id', 'name'])
            ->keyBy(fn ($b) => strtoupper(trim($b->name)));

        $rows = [];
        $bar  = $asJson ? null : $this->output->createProgressBar($periods->count());

        foreach ($periods as $period) {
            $summary = PeriodSummary::where('period_id', $period->id)->latest('id')->first();

            // Un periodo sin PeriodSummary, o cuyo summary nunca llegó a 'generated' (p. ej.
            // se quedó en 'database_updated' o fue invalidado), NO es una inconsistencia
            // financiera — simplemente nunca se generó (o ya no es válida) su radiografía.
            // Marcarlo como ERROR_* infla el conteo de "errores" con periodos que ni siquiera
            // aplican, y le resta señal a los errores reales. Ver PARTE B del cierre.
            if (!$summary || $summary->status !== 'generated' || $summary->invalidated_at) {
                $reason = !$summary
                    ? 'No existe PeriodSummary para este periodo — la radiografía nunca se generó.'
                    : ($summary->invalidated_at
                        ? 'El PeriodSummary fue invalidado (' . ($summary->invalidated_reason ?: 'sin motivo registrado') . ') — no representa una radiografía vigente.'
                        : "El PeriodSummary existe pero su status es '{$summary->status}', nunca llegó a 'generated'.");
                $rows[] = $this->baseRow($period, 'general', null, null)
                    + ['status' => 'SKIPPED_NO_GENERATED_RADIOGRAPHY', 'modules' => [], 'detail' => ['reason' => $reason]];
                $bar?->advance();
                continue;
            }

            try {
                $general = $builder->build($period, $summary, ['scope' => 'general']);
            } catch (\Throwable $e) {
                $rows[] = $this->baseRow($period, 'general', null, null)
                    + ['status' => 'ERROR_PERIOD_MAPPING', 'modules' => [], 'detail' => ['reason' => 'build(general) lanzó una excepción: ' . $e->getMessage()]];
                $bar?->advance();
                continue;
            }

            if (!$employeeFilter && !$branchFilter) {
                $rows[] = $this->evaluate($general, $period, 'general', null, null, $general);
            }

            // ── Conflictos de identidad (item 11/12 del cierre) ──────────────
            // Nunca silenciosos: reports:audit-scopes es la fuente oficial para
            // detectarlos, aunque no bloqueen la generación por sí solos.
            foreach ($builder->lastIdentityConflicts() as $conflict) {
                $rows[] = $this->baseRow($period, 'employee', null, $conflict['normalized_name'])
                    + ['status' => 'IDENTITY_CONFLICT', 'modules' => ['IDENTITY' => 'IDENTITY_CONFLICT'], 'detail' => [
                        'employee_ids' => implode(',', $conflict['employee_ids']),
                        'branch_ids'   => json_encode($conflict['branch_ids']),
                        'reason'       => 'Mismo nombre normalizado, sucursales operativas distintas y contradictorias en el mismo periodo — no se fusionó automáticamente.',
                    ]];
            }

            // ── Sucursales ──────────────────────────────────────────────────
            if (!$employeeFilter) {
                foreach ($general['branch_radiography']['branches'] ?? [] as $branchRow) {
                    $name = strtoupper(trim($branchRow['sucursal'] ?? ''));
                    $branch = $allBranchesByName->get($name);
                    if (!$branch) continue;
                    if ($branchFilter && (int) $branch->id !== $branchFilter) continue;

                    $scoped = $builder->projectScope($general, $period, ['scope' => 'branch', 'branch_id' => $branch->id]);
                    $rows[] = $this->evaluate($scoped, $period, 'branch', (int) $branch->id, $branch->name, $general);
                }
            }

            // ── Colaboradores del roster ────────────────────────────────────
            if (!$branchFilter) {
                $roster = $rosterService->rosterRowsForSelector($period)['rows'] ?? [];
                foreach ($roster as $emp) {
                    $empId = (int) $emp['employee_id'];
                    if ($employeeFilter && $empId !== $employeeFilter) continue;

                    $scoped = $builder->projectScope($general, $period, ['scope' => 'employee', 'employee_id' => $empId]);
                    $rows[] = $this->evaluate($scoped, $period, 'employee', $empId, $emp['name'], $general);
                }
            }

            $bar?->advance();
        }
        $bar?->finish();
        if (!$asJson) $this->newLine(2);

        if ($errorsOnly) {
            $rows = array_values(array_filter($rows, fn ($r) => $this->rowHasError($r)));
        }

        if ($asJson) {
            $this->line(json_encode(['rows' => $rows, 'tally' => $this->tally($rows)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        foreach ($rows as $r) {
            $this->printRow($r);
        }

        $this->printTally($rows);

        return 0;
    }

    private function baseRow(Period $period, string $scopeType, ?int $refId, ?string $refName): array
    {
        return [
            'period_id'    => $period->id,
            'period_label' => $period->label,
            'scope_type'   => $scopeType,
            'ref_id'       => $refId,
            'ref_name'     => $refName,
        ];
    }

    private function approx(float $a, float $b, float $tol = self::TOLERANCE): bool
    {
        return abs($a - $b) <= $tol;
    }

    /**
     * Evalúa TODOS los invariantes de reconciliación para una fila (period × scope).
     * $scoped = snapshot ya proyectado (general/branch/employee). $general = snapshot
     * general del mismo periodo, usado solo para la comparación de fuga de datos.
     */
    private function evaluate(array $scoped, Period $period, string $scopeType, ?int $refId, ?string $refName, array $general): array
    {
        $summary  = $scoped['summary'] ?? [];
        $sections = $scoped['sections'] ?? [];
        $scopeMeta = $scoped['scope'] ?? [];
        $available = (bool) ($scopeMeta['available'] ?? true);

        $modules = [];
        $detail  = [];

        // ── IDENTITY ──────────────────────────────────────────────────────
        if ($scopeType === 'employee') {
            $resolution = $scopeMeta['identity_resolution'] ?? null;
            // Item 12 del cierre: quiero que fuzzy sea excepcional — se registra SIEMPRE
            // (no solo en el caso de error) para poder tallarlo en el resumen final.
            $detail['identity_resolution'] = $resolution;
            if (!$available) {
                $modules['IDENTITY'] = 'NOT_ATTRIBUTABLE';
                $detail['identity'] = "Sin fila fusionada para employee_id={$refId} este periodo (roster lo lista, fact tables no aportaron datos atribuibles). resolution={$resolution}";
            } elseif (in_array($resolution, ['name_fallback_exact', 'name_fallback_substring'], true)) {
                $modules['IDENTITY'] = 'ERROR_IDENTITY';
                $detail['identity'] = "Fila resuelta por NOMBRE ({$resolution}), no por employee_id — vinculación de identidad incompleta este periodo para employee_id={$refId}.";
            } else {
                $modules['IDENTITY'] = 'OK';
            }
        }

        if (!$available) {
            // Sin datos para este alcance — no tiene sentido evaluar el resto de módulos.
            return array_merge($this->baseRow($period, $scopeType, $refId, $refName), [
                'status'  => $modules['IDENTITY'] ?? 'NOT_ATTRIBUTABLE',
                'modules' => $modules + ['RECOVERY' => 'NOT_ATTRIBUTABLE', 'PLACEMENT' => 'NOT_ATTRIBUTABLE', 'PORTFOLIO' => 'NOT_ATTRIBUTABLE', 'PAYROLL' => 'NOT_ATTRIBUTABLE', 'EBITDA' => 'NOT_ATTRIBUTABLE'],
                'detail'  => $detail,
            ]);
        }

        // ── RECOVERY ──────────────────────────────────────────────────────
        $recoveryTotal = round((float) ($summary['recovery_total'] ?? 0), 2);
        $modules['RECOVERY'] = 'OK';
        if ($scopeType !== 'general') {
            $rc = $sections['recovery_components'] ?? null;
            if (is_array($rc) && !($rc['not_attributable'] ?? false) && !empty($rc)) {
                $sum = round(array_sum(array_map('floatval', $rc)), 2);
                if (!$this->approx($sum, $recoveryTotal)) {
                    $modules['RECOVERY'] = 'ERROR_RECOVERY_RECONCILIATION';
                    $detail['recovery_components_sum'] = $sum;
                    $detail['recovery_total']           = $recoveryTotal;
                }
            }
            $byProductKey = $scopeType === 'employee' ? 'recovery_by_product_scope' : null;
            if ($byProductKey && $modules['RECOVERY'] === 'OK') {
                $rbp = $sections[$byProductKey] ?? null;
                if (is_array($rbp) && !($rbp['not_attributable'] ?? false) && !empty($rbp)) {
                    $sum2 = round(array_sum(array_column($rbp, 'recuperacion')), 2);
                    if (!$this->approx($sum2, $recoveryTotal)) {
                        $modules['RECOVERY'] = 'ERROR_RECOVERY_RECONCILIATION';
                        $detail['recovery_by_product_sum'] = $sum2;
                        $detail['recovery_total']          = $recoveryTotal;
                    }
                }
            }
        }

        // ── PLACEMENT ─────────────────────────────────────────────────────
        $placementTotal = round((float) ($summary['placement_total'] ?? 0), 2);
        $modules['PLACEMENT'] = 'OK';
        if ($scopeType === 'employee') {
            $products = $sections['products'] ?? null;
            if (is_array($products) && !($products['not_attributable'] ?? false) && !empty($products)) {
                $sum = round(array_sum(array_column($products, 'colocacion')), 2);
                if (!$this->approx($sum, $placementTotal)) {
                    $modules['PLACEMENT'] = 'ERROR_PLACEMENT_RECONCILIATION';
                    $detail['placement_products_sum'] = $sum;
                    $detail['placement_total']         = $placementTotal;
                }
            }
        }
        // Nota: branch/general no exponen un desglose de colocación por producto acotado a
        // ese alcance en este snapshot — no se inventa una comparación sin esa fuente.

        // ── PORTFOLIO / MORA ──────────────────────────────────────────────
        $overdue = round((float) ($summary['overdue_portfolio'] ?? 0), 2);
        $modules['PORTFOLIO'] = 'OK';
        $buckets = $sections['portfolio_buckets'] ?? null;
        if (is_array($buckets) && !($buckets['not_attributable'] ?? false) && !empty($buckets) && array_is_list($buckets)) {
            $sum = round((float) array_sum(array_column($buckets, 'vencida')), 2);
            if (!$this->approx($sum, $overdue)) {
                $modules['PORTFOLIO'] = 'ERROR_MORA_RECONCILIATION';
                $detail['mora_buckets_sum']  = $sum;
                $detail['overdue_portfolio'] = $overdue;
            }
        }

        // ── PAYROLL ───────────────────────────────────────────────────────
        $payrollTotal = round((float) ($summary['nomina_capital_humano_total'] ?? 0), 2);
        $noiPercep    = round((float) ($summary['noi_percepciones'] ?? 0), 2);
        $noiDeduc     = round((float) ($summary['noi_deducciones'] ?? 0), 2);
        $modules['PAYROLL'] = 'OK';
        if ($payrollTotal > self::TOLERANCE && $noiPercep <= self::TOLERANCE && $noiDeduc <= self::TOLERANCE) {
            $modules['PAYROLL'] = 'ERROR_PAYROLL_SOURCE_MISMATCH';
            $detail['payroll_total']    = $payrollTotal;
            $detail['noi_percepciones'] = $noiPercep;
            $detail['noi_deducciones']  = $noiDeduc;
            if ($scopeType === 'employee') {
                $detail['noi_rows_raw_employee_id'] = DB::table('fact_noi_movements')
                    ->where('employee_id', $refId)
                    ->whereIn('period_id', $this->dataIdsFor($period))
                    ->count();
                $detail['identity_resolution'] = $scopeMeta['identity_resolution'] ?? null;
            }
        } elseif ($scopeType === 'employee') {
            $payrollDetail = $sections['payroll_detail'] ?? null;
            if (is_array($payrollDetail) && isset($payrollDetail['percepciones_total'], $payrollDetail['deducciones_total'])) {
                if (!$this->approx((float) $payrollDetail['percepciones_total'], $noiPercep)
                    || !$this->approx((float) $payrollDetail['deducciones_total'], $noiDeduc)) {
                    $modules['PAYROLL'] = 'ERROR_PAYROLL_RECONCILIATION';
                    $detail['payroll_detail_percepciones'] = $payrollDetail['percepciones_total'];
                    $detail['noi_percepciones']             = $noiPercep;
                    $detail['payroll_detail_deducciones']  = $payrollDetail['deducciones_total'];
                    $detail['noi_deducciones']              = $noiDeduc;
                }
            }
        }

        // ── EBITDA ────────────────────────────────────────────────────────
        $ingresoBase   = round((float) ($summary['ingreso_ebitda_base'] ?? 0), 2);
        $gastosTotales = round((float) ($summary['gastos_totales'] ?? 0), 2);
        $ebitdaFinal   = round((float) ($summary['ebitda_final'] ?? 0), 2);
        $expected      = round($ingresoBase - $gastosTotales, 2);
        $modules['EBITDA'] = 'OK';
        if (!$this->approx($expected, $ebitdaFinal)) {
            $modules['EBITDA'] = 'ERROR_EBITDA_RECONCILIATION';
            $detail['ebitda_expected'] = $expected;
            $detail['ebitda_final']    = $ebitdaFinal;
        }

        // ── GLOBAL LEAK ───────────────────────────────────────────────────
        $modules['GLOBAL_LEAK'] = 'OK';
        if ($scopeType !== 'general') {
            $generalSummary = $general['summary'] ?? [];
            foreach (['recovery_total', 'placement_total', 'portfolio_total', 'nomina_capital_humano_total'] as $field) {
                $scopedVal  = (float) ($summary[$field] ?? 0);
                $generalVal = (float) ($generalSummary[$field] ?? 0);
                if ($scopedVal > $generalVal + self::TOLERANCE) {
                    $modules['GLOBAL_LEAK'] = 'ERROR_GLOBAL_DATA_LEAK';
                    $detail['leak_field']        = $field;
                    $detail['leak_scoped_value'] = round($scopedVal, 2);
                    $detail['leak_general_value']= round($generalVal, 2);
                    break;
                }
            }
        }

        $status = $this->overallStatus($modules);

        return array_merge($this->baseRow($period, $scopeType, $refId, $refName), [
            'status'  => $status,
            'modules' => $modules,
            'detail'  => $detail,
        ]);
    }

    private function dataIdsFor(Period $period): array
    {
        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        return array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));
    }

    private function overallStatus(array $modules): string
    {
        foreach ($modules as $status) {
            if (str_starts_with($status, 'ERROR_')) return $status;
        }
        return 'OK';
    }

    private function rowHasError(array $row): bool
    {
        return str_starts_with($row['status'] ?? '', 'ERROR_');
    }

    private function printRow(array $r): void
    {
        $header = strtoupper($r['scope_type']) . ($r['ref_name'] ? " — {$r['ref_name']}" : '');
        $this->line("PERIODO: {$r['period_label']} (ID {$r['period_id']})  |  {$header}");

        foreach ($r['modules'] as $mod => $status) {
            $color = str_starts_with($status, 'ERROR_') ? 'error' : ($status === 'OK' ? 'info' : 'comment');
            $this->line('  ' . str_pad($mod, 14) . "<{$color}>{$status}</{$color}>");
        }

        if (!empty($r['detail'])) {
            $this->line('  Detalle:');
            foreach ($r['detail'] as $k => $v) {
                $this->line("    {$k}: " . (is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)));
            }
        }
        $this->newLine();
    }

    private function tally(array $rows): array
    {
        $tally = [];
        foreach ($rows as $r) {
            $tally[$r['status']] = ($tally[$r['status']] ?? 0) + 1;
        }
        ksort($tally);
        return ['total' => count($rows)] + $tally;
    }

    private function printTally(array $rows): void
    {
        $this->info('════ RESUMEN ════');
        $this->line('Total filas auditadas: ' . count($rows));
        foreach ($this->tally($rows) as $status => $count) {
            if ($status === 'total') continue;
            $this->line('  ' . str_pad($status, 32) . $count);
        }

        // Item 12 del cierre: quiero que fuzzy sea excepcional — desglose de CÓMO se
        // resolvió la identidad en cada fila employee (nunca solo "OK"/"ERROR").
        $resolutionCounts = [];
        foreach ($rows as $r) {
            if (($r['scope_type'] ?? null) !== 'employee') continue;
            $resolution = $r['detail']['identity_resolution'] ?? 'not_applicable';
            $label = match ($resolution) {
                'employee_id' => 'resolved_by_employee_id',
                'name_fallback_exact' => 'resolved_by_exact_name',
                'name_fallback_substring' => 'resolved_by_fuzzy',
                default => $resolution ?? 'unresolved',
            };
            $resolutionCounts[$label] = ($resolutionCounts[$label] ?? 0) + 1;
        }
        if (!empty($resolutionCounts)) {
            ksort($resolutionCounts);
            $this->newLine();
            $this->info('════ IDENTIDAD (colaboradores) ════');
            foreach ($resolutionCounts as $label => $count) {
                $this->line('  ' . str_pad($label, 32) . $count);
            }
        }
    }
}
