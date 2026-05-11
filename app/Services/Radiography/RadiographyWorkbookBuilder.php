<?php

namespace App\Services\Radiography;

use App\Models\Period;
use App\Models\PeriodSummary;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds Excel workbook from scratch using a pre-built snapshot.
 * NO template loading — creates new Spreadsheet() every time.
 */
class RadiographyWorkbookBuilder
{
    // Color palette
    private const BG_DARK   = 'FF0F172A';
    private const BG_BLUE   = 'FF1D4ED8';
    private const BG_HDR    = 'FFDBEAFE';
    private const FG_HDR    = 'FF1E3A8A';
    private const BG_META   = 'FFF1F5F9';
    private const FG_META   = 'FF475569';
    private const BG_ALT    = 'FFF8FAFC';
    private const BG_TOTAL  = 'FF334155';
    private const FG_WHITE  = 'FFFFFFFF';
    private const FG_RED    = 'FFB91C1C';
    private const BG_EVEN    = 'FFFFFFFF';
    private const BORDER_LT  = 'FFE2E8F0';
    private const CURRENCY   = '"$"#,##0.00';
    private const PERCENT    = '0.00"%"';
    private const INTEGER    = '#,##0';
    // Green palette (RECUP., MORA, P. INTERSUC.)
    private const BG_GREEN_HDR = 'FF065F46';
    private const BG_GREEN_ROW = 'FFF0FDF4';
    private const BG_GREEN_TOT = 'FF047857';
    private const DATE_FMT     = 'DD/MM/YYYY';

    public function buildFromSnapshot(Period $period, PeriodSummary $summary, array $snap): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Radiografía ' . $period->label)
            ->setCreator('Sistema de Reportes')
            ->setSubject('Radiografía Financiera')
            ->setDescription('Generado automáticamente — sin plantilla');

        $this->buildGlobalSheet($spreadsheet, $period, $snap);
        $this->buildProductosSheet($spreadsheet, $period, $snap);
        $this->buildSucursalesSheet($spreadsheet, $period, $snap);
        $this->buildInterbranchLoansSheet($spreadsheet, $period, $snap);
        $this->buildNominasSheet($spreadsheet, $period, $snap);
        $this->buildGastosMatrixSheet($spreadsheet, $period, $snap);
        $this->buildPortfolioValueSheet($spreadsheet, $period, $snap);
        $this->buildRecuperacionSheet($spreadsheet, $period, $snap);
        $this->buildMoraSheet($spreadsheet, $period, $snap);
        $this->buildCorporateFundingSheet($spreadsheet, $period, $snap);
        $this->buildPlacementSheet($spreadsheet, $period, $snap);
        $this->buildCategoriesSheet($spreadsheet, $period, $snap);
        $this->buildEmpleadosSheet($spreadsheet, $period, $snap);
        $this->buildIncidenciasSheet($spreadsheet, $period, $snap);
        $this->buildCarteraSheet($spreadsheet, $period, $snap);
        $this->buildMetadataSheet($spreadsheet, $period, $snap);

