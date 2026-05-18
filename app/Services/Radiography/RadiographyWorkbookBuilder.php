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

        // Sheet order: GLOBAL first, then branch sheets, SIN ASIGNAR, PRODUCTOS
        $this->buildGlobalSheet($spreadsheet, $period, $snap);
        $this->buildBranchSheets($spreadsheet, $period, $snap);
        $this->buildSinAsignarSheet($spreadsheet, $period, $snap);
        $this->buildProductosSheet($spreadsheet, $period, $snap);

        // Remove default empty sheet if it exists
        if ($spreadsheet->getSheetCount() > 1) {
            try {
                $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName('Worksheet'));
                if ($idx !== null) $spreadsheet->removeSheetByIndex($idx);
            } catch (\Throwable) {}
        }

        $spreadsheet->setActiveSheetIndex(0); // GLOBAL is already first

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
        $sheet   = $ss->getActiveSheet()->setTitle('GLOBAL');
        $sum     = $snap['summary'];
        $pay     = $snap['sections']['payroll'];
        $buckets = $snap['sections']['portfolio_buckets'] ?? [];
        $loans   = $snap['sections']['interbranch_loans'] ?? [];
        $expDet  = $snap['sections']['expenses_detail']   ?? [];
        $expMx   = $snap['sections']['expenses_matrix']   ?? [];
        $funding = $snap['sections']['corporate_funding'] ?? [];
        $payBC   = $snap['sections']['payroll_by_branch_concept'] ?? [];

        $this->sheetTitle($sheet, 'A1:C1', 'RADIOGRAFÍA GLOBAL — ' . strtoupper($period->label));
        $sheet->mergeCells('A2:C2');
        $sheet->setCellValue('A2', 'Periodo: ' . ($period->code ?: $period->id) . '  |  Generado: ' . $snap['generated_at']);
        $this->metaStyle($sheet, 'A2:C2');

        $this->colHeaders($sheet, 3, ['A' => 'MÉTRICA', 'B' => 'VALOR', 'C' => '%']);

        // Helper: write a section block; returns next row
        $writeRows = function (int $startRow, array $items) use ($sheet): int {
            $r = $startRow;
            foreach ($items as $i => [$label, $value, $fmt, $pct]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $value);
                $sheet->setCellValue("C{$r}", $pct);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $value);
                if ($pct !== '' && $pct !== null) {
                    $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
                    $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                }
                $r++;
            }
            return $r;
        };

        // Prefer BranchRadiographyCalculator (GLOBAL = suma de sucursales) over legacy summary
        $brCalcGlobal = $snap['branch_radiography']['global'] ?? null;

        $carteraTotal = $brCalcGlobal ? (float)$brCalcGlobal['valor_cartera'] : (float)$sum['portfolio_total'];
        $recTotal     = $brCalcGlobal ? (float)$brCalcGlobal['recuperacion_total'] : (float)$sum['recovery_total'];
        $colTotal     = $brCalcGlobal ? (float)$brCalcGlobal['colocacion'] : (float)$sum['placement_total'];
        $excedentes   = $brCalcGlobal ? (float)$brCalcGlobal['excedentes'] : (float)($funding['total'] ?? 0);

        // Mora buckets — prefer calculator; fallback to legacy portfolio_buckets
        if ($brCalcGlobal) {
            $mora0_30   = (float)$brCalcGlobal['mora_0_30'];
            $mora31_60  = (float)$brCalcGlobal['mora_31_60'];
            $mora61_90  = (float)$brCalcGlobal['mora_61_90'];
            $mora91_120 = (float)$brCalcGlobal['mora_91_120'];
            $mora120p   = (float)$brCalcGlobal['mora_120_plus'];
        } else {
            $bucket = fn (string $label) => collect($buckets)->firstWhere('label', $label);
            $mora0_30   = (float)($bucket('Mora 1-30')['vencida']   ?? 0);
            $mora31_60  = (float)($bucket('Mora 31-60')['vencida']  ?? 0);
            $mora61_90  = (float)($bucket('Mora 61-90')['vencida']  ?? 0);
            $mora91_120 = (float)($bucket('Mora 91-120')['vencida'] ?? 0);
            $mora120p   = 0.0;
        }
        $moraTotal = $mora0_30 + $mora31_60 + $mora61_90 + $mora91_120 + $mora120p;

        $r = 4;

        // ── 1. Métricas Generales ─────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '1. MÉTRICAS GENERALES');
        $r++;
        $r = $writeRows($r, [
            ['Valor cartera global',         $carteraTotal,  'currency', ''],
            ['Otorgamientos',                $colTotal,      'currency', ''],
            ['Recuperación total',           $recTotal,      'currency', ''],
            ['Mora de 0 a 30 días',          $mora0_30,      'currency', $carteraTotal > 0 ? round($mora0_30 / $carteraTotal * 100, 2) : ''],
            ['Mora de 31 a 60 días',         $mora31_60,     'currency', $carteraTotal > 0 ? round($mora31_60 / $carteraTotal * 100, 2) : ''],
            ['Mora de 61 a 90 días',         $mora61_90,     'currency', $carteraTotal > 0 ? round($mora61_90 / $carteraTotal * 100, 2) : ''],
            ['Mora de 91 a 120 días',        $mora91_120,    'currency', $carteraTotal > 0 ? round($mora91_120 / $carteraTotal * 100, 2) : ''],
            ['Mora total',                   $moraTotal,     'currency', $carteraTotal > 0 ? round($moraTotal / $carteraTotal * 100, 2) : ''],
            ['Envío de utilidad a corporativo', $excedentes, 'currency', ''],
        ]);
        $r++;

        // ── 2. Ingresos ───────────────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '2. INGRESOS');
        $r++;
        $ingrCapital   = $brCalcGlobal ? (float)$brCalcGlobal['capital_recuperado']  : 0.0;
        $ingrInteres   = $brCalcGlobal ? (float)$brCalcGlobal['interes_recuperado']  : 0.0;
        $ingrImpuesto  = $brCalcGlobal ? (float)$brCalcGlobal['impuesto_recuperado'] : 0.0;
        $ingrMuletas   = $brCalcGlobal ? (float)$brCalcGlobal['charges']             : 0.0;
        $ingrCargosIni = $brCalcGlobal ? (float)$brCalcGlobal['cargos_inicio']       : 0.0;
        $ingrComAper   = $brCalcGlobal ? (float)$brCalcGlobal['comision_apertura']   : 0.0;
        $ingrVencidos  = 0.0; // no hay cruce confiable con +90 días
        $ingrTotal     = $ingrCapital + $ingrInteres + $ingrImpuesto + $ingrMuletas + $ingrCargosIni + $ingrComAper + $ingrVencidos;

        $ingrItems = [
            ['Capital por producto',                   $ingrCapital,   'currency'],
            ['Intereses por producto',                 $ingrInteres,   'currency'],
            ['Impuestos por producto',                 $ingrImpuesto,  'currency'],
            ['Multas por producto',                    $ingrMuletas,   'currency'],
            ['Cargos al inicio',                       $ingrCargosIni, 'currency'],
            ['Comisión por apertura',                  $ingrComAper,   'currency'],
            ['Ingreso de créditos vencidos (+90 días)',$ingrVencidos,  'currency'],
        ];
        $ingrIdx = 0;
        foreach ($ingrItems as [$ingrLabel, $ingrVal, $ingrFmt]) {
            if ($ingrVal == 0.0) continue;
            $sheet->setCellValue("A{$r}", $ingrLabel);
            $sheet->setCellValue("B{$r}", $ingrVal);
            $sheet->setCellValue("C{$r}", '');
            $this->dataRow($sheet, "A{$r}:C{$r}", $ingrIdx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $ingrFmt, $ingrVal);
            $ingrIdx++;
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Total Ingresos');
        $sheet->setCellValue("B{$r}", $ingrTotal);
        $this->totalsRow($sheet, "A{$r}:C{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── 3. Gastos Operativos ──────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '3. GASTOS OPERATIVOS');
        $r++;
        // Source: BranchRadiographyCalculator gastos_detalle (GLOBAL = suma 13 sucursales, excluye Norte y Corporativo)
        $globalGastosDetalle = (array)($brCalcGlobal['gastos_detalle'] ?? []);
        $getGDet = fn (string $name) => (float)($globalGastosDetalle[$name] ?? 0.0);

        $gastosOp = [
            'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
            'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
            'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
            'Publicidad','Mecánicos','Servicios de Motocicletas','Software Póliza Anual','Pólizas',
            'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
            'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
            'Gastos legales','IMSS','Financiamiento de Motos',
        ];
        $gastosOpTotal = 0.0;
        $gastosOpIdx = 0;
        foreach ($gastosOp as $gasto) {
            $val = $getGDet($gasto);
            $gastosOpTotal += $val;
            if ($val == 0.0) continue; // skip zero rows
            $sheet->setCellValue("A{$r}", $gasto);
            $sheet->setCellValue("B{$r}", $val);
            $sheet->setCellValue("C{$r}", '');
            $this->dataRow($sheet, "A{$r}:C{$r}", $gastosOpIdx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", 'currency', $val);
            $gastosOpIdx++;
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Total Gastos Operativos');
        $sheet->setCellValue("B{$r}", $gastosOpTotal);
        $this->totalsRow($sheet, "A{$r}:C{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── 4. Nómina y Capital Humano ────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '4. NÓMINA Y CAPITAL HUMANO');
        $r++;
        // Source: BranchRadiographyCalculator (GLOBAL = suma 13 sucursales + SIN ASIGNAR)
        $globalNomina    = $brCalcGlobal ? (float)$brCalcGlobal['nomina_total']     : (float)$pay['pagos'];
        $globalComisions = $brCalcGlobal ? (float)$brCalcGlobal['comisiones']       : 0.0;
        $globalBonos     = $brCalcGlobal ? (float)$brCalcGlobal['bonos']            : (float)$pay['bonos'];
        $globalVacac     = $brCalcGlobal ? (float)$brCalcGlobal['vacaciones']       : 0.0;
        $globalPrimaVac  = $brCalcGlobal ? (float)$brCalcGlobal['prima_vacacional'] : 0.0;
        $globalNomDet    = (array)($brCalcGlobal['nomina_detalle'] ?? []);

        // Ordered display list: scalars first, then detail items
        $nomDisplayOrder = [
            'Nómina'           => $globalNomina,
            'Comisiones'       => $globalComisions,
            'Vacaciones'       => $globalVacac,
            'Prima vacacional' => $globalPrimaVac,
            'Bonos'            => $globalBonos,
        ];
        // Append nomina_detalle items in a canonical order
        $nomDetalleOrder = [
            'IMSS','Descuentos Infonavit','Finiquito','Gastos médicos',
            'Gasolina','Financiamiento de Motos','Financiamiento de Motos (desc.)',
            'Descuento Servicios Moto','Financiamiento Celular','Cascos',
            'Descuento de uniformes','Pensión Alimenticia','Préstamo Personal',
            'Anticipo de nómina','Otros conceptos nómina','Otros descuentos NOI',
        ];
        foreach ($nomDetalleOrder as $detKey) {
            if (isset($globalNomDet[$detKey]) && $globalNomDet[$detKey] > 0) {
                $nomDisplayOrder[$detKey] = $globalNomDet[$detKey];
            }
        }
        // Any remaining detail items not in the ordered list
        foreach ($globalNomDet as $detKey => $detVal) {
            if (!isset($nomDisplayOrder[$detKey]) && $detVal > 0) {
                $nomDisplayOrder[$detKey] = $detVal;
            }
        }

        $nomTotal = 0.0;
        $nomIdx   = 0;
        foreach ($nomDisplayOrder as $nomName => $nomVal) {
            if ($nomVal == 0) continue;
            $sheet->setCellValue("A{$r}", $nomName);
            $sheet->setCellValue("B{$r}", $nomVal);
            $sheet->setCellValue("C{$r}", '');
            $this->dataRow($sheet, "A{$r}:C{$r}", $nomIdx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", 'currency', $nomVal);
            $nomTotal += $nomVal;
            $nomIdx++;
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Total Nómina y Capital Humano');
        $sheet->setCellValue("B{$r}", $nomTotal);
        $this->totalsRow($sheet, "A{$r}:C{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── 5. Préstamos Intersucursales ──────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '5. PRÉSTAMOS INTERSUCURSALES');
        $r++;
        $loanTotal = (float)($loans['total'] ?? 0);
        $brGlobalFondea = $brCalcGlobal ? (float)$brCalcGlobal['prestamos_fondea'] : $loanTotal;
        $r = $writeRows($r, [
            ['Activos (fondea)',  $brGlobalFondea, 'currency', ''],
            ['Pasivos (recibe)',  $brGlobalFondea, 'currency', ''],
            ['Total',            $brGlobalFondea, 'currency', ''],
        ]);
        $r++;

        // ── 6. Índice de rotación de personal ────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '6. ÍNDICE DE ROTACIÓN DE PERSONAL');
        $r++;
        $r = $writeRows($r, [
            ['N° de personas que dejaron la empresa', 0, 'integer', ''],
            ['Promedio de personas en el periodo',    0, 'integer', ''],
            ['Índice de rotación',                   0, 'percent',  ''],
        ]);
        $r++;

        // ── 7. Análisis de Tendencias y Proyecciones ──────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '7. ANÁLISIS DE TENDENCIAS Y PROYECCIONES');
        $r++;
        $gastosTotal  = $gastosOpTotal;
        $utilidad     = $recTotal - $gastosTotal - $nomTotal;
        $excGlobal    = $brCalcGlobal ? (float)$brCalcGlobal['excedentes'] : $excedentes;
        $mora0_30g    = $brCalcGlobal ? (float)$brCalcGlobal['mora_0_30']    : $mora0_30;
        $mora31_60g   = $brCalcGlobal ? (float)$brCalcGlobal['mora_31_60']   : $mora31_60;
        $mora61_90g   = $brCalcGlobal ? (float)$brCalcGlobal['mora_61_90']   : $mora61_90;
        $mora91_120g  = $brCalcGlobal ? (float)$brCalcGlobal['mora_91_120']  : $mora91_120;
        $r = $writeRows($r, [
            ['Saldo inicial en caja',              0,              'currency', ''],
            ['Ingresos Totales',                   $recTotal,      'currency', ''],
            ['Otorgamientos',                      $colTotal,      'currency', ''],
            ['Gastos Totales',                     $gastosTotal,   'currency', ''],
            ['Utilidad',                           $utilidad,      'currency', ''],
            ['Saldo final en caja',                0,              'currency', ''],
            ['Préstamos inter sucursales',         $brGlobalFondea,'currency', ''],
            ['Envío de utilidad a corporativo',    $excGlobal,     'currency', ''],
            ['Diferencia',                         0,              'currency', ''],
            ['Mora de 0 a 30 días',                $mora0_30g,     'currency', ''],
            ['Mora de 31 a 60 días',               $mora31_60g,    'currency', ''],
            ['Mora de 61 a 90 días',               $mora61_90g,    'currency', ''],
            ['Mora de 91 a 120 días',              $mora91_120g,   'currency', ''],
            ['Valor cartera',                      $carteraTotal,  'currency', ''],
        ]);
        $r++;

        // ── 8. Utilidad ───────────────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '8. UTILIDAD');
        $r++;
        $r = $writeRows($r, [
            ['Total', $utilidad, 'currency', ''],
        ]);
        $r++;

        // ── 9. Saldo Total Acumulado Cuentas ──────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '9. SALDO TOTAL ACUMULADO CUENTAS');
        $r++;
        $r = $writeRows($r, [
            ['Total', 0, 'currency', ''],
        ]);
        $r++;

        // ── 10. Observaciones y Notas ─────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '10. OBSERVACIONES Y NOTAS');
        $r++;
        foreach ([
            'Comentarios sobre el desempeño financiero:',
            'Factores de riesgo y oportunidades:',
            'Recomendaciones para optimización financiera:',
        ] as $i => $obsLabel) {
            $sheet->setCellValue("A{$r}", $obsLabel);
            $sheet->setCellValue("B{$r}", '');
            $sheet->mergeCells("B{$r}:C{$r}");
            $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
            $sheet->getStyle("A{$r}")->getFont()->setBold(false);
            $sheet->getRowDimension($r)->setRowHeight(24);
            $r++;
        }
        $r++;

        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->freezePane('A4');

        // Hyperlinks to each sucursal tab
        $r += 2;
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '11. DETALLE POR SUCURSAL');
        $r++;
        $operativeSucursales = [
            'ATLACOMULCO','ATLIXCO','CORDOBA','CUERNAVACA','HUAMANTLA',
            'IXTLAHUACA','MIACATLAN','ORIZABA','SAN JUAN DEL RÍO',
            'SAN LUIS POTOSI','TENANGO DEL VALLE','TLAXCALA','TULA',
        ];
        foreach ($operativeSucursales as $i => $suc) {
            $tabName = preg_replace('/[\/\\\?\*\[\]:]/', '-', $suc) ?? $suc;
            if (mb_strlen($tabName) > 28) {
                $tabName = mb_substr($tabName, 0, 28);
            }
            $sheet->setCellValue("A{$r}", $suc);
            $sheet->getCell("A{$r}")->getHyperlink()->setUrl("sheet://{$tabName}!A1");
            $sheet->getStyle("A{$r}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);
            $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
            $r++;
        }
    }

    // ── PER-BRANCH SHEETS ────────────────────────────────────────────────────

    /**
     * Creates one worksheet per real branch with the same financial structure as GLOBAL.
     * Branches are derived from the snapshot branches list (already filtered to real branches).
     */
    private function buildBranchSheets(Spreadsheet $ss, Period $period, array $snap): void
    {
        $branches    = $snap['sections']['branches']                    ?? [];
        $payBC       = $snap['sections']['payroll_by_branch_concept']   ?? [];
        $expMx       = $snap['sections']['expenses_matrix']             ?? [];
        $moraBranch  = $snap['sections']['mora_by_branch']              ?? [];
        $recDet      = $snap['sections']['recovery_detail']['by_branch'] ?? [];
        $portProd    = $snap['sections']['portfolio_by_branch_product']  ?? [];
        $placeProd   = $snap['sections']['placement_by_branch_product']  ?? [];
        $loans       = $snap['sections']['interbranch_loans']            ?? [];
        $funding     = $snap['sections']['corporate_funding']            ?? [];

        if (empty($branches)) {
            return;
        }

        // Index supporting data by normalized branch name
        $morIdx  = [];
        foreach ($moraBranch as $row) {
            $morIdx[strtoupper(trim($row['branch']))] = $row;
        }
        $recIdx  = [];
        foreach ($recDet as $row) {
            $recIdx[strtoupper(trim($row['branch']))] = $row;
        }

        // Excel tab name: max 31 chars, no special chars
        $tabName = function (string $name): string {
            $name = preg_replace('/[\/\\\?\*\[\]:]/', '-', $name) ?? $name;
            if (mb_strlen($name) > 28) {
                $name = mb_substr($name, 0, 28);
            }
            return $name;
        };

        // BranchRadiographyCalculator data indexed by sucursal name (UPPERCASE)
        $brCalcBranches = [];
        foreach ($snap['branch_radiography']['branches'] ?? [] as $b) {
            $brCalcBranches[strtoupper(trim($b['sucursal']))] = $b;
        }

        // Use calculator output to drive the 13 sucursal sheets when available;
        // fall back to legacy $branches for sheets we can't resolve.
        $resolver = app(\App\Services\BranchResolverService::class);
        $sheetSourceBranches = !empty($brCalcBranches)
            ? array_map(fn ($b) => ['nombre' => $b['sucursal']], array_values($brCalcBranches))
            : $branches;

        foreach ($sheetSourceBranches as $branchData) {
            $branchName = $branchData['nombre'] ?? ($branchData['name'] ?? 'Sin nombre');

            // Only create sheets for the 13 operative branches; skip AGS if it has no cartera/col/rec
            if (!$resolver->isSheetBranch($branchName)) {
                continue;
            }

            $brUp = strtoupper(trim($branchName));

            // Prefer calculator data; fall back to legacy branchData
            $calc = $brCalcBranches[$brUp] ?? null;

            if ($calc) {
                $carteraB  = (float)$calc['valor_cartera'];
                $colB      = (float)$calc['colocacion'];
                $recB      = (float)$calc['recuperacion_total'];
                $gastosB   = (float)$calc['gastos_operativos'];
                $mora0_30  = (float)$calc['mora_0_30'];
                $mora31_60 = (float)$calc['mora_31_60'];
                $mora61_90 = (float)$calc['mora_61_90'];
                $mora91120 = (float)$calc['mora_91_120'];
                $mora120p  = (float)$calc['mora_120_plus'];
                $vencidaB  = $mora0_30 + $mora31_60 + $mora61_90 + $mora91120 + $mora120p;
                $nomCalc   = (float)$calc['nomina_total'];
                $excedCalc = (float)$calc['excedentes'];
                $fondeoCalc= (float)$calc['prestamos_fondea'];
            } else {
                $legacyData = collect($branches)->first(fn ($b) => strtoupper(trim($b['nombre'] ?? $b['name'] ?? '')) === $brUp) ?? [];
                $carteraB  = (float)($legacyData['cartera']      ?? 0);
                $vencidaB  = (float)($legacyData['vencida']      ?? 0);
                $colB      = (float)($legacyData['colocacion']    ?? 0);
                $recB      = (float)($legacyData['recuperacion']  ?? 0);
                $gastosB   = (float)($legacyData['gastos']        ?? 0);
                $morRow    = $morIdx[$brUp] ?? [];
                $mora0_30  = (float)($morRow['mora_1_30']  ?? 0);
                $mora31_60 = (float)($morRow['mora_31_60'] ?? 0);
                $mora61_90 = (float)($morRow['mora_61_90'] ?? 0);
                $mora91120 = (float)($morRow['mora_91_120'] ?? 0);
                $mora120p  = 0.0;
                $nomCalc   = 0.0;
                $excedCalc = 0.0;
                $fondeoCalc= 0.0;
            }

            $moraPct = $carteraB > 0 ? round($vencidaB / $carteraB * 100, 2) : 0.0;
            $recRow  = $recIdx[$brUp] ?? [];

            $isSJR = $brUp === 'SAN JUAN DEL RÍO';

            $sheet = $ss->createSheet()->setTitle($tabName($branchName));

            // Title row — SJR gets closed-branch note
            $titleText = strtoupper($branchName) . ' — ' . strtoupper($period->label);
            if ($isSJR) {
                $titleText .= ' — SUCURSAL CERRADA / CARTERA EN RECUPERACIÓN';
            }
            $this->sheetTitle($sheet, 'A1:D1', $titleText);

            // Navigation: back link to GLOBAL
            $sheet->setCellValue('A2', '← GLOBAL');
            $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
            $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);
            $sheet->setCellValue('B2', 'Periodo: ' . ($period->code ?: $period->id));
            $this->metaStyle($sheet, 'B2:C2');
            $sheet->mergeCells('B2:C2');

            $this->colHeaders($sheet, 3, ['A' => 'MÉTRICA', 'B' => 'VALOR', 'C' => '%']);
            $recRow    = $recIdx[$brUp] ?? [];

            // Payroll for this branch
            $branchPayroll = $payBC[$branchName] ?? $payBC[$brUp] ?? [];
            $nomTotal      = 0.0;
            foreach ($branchPayroll as $concept => $amount) {
                $k = strtoupper(trim($concept));
                if (!str_contains($k, 'DESCUENTO') && !str_contains($k, 'DEDUCCION')) {
                    $nomTotal += (float)$amount;
                }
            }

            // Gastos from expenses_matrix for this branch
            $expMatrix  = $expMx['matrix']   ?? [];
            $expBranches = $expMx['branches'] ?? [];
            $branchKey  = '';
            foreach ($expBranches as $eb) {
                if (strtoupper($eb) === $brUp) { $branchKey = $eb; break; }
            }
            $branchExpByCategory = [];
            if ($branchKey !== '') {
                foreach ($expMatrix as $cat => $byBranch) {
                    $v = $byBranch[$branchKey] ?? 0.0;
                    if ($v > 0) $branchExpByCategory[$cat] = $v;
                }
            }
            $getCat = fn (string $name) => ($branchExpByCategory[strtoupper($name)] ?? 0.0);

            $loanB   = 0.0;
            foreach ($loans['fondea'] ?? [] as $lRow) {
                if (strtoupper($lRow['branch']) === $brUp) { $loanB += (float)$lRow['total']; break; }
            }

            $nomTotalCalc = $calc
                ? ((float)$calc['nomina_total'] + (float)$calc['comisiones'] + (float)$calc['bonos'])
                : $nomTotal;
            $utilidad = $recB - $gastosB - $nomTotalCalc;

            $r = 4;

            // 1. Métricas Generales
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '1. MÉTRICAS GENERALES');
            $r++;
            $metricItems = [
                ['Valor cartera',          $carteraB,   'currency', ''],
                ['Otorgamientos',          $colB,       'currency', ''],
                ['Recuperación total',     $recB,       'currency', ''],
                ['Mora de 0 a 30 días',    $mora0_30,   'currency', $carteraB > 0 ? round($mora0_30  / $carteraB * 100, 2) : ''],
                ['Mora de 31 a 60 días',   $mora31_60,  'currency', $carteraB > 0 ? round($mora31_60 / $carteraB * 100, 2) : ''],
                ['Mora de 61 a 90 días',   $mora61_90,  'currency', $carteraB > 0 ? round($mora61_90 / $carteraB * 100, 2) : ''],
                ['Mora de 91 a 120 días',  $mora91120,  'currency', $carteraB > 0 ? round($mora91120 / $carteraB * 100, 2) : ''],
                ['Mora 120+ días',         $mora120p,   'currency', $carteraB > 0 ? round($mora120p  / $carteraB * 100, 2) : ''],
                ['Mora total',             $vencidaB,   'currency', $moraPct],
                ['Gastos operativos',      $gastosB,    'currency', ''],
                ['Nómina (P001)',           $nomCalc,    'currency', ''],
                ['Excedentes corp.',       $excedCalc,  'currency', ''],
                ['Préstamos intersuc.',    $fondeoCalc, 'currency', ''],
            ];
            foreach ($metricItems as $i => [$label, $value, $fmt, $pct]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $value);
                $sheet->setCellValue("C{$r}", $pct);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $value);
                if ($pct !== '' && $pct !== null) {
                    $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
                    $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                }
                $r++;
            }
            $r++;

            // 2. Ingresos (per branch from calculator)
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '2. INGRESOS');
            $r++;
            $bIngrCapital  = $calc ? (float)$calc['capital_recuperado']  : 0.0;
            $bIngrInteres  = $calc ? (float)$calc['interes_recuperado']  : 0.0;
            $bIngrImpuesto = $calc ? (float)$calc['impuesto_recuperado'] : 0.0;
            $bIngrMuletas  = $calc ? (float)$calc['charges']             : 0.0;
            $bIngrTotal    = $bIngrCapital + $bIngrInteres + $bIngrImpuesto + $bIngrMuletas;
            $bIngrItems = [
                'Capital por producto'                    => $bIngrCapital,
                'Intereses por producto'                  => $bIngrInteres,
                'Impuestos por producto'                  => $bIngrImpuesto,
                'Multas por producto'                     => $bIngrMuletas,
                'Cargos al inicio'                        => 0.0,
                'Comisión por apertura'                   => 0.0,
                'Ingreso de créditos vencidos (+90 días)' => 0.0,
            ];
            $bIngrIdx = 0;
            foreach ($bIngrItems as $bILabel => $bIVal) {
                if ($bIVal == 0.0) continue;
                $sheet->setCellValue("A{$r}", $bILabel);
                $sheet->setCellValue("B{$r}", $bIVal);
                $this->dataRow($sheet, "A{$r}:C{$r}", $bIngrIdx % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $bIVal);
                $bIngrIdx++;
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'Total Ingresos');
            $sheet->setCellValue("B{$r}", $bIngrTotal);
            $this->totalsRow($sheet, "A{$r}:C{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r += 2;

            // 3. Gastos Operativos
            // Source: BranchRadiographyCalculator gastos_detalle — each row is a real amount,
            // total = sum of visible rows (no fallback to a phantom total).
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '3. GASTOS OPERATIVOS');
            $r++;
            $branchGDetalle = (array)($calc['gastos_detalle'] ?? []);
            $getBGDet = fn (string $name) => (float)($branchGDetalle[$name] ?? 0.0);
            $gastosOpList = [
                'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
                'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
                'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
                'Publicidad','Mecánicos','Servicios de Motocicletas','Software Póliza Anual','Pólizas',
                'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
                'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
                'Gastos legales','IMSS','Financiamiento de Motos',
            ];
            $gopTotal = 0.0;
            $gopIdx = 0;
            foreach ($gastosOpList as $gastoName) {
                $val = $getBGDet($gastoName);
                $gopTotal += $val;
                if ($val == 0.0) continue; // skip zero rows
                $sheet->setCellValue("A{$r}", $gastoName);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:C{$r}", $gopIdx % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $val);
                $gopIdx++;
                $r++;
            }
            // Total must equal the sum of visible rows — no fallback to external total
            $sheet->setCellValue("A{$r}", 'Total Gastos Operativos');
            $sheet->setCellValue("B{$r}", $gopTotal);
            $this->totalsRow($sheet, "A{$r}:C{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r += 2;

            // 4. Nómina y Capital Humano — expanded (same structure as GLOBAL)
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '4. NÓMINA Y CAPITAL HUMANO');
            $r++;
            $brNomina    = $calc ? (float)$calc['nomina_total']     : 0.0;
            $brComisions = $calc ? (float)$calc['comisiones']       : 0.0;
            $brBonos     = $calc ? (float)$calc['bonos']            : 0.0;
            $brVacac     = $calc ? (float)$calc['vacaciones']       : 0.0;
            $brPrimaVac  = $calc ? (float)$calc['prima_vacacional'] : 0.0;
            $brNomDet    = (array)($calc['nomina_detalle'] ?? []);

            $brNomDisplay = [
                'Nómina'           => $brNomina,
                'Comisiones'       => $brComisions,
                'Vacaciones'       => $brVacac,
                'Prima vacacional' => $brPrimaVac,
                'Bonos'            => $brBonos,
            ];
            $brNomDetalleOrder = [
                'IMSS','Descuentos Infonavit','Finiquito','Gastos médicos',
                'Gasolina','Financiamiento de Motos','Financiamiento de Motos (desc.)',
                'Descuento Servicios Moto','Financiamiento Celular','Cascos',
                'Descuento de uniformes','Pensión Alimenticia','Préstamo Personal',
                'Anticipo de nómina','Otros conceptos nómina','Otros descuentos NOI',
            ];
            foreach ($brNomDetalleOrder as $detKey) {
                if (isset($brNomDet[$detKey]) && $brNomDet[$detKey] > 0) {
                    $brNomDisplay[$detKey] = $brNomDet[$detKey];
                }
            }
            foreach ($brNomDet as $detKey => $detVal) {
                if (!isset($brNomDisplay[$detKey]) && $detVal > 0) {
                    $brNomDisplay[$detKey] = $detVal;
                }
            }

            $nomTotal2 = 0.0;
            $i2 = 0;
            foreach ($brNomDisplay as $nomName => $nomVal) {
                if ($nomVal == 0.0) continue;
                $sheet->setCellValue("A{$r}", $nomName);
                $sheet->setCellValue("B{$r}", $nomVal);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i2 % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $nomVal);
                $nomTotal2 += $nomVal;
                $i2++;
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'Total Nómina y Capital Humano');
            $sheet->setCellValue("B{$r}", $nomTotal2);
            $this->totalsRow($sheet, "A{$r}:C{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r += 2;

            // 5. Préstamos Intersucursales
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '5. PRÉSTAMOS INTERSUCURSALES');
            $r++;
            foreach ([
                ['Fondeo otorgado', $fondeoCalc, 'currency'],
                ['Total', $fondeoCalc, 'currency'],
            ] as $i => [$label, $val, $fmt]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $val);
                $r++;
            }
            $r++;

            // 6. Índice de rotación de personal (estructura siempre presente)
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '6. ÍNDICE DE ROTACIÓN DE PERSONAL');
            $r++;
            foreach ([
                ['N° de personas que dejaron la empresa', 0, 'integer'],
                ['Promedio de personas en el periodo',    0, 'integer'],
                ['Índice de rotación',                   0, 'percent'],
            ] as $i => [$label, $val, $fmt]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $val);
                $r++;
            }
            $r++;

            // 7. Análisis de Tendencias
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '7. ANÁLISIS DE TENDENCIAS Y PROYECCIONES');
            $r++;
            $brNomTotal  = $nomTotal2;
            $brUtilidad  = $recB - $gopTotal - $brNomTotal;
            $brFondeoB   = $calc ? (float)$calc['prestamos_fondea'] : $fondeoCalc;
            $brExcedCalc = $calc ? (float)$calc['excedentes']       : $excedCalc;
            foreach ([
                ['Saldo inicial en caja',              0,           'currency'],
                ['Ingresos Totales',                   $recB,       'currency'],
                ['Otorgamientos',                      $colB,       'currency'],
                ['Gastos Totales',                     $gopTotal,   'currency'],
                ['Utilidad',                           $brUtilidad, 'currency'],
                ['Saldo final en caja',                0,           'currency'],
                ['Préstamos inter sucursales',         $brFondeoB,  'currency'],
                ['Envío de utilidad a corporativo',    $brExcedCalc,'currency'],
                ['Diferencia',                         0,           'currency'],
                ['Mora de 0 a 30 días',                $mora0_30,   'currency'],
                ['Mora de 31 a 60 días',               $mora31_60,  'currency'],
                ['Mora de 61 a 90 días',               $mora61_90,  'currency'],
                ['Mora de 91 a 120 días',              $mora91120,  'currency'],
                ['Valor cartera',                      $carteraB,   'currency'],
            ] as $i => [$label, $val, $fmt]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $val);
                $r++;
            }
            $r++;

            // 8. Utilidad
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '8. UTILIDAD');
            $r++;
            $sheet->setCellValue("A{$r}", 'Total');
            $sheet->setCellValue("B{$r}", $brUtilidad);
            $this->dataRow($sheet, "A{$r}:C{$r}", true);
            $this->applyFmt($sheet, "B{$r}", 'currency', $brUtilidad);
            $r += 2;

            // 9. Saldo Total Acumulado Cuentas
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '9. SALDO TOTAL ACUMULADO CUENTAS');
            $r++;
            $sheet->setCellValue("A{$r}", 'Total');
            $sheet->setCellValue("B{$r}", 0);
            $this->dataRow($sheet, "A{$r}:C{$r}", true);
            $this->applyFmt($sheet, "B{$r}", 'currency', 0);
            $r += 2;

            // 10. Observaciones y Notas
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '10. OBSERVACIONES Y NOTAS');
            $r++;
            foreach ([
                'Comentarios sobre el desempeño financiero:',
                'Factores de riesgo y oportunidades:',
                'Recomendaciones para optimización financiera:',
            ] as $i => $obsLabel) {
                $sheet->setCellValue("A{$r}", $obsLabel);
                $sheet->setCellValue("B{$r}", '');
                $sheet->mergeCells("B{$r}:C{$r}");
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $sheet->getRowDimension($r)->setRowHeight(24);
                $r++;
            }
            $r++;

            $sheet->getColumnDimension('A')->setWidth(42);
            $sheet->getColumnDimension('B')->setWidth(22);
            $sheet->getColumnDimension('C')->setWidth(10);
            $sheet->freezePane('A4');
        }
    }

    // ── SIN ASIGNAR — empleados/gastos sin sucursal operativa ───────────────

    private function buildSinAsignarSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet  = $ss->createSheet()->setTitle('SIN ASIGNAR');
        $unassigned = $snap['branch_radiography']['unassigned'] ?? [];
        $empleados  = $unassigned['empleados']   ?? [];
        $gastos     = $unassigned['gastos_items'] ?? [];

        $label = strtoupper($period->label ?? ($period->code ?: 'Periodo'));
        $this->sheetTitle($sheet, 'A1:E1', 'SIN ASIGNAR — ' . $label);

        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);
        $sheet->setCellValue('B2', 'Empleados o conceptos sin sucursal asignada. Se muestran para control administrativo.');
        $this->metaStyle($sheet, 'B2:E2');
        $sheet->mergeCells('B2:E2');

        $r = 4;

        // ── Empleados sin sucursal ────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:E{$r}", '1. EMPLEADOS SIN SUCURSAL ASIGNADA');
        $r++;

        $this->colHeaders($sheet, $r, ['A' => 'EMPLEADO', 'B' => 'P001 SUELDO', 'C' => 'P002 COMISIONES', 'D' => 'BONOS', 'E' => 'NOTA']);
        $r++;

        if (empty($empleados)) {
            $sheet->setCellValue("A{$r}", 'Sin empleados sin asignar.');
            $sheet->mergeCells("A{$r}:E{$r}");
            $r++;
        } else {
            $totalP001 = 0.0;
            $totalP002 = 0.0;
            $totalBonos = 0.0;
            foreach ($empleados as $i => $emp) {
                $sheet->setCellValue("A{$r}", mb_substr((string)($emp['nombre'] ?? ''), 0, 45));
                $sheet->setCellValue("B{$r}", (float)($emp['p001'] ?? 0));
                $sheet->setCellValue("C{$r}", (float)($emp['p002'] ?? 0));
                $sheet->setCellValue("D{$r}", (float)($emp['bonos'] ?? 0));
                $sheet->setCellValue("E{$r}", $emp['fuente'] ?? '');
                $this->dataRow($sheet, "A{$r}:E{$r}", $i % 2 === 0);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $totalP001  += (float)($emp['p001'] ?? 0);
                $totalP002  += (float)($emp['p002'] ?? 0);
                $totalBonos += (float)($emp['bonos'] ?? 0);
                $r++;
            }
            // Totals row
            $sheet->setCellValue("A{$r}", 'TOTAL SIN ASIGNAR (empleados)');
            $sheet->setCellValue("B{$r}", $totalP001);
            $sheet->setCellValue("C{$r}", $totalP002);
            $sheet->setCellValue("D{$r}", $totalBonos);
            $sheet->setCellValue("E{$r}", 'Control administrativo');
            $this->totalsRow($sheet, "A{$r}:E{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r++;
        }

        $r++;

        // ── Gastos sin sucursal asignada ─────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:E{$r}", '2. GASTOS SIN SUCURSAL ASIGNADA');
        $r++;

        $this->colHeaders($sheet, $r, ['A' => 'CONCEPTO', 'B' => 'MONTO (REFERENCIA)', 'C' => 'ORIGEN', 'D' => '', 'E' => 'NOTA']);
        $r++;

        if (empty($gastos)) {
            $sheet->setCellValue("A{$r}", 'Sin gastos corporativos registrados.');
            $sheet->mergeCells("A{$r}:E{$r}");
            $r++;
        } else {
            $totalGastos = 0.0;
            foreach ($gastos as $i => $g) {
                $sheet->setCellValue("A{$r}", $g['concepto'] ?? '');
                $sheet->setCellValue("B{$r}", (float)($g['monto'] ?? 0));
                $sheet->setCellValue("C{$r}", $g['origen'] ?? '');
                $sheet->setCellValue("E{$r}", '');
                $this->dataRow($sheet, "A{$r}:E{$r}", $i % 2 === 0);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $totalGastos += (float)($g['monto'] ?? 0);
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'TOTAL GASTOS SIN SUCURSAL (referencia)');
            $sheet->setCellValue("B{$r}", $totalGastos);
            $sheet->setCellValue("C{$r}", '');
            $this->totalsRow($sheet, "A{$r}:E{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r++;
        }

        $r += 2;

        // ── Nota aclaratoria ─────────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:E{$r}", '3. NOTA ADMINISTRATIVA');
        $r++;
        $sheet->setCellValue("A{$r}", 'Estos registros se muestran para control administrativo y revisión posterior. Para asignar un empleado a una sucursal, contactar al administrador del sistema.');
        $sheet->mergeCells("A{$r}:E{$r}");
        $this->dataRow($sheet, "A{$r}:E{$r}", true);
        $sheet->getStyle("A{$r}:E{$r}")->getAlignment()->setWrapText(true);
        $sheet->getRowDimension($r)->setRowHeight(30);
        $r++;

        $sheet->getColumnDimension('A')->setWidth(50);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(18);
        $sheet->getColumnDimension('E')->setWidth(40);
        $sheet->freezePane('A4');
    }

    // ── ÍNDICE — se inserta en posición 0 después de crear todas las pestañas ─

    private function buildIndiceSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $indice = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($ss, 'ÍNDICE');
        $ss->addSheet($indice, 0);
        $ss->setActiveSheetIndex(0);
        $sheet = $ss->getActiveSheet();

        $brCalc    = $snap['branch_radiography'] ?? [];
        $branches  = $brCalc['branches'] ?? [];
        $global    = $brCalc['global']   ?? [];

        $label = strtoupper($period->label ?? ($period->code ?: 'Periodo'));

        $this->sheetTitle($sheet, 'A1:C1', 'ÍNDICE — RADIOGRAFÍA FINANCIERA ' . $label);

        $sheet->mergeCells('A2:C2');
        $sheet->setCellValue('A2', 'Generado: ' . ($snap['generated_at'] ?? date('d/m/Y H:i')));
        $this->metaStyle($sheet, 'A2:C2');

        $r = 4;
        $this->sectionHeader($sheet, "A{$r}:C{$r}", 'SECCIONES DEL INFORME');
        $r++;

        // Helper: write an index row with optional hyperlink
        $writeLink = function (int $row, string $nombre, string $sheetName, string $descripcion, bool $even) use ($sheet): void {
            $cell = $sheet->getCell("A{$row}");
            $cell->setValue($nombre);
            $cell->getHyperlink()->setUrl("sheet://{$sheetName}!A1");
            $sheet->getStyle("A{$row}")->getFont()->setColor(
                (new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))
            )->setUnderline(true);
            $sheet->setCellValue("B{$row}", $sheetName);
            $sheet->setCellValue("C{$row}", $descripcion);
            $this->dataRow($sheet, "A{$row}:C{$row}", $even);
        };

        // GLOBAL link
        $writeLink($r, 'GLOBAL', 'GLOBAL', 'Resumen consolidado de las 13 sucursales operativas', true);
        $r++;

        // 13 sucursales from calculator output
        $sucursales = array_column($branches, 'sucursal');
        if (empty($sucursales)) {
            $sucursales = [
                'ATLACOMULCO','ATLIXCO','CORDOBA','CUERNAVACA','HUAMANTLA',
                'IXTLAHUACA','MIACATLAN','ORIZABA','SAN JUAN DEL RÍO',
                'SAN LUIS POTOSI','TENANGO DEL VALLE','TLAXCALA','TULA',
            ];
        }

        foreach ($sucursales as $i => $suc) {
            $tabName = preg_replace('/[\/\\\?\*\[\]:]/', '-', $suc) ?? $suc;
            if (mb_strlen($tabName) > 28) {
                $tabName = mb_substr($tabName, 0, 28);
            }
            $nota = strtoupper($suc) === 'SAN JUAN DEL RÍO' ? 'Sucursal cerrada / cartera en recuperación' : '';
            $targetSheet = $ss->getSheetByName($tabName);
            if ($targetSheet !== null) {
                $writeLink($r, $suc, $tabName, $nota, ($i + 1) % 2 !== 0);
            } else {
                $sheet->setCellValue("A{$r}", $suc);
                $sheet->setCellValue("C{$r}", $nota ?: '(sin datos)');
                $this->dataRow($sheet, "A{$r}:C{$r}", ($i + 1) % 2 !== 0);
            }
            $r++;
        }

        // SIN ASIGNAR link — always included (empleados/gastos sin sucursal)
        $unassigned = $snap['branch_radiography']['unassigned'] ?? [];
        $hasUnassigned = (($unassigned['nomina_total'] ?? 0) + ($unassigned['comisiones'] ?? 0)
                        + ($unassigned['bonos'] ?? 0) + ($unassigned['gastos_operativos'] ?? 0)) > 0;
        $sinAsignarSheet = $ss->getSheetByName('SIN ASIGNAR');
        if ($sinAsignarSheet !== null) {
            $nota = $hasUnassigned
                ? 'PENDIENTE ASIGNACIÓN — montos incluidos en GLOBAL'
                : 'Sin montos pendientes';
            $writeLink($r, 'SIN ASIGNAR', 'SIN ASIGNAR', $nota, count($sucursales) % 2 !== 0);
            $r++;
        }

        $r++;

        // Totals summary block with status column
        $this->sectionHeader($sheet, "A{$r}:C{$r}", 'RESUMEN GLOBAL ABRIL 2026');
        $r++;

        $targets = [
            ['Cartera global',           (float)($global['valor_cartera'] ?? 0),      39_130_054.53],
            ['Colocación',               (float)($global['colocacion'] ?? 0),          14_538_964.00],
            ['Recuperación total',       (float)($global['recuperacion_total'] ?? 0),  18_323_749.55],
            ['Mora 0-30',                (float)($global['mora_0_30'] ?? 0),            1_057_765.00],
            ['Mora 31-60',               (float)($global['mora_31_60'] ?? 0),             926_001.80],
            ['Mora 61-90',               (float)($global['mora_61_90'] ?? 0),             913_414.71],
            ['Mora 91-120',              (float)($global['mora_91_120'] ?? 0),            816_584.91],
            ['Gastos operativos',        (float)($global['gastos_operativos'] ?? 0),      837_384.28],
            ['Excedentes (util. corp.)', (float)($global['excedentes'] ?? 0),           3_076_800.00],
            ['Préstamos intersucursales',(float)($global['prestamos_fondea'] ?? 0),       449_425.00],
            ['Nómina (P001)',            (float)($global['nomina_total'] ?? 0),          1_075_509.70],
            ['Comisiones (P002)',        (float)($global['comisiones'] ?? 0),              716_885.13],
            ['Bonos',                   (float)($global['bonos'] ?? 0),                   291_655.85],
        ];

        // Extend to 4 columns for status
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->setCellValue("A{$r}", 'MÉTRICA');
        $sheet->setCellValue("B{$r}", 'CALCULADO');
        $sheet->setCellValue("C{$r}", 'TARGET');
        $sheet->setCellValue("D{$r}", 'ESTADO');
        $this->colHeaders($sheet, $r, ['A' => 'MÉTRICA', 'B' => 'CALCULADO', 'C' => 'TARGET', 'D' => 'ESTADO']);
        $r++;

        foreach ($targets as $i => [$label2, $calc, $target]) {
            $diff   = $target > 0 ? abs($calc - $target) / $target * 100 : 0;
            $estado = match (true) {
                abs($calc - $target) < 1          => 'OK',
                $diff < 2                          => 'CERCA',
                $diff < 10                         => 'DIF',
                default                            => 'DIF significativa',
            };
            if (str_contains($label2, 'Préstamos') && $diff > 20) {
                $estado = 'PENDIENTE ASIGNACIÓN';
            }
            if (str_contains($label2, 'Recuperación') && $diff > 5) {
                $estado = 'FALTA FUENTE';
            }
            if ($hasUnassigned && in_array($label2, ['Nómina (P001)', 'Comisiones (P002)', 'Bonos'])) {
                if ($estado !== 'OK') {
                    $estado = 'PENDIENTE ASIGNACIÓN';
                }
            }
            if (str_contains($label2, 'Gastos') && $diff < 0) {
                $estado = 'CERCA';
            }
            $sheet->setCellValue("A{$r}", $label2);
            $sheet->setCellValue("B{$r}", $calc);
            $sheet->setCellValue("C{$r}", $target);
            $sheet->setCellValue("D{$r}", $estado);
            $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
            $sheet->getStyle("D{$r}")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => match ($estado) {
                    'OK'                    => 'FF166534',
                    'CERCA'                 => 'FF713F12',
                    'PENDIENTE ASIGNACIÓN'  => 'FF1D4ED8',
                    'FALTA FUENTE'          => 'FFB91C1C',
                    default                 => 'FFB91C1C',
                }]],
            ]);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r++;
        }

        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(20);
        $sheet->freezePane('A5');
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

        $resolver = app(\App\Services\BranchResolverService::class);

        // Filter to real branches only before rendering — routes must never appear here
        $rows = array_values(array_filter($rows, fn ($row) =>
            $resolver->isRealOperationalBranch($row['branch'] ?? '')
        ));

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

    // ═══════════════════════════════════════════════════════════════════════════
    // FILTERED / COMPARATIVE WORKBOOKS
    // ═══════════════════════════════════════════════════════════════════════════

    /**
     * Build a single-branch workbook from an existing full snapshot.
     * Sheets: RESUMEN, EMPLEADOS, GASTOS, CARTERA, COLOCACIÓN, RECUPERACIÓN, P.INTERSUC.
     */
    public function buildBranchFromSnapshot(
        Period        $period,
        PeriodSummary $summary,
        array         $snap,
        int           $branchId
    ): Spreadsheet {
        @ini_set('memory_limit', '1024M');

        $branchRow = collect($snap['sections']['branches'] ?? [])
            ->firstWhere('branch_id', $branchId);

        if (!$branchRow) {
            throw new \RuntimeException("Sucursal ID {$branchId} no encontrada en el snapshot del periodo.");
        }

        $branchName = $branchRow['nombre'];
        $brUp       = strtoupper(trim($branchName));

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("Radiografía {$branchName} — {$period->label}")
            ->setCreator('Sistema de Reportes')
            ->setSubject('Radiografía por Sucursal');

        // ── Prepare supporting data ──────────────────────────────────────────
        $payBC      = $snap['sections']['payroll_by_branch_concept']  ?? [];
        $expMx      = $snap['sections']['expenses_matrix']            ?? [];
        $moraBranch = $snap['sections']['mora_by_branch']             ?? [];
        $recDet     = $snap['sections']['recovery_detail']['by_branch'] ?? [];
        $portProd   = $snap['sections']['portfolio_by_branch_product'] ?? [];
        $placeProd  = $snap['sections']['placement_by_branch_product'] ?? [];
        $loans      = $snap['sections']['interbranch_loans']          ?? [];
        $empGest    = $snap['sections']['employees_gestores']         ?? [];

        $morIdx = [];
        foreach ($moraBranch as $row) { $morIdx[strtoupper(trim($row['branch']))] = $row; }
        $recIdx = [];
        foreach ($recDet as $row) { $recIdx[strtoupper(trim($row['branch']))] = $row; }

        $carteraB  = (float)($branchRow['cartera']      ?? 0);
        $vencidaB  = (float)($branchRow['vencida']      ?? 0);
        $recB      = (float)($branchRow['recuperacion']  ?? 0);
        $colB      = (float)($branchRow['colocacion']    ?? 0);
        $gastosB   = (float)($branchRow['gastos']        ?? 0);
        $moraPct   = $carteraB > 0 ? round($vencidaB / $carteraB * 100, 2) : 0.0;

        $morRow    = $morIdx[$brUp] ?? [];
        $mora0_30  = (float)($morRow['mora_1_30']   ?? 0);
        $mora31_60 = (float)($morRow['mora_31_60']  ?? 0);
        $mora61_90 = (float)($morRow['mora_61_90']  ?? 0);
        $mora91120 = (float)($morRow['mora_91_120'] ?? 0);

        $branchPayroll = $payBC[$branchName] ?? $payBC[$brUp] ?? [];
        $nomTotal = 0.0;
        foreach ($branchPayroll as $concept => $amount) {
            $k = strtoupper(trim($concept));
            if (!str_contains($k, 'DESCUENTO') && !str_contains($k, 'DEDUCCION')) {
                $nomTotal += (float)$amount;
            }
        }

        $expMatrix   = $expMx['matrix']   ?? [];
        $expBranches = $expMx['branches'] ?? [];
        $branchKey   = '';
        foreach ($expBranches as $eb) {
            if (strtoupper($eb) === $brUp) { $branchKey = $eb; break; }
        }
        $branchExpByCategory = [];
        if ($branchKey !== '') {
            foreach ($expMatrix as $cat => $byBranch) {
                $v = $byBranch[$branchKey] ?? 0.0;
                if ($v > 0) $branchExpByCategory[strtoupper($cat)] = $v;
            }
        }
        $getCat = fn (string $name) => ($branchExpByCategory[strtoupper($name)] ?? 0.0);

        $loanFondea = 0.0;
        $loanRecibe = 0.0;
        foreach ($loans['fondea'] ?? [] as $lRow) {
            if (strtoupper($lRow['branch']) === $brUp) { $loanFondea += (float)$lRow['total']; }
        }
        foreach ($loans['recibe'] ?? [] as $lRow) {
            if (strtoupper($lRow['branch']) === $brUp) { $loanRecibe += (float)$lRow['total']; }
        }

        $utilidad = $recB - $gastosB - $nomTotal;

        // ── Sheet 1: RESUMEN ─────────────────────────────────────────────────
        $sheet = $spreadsheet->getActiveSheet()->setTitle('RESUMEN');
        $title = strtoupper($branchName) . ' — ' . strtoupper($period->label);
        $this->sheetTitle($sheet, 'A1:D1', $title);
        $sheet->mergeCells('A2:D2');
        $sheet->setCellValue('A2', 'Periodo: ' . ($period->code ?: $period->id) . '  |  Sucursal: ' . $branchName . '  |  Generado: ' . $snap['generated_at']);
        $this->metaStyle($sheet, 'A2:D2');
        $this->colHeaders($sheet, 3, ['A' => 'MÉTRICA', 'B' => 'VALOR', 'C' => '%', 'D' => 'OBSERVACIÓN']);

        $r = 4;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '1. MÉTRICAS GENERALES'); $r++;
        foreach ([
            ['Valor cartera',          $carteraB,  'currency', '',     ''],
            ['Otorgamientos',          $colB,       'currency', '',     ''],
            ['Recuperación total',     $recB,       'currency', '',     ''],
            ['Mora de 0 a 30 días',    $mora0_30,  'currency', $carteraB > 0 ? round($mora0_30  / $carteraB * 100, 2) : '', ''],
            ['Mora de 31 a 60 días',   $mora31_60, 'currency', $carteraB > 0 ? round($mora31_60 / $carteraB * 100, 2) : '', ''],
            ['Mora de 61 a 90 días',   $mora61_90, 'currency', $carteraB > 0 ? round($mora61_90 / $carteraB * 100, 2) : '', ''],
            ['Mora de 91 a 120 días',  $mora91120, 'currency', $carteraB > 0 ? round($mora91120 / $carteraB * 100, 2) : '', ''],
            ['Cartera vencida',        $vencidaB,  'currency', $moraPct, ''],
        ] as $i => [$label, $value, $fmt, $pct, $obs]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $sheet->setCellValue("C{$r}", $pct);
            $sheet->setCellValue("D{$r}", $obs);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $value);
            if ($pct !== '' && $pct !== null) {
                $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            }
            $r++;
        }
        $r++;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '2. INGRESOS'); $r++;
        foreach ([
            ['Capital por producto', $recB,  'currency'],
            ['Intereses',            0.0,    'currency'],
            ['Total',                $recB,  'currency'],
        ] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $r++;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '3. GASTOS OPERATIVOS'); $r++;
        $gastosOpList = [
            'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
            'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
            'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
            'Publicidad','Mecánicos','Servicios de Motocicletas','Software Póliza Anual','Pólizas',
            'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
            'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
            'Gastos legales','Préstamos Intersucursales','IMSS','Financiamiento de Motos',
        ];
        $gopTotal = 0.0;
        foreach ($gastosOpList as $idx => $gastoName) {
            $val = $getCat($gastoName);
            $gopTotal += $val;
            $sheet->setCellValue("A{$r}", $gastoName);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $idx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", 'currency', $val);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Total Gastos Operativos');
        $sheet->setCellValue("B{$r}", $gopTotal > 0 ? $gopTotal : $gastosB);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '4. NÓMINA Y CAPITAL HUMANO'); $r++;
        $nomCats = ['Nómina' => 0.0, 'Comisiones' => 0.0, 'Vacaciones' => 0.0, 'Bonos' => 0.0, 'Gasolina' => 0.0];
        foreach ($branchPayroll as $concept => $amount) {
            $k = strtoupper(trim($concept));
            foreach ($nomCats as $nom => $v) {
                if (str_contains($k, strtoupper($nom))) { $nomCats[$nom] += (float)$amount; }
            }
        }
        $nomTotal2 = 0.0;
        foreach ($nomCats as $i2 => [$nomName, $nomVal]) {}
        $ii = 0;
        foreach ($nomCats as $nomName => $nomVal) {
            $sheet->setCellValue("A{$r}", $nomName);
            $sheet->setCellValue("B{$r}", $nomVal);
            $this->dataRow($sheet, "A{$r}:D{$r}", $ii % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", 'currency', $nomVal);
            $nomTotal2 += $nomVal;
            $ii++;
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Total Nómina');
        $sheet->setCellValue("B{$r}", $nomTotal > 0 ? $nomTotal : $nomTotal2);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '5. PRÉSTAMOS INTERSUCURSALES'); $r++;
        foreach ([
            ['Activos (fondea)',  $loanFondea, 'currency'],
            ['Pasivos (recibe)',  $loanRecibe, 'currency'],
            ['Total',            $loanFondea + $loanRecibe, 'currency'],
        ] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $r++;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '6. ÍNDICE DE ROTACIÓN DE PERSONAL'); $r++;
        foreach ([['N° personas que dejaron la empresa', 0, 'integer'], ['Índice de rotación', 0.0, 'percent']] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $sheet->setCellValue("D{$r}", '—');
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $r++;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '7. ANÁLISIS DE TENDENCIAS Y PROYECCIONES'); $r++;
        foreach ([
            ['Ingresos Totales',            $recB,       'currency'],
            ['Otorgamientos',               $colB,        'currency'],
            ['Gastos Totales',              $gastosB,     'currency'],
            ['Utilidad estimada',           $utilidad,    'currency'],
            ['Préstamos intersucursales',   $loanFondea,  'currency'],
            ['Mora 0-30 días',              $mora0_30,   'currency'],
            ['Mora 31-60 días',             $mora31_60,  'currency'],
            ['Mora 61-90 días',             $mora61_90,  'currency'],
            ['Mora 91-120 días',            $mora91120,  'currency'],
            ['Valor cartera',               $carteraB,   'currency'],
        ] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $r++;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '8. UTILIDAD'); $r++;
        $sheet->setCellValue("A{$r}", 'Utilidad estimada (Rec - Gastos - Nómina)');
        $sheet->setCellValue("B{$r}", $utilidad);
        $this->dataRow($sheet, "A{$r}:D{$r}", true);
        $this->applyFmt($sheet, "B{$r}", 'currency', $utilidad);
        $r += 2;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '9. SALDO TOTAL ACUMULADO CUENTAS'); $r++;
        $sheet->setCellValue("A{$r}", 'Total'); $sheet->setCellValue("B{$r}", 0.0);
        $sheet->setCellValue("D{$r}", '—');
        $this->dataRow($sheet, "A{$r}:D{$r}", true);
        $this->applyFmt($sheet, "B{$r}", 'currency', 0.0);
        $r += 2;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '10. OBSERVACIONES Y NOTAS'); $r++;
        foreach (['Comentarios sobre el desempeño financiero', 'Factores de riesgo y oportunidades', 'Recomendaciones'] as $idx => $obs) {
            $sheet->setCellValue("A{$r}", $obs);
            $sheet->mergeCells("B{$r}:D{$r}");
            $this->dataRow($sheet, "A{$r}:D{$r}", $idx % 2 === 0);
            $sheet->getStyle("A{$r}:D{$r}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($r)->setRowHeight(30);
            $r++;
        }
        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(30);
        $sheet->freezePane('A4');

        // ── Sheet 2: EMPLEADOS ───────────────────────────────────────────────
        $empSheet = $spreadsheet->createSheet()->setTitle('EMPLEADOS');
        $this->sheetTitle($empSheet, 'A1:H1', 'EMPLEADOS / GESTORES — ' . $branchName . ' — ' . strtoupper($period->label));
        $this->colHeaders($empSheet, 2, ['A' => 'NOMBRE', 'B' => 'SUCURSAL', 'C' => 'PAGOS', 'D' => 'BONOS', 'E' => 'DESCUENTOS', 'F' => 'GASTOS', 'G' => 'NETO', 'H' => 'COLOCACIÓN']);
        $branchEmployees = collect($empGest)->filter(fn ($e) => strtoupper($e['branch'] ?? '') === $brUp)->values();
        $er = 3;
        foreach ($branchEmployees as $i => $emp) {
            $empSheet->setCellValue("A{$er}", $emp['name']);
            $empSheet->setCellValue("B{$er}", $emp['branch']);
            $empSheet->setCellValue("C{$er}", $emp['pagos']      ?? 0);
            $empSheet->setCellValue("D{$er}", $emp['bonos']      ?? 0);
            $empSheet->setCellValue("E{$er}", $emp['descuentos'] ?? 0);
            $empSheet->setCellValue("F{$er}", $emp['gastos']     ?? 0);
            $empSheet->setCellValue("G{$er}", $emp['neto']       ?? 0);
            $empSheet->setCellValue("H{$er}", $emp['colocacion'] ?? 0);
            $this->dataRow($empSheet, "A{$er}:H{$er}", $i % 2 === 0);
            foreach (['C', 'D', 'E', 'F', 'G', 'H'] as $col) {
                $empSheet->getStyle("{$col}{$er}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            }
            $er++;
        }
        if ($branchEmployees->isEmpty()) { $empSheet->setCellValue('A3', 'Sin empleados asignados a esta sucursal.'); }
        $this->setColWidths($empSheet, ['A' => 36, 'B' => 18, 'C' => 16, 'D' => 14, 'E' => 14, 'F' => 14, 'G' => 16, 'H' => 16]);

        // ── Sheet 3: GASTOS ──────────────────────────────────────────────────
        $gasSheet = $spreadsheet->createSheet()->setTitle('GASTOS');
        $this->sheetTitle($gasSheet, 'A1:C1', 'GASTOS — ' . $branchName . ' — ' . strtoupper($period->label));
        $this->colHeaders($gasSheet, 2, ['A' => 'CATEGORÍA', 'B' => 'MONTO', 'C' => '%']);
        $totalGastosSheet = array_sum($branchExpByCategory);
        $gr = 3;
        foreach ($branchExpByCategory as $cat => $val) {
            $gasSheet->setCellValue("A{$gr}", $cat);
            $gasSheet->setCellValue("B{$gr}", $val);
            $gasSheet->setCellValue("C{$gr}", $totalGastosSheet > 0 ? round($val / $totalGastosSheet * 100, 2) : 0);
            $this->dataRow($gasSheet, "A{$gr}:C{$gr}", $gr % 2 === 0);
            $gasSheet->getStyle("B{$gr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $gasSheet->getStyle("C{$gr}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $gr++;
        }
        $gasSheet->setCellValue("A{$gr}", 'Total'); $gasSheet->setCellValue("B{$gr}", $totalGastosSheet > 0 ? $totalGastosSheet : $gastosB);
        $this->totalsRow($gasSheet, "A{$gr}:C{$gr}");
        $gasSheet->getStyle("B{$gr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $this->setColWidths($gasSheet, ['A' => 38, 'B' => 20, 'C' => 10]);

        // ── Sheet 4: CARTERA ─────────────────────────────────────────────────
        $portSheet = $spreadsheet->createSheet()->setTitle('CARTERA');
        $this->sheetTitle($portSheet, 'A1:D1', 'CARTERA — ' . $branchName . ' — ' . strtoupper($period->label));
        $this->colHeaders($portSheet, 2, ['A' => 'PRODUCTO', 'B' => 'CARTERA', 'C' => 'CARTERA VENCIDA', 'D' => 'MORA %']);
        $portRows = collect($portProd)->filter(fn ($p) => strtoupper($p['branch'] ?? '') === $brUp)->values();
        $pr = 3;
        foreach ($portRows as $i => $row) {
            $portSheet->setCellValue("A{$pr}", $row['product'] ?? $row['producto'] ?? '—');
            $portSheet->setCellValue("B{$pr}", $row['cartera'] ?? $row['balance'] ?? 0);
            $portSheet->setCellValue("C{$pr}", $row['vencida'] ?? 0);
            $moraPP = ($row['cartera'] ?? 0) > 0 ? round(($row['vencida'] ?? 0) / $row['cartera'] * 100, 2) : 0;
            $portSheet->setCellValue("D{$pr}", $moraPP);
            $this->dataRow($portSheet, "A{$pr}:D{$pr}", $i % 2 === 0);
            foreach (['B', 'C'] as $col) { $portSheet->getStyle("{$col}{$pr}")->getNumberFormat()->setFormatCode(self::CURRENCY); }
            $portSheet->getStyle("D{$pr}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $pr++;
        }
        $portSheet->setCellValue("A{$pr}", 'Total'); $portSheet->setCellValue("B{$pr}", $carteraB); $portSheet->setCellValue("C{$pr}", $vencidaB); $portSheet->setCellValue("D{$pr}", $moraPct);
        $this->totalsRow($portSheet, "A{$pr}:D{$pr}");
        foreach (['B', 'C'] as $col) { $portSheet->getStyle("{$col}{$pr}")->getNumberFormat()->setFormatCode(self::CURRENCY); }
        $this->setColWidths($portSheet, ['A' => 28, 'B' => 20, 'C' => 20, 'D' => 10]);

        // ── Sheet 5: COLOCACIÓN ──────────────────────────────────────────────
        $colSheet = $spreadsheet->createSheet()->setTitle('COLOCACIÓN');
        $this->sheetTitle($colSheet, 'A1:D1', 'COLOCACIÓN — ' . $branchName . ' — ' . strtoupper($period->label));
        $this->colHeaders($colSheet, 2, ['A' => 'PRODUCTO', 'B' => 'MONTO', 'C' => 'CRÉDITOS', 'D' => '%']);
        $colRows = collect($placeProd)->filter(fn ($p) => strtoupper($p['branch'] ?? '') === $brUp)->values();
        $totalColSheet = $colRows->sum(fn ($p) => $p['monto'] ?? $p['amount'] ?? 0);
        $cr = 3;
        foreach ($colRows as $i => $row) {
            $monto = $row['monto'] ?? $row['amount'] ?? 0;
            $colSheet->setCellValue("A{$cr}", $row['product'] ?? $row['producto'] ?? '—');
            $colSheet->setCellValue("B{$cr}", $monto);
            $colSheet->setCellValue("C{$cr}", $row['creditos'] ?? $row['operaciones'] ?? 0);
            $colSheet->setCellValue("D{$cr}", $totalColSheet > 0 ? round($monto / $totalColSheet * 100, 2) : 0);
            $this->dataRow($colSheet, "A{$cr}:D{$cr}", $i % 2 === 0);
            $colSheet->getStyle("B{$cr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $colSheet->getStyle("D{$cr}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $cr++;
        }
        $colSheet->setCellValue("A{$cr}", 'Total'); $colSheet->setCellValue("B{$cr}", $colB);
        $this->totalsRow($colSheet, "A{$cr}:D{$cr}");
        $colSheet->getStyle("B{$cr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $this->setColWidths($colSheet, ['A' => 28, 'B' => 20, 'C' => 12, 'D' => 10]);

        // ── Sheet 6: RECUPERACIÓN ────────────────────────────────────────────
        $recSheet = $spreadsheet->createSheet()->setTitle('RECUPERACIÓN');
        $this->sheetTitle($recSheet, 'A1:E1', 'RECUPERACIÓN — ' . $branchName . ' — ' . strtoupper($period->label));
        $this->colHeaders($recSheet, 2, ['A' => 'CONCEPTO', 'B' => 'CAPITAL', 'C' => 'INTERÉS', 'D' => 'IVA', 'E' => 'TOTAL']);
        $recRow  = $recIdx[$brUp] ?? [];
        $rr = 3;
        foreach ([
            ['Capital',     (float)($recRow['capital']   ?? 0), 'B'],
            ['Intereses',   (float)($recRow['interest']  ?? 0), 'C'],
            ['IVA',         (float)($recRow['tax']       ?? 0), 'D'],
            ['Cargos',      (float)($recRow['charges']   ?? 0), 'E'],
        ] as $i => [$label, $val, $col]) {
            $recSheet->setCellValue("A{$rr}", $label);
            $recSheet->setCellValue($col . $rr, $val);
            $this->dataRow($recSheet, "A{$rr}:E{$rr}", $i % 2 === 0);
            $recSheet->getStyle($col . $rr)->getNumberFormat()->setFormatCode(self::CURRENCY);
            $rr++;
        }
        $recSheet->setCellValue("A{$rr}", 'Total'); $recSheet->setCellValue("E{$rr}", $recB);
        $this->totalsRow($recSheet, "A{$rr}:E{$rr}");
        $recSheet->getStyle("E{$rr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $this->setColWidths($recSheet, ['A' => 22, 'B' => 18, 'C' => 18, 'D' => 14, 'E' => 18]);

        // ── Sheet 7: P. INTERSUC. ────────────────────────────────────────────
        $lSheet = $spreadsheet->createSheet()->setTitle('P. INTERSUC.');
        $this->sheetTitle($lSheet, 'A1:D1', 'PRÉSTAMOS INTERSUCURSALES — ' . $branchName . ' — ' . strtoupper($period->label));
        $this->colHeaders($lSheet, 2, ['A' => 'ROL', 'B' => 'CONTRAPARTE', 'C' => 'MONTO', 'D' => 'FECHA']);
        $lr = 3;
        foreach ($loans['detail'] ?? [] as $i => $det) {
            $from = strtoupper($det['from_branch'] ?? '');
            $to   = strtoupper($det['to_branch']   ?? '');
            if ($from !== $brUp && $to !== $brUp) continue;
            $rol  = $from === $brUp ? 'Fondea' : 'Recibe';
            $cprt = $from === $brUp ? ($det['to_branch'] ?? '—') : ($det['from_branch'] ?? '—');
            $lSheet->setCellValue("A{$lr}", $rol);
            $lSheet->setCellValue("B{$lr}", $cprt);
            $lSheet->setCellValue("C{$lr}", (float)($det['amount'] ?? $det['total'] ?? 0));
            $lSheet->setCellValue("D{$lr}", $det['date'] ?? '—');
            $this->dataRow($lSheet, "A{$lr}:D{$lr}", $i % 2 === 0);
            $lSheet->getStyle("C{$lr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $lr++;
        }
        if ($lr === 3) { $lSheet->setCellValue('A3', 'Sin préstamos intersucursales para esta sucursal en el periodo.'); }
        $this->setColWidths($lSheet, ['A' => 14, 'B' => 28, 'C' => 18, 'D' => 16]);

        if ($spreadsheet->getSheetCount() > 1) {
            try {
                $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName('Worksheet'));
                if ($idx !== null) $spreadsheet->removeSheetByIndex($idx);
            } catch (\Throwable) {}
        }
        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    /**
     * Build an employee/gestor-specific workbook from an existing full snapshot.
     * Sheets: RESUMEN, NÓMINA, COLOCACIÓN, CARTERA
     */
    public function buildEmployeeFromSnapshot(
        Period        $period,
        PeriodSummary $summary,
        array         $snap,
        int           $employeeId,
        float         $extraExpenseAmount = 0.0,
        string        $extraExpenseNotes  = ''
    ): Spreadsheet {
        @ini_set('memory_limit', '1024M');

        // Look up employee name from DB then find in snapshot
        $employee = \App\Models\Employee::find($employeeId);
        if (!$employee) {
            throw new \RuntimeException("Empleado ID {$employeeId} no encontrado.");
        }

        $canonicalizer = app(\App\Services\EmployeeNameCanonicalizer::class);
        $empNormTarget = $canonicalizer->normalize($employee->full_name ?? '');

        $empGest = $snap['sections']['employees_gestores'] ?? [];
        $empRow  = null;
        foreach ($empGest as $e) {
            if ($canonicalizer->normalize($e['name'] ?? '') === $empNormTarget) {
                $empRow = $e;
                break;
            }
        }

        // Fallback: partial match
        if (!$empRow) {
            foreach ($empGest as $e) {
                $norm = $canonicalizer->normalize($e['name'] ?? '');
                if ($norm && str_contains($norm, $empNormTarget) || str_contains($empNormTarget, $norm)) {
                    $empRow = $e;
                    break;
                }
            }
        }

        $empName   = $employee->full_name;
        $empBranch = $empRow['branch'] ?? 'Sin asignar';
        $pagos     = (float)($empRow['pagos']        ?? 0);
        $bonos     = (float)($empRow['bonos']        ?? 0);
        $desctos   = (float)($empRow['descuentos']   ?? 0);
        $gastos    = (float)($empRow['gastos']        ?? 0) + $extraExpenseAmount;
        $neto      = (float)($empRow['neto']          ?? ($pagos + $bonos - $desctos));
        $coloc     = (float)($empRow['colocacion']    ?? 0);
        $ops       = (int)($empRow['operaciones']     ?? 0);
        $rec       = (float)($empRow['recuperacion']  ?? 0);
        $cartera   = (float)($empRow['cartera']       ?? 0);
        $vencida   = (float)($empRow['vencida']       ?? 0);
        $mora      = $cartera > 0 ? round($vencida / $cartera * 100, 2) : (float)($empRow['mora'] ?? 0);

        $utilidad  = $rec - $gastos - ($pagos + $bonos - $desctos);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("Radiografía Gestor {$empName} — {$period->label}")
            ->setCreator('Sistema de Reportes');

        // ── Sheet 1: RESUMEN ─────────────────────────────────────────────────
        $sheet = $spreadsheet->getActiveSheet()->setTitle('RESUMEN');
        $this->sheetTitle($sheet, 'A1:D1', mb_strtoupper($empName) . ' — ' . strtoupper($period->label));
        $sheet->mergeCells('A2:D2');
        $sheet->setCellValue('A2', "Gestor / Empleado: {$empName}  |  Sucursal: {$empBranch}  |  Periodo: {$period->label}");
        $this->metaStyle($sheet, 'A2:D2');
        $this->colHeaders($sheet, 3, ['A' => 'MÉTRICA', 'B' => 'VALOR', 'C' => '%', 'D' => 'OBSERVACIÓN']);

        $r = 4;
        $this->sectionHeader($sheet, "A{$r}:D{$r}", '1. MÉTRICAS GENERALES'); $r++;
        foreach ([
            ['Recuperación', $rec,     'currency', '', ''],
            ['Colocación',   $coloc,   'currency', '', ''],
            ['Operaciones',  $ops,     'integer',  '', ''],
            ['Cartera',      $cartera, 'currency', '', ''],
            ['Cartera vencida', $vencida, 'currency', $mora, ''],
            ['Mora %',       $mora,    'percent',  '', ''],
        ] as $i => [$label, $value, $fmt, $pct, $obs]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $value);
            $sheet->setCellValue("C{$r}", $pct);
            $sheet->setCellValue("D{$r}", $obs);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $value);
            if ($pct !== '') { $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::PERCENT); }
            $r++;
        }
        $r++;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '2. NÓMINA'); $r++;
        foreach ([
            ['Pagos',       $pagos,  'currency'],
            ['Bonos',       $bonos,  'currency'],
            ['Descuentos',  $desctos, 'currency'],
            ['Neto',        $neto,   'currency'],
        ] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label); $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $r++;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '3. GASTOS'); $r++;
        $sheet->setCellValue("A{$r}", 'Gastos directos'); $sheet->setCellValue("B{$r}", (float)($empRow['gastos'] ?? 0));
        $this->dataRow($sheet, "A{$r}:D{$r}", true);
        $this->applyFmt($sheet, "B{$r}", 'currency', (float)($empRow['gastos'] ?? 0));
        $r++;
        if ($extraExpenseAmount > 0) {
            $sheet->setCellValue("A{$r}", 'Gasto general asignado al gestor');
            $sheet->setCellValue("B{$r}", $extraExpenseAmount);
            $sheet->setCellValue("D{$r}", $extraExpenseNotes ?: '—');
            $this->dataRow($sheet, "A{$r}:D{$r}", false);
            $this->applyFmt($sheet, "B{$r}", 'currency', $extraExpenseAmount);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Total Gastos'); $sheet->setCellValue("B{$r}", $gastos);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '4. UTILIDAD ESTIMADA'); $r++;
        $sheet->setCellValue("A{$r}", 'Rec - (Pagos + Bonos - Desctos) - Gastos');
        $sheet->setCellValue("B{$r}", $utilidad);
        $this->dataRow($sheet, "A{$r}:D{$r}", true);
        $this->applyFmt($sheet, "B{$r}", 'currency', $utilidad);
        $r += 2;

        if ($extraExpenseNotes) {
            $this->sectionHeader($sheet, "A{$r}:D{$r}", 'OBSERVACIONES'); $r++;
            $sheet->setCellValue("A{$r}", $extraExpenseNotes);
            $sheet->mergeCells("A{$r}:D{$r}");
            $this->dataRow($sheet, "A{$r}:D{$r}", true);
            $sheet->getStyle("A{$r}:D{$r}")->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($r)->setRowHeight(40);
        }

        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(35);
        $sheet->freezePane('A4');

        // ── Sheet 2: NÓMINA DETAIL (from DB) ─────────────────────────────────
        $noiSheet = $spreadsheet->createSheet()->setTitle('NÓMINA');
        $this->sheetTitle($noiSheet, 'A1:E1', 'DETALLE DE NÓMINA — ' . mb_strtoupper($empName));
        $this->colHeaders($noiSheet, 2, ['A' => 'CONCEPTO', 'B' => 'TIPO', 'C' => 'MONTO', 'D' => 'FECHA', 'E' => 'PERIODO NÓMINA']);
        $resolveDataIds = app(\App\Services\Radiography\RadiographySnapshotBuilder::class)
            ->resolveDataIdsPublic($period);
        $noiRows = \Illuminate\Support\Facades\DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $resolveDataIds)
            ->where('n.employee_id', $employeeId)
            ->selectRaw('n.concept, n.concept_type, n.amount, n.movement_date, n.payroll_type')
            ->orderBy('n.movement_date')
            ->get();
        $nr = 3;
        foreach ($noiRows as $i => $row) {
            $noiSheet->setCellValue("A{$nr}", $row->concept ?? '—');
            $noiSheet->setCellValue("B{$nr}", $row->concept_type ?? '—');
            $noiSheet->setCellValue("C{$nr}", (float)$row->amount);
            $noiSheet->setCellValue("D{$nr}", $row->movement_date ?? '—');
            $noiSheet->setCellValue("E{$nr}", $row->payroll_type ?? '—');
            $this->dataRow($noiSheet, "A{$nr}:E{$nr}", $i % 2 === 0);
            $noiSheet->getStyle("C{$nr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $nr++;
        }
        if ($noiRows->isEmpty()) { $noiSheet->setCellValue('A3', 'Sin movimientos de nómina NOI para este empleado en el periodo.'); }
        $this->setColWidths($noiSheet, ['A' => 38, 'B' => 16, 'C' => 18, 'D' => 16, 'E' => 20]);

        // ── Sheet 3: COLOCACIÓN ──────────────────────────────────────────────
        $colEmpSheet = $spreadsheet->createSheet()->setTitle('COLOCACIÓN');
        $this->sheetTitle($colEmpSheet, 'A1:D1', 'COLOCACIÓN — ' . mb_strtoupper($empName));
        $this->colHeaders($colEmpSheet, 2, ['A' => 'PRODUCTO', 'B' => 'MONTO', 'C' => 'CRÉDITOS', 'D' => 'SUCURSAL']);
        $canonicalizer2 = app(\App\Services\EmployeeNameCanonicalizer::class);
        $placRows = \Illuminate\Support\Facades\DB::table('fact_placements as p')
            ->leftJoin('branches as b', 'p.branch_id', '=', 'b.id')
            ->whereIn('p.period_id', $resolveDataIds)
            ->where(fn ($q) => $q->whereRaw('LOWER(p.promoter_name) LIKE ?', ['%' . mb_strtolower($employee->full_name) . '%']))
            ->selectRaw('p.product_name, SUM(p.amount) as monto, COUNT(*) as creditos, b.name as sucursal')
            ->groupBy('p.product_name', 'b.name')
            ->get();
        $plr = 3;
        foreach ($placRows as $i => $row) {
            $colEmpSheet->setCellValue("A{$plr}", $row->product_name ?? '—');
            $colEmpSheet->setCellValue("B{$plr}", (float)$row->monto);
            $colEmpSheet->setCellValue("C{$plr}", (int)$row->creditos);
            $colEmpSheet->setCellValue("D{$plr}", $row->sucursal ?? '—');
            $this->dataRow($colEmpSheet, "A{$plr}:D{$plr}", $i % 2 === 0);
            $colEmpSheet->getStyle("B{$plr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $plr++;
        }
        if ($placRows->isEmpty()) { $colEmpSheet->setCellValue('A3', 'Sin colocación registrada para este gestor en el periodo.'); }
        $this->setColWidths($colEmpSheet, ['A' => 28, 'B' => 18, 'C' => 12, 'D' => 22]);

        // ── Sheet 4: CARTERA ─────────────────────────────────────────────────
        $portEmpSheet = $spreadsheet->createSheet()->setTitle('CARTERA');
        $this->sheetTitle($portEmpSheet, 'A1:E1', 'CARTERA Y MORA — ' . mb_strtoupper($empName));
        $this->colHeaders($portEmpSheet, 2, ['A' => 'PRODUCTO', 'B' => 'CARTERA', 'C' => 'VENCIDA', 'D' => 'MORA %', 'E' => 'SUCURSAL']);
        $portEmpRows = \Illuminate\Support\Facades\DB::table('fact_portfolios as po')
            ->leftJoin('branches as b', 'po.branch_id', '=', 'b.id')
            ->whereIn('po.period_id', $resolveDataIds)
            ->where(fn ($q) => $q->whereRaw('LOWER(po.promoter_name) LIKE ?', ['%' . mb_strtolower($employee->full_name) . '%']))
            ->selectRaw('po.product_name, SUM(po.balance) as cartera, SUM(CASE WHEN po.days_past_due>0 THEN po.balance ELSE 0 END) as vencida, b.name as sucursal')
            ->groupBy('po.product_name', 'b.name')
            ->get();
        $por = 3;
        foreach ($portEmpRows as $i => $row) {
            $moraPp = (float)$row->cartera > 0 ? round((float)$row->vencida / (float)$row->cartera * 100, 2) : 0;
            $portEmpSheet->setCellValue("A{$por}", $row->product_name ?? '—');
            $portEmpSheet->setCellValue("B{$por}", (float)$row->cartera);
            $portEmpSheet->setCellValue("C{$por}", (float)$row->vencida);
            $portEmpSheet->setCellValue("D{$por}", $moraPp);
            $portEmpSheet->setCellValue("E{$por}", $row->sucursal ?? '—');
            $this->dataRow($portEmpSheet, "A{$por}:E{$por}", $i % 2 === 0);
            foreach (['B', 'C'] as $col) { $portEmpSheet->getStyle("{$col}{$por}")->getNumberFormat()->setFormatCode(self::CURRENCY); }
            $portEmpSheet->getStyle("D{$por}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $por++;
        }
        if ($portEmpRows->isEmpty()) { $portEmpSheet->setCellValue('A3', 'Sin cartera registrada para este gestor en el periodo.'); }
        $this->setColWidths($portEmpSheet, ['A' => 28, 'B' => 18, 'C' => 18, 'D' => 10, 'E' => 22]);

        try {
            $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName('Worksheet'));
            if ($idx !== null) $spreadsheet->removeSheetByIndex($idx);
        } catch (\Throwable) {}

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    /**
     * Build a comparative workbook from two period snapshots.
     * Compares the 10 sections between periods (or filtered to branch/employee).
     */
    public function buildComparativeFromSnapshots(
        Period  $currentPeriod,
        array   $currentSnap,
        Period  $comparePeriod,
        array   $compareSnap,
        array   $config = []
    ): Spreadsheet {
        @ini_set('memory_limit', '1024M');

        $scope    = $config['scope']    ?? 'general';
        $branchId = $config['branch_id']   ?? null;
        $empId    = $config['employee_id'] ?? null;

        // Resolve labels
        $labelCur = strtoupper($currentPeriod->label);
        $labelCmp = strtoupper($comparePeriod->label);
        $scopeLabel = 'General';
        $branchName = null;

        if ($scope === 'branch' && $branchId) {
            $branchRow  = collect($currentSnap['sections']['branches'] ?? [])->firstWhere('branch_id', (int)$branchId);
            $branchName = $branchRow['nombre'] ?? "Sucursal #{$branchId}";
            $scopeLabel = $branchName;
        } elseif ($scope === 'employee' && $empId) {
            $emp        = \App\Models\Employee::find($empId);
            $scopeLabel = $emp->full_name ?? "Empleado #{$empId}";
        }

        // Helper: get branch-level value from snapshot
        $branchVal = function (array $snap, string $field) use ($branchId, $branchName): float {
            if (!$branchId) return 0.0;
            $brUp = strtoupper(trim($branchName ?? ''));
            $branches = $snap['sections']['branches'] ?? [];
            $row = collect($branches)->firstWhere('branch_id', (int)$branchId);
            if (!$row) { $row = collect($branches)->first(fn ($b) => strtoupper($b['nombre'] ?? '') === $brUp); }
            return (float)($row[$field] ?? 0);
        };

        // Helper: get employee value from snapshot
        $canonicalizer = app(\App\Services\EmployeeNameCanonicalizer::class);
        $empVal = function (array $snap, string $field) use ($empId, $canonicalizer): float {
            if (!$empId) return 0.0;
            $emp = \App\Models\Employee::find($empId);
            $target = $canonicalizer->normalize($emp->full_name ?? '');
            $rows = $snap['sections']['employees_gestores'] ?? [];
            foreach ($rows as $r) {
                if ($canonicalizer->normalize($r['name'] ?? '') === $target) {
                    return (float)($r[$field] ?? 0);
                }
            }
            return 0.0;
        };

        // Get metric values from both snapshots
        $get = function (array $snap, string $path) use ($scope, $branchVal, $empVal, $branchId, $empId): float {
            if ($scope === 'branch' && $branchId) {
                return match ($path) {
                    'cartera'      => $branchVal($snap, 'cartera'),
                    'colocacion'   => $branchVal($snap, 'colocacion'),
                    'recuperacion' => $branchVal($snap, 'recuperacion'),
                    'gastos'       => $branchVal($snap, 'gastos'),
                    'mora'         => $branchVal($snap, 'mora'),
                    'vencida'      => $branchVal($snap, 'vencida'),
                    'pagos'        => (float)($snap['sections']['payroll']['pagos'] ?? 0),
                    'bonos'        => (float)($snap['sections']['payroll']['bonos'] ?? 0),
                    'descuentos'   => (float)($snap['sections']['payroll']['descuentos'] ?? 0),
                    'neto'         => (float)($snap['sections']['payroll']['neto'] ?? 0),
                    default        => (float)data_get($snap, $path, 0),
                };
            }
            if ($scope === 'employee' && $empId) {
                return match ($path) {
                    'recuperacion' => $empVal($snap, 'recuperacion'),
                    'colocacion'   => $empVal($snap, 'colocacion'),
                    'cartera'      => $empVal($snap, 'cartera'),
                    'vencida'      => $empVal($snap, 'vencida'),
                    'pagos'        => $empVal($snap, 'pagos'),
                    'bonos'        => $empVal($snap, 'bonos'),
                    'descuentos'   => $empVal($snap, 'descuentos'),
                    'neto'         => $empVal($snap, 'neto'),
                    'gastos'       => $empVal($snap, 'gastos'),
                    default        => 0.0,
                };
            }
            // General scope: use summary
            return match ($path) {
                'cartera'      => (float)($snap['summary']['portfolio_total']   ?? 0),
                'colocacion'   => (float)($snap['summary']['placement_total']   ?? 0),
                'recuperacion' => (float)($snap['summary']['recovery_total']    ?? 0),
                'gastos'       => (float)($snap['summary']['expenses_total']    ?? 0),
                'vencida'      => (float)($snap['summary']['overdue_portfolio'] ?? 0),
                'mora'         => (float)($snap['summary']['mora_index']        ?? 0),
                'pagos'        => (float)($snap['sections']['payroll']['pagos']     ?? 0),
                'bonos'        => (float)($snap['sections']['payroll']['bonos']     ?? 0),
                'descuentos'   => (float)($snap['sections']['payroll']['descuentos'] ?? 0),
                'neto'         => (float)($snap['sections']['payroll']['neto']       ?? 0),
                default        => (float)data_get($snap, $path, 0),
            };
        };

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("Comparativo {$labelCmp} vs {$labelCur}")
            ->setCreator('Sistema de Reportes');

        $sheet = $spreadsheet->getActiveSheet()->setTitle('COMPARATIVO');
        $reportType = $config['report_type'] ?? 'month_vs_month';
        $typeLabel  = match ($reportType) {
            'bimester_vs_bimester' => 'COMPARATIVO BIMESTRE',
            'quarter_vs_quarter'   => 'COMPARATIVO TRIMESTRE',
            default                => 'COMPARATIVO MES VS MES',
        };
        $this->sheetTitle($sheet, 'A1:G1', "{$typeLabel} — {$labelCmp} vs {$labelCur}" . ($scopeLabel !== 'General' ? " — {$scopeLabel}" : ''));
        $sheet->mergeCells('A2:G2');
        $sheet->setCellValue('A2', "Alcance: {$scopeLabel}  |  Periodo comparado: {$comparePeriod->label}  |  Periodo actual: {$currentPeriod->label}  |  Generado: " . now('America/Mexico_City')->format('d/m/Y H:i'));
        $this->metaStyle($sheet, 'A2:G2');

        // Column headers
        $sheet->setCellValue('A3', 'MÉTRICA');
        $sheet->setCellValue('B3', $labelCmp);
        $sheet->setCellValue('C3', '%');
        $sheet->setCellValue('D3', $labelCur);
        $sheet->setCellValue('E3', '%');
        $sheet->setCellValue('F3', 'DIFERENCIA');
        $sheet->setCellValue('G3', 'VAR %');
        $hdrRange = 'A3:G3';
        $sheet->getStyle($hdrRange)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_HDR]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_HDR]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::FG_HDR]]],
        ]);
        $sheet->getRowDimension(3)->setRowHeight(18);

        $r = 4;

        // Helper: write comparison row
        $writeComp = function (int &$r, string $label, float $prev, float $curr, string $fmt, bool $alt) use ($sheet): void {
            $diff   = $curr - $prev;
            $varPct = $prev != 0 ? round($diff / abs($prev) * 100, 2) : ($curr != 0 ? 100.0 : 0.0);
            $base   = max(abs($prev), abs($curr));
            $prevPct = $base > 0 ? round(abs($prev) / $base * 100, 2) : 0;
            $currPct = $base > 0 ? round(abs($curr) / $base * 100, 2) : 0;

            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $prev);
            $sheet->setCellValue("C{$r}", $prevPct);
            $sheet->setCellValue("D{$r}", $curr);
            $sheet->setCellValue("E{$r}", $currPct);
            $sheet->setCellValue("F{$r}", $diff);
            $sheet->setCellValue("G{$r}", $varPct);

            $this->dataRow($sheet, "A{$r}:G{$r}", $alt);
            $fmtCode = $fmt === 'currency' ? self::CURRENCY : ($fmt === 'percent' ? self::PERCENT : self::INTEGER);
            foreach (['B', 'D', 'F'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode($fmtCode);
            }
            foreach (['C', 'E'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            }
            $sheet->getStyle("G{$r}")->getNumberFormat()->setFormatCode('0.00"%"');
            // Color the variance: green if positive, red if negative
            if ($varPct > 0) {
                $sheet->getStyle("G{$r}")->getFont()->getColor()->setARGB('FF15803D');
            } elseif ($varPct < 0) {
                $sheet->getStyle("G{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
            }
            $r++;
        };

        // ── Section 1: Métricas Generales ────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '1. MÉTRICAS GENERALES'); $r++;
        $cartPrev = $get($compareSnap, 'cartera');
        $cartCurr = $get($currentSnap, 'cartera');
        foreach ([
            ['Valor cartera',      $cartPrev, $cartCurr, 'currency'],
            ['Otorgamientos',      $get($compareSnap,'colocacion'), $get($currentSnap,'colocacion'), 'currency'],
            ['Recuperación total', $get($compareSnap,'recuperacion'), $get($currentSnap,'recuperacion'), 'currency'],
            ['Cartera vencida',    $get($compareSnap,'vencida'), $get($currentSnap,'vencida'), 'currency'],
            ['Mora %',             $get($compareSnap,'mora'), $get($currentSnap,'mora'), 'percent'],
        ] as $i => [$label, $prev, $curr, $fmt]) {
            $writeComp($r, $label, $prev, $curr, $fmt, $i % 2 === 0);
        }
        $r++;

        // ── Section 2: Ingresos ──────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '2. INGRESOS'); $r++;
        $writeComp($r, 'Recuperación (ingresos)', $get($compareSnap,'recuperacion'), $get($currentSnap,'recuperacion'), 'currency', true);
        $r++;

        // ── Section 3: Gastos Operativos ─────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '3. GASTOS OPERATIVOS'); $r++;
        $writeComp($r, 'Total Gastos', $get($compareSnap,'gastos'), $get($currentSnap,'gastos'), 'currency', true);
        $r++;

        // ── Section 4: Nómina ────────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '4. NÓMINA Y CAPITAL HUMANO'); $r++;
        foreach ([
            ['Pagos',       'pagos'],
            ['Bonos',       'bonos'],
            ['Descuentos',  'descuentos'],
            ['Neto',        'neto'],
        ] as $i => [$label, $key]) {
            $writeComp($r, $label, $get($compareSnap,$key), $get($currentSnap,$key), 'currency', $i % 2 === 0);
        }
        $r++;

        // ── Section 5: Préstamos Intersucursales ─────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '5. PRÉSTAMOS INTERSUCURSALES'); $r++;
        $loanPrev = (float)($compareSnap['sections']['interbranch_loans']['total'] ?? 0);
        $loanCurr = (float)($currentSnap['sections']['interbranch_loans']['total'] ?? 0);
        $writeComp($r, 'Total Intersucursal', $loanPrev, $loanCurr, 'currency', true);
        $r++;

        // ── Section 6: Rotación ──────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '6. ÍNDICE DE ROTACIÓN DE PERSONAL'); $r++;
        $sheet->setCellValue("A{$r}", 'Sin datos disponibles');
        $this->dataRow($sheet, "A{$r}:G{$r}", true);
        $r += 2;

        // ── Section 7: Tendencias ────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '7. ANÁLISIS DE TENDENCIAS Y PROYECCIONES'); $r++;
        foreach ([
            ['Recuperación',   'recuperacion'],
            ['Otorgamientos',  'colocacion'],
            ['Gastos Totales', 'gastos'],
        ] as $i => [$label, $key]) {
            $writeComp($r, $label, $get($compareSnap,$key), $get($currentSnap,$key), 'currency', $i % 2 === 0);
        }
        $utilPrev = $get($compareSnap,'recuperacion') - $get($compareSnap,'gastos') - ($get($compareSnap,'pagos') + $get($compareSnap,'bonos') - $get($compareSnap,'descuentos'));
        $utilCurr = $get($currentSnap,'recuperacion') - $get($currentSnap,'gastos') - ($get($currentSnap,'pagos') + $get($currentSnap,'bonos') - $get($currentSnap,'descuentos'));
        $writeComp($r, 'Utilidad estimada', $utilPrev, $utilCurr, 'currency', true);
        $r++;

        // ── Section 8: Utilidad ──────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '8. UTILIDAD'); $r++;
        $writeComp($r, 'Utilidad estimada', $utilPrev, $utilCurr, 'currency', true);
        $r++;

        // ── Sections 9-10: N/A ───────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '9. SALDO TOTAL ACUMULADO CUENTAS'); $r++;
        $sheet->setCellValue("A{$r}", 'Sin datos disponibles'); $this->dataRow($sheet, "A{$r}:G{$r}", true); $r += 2;
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '10. OBSERVACIONES Y NOTAS'); $r++;
        $sheet->setCellValue("A{$r}", 'Comparativo generado automáticamente. Revisar con fuentes originales para detalles.');
        $sheet->mergeCells("A{$r}:G{$r}");
        $this->dataRow($sheet, "A{$r}:G{$r}", true);

        $this->setColWidths($sheet, ['A' => 42, 'B' => 20, 'C' => 8, 'D' => 20, 'E' => 8, 'F' => 18, 'G' => 10]);
        $sheet->freezePane('A4');

        try {
            $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName('Worksheet'));
            if ($idx !== null) $spreadsheet->removeSheetByIndex($idx);
        } catch (\Throwable) {}

        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }
}
