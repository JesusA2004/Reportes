<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\EmployeeNameCanonicalizer;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\Radiography\RadiographyWorkbookBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class RadiografiaExportService
{
    public function __construct(
        private RadiographyWorkbookBuilder $workbookBuilder,
        private RadiographySnapshotBuilder $snapshotBuilder,
    ) {}

    public function export(Period $period, array $config = []): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = $this->requireSummary($period);

        $snapshot    = $this->snapshotBuilder->build($period, $summary, $config);
        $spreadsheet = $this->workbookBuilder->buildFromSnapshot($period, $summary, $snapshot);

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . now()->format('Ymd_His') . '.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->setIncludeCharts(true);
        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $outputPath;
    }

    public function exportPdf(Period $period, array $config = []): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);

        $snapshot = $this->snapshotBuilder->build($period, $summary, $config);

        $pdf = Pdf::loadView('reports.radiography-pdf', [
            'period'   => $period,
            'snapshot' => $snapshot,
        ])->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . now()->format('Ymd_His') . '.pdf';

        $pdf->save($outputPath);

        return $outputPath;
    }

    /**
     * Export a filtered/comparative workbook based on config:
     *   scope: general | branch | employee
     *   report_type: simple | month_vs_month | bimester_vs_bimester | quarter_vs_quarter
     *   branch_id, employee_id, compare_period_id,
     *   extra_employee_expense_amount, extra_employee_expense_notes
     */
    public function exportWithConfig(Period $period, array $config): string
    {
        @ini_set('memory_limit', '1024M');

        $summary  = $this->requireSummary($period);
        $snapshot = $this->snapshotBuilder->build($period, $summary);

        $scope      = $config['scope'] ?? 'general';
        $reportType = $config['report_type'] ?? 'simple';

        if (in_array($reportType, ['month_vs_month', 'bimester_vs_bimester', 'quarter_vs_quarter'])) {
            $comparePeriodId = (int) ($config['compare_period_id'] ?? 0);
            if (!$comparePeriodId) {
                throw new RuntimeException('Se requiere compare_period_id para reportes comparativos.');
            }
            $comparePeriod = Period::findOrFail($comparePeriodId);
            $compareSummary = $this->requireSummary($comparePeriod);
            $compareSnap    = $this->snapshotBuilder->build($comparePeriod, $compareSummary);
            $spreadsheet    = $this->workbookBuilder->buildComparativeFromSnapshots($period, $snapshot, $comparePeriod, $compareSnap, $config);
            $suffix         = 'comparativo_' . $comparePeriod->code . '_vs_' . ($period->code ?: $period->id);
        } elseif ($scope === 'branch') {
            $branchId = (int) ($config['branch_id'] ?? 0);
            if (!$branchId) {
                throw new RuntimeException('Se requiere branch_id para reportes por sucursal.');
            }
            $spreadsheet = $this->workbookBuilder->buildBranchFromSnapshot($period, $summary, $snapshot, $branchId);
            $suffix      = 'sucursal_' . $branchId;
        } elseif ($scope === 'employee') {
            $employeeId    = (int) ($config['employee_id'] ?? 0);
            if (!$employeeId) {
                throw new RuntimeException('Se requiere employee_id para reportes por gestor.');
            }
            $extraAmount = (float) ($config['extra_employee_expense_amount'] ?? 0);
            $extraNotes  = (string) ($config['extra_employee_expense_notes'] ?? '');
            $spreadsheet = $this->workbookBuilder->buildEmployeeFromSnapshot($period, $summary, $snapshot, $employeeId, $extraAmount, $extraNotes);
            $suffix      = 'gestor_' . $employeeId;
        } else {
            $spreadsheet = $this->workbookBuilder->buildFromSnapshot($period, $summary, $snapshot);
            $suffix      = 'general';
        }

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . $suffix . '_' . now()->format('Ymd_His') . '.xlsx';

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->setPreCalculateFormulas(false);
        $writer->setIncludeCharts(true);
        $writer->save($outputPath);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $outputPath;
    }

    public function exportPdfWithConfig(Period $period, array $config): string
    {
        @ini_set('memory_limit', '1024M');

        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);
        $snapshot = $this->snapshotBuilder->build($period, $summary);

        $scope      = $config['scope'] ?? 'general';
        $reportType = $config['report_type'] ?? 'simple';

        if (in_array($reportType, ['month_vs_month', 'bimester_vs_bimester', 'quarter_vs_quarter'], true)) {
            $comparePeriodId = (int) ($config['compare_period_id'] ?? 0);
            if (!$comparePeriodId) {
                throw new RuntimeException('Se requiere compare_period_id para reportes comparativos.');
            }
            $comparePeriod  = Period::findOrFail($comparePeriodId);
            $compareSummary = $this->requireSummary($comparePeriod);
            $compareSnap    = $this->snapshotBuilder->build($comparePeriod, $compareSummary);

            [$rows, $scopeLabel] = $this->buildComparativeRows($snapshot, $compareSnap, $config);

            $pdf = Pdf::loadView('reports.radiography-pdf-comparative', [
                'period'        => $period,
                'comparePeriod' => $comparePeriod,
                'rows'          => $rows,
                'scopeLabel'    => $scopeLabel,
                'reportType'    => $reportType,
            ])->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);
            $suffix = 'comparativo_' . $comparePeriod->code;
        } elseif ($scope === 'branch') {
            $branchId = (int) ($config['branch_id'] ?? 0);
            if (!$branchId) {
                throw new RuntimeException('Se requiere branch_id para reportes por sucursal.');
            }
            $branchRow = $this->resolveBranchRow($snapshot, $branchId);

            $pdf = Pdf::loadView('reports.radiography-pdf-branch', [
                'period'     => $period,
                'snap'       => $snapshot,
                'branchRow'  => $branchRow,
            ])->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);
            $suffix = 'sucursal_' . $branchId;
        } elseif ($scope === 'employee') {
            $employeeId = (int) ($config['employee_id'] ?? 0);
            if (!$employeeId) {
                throw new RuntimeException('Se requiere employee_id para reportes por gestor.');
            }
            $extraAmount = (float) ($config['extra_employee_expense_amount'] ?? 0);
            $extraNotes  = (string) ($config['extra_employee_expense_notes'] ?? '');
            $empData = $this->resolveEmployeeRow($snapshot, $employeeId, $extraAmount);

            $pdf = Pdf::loadView('reports.radiography-pdf-employee', array_merge($empData, [
                'period'      => $period,
                'snap'        => $snapshot,
                'extraAmount' => $extraAmount,
                'extraNotes'  => $extraNotes,
            ]))->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);
            $suffix = 'gestor_' . $employeeId;
        } else {
            $pdf = Pdf::loadView('reports.radiography-pdf', [
                'period'   => $period,
                'snapshot' => $snapshot,
            ])->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);
            $suffix = 'general';
        }

        $directory  = storage_path('app/radiografias');
        File::ensureDirectoryExists($directory);
        $outputPath = $directory . '/radiografia_' . ($period->code ?: $period->id) . '_' . $suffix . '_' . now()->format('Ymd_His') . '.pdf';

        $pdf->save($outputPath);

        return $outputPath;
    }

    /**
     * Resolve a single branch's row from branch_radiography.branches, given a branch_id.
     * Same two-step resolution used for the Excel branch export (sections.branches → name →
     * branch_radiography row).
     */
    private function resolveBranchRow(array $snapshot, int $branchId): array
    {
        $branchSection = collect($snapshot['sections']['branches'] ?? [])->firstWhere('branch_id', $branchId);
        if (!$branchSection) {
            throw new RuntimeException("Sucursal ID {$branchId} no encontrada en el snapshot del periodo.");
        }
        $branchName = strtoupper(trim($branchSection['nombre']));

        $branchRow = collect($snapshot['branch_radiography']['branches'] ?? [])
            ->first(fn ($b) => strtoupper(trim($b['sucursal'])) === $branchName);

        if (!$branchRow) {
            throw new RuntimeException("Sin datos de radiografía para la sucursal {$branchSection['nombre']} en este periodo.");
        }

        return $branchRow;
    }

    /**
     * Resolve a single employee's gestor metrics, mirroring buildEmployeeFromSnapshot's
     * lookup (exact normalized-name match, then partial match fallback).
     */
    private function resolveEmployeeRow(array $snapshot, int $employeeId, float $extraExpenseAmount = 0.0): array
    {
        $employee = Employee::find($employeeId);
        if (!$employee) {
            throw new RuntimeException("Empleado ID {$employeeId} no encontrado.");
        }

        $canonicalizer = app(EmployeeNameCanonicalizer::class);
        $target        = $canonicalizer->normalize($employee->full_name ?? '');

        $empGest = $snapshot['sections']['employees_gestores'] ?? [];
        $empRow  = null;
        foreach ($empGest as $e) {
            if ($canonicalizer->normalize($e['name'] ?? '') === $target) {
                $empRow = $e;
                break;
            }
        }
        if (!$empRow) {
            foreach ($empGest as $e) {
                $norm = $canonicalizer->normalize($e['name'] ?? '');
                if ($norm && (str_contains($norm, $target) || str_contains($target, $norm))) {
                    $empRow = $e;
                    break;
                }
            }
        }

        $pagos   = (float)($empRow['pagos']       ?? 0);
        $bonos   = (float)($empRow['bonos']       ?? 0);
        $desctos = (float)($empRow['descuentos']  ?? 0);
        $gastos  = (float)($empRow['gastos']      ?? 0) + $extraExpenseAmount;
        $neto    = (float)($empRow['neto']        ?? ($pagos + $bonos - $desctos));
        $coloc   = (float)($empRow['colocacion']  ?? 0);
        $rec     = (float)($empRow['recuperacion'] ?? 0);
        $cartera = (float)($empRow['cartera']     ?? 0);
        $vencida = (float)($empRow['vencida']     ?? 0);
        $mora    = $cartera > 0 ? round($vencida / $cartera * 100, 2) : (float)($empRow['mora'] ?? 0);

        return [
            'empName'   => $employee->full_name,
            'empBranch' => $empRow['branch'] ?? 'Sin asignar',
            'pagos'     => $pagos,
            'bonos'     => $bonos,
            'desctos'   => $desctos,
            'gastos'    => $gastos,
            'neto'      => $neto,
            'coloc'     => $coloc,
            'ops'       => (int)($empRow['operaciones'] ?? 0),
            'rec'       => $rec,
            'cartera'   => $cartera,
            'vencida'   => $vencida,
            'mora'      => $mora,
            // EBITDA estimado = Recuperación − Colocación − (Gastos + NóminaNet) — misma fórmula que Excel.
            'utilidad'  => $rec - $coloc - ($gastos + $pagos + $bonos - $desctos),
        ];
    }

    /**
     * Build the comparative metrics rows (prev/curr/diff/var%), respecting scope
     * general|branch|employee — same metric set and logic as
     * RadiographyWorkbookBuilder::buildComparativeFromSnapshots().
     */
    private function buildComparativeRows(array $currentSnap, array $compareSnap, array $config): array
    {
        $scope    = $config['scope']       ?? 'general';
        $branchId = $config['branch_id']   ?? null;
        $empId    = $config['employee_id'] ?? null;

        $scopeLabel = 'General';
        $branchName = null;

        if ($scope === 'branch' && $branchId) {
            $branchRow  = collect($currentSnap['sections']['branches'] ?? [])->firstWhere('branch_id', (int)$branchId);
            $branchName = $branchRow['nombre'] ?? "Sucursal #{$branchId}";
            $scopeLabel = $branchName;
        } elseif ($scope === 'employee' && $empId) {
            $emp        = Employee::find($empId);
            $scopeLabel = $emp->full_name ?? "Empleado #{$empId}";
        }

        $branchVal = function (array $snap, string $field) use ($branchId, $branchName): float {
            if (!$branchId) return 0.0;
            $brUp     = strtoupper(trim($branchName ?? ''));
            $branches = $snap['sections']['branches'] ?? [];
            $row = collect($branches)->firstWhere('branch_id', (int)$branchId);
            if (!$row) { $row = collect($branches)->first(fn ($b) => strtoupper($b['nombre'] ?? '') === $brUp); }
            return (float)($row[$field] ?? 0);
        };

        $canonicalizer = app(EmployeeNameCanonicalizer::class);
        $empVal = function (array $snap, string $field) use ($empId, $canonicalizer): float {
            if (!$empId) return 0.0;
            $emp    = Employee::find($empId);
            $target = $canonicalizer->normalize($emp->full_name ?? '');
            foreach ($snap['sections']['employees_gestores'] ?? [] as $r) {
                if ($canonicalizer->normalize($r['name'] ?? '') === $target) {
                    return (float)($r[$field] ?? 0);
                }
            }
            return 0.0;
        };

        $get = function (array $snap, string $path) use ($scope, $branchVal, $empVal, $branchId, $empId): float {
            if ($scope === 'branch' && $branchId) {
                return match ($path) {
                    'cartera'      => $branchVal($snap, 'cartera'),
                    'colocacion'   => $branchVal($snap, 'colocacion'),
                    'recuperacion' => $branchVal($snap, 'recuperacion'),
                    'gastos'       => $branchVal($snap, 'gastos'),
                    'mora'         => $branchVal($snap, 'mora'),
                    'vencida'      => $branchVal($snap, 'vencida'),
                    default        => 0.0,
                };
            }
            if ($scope === 'employee' && $empId) {
                return match ($path) {
                    'recuperacion' => $empVal($snap, 'recuperacion'),
                    'colocacion'   => $empVal($snap, 'colocacion'),
                    'cartera'      => $empVal($snap, 'cartera'),
                    'vencida'      => $empVal($snap, 'vencida'),
                    'gastos'       => $empVal($snap, 'gastos'),
                    default        => 0.0,
                };
            }
            return match ($path) {
                'cartera'      => (float)($snap['summary']['portfolio_total']   ?? 0),
                'colocacion'   => (float)($snap['summary']['placement_total']   ?? 0),
                'recuperacion' => (float)($snap['summary']['recovery_total']    ?? 0),
                'gastos'       => (float)($snap['summary']['expenses_total']    ?? 0),
                'vencida'      => (float)($snap['summary']['overdue_portfolio'] ?? 0),
                'mora'         => (float)($snap['summary']['mora_index']        ?? 0),
                default        => 0.0,
            };
        };

        $metrics = [
            ['Valor cartera',      'cartera',      'currency'],
            ['Otorgamientos',      'colocacion',   'currency'],
            ['Recuperación total', 'recuperacion', 'currency'],
            ['Cartera vencida',    'vencida',      'currency'],
            ['Gastos totales',     'gastos',       'currency'],
        ];
        if ($scope === 'general' || $scope === 'branch') {
            $metrics[] = ['Mora %', 'mora', 'percent'];
        }

        $rows = [];
        foreach ($metrics as [$label, $path, $fmt]) {
            $prev = $get($compareSnap, $path);
            $curr = $get($currentSnap, $path);
            $diff = $curr - $prev;
            $varPct = $prev != 0 ? round($diff / abs($prev) * 100, 2) : ($curr != 0 ? 100.0 : 0.0);
            $rows[] = ['label' => $label, 'prev' => $prev, 'curr' => $curr, 'diff' => $diff, 'var_pct' => $varPct, 'fmt' => $fmt];
        }

        return [$rows, $scopeLabel];
    }

    /**
     * Build the snapshot for use in the web preview page.
     * This avoids recalculating in the controller.
     */
    public function buildSnapshot(Period $period, array $config = []): array
    {
        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);
        return $this->snapshotBuilder->build($period, $summary, $config);
    }

    private function requireSummary(Period $period): PeriodSummary
    {
        $summary = PeriodSummary::query()
            ->with(['branchSummaries', 'incidents'])
            ->where('period_id', $period->id)
            ->where('status', 'generated')
            ->first();

        if (!$summary) {
            throw new \RuntimeException("No existe una radiografía generada para el periodo {$period->label}.");
        }

        return $summary;
    }
}