        // Remove default empty sheet if it exists
        if ($spreadsheet->getSheetCount() > 1) {
            try {
                $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName('Worksheet'));
                if ($idx !== null) $spreadsheet->removeSheetByIndex($idx);
            } catch (\Throwable) {}
        }

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @deprecated use buildFromSnapshot */
    public function build(Period $period, PeriodSummary $summary): Spreadsheet
    {
        $snap = app(RadiographySnapshotBuilder::class)->build($period, $summary);
        return $this->buildFromSnapshot($period, $summary, $snap);
    }

    // ── HOJA 1: GLOBAL ───────────────────────────────────────────────────────

    private function buildGlobalSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->getActiveSheet()->setTitle('GLOBAL');
        $sum   = $snap['summary'];
        $pay   = $snap['sections']['payroll'];

        $this->sheetTitle($sheet, 'A1:F1', 'RADIOGRAFÍA GLOBAL — ' . strtoupper($period->label));
        $sheet->mergeCells('A2:F2');
        $sheet->setCellValue('A2', 'Sistema de Reportes  |  Periodo: ' . ($period->code ?: $period->id) . '  |  Generado: ' . $snap['generated_at']);
        $this->metaStyle($sheet, 'A2:F2');

        // ── Financial metrics section
        $this->sectionHeader($sheet, 'A4:F4', 'MÉTRICAS FINANCIERAS');
        $this->colHeaders($sheet, 5, ['A' => 'CONCEPTO', 'B' => 'VALOR']);

        $finRows = [
            ['Recuperación total',  $sum['recovery_total'],    'currency'],
            ['Colocación total',    $sum['placement_total'],   'currency'],
            ['Valor cartera total', $sum['portfolio_total'],   'currency'],
            ['Cartera vencida',     $sum['overdue_portfolio'],  'currency'],
            ['Índice de mora',      $sum['mora_index'],        'percent'],
            ['Gastos totales',      $sum['expenses_total'],    'currency'],
        ];

        $r = 6;
        foreach ($finRows as $i => [$label, $value, $fmt]) {
            $sheet->mergeCells("B{$r}:F{$r}");
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $this->dataRow($sheet, "A{$r}:F{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $value);
            $r++;
        }

        // ── Payroll section
        $r++;
        $this->sectionHeader($sheet, "A{$r}:F{$r}", 'NÓMINA / EMPLEADOS');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'CONCEPTO', 'B' => 'VALOR']);
        $r++;

        $payRows = [
            ['Total empleados',   $pay['total_empleados'], 'integer'],
            ['Total pagos',       $pay['pagos'],           'currency'],
            ['Total bonos',       $pay['bonos'],           'currency'],
            ['Total descuentos',  $pay['descuentos'],      'currency'],
            ['Total gastos',      $pay['gastos'],          'currency'],
            ['Neto acumulado',    $pay['neto'],            'currency'],
        ];

        foreach ($payRows as $i => [$label, $value, $fmt]) {
            $sheet->mergeCells("B{$r}:F{$r}");
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $this->dataRow($sheet, "A{$r}:F{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $value);
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(22);
    }

    // ── HOJA 2: PRODUCTOS ────────────────────────────────────────────────────

    private function buildProductosSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet    = $ss->createSheet()->setTitle('PRODUCTOS');
        $products = $snap['sections']['products'] ?? [];

        $this->sheetTitle($sheet, 'A1:F1', 'PRODUCTOS POR TIPO — ' . strtoupper($period->label));

        if (empty($products)) {
            $sheet->setCellValue('A2', 'Sin datos de productos para este periodo.');
            return;
        }

        $headers = ['A' => 'PRODUCTO', 'B' => 'TIPO', 'C' => 'OPERACIONES', 'D' => 'COLOCACIÓN', 'E' => 'CARTERA', 'F' => 'CONTRATOS'];
        $this->colHeaders($sheet, 2, $headers);

        $grupos = [
            'operativo'    => 'PRODUCTOS OPERATIVOS',
            'otro_cartera' => 'OTROS PRODUCTOS DE CARTERA',
            'reestructura' => 'REESTRUCTURAS / MIGRACIONES',
        ];

        $r = 3;
        foreach ($grupos as $tipo => $titulo) {
            $grupo = array_values(array_filter($products, fn ($p) => ($p['tipo'] ?? 'operativo') === $tipo));
            if (empty($grupo)) {
                continue;
            }

            // Section separator row
            $sheet->setCellValue("A{$r}", $titulo);
            $sheet->mergeCells("A{$r}:F{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle("A{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF1E293B');
            $sheet->getStyle("A{$r}")->getFont()->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $r++;

            foreach ($grupo as $i => $p) {
                $sheet->setCellValue("A{$r}", $p['producto']);
                $sheet->setCellValue("B{$r}", match ($tipo) { 'otro_cartera' => 'Otro cartera', 'reestructura' => 'Reestructura', default => 'Operativo' });
                $sheet->setCellValue("C{$r}", $p['operaciones']);
                $sheet->setCellValue("D{$r}", $p['colocacion']);
                $sheet->setCellValue("E{$r}", $p['cartera']);
                $sheet->setCellValue("F{$r}", $p['contratos']);
                $this->dataRow($sheet, "A{$r}:F{$r}", $i % 2 === 0);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
                $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
                foreach (['D', 'E'] as $col) {
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                    $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                $r++;
            }
        }

        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(14);
        $sheet->freezePane('A3');
    }

    // ── HOJA 3: SUCURSALES ───────────────────────────────────────────────────

    private function buildSucursalesSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet    = $ss->createSheet()->setTitle('SUCURSALES');
        $branches = $snap['sections']['branches'] ?? [];

        $this->sheetTitle($sheet, 'A1:G1', 'DESGLOSE POR SUCURSAL — ' . strtoupper($period->label));

        if (empty($branches)) {
            $sheet->setCellValue('A2', 'Sin datos por sucursal para este periodo.');
            return;
        }

        $this->colHeaders($sheet, 2, [
            'A' => 'SUCURSAL', 'B' => 'RECUPERACIÓN', 'C' => 'COLOCACIÓN',
            'D' => 'CARTERA', 'E' => 'C. VENCIDA', 'F' => 'MORA %', 'G' => 'GASTOS',
        ]);

        $r = 3;
        $totRec = $totPla = $totCar = $totVen = $totGas = 0.0;
        foreach ($branches as $i => $b) {
            $mora = (float)$b['mora'];
            $sheet->setCellValue("A{$r}", $b['nombre']);
            $sheet->setCellValue("B{$r}", (float)$b['recuperacion']);
            $sheet->setCellValue("C{$r}", (float)$b['colocacion']);
            $sheet->setCellValue("D{$r}", (float)$b['cartera']);
            $sheet->setCellValue("E{$r}", (float)$b['vencida']);
            $sheet->setCellValue("F{$r}", $mora);
            $sheet->setCellValue("G{$r}", (float)$b['gastos']);
            $this->dataRow($sheet, "A{$r}:G{$r}", $i % 2 === 0);
            foreach (['B', 'C', 'D', 'E', 'G'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ($mora > 25) {
                $sheet->getStyle("F{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
            }
            $totRec += (float)$b['recuperacion'];
            $totPla += (float)$b['colocacion'];
            $totCar += (float)$b['cartera'];
            $totVen += (float)$b['vencida'];
            $totGas += (float)$b['gastos'];
            $r++;
        }

        $sheet->setCellValue("A{$r}", 'TOTALES');
        $sheet->setCellValue("B{$r}", $totRec);
        $sheet->setCellValue("C{$r}", $totPla);
        $sheet->setCellValue("D{$r}", $totCar);
        $sheet->setCellValue("E{$r}", $totVen);
        $sheet->setCellValue("F{$r}", $totCar > 0 ? round($totVen / $totCar * 100, 2) : 0);
        $sheet->setCellValue("G{$r}", $totGas);
        $this->totalsRow($sheet, "A{$r}:G{$r}");
        foreach (['B', 'C', 'D', 'E', 'G'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        }
        $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);

        $sheet->getColumnDimension('A')->setWidth(28);
        foreach (['B', 'C', 'D', 'E', 'G'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(18);
        }
        $sheet->getColumnDimension('F')->setWidth(10);
        $sheet->setAutoFilter("A2:G2");
        $sheet->freezePane('A3');
    }

    // ── EMPLEADOS (fusionado, sin duplicados por alias) ──────────────────────

    private function buildEmpleadosSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('EMPLEADOS');
        $rows  = $snap['sections']['employees_gestores'] ?? [];

        $this->sheetTitle($sheet, 'A1:M1', 'EMPLEADOS / GESTORES — ' . strtoupper($period->label));

        if (empty($rows)) {
            $sheet->setCellValue('A2', 'Sin datos de empleados para este periodo.');
            return;
        }

        $this->colHeaders($sheet, 2, [
            'A' => 'EMPLEADO / GESTOR',
            'B' => 'SUCURSAL',
            'C' => 'PAGOS',
            'D' => 'BONOS',
            'E' => 'DESCUENTOS',
            'F' => 'NETO',
            'G' => 'COLOCACIÓN',
            'H' => 'OPS',
            'I' => 'RECUPERACIÓN',
            'J' => 'CARTERA',
            'K' => 'C. VENCIDA',
            'L' => 'MORA %',
            'M' => 'GASTOS',
        ]);

        $r = 3;
        foreach ($rows as $i => $emp) {
            $sheet->setCellValue("A{$r}", $emp['name']);
            $sheet->setCellValue("B{$r}", $emp['branch'] !== 'Sin sucursal' ? $emp['branch'] : '—');
            $sheet->setCellValue("C{$r}", (float)$emp['pagos']);
            $sheet->setCellValue("D{$r}", (float)$emp['bonos']);
            $sheet->setCellValue("E{$r}", (float)$emp['descuentos']);
            $sheet->setCellValue("F{$r}", (float)$emp['neto']);
            $sheet->setCellValue("G{$r}", (float)$emp['colocacion']);
            $sheet->setCellValue("H{$r}", (int)$emp['operaciones']);
            $sheet->setCellValue("I{$r}", (float)$emp['recuperacion']);
            $sheet->setCellValue("J{$r}", (float)$emp['cartera']);
            $sheet->setCellValue("K{$r}", (float)$emp['vencida']);
            $sheet->setCellValue("L{$r}", (float)$emp['mora']);
            $sheet->setCellValue("M{$r}", (float)$emp['gastos']);
            $this->dataRow($sheet, "A{$r}:M{$r}", $i % 2 === 0);
            foreach (['C', 'D', 'E', 'F', 'G', 'I', 'J', 'K', 'M'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
            $sheet->getStyle("L{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("L{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ((float)$emp['mora'] > 25) {
                $sheet->getStyle("L{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
            }
            $r++;
        }

        // Totals row
        $sheet->setCellValue("A{$r}", 'TOTALES');
        foreach ([
            'C' => 'pagos', 'D' => 'bonos', 'E' => 'descuentos', 'F' => 'neto',
            'G' => 'colocacion', 'I' => 'recuperacion', 'J' => 'cartera',
            'K' => 'vencida', 'M' => 'gastos',
        ] as $col => $key) {
            $tot = array_sum(array_column($rows, $key));
            $sheet->setCellValue("{$col}{$r}", $tot);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        }
        $sheet->setCellValue("H{$r}", array_sum(array_column($rows, 'operaciones')));
        $this->totalsRow($sheet, "A{$r}:M{$r}");

        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(22);
        foreach (['C', 'D', 'E', 'F', 'G', 'I', 'J', 'K', 'M'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
        $sheet->getColumnDimension('H')->setWidth(8);
        $sheet->getColumnDimension('L')->setWidth(10);
        $sheet->setAutoFilter("A2:M2");
        $sheet->freezePane('A3');
    }

    // ── HOJA 5: CARTERA Y MORA ───────────────────────────────────────────────

    private function buildCarteraSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet   = $ss->createSheet()->setTitle('CARTERA Y MORA');
        $buckets = $snap['sections']['portfolio_buckets'] ?? [];
        $sum     = $snap['summary'];

        $this->sheetTitle($sheet, 'A1:E1', 'CARTERA Y MORA — ' . strtoupper($period->label));

        // Global portfolio summary
        $this->sectionHeader($sheet, 'A3:E3', 'RESUMEN CARTERA GLOBAL');
        $this->colHeaders($sheet, 4, ['A' => 'MÉTRICA', 'B' => 'VALOR']);

        $globalRows = [
            ['Cartera total',       $sum['portfolio_total'],   'currency'],
            ['Cartera vencida',     $sum['overdue_portfolio'],  'currency'],
            ['Índice de mora',      $sum['mora_index'],        'percent'],
        ];
        $r = 5;
        foreach ($globalRows as $i => [$label, $value, $fmt]) {
            $sheet->mergeCells("B{$r}:E{$r}");
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $this->dataRow($sheet, "A{$r}:E{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $value);
            $r++;
        }

        // Bucket breakdown
        $r += 2;
        $this->sectionHeader($sheet, "A{$r}:E{$r}", 'DISTRIBUCIÓN POR DÍAS VENCIDOS (BUCKETS)');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'BUCKET', 'B' => 'CONTRATOS', 'C' => 'BALANCE', 'D' => 'VENCIDO', 'E' => '% DEL TOTAL']);
        $r++;

        if (empty($buckets)) {
            $sheet->setCellValue("A{$r}", 'Sin datos de días vencidos en cartera. Verifica que el archivo de Saldos incluya columna "días_mora" o "días_vencidos".');
        } else {
            $totBal = collect($buckets)->sum('balance');
            $totVen = collect($buckets)->sum('vencida');
            foreach ($buckets as $i => $b) {
                $pct = $totBal > 0 ? round($b['balance'] / $totBal * 100, 1) : 0;
                $sheet->setCellValue("A{$r}", $b['label']);
                $sheet->setCellValue("B{$r}", $b['contratos']);
                $sheet->setCellValue("C{$r}", $b['balance']);
                $sheet->setCellValue("D{$r}", $b['vencida']);
                $sheet->setCellValue("E{$r}", $pct);
                $this->dataRow($sheet, "A{$r}:E{$r}", $i % 2 === 0);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
                foreach (['C', 'D', 'E'] as $col) {
                    $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                $r++;
            }
            // Totals
            $sheet->setCellValue("A{$r}", 'TOTALES');
            $sheet->setCellValue("C{$r}", $totBal);
            $sheet->setCellValue("D{$r}", $totVen);
            $this->totalsRow($sheet, "A{$r}:E{$r}");
            foreach (['C', 'D'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            }
        }

        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(12);
    }

    // ── GASTOS (matriz categoría × sucursal) ────────────────────────────────

    private function buildGastosMatrixSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet  = $ss->createSheet()->setTitle('GASTOS');
        $mx     = $snap['sections']['expenses_matrix'] ?? [];

        $this->sheetTitle($sheet, 'A1:A1', 'GASTOS — ' . strtoupper($period->label));

        $categories = $mx['categories'] ?? [];
        $branches   = $mx['branches']   ?? [];
        $matrix     = $mx['matrix']     ?? [];
        $totByCat   = $mx['totals_by_category'] ?? [];
        $totByBr    = $mx['totals_by_branch']   ?? [];
        $grandTotal = $mx['grand_total'] ?? 0.0;

        if (empty($categories) || empty($branches)) {
            $sheet->setCellValue('A2', 'Sin gastos registrados para este periodo.');
            return;
        }

        // Merge title across all columns: 1 label col + N branch cols + 1 total col
        $totalCols = count($branches) + 2; // A=categoría, B..N=sucursales, last=TOTALES
        $lastCol   = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalCols);
        $sheet->mergeCells("A1:{$lastCol}1");

        // Header row
        $headers = ['A' => 'CATEGORÍA'];
        foreach ($branches as $idx => $br) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 2);
            $headers[$col] = strtoupper($br);
        }
        $headers[$lastCol] = 'TOTALES';
        $this->colHeaders($sheet, 2, $headers);

        $r = 3;
        foreach ($categories as $i => $cat) {
            $sheet->setCellValue("A{$r}", $cat);
            foreach ($branches as $idx => $br) {
                $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 2);
                $val = (float)($matrix[$cat][$br] ?? 0);
                $sheet->setCellValue("{$col}{$r}", $val);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $tot = (float)($totByCat[$cat] ?? 0);
            $sheet->setCellValue("{$lastCol}{$r}", $tot);
            $sheet->getStyle("{$lastCol}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$lastCol}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("{$lastCol}{$r}")->getFont()->setBold(true);
            $this->dataRow($sheet, "A{$r}:{$lastCol}{$r}", $i % 2 === 0);
            $r++;
        }

        // Totals row
        $sheet->setCellValue("A{$r}", 'TOTALES');
        foreach ($branches as $idx => $br) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 2);
            $val = (float)($totByBr[$br] ?? 0);
            $sheet->setCellValue("{$col}{$r}", $val);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        }
        $sheet->setCellValue("{$lastCol}{$r}", $grandTotal);
        $sheet->getStyle("{$lastCol}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $this->totalsRow($sheet, "A{$r}:{$lastCol}{$r}");

        $sheet->getColumnDimension('A')->setWidth(36);
        foreach ($branches as $idx => $br) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 2);
            $sheet->getColumnDimension($col)->setWidth(18);
        }
        $sheet->getColumnDimension($lastCol)->setWidth(18);
        $sheet->setAutoFilter("A2:{$lastCol}2");
        $sheet->freezePane('B3');
    }

    // ── NOMINAS (por sucursal y concepto NOI) ───────────────────────────────

    private function buildNominasSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet   = $ss->createSheet()->setTitle('NOMINAS');
        $byBrCon = $snap['sections']['payroll_by_branch_concept'] ?? [];

        $this->sheetTitle($sheet, 'A1:C1', 'NÓMINA — ' . strtoupper($period->label));

        if (empty($byBrCon)) {
            $sheet->setCellValue('A2', 'Sin movimientos NOI para este periodo.');
            return;
        }

        $r = 3;
        $grandTotal = 0.0;

        foreach ($byBrCon as $branch => $concepts) {
            // Branch section header
            $this->sectionHeader($sheet, "A{$r}:C{$r}", strtoupper($branch));
            $r++;
            $this->colHeaders($sheet, $r, ['A' => 'CONCEPTO', 'B' => 'ACUMULADO', 'C' => '']);
            $r++;

            $branchTotal = 0.0;
            $rowIdx = 0;
            foreach ($concepts as $concept => $amount) {
                $sheet->setCellValue("A{$r}", $concept);
                $sheet->setCellValue("B{$r}", (float)$amount);
                $this->dataRow($sheet, "A{$r}:C{$r}", $rowIdx % 2 === 0);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $branchTotal += (float)$amount;
                $rowIdx++;
                $r++;
            }

            // Branch subtotal
            $sheet->setCellValue("A{$r}", 'TOTAL ' . strtoupper($branch));
            $sheet->setCellValue("B{$r}", $branchTotal);
            $this->totalsRow($sheet, "A{$r}:C{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $grandTotal += $branchTotal;
            $r += 2;
        }

        // Grand total
        $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $grandTotal);
        $this->totalsRow($sheet, "A{$r}:C{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getStyle("A{$r}")->getFont()->setSize(11);

        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(4);
        $sheet->freezePane('A4');
    }

    // ── INCIDENCIAS ──────────────────────────────────────────────────────────

    private function buildIncidenciasSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet     = $ss->createSheet()->setTitle('INCIDENCIAS');
        $incidents = $snap['sections']['incidents'] ?? [];

        $this->sheetTitle($sheet, 'A1:E1', 'INCIDENCIAS — ' . strtoupper($period->label));
        $this->colHeaders($sheet, 2, [
            'A' => 'SEVERIDAD',
            'B' => 'TIPO',
            'C' => 'DESCRIPCIÓN',
            'D' => 'DATOS AFECTADOS',
            'E' => 'SUGERENCIA',
        ]);

        if (empty($incidents)) {
            $sheet->setCellValue('A3', 'Sin incidencias detectadas para este periodo.');
            $sheet->getStyle('A3')->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
            $sheet->getColumnDimension('C')->setWidth(60);
            return;
        }

        $friendlyType = [
            'empleados_sin_sucursal'     => 'Empleados sin sucursal',
            'gestores_sin_match_noi'     => 'Gestores sin coincidencia NOI',
            'cartera_sin_producto'       => 'Contratos sin producto',
            'cartera_vencida_recalculada'=> 'Cartera vencida recalculada',
            'nombre_fusionado'           => 'Nombre fusionado (variante)',
        ];

        $severityColors = [
            'error'   => 'FFFEE2E2',
            'warning' => 'FFFEF9C3',
            'info'    => 'FFE0F2FE',
        ];

        $r = 3;
        foreach ($incidents as $i => $inc) {
            $sev  = $inc['severity'] ?? 'info';
            $type = $inc['type']     ?? '';
            $bg   = $severityColors[$sev] ?? self::BG_ALT;

            $sheet->setCellValue("A{$r}", match($sev) { 'error' => 'ERROR', 'warning' => 'ADVERTENCIA', default => 'INFO' });
            $sheet->setCellValue("B{$r}", $friendlyType[$type] ?? ucwords(str_replace('_', ' ', $type)));
            $sheet->setCellValue("C{$r}", $inc['message'] ?? '');
            $sheet->setCellValue("D{$r}", '');
            $sheet->setCellValue("E{$r}", match($sev) {
                'error'   => 'Revisar y corregir antes de cerrar el periodo.',
                'warning' => 'Verificar y corregir si es posible.',
                default   => 'Informativo. Sin acción requerida.',
            });

            $sheet->getStyle("A{$r}:E{$r}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => self::BORDER_LT]]],
                'alignment' => ['vertical' => Alignment::VERTICAL_TOP, 'wrapText' => true],
            ]);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle("B{$r}:E{$r}")->getFont()->setSize(9);
            $sheet->getRowDimension($r)->setRowHeight(32);
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(14);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(60);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(40);
        $sheet->setAutoFilter("A2:E2");
        $sheet->freezePane('A3');
    }

    // ── RECUP. ───────────────────────────────────────────────────────────────

    private function buildRecuperacionSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet  = $ss->createSheet()->setTitle('RECUP.');
        $detail = $snap['sections']['recovery_detail'] ?? [];
        $rows   = $detail['by_branch'] ?? [];

        [$mesLabel, $anioLabel] = $this->periodMonthYear($period);
        $this->greenTitle($sheet, 'A1:H1', "RECUPERACIÓN {$mesLabel} - {$anioLabel}");

        $headers = ['A' => 'SUCURSAL', 'B' => 'CAPITAL', 'C' => 'INTERÉS',
                    'D' => 'IMPUESTO', 'E' => 'CARGOS AL INICIO', 'F' => 'CARGOS CALENDARIOS',
                    'G' => 'MORATORIOS', 'H' => 'GRAN TOTAL'];
        $this->greenColHeaders($sheet, 2, $headers);

        if (empty($rows)) {
            $sheet->setCellValue('A3', 'Sin datos de recuperación para este periodo.');
            $this->setColWidths($sheet, ['A' => 28, 'B' => 18, 'C' => 18, 'D' => 14,
                'E' => 18, 'F' => 18, 'G' => 14, 'H' => 18]);
            return;
        }

        $r = 3;
        $tots = ['capital' => 0.0, 'interest' => 0.0, 'tax' => 0.0,
                 'charges' => 0.0, 'moratorios' => 0.0, 'total' => 0.0];

        foreach ($rows as $i => $row) {
            $bg = $i % 2 === 0 ? self::BG_GREEN_ROW : self::BG_EVEN;
            $sheet->setCellValue("A{$r}", $row['branch']);
            $sheet->setCellValue("B{$r}", $row['capital']    ?? 0.0);
            $sheet->setCellValue("C{$r}", $row['interest']   ?? 0.0);
            $sheet->setCellValue("D{$r}", $row['tax']        ?? 0.0);
            // charges split equally between inicio/calendarios if not separated
            $charges = (float)($row['charges'] ?? 0);
            $sheet->setCellValue("E{$r}", $charges);
            $sheet->setCellValue("F{$r}", 0.0);
            $sheet->setCellValue("G{$r}", $row['moratorios'] ?? 0.0);
            $sheet->setCellValue("H{$r}", $row['total']      ?? 0.0);

            $sheet->getStyle("A{$r}:H{$r}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR,
                                             'color'       => ['argb' => 'FFD1FAE5']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(9);
            foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("{$col}{$r}")->getFont()->setSize(9);
            }
            $sheet->getRowDimension($r)->setRowHeight(17);

            $tots['capital']    += (float)($row['capital']   ?? 0);
            $tots['interest']   += (float)($row['interest']  ?? 0);
            $tots['tax']        += (float)($row['tax']       ?? 0);
            $tots['charges']    += $charges;
            $tots['moratorios'] += (float)($row['moratorios']?? 0);
            $tots['total']      += (float)($row['total']     ?? 0);
            $r++;
        }

        // Total general
        $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $tots['capital']);
        $sheet->setCellValue("C{$r}", $tots['interest']);
        $sheet->setCellValue("D{$r}", $tots['tax']);
        $sheet->setCellValue("E{$r}", $tots['charges']);
        $sheet->setCellValue("F{$r}", 0.0);
        $sheet->setCellValue("G{$r}", $tots['moratorios']);
        $sheet->setCellValue("H{$r}", $tots['total']);
        $sheet->getStyle("A{$r}:H{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_TOT]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF064E3B']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getRowDimension($r)->setRowHeight(20);

        $this->setColWidths($sheet, ['A' => 26, 'B' => 16, 'C' => 16, 'D' => 14,
            'E' => 18, 'F' => 18, 'G' => 14, 'H' => 18]);
        $sheet->setAutoFilter("A2:H2");
        $sheet->freezePane('A3');
    }

    // ── MORA ─────────────────────────────────────────────────────────────────

    private function buildMoraSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('MORA');
        $rows  = $snap['sections']['mora_by_branch'] ?? [];

        [$mesLabel, $anioLabel] = $this->periodMonthYear($period);
        $this->greenTitle($sheet, 'A1:L1', "MORAS {$mesLabel} - {$anioLabel}");

        // Tabla principal: sucursal + capital atrasado + vencida + buckets
        $this->greenColHeaders($sheet, 2, [
            'A' => 'SUCURSAL',
            'B' => 'CAPITAL ATRASADO',
            'C' => 'INTERÉS ATRASADO',
            'D' => 'IMPUESTO ATRASADO',
            'E' => 'MORATORIOS',
            'F' => 'TOTAL VENCIDO',
            'G' => 'DE 0 A 30',
            'H' => 'DE 31 A 60',
            'I' => 'DE 61 A 90',
            'J' => 'DE 91 A 120',
            'K' => 'DE 120 EN ADELANTE',
            'L' => 'CARTERA TOTAL',
        ]);

        if (empty($rows)) {
            $sheet->setCellValue('A3', 'Sin datos de mora por sucursal para este periodo.');
            $this->setColWidths($sheet, ['A' => 26, 'B' => 18, 'C' => 18, 'D' => 18,
                'E' => 14, 'F' => 18, 'G' => 14, 'H' => 14, 'I' => 14, 'J' => 14, 'K' => 20, 'L' => 16]);
            return;
        }

        $r   = 3;
        $tot = array_fill_keys(['vencida_total', 'cartera_total', 'mora_1_30', 'mora_31_60',
                                'mora_61_90', 'mora_91_120', 'mora_121_180', 'mora_180_mas'], 0.0);

        foreach ($rows as $i => $row) {
            $bg = $i % 2 === 0 ? self::BG_GREEN_ROW : self::BG_EVEN;

            // capital_due available per bucket not per row; sum all vencida buckets as best proxy
            $vencida = (float)($row['vencida_total'] ?? 0);
            $m0_30   = (float)($row['mora_1_30']    ?? 0);
            $m31_60  = (float)($row['mora_31_60']   ?? 0);
            $m61_90  = (float)($row['mora_61_90']   ?? 0);
            $m91_120 = (float)($row['mora_91_120']  ?? 0);
            $m120p   = ((float)($row['mora_121_180'] ?? 0)) + ((float)($row['mora_180_mas'] ?? 0));

            $sheet->setCellValue("A{$r}", $row['branch']);
            $sheet->setCellValue("B{$r}", $vencida);   // capital atrasado proxy
            $sheet->setCellValue("C{$r}", 0.0);        // interés atrasado: N/D en fact_portfolios
            $sheet->setCellValue("D{$r}", 0.0);        // impuesto atrasado: N/D
            $sheet->setCellValue("E{$r}", 0.0);        // moratorios: N/D
            $sheet->setCellValue("F{$r}", $vencida);
            $sheet->setCellValue("G{$r}", $m0_30);
            $sheet->setCellValue("H{$r}", $m31_60);
            $sheet->setCellValue("I{$r}", $m61_90);
            $sheet->setCellValue("J{$r}", $m91_120);
            $sheet->setCellValue("K{$r}", $m120p);
            $sheet->setCellValue("L{$r}", (float)($row['cartera_total'] ?? 0));

            $sheet->getStyle("A{$r}:L{$r}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR,
                                             'color'       => ['argb' => 'FFD1FAE5']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(9);
            foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("{$col}{$r}")->getFont()->setSize(9);
            }
            $sheet->getRowDimension($r)->setRowHeight(17);

            $tot['vencida_total']  += $vencida;
            $tot['cartera_total']  += (float)($row['cartera_total'] ?? 0);
            $tot['mora_1_30']      += $m0_30;
            $tot['mora_31_60']     += $m31_60;
            $tot['mora_61_90']     += $m61_90;
            $tot['mora_91_120']    += $m91_120;
            $tot['mora_121_180']   += $m120p;
            $r++;
        }

        // Total general
        $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $tot['vencida_total']);
        $sheet->setCellValue("C{$r}", 0.0);
        $sheet->setCellValue("D{$r}", 0.0);
        $sheet->setCellValue("E{$r}", 0.0);
        $sheet->setCellValue("F{$r}", $tot['vencida_total']);
        $sheet->setCellValue("G{$r}", $tot['mora_1_30']);
        $sheet->setCellValue("H{$r}", $tot['mora_31_60']);
        $sheet->setCellValue("I{$r}", $tot['mora_61_90']);
        $sheet->setCellValue("J{$r}", $tot['mora_91_120']);
        $sheet->setCellValue("K{$r}", $tot['mora_121_180']);
        $sheet->setCellValue("L{$r}", $tot['cartera_total']);
        $sheet->getStyle("A{$r}:L{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_TOT]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF064E3B']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getRowDimension($r)->setRowHeight(20);

        $this->setColWidths($sheet, ['A' => 26, 'B' => 18, 'C' => 18, 'D' => 18,
            'E' => 14, 'F' => 18, 'G' => 14, 'H' => 14, 'I' => 14, 'J' => 14, 'K' => 22, 'L' => 16]);
        $sheet->setAutoFilter("A2:L2");
        $sheet->freezePane('A3');
    }

    // ── P. INTERSUC. ─────────────────────────────────────────────────────────

    private function buildInterbranchLoansSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('P. INTERSUC.');
        $loans = $snap['sections']['interbranch_loans'] ?? [];

        [$mesLabel, $anioLabel] = $this->periodMonthYear($period);
        $this->sheetTitle($sheet, 'A1:D1',
            'PRÉSTAMOS INTERSUCURSALES — ' . strtoupper($mesLabel) . ' ' . $anioLabel);

        $fondea = $loans['fondea']  ?? [];
        $recibe = $loans['recibe']  ?? [];
        $detail = $loans['detail']  ?? [];
        $total  = (float)($loans['total'] ?? 0);

        $r = 3;

        if ($total === 0.0 && empty($detail)) {
            $sheet->setCellValue("A{$r}", 'Sin préstamos intersucursales registrados para este periodo.');
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
            $this->setColWidths($sheet, ['A' => 36, 'B' => 22, 'C' => 22, 'D' => 18]);
            return;
        }

        // ── Bloque 1: FONDEA ────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:B{$r}", 'SUCURSAL QUE FONDEA');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'SUMA DE TOTAL']);
        $r++;
        $fondeaTotal = 0.0;
        foreach ($fondea as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['branch']);
            $sheet->setCellValue("B{$r}", (float)$row['total']);
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $fondeaTotal += (float)$row['total'];
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("B{$r}", $fondeaTotal);
        $this->totalsRow($sheet, "A{$r}:B{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── Bloque 2: RECIBE ────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:B{$r}", 'SUCURSAL QUE RECIBE');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'SUMA DE TOTAL']);
        $r++;
        $recibeTotal = 0.0;
        foreach ($recibe as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['branch']);
            $sheet->setCellValue("B{$r}", (float)$row['total']);
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $recibeTotal += (float)$row['total'];
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("B{$r}", $recibeTotal);
        $this->totalsRow($sheet, "A{$r}:B{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── Bloque 3: DETALLE ───────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:D{$r}", 'DETALLE DE OPERACIONES');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'FECHA CREACIÓN', 'B' => 'SUCURSAL QUE FONDEA',
            'C' => 'SUCURSAL QUE RECIBE', 'D' => 'MONTO',
        ]);
        $r++;
        foreach ($detail as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['date']);
            $sheet->setCellValue("B{$r}", $row['from_branch']);
            $sheet->setCellValue("C{$r}", $row['to_branch']);
            $sheet->setCellValue("D{$r}", $row['amount']);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("D{$r}", $total);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);

        $this->setColWidths($sheet, ['A' => 18, 'B' => 28, 'C' => 28, 'D' => 18]);
        $sheet->freezePane('A3');
    }

    // ── HOJA 8: METADATA ─────────────────────────────────────────────────────

    private function buildMetadataSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('METADATA');

        $this->sheetTitle($sheet, 'A1:C1', 'METADATA DEL REPORTE');

        $rows = [
            ['Periodo',             $snap['period']['label']],
            ['Código',              $snap['period']['code'] ?? $snap['period']['id']],
            ['Fecha inicio',        $snap['period']['start_date'] ?? '—'],
            ['Fecha fin',           $snap['period']['end_date'] ?? '—'],
            ['Generado (hora MX)',  $snap['generated_at']],
            ['Versión',             $snap['version']],
            ['Tipo reporte',        'Radiografía simple'],
            ['Fuente nómina',       $snap['sections']['payroll']['source'] ?? 'desconocida'],
            ['Empleados / gestores', count($snap['sections']['employees_gestores'] ?? [])],
            ['Total sucursales',    count($snap['sections']['branches'])],
            ['Total productos',     count($snap['sections']['products'])],
        ];

        $this->colHeaders($sheet, 2, ['A' => 'CAMPO', 'B' => 'VALOR']);
        $r = 3;
        foreach ($rows as $i => [$k, $v]) {
            $sheet->setCellValue("A{$r}", $k);
            $sheet->setCellValue("B{$r}", $v);
            $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(28);
        $sheet->getColumnDimension('B')->setWidth(30);
    }

    // ── VAL. CART ─────────────────────────────────────────────────────────────

    private function buildPortfolioValueSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('VAL. CART');
        $rows  = $snap['sections']['portfolio_by_branch_product'] ?? [];

        [$mesLabel, $anioLabel] = $this->periodMonthYear($period);
        $this->greenTitle($sheet, 'A1:D1', "VALOR CARTERA {$mesLabel} - {$anioLabel}");
        $this->greenColHeaders($sheet, 2, [
            'A' => 'SUCURSAL / PRODUCTO',
            'B' => 'VALOR CRÉDITO ADEUDO',
            'C' => 'CARTERA VENCIDA',
            'D' => 'MORA %',
        ]);

        if (empty($rows)) {
            $sheet->setCellValue('A3', 'Sin datos de cartera para este periodo.');
            $this->setColWidths($sheet, ['A' => 36, 'B' => 22, 'C' => 22, 'D' => 10]);
            return;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['branch']][] = $row;
        }

        $r = 3;
        $grandTotal   = 0.0;
        $grandVencida = 0.0;

        foreach ($grouped as $branch => $products) {
            $bCartera = array_sum(array_column($products, 'cartera'));
            $bVencida = array_sum(array_column($products, 'vencida'));
            $bMora    = $bCartera > 0 ? round($bVencida / $bCartera * 100, 2) : 0.0;

            $sheet->setCellValue("A{$r}", strtoupper($branch));
            $sheet->setCellValue("B{$r}", $bCartera);
            $sheet->setCellValue("C{$r}", $bVencida);
            $sheet->setCellValue("D{$r}", $bMora);
            $sheet->getStyle("A{$r}:D{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_WHITE]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_HDR]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            foreach (['B', 'C'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;

            foreach ($products as $i => $prod) {
                if ((float)$prod['cartera'] <= 0) continue;
                $bg = $i % 2 === 0 ? self::BG_GREEN_ROW : self::BG_EVEN;
                $sheet->setCellValue("A{$r}", '    ' . $prod['product']);
                $sheet->setCellValue("B{$r}", (float)$prod['cartera']);
                $sheet->setCellValue("C{$r}", (float)$prod['vencida']);
                $sheet->setCellValue("D{$r}", (float)$prod['mora']);
                $sheet->getStyle("A{$r}:D{$r}")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFD1FAE5']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("A{$r}")->getFont()->setSize(9);
                foreach (['B', 'C'] as $col) {
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                    $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle("{$col}{$r}")->getFont()->setSize(9);
                }
                $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
                $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("D{$r}")->getFont()->setSize(9);
                if ((float)$prod['mora'] > 25) {
                    $sheet->getStyle("D{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
                }
                $sheet->getRowDimension($r)->setRowHeight(16);
                $r++;
            }

            $grandTotal   += $bCartera;
            $grandVencida += $bVencida;
        }

        $grandMora = $grandTotal > 0 ? round($grandVencida / $grandTotal * 100, 2) : 0.0;
        $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $grandTotal);
        $sheet->setCellValue("C{$r}", $grandVencida);
        $sheet->setCellValue("D{$r}", $grandMora);
        $sheet->getStyle("A{$r}:D{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_TOT]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF064E3B']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        foreach (['B', 'C'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
        $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getRowDimension($r)->setRowHeight(20);

        $this->setColWidths($sheet, ['A' => 36, 'B' => 22, 'C' => 22, 'D' => 10]);
        $sheet->setAutoFilter("A2:D2");
        $sheet->freezePane('A3');
    }

    // ── FOND CORP ─────────────────────────────────────────────────────────────

    private function buildCorporateFundingSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet    = $ss->createSheet()->setTitle('FOND CORP');
        $funding  = $snap['sections']['corporate_funding'] ?? [];
        $byDay    = $funding['by_day']    ?? [];
        $byBranch = $funding['by_branch'] ?? [];
        $total    = (float)($funding['total'] ?? 0);

        [$mesLabel, $anioLabel] = $this->periodMonthYear($period);
        $this->greenTitle($sheet, 'A1:C1', $mesLabel);

        // Block 1: calendar by day
        $this->greenColHeaders($sheet, 2, ['A' => 'DÍA', 'B' => 'DÍA DE SEMANA', 'C' => 'MONTO']);

        $r = 3;
        foreach ($byDay as $i => $dayRow) {
            $bg = $i % 2 === 0 ? self::BG_GREEN_ROW : self::BG_EVEN;
            $sheet->setCellValue("A{$r}", $dayRow['day']);
            $sheet->setCellValue("B{$r}", $dayRow['weekday']);
            $sheet->setCellValue("C{$r}", (float)$dayRow['total']);
            $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFD1FAE5']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("A{$r}")->getFont()->setSize(9);
            $sheet->getStyle("B{$r}")->getFont()->setSize(9);
            $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("C{$r}")->getFont()->setSize(9);
            $sheet->getRowDimension($r)->setRowHeight(16);
            $r++;
        }

        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("C{$r}", $total);
        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_TOT]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF064E3B']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r += 2;

        // Block 2: by branch
        $this->greenColHeaders($sheet, $r, ['A' => 'SUCURSAL', 'B' => '', 'C' => 'MONTO']);
        $r++;

        if (empty($byBranch)) {
            $sheet->setCellValue("A{$r}", 'Sin datos de envío a corporativo para este periodo.');
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
        } else {
            $branchTotal = 0.0;
            foreach ($byBranch as $i => $row) {
                $bg = $i % 2 === 0 ? self::BG_GREEN_ROW : self::BG_EVEN;
                $sheet->setCellValue("A{$r}", $row['branch']);
                $sheet->setCellValue("C{$r}", (float)$row['total']);
                $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFD1FAE5']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(9);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("C{$r}")->getFont()->setSize(9);
                $sheet->getRowDimension($r)->setRowHeight(16);
                $branchTotal += (float)$row['total'];
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'TOTAL');
            $sheet->setCellValue("C{$r}", $branchTotal);
            $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::FG_WHITE]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_TOT]],
                'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF064E3B']]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($r)->setRowHeight(20);
        }

        $this->setColWidths($sheet, ['A' => 28, 'B' => 20, 'C' => 22]);
        $sheet->freezePane('A3');
    }

    // ── COLOCACIÓN ───────────────────────────────────────────────────────────

    private function buildPlacementSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('COLOCACIÓN');
        $rows  = $snap['sections']['placement_by_branch_product'] ?? [];

        [$mesLabel, $anioLabel] = $this->periodMonthYear($period);
        $this->greenTitle($sheet, 'A1:C1', $mesLabel);

        // Block 1: Colocación by branch / product
        $this->sectionHeader($sheet, 'A2:C2', 'COLOCACIÓN');
        $this->greenColHeaders($sheet, 3, [
            'A' => 'SUCURSAL / PRODUCTO',
            'B' => 'SUMA DE MONTO COLOCADO',
            'C' => '# CRÉDITOS COLOCADOS',
        ]);

        if (empty($rows)) {
            $sheet->setCellValue('A4', 'Sin datos de colocación para este periodo.');
            $sheet->getStyle('A4')->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
            $this->setColWidths($sheet, ['A' => 36, 'B' => 24, 'C' => 20]);
            return;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['branch']][] = $row;
        }

        $r = 4;
        $grandMonto    = 0.0;
        $grandCreditos = 0;

        foreach ($grouped as $branch => $products) {
            $bMonto    = array_sum(array_column($products, 'monto'));
            $bCreditos = (int)array_sum(array_column($products, 'creditos'));

            $sheet->setCellValue("A{$r}", strtoupper($branch));
            $sheet->setCellValue("B{$r}", $bMonto);
            $sheet->setCellValue("C{$r}", $bCreditos);
            $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_WHITE]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_HDR]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
            $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;

            foreach ($products as $i => $prod) {
                $bg = $i % 2 === 0 ? self::BG_GREEN_ROW : self::BG_EVEN;
                $sheet->setCellValue("A{$r}", '    ' . $prod['product']);
                $sheet->setCellValue("B{$r}", (float)$prod['monto']);
                $sheet->setCellValue("C{$r}", (int)$prod['creditos']);
                $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFD1FAE5']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("A{$r}")->getFont()->setSize(9);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("B{$r}")->getFont()->setSize(9);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
                $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("C{$r}")->getFont()->setSize(9);
                $sheet->getRowDimension($r)->setRowHeight(16);
                $r++;
            }

            $grandMonto    += $bMonto;
            $grandCreditos += $bCreditos;
        }

        $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $grandMonto);
        $sheet->setCellValue("C{$r}", $grandCreditos);
        $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_TOT]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF064E3B']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
        $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getRowDimension($r)->setRowHeight(20);
        $r += 2;

        // Block 2: Comisión por apertura (no source data available)
        $this->sectionHeader($sheet, "A{$r}:C{$r}", 'COMISIÓN POR APERTURA');
        $r++;
        $this->greenColHeaders($sheet, $r, [
            'A' => 'SUCURSAL / PRODUCTO',
            'B' => 'MONTO DE APERTURA',
            'C' => '# APERTURAS REGISTRADAS',
        ]);
        $r++;
        $sheet->mergeCells("A{$r}:C{$r}");
        $sheet->setCellValue("A{$r}", 'No hay datos de comisión por apertura disponibles en la fuente actual.');
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF64748B');

        $this->setColWidths($sheet, ['A' => 36, 'B' => 24, 'C' => 20]);
        $sheet->setAutoFilter("A3:C3");
        $sheet->freezePane('A4');
    }

    // ── Categorías ────────────────────────────────────────────────────────────

    private function buildCategoriesSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet    = $ss->createSheet()->setTitle('Categorías');
        $branches = $snap['sections']['branches'] ?? [];
        $empRows  = $snap['sections']['employees_gestores'] ?? [];

        $this->sheetTitle($sheet, 'A1:C1', 'CATEGORIA GESTORES — ' . strtoupper($period->label));
        $this->colHeaders($sheet, 2, ['A' => 'SUCURSAL', 'B' => 'UTILIDAD', 'C' => 'CATEGORÍA']);

        if (empty($branches)) {
            $sheet->setCellValue('A3', 'Sin datos por sucursal para calcular categorías.');
            $this->setColWidths($sheet, ['A' => 28, 'B' => 20, 'C' => 16]);
            return;
        }

        // Aggregate net payroll per branch from employees_gestores
        $payrollByBranch = [];
        foreach ($empRows as $emp) {
            $br = strtoupper(trim($emp['branch'] ?? ''));
            if ($br === '' || $br === 'SIN SUCURSAL') continue;
            $payrollByBranch[$br] = ($payrollByBranch[$br] ?? 0.0) + (float)($emp['neto'] ?? 0);
        }

        $r = 3;
        foreach ($branches as $branch) {
            $brName   = $branch['nombre'] ?? ($branch['name'] ?? '');
            $rec      = (float)($branch['recuperacion'] ?? 0);
            $gastos   = (float)($branch['gastos'] ?? 0);
            $nomina   = $payrollByBranch[strtoupper(trim($brName))] ?? 0.0;
            $utilidad = $rec - $gastos - $nomina;

            $categoria = match(true) {
                $utilidad >= 300_000 => 'SENIOR',
                $utilidad >= 100_000 => 'JUNIOR',
                default              => 'MANTENIDO',
            };

            $bgRow = $utilidad >= 0 ? 'FFECFDF5' : 'FFFEF2F2';

            $sheet->setCellValue("A{$r}", $brName);
            $sheet->setCellValue("B{$r}", $utilidad);
            $sheet->setCellValue("C{$r}", $categoria);
            $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bgRow]],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => self::BORDER_LT]]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(9);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getStyle("B{$r}")->getFont()->setSize(9)->getColor()->setARGB($utilidad >= 0 ? 'FF065F46' : 'FFB91C1C');
            $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("C{$r}")->getFont()->setBold(true)->setSize(9);
            $sheet->getRowDimension($r)->setRowHeight(17);
            $r++;
        }

        $this->setColWidths($sheet, ['A' => 28, 'B' => 20, 'C' => 16]);
        $sheet->setAutoFilter("A2:C2");
        $sheet->freezePane('A3');
    }

    // ── Style helpers ─────────────────────────────────────────────────────────

    private function sheetTitle(Worksheet $sheet, string $range, string $text): void
    {
        $sheet->mergeCells($range);
        $sheet->setCellValue(explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(30);
        }
    }

    private function metaStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['size' => 9, 'color' => ['argb' => self::FG_META]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_META]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
    }

    private function sectionHeader(Worksheet $sheet, string $range, string $text): void
    {
        $sheet->mergeCells($range);
        $sheet->setCellValue(explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_BLUE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(20);
        }
    }

    private function colHeaders(Worksheet $sheet, int $row, array $cols): void
    {
        foreach ($cols as $col => $label) {
            $sheet->setCellValue("{$col}{$row}", $label);
        }
        $keys = array_keys($cols);
        $range = reset($keys) . $row . ':' . end($keys) . $row;
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_HDR]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_HDR]],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF93C5FD']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(18);
    }

    private function dataRow(Worksheet $sheet, string $range, bool $even): void
    {
        $bg = $even ? self::BG_EVEN : self::BG_ALT;
        $sheet->getStyle($range)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => self::BORDER_LT]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($range)->getFont()->setSize(9);
        preg_match('/[A-Z](\d+)/', $range, $m);
        if (!empty($m[1])) {
            $row = (int)$m[1];
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getRowDimension($row)->setRowHeight(17);
        }
    }

    private function totalsRow(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_TOTAL]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF94A3B8']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        preg_match('/[A-Z](\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(20);
        }
    }

    private function applyFmt(Worksheet $sheet, string $cell, string $fmt, mixed $value): void
    {
        match ($fmt) {
            'currency' => $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::CURRENCY),
            'percent'  => $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::PERCENT),
            'integer'  => $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(self::INTEGER),
            default    => null,
        };
        if ($fmt === 'percent' && (float)$value > 25) {
            $font = $sheet->getStyle($cell)->getFont();
            $font->setBold(true);
            $font->getColor()->setARGB(self::FG_RED);
        }
        if (in_array($fmt, ['currency', 'percent', 'integer'])) {
            $sheet->getStyle($cell)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
    }

    private function periodMonthYear(Period $period): array
    {
        $months = ['Enero','Febrero','Marzo','Abril','Mayo','Junio',
                   'Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $mes  = $months[(int)($period->month ?? date('n')) - 1] ?? strtoupper($period->label);
        $anio = $period->year ?? date('Y');
        return [strtoupper($mes), (string)$anio];
    }

    private function greenTitle(Worksheet $sheet, string $range, string $text): void
    {
        $sheet->mergeCells($range);
        $sheet->setCellValue(explode(':', $range)[0], $text);
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_HDR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(30);
        }
    }

    private function greenColHeaders(Worksheet $sheet, int $row, array $cols): void
    {
        foreach ($cols as $col => $label) {
            $sheet->setCellValue("{$col}{$row}", $label);
        }
        $keys  = array_keys($cols);
        $range = reset($keys) . $row . ':' . end($keys) . $row;
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_HDR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(18);
    }

    private function setColWidths(Worksheet $sheet, array $widths): void
    {
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
    }
}
