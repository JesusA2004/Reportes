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
    private const BG_EVEN   = 'FFFFFFFF';
    private const BORDER_LT = 'FFE2E8F0';
    private const CURRENCY  = '"$"#,##0.00';
    private const PERCENT   = '0.00"%"';
    private const INTEGER   = '#,##0';

    public function buildFromSnapshot(Period $period, PeriodSummary $summary, array $snap): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Radiografía ' . $period->label)
            ->setCreator('Sistema de Reportes')
            ->setSubject('Radiografía Financiera')
            ->setDescription('Generado automáticamente — sin plantilla');

        $this->buildResumenSheet($spreadsheet, $period, $snap);
        $this->buildProductosSheet($spreadsheet, $period, $snap);
        $this->buildSucursalesSheet($spreadsheet, $period, $snap);
        $this->buildEmpleadosGestoresSheet($spreadsheet, $period, $snap);
        $this->buildCarteraSheet($spreadsheet, $period, $snap);
        $this->buildGastosSheet($spreadsheet, $period, $snap);
        $this->buildMetadataSheet($spreadsheet, $period, $snap);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /** @deprecated use buildFromSnapshot */
    public function build(Period $period, PeriodSummary $summary): Spreadsheet
    {
        // Stub to avoid breaking existing callers until fully migrated
        $snap = (new RadiographySnapshotBuilder())->build($period, $summary);
        return $this->buildFromSnapshot($period, $summary, $snap);
    }

    // ── HOJA 1: RESUMEN ──────────────────────────────────────────────────────

    private function buildResumenSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->getActiveSheet()->setTitle('RESUMEN');
        $sum   = $snap['summary'];
        $pay   = $snap['sections']['payroll'];

        $this->sheetTitle($sheet, 'A1:F1', 'RADIOGRAFÍA — ' . strtoupper($period->label));
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

    // ── HOJA 4: EMPLEADOS / GESTORES (fusionado) ─────────────────────────────

    private function buildEmpleadosGestoresSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('EMPLEADOS GESTORES');
        $rows  = $snap['sections']['employees_gestores'] ?? [];

        $this->sheetTitle($sheet, 'A1:O1', 'EMPLEADOS / GESTORES — ' . strtoupper($period->label));

        if (empty($rows)) {
            $sheet->setCellValue('A2', 'Sin datos de empleados/gestores para este periodo.');
            return;
        }

        $this->colHeaders($sheet, 2, [
            'A' => 'EMPLEADO / GESTOR',
            'B' => 'CÓDIGO',
            'C' => 'SUCURSAL',
            'D' => 'RUTA',
            'E' => 'PAGOS NÓMINA',
            'F' => 'BONOS',
            'G' => 'DESCUENTOS',
            'H' => 'NETO NÓMINA',
            'I' => 'COLOCACIÓN',
            'J' => 'OPS',
            'K' => 'RECUPERACIÓN',
            'L' => 'CARTERA',
            'M' => 'C. VENCIDA',
            'N' => 'MORA %',
            'O' => 'GASTOS',
        ]);

        $r = 3;
        foreach ($rows as $i => $emp) {
            $sheet->setCellValue("A{$r}", $emp['name']);
            $sheet->setCellValue("B{$r}", $emp['code'] ?? '');
            $sheet->setCellValue("C{$r}", $emp['branch']);
            $sheet->setCellValue("D{$r}", $emp['route'] ?? '');
            $sheet->setCellValue("E{$r}", $emp['pagos']);
            $sheet->setCellValue("F{$r}", $emp['bonos']);
            $sheet->setCellValue("G{$r}", $emp['descuentos']);
            $sheet->setCellValue("H{$r}", $emp['neto']);
            $sheet->setCellValue("I{$r}", $emp['colocacion']);
            $sheet->setCellValue("J{$r}", $emp['operaciones']);
            $sheet->setCellValue("K{$r}", $emp['recuperacion']);
            $sheet->setCellValue("L{$r}", $emp['cartera']);
            $sheet->setCellValue("M{$r}", $emp['vencida']);
            $sheet->setCellValue("N{$r}", $emp['mora']);
            $sheet->setCellValue("O{$r}", $emp['gastos']);
            $this->dataRow($sheet, "A{$r}:O{$r}", $i % 2 === 0);
            foreach (['E', 'F', 'G', 'H', 'I', 'K', 'L', 'M', 'O'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("J{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
            $sheet->getStyle("N{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("N{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ((float)$emp['mora'] > 25) {
                $sheet->getStyle("N{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
            }
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(14);
        $sheet->getColumnDimension('C')->setWidth(22);
        $sheet->getColumnDimension('D')->setWidth(18);
        foreach (['E', 'F', 'G', 'H', 'I', 'K', 'L', 'M', 'O'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
        $sheet->getColumnDimension('J')->setWidth(8);
        $sheet->getColumnDimension('N')->setWidth(10);
        $sheet->setAutoFilter("A2:O2");
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

    // ── HOJA 6: GASTOS (expandido) ──────────────────────────────────────────

    private function buildGastosSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet  = $ss->createSheet()->setTitle('GASTOS');
        $exp    = $snap['sections']['expenses_detail'] ?? [];
        $total  = (float)($exp['total'] ?? 0);

        $this->sheetTitle($sheet, 'A1:D1', 'GASTOS — ' . strtoupper($period->label));

        if (empty($exp) || $total === 0.0) {
            $sheet->setCellValue('A2', 'Sin gastos registrados para este periodo.');
            return;
        }

        $r = 2;

        // Total global
        $sheet->mergeCells("B{$r}:D{$r}");
        $sheet->setCellValue("A{$r}", 'TOTAL GLOBAL');
        $sheet->setCellValue("B{$r}", $total);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // Por categoría
        $byCategory = $exp['byCategory'] ?? [];
        if (!empty($byCategory)) {
            $this->sectionHeader($sheet, "A{$r}:D{$r}", 'POR CATEGORÍA');
            $r++;
            $this->colHeaders($sheet, $r, ['A' => 'CATEGORÍA', 'B' => 'REGISTROS', 'C' => 'TOTAL', 'D' => '']);
            $r++;
            foreach ($byCategory as $i => $d) {
                $sheet->setCellValue("A{$r}", $d['categoria']);
                $sheet->setCellValue("B{$r}", $d['count']);
                $sheet->setCellValue("C{$r}", $d['total']);
                $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $r++;
            }
            $r++;
        }

        // Por sucursal
        $byBranch = $exp['byBranch'] ?? [];
        if (!empty($byBranch)) {
            $this->sectionHeader($sheet, "A{$r}:D{$r}", 'POR SUCURSAL');
            $r++;
            $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'REGISTROS', 'C' => 'TOTAL', 'D' => '']);
            $r++;
            foreach ($byBranch as $i => $d) {
                $sheet->setCellValue("A{$r}", $d['sucursal']);
                $sheet->setCellValue("B{$r}", $d['count']);
                $sheet->setCellValue("C{$r}", $d['total']);
                $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $r++;
            }
            $r++;
        }

        // Por concepto
        $byConcept = $exp['byConcept'] ?? [];
        if (!empty($byConcept)) {
            $this->sectionHeader($sheet, "A{$r}:D{$r}", 'POR CONCEPTO');
            $r++;
            $this->colHeaders($sheet, $r, ['A' => 'CONCEPTO', 'B' => 'REGISTROS', 'C' => 'TOTAL', 'D' => '']);
            $r++;
            foreach ($byConcept as $i => $d) {
                $sheet->setCellValue("A{$r}", $d['concepto']);
                $sheet->setCellValue("B{$r}", $d['count']);
                $sheet->setCellValue("C{$r}", $d['total']);
                $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $r++;
            }
            $r++;
        }

        // Por empleado
        $byEmployee = $exp['byEmployee'] ?? [];
        if (!empty($byEmployee)) {
            $this->sectionHeader($sheet, "A{$r}:D{$r}", 'POR EMPLEADO / GESTOR');
            $r++;
            $this->colHeaders($sheet, $r, ['A' => 'EMPLEADO', 'B' => 'SUCURSAL', 'C' => 'TOTAL', 'D' => '']);
            $r++;
            foreach ($byEmployee as $i => $d) {
                $sheet->setCellValue("A{$r}", $d['empleado']);
                $sheet->setCellValue("B{$r}", $d['sucursal']);
                $sheet->setCellValue("C{$r}", $d['total']);
                $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $r++;
            }
        }

        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->getColumnDimension('D')->setWidth(4);
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
}
