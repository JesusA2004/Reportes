<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\EmployeeNameCanonicalizer;
use App\Services\Radiography\BranchRadiographyCalculator;
use App\Services\Radiography\RadiographySnapshotBuilder;
use App\Services\Radiography\RadiographyWorkbookBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class RadiografiaExportService
{
    public function __construct(
        private RadiographyWorkbookBuilder $workbookBuilder,
        private RadiographySnapshotBuilder $snapshotBuilder,
    ) {}

    /**
     * Excel y PDF de un mismo comparativo se piden en requests HTTP separados (dos
     * botones/enlaces distintos), y cada uno reconstruye el snapshot de AMBOS
     * periodos desde fact_* — coste evitable cuando el usuario pide Excel y PDF
     * seguidos del mismo comparativo. Cachea el snapshot ya calculado por un rato
     * corto, invalidado automáticamente si el summary se regenera (cambia su
     * updated_at). No cambia ningún cálculo, solo evita repetirlo.
     */
    private function buildSnapshotCached(Period $period, PeriodSummary $summary, array $config = []): array
    {
        $key = sprintf(
            'radiography_snapshot:%d:%d:%d:%s',
            $period->id,
            $summary->id,
            $summary->updated_at?->timestamp ?? 0,
            md5(json_encode($config))
        );

        return Cache::remember($key, 180, fn () => $this->snapshotBuilder->build($period, $summary, $config));
    }

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
     *
     * DEUDA ARQUITECTÓNICA CONOCIDA (evaluada y NO forzada — 2026-08-25):
     * Web (MonthlyReportController::scopedData() → buildSnapshot()) construye el
     * snapshot CON el config de scope, así que RadiographySnapshotBuilder::build()
     * corre applyEmployeeScope()/applyBranchScope() y devuelve sections.payroll_detail/
     * recovery_components/products/portfolio_buckets YA acotados y reconciliados.
     * Este método, en cambio, construye el snapshot SIN scope (build($period,$summary)
     * a secas, ver buildSnapshotCached() abajo) y RadiographyWorkbookBuilder::
     * buildEmployeeFromSnapshot()/buildBranchFromSnapshot()/resolveEmployeeRow() ubican
     * la fila del colaborador/sucursal a mano dentro de sections.employees_gestores/
     * branches y leen sus campos internos "_"-prefijados directamente.
     *
     * Por qué NO se unificó ahora: ambos caminos leen los MISMOS campos internos de la
     * MISMA fila que produce buildEmployeesGestores() (nunca dos cálculos financieros
     * independientes) — verificado con datos reales en
     * tests/Integration/WebExcelPdfDatasetConsistencyTest.php (Recuperación/Colocación/
     * Cartera coinciden exactamente entre Web y Excel). Migrar Excel/PDF a consumir
     * build($period, $summary, $config) directamente requeriría reescribir
     * buildEmployeeFromSnapshot()/buildBranchFromSnapshot()/resolveEmployeeRow() para
     * leer de $snap['sections'] en vez de $empRow/$branchRow — un cambio real pero de
     * alto riesgo de regresión en esta etapa de cierre (esas funciones ya están
     * probadas con datos reales, incluyendo las gráficas nuevas). Se prioriza no
     * romper cifras que ya cuadran: se documenta la deuda, se conserva el camino
     * actual, y el test de integración de arriba queda como red de seguridad
     * permanente — si algún cambio futuro rompe la reconciliación Web=Excel, ese test
     * lo detecta.
     */
    public function exportWithConfig(Period $period, array $config): string
    {
        @ini_set('memory_limit', '1024M');

        $summary  = $this->requireSummary($period);
        $snapshot = $this->buildSnapshotCached($period, $summary);

        $scope      = $config['scope'] ?? 'general';
        $reportType = $config['report_type'] ?? 'simple';

        if (in_array($reportType, ['month_vs_month', 'bimester_vs_bimester', 'quarter_vs_quarter'])) {
            $comparePeriodId = (int) ($config['compare_period_id'] ?? 0);
            if (!$comparePeriodId) {
                throw new RuntimeException('Se requiere compare_period_id para reportes comparativos.');
            }
            $comparePeriod = Period::findOrFail($comparePeriodId);
            $compareSummary = $this->requireSummary($comparePeriod);
            $compareSnap    = $this->buildSnapshotCached($comparePeriod, $compareSummary);

            // General: el comparativo es EL MISMO libro que el reporte simple (mismas
            // pestañas, secciones y gráficas), con columnas de comparación en cada
            // tabla — no una hoja ejecutiva aparte. Por sucursal/gestor sigue usando
            // el comparativo ejecutivo (una sola hoja) mientras se extiende con el
            // mismo criterio.
            if ($scope === 'general') {
                $spreadsheet = $this->workbookBuilder->buildFromSnapshot($period, $summary, $snapshot, $comparePeriod, $compareSnap);
            } else {
                $spreadsheet = $this->workbookBuilder->buildComparativeFromSnapshots($period, $snapshot, $comparePeriod, $compareSnap, $config);
            }
            $suffix = 'comparativo_' . $comparePeriod->code . '_vs_' . ($period->code ?: $period->id);
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
        $snapshot = $this->buildSnapshotCached($period, $summary);

        $scope      = $config['scope'] ?? 'general';
        $reportType = $config['report_type'] ?? 'simple';

        if (in_array($reportType, ['month_vs_month', 'bimester_vs_bimester', 'quarter_vs_quarter'], true)) {
            $viewData      = $this->comparativeViewData($period, $config, $summary, $snapshot);
            $comparePeriod = $viewData['comparePeriod'];

            $pdf = Pdf::loadView('reports.radiography-pdf-comparative', $viewData)
                ->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);
            $suffix = 'comparativo_' . $comparePeriod->code;
        } elseif ($scope === 'branch') {
            $branchId = (int) ($config['branch_id'] ?? 0);
            if (!$branchId) {
                throw new RuntimeException('Se requiere branch_id para reportes por sucursal.');
            }
            $branchRow  = $this->resolveBranchRow($snapshot, $branchId);
            $branchData = $this->resolveBranchPdfData($period, $snapshot, $branchId, $branchRow);

            $pdf = Pdf::loadView('reports.radiography-pdf-branch', array_merge($branchData, [
                'period'     => $period,
                'snap'       => $snapshot,
                'branchRow'  => $branchRow,
            ]))->setPaper('letter', 'portrait')->setOption('isPhpEnabled', true);
            $suffix = 'sucursal_' . $branchId;
        } elseif ($scope === 'employee') {
            $employeeId = (int) ($config['employee_id'] ?? 0);
            if (!$employeeId) {
                throw new RuntimeException('Se requiere employee_id para reportes por gestor.');
            }
            $extraAmount = (float) ($config['extra_employee_expense_amount'] ?? 0);
            $extraNotes  = (string) ($config['extra_employee_expense_notes'] ?? '');
            $empData = $this->resolveEmployeeRow($period, $snapshot, $employeeId, $extraAmount);

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
     * Datos de un comparativo (mes/bimestre/trimestre vs mes/bimestre/trimestre),
     * separados de su renderizado — reutilizados tanto por exportPdfWithConfig()
     * (PDF vía dompdf) como por el "Ver" web de un run comparativo (misma plantilla,
     * renderizada como página normal), así ambos nunca se desincronizan.
     */
    public function comparativeViewData(Period $period, array $config, ?PeriodSummary $summary = null, ?array $snapshot = null): array
    {
        $summary  ??= $this->requireSummary($period);
        $snapshot ??= $this->buildSnapshotCached($period, $summary);

        $reportType = $config['report_type'] ?? 'month_vs_month';
        $comparePeriodId = (int) ($config['compare_period_id'] ?? 0);
        if (!$comparePeriodId) {
            throw new RuntimeException('Se requiere compare_period_id para reportes comparativos.');
        }

        $comparePeriod  = Period::findOrFail($comparePeriodId);
        $compareSummary = $this->requireSummary($comparePeriod);
        $compareSnap    = $this->buildSnapshotCached($comparePeriod, $compareSummary);

        [$rows, $scopeLabel] = $this->buildComparativeRows($snapshot, $compareSnap, $config);

        return [
            'period'           => $period,
            'comparePeriod'    => $comparePeriod,
            'rows'             => $rows,
            'scopeLabel'       => $scopeLabel,
            'reportType'       => $reportType,
            'currentComposite' => $snapshot['period']['composite'] ?? null,
            'compareComposite' => $compareSnap['period']['composite'] ?? null,
        ];
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
     * Enriquece $branchRow (branch_radiography.branches, ya resuelto por resolveBranchRow())
     * con los mismos desgloses/gráficas que resolveEmployeeRow() ya construye para gestor —
     * mismo dataset canónico, cero cálculos financieros nuevos (solo reshaping de
     * presentación + reutilización de métodos canónicos ya existentes). Notas por sección:
     *  - EBITDA (ingreso base/gastos totales/nómina/margen/categoría): reutiliza
     *    BranchRadiographyCalculator::ingresoEbitdaBaseFor()/gastosTotalesFor()/
     *    nominaTotalFor()/ebitdaFinalFor()/margenEbitdaFor() — las MISMAS fuentes que ya usa
     *    RadiographyStyleHelper::branchEbitdaEstimate() (blade anterior).
     *  - Recuperación por producto: NO se agrega aquí — Web mismo expone esto como "no
     *    disponible por sucursal todavía" bajo alcance sucursal (ver
     *    RadiographySnapshotBuilder::applyBranchScope(), comentario junto a
     *    'recovery_by_product') — el PDF respeta el mismo límite, nunca inventa un desglose
     *    que Web no tiene.
     *  - Colocación por producto: SÍ existe por sucursal (sections.placement_by_branch_product,
     *    el mismo campo que ya usa Web bajo applyBranchScope()) — se reutiliza tal cual,
     *    filtrado por nombre de sucursal.
     *  - Nómina y Capital Humano detallada: usa el MISMO universo de empleados que
     *    computeNoiPercepcionesDeduccionesForBranch() (employee_branch_assignments del
     *    periodo), para que el desglose por categoría siempre sume el mismo total que el KPI
     *    agregado.
     *  - Mora por bucket: mismos campos "mora_X_Y"/"mora_X_Y_cnt" que ya usa
     *    RadiographySnapshotBuilder::buildPortfolioBuckets() (privado, no accesible desde
     *    aquí) — mismo criterio, sin duplicar cálculo financiero, solo reshaping.
     *  - Efectividad de cobranza: reutiliza buildEfectividadCobranza($period, null,
     *    $branchId), la MISMA llamada que ya hace applyBranchScope() para Web.
     */
    private function resolveBranchPdfData(Period $period, array $snapshot, int $branchId, array $branchRow): array
    {
        $branchName = (string) ($branchRow['sucursal'] ?? '');

        $rec     = (float) ($branchRow['recuperacion_total'] ?? 0);
        $coloc   = (float) ($branchRow['colocacion'] ?? 0);
        $cartera = (float) ($branchRow['valor_cartera'] ?? 0);
        $gastos  = (float) ($branchRow['gastos_operativos'] ?? 0);

        $bucketFields = [
            'mora_1_30'     => 'mora_0_30',
            'mora_31_60'    => 'mora_31_60',
            'mora_61_90'    => 'mora_61_90',
            'mora_91_120'   => 'mora_91_120',
            'mora_120_plus' => 'mora_120_plus',
        ];
        $moraBuckets  = [];
        $vencida      = 0.0;
        $cntMoraTotal = 0;
        foreach ($bucketFields as $bucketKey => $field) {
            $monto = (float) ($branchRow[$field] ?? 0);
            $cnt   = (int) ($branchRow["{$field}_cnt"] ?? 0);
            $vencida      += $monto;
            $cntMoraTotal += $cnt;
            $moraBuckets[$bucketKey] = ['contratos' => $cnt, 'monto' => $monto];
        }
        $cntAlCorriente = max(0, (int) ($branchRow['contratos'] ?? 0) - $cntMoraTotal);
        $moraBuckets = ['al_corriente' => ['contratos' => $cntAlCorriente, 'monto' => max(0, $cartera - $vencida)]] + $moraBuckets;
        $mora = $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0.0;

        $recoveryComponents = [
            'capital_recuperado'      => (float) ($branchRow['capital_recuperado']      ?? 0),
            'interes_recuperado'      => (float) ($branchRow['interes_recuperado']      ?? 0),
            'impuesto_recuperado'     => (float) ($branchRow['impuesto_recuperado']     ?? 0),
            'charges'                 => (float) ($branchRow['charges']                 ?? 0),
            'cargos_adicionales'      => (float) ($branchRow['cargos_adicionales']      ?? 0),
            'excedente_recuperado'    => (float) ($branchRow['excedente_recuperado']    ?? 0),
            'comision_apertura'       => (float) ($branchRow['comision_apertura']       ?? 0),
            'cargos_inicio'           => (float) ($branchRow['cargos_inicio']           ?? 0),
            'seguro_crece_reconocido' => (float) ($branchRow['seguro_crece_reconocido'] ?? 0),
            'otros_recuperacion'      => (float) ($branchRow['otros_recuperacion']      ?? 0),
        ];

        $placementsByProduct = collect($snapshot['sections']['placement_by_branch_product'] ?? [])
            ->filter(fn ($r) => strtoupper(trim($r['branch'] ?? '')) === strtoupper(trim($branchName)))
            ->map(fn ($r) => ['producto' => $r['product'] ?? '—', 'operaciones' => (int) ($r['creditos'] ?? 0), 'colocacion' => (float) ($r['monto'] ?? 0)])
            ->values()->all();

        // Detalle de nómina NOI por concepto — reutiliza sections.payroll_by_branch_concept,
        // la MISMA resolución empleado→sucursal (EBA + activity-lookup con fallback) que ya
        // usa Web bajo applyBranchScope() (ver RadiographySnapshotBuilder::
        // buildPayrollByBranchConceptResolved()). Un query directo a
        // employee_branch_assignments (más simple) NO sirve aquí: muchos colaboradores no
        // tienen asignación manual y solo se resuelven por actividad, así que habría dado
        // falsos $0.00 para sucursales con nómina real. Es un SUBCONJUNTO informativo de
        // "Nómina y Capital Humano" (nominaTotalFor() también incluye IMSS patronal y gastos
        // de empleados vía nómina, que no vienen de fact_noi_movements) — nunca se presenta
        // como si sumara contra ese KPI.
        $branchCalc     = app(BranchRadiographyCalculator::class);
        $payrollByBranch = collect($snapshot['sections']['payroll_by_branch_concept'] ?? [])
            ->first(fn ($_, $k) => strtoupper(trim($k)) === strtoupper(trim($branchName))) ?? [];

        $percepciones = [];
        $deducciones  = [];
        foreach ($payrollByBranch as $concept => $amount) {
            $amount = (float) $amount;
            if ($amount == 0.0) {
                continue;
            }
            if (str_starts_with((string) $concept, 'D')) {
                $deducciones[] = ['concepto' => (string) $concept, 'monto' => round($amount, 2)];
            } else {
                $percepciones[] = ['concepto' => (string) $concept, 'monto' => round($amount, 2)];
            }
        }
        $payrollDetail = [
            'percepciones'       => $percepciones,
            'deducciones'        => $deducciones,
            'percepciones_total' => round((float) array_sum(array_column($percepciones, 'monto')), 2),
            'deducciones_total'  => round((float) array_sum(array_column($deducciones, 'monto')), 2),
        ];

        $nomina        = BranchRadiographyCalculator::nominaTotalFor($branchRow);
        $ingresoBase   = BranchRadiographyCalculator::ingresoEbitdaBaseFor($branchRow);
        $gastosTotales = BranchRadiographyCalculator::gastosTotalesFor($branchRow);
        $ebitda        = BranchRadiographyCalculator::ebitdaFinalFor($branchRow);
        $margen        = BranchRadiographyCalculator::margenEbitdaFor($branchRow);
        $categoria     = \App\Services\Radiography\RadiographyStyleHelper::ebitdaCategory($ebitda);
        $catColors     = \App\Services\Radiography\RadiographyStyleHelper::categoryColors($categoria);

        $gastosDetalle = (array) ($branchRow['gastos_detalle'] ?? []);
        arsort($gastosDetalle);

        $fondeo      = (float) ($branchRow['prestamos_fondea'] ?? 0);
        $excedente   = (float) ($branchRow['excedentes']       ?? 0);

        // warmDataIds() ANTES de buildEfectividadCobranza(): $this->snapshotBuilder es el
        // mismo objeto usado arriba para $snapshot, pero buildSnapshot()/buildSnapshotCached()
        // puede haber servido esa lectura desde caché (sin invocar build() de nuevo en ESTA
        // instancia) — dejaría $this->dataIds vacío y buildEfectividadCobranza() devolvería
        // ceros silenciosos, no un error. El PDF/Excel de gestor no sufre esto porque
        // findEmployeeGestorRowByEmployeeId() recalienta ese estado incondicionalmente en
        // cada llamada (ver su implementación) — sucursal no tiene un equivalente, así que se
        // recalienta explícitamente aquí.
        $this->snapshotBuilder->warmDataIds($period);
        $efectividad = $this->snapshotBuilder->buildEfectividadCobranza($period, null, $branchId);

        $charts = app(\App\Services\Radiography\RadiographyChartSvgBuilder::class);

        $chartRecuperacionVsColocacion = $charts->horizontalBarChart([
            ['label' => 'Recuperación', 'value' => $rec],
            ['label' => 'Colocación', 'value' => $coloc],
        ], 'Recuperación vs Colocación');

        $chartCarteraSanaVsVencida = $charts->stackedCompositionBar([
            ['label' => 'Al corriente', 'value' => max(0, $cartera - $vencida)],
            ['label' => 'Vencida', 'value' => $vencida],
        ], 'Cartera sana vs vencida');

        $moraBucketLabels = [
            'al_corriente' => 'Al corriente', 'mora_1_30' => 'Mora 1-30', 'mora_31_60' => 'Mora 31-60',
            'mora_61_90'   => 'Mora 61-90',   'mora_91_120' => 'Mora 91-120', 'mora_120_plus' => 'Mora 120+',
        ];
        $chartMoraPorBucket = $charts->horizontalBarChart(
            array_map(fn ($k, $b) => ['label' => $moraBucketLabels[$k] ?? $k, 'value' => (float) $b['monto']], array_keys($moraBuckets), $moraBuckets),
            'Mora por bucket',
        );

        $chartEbitda = $charts->horizontalBarChart([
            ['label' => 'Ingreso base EBITDA', 'value' => $ingresoBase],
            ['label' => 'Gastos totales', 'value' => $gastosTotales],
            ['label' => 'EBITDA', 'value' => $ebitda],
        ], 'Ingreso EBITDA vs Gastos vs EBITDA');

        $chartNominaComposicion = $charts->stackedCompositionBar(
            array_map(fn ($p) => ['label' => $p['concepto'], 'value' => (float) $p['monto']], $payrollDetail['percepciones'] ?? []),
            'Composición de nómina (percepciones)',
        );

        $chartColocacionPorProducto = $charts->horizontalBarChart(
            array_map(fn ($p) => ['label' => $p['producto'], 'value' => (float) $p['colocacion']], $placementsByProduct),
            'Colocación por producto',
        );

        $chartEfectividad = $charts->horizontalBarChart([
            ['label' => 'Vigente', 'value' => (float) ($efectividad['vigente']['total'] ?? 0)],
            ['label' => 'Atrasado', 'value' => (float) ($efectividad['atrasado']['total'] ?? 0)],
            ['label' => 'Vencido', 'value' => (float) ($efectividad['vencido']['total'] ?? 0)],
        ], 'Efectividad de cobranza');

        return [
            'rec' => $rec, 'coloc' => $coloc, 'cartera' => $cartera, 'vencida' => $vencida, 'mora' => $mora,
            'ops' => array_sum(array_column($placementsByProduct, 'operaciones')),
            'gastos' => $gastos, 'nomina' => $nomina, 'ingresoBase' => $ingresoBase, 'gastosTotales' => $gastosTotales,
            'ebitda' => $ebitda, 'margen' => $margen, 'ebitdaCategoria' => $categoria, 'ebitdaCategoriaColors' => $catColors,
            'moraBuckets' => $moraBuckets, 'recoveryComponents' => $recoveryComponents,
            'placementsByProduct' => $placementsByProduct, 'payrollDetail' => $payrollDetail,
            'gastosDetalle' => $gastosDetalle, 'fondeo' => $fondeo, 'excedente' => $excedente,
            'efectividad' => $efectividad,
            'chartRecuperacionVsColocacion' => $chartRecuperacionVsColocacion,
            'chartCarteraSanaVsVencida'     => $chartCarteraSanaVsVencida,
            'chartMoraPorBucket'            => $chartMoraPorBucket,
            'chartEbitda'                   => $chartEbitda,
            'chartNominaComposicion'        => $chartNominaComposicion,
            'chartColocacionPorProducto'    => $chartColocacionPorProducto,
            'chartEfectividad'              => $chartEfectividad,
        ];
    }

    /**
     * Resolve a single employee's gestor metrics. Identidad PRIMERO por employee_id, vía
     * RadiographySnapshotBuilder::findEmployeeGestorRowByEmployeeId() — la MISMA vinculación
     * robusta que usa Web (applyEmployeeScope()), en vez de comparar texto contra el nombre
     * de display de la fila ya expuesta (frágil: causa raíz real de "funciona un mes, falla
     * otro" — ver auditoría 2026-08-24). El nombre solo se usa como fallback explícito.
     */
    private function resolveEmployeeRow(Period $period, array $snapshot, int $employeeId, float $extraExpenseAmount = 0.0): array
    {
        $employee = Employee::find($employeeId);
        if (!$employee) {
            throw new RuntimeException("Empleado ID {$employeeId} no encontrado.");
        }

        $canonicalizer = app(EmployeeNameCanonicalizer::class);
        $target        = $canonicalizer->normalize($employee->full_name ?? '');

        $empRow = $this->snapshotBuilder->findEmployeeGestorRowByEmployeeId($period, $employeeId);

        $empGest = $snapshot['sections']['employees_gestores'] ?? [];
        if (!$empRow) {
            foreach ($empGest as $e) {
                if ($canonicalizer->normalize($e['name'] ?? '') === $target) {
                    $empRow = $e;
                    break;
                }
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

        $pagos       = (float)($empRow['pagos']       ?? 0);
        $bonos       = (float)($empRow['bonos']       ?? 0);
        $desctos     = (float)($empRow['descuentos']  ?? 0);
        $gastos      = (float)($empRow['gastos']      ?? 0) + $extraExpenseAmount;
        $neto        = (float)($empRow['neto']        ?? ($pagos + $bonos - $desctos));
        $coloc       = (float)($empRow['colocacion']  ?? 0);
        $rec         = (float)($empRow['recuperacion'] ?? 0);
        $cartera     = (float)($empRow['cartera']     ?? 0);
        $vencida     = (float)($empRow['vencida']     ?? 0);
        $mora        = $cartera > 0 ? round($vencida / $cartera * 100, 2) : (float)($empRow['mora'] ?? 0);
        $ingresoBase = (float)($empRow['ingreso_ebitda_base'] ?? 0);

        // ── Desgloses canónicos (2026-08-25) — MISMOS campos internos que ya usa Web
        // (RadiographySnapshotBuilder::applyEmployeeScope()) y el Excel de gestor
        // (RadiographyWorkbookBuilder::buildEmployeeFromSnapshot()) — nunca recalculados
        // aparte, para que Web = Excel = PDF muestren siempre la misma historia. Ver
        // buildEmployeesGestores() por el origen de estos campos "_"-internos.
        $employeeIdsForNoi = !empty($empRow['_employee_ids']) ? $empRow['_employee_ids'] : [$employeeId];
        $percepDeducc      = app(BranchRadiographyCalculator::class)
            ->computeNoiPercepcionesDeduccionesForEmployees($this->snapshotBuilder->resolveDataIdsPublic($period), $employeeIdsForNoi);
        $payrollDetail     = $this->snapshotBuilder->buildEmployeePayrollDetail($employeeIdsForNoi, $percepDeducc);

        $recoveryComponents = $empRow['_recovery_components'] ?? null;
        $recoveryByProduct  = $empRow['_recovery_by_product'] ?? [];
        $placementsByProduct = $empRow['_placements_by_product'] ?? [];
        $moraBuckets        = $empRow['_mora_buckets'] ?? [];
        $efectividad        = $this->snapshotBuilder->buildEfectividadCobranza($period, $empRow['name'] ?? null);

        // ── Gráficas del PDF — SVG server-side sobre el MISMO dataset canónico de
        // arriba (nunca recalculado aparte). Cada builder devuelve '' si no hay datos
        // reales que graficar — el blade omite la sección completa en ese caso, nunca
        // un recuadro vacío. Ver RadiographyChartSvgBuilder por qué SVG (dompdf no
        // ejecuta JS/canvas, así que una librería de charts basada en JS no aplica).
        $charts = app(\App\Services\Radiography\RadiographyChartSvgBuilder::class);

        $chartRecuperacionVsColocacion = $charts->horizontalBarChart([
            ['label' => 'Recuperación', 'value' => $rec],
            ['label' => 'Colocación', 'value' => $coloc],
        ], 'Recuperación vs Colocación');

        $chartCarteraSanaVsVencida = $charts->stackedCompositionBar([
            ['label' => 'Al corriente', 'value' => max(0, $cartera - $vencida)],
            ['label' => 'Vencida', 'value' => $vencida],
        ], 'Cartera sana vs vencida');

        // Mismas etiquetas que RadiographySnapshotBuilder::moraBucketDefs() y el blade de
        // gestor — solo texto de presentación, no recalcula montos.
        $moraBucketLabels = [
            'al_corriente'  => 'Al corriente',
            'mora_1_30'     => 'Mora 1-30',
            'mora_31_60'    => 'Mora 31-60',
            'mora_61_90'    => 'Mora 61-90',
            'mora_91_120'   => 'Mora 91-120',
            'mora_120_plus' => 'Mora 120+',
        ];
        $chartMoraPorBucket = $charts->horizontalBarChart(
            array_map(fn ($k, $b) => ['label' => $b['label'] ?? ($moraBucketLabels[$k] ?? $k), 'value' => (float) ($b['monto'] ?? 0)], array_keys($moraBuckets), $moraBuckets),
            'Mora por bucket',
        );

        $chartEbitda = $charts->horizontalBarChart([
            ['label' => 'Ingreso base EBITDA', 'value' => $ingresoBase],
            ['label' => 'Gastos + Nómina neta', 'value' => $gastos + $pagos + $bonos - $desctos],
            ['label' => 'EBITDA', 'value' => $ingresoBase - ($gastos + $pagos + $bonos - $desctos)],
        ], 'Ingreso EBITDA vs Gastos vs EBITDA');

        $chartNominaComposicion = $charts->stackedCompositionBar(
            array_map(fn ($p) => ['label' => $p['concepto'], 'value' => (float) $p['monto']], $payrollDetail['percepciones'] ?? []),
            'Composición de nómina (percepciones)',
        );

        $chartColocacionPorProducto = $charts->horizontalBarChart(
            array_map(fn ($p) => ['label' => $p['producto'] ?? '—', 'value' => (float) ($p['colocacion'] ?? 0)], $placementsByProduct),
            'Colocación por producto',
        );

        $chartRecuperacionPorProducto = $charts->horizontalBarChart(
            array_map(fn ($p) => ['label' => $p['producto'] ?? '—', 'value' => (float) ($p['recuperacion'] ?? 0)], $recoveryByProduct),
            'Recuperación por producto',
        );

        $chartEfectividad = $charts->horizontalBarChart([
            ['label' => 'Vigente', 'value' => (float) ($efectividad['vigente']['total'] ?? 0)],
            ['label' => 'Atrasado', 'value' => (float) ($efectividad['atrasado']['total'] ?? 0)],
            ['label' => 'Vencido', 'value' => (float) ($efectividad['vencido']['total'] ?? 0)],
        ], 'Efectividad de cobranza');

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
            'ingresoBase' => $ingresoBase,
            'payrollDetail'       => $payrollDetail,
            'recoveryComponents'  => $recoveryComponents,
            'recoveryByProduct'   => $recoveryByProduct,
            'placementsByProduct' => $placementsByProduct,
            'moraBuckets'         => $moraBuckets,
            'efectividad'         => $efectividad,
            'chartRecuperacionVsColocacion' => $chartRecuperacionVsColocacion,
            'chartCarteraSanaVsVencida'     => $chartCarteraSanaVsVencida,
            'chartMoraPorBucket'            => $chartMoraPorBucket,
            'chartEbitda'                   => $chartEbitda,
            'chartNominaComposicion'        => $chartNominaComposicion,
            'chartColocacionPorProducto'    => $chartColocacionPorProducto,
            'chartRecuperacionPorProducto'  => $chartRecuperacionPorProducto,
            'chartEfectividad'              => $chartEfectividad,
            // EBITDA = Ingreso base EBITDA (mismos componentes que BranchRadiographyCalculator::
            // ingresoEbitdaBaseFor(), agregados por gestor) − (Gastos + NóminaNeta). NUNCA
            // Recuperación − Colocación (esa fórmula quedó obsoleta, ver criterio final 2026-07).
            'utilidad'  => $utilidadFinal = $ingresoBase - ($gastos + $pagos + $bonos - $desctos),
            'margen'    => $ingresoBase > 0 ? round($utilidadFinal / $ingresoBase * 100, 2) : 0.0,
            'ebitdaCategoria' => $ebitdaCategoriaFinal = \App\Services\Radiography\RadiographyStyleHelper::ebitdaCategory($utilidadFinal),
            // Colores ARGB (formato Excel, "FFrrggbb") de la MISMA fuente que usa el
            // badge de categoría en Excel — se convierten a "#rrggbb" en el blade.
            'ebitdaCategoriaColors' => \App\Services\Radiography\RadiographyStyleHelper::categoryColors($ebitdaCategoriaFinal),
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

        $rows = [];

        if ($scope === 'general' || $scope === 'branch') {
            // Misma fuente que RadiographyWorkbookBuilder::buildComparativeFromSnapshots()
            // sección B — GLOBAL o el registro de esa sucursal en branch_radiography.branches.
            $rowSource = function (array $snap) use ($scope, $branchName): array {
                if ($scope === 'branch' && $branchName) {
                    $brUp = strtoupper(trim($branchName));
                    return collect($snap['branch_radiography']['branches'] ?? [])
                        ->first(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')) === $brUp) ?? [];
                }
                return $snap['branch_radiography']['global'] ?? [];
            };
            $cmpRow = $rowSource($compareSnap);
            $curRow = $rowSource($currentSnap);

            $moraTotalFor = fn (array $row) => (float)($row['mora_0_30'] ?? 0) + (float)($row['mora_31_60'] ?? 0)
                + (float)($row['mora_61_90'] ?? 0) + (float)($row['mora_91_120'] ?? 0) + (float)($row['mora_120_plus'] ?? 0);
            $moraPctFor = fn (array $row) => (float)($row['valor_cartera'] ?? 0) > 0
                ? round($moraTotalFor($row) / (float)$row['valor_cartera'] * 100, 2) : 0.0;

            $ingBasePrev = BranchRadiographyCalculator::ingresoEbitdaBaseFor($cmpRow);
            $ingBaseCurr = BranchRadiographyCalculator::ingresoEbitdaBaseFor($curRow);
            $gastosTotPrev = BranchRadiographyCalculator::gastosTotalesFor($cmpRow);
            $gastosTotCurr = BranchRadiographyCalculator::gastosTotalesFor($curRow);

            $metricValues = [
                ['Recuperación',                (float)($cmpRow['recuperacion_total'] ?? 0), (float)($curRow['recuperacion_total'] ?? 0), 'currency'],
                ['Ingreso base EBITDA',          $ingBasePrev, $ingBaseCurr, 'currency'],
                ['Colocación',                   (float)($cmpRow['colocacion'] ?? 0), (float)($curRow['colocacion'] ?? 0), 'currency'],
                ['Valor cartera',                (float)($cmpRow['valor_cartera'] ?? 0), (float)($curRow['valor_cartera'] ?? 0), 'currency'],
                ['Cartera vencida',              $moraTotalFor($cmpRow), $moraTotalFor($curRow), 'currency'],
                ['Mora %',                       $moraPctFor($cmpRow), $moraPctFor($curRow), 'percent'],
                ['OPEX',                         (float)($cmpRow['gastos_operativos'] ?? 0), (float)($curRow['gastos_operativos'] ?? 0), 'currency'],
                ['Nómina y Capital Humano',      BranchRadiographyCalculator::nominaTotalFor($cmpRow), BranchRadiographyCalculator::nominaTotalFor($curRow), 'currency'],
                ['Gastos Totales',               $gastosTotPrev, $gastosTotCurr, 'currency'],
                ['EBITDA',                       $ingBasePrev - $gastosTotPrev, $ingBaseCurr - $gastosTotCurr, 'currency'],
                ['Margen EBITDA',                BranchRadiographyCalculator::margenEbitdaFor($cmpRow), BranchRadiographyCalculator::margenEbitdaFor($curRow), 'percent'],
                ['Préstamos activos (contratos)', (float)($cmpRow['contratos'] ?? 0), (float)($curRow['contratos'] ?? 0), 'integer'],
                ['IMSS',                         (float)($cmpRow['imss_patronal'] ?? 0), (float)($curRow['imss_patronal'] ?? 0), 'currency'],
            ];
            if ($scope === 'general') {
                $rotCmp = $compareSnap['sections']['rotation'] ?? [];
                $rotCur = $currentSnap['sections']['rotation'] ?? [];
                $metricValues[] = ['Percepciones',              (float)($compareSnap['summary']['noi_percepciones'] ?? 0), (float)($currentSnap['summary']['noi_percepciones'] ?? 0), 'currency'];
                $metricValues[] = ['Deducciones (informativo)', (float)($compareSnap['summary']['noi_deducciones']  ?? 0), (float)($currentSnap['summary']['noi_deducciones']  ?? 0), 'currency'];
                $metricValues[] = ['Neto pagado a trabajadores', (float)($compareSnap['summary']['noi_neto_pagado'] ?? 0), (float)($currentSnap['summary']['noi_neto_pagado'] ?? 0), 'currency'];
                $metricValues[] = ['Plantilla',                 (float)($rotCmp['current_count'] ?? $rotCmp['promedio'] ?? 0), (float)($rotCur['current_count'] ?? $rotCur['promedio'] ?? 0), 'integer'];
                $metricValues[] = ['Altas del periodo',          (float)($rotCmp['altas'] ?? 0), (float)($rotCur['altas'] ?? 0), 'integer'];
                $metricValues[] = ['Bajas del periodo',          (float)($rotCmp['bajas'] ?? 0), (float)($rotCur['bajas'] ?? 0), 'integer'];
                $metricValues[] = ['Rotación %',                 (float)($rotCmp['indice'] ?? 0), (float)($rotCur['indice'] ?? 0), 'percent'];
            }

            foreach ($metricValues as [$label, $prev, $curr, $fmt]) {
                $diff = $curr - $prev;
                $varPct = $prev != 0 ? round($diff / abs($prev) * 100, 2) : ($curr != 0 ? 100.0 : 0.0);
                $rows[] = ['label' => $label, 'prev' => $prev, 'curr' => $curr, 'diff' => $diff, 'var_pct' => $varPct, 'fmt' => $fmt];
            }

            return [$rows, $scopeLabel];
        }

        // Scope employee: sin desglose de recuperación por gestor, se mantiene simple.
        $metrics = [
            ['Recuperación',       'recuperacion', 'currency'],
            ['Colocación',         'colocacion',   'currency'],
            ['Valor cartera',      'cartera',      'currency'],
            ['Cartera vencida',    'vencida',      'currency'],
            ['Gastos',             'gastos',       'currency'],
        ];
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
     * Build the snapshot for use in the web preview page — general o proyectado a un
     * scope (sucursal/colaborador) según $config['scope']/'branch_id'/'employee_id'.
     * Pasa por el mismo caché de 180s que Excel/PDF (buildSnapshotCached ya incluye el
     * config completo en su clave), así que cambiar de filtro repetidamente durante la
     * misma sesión no recalcula desde cero cada vez.
     */
    public function buildSnapshot(Period $period, array $config = []): array
    {
        $summary = $this->requireSummary($period);
        $summary->loadMissing(['branchSummaries', 'incidents']);
        return $this->buildSnapshotCached($period, $summary, $config);
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
