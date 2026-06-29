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
    // Color palette — aliases onto RadiographyStyleHelper, the single source of
    // truth for the workbook's colors/formats. Every inline ->applyFromArray()
    // call elsewhere in this file that still references self::BG_xxx/self::CURRENCY
    // etc. automatically follows whatever palette is defined there.
    private const BG_DARK   = RadiographyStyleHelper::BG_PRIMARY_DARK;
    private const BG_BLUE   = RadiographyStyleHelper::BG_SECTION_HDR;
    private const BG_HDR    = RadiographyStyleHelper::BG_LIGHT_BLUE;
    private const FG_HDR    = RadiographyStyleHelper::FG_DARK_TEXT;
    private const BG_META   = RadiographyStyleHelper::BG_GRAY;
    private const FG_META   = RadiographyStyleHelper::FG_DARK_TEXT;
    private const BG_ALT    = RadiographyStyleHelper::BG_GRAY;
    private const BG_TOTAL  = RadiographyStyleHelper::BG_PRIMARY_DARK;
    private const FG_WHITE  = RadiographyStyleHelper::FG_WHITE;
    private const FG_RED    = RadiographyStyleHelper::FG_RED;
    private const BG_EVEN    = RadiographyStyleHelper::BG_WHITE;
    private const BORDER_LT  = RadiographyStyleHelper::BORDER_LT;
    private const CURRENCY   = RadiographyStyleHelper::CURRENCY;
    private const PERCENT    = RadiographyStyleHelper::PERCENT;
    private const INTEGER    = RadiographyStyleHelper::INTEGER;
    // Green/teal palette (RECUP., MORA, P. INTERSUC. and other detail sheets)
    private const BG_GREEN_HDR = RadiographyStyleHelper::BG_PRIMARY_DARK;
    private const BG_GREEN_ROW = RadiographyStyleHelper::BG_GRAY;
    private const BG_GREEN_TOT = RadiographyStyleHelper::BG_PRIMARY_DARK;
    private const DATE_FMT     = RadiographyStyleHelper::DATE_FMT;

    // All nomina_detalle items are displayed as POSITIVE additions to the payroll block.
    // The total "Nómina y Capital Humano" = SUM of all configured concepts, not a net figure.
    private const NOI_DEDUCTION_LABELS = [];

    public function buildFromSnapshot(Period $period, PeriodSummary $summary, array $snap): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Radiografía ' . $period->label)
            ->setCreator('Sistema de Reportes')
            ->setSubject('Radiografía Financiera')
            ->setDescription('Generado automáticamente — sin plantilla');

        // Sheet order: GLOBAL, analysis sheets, PRÉSTAMOS ACTIVOS, detail sheets,
        // branch sheets, PRODUCTOS, NÓMINA POR GESTOR, MORA DETALLE, admin sheets.
        // Each entry is [method, label]; label is only used to title an error
        // placeholder sheet if that one builder throws — a single sheet failing
        // must never abort the rest of the workbook.
        $sheetBuilders = [
            ['buildGlobalSheet',           'GLOBAL'],
            ['buildValorCarteraSheet',     'VALOR CARTERA'],
            ['buildMorasSheet',            'MORAS'],
            ['buildEfectividadCobranzaSheet', 'EFECT. COBRANZA'],
            ['buildIngresosSheet',         'INGRESOS'],
            ['buildGastosSheet',           'GASTOS'],
            ['buildNominaSheet',           'NÓMINA'],
            ['buildActiveLoansSheet',      'PRÉSTAMOS ACTIVOS'],
            ['buildPortfolioValueSheet',   'VAL. CART'],
            ['buildPlacementSheet',        'COLOCACIÓN'],
            ['buildInterbranchLoansSheet', 'P. INTERSUC.'],
            ['buildBranchSheets',          null], // creates multiple sheets, no single placeholder
            ['buildProductosSheet',        'PRODUCTOS'],
            ['buildEmpleadosSheet',        'EMPLEADOS'],
            ['buildNominaGestorSheet',     'NÓMINA POR GESTOR'],
            ['buildMoraDetalleSheet',      'MORA DETALLE'],
            ['buildCategoriaEbitdaSheet',  'CATEGORÍA EBITDA'],
        ];
        // Deliberately NOT wired in (business decision, avoid duplicate/confusing tabs):
        // - FOND CORP / SIN ASIGNAR / INCIDENCIAS / METADATA: out of scope for Excel/PDF/UI.
        // - RECUP. (buildRecuperacionSheet): duplicated INGRESOS using a different,
        //   non-canonical data source (sections.recovery_detail instead of
        //   branch_radiography) — INGRESOS is the single source of truth for recuperación.
        // - MORA (buildMoraSheet): duplicated MORAS' "MORA POR SUCURSAL" block, plus
        //   3 always-zero N/D columns (interés/impuesto/moratorios atrasado not in fact_portfolios).

        foreach ($sheetBuilders as [$method, $label]) {
            try {
                $this->$method($spreadsheet, $period, $snap);
            } catch (\Throwable $e) {
                report($e);
                if ($label !== null) {
                    $placeholder = $spreadsheet->createSheet()->setTitle(
                        RadiographyStyleHelper::safeSheetName($label, $spreadsheet->getSheetNames())
                    );
                    RadiographyStyleHelper::setCellValueSafe(
                        $placeholder,
                        'A1',
                        "No se pudo generar esta hoja ({$label}): {$e->getMessage()}"
                    );
                }
            }
        }

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

    // ── HOJA 1: GLOBAL — índice ejecutivo principal del reporte ─────────────

    private function buildGlobalSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet   = $ss->getActiveSheet()->setTitle('GLOBAL');
        $sum     = $snap['summary'];
        $pay     = $snap['sections']['payroll'];
        $buckets = $snap['sections']['portfolio_buckets'] ?? [];
        $loans   = $snap['sections']['interbranch_loans'] ?? [];
        $funding = $snap['sections']['corporate_funding'] ?? [];

        // Prefer BranchRadiographyCalculator (GLOBAL = suma de sucursales) over legacy summary
        $brCalcGlobal = $snap['branch_radiography']['global'] ?? null;
        $branchesList = $snap['branch_radiography']['branches'] ?? [];

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
        $moraPct   = $carteraTotal > 0 ? round($moraTotal / $carteraTotal * 100, 2) : 0.0;

        // ── Ingresos / Cobranza — desglose completo ─────────────────────────────
        $ingrBruta       = $brCalcGlobal ? (float)$brCalcGlobal['recuperacion_bruta']      : (float)($sum['recovery_bruta']            ?? 0.0);
        $ingrSegExcluido = $brCalcGlobal ? (float)$brCalcGlobal['seguro_excluido_bruto']    : (float)($sum['recovery_seguro_excluido']  ?? 0.0);
        $ingrCrece       = $brCalcGlobal ? (float)$brCalcGlobal['seguro_crece_bruto']       : (float)($sum['recovery_crece_bruto']      ?? 0.0);
        $ingrCrece30     = $brCalcGlobal ? (float)$brCalcGlobal['seguro_crece_reconocido']  : (float)($sum['recovery_crece_reconocido'] ?? 0.0);
        $ingrCrece70     = max(0.0, $ingrCrece - $ingrCrece30);
        $ingrTotal       = $recTotal; // recuperacion_total ya incluye el 30% CRECE
        // Componentes de recuperación final
        $ingrCapital     = $brCalcGlobal ? (float)$brCalcGlobal['capital_recuperado']   : 0.0;
        $ingrInteres     = $brCalcGlobal ? (float)$brCalcGlobal['interes_recuperado']   : 0.0;
        $ingrImpuesto    = $brCalcGlobal ? (float)$brCalcGlobal['impuesto_recuperado']  : 0.0;
        $ingrCharges     = $brCalcGlobal ? (float)$brCalcGlobal['charges']              : 0.0;
        $ingrCargosIni   = $brCalcGlobal ? (float)$brCalcGlobal['cargos_inicio']        : 0.0;
        $ingrComAper     = $brCalcGlobal ? (float)$brCalcGlobal['comision_apertura']    : 0.0;
        $ingrCondonacion = $brCalcGlobal ? (float)$brCalcGlobal['condonacion_excluida'] : 0.0;
        $ingrUnificacion = $brCalcGlobal ? (float)$brCalcGlobal['unificacion_excluida'] : 0.0;
        // Sección A: conciliación (bruta → final)
        $ingrItemsConc = [
            ['Recuperación bruta (total archivo)',          $ingrBruta,       'currency'],
            ['(-) Seguros excluidos (Savehearts/Comadres)', -$ingrSegExcluido,'currency'],
            ['(-) Seguro CRECE no reconocido (70%)',        -$ingrCrece70,    'currency'],
            ['(+) Seguro CRECE reconocido (30%)',           $ingrCrece30,     'currency'],
            ['(-) Condonaciones excluidas',                 -$ingrCondonacion,'currency'],
        ];
        // Sección B: desglose por componente de la recuperación final
        $ingrItemsComp = [
            ['Capital recuperado',     $ingrCapital,   'currency'],
            ['Intereses',              $ingrInteres,   'currency'],
            ['Impuestos',              $ingrImpuesto,  'currency'],
            ['Moratorios / Multas',    $ingrCharges,   'currency'],
            ['Cargos al inicio',       $ingrCargosIni, 'currency'],
            ['Comisión por apertura',  $ingrComAper,   'currency'],
        ];
        // legacy alias
        $ingrItems = $ingrItemsConc;

        // ── Gastos operativos (totales primero, render después) ─────────────
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
        foreach ($gastosOp as $gasto) {
            $gastosOpTotal += $getGDet($gasto);
        }

        // ── Nómina y Capital Humano (totales primero, render después) ───────
        $globalNomina    = $brCalcGlobal ? (float)$brCalcGlobal['nomina_total']     : (float)$pay['pagos'];
        $globalComisions = $brCalcGlobal ? (float)$brCalcGlobal['comisiones']       : 0.0;
        $globalBonos     = $brCalcGlobal ? (float)$brCalcGlobal['bonos']            : (float)$pay['bonos'];
        $globalVacac     = $brCalcGlobal ? (float)$brCalcGlobal['vacaciones']       : 0.0;
        $globalPrimaVac  = $brCalcGlobal ? (float)$brCalcGlobal['prima_vacacional'] : 0.0;
        $globalNomDet    = (array)($brCalcGlobal['nomina_detalle'] ?? []);

        // 24 mandatory rows — always shown even if $0
        $nomDisplayOrder = [
            'Nómina'                                    => $globalNomina,
            'Comisiones'                                => $globalComisions,
            'Vacaciones'                                => $globalVacac,
            'Prima vacacional'                          => $globalPrimaVac,
            'Bonos'                                     => $globalBonos,
            'Bonos Aceleradores'                        => 0.0,
            'IMSS'                                      => 0.0,
            'Descuentos Infonavit'                      => 0.0,
            'Finiquito'                                 => 0.0,
            'Gastos médicos'                            => 0.0,
            'Gasolina'                                  => 0.0,
            'Financiamiento De Motos'                   => 0.0,
            'Descuento Servicios Moto'                  => 0.0,
            'Financiamiento Celular'                    => 0.0,
            'Cascos'                                    => 0.0,
            'Descuento de uniformes'                    => 0.0,
            'Descuento gastos sin comprobar'            => 0.0,
            'Descuento extravío tarjeta de circulación' => 0.0,
            'Descuento tienda Mr Lana'                  => 0.0,
            'Descuento Servicios Automóvil'             => 0.0,
            'Descuento faltante en caja'                => 0.0,
            'Anticipo de nómina'                        => 0.0,
            'Formatería'                                => 0.0,
            'Pensión Alimenticia'                       => 0.0,
        ];
        // Aliases: calculator key → canonical display label
        $nomDetAlias = [
            'Financiamiento de Motos'   => 'Financiamiento De Motos',
            'Descuentos Tienda Mr Lana' => 'Descuento tienda Mr Lana',
        ];
        $mandatory24 = array_keys($nomDisplayOrder);
        $claimed = [];
        foreach ($globalNomDet as $detKey => $detVal) {
            $canonical = $nomDetAlias[$detKey] ?? $detKey;
            if (array_key_exists($canonical, $nomDisplayOrder)) {
                $nomDisplayOrder[$canonical] += (float) $detVal;
                $claimed[$detKey] = true;
            }
        }
        // Overflow: nomina_detalle items not covered by mandatory rows (only shown if > 0)
        foreach ($globalNomDet as $detKey => $detVal) {
            if (!isset($claimed[$detKey]) && (float) $detVal > 0) {
                $nomDisplayOrder[$detKey] = ($nomDisplayOrder[$detKey] ?? 0.0) + (float) $detVal;
            }
        }
        $nomTotal = array_sum($nomDisplayOrder);

        // ── Préstamos intersucursales ─────────────────────────────────────────
        $loanTotal      = (float)($loans['total'] ?? 0);
        $brGlobalFondea = $brCalcGlobal ? (float)$brCalcGlobal['prestamos_fondea'] : $loanTotal;
        $brGlobalRecibe = (float) array_sum(array_column($loans['recibe'] ?? [], 'total'));
        $brLoanNeto     = $brGlobalFondea - $brGlobalRecibe;

        // ── Tendencias / EBITDA (depende de gastosOpTotal y nomTotal ya conocidos) ──
        $saldoInicial   = (float)($snap['saldo_inicial_caja'] ?? 0);
        $saldoFinal     = $snap['saldo_final_caja'] ?? null;  // null = no configurado
        $excGlobal      = $brCalcGlobal ? (float)$brCalcGlobal['excedentes'] : $excedentes;
        $gastosTotal    = $gastosOpTotal + $nomTotal;
        $utilidad       = $saldoInicial + $recTotal - $colTotal - $gastosTotal;
        // Diferencia = EBITDA − Envío de utilidad a corporativo. Se muestra siempre el valor
        // real (puede ser negativo si el envío corporativo supera el EBITDA disponible) — ese
        // saldo es justamente lo que se lleva como saldo inicial del siguiente periodo.
        // $inconsistencia solo se usa para resaltar visualmente, no para forzar la diferencia a 0.
        $inconsistencia = $excGlobal > $utilidad;
        $diferencia     = $utilidad - $excGlobal;

        // ════════════════════════════════════════════════════════════════════
        // Layout: título(1) · subtítulo(2) · meta(3) · KPI band(4-7) · blank(8)
        // · navegación(9-14) · blank(15) · encabezado de tabla(16) · datos(17+)
        // ════════════════════════════════════════════════════════════════════

        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(18);

        RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:D1', 'Radiografía Completa del Negocio — Estado Financiero');
        RadiographyStyleHelper::applySubtitleStyle($sheet, 'A2:D2', 'MR LANA — GLOBAL · ' . strtoupper($period->label));

        RadiographyStyleHelper::mergeCellsSafe($sheet,'A3:D3');
        RadiographyStyleHelper::setCellValueSafe(
            $sheet,
            'A3',
            'Periodo: ' . ($period->code ?: $period->id)
            . '  |  Generado: ' . $snap['generated_at']
        );
        RadiographyStyleHelper::applyMetaStyle($sheet, 'A3:D3');

        // ── KPI band (8 indicadores clave del periodo) ───────────────────────
        $kpiPairs = [
            ['Valor cartera global', $carteraTotal, 'currency', 'Recuperación total',          $recTotal,   'currency'],
            ['Otorgamientos',        $colTotal,     'currency', 'Mora total',                  $moraTotal,  'currency'],
            ['Mora %',               $moraPct,      'percent',  'Gastos totales',               $gastosTotal,'currency'],
            ['Nómina y Capital Humano', $nomTotal,  'currency', 'EBITDA', $utilidad,   'currency'],
        ];
        $kpiRow = 4;
        $kpiCardBorder = ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => RadiographyStyleHelper::BG_ACCENT]];
        foreach ($kpiPairs as [$lblA, $valA, $fmtA, $lblC, $valC, $fmtC]) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$kpiRow}", $lblA);
            $sheet->setCellValue("B{$kpiRow}", $valA);
            RadiographyStyleHelper::setCellValueSafe($sheet, "C{$kpiRow}", $lblC);
            $sheet->setCellValue("D{$kpiRow}", $valC);
            // Each label/value pair reads as its own bordered "card"
            $sheet->getStyle("A{$kpiRow}:B{$kpiRow}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_LIGHT_BLUE]],
                'borders'   => ['outline' => $kpiCardBorder],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("C{$kpiRow}:D{$kpiRow}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_LIGHT_BLUE]],
                'borders'   => ['outline' => $kpiCardBorder],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getStyle("A{$kpiRow}")->getFont()->setSize(9.5)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::FG_DARK_TEXT));
            $sheet->getStyle("C{$kpiRow}")->getFont()->setSize(9.5)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::FG_DARK_TEXT));
            $sheet->getStyle("B{$kpiRow}")->getFont()->setBold(true)->setSize(13)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::BG_PRIMARY_DARK));
            $sheet->getStyle("D{$kpiRow}")->getFont()->setBold(true)->setSize(13)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::BG_PRIMARY_DARK));
            $fmtA === 'percent' ? RadiographyStyleHelper::applyPercentFormat($sheet, "B{$kpiRow}") : RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$kpiRow}");
            $fmtC === 'percent' ? RadiographyStyleHelper::applyPercentFormat($sheet, "D{$kpiRow}") : RadiographyStyleHelper::applyCurrencyFormat($sheet, "D{$kpiRow}");
            $sheet->getRowDimension($kpiRow)->setRowHeight(24);
            $kpiRow++;
        }

        // ── Dashboard visual (columnas F:G) — tabla + gráficas dona/pastel.
        // Las columnas H:N contienen 4 gráficas: Rec/Col, Cartera/Mora,
        // Top Sucursales Cartera, Mora por Bucket.
        $sheet->getColumnDimension('F')->setWidth(26);
        $sheet->getColumnDimension('G')->setWidth(20);
        foreach (['H','I','J','K','L','M','N','O','P','Q','R','S','T'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(10);
        }

        $dashRow = 4;

        // Sección 1: Recuperación vs Colocación
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "F{$dashRow}:G{$dashRow}", 'RECUPERACIÓN VS COLOCACIÓN');
        $dashRow++;
        $recColLabelStart = $dashRow;
        RadiographyStyleHelper::setCellValueSafe($sheet, "F{$dashRow}", 'Recuperación');
        $sheet->setCellValue("G{$dashRow}", $recTotal);
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "G{$dashRow}");
        $dashRow++;
        RadiographyStyleHelper::setCellValueSafe($sheet, "F{$dashRow}", 'Colocación');
        $sheet->setCellValue("G{$dashRow}", $colTotal);
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "G{$dashRow}");
        $recColLabelEnd = $dashRow;
        $dashRow += 2;

        // Sección 2: Cartera vs Cartera Vencida
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "F{$dashRow}:G{$dashRow}", 'CARTERA VS CARTERA VENCIDA');
        $dashRow++;
        $carteraMoraLabelStart = $dashRow;
        RadiographyStyleHelper::setCellValueSafe($sheet, "F{$dashRow}", 'Cartera sana');
        $sheet->setCellValue("G{$dashRow}", max(0.0, $carteraTotal - $moraTotal));
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "G{$dashRow}");
        $dashRow++;
        RadiographyStyleHelper::setCellValueSafe($sheet, "F{$dashRow}", 'Cartera vencida');
        $sheet->setCellValue("G{$dashRow}", $moraTotal);
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "G{$dashRow}");
        $carteraMoraLabelEnd = $dashRow;
        $dashRow += 2;

        // Sección 3: Top sucursales por cartera (solo Sucursal + Valor, sin categoría EBITDA)
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "F{$dashRow}:G{$dashRow}", 'TOP SUCURSALES POR CARTERA');
        $dashRow++;
        $rankBranches = $branchesList;
        usort($rankBranches, fn ($a, $b) => (float)($b['valor_cartera'] ?? 0) <=> (float)($a['valor_cartera'] ?? 0));
        $rankBranches    = array_slice($rankBranches, 0, 8);
        $topSucLabelStart = $dashRow;
        foreach ($rankBranches as $rb) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "F{$dashRow}", $rb['sucursal']);
            $sheet->setCellValue("G{$dashRow}", (float)($rb['valor_cartera'] ?? 0));
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "G{$dashRow}");
            $dashRow++;
        }
        $topSucLabelEnd = $dashRow - 1;
        $topSucCount    = count($rankBranches);
        $dashRow++;

        // Sección 4: Mora por bucket
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "F{$dashRow}:G{$dashRow}", 'MORA POR BUCKET');
        $dashRow++;
        $moraBucketLabelStart = $dashRow;
        foreach ([
            ['Mora 1-30',   $mora0_30],
            ['Mora 31-60',  $mora31_60],
            ['Mora 61-90',  $mora61_90],
            ['Mora 91-120', $mora91_120],
            ['Mora 120+',   $mora120p],
        ] as [$bLabel, $bVal]) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "F{$dashRow}", $bLabel);
            $sheet->setCellValue("G{$dashRow}", $bVal);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "G{$dashRow}");
            $dashRow++;
        }
        $moraBucketLabelEnd = $dashRow - 1;

        // ── Gráficas dona/pastel (columnas H:T) sin DataBars ─────────────────
        $chartColors = ['106A59', '5B9BD5', '1DC1A2', 'D97706', '94A3B8', '0EA5E9', '7C3AED', 'DC2626'];
        $tealBlue    = ['106A59', '5B9BD5'];
        $greenRed    = ['10B981', 'DC2626'];

        RadiographyStyleHelper::addDonutChart(
            $sheet, 'Recuperación vs Colocación',
            "F{$recColLabelStart}:F{$recColLabelEnd}",
            "G{$recColLabelStart}:G{$recColLabelEnd}",
            2, 'H4', 'N16', $tealBlue
        );
        RadiographyStyleHelper::addDonutChart(
            $sheet, 'Cartera vs Cartera Vencida',
            "F{$carteraMoraLabelStart}:F{$carteraMoraLabelEnd}",
            "G{$carteraMoraLabelStart}:G{$carteraMoraLabelEnd}",
            2, 'O4', 'U16', $greenRed
        );
        if ($topSucCount > 0) {
            RadiographyStyleHelper::addPieChart(
                $sheet, 'Top Sucursales por Cartera',
                "F{$topSucLabelStart}:F{$topSucLabelEnd}",
                "G{$topSucLabelStart}:G{$topSucLabelEnd}",
                $topSucCount, 'H17', 'N33', array_slice($chartColors, 0, $topSucCount)
            );
        }
        RadiographyStyleHelper::addDonutChart(
            $sheet, 'Mora por Bucket',
            "F{$moraBucketLabelStart}:F{$moraBucketLabelEnd}",
            "G{$moraBucketLabelStart}:G{$moraBucketLabelEnd}",
            5, 'O17', 'U33',
            ['e11d48', 'f97316', 'eab308', '3b82f6', '8b5cf6']
        );

        // ── Navegación: acceso directo a cada hoja del workbook ──────────────
        $r = $kpiRow + 1;
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '0. NAVEGACIÓN — ACCESOS DIRECTOS');
        $r++;
        $navTargets = [
            'VALOR CARTERA', 'MORAS', 'INGRESOS', 'GASTOS', 'NÓMINA',
            'PRÉSTAMOS ACTIVOS', 'VAL. CART', 'COLOCACIÓN',
            'P. INTERSUC.', 'PRODUCTOS', 'EMPLEADOS', 'NÓMINA POR GESTOR', 'MORA DETALLE',
            'CATEGORÍA EBITDA',
        ];
        $navCols = ['A', 'B', 'C', 'D'];
        foreach (array_chunk($navTargets, 4) as $rowTargets) {
            foreach ($rowTargets as $i => $target) {
                $cell = "{$navCols[$i]}{$r}";
                RadiographyStyleHelper::applyHyperlinkStyle($sheet, $cell, "→ {$target}", $target);
                $sheet->getStyle($cell)->getFont()->setSize(9);
                $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
            }
            $sheet->getRowDimension($r)->setRowHeight(16);
            $r++;
        }
        $r++;

        // ── Panel de filtros (estilo slicer) — PhpSpreadsheet no genera slicers
        // reales de Excel; este panel es visual/informativo. El filtrado real
        // funcional está en los autofiltros (▼) de PRÉSTAMOS ACTIVOS, EMPLEADOS,
        // MORA DETALLE y NÓMINA POR GESTOR.
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'FILTROS — use el autofiltro ▼ en PRÉSTAMOS ACTIVOS / EMPLEADOS / MORA DETALLE');
        RadiographyStyleHelper::mergeCellsSafe($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(8.5)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::FG_DARK_TEXT));
        $r++;
        $sucursalChips = ['ATLACOMULCO','ATLIXCO','CORDOBA','CUERNAVACA','HUAMANTLA','IXTLAHUACA','MIACATLAN','ORIZABA','SAN JUAN DEL RÍO','SAN LUIS POTOSI','TENANGO DEL VALLE','TLAXCALA','TULA'];
        $chipCols = ['A', 'B', 'C', 'D'];
        foreach (array_chunk($sucursalChips, 4) as $chunk) {
            foreach ($chunk as $i => $suc) {
                $cell = "{$chipCols[$i]}{$r}";
                RadiographyStyleHelper::applyHyperlinkStyle($sheet, $cell, $suc, RadiographyStyleHelper::safeSheetName($suc));
                $sheet->getStyle($cell)->applyFromArray([
                    'font'      => ['size' => 8.5, 'bold' => true, 'color' => ['argb' => RadiographyStyleHelper::FG_STRONG_GREEN]],
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_POSITIVE]],
                    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => RadiographyStyleHelper::FG_STRONG_GREEN]]],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                ]);
            }
            $sheet->getRowDimension($r)->setRowHeight(16);
            $r++;
        }
        $r++;

        RadiographyStyleHelper::applyTableHeaderStyle($sheet, $r, ['A' => 'MÉTRICA', 'B' => 'VALOR', 'C' => '%', 'D' => 'NOTA']);
        $r++;

        // Helper: write a section block; returns next row. Flags D with a warning
        // marker when pct > 25 (mora alta) — purely presentational, same numbers.
        $writeRows = function (int $startRow, array $items) use ($sheet): int {
            $r = $startRow;
            foreach ($items as $i => [$label, $value, $fmt, $pct]) {
                RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $label);
                $sheet->setCellValue("B{$r}", $value);
                $sheet->setCellValue("C{$r}", $pct);
                $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $value);
                if ($pct !== '' && $pct !== null) {
                    RadiographyStyleHelper::applyPercentFormat($sheet, "C{$r}", (float)$pct);
                    if ((float)$pct > 25) {
                        RadiographyStyleHelper::setCellValueSafe($sheet, "D{$r}", '⚠ Alto');
                        RadiographyStyleHelper::applyWarningStyle($sheet, "D{$r}");
                    }
                }
                $r++;
            }
            return $r;
        };

        // ── 1. Métricas Generales ─────────────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '1. MÉTRICAS GENERALES');
        $r++;
        $r = $writeRows($r, [
            ['Valor cartera global',         $carteraTotal,  'currency', ''],
            ['Otorgamientos',                $colTotal,      'currency', ''],
            ['Recuperación total',           $recTotal,      'currency', ''],
            ['Mora de 0 a 30 días',          $mora0_30,      'currency', $carteraTotal > 0 ? round($mora0_30 / $carteraTotal * 100, 2) : ''],
            ['Mora de 31 a 60 días',         $mora31_60,     'currency', $carteraTotal > 0 ? round($mora31_60 / $carteraTotal * 100, 2) : ''],
            ['Mora de 61 a 90 días',         $mora61_90,     'currency', $carteraTotal > 0 ? round($mora61_90 / $carteraTotal * 100, 2) : ''],
            ['Mora de 91 a 120 días',        $mora91_120,    'currency', $carteraTotal > 0 ? round($mora91_120 / $carteraTotal * 100, 2) : ''],
            ['Mora 120+ días',               $mora120p,      'currency', $carteraTotal > 0 ? round($mora120p  / $carteraTotal * 100, 2) : ''],
            ['Mora total',                   $moraTotal,     'currency', $carteraTotal > 0 ? round($moraTotal / $carteraTotal * 100, 2) : ''],
            ['Envío de utilidad a corporativo', $excedentes, 'currency', ''],
        ]);
        $r++;

        // ── 2. Ingresos / Recuperación ───────────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '2. INGRESOS / RECUPERACIÓN');
        $r++;

        // A) Conciliación bruta → final
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'A) Conciliación');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:D{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
        $r++;
        $ingrIdx = 0;
        foreach ($ingrItemsConc as [$ingrLabel, $ingrVal, $ingrFmt]) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $ingrLabel);
            $sheet->setCellValue("B{$r}", $ingrVal);
            $this->dataRow($sheet, "A{$r}:D{$r}", $ingrIdx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $ingrFmt, $ingrVal);
            if ($ingrVal < 0) {
                $sheet->getStyle("B{$r}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::FG_RED));
            }
            $ingrIdx++;
            $r++;
        }
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Total Ingresos / Recuperación final');
        $sheet->setCellValue("B{$r}", $ingrTotal);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // B) Desglose por componente
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'B) Desglose por componente');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:D{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
        $r++;
        $compIdx = 0;
        foreach ($ingrItemsComp as [$compLabel, $compVal, $compFmt]) {
            if ($compVal == 0.0) continue;
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $compLabel);
            $sheet->setCellValue("B{$r}", $compVal);
            $this->dataRow($sheet, "A{$r}:D{$r}", $compIdx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $compFmt, $compVal);
            $compIdx++;
            $r++;
        }
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Total (suma componentes)');
        $compSum = $ingrCapital + $ingrInteres + $ingrImpuesto + $ingrCharges + $ingrCargosIni + $ingrComAper;
        $sheet->setCellValue("B{$r}", $compSum);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // C) Desglose por sucursal
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'C) Desglose por sucursal');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
        $r++;
        // Header row
        $brIngrHeaders = ['Sucursal', 'Capital', 'Intereses', 'Impuestos', 'Moratorios', 'Cargos inicio', 'Com. apertura', 'Total'];
        $brIngrCols    = ['A','B','C','D','E','F','G','H'];
        foreach ($brIngrHeaders as $ci => $hdr) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "{$brIngrCols[$ci]}{$r}", $hdr);
        }
        $sheet->getStyle("A{$r}:H{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:H{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_LIGHT_BLUE);
        $r++;
        $sucIngrTotals = array_fill(0, 7, 0.0);
        foreach ($branchesList as $idx => $bBranch) {
            $bCap   = (float)($bBranch['capital_recuperado']  ?? 0);
            $bInt   = (float)($bBranch['interes_recuperado']  ?? 0);
            $bImp   = (float)($bBranch['impuesto_recuperado'] ?? 0);
            $bChg   = (float)($bBranch['charges']             ?? 0);
            $bCar   = (float)($bBranch['cargos_inicio']       ?? 0);
            $bCom   = (float)($bBranch['comision_apertura']   ?? 0);
            $bTot   = (float)($bBranch['recuperacion_total']  ?? 0);
            $vals   = [$bCap, $bInt, $bImp, $bChg, $bCar, $bCom, $bTot];
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $bBranch['sucursal']);
            foreach ([0,1,2,3,4,5,6] as $ci) {
                $sheet->setCellValue("{$brIngrCols[$ci+1]}{$r}", $vals[$ci]);
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$brIngrCols[$ci+1]}{$r}");
                $sucIngrTotals[$ci] += $vals[$ci];
            }
            $this->dataRow($sheet, "A{$r}:H{$r}", $idx % 2 === 0);
            $r++;
        }
        // Totals row
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'TOTAL');
        $totCols = ['B','C','D','E','F','G','H'];
        foreach ($totCols as $ci => $col) {
            $sheet->setCellValue("{$col}{$r}", $sucIngrTotals[$ci]);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$col}{$r}");
        }
        $this->totalsRow($sheet, "A{$r}:H{$r}");
        // Widen columns for this section
        foreach (['E','F','G','H'] as $wCol) {
            if ((float)$sheet->getColumnDimension($wCol)->getWidth() < 16) {
                $sheet->getColumnDimension($wCol)->setWidth(16);
            }
        }
        $r += 2;

        // ── 3. Gastos Operativos ──────────────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '3. GASTOS OPERATIVOS');
        $r++;
        $gastosOpIdx = 0;
        foreach ($gastosOp as $gasto) {
            $val = $getGDet($gasto);
            if ($val == 0.0) continue; // skip zero rows
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $gasto);
            $sheet->setCellValue("B{$r}", $val);
            $sheet->setCellValue("C{$r}", '');
            $this->dataRow($sheet, "A{$r}:D{$r}", $gastosOpIdx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", 'currency', $val);
            $gastosOpIdx++;
            $r++;
        }
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Total Gastos Operativos');
        $sheet->setCellValue("B{$r}", $gastosOpTotal);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── 4. Nómina y Capital Humano ────────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '4. NÓMINA Y CAPITAL HUMANO');
        $r++;
        $nomIdx = 0;
        foreach ($nomDisplayOrder as $nomName => $nomVal) {
            if ($nomVal == 0 && !in_array($nomName, $mandatory24)) continue;
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $nomName);
            $sheet->setCellValue("B{$r}", $nomVal);
            $sheet->setCellValue("C{$r}", '');
            $this->dataRow($sheet, "A{$r}:D{$r}", $nomIdx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", 'currency', $nomVal);
            $nomIdx++;
            $r++;
        }
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Total Nómina y Capital Humano');
        $sheet->setCellValue("B{$r}", $nomTotal);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── 5. Préstamos Intersucursales ──────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '5. PRÉSTAMOS INTERSUCURSALES');
        $r++;
        $r = $writeRows($r, [
            ['Activos (fondea)',  $brGlobalFondea, 'currency', ''],
            ['Pasivos (recibe)',  $brGlobalRecibe, 'currency', ''],
            ['Total neto',       $brLoanNeto,      'currency', ''],
        ]);
        $r++;

        // ── 6. Índice de rotación de personal ────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '6. ÍNDICE DE ROTACIÓN DE PERSONAL');
        $r++;
        $rotation = $snap['sections']['rotation'] ?? [];
        $r = $writeRows($r, [
            ['N° de personas que dejaron la empresa', (int)($rotation['bajas']    ?? 0),   'integer', ''],
            ['Promedio de personas en el periodo',    (int)($rotation['promedio'] ?? 0),   'integer', ''],
            ['Índice de rotación',                   (float)($rotation['indice'] ?? 0.0), 'percent',  ''],
        ]);
        $r++;

        // ── 7. Análisis de Tendencias y Proyecciones ──────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '7. ANÁLISIS DE TENDENCIAS Y PROYECCIONES');
        $r++;
        $r = $writeRows($r, [
            ['Saldo inicial en caja',              $saldoInicial,         'currency', ''],
            ['Ingresos Totales',                   $recTotal,             'currency', ''],
            ['Otorgamientos',                      $colTotal,             'currency', ''],
            ['Gastos Totales',                     $gastosTotal,          'currency', ''],
            ['EBITDA',                             $utilidad,             'currency', ''],
            ['Saldo final en caja',                $saldoFinal ?? 0.0,    'currency', ''],
            ['Total neto préstamos inter suc.',    $brLoanNeto,           'currency', ''],
            ['Envío de utilidad a corporativo',    $excGlobal,            'currency', ''],
            ['Diferencia / sobrante',              $diferencia,           'currency', ''],
            ['Mora de 0 a 30 días',                $mora0_30,      'currency', ''],
            ['Mora de 31 a 60 días',               $mora31_60,     'currency', ''],
            ['Mora de 61 a 90 días',               $mora61_90,     'currency', ''],
            ['Mora de 91 a 120 días',              $mora91_120,    'currency', ''],
            ['Mora 120+ días',                     $mora120p,      'currency', ''],
            ['Valor cartera',                      $carteraTotal,  'currency', ''],
        ]);
        if ($inconsistencia) {
            $this->writeInconsistenciaRow($sheet, $r, 'D');
            $r++;
        }
        $r++;

        // ── 8. EBITDA ─────────────────────────────────────────────────────────
        // EBITDA = Recuperación total − Colocación − Gastos Totales
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '8. EBITDA');
        $r++;
        $r = $writeRows($r, [
            ['Saldo inicial en caja',               $saldoInicial,  'currency', ''],
            ['Recuperación total / Cobranza',       $recTotal,      'currency', ''],
            ['Menos: Colocación del periodo',       $colTotal,      'currency', ''],
            ['Menos: Gastos Totales',               $gastosTotal,   'currency', ''],
            ['  Gastos operativos',                 $gastosOpTotal, 'currency', ''],
            ['  Nómina y Capital Humano',            $nomTotal,      'currency', ''],
            ['EBITDA',                              $utilidad,      'currency', ''],
            ['Envío de utilidad a corporativo',     $excGlobal,     'currency', ''],
            ['Diferencia',                          $diferencia,    'currency', ''],
        ]);
        if ($inconsistencia) {
            $this->writeInconsistenciaRow($sheet, $r, 'D');
            $r++;
        }
        $r++;

        // ── CATEGORÍA POR EBITDA (por sucursal) — visible aquí en GLOBAL, no
        // escondida en una hoja aparte. Misma fórmula que usa el PDF, centralizada
        // en RadiographyStyleHelper::branchEbitdaEstimate()/ebitdaCategory() para
        // que ambos documentos coincidan siempre. Detalle completo (Recuperación/
        // Gastos/Nómina por sucursal) en la hoja CATEGORÍA EBITDA.
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", 'CATEGORÍA POR EBITDA (POR SUCURSAL)');
        $r++;
        RadiographyStyleHelper::applyTableHeaderStyle($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'EBITDA ESTIMADO', 'C' => 'CATEGORÍA', 'D' => '']);
        $r++;
        $catRankedBranches = $branchesList;
        usort($catRankedBranches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
        $catIdx = 0;
        foreach ($catRankedBranches as $cb) {
            $cbUtil   = RadiographyStyleHelper::branchEbitdaEstimate($cb);
            $cbCat    = RadiographyStyleHelper::ebitdaCategory($cbUtil);
            $cbColors = RadiographyStyleHelper::categoryColors($cbCat);
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $cb['sucursal']);
            $sheet->setCellValue("B{$r}", $cbUtil);
            $sheet->setCellValue("C{$r}", $cbCat);
            $this->dataRow($sheet, "A{$r}:D{$r}", $catIdx % 2 === 0);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            $sheet->getStyle("C{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => $cbColors['fg']]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $cbColors['bg']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $catIdx++;
            $r++;
        }
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'EBITDA estimado = Recuperación − Gastos − Nómina completa estimada por sucursal.');
        RadiographyStyleHelper::mergeCellsSafe($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(8)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::FG_DARK_TEXT));
        $r += 2;
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, "A{$r}", '→ Ver detalle completo en hoja CATEGORÍA EBITDA', 'CATEGORÍA EBITDA');
        $sheet->getStyle("A{$r}")->getFont()->setSize(9);
        $r += 2;

        // ── 9. Saldo Total Acumulado Cuentas ──────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '9. SALDO TOTAL ACUMULADO CUENTAS');
        $r++;
        $r = $writeRows($r, [
            ['Total', 0, 'currency', ''],
        ]);
        $r++;

        // ── 10. Observaciones y Notas ─────────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '10. OBSERVACIONES Y NOTAS');
        $r++;
        foreach ([
            'Comentarios sobre el desempeño financiero:',
            'Factores de riesgo y oportunidades:',
            'Recomendaciones para optimización financiera:',
        ] as $i => $obsLabel) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $obsLabel);
            $sheet->setCellValue("B{$r}", '');
            RadiographyStyleHelper::mergeCellsSafe($sheet,"B{$r}:D{$r}");
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $sheet->getStyle("A{$r}")->getFont()->setBold(false);
            $sheet->getRowDimension($r)->setRowHeight(24);
            $r++;
        }
        $r++;

        // Freeze mínimo: solo título/subtítulo/meta (filas 1-3). Antes se congelaba
        // hasta la fila del encabezado de tabla (~21), dejando muy poco espacio
        // visible para navegar hacia las secciones inferiores.
        $sheet->freezePane('A4');
        // Nota: los accesos directos a cada sucursal ya están en el panel de filtros
        // (filas 16-19, junto a NAVEGACIÓN); no se repiten aquí para evitar duplicar
        // el mismo enlace dos veces en la misma hoja.
    }

    // ── VALOR CARTERA ────────────────────────────────────────────────────────

    private function buildValorCarteraSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet    = $ss->createSheet()->setTitle('VALOR CARTERA');
        $brCalc   = $snap['branch_radiography'] ?? [];
        $global   = $brCalc['global']   ?? [];
        $branches = $brCalc['branches'] ?? [];
        $label    = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:G1', 'VALOR CARTERA — ' . $label);
        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);
        $sheet->setCellValue('B2', 'Cartera vencida = Capital atrasado + Interés atrasado + Impuesto atrasado + Saldo int. moratorio + Saldo imp. int. moratorio');
        $this->metaStyle($sheet, 'B2:G2');
        RadiographyStyleHelper::mergeCellsSafe($sheet, 'B2:G2');

        $carteraGlobal = (float)($global['valor_cartera'] ?? 0);
        // cartera_vencida = SUM of 5 columns (fixed formula), same as KPI
        $vencidaGlobal = (float)($global['cartera_vencida'] ?? 0);
        $pctMora       = $carteraGlobal > 0 ? round($vencidaGlobal / $carteraGlobal * 100, 2) : 0;

        // ── Resumen global ────────────────────────────────────────────────────
        $r = 4;
        $this->sectionHeader($sheet, "A{$r}:G{$r}", 'RESUMEN GLOBAL');
        $r++;
        foreach ([
            ['Valor cartera total',     $carteraGlobal,  'currency'],
            ['Cartera vencida (5 cols)',$vencidaGlobal,  'currency'],
            ['% Mora sobre cartera',    $pctMora,        'percent'],
            ['Colocación del periodo',  (float)($global['colocacion'] ?? 0),          'currency'],
            ['Recuperación del periodo',(float)($global['recuperacion_total'] ?? 0),  'currency'],
        ] as $i => [$lbl, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $lbl);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:G{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $r++;

        // ── Desglose componentes vencidos (global) ────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", 'DESGLOSE CARTERA VENCIDA — COMPONENTES GLOBALES');
        $r++;
        // These are summed from branches (branch_radiography.global = sum of branches)
        $compRows = [
            ['Capital atrasado',              'mora_0_30',  0.0],  // placeholder — pulled from VAL.CART
            ['Interés atrasado',              '',           0.0],
            ['Impuesto atrasado',             '',           0.0],
            ['Saldo interés moratorio',       '',           0.0],
            ['Saldo impuesto int. moratorio', '',           0.0],
        ];
        // Get component sums from portfolio_by_branch_product aggregated
        $pbbp   = $snap['sections']['portfolio_by_branch_product'] ?? [];
        $capSum = array_sum(array_column($pbbp, 'capital_atrasado'));
        $intSum = array_sum(array_column($pbbp, 'interes_atrasado'));
        $impSum = array_sum(array_column($pbbp, 'impuesto_atrasado'));
        $simSum = array_sum(array_column($pbbp, 'saldo_interes_moratorio'));
        $siiSum = array_sum(array_column($pbbp, 'saldo_impuesto_interes_moratorio'));
        $venSum = $capSum + $intSum + $impSum + $simSum + $siiSum;
        foreach ([
            ['Capital atrasado',              $capSum],
            ['Interés atrasado',              $intSum],
            ['Impuesto atrasado',             $impSum],
            ['Saldo interés moratorio',       $simSum],
            ['Saldo impuesto int. moratorio', $siiSum],
        ] as $i => [$lbl, $val]) {
            $sheet->setCellValue("A{$r}", $lbl);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:G{$r}", $i % 2 === 0);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Cartera vencida TOTAL');
        $sheet->setCellValue("B{$r}", $vencidaGlobal);
        $this->totalsRow($sheet, "A{$r}:G{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── Por sucursal ──────────────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", 'CARTERA POR SUCURSAL');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'SUCURSAL',
            'B' => 'VALOR CARTERA',
            'C' => '% DEL TOTAL',
            'D' => 'CARTERA VENCIDA',
            'E' => 'MORA %',
        ]);
        $r++;

        usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
        $branchStartRow = $r;
        foreach ($branches as $i => $b) {
            $cart    = (float)($b['valor_cartera'] ?? 0);
            $vencida = (float)($b['cartera_vencida'] ?? 0);
            $pct     = $carteraGlobal > 0 ? round($cart / $carteraGlobal * 100, 2) : 0;
            $mpct    = $cart > 0 ? round($vencida / $cart * 100, 2) : 0;
            $sheet->setCellValue("A{$r}", $b['sucursal']);
            $sheet->setCellValue("B{$r}", $cart);
            $sheet->setCellValue("C{$r}", $pct);
            $sheet->setCellValue("D{$r}", $vencida);
            $sheet->setCellValue("E{$r}", $mpct);
            $this->dataRow($sheet, "A{$r}:G{$r}", $i % 2 === 0);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            foreach (['B', 'C', 'D', 'E'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $r++;
        }
        $branchEndRow = $r - 1;

        // Donut chart: distribución de cartera por sucursal
        if ($branchEndRow >= $branchStartRow) {
            RadiographyStyleHelper::addDonutChart(
                $sheet,
                'Distribución de cartera por sucursal',
                "\$A\${$branchStartRow}:\$A\${$branchEndRow}",
                "\$B\${$branchStartRow}:\$B\${$branchEndRow}",
                $branchEndRow - $branchStartRow + 1,
                'G4',
                'O24'
            );
        }

        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("B{$r}", $carteraGlobal);
        $sheet->setCellValue("C{$r}", 100);
        $sheet->setCellValue("D{$r}", $vencidaGlobal);
        $sheet->setCellValue("E{$r}", $pctMora);
        $this->totalsRow($sheet, "A{$r}:G{$r}");
        foreach (['B', 'D'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        }
        foreach (['C', 'E'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
        }

        foreach (['A'=>28,'B'=>20,'C'=>14,'D'=>20,'E'=>12,'F'=>14,'G'=>14] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A4');
    }

    // ── MORAS ────────────────────────────────────────────────────────────────

    private function buildMorasSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet   = $ss->createSheet()->setTitle('MORAS');
        $buckets = $snap['sections']['portfolio_buckets'] ?? [];
        $brCalc  = $snap['branch_radiography'] ?? [];
        $branches = $brCalc['branches'] ?? [];
        $label   = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:F1', 'MORAS — ' . $label);
        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);
        $sheet->setCellValue('B2', 'Días vencidos — fuente: Lendus Saldos por Cliente');
        $this->metaStyle($sheet, 'B2:F2');
        RadiographyStyleHelper::mergeCellsSafe($sheet,'B2:F2');

        $r = 4;
        // Buckets globales (from portfolio_buckets section)
        $this->sectionHeader($sheet, "A{$r}:F{$r}", 'DISTRIBUCIÓN GLOBAL POR DÍAS VENCIDOS');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'BUCKET', 'B' => 'CONTRATOS', 'C' => 'BALANCE', 'D' => 'VENCIDO', 'E' => '% CARTERA', 'F' => 'MORA %']);
        $r++;

        if (!empty($buckets)) {
            $bucketStartRow = $r;
            $totBal = (float) array_sum(array_column($buckets, 'balance'));
            $totVen = (float) array_sum(array_column($buckets, 'vencida'));
            foreach ($buckets as $i => $b) {
                $pctCart = $totBal > 0 ? round((float)$b['balance'] / $totBal * 100, 1) : 0;
                $pctMora = (float)$b['balance'] > 0 ? round((float)$b['vencida'] / (float)$b['balance'] * 100, 2) : 0;
                $sheet->setCellValue("A{$r}", $b['label']);
                $sheet->setCellValue("B{$r}", (int)$b['contratos']);
                $sheet->setCellValue("C{$r}", (float)$b['balance']);
                $sheet->setCellValue("D{$r}", (float)$b['vencida']);
                $sheet->setCellValue("E{$r}", $pctCart);
                $sheet->setCellValue("F{$r}", $pctMora);
                $this->dataRow($sheet, "A{$r}:F{$r}", $i % 2 === 0);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
                foreach (['C', 'D'] as $col) {
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                }
                foreach (['E', 'F'] as $col) {
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
                }
                foreach (['B', 'C', 'D', 'E', 'F'] as $col) {
                    $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'TOTALES');
            $sheet->setCellValue("B{$r}", array_sum(array_column($buckets, 'contratos')));
            $sheet->setCellValue("C{$r}", $totBal);
            $sheet->setCellValue("D{$r}", $totVen);
            $sheet->setCellValue("E{$r}", 100);
            $sheet->setCellValue("F{$r}", $totBal > 0 ? round($totVen / $totBal * 100, 2) : 0);
            $this->totalsRow($sheet, "A{$r}:F{$r}");
            foreach (['C', 'D'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            }
            foreach (['E', 'F'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            }
            $r++;

            $bucketEndRow = $bucketStartRow + count($buckets) - 1;
            RadiographyStyleHelper::addDonutChart(
                $sheet,
                'Distribución mora por bucket (días vencidos)',
                "\$A\${$bucketStartRow}:\$A\${$bucketEndRow}",
                "\$D\${$bucketStartRow}:\$D\${$bucketEndRow}",
                count($buckets),
                'H4',
                'P18'
            );
        } else {
            $sheet->setCellValue("A{$r}", 'Sin datos de días vencidos. Verifica que el archivo Lendus Saldos incluya columna días_vencidos.');
            RadiographyStyleHelper::mergeCellsSafe($sheet,"A{$r}:F{$r}");
            $r++;
        }
        $r++;

        // Por sucursal (desde branch_radiography) — usa cartera_vencida (5 cols, misma fuente que KPI)
        if (!empty($branches)) {
            $this->sectionHeader($sheet, "A{$r}:I{$r}", 'MORA POR SUCURSAL (5 columnas vencidas — misma fuente que KPI)');
            $r++;
            $this->colHeaders($sheet, $r, [
                'A' => 'SUCURSAL',
                'B' => 'MORA 1-30',
                'C' => 'MORA 31-60',
                'D' => 'MORA 61-90',
                'E' => 'MORA 91-120',
                'F' => 'MORA 120+',
                'G' => 'CARTERA VENCIDA',
                'H' => 'VALOR CARTERA',
                'I' => 'MORA %',
            ]);
            $r++;
            usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
            $totCols = array_fill_keys(['B','C','D','E','F','G','H'], 0.0);
            $branchMoraStartRow = $r;
            foreach ($branches as $i => $b) {
                $m0   = (float)($b['mora_0_30']     ?? 0);
                $m31  = (float)($b['mora_31_60']    ?? 0);
                $m61  = (float)($b['mora_61_90']    ?? 0);
                $m91  = (float)($b['mora_91_120']   ?? 0);
                $m120 = (float)($b['mora_120_plus'] ?? 0);
                $cart = (float)($b['valor_cartera'] ?? 0);
                $ven  = (float)($b['cartera_vencida'] ?? ($m0 + $m31 + $m61 + $m91 + $m120));
                $mpct = $cart > 0 ? round($ven / $cart * 100, 2) : 0;

                $sheet->setCellValue("A{$r}", $b['sucursal']);
                foreach (['B'=>$m0,'C'=>$m31,'D'=>$m61,'E'=>$m91,'F'=>$m120,'G'=>$ven,'H'=>$cart] as $col => $val) {
                    $sheet->setCellValue("{$col}{$r}", $val);
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                    $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $totCols[$col] += $val;
                }
                $sheet->setCellValue("I{$r}", $mpct);
                $sheet->getStyle("I{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
                $sheet->getStyle("I{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $this->dataRow($sheet, "A{$r}:I{$r}", $i % 2 === 0);
                if ($mpct > 25) {
                    $sheet->getStyle("I{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
                }
                $r++;
            }

            $sheet->setCellValue("A{$r}", 'TOTAL');
            foreach ($totCols as $col => $val) {
                $sheet->setCellValue("{$col}{$r}", $val);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $gMoraPct = $totCols['H'] > 0 ? round($totCols['G'] / $totCols['H'] * 100, 2) : 0;
            $sheet->setCellValue("I{$r}", $gMoraPct);
            $sheet->getStyle("I{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $this->totalsRow($sheet, "A{$r}:I{$r}");
            $r++;
        }

        // ── Desglose por componente por bucket (GLOBAL) ──────────────────────────
        $global = $brCalc['global'] ?? [];
        $bucketDefs = [
            'mora_0_30'     => 'Mora 1-30 días',
            'mora_31_60'    => 'Mora 31-60 días',
            'mora_61_90'    => 'Mora 61-90 días',
            'mora_91_120'   => 'Mora 91-120 días',
            'mora_120_plus' => 'Mora 120+ días',
        ];
        $r++;
        $this->sectionHeader($sheet, "A{$r}:H{$r}", 'DESGLOSE POR COMPONENTE — MORA GLOBAL');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'BUCKET',
            'B' => 'CAPITAL ATRASADO',
            'C' => 'INTERÉS ATRASADO',
            'D' => 'IMPUESTO ATRASADO',
            'E' => 'S. INTERÉS MORATORIO',
            'F' => 'S. IMP. MORATORIO',
            'G' => 'TOTAL BUCKET',
            'H' => '% MORA TOTAL',
        ]);
        $r++;
        $totalVencidaGlobal = (float) ($global['cartera_vencida'] ?? 0.0);
        $totCompCols = array_fill_keys(['B','C','D','E','F','G'], 0.0);
        $idx = 0;
        foreach ($bucketDefs as $bKey => $bLabel) {
            $cap  = (float) ($global["{$bKey}_capital"]       ?? 0.0);
            $int  = (float) ($global["{$bKey}_interes"]       ?? 0.0);
            $imp  = (float) ($global["{$bKey}_impuesto"]      ?? 0.0);
            $mor  = (float) ($global["{$bKey}_moratorio"]     ?? 0.0);
            $impm = (float) ($global["{$bKey}_imp_moratorio"] ?? 0.0);
            $tot  = (float) ($global[$bKey]                   ?? 0.0);
            $pct  = $totalVencidaGlobal > 0 ? round($tot / $totalVencidaGlobal * 100, 1) : 0.0;

            $sheet->setCellValue("A{$r}", $bLabel);
            foreach (['B'=>$cap,'C'=>$int,'D'=>$imp,'E'=>$mor,'F'=>$impm,'G'=>$tot] as $col => $val) {
                $sheet->setCellValue("{$col}{$r}", $val);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $totCompCols[$col] += $val;
            }
            $sheet->setCellValue("H{$r}", $pct);
            $sheet->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->dataRow($sheet, "A{$r}:H{$r}", $idx % 2 === 0);
            $idx++;
            $r++;
        }
        // Totals row
        $sheet->setCellValue("A{$r}", 'TOTAL MORA');
        foreach ($totCompCols as $col => $val) {
            $sheet->setCellValue("{$col}{$r}", $val);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->setCellValue("H{$r}", 100.0);
        $sheet->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
        $this->totalsRow($sheet, "A{$r}:H{$r}");
        $r++;

        foreach (['A'=>22,'B'=>18,'C'=>18,'D'=>18,'E'=>20,'F'=>20,'G'=>18,'H'=>12,'I'=>10] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A4');
    }

    // ── EFECTIVIDAD DE COBRANZA ───────────────────────────────────────────────

    private function buildEfectividadCobranzaSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('EFECT. COBRANZA');
        $label = strtoupper($period->label);
        $ec    = $snap['sections']['efectividad_cobranza'] ?? [];

        $this->sheetTitle($sheet, 'A1:H1', 'EFECTIVIDAD DE COBRANZA — ' . $label);
        $sheet->setCellValue('B2', 'Cobros clasificados por estatus del crédito: Vigente (DPD=0) / Atrasado (1-90) / Vencido (>90). Exclusiones: Seguros, Condonaciones, Coberturas.');
        $this->metaStyle($sheet, 'B2:H2');
        RadiographyStyleHelper::mergeCellsSafe($sheet, 'B2:H2');

        $r = 4;
        $this->sectionHeader($sheet, "A{$r}:H{$r}", 'RESUMEN POR ESTATUS');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'ESTATUS',
            'B' => 'CONTRATOS',
            'C' => 'CAPITAL',
            'D' => 'INTERÉS',
            'E' => 'IMPUESTO',
            'F' => 'MORATORIOS',
            'G' => 'TOTAL',
            'H' => '% COBRADO',
        ]);
        $r++;

        $statusDefs = [
            'vigente'    => 'Vigente (DPD=0)',
            'atrasado'   => 'Atrasado (1-90)',
            'vencido'    => 'Vencido (>90)',
            'sin_status' => 'Sin estatus',
        ];
        $grandTotal = (float) ($ec['total']['total'] ?? 0.0) ?: 1;
        $idx = 0;
        foreach ($statusDefs as $key => $label2) {
            $b   = $ec[$key] ?? [];
            $tot = (float) ($b['total'] ?? 0.0);
            $pct = round($tot / $grandTotal * 100, 1);

            $sheet->setCellValue("A{$r}", $label2);
            $sheet->setCellValue("B{$r}", (int) ($b['contratos'] ?? 0));
            $sheet->setCellValue("C{$r}", (float) ($b['capital']    ?? 0));
            $sheet->setCellValue("D{$r}", (float) ($b['interes']    ?? 0));
            $sheet->setCellValue("E{$r}", (float) ($b['impuesto']   ?? 0));
            $sheet->setCellValue("F{$r}", (float) ($b['moratorios'] ?? 0));
            $sheet->setCellValue("G{$r}", $tot);
            $sheet->setCellValue("H{$r}", $pct);
            $this->dataRow($sheet, "A{$r}:H{$r}", $idx % 2 === 0);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::INTEGER);
            foreach (['C','D','E','F','G'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $idx++;
            $r++;
        }

        // Totals
        $tot2 = $ec['total'] ?? [];
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("B{$r}", (int) ($tot2['contratos'] ?? 0));
        $sheet->setCellValue("C{$r}", (float) ($tot2['capital']    ?? 0));
        $sheet->setCellValue("D{$r}", (float) ($tot2['interes']    ?? 0));
        $sheet->setCellValue("E{$r}", (float) ($tot2['impuesto']   ?? 0));
        $sheet->setCellValue("F{$r}", (float) ($tot2['moratorios'] ?? 0));
        $sheet->setCellValue("G{$r}", (float) ($tot2['total']      ?? 0));
        $sheet->setCellValue("H{$r}", 100.0);
        $this->totalsRow($sheet, "A{$r}:H{$r}");
        foreach (['C','D','E','F','G'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        }
        $sheet->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
        $r++;

        $r++;
        $this->sectionHeader($sheet, "A{$r}:H{$r}", 'INDICADORES DE EFECTIVIDAD');
        $r++;
        $vigente  = (float) ($ec['vigente']['total']  ?? 0);
        $atrasado = (float) ($ec['atrasado']['total'] ?? 0);
        $vencido  = (float) ($ec['vencido']['total']  ?? 0);
        $total    = (float) ($ec['total']['total']    ?? 0) ?: 1;
        $enMora   = $atrasado + $vencido;
        $sheet->setCellValue("A{$r}", 'Cobros en mora (Atrasado + Vencido)');
        $sheet->setCellValue("B{$r}", '$' . number_format($enMora, 2) . ' (' . round($enMora / $total * 100, 1) . '%)');
        $r++;
        $sheet->setCellValue("A{$r}", 'Cobros al corriente (Vigente)');
        $sheet->setCellValue("B{$r}", '$' . number_format($vigente, 2) . ' (' . round($vigente / $total * 100, 1) . '%)');
        $r++;

        foreach (['A'=>32,'B'=>22,'C'=>18,'D'=>18,'E'=>18,'F'=>18,'G'=>18,'H'=>12] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A4');
    }

    // ── INGRESOS ─────────────────────────────────────────────────────────────

    private function buildIngresosSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet   = $ss->createSheet()->setTitle('INGRESOS');
        $brCalc  = $snap['branch_radiography'] ?? [];
        $global  = $brCalc['global']   ?? [];
        $branches = $brCalc['branches'] ?? [];
        $label   = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:H1', 'INGRESOS — ' . $label);
        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);
        $sheet->setCellValue('B2', 'Desglose de recuperación por componente');
        $this->metaStyle($sheet, 'B2:H2');
        RadiographyStyleHelper::mergeCellsSafe($sheet,'B2:H2');

        $r = 4;
        $this->sectionHeader($sheet, "A{$r}:L{$r}", 'INGRESOS POR SUCURSAL');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'SUCURSAL',
            'B' => 'CAPITAL',
            'C' => 'INTERESES',
            'D' => 'IMPUESTOS',
            'E' => 'MULTAS/MORATORIOS',
            'F' => 'CARGOS INICIO',
            'G' => 'COM. APERTURA',
            'H' => 'TOTAL RECUPERACIÓN',
            'I' => 'SEGUROS EXCL.',
            'J' => 'CONDONACIÓN EXCL.',
            'K' => 'UNIFICACIÓN EXCL.',
            'L' => 'BRUTO',
        ]);
        $r++;

        usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
        $totals = array_fill_keys(['B','C','D','E','F','G','H','I','J','K','L'], 0.0);
        foreach ($branches as $i => $b) {
            $cap  = (float)($b['capital_recuperado']     ?? 0);
            $int  = (float)($b['interes_recuperado']     ?? 0);
            $imp  = (float)($b['impuesto_recuperado']    ?? 0);
            $mul  = (float)($b['charges']                ?? 0);
            $car  = (float)($b['cargos_inicio']          ?? 0);
            $com  = (float)($b['comision_apertura']      ?? 0);
            $tot  = (float)($b['recuperacion_total']     ?? 0);  // authoritative total
            $seg  = (float)($b['seguro_excluido_bruto']  ?? 0);
            $con  = (float)($b['condonacion_excluida']   ?? 0);
            $uni  = (float)($b['unificacion_excluida']   ?? 0);
            $bru  = (float)($b['recuperacion_bruta']     ?? 0);
            $vals = ['B'=>$cap,'C'=>$int,'D'=>$imp,'E'=>$mul,'F'=>$car,'G'=>$com,
                     'H'=>$tot,'I'=>$seg,'J'=>$con,'K'=>$uni,'L'=>$bru];
            $sheet->setCellValue("A{$r}", $b['sucursal']);
            foreach ($vals as $col => $val) {
                $sheet->setCellValue("{$col}{$r}", $val);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $totals[$col] += $val;
            }
            $this->dataRow($sheet, "A{$r}:L{$r}", $i % 2 === 0);
            $r++;
        }
        // Global totals row — H uses authoritative recuperacion_total (must equal $18,324,971.76)
        $sheet->setCellValue("A{$r}", 'TOTAL GLOBAL');
        $gTotals = [
            'B' => (float)($global['capital_recuperado']    ?? 0),
            'C' => (float)($global['interes_recuperado']    ?? 0),
            'D' => (float)($global['impuesto_recuperado']   ?? 0),
            'E' => (float)($global['charges']               ?? 0),
            'F' => (float)($global['cargos_inicio']         ?? 0),
            'G' => (float)($global['comision_apertura']     ?? 0),
            'H' => (float)($global['recuperacion_total']    ?? 0),
            'I' => (float)($global['seguro_excluido_bruto'] ?? 0),
            'J' => (float)($global['condonacion_excluida']  ?? 0),
            'K' => (float)($global['unificacion_excluida']  ?? 0),
            'L' => (float)($global['recuperacion_bruta']    ?? 0),
        ];
        foreach ($gTotals as $col => $val) {
            $sheet->setCellValue("{$col}{$r}", $val);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        }
        $this->totalsRow($sheet, "A{$r}:L{$r}");

        foreach (['A'=>26,'B'=>16,'C'=>16,'D'=>14,'E'=>18,'F'=>16,'G'=>16,
                  'H'=>20,'I'=>16,'J'=>18,'K'=>18,'L'=>18] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A6');
    }

    // ── GASTOS (canonical cross-tab desde branch_radiography) ────────────────

    private function buildGastosSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet    = $ss->createSheet()->setTitle('GASTOS');
        $brCalc   = $snap['branch_radiography'] ?? [];
        $global   = $brCalc['global']   ?? [];
        $branches = $brCalc['branches'] ?? [];
        $label    = strtoupper($period->label);

        $conceptos = [
            'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
            'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
            'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
            'Publicidad','Mecánicos','Servicios de Motocicletas','Software Póliza Anual','Pólizas',
            'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
            'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
            'Gastos legales',
        ];

        usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));

        // Build column map: A=Concepto, B=GLOBAL, C..O=branches
        $colMap = ['A' => 'CONCEPTO', 'B' => 'GLOBAL'];
        $branchCols = [];
        foreach ($branches as $idx => $b) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($idx + 3);
            $colMap[$col] = strtoupper($b['sucursal']);
            $branchCols[$col] = $b;
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($branches) + 2);

        RadiographyStyleHelper::applyTitleStyle($sheet, "A1:{$lastCol}1", 'GASTOS OPERATIVOS — ' . $label);
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');

        // ── 0. Resumen OPEX: ERP y Lendus desglosados ───────────────────────
        $r = 4;
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '0. RESUMEN — INTEGRACIÓN OPEX (ERP + LENDUS)');
        $r++;

        $get = fn (string $k) => (float) ($global[$k] ?? 0.0);

        $resumenRows = [
            ['ERP total cargado (válido por fecha de pago)',          $get('gastos_erp_cargado'),                 false],
            ['(-) ERP reclasificado a Nómina y Capital Humano',        -$get('gastos_erp_reclasificado_nomina'),   false],
            ['(=) ERP final OPEX',                                     $get('gastos_erp_total'),                   true ],
            ['',                                                        null,                                        false],
            ['Lendus total cargado (PDF)',                              $get('gastos_lendus_cargado'),               false],
            ['(-) Lendus excluido: fondeos entre sucursales',          -$get('gastos_lendus_excluido_fondeo'),      false],
            ['(-) Lendus excluido: excedentes / envíos a corporativo', -$get('gastos_lendus_excluido_excedentes'), false],
            ['(-) Lendus excluido: nómina real (NOMINA/IMSS/etc.)',    -$get('gastos_lendus_excluido_nomina'),     false],
            ['(-) Lendus reclasificado a Nómina y Capital Humano',     -$get('gastos_lendus_reclasificado_nomina'),false],
            ['(-) Lendus excluido: pólizas / seguros puente',          -$get('gastos_lendus_excluido_polizas'),    false],
            ['(=) Lendus final OPEX',                                  $get('gastos_lendus_total'),                true ],
            ['',                                                        null,                                        false],
            ['OPEX TOTAL (ERP final + Lendus final)',                  $get('gastos_operativos'),                  true ],
        ];

        foreach ($resumenRows as $i => [$label2, $val, $isTotal]) {
            if ($label2 === '') {
                $r++;
                continue;
            }
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $label2);
            if ($val !== null) {
                $sheet->setCellValue("B{$r}", $val);
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }
            if ($isTotal) {
                $this->totalsRow($sheet, "A{$r}:B{$r}");
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            } else {
                $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
                if ($val !== null && $val < 0) {
                    $sheet->getStyle("B{$r}")->getFont()->getColor()->setARGB(RadiographyStyleHelper::FG_RED);
                }
            }
            $r++;
        }
        $r += 2;

        // ── Vista 1: jerárquica por sucursal ─────────────────────────────────
        $vista1Row = $r;
        $this->sectionHeader($sheet, "A{$r}:B{$r}", 'VISTA 1 — GASTOS POR SUCURSAL (DESGLOSE JERÁRQUICO)');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL / CONCEPTO', 'B' => 'MONTO']);
        $vista1HeaderRow = $r;
        $r++;
        foreach ($branches as $b) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", strtoupper($b['sucursal']));
            $sheet->setCellValue("B{$r}", (float)($b['gastos_operativos'] ?? 0));
            $sheet->getStyle("A{$r}:B{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => RadiographyStyleHelper::FG_WHITE]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_PRIMARY_DARK]],
            ]);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;

            $bDet = (array)($b['gastos_detalle'] ?? []);
            $i = 0;
            foreach ($conceptos as $con) {
                $val = (float)($bDet[$con] ?? 0);
                if ($val == 0.0) continue;
                RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", '    ' . $con);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
                $i++;
                $r++;
            }
        }
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", (float)($global['gastos_operativos'] ?? 0));
        $this->totalsRow($sheet, "A{$r}:B{$r}");
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
        $r += 2;

        // ── Vista 2: matriz ejecutiva por sucursal ───────────────────────────
        $this->sectionHeader($sheet, "A{$r}:{$lastCol}{$r}", 'VISTA 2 — MATRIZ POR SUCURSAL');
        $r++;
        $this->colHeaders($sheet, $r, $colMap);
        $vista2HeaderRow = $r;
        $r++;

        $globalDet = (array)($global['gastos_detalle'] ?? []);
        $colTotals = array_fill_keys(array_keys($colMap), 0.0);

        foreach ($conceptos as $i => $con) {
            $gVal = (float)($globalDet[$con] ?? 0);
            // Check if any branch has this concept
            $anyVal = $gVal > 0;
            foreach ($branchCols as $col => $b) {
                if ((float)(($b['gastos_detalle'] ?? [])[$con] ?? 0) > 0) { $anyVal = true; }
            }
            if (!$anyVal) continue; // skip empty rows

            $sheet->setCellValue("A{$r}", $con);
            $sheet->setCellValue("B{$r}", $gVal);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $colTotals['B'] += $gVal;
            foreach ($branchCols as $col => $b) {
                $val = (float)(($b['gastos_detalle'] ?? [])[$con] ?? 0);
                $sheet->setCellValue("{$col}{$r}", $val);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $colTotals[$col] += $val;
            }
            $this->dataRow($sheet, "A{$r}:{$lastCol}{$r}", $i % 2 === 0);
            $r++;
        }

        // Totals row
        $sheet->setCellValue("A{$r}", 'TOTAL GASTOS');
        $sheet->setCellValue("B{$r}", (float)($global['gastos_operativos'] ?? $colTotals['B']));
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        foreach ($branchCols as $col => $b) {
            $sheet->setCellValue("{$col}{$r}", (float)($b['gastos_operativos'] ?? 0));
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        }
        $this->totalsRow($sheet, "A{$r}:{$lastCol}{$r}");

        $sheet->getColumnDimension('A')->setWidth(32);
        $sheet->getColumnDimension('B')->setWidth(18);
        foreach (array_keys($branchCols) as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
        $sheet->setAutoFilter("A{$vista2HeaderRow}:{$lastCol}{$vista2HeaderRow}");
        // Freeze simple: solo el título/hipervínculo (filas 1-3). Antes se congelaba
        // en "B{$vista2HeaderRow+1}", una fila que cae muy abajo (después de toda la
        // Vista 1 jerárquica por sucursal) — eso bloqueaba decenas de filas como panel
        // fijo y dejaba el scroll real apretado fuera de la pantalla visible.
        $sheet->freezePane('A4');

        // ── Top 10 gastos por concepto (GLOBAL) — tabla limpia + gráfica ────────
        $r += 2;
        $topGastosRow = $r;
        $this->sectionHeader($sheet, "A{$r}:B{$r}", 'TOP 10 GASTOS POR CONCEPTO (GLOBAL)');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'CONCEPTO', 'B' => 'MONTO']);
        $r++;
        $topGastosStartRow = $r;
        $topGastos = collect($globalDet)->filter(fn ($v) => (float)$v > 0)
            ->sortDesc()->take(10);
        $gi = 0;
        foreach ($topGastos as $concepto => $monto) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $concepto);
            $sheet->setCellValue("B{$r}", (float)$monto);
            $this->dataRow($sheet, "A{$r}:B{$r}", $gi % 2 === 0);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            $gi++;
            $r++;
        }
        $topGastosEndRow = $r - 1;
        if ($topGastosEndRow >= $topGastosStartRow) {
            RadiographyStyleHelper::addDonutChart(
                $sheet,
                'Top 10 gastos por concepto (GLOBAL)',
                "\$A\${$topGastosStartRow}:\$A\${$topGastosEndRow}",
                "\$B\${$topGastosStartRow}:\$B\${$topGastosEndRow}",
                $topGastosEndRow - $topGastosStartRow + 1,
                "D{$topGastosRow}",
                'L' . ($topGastosRow + 16)
            );
        }
    }

    // ── NÓMINA (canonical cross-tab desde branch_radiography) ────────────────

    /**
     * Hierarchical, pivot-table-style view: sucursal as a bold parent row with
     * its total, concept rows indented underneath. Deduction-type concepts
     * (label contains "Descuento"/"Pensión Alimenticia"/"Anticipo") are shown
     * as negative for readability, but every parent total is the SAME canonical
     * "Nómina y Capital Humano" figure used everywhere else in the workbook
     * (nomina_total + comisiones + bonos + vacaciones + prima_vacacional +
     * sum(nomina_detalle)) — no calculation changes, presentation only.
     */
    private function buildNominaSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet      = $ss->createSheet()->setTitle('NÓMINA');
        $brCalc     = $snap['branch_radiography'] ?? [];
        $global     = $brCalc['global']     ?? [];
        $branches   = $brCalc['branches']   ?? [];
        $unassigned = $brCalc['unassigned'] ?? [];
        $label      = strtoupper($period->label);

        RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:C1', 'NÓMINA — ' . $label);
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'D1', '← GLOBAL', 'GLOBAL');
        RadiographyStyleHelper::setCellValueSafe(
            $sheet,
            'A2',
            'Descuentos mostrados en rojo/negativo solo a modo informativo — el total de cada sucursal es el monto bruto registrado (no resta los descuentos).'
        );
        RadiographyStyleHelper::mergeCellsSafe($sheet, 'A2:C2');
        RadiographyStyleHelper::applyMetaStyle($sheet, 'A2:C2');

        $sheet->getColumnDimension('A')->setWidth(40);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->getColumnDimension('C')->setWidth(28);
        $sheet->setAutoFilter('A3:C3');
        $sheet->freezePane('A4');

        RadiographyStyleHelper::applyTableHeaderStyle($sheet, 3, [
            'A' => 'ETIQUETAS DE FILA', 'B' => 'SUMA DE ACUMULADO', 'C' => 'NOTA',
        ], accent: true);

        // Codes confirmed against BranchRadiographyCalculator::accumulateNomina()'s
        // own NOI concept grouping — real codes, not invented ones.
        $codeLabels = [
            'Nómina'           => 'P001 SUELDO',
            'Comisiones'       => 'P002 COMISIONES',
            'Vacaciones'       => 'P009 VACACIONES',
            'Prima vacacional' => 'P010 PRIMA VACACIONAL',
            'Bonos'            => 'P1XX BONOS (CATEGORÍA / PRODUCTIVIDAD)',
        ];
        $scalarFields = [
            'Nómina' => 'nomina_total', 'Comisiones' => 'comisiones', 'Vacaciones' => 'vacaciones',
            'Prima vacacional' => 'prima_vacacional', 'Bonos' => 'bonos',
        ];
        $detOrder = [
            'IMSS', 'Descuentos Infonavit', 'Finiquito', 'Gastos médicos',
            'Gasolina', 'Financiamiento de Motos', 'Cascos',
            'Descuento Servicios Moto', 'Financiamiento Celular',
            'Descuento de uniformes', 'Anticipo de nómina', 'Pensión Alimenticia', 'Préstamo Personal',
        ];
        $isDeduction = fn (string $label) => (bool) preg_match('/Descuento|Pensión Alimenticia|Anticipo/i', $label);
        $branchTotal = fn (array $b) => (float)($b['nomina_total'] ?? 0) + (float)($b['comisiones'] ?? 0)
            + (float)($b['bonos'] ?? 0) + (float)($b['vacaciones'] ?? 0)
            + (float)($b['prima_vacacional'] ?? 0) + array_sum(array_values((array)($b['nomina_detalle'] ?? [])));

        usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
        $groups = $branches;
        if ($branchTotal($unassigned) != 0.0) {
            $unassigned['sucursal'] = 'SIN ASIGNAR';
            $groups[] = $unassigned;
        }

        $r = 4;
        $grandTotal = 0.0;
        foreach ($groups as $g) {
            $total = $branchTotal($g);
            $grandTotal += $total;

            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", strtoupper($g['sucursal']));
            $sheet->setCellValue("B{$r}", $total);
            $sheet->getStyle("A{$r}:C{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => RadiographyStyleHelper::FG_WHITE]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_PRIMARY_DARK]],
            ]);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            $sheet->getRowDimension($r)->setRowHeight(19);
            $r++;

            $det = (array)($g['nomina_detalle'] ?? []);
            $detKeys = array_unique(array_merge($detOrder, array_keys($det)));
            $i = 0;
            foreach ($scalarFields as $concept => $field) {
                $val = (float)($g[$field] ?? 0);
                if ($val == 0.0) continue;
                $displayVal = $isDeduction($concept) ? -$val : $val;
                RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", '    ' . ($codeLabels[$concept] ?? $concept));
                $sheet->setCellValue("B{$r}", $displayVal);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
                if ($displayVal < 0) {
                    $sheet->getStyle("B{$r}")->getFont()->getColor()->setARGB(RadiographyStyleHelper::FG_RED);
                }
                $i++;
                $r++;
            }
            foreach ($detKeys as $key) {
                $val = (float)($det[$key] ?? 0);
                if ($val == 0.0) continue;
                $displayVal = $isDeduction($key) ? -$val : $val;
                RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", '    ' . $key);
                $sheet->setCellValue("B{$r}", $displayVal);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
                if ($displayVal < 0) {
                    $sheet->getStyle("B{$r}")->getFont()->getColor()->setARGB(RadiographyStyleHelper::FG_RED);
                }
                $i++;
                $r++;
            }
        }

        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $grandTotal);
        RadiographyStyleHelper::setCellValueSafe(
            $sheet,
            "C{$r}",
            'Descuentos en rojo solo a modo informativo; el total es el monto bruto registrado.'
        );
        $this->totalsRow($sheet, "A{$r}:C{$r}");
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");

        // ── Resumen + gráfica: nómina por sucursal ───────────────────────────
        $r += 2;
        $summaryRow = $r;
        $this->sectionHeader($sheet, "A{$r}:B{$r}", 'RESUMEN: NÓMINA POR SUCURSAL');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'TOTAL']);
        $r++;
        $summaryStartRow = $r;
        foreach ($groups as $i => $g) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", strtoupper($g['sucursal']));
            $sheet->setCellValue("B{$r}", $branchTotal($g));
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            $r++;
        }
        $summaryEndRow = $r - 1;
        if ($summaryEndRow >= $summaryStartRow) {
            RadiographyStyleHelper::addDonutChart(
                $sheet,
                'Nómina por sucursal',
                "\$A\${$summaryStartRow}:\$A\${$summaryEndRow}",
                "\$B\${$summaryStartRow}:\$B\${$summaryEndRow}",
                $summaryEndRow - $summaryStartRow + 1,
                "E{$summaryRow}",
                'M' . ($summaryRow + 16)
            );
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

        // Excel tab name: max 31 chars, no special chars, unique across the workbook.
        $usedTabNames = [];
        $tabName = function (string $name) use (&$usedTabNames): string {
            $safe = RadiographyStyleHelper::safeSheetName($name, $usedTabNames);
            $usedTabNames[] = $safe;
            return $safe;
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

            $sheet->getColumnDimension('D')->setWidth(18);

            // Title row — SJR gets closed-branch note
            $titleText = strtoupper($branchName) . ' — ' . strtoupper($period->label);
            if ($isSJR) {
                $titleText .= ' — SUCURSAL CERRADA / CARTERA EN RECUPERACIÓN';
            }
            RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:D1', $titleText);

            // Meta row: back link to GLOBAL + periodo
            RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');
            RadiographyStyleHelper::mergeCellsSafe($sheet,'B2:D2');
            RadiographyStyleHelper::setCellValueSafe($sheet, 'B2', 'Periodo: ' . ($period->code ?: $period->id));
            RadiographyStyleHelper::applyMetaStyle($sheet, 'B2:D2');

            // Navigation row: direct links to this branch's detail in every relevant sheet
            $branchNavTargets = ['PRÉSTAMOS ACTIVOS', 'MORA DETALLE', 'NÓMINA POR GESTOR', 'VAL. CART', 'COLOCACIÓN', 'INGRESOS'];
            $navColLetters = ['A', 'B', 'C', 'D'];
            $navRow = 3;
            foreach (array_chunk($branchNavTargets, 4) as $chunk) {
                foreach ($chunk as $i => $navTarget) {
                    $cell = "{$navColLetters[$i]}{$navRow}";
                    RadiographyStyleHelper::applyHyperlinkStyle($sheet, $cell, "→ {$navTarget}", $navTarget);
                    $sheet->getStyle($cell)->getFont()->setSize(8.5);
                    $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
                }
                $sheet->getRowDimension($navRow)->setRowHeight(15);
                $navRow++;
            }

            // KPI card row for this branch: Cartera / Mora % / Recuperación / Colocación
            $kpiCardBorder = ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => RadiographyStyleHelper::BG_ACCENT]];
            $branchKpis = [
                ['Valor cartera', $carteraB, 'currency', 'Mora %', $moraPct, 'percent'],
                ['Recuperación',  $recB,     'currency', 'Otorgamientos', $colB, 'currency'],
            ];
            foreach ($branchKpis as [$lblA, $valA, $fmtA, $lblC, $valC, $fmtC]) {
                RadiographyStyleHelper::setCellValueSafe($sheet, "A{$navRow}", $lblA);
                $sheet->setCellValue("B{$navRow}", $valA);
                RadiographyStyleHelper::setCellValueSafe($sheet, "C{$navRow}", $lblC);
                $sheet->setCellValue("D{$navRow}", $valC);
                $sheet->getStyle("A{$navRow}:B{$navRow}")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_LIGHT_BLUE]],
                    'borders'   => ['outline' => $kpiCardBorder],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("C{$navRow}:D{$navRow}")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_LIGHT_BLUE]],
                    'borders'   => ['outline' => $kpiCardBorder],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("B{$navRow}")->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::BG_PRIMARY_DARK));
                $sheet->getStyle("D{$navRow}")->getFont()->setBold(true)->setSize(11)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::BG_PRIMARY_DARK));
                $fmtA === 'percent' ? RadiographyStyleHelper::applyPercentFormat($sheet, "B{$navRow}") : RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$navRow}");
                $fmtC === 'percent' ? RadiographyStyleHelper::applyPercentFormat($sheet, "D{$navRow}") : RadiographyStyleHelper::applyCurrencyFormat($sheet, "D{$navRow}");
                $sheet->getRowDimension($navRow)->setRowHeight(22);
                $navRow++;
            }
            $navRow++;

            $this->colHeaders($sheet, $navRow, ['A' => 'MÉTRICA', 'B' => 'VALOR', 'C' => '%']);
            $headerRow = $navRow;
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

            // EBITDA y gastos totales se calculan más abajo tras construir el desglose de nómina.

            $r = $headerRow + 1;

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

            // 2. Ingresos (per branch from calculator) — desglose por componente
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '2. INGRESOS / RECUPERACIÓN');
            $r++;
            $bCapital    = $calc ? (float)$calc['capital_recuperado']    : 0.0;
            $bIntereses  = $calc ? (float)$calc['interes_recuperado']    : 0.0;
            $bImpuestos  = $calc ? (float)$calc['impuesto_recuperado']   : 0.0;
            $bMulMor     = $calc ? (float)$calc['charges']               : 0.0;
            $bCargosIni  = $calc ? (float)$calc['cargos_inicio']         : 0.0;
            $bComAp      = $calc ? (float)$calc['comision_apertura']     : 0.0;
            $bSegExcl    = $calc ? (float)$calc['seguro_excluido_bruto'] : 0.0;
            $bCondExcl   = $calc ? (float)$calc['condonacion_excluida']  : 0.0;
            $bUnifExcl   = $calc ? (float)$calc['unificacion_excluida']  : 0.0;
            $bIngrTotal  = $calc ? (float)$calc['recuperacion_total']    : 0.0;
            $bIngrItems = [
                'Capital'                          => $bCapital,
                'Intereses'                        => $bIntereses,
                'Impuestos'                        => $bImpuestos,
                'Multas / Moratorios'              => $bMulMor,
                'Cargos al inicio'                 => $bCargosIni,
                'Comisión por apertura'            => $bComAp,
                '(-) Seguros/Coberturas excluidos' => -$bSegExcl,
                '(-) Condonaciones excluidas'      => -$bCondExcl,
                '(-) Unificación cartera excluida' => -$bUnifExcl,
            ];
            $bIngrIdx = 0;
            foreach ($bIngrItems as $bILabel => $bIVal) {
                // Always show rows (including $0) so users can confirm each component
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
            $brNomina    = $calc ? (float)$calc['nomina_total']        : 0.0;
            $brComisions = $calc ? (float)$calc['comisiones']          : 0.0;
            $brBonos     = $calc ? (float)$calc['bonos']               : 0.0;
            $brBonAcel   = $calc ? (float)$calc['bonos_aceleradores']  : 0.0;
            $brVacac     = $calc ? (float)$calc['vacaciones']          : 0.0;
            $brPrimaVac  = $calc ? (float)$calc['prima_vacacional']    : 0.0;
            $brNomDet    = (array)($calc['nomina_detalle'] ?? []);

            // 24 mandatory rows — always shown even if $0
            $brNomDisplay = [
                'Nómina'                                    => $brNomina,
                'Comisiones'                                => $brComisions,
                'Vacaciones'                                => $brVacac,
                'Prima vacacional'                          => $brPrimaVac,
                'Bonos'                                     => $brBonos,
                'Bonos Aceleradores'                        => $brBonAcel,
                'IMSS'                                      => 0.0,
                'Descuentos Infonavit'                      => 0.0,
                'Finiquito'                                 => 0.0,
                'Gastos médicos'                            => 0.0,
                'Gasolina'                                  => 0.0,
                'Financiamiento De Motos'                   => 0.0,
                'Descuento Servicios Moto'                  => 0.0,
                'Financiamiento Celular'                    => 0.0,
                'Cascos'                                    => 0.0,
                'Descuento de uniformes'                    => 0.0,
                'Descuento gastos sin comprobar'            => 0.0,
                'Descuento extravío tarjeta de circulación' => 0.0,
                'Descuento tienda Mr Lana'                  => 0.0,
                'Descuento Servicios Automóvil'             => 0.0,
                'Descuento faltante en caja'                => 0.0,
                'Anticipo de nómina'                        => 0.0,
                'Formatería'                                => 0.0,
                'Pensión Alimenticia'                       => 0.0,
            ];
            $brNomAlias = [
                'Financiamiento de Motos'   => 'Financiamiento De Motos',
                'Descuentos Tienda Mr Lana' => 'Descuento tienda Mr Lana',
            ];
            $brMandatory24 = array_keys($brNomDisplay);
            $brClaimed = [];
            foreach ($brNomDet as $detKey => $detVal) {
                $canonical = $brNomAlias[$detKey] ?? $detKey;
                if (array_key_exists($canonical, $brNomDisplay)) {
                    $brNomDisplay[$canonical] += (float) $detVal;
                    $brClaimed[$detKey] = true;
                }
            }
            foreach ($brNomDet as $detKey => $detVal) {
                if (!isset($brClaimed[$detKey]) && (float) $detVal > 0) {
                    $brNomDisplay[$detKey] = ($brNomDisplay[$detKey] ?? 0.0) + (float) $detVal;
                }
            }

            $brNomTotal = 0.0;
            $i2         = 0;
            foreach ($brNomDisplay as $nomName => $nomVal) {
                if ($nomVal == 0.0 && !in_array($nomName, $brMandatory24)) continue;
                $sheet->setCellValue("A{$r}", $nomName);
                $sheet->setCellValue("B{$r}", $nomVal);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i2 % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $nomVal);
                $brNomTotal += $nomVal;
                $i2++;
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'Total Nómina y Capital Humano');
            $sheet->setCellValue("B{$r}", $brNomTotal);
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
            $brGastosTotal    = $gopTotal + $brNomTotal;
            $brUtilidad       = $recB - $colB - $brGastosTotal;
            $brFondeoB        = $calc ? (float)$calc['prestamos_fondea'] : $fondeoCalc;
            $brExcedCalc      = $calc ? (float)$calc['excedentes']       : $excedCalc;
            $brInconsistencia = $brExcedCalc > $brUtilidad;
            $brDiferencia     = $brUtilidad - $brExcedCalc;
            foreach ([
                ['Saldo inicial en caja',              0,              'currency'],
                ['Ingresos Totales',                   $recB,          'currency'],
                ['Otorgamientos',                      $colB,          'currency'],
                ['Gastos Totales',                     $brGastosTotal, 'currency'],
                ['EBITDA',                             $brUtilidad,    'currency'],
                ['Saldo final en caja',                0,              'currency'],
                ['Préstamos inter sucursales',         $brFondeoB,     'currency'],
                ['Envío de utilidad a corporativo',    $brExcedCalc,   'currency'],
                ['Diferencia / sobrante',              $brDiferencia,  'currency'],
                ['Mora de 0 a 30 días',                $mora0_30,      'currency'],
                ['Mora de 31 a 60 días',               $mora31_60,     'currency'],
                ['Mora de 61 a 90 días',               $mora61_90,     'currency'],
                ['Mora de 91 a 120 días',              $mora91120,     'currency'],
                ['Mora 120+ días',                     $mora120p,      'currency'],
                ['Valor cartera',                      $carteraB,      'currency'],
            ] as $i => [$label, $val, $fmt]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $val);
                $r++;
            }
            if ($brInconsistencia) {
                $this->writeInconsistenciaRow($sheet, $r, 'C');
                $r++;
            }
            $r++;

            // 8. EBITDA = Ingresos − Otorgamientos − Gastos Totales
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '8. EBITDA');
            $r++;
            foreach ([
                ['Ingresos Totales',                   $recB,          'currency'],
                ['Más: Colocación del periodo',        $colB,          'currency'],
                ['Menos: Gastos Totales',              $brGastosTotal, 'currency'],
                ['  Gastos operativos',                $gopTotal,      'currency'],
                ['  Nómina y Capital Humano',           $brNomTotal,    'currency'],
                ['= EBITDA',     $brUtilidad,    'currency'],
                ['Envío utilidad a corporativo',       $brExcedCalc,   'currency'],
                ['Diferencia / sobrante',              $brDiferencia,  'currency'],
            ] as $i => [$label, $val, $fmt]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $val);
                $r++;
            }
            if ($brInconsistencia) {
                $this->writeInconsistenciaRow($sheet, $r, 'C');
                $r++;
            }
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
                RadiographyStyleHelper::mergeCellsSafe($sheet,"B{$r}:C{$r}");
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $sheet->getRowDimension($r)->setRowHeight(24);
                $r++;
            }
            $r++;

            $sheet->getColumnDimension('A')->setWidth(42);
            $sheet->getColumnDimension('B')->setWidth(22);
            $sheet->getColumnDimension('C')->setWidth(10);
            $sheet->freezePane('A' . ($headerRow + 1));
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
        RadiographyStyleHelper::mergeCellsSafe($sheet,'B2:E2');

        $r = 4;

        // ── Empleados sin sucursal ────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:E{$r}", '1. EMPLEADOS SIN SUCURSAL ASIGNADA');
        $r++;

        $this->colHeaders($sheet, $r, ['A' => 'EMPLEADO', 'B' => 'P001 SUELDO', 'C' => 'P002 COMISIONES', 'D' => 'BONOS', 'E' => 'NOTA']);
        $r++;

        if (empty($empleados)) {
            $sheet->setCellValue("A{$r}", 'Sin empleados sin asignar.');
            RadiographyStyleHelper::mergeCellsSafe($sheet,"A{$r}:E{$r}");
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
            RadiographyStyleHelper::mergeCellsSafe($sheet,"A{$r}:E{$r}");
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
        RadiographyStyleHelper::mergeCellsSafe($sheet,"A{$r}:E{$r}");
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
        $operativoStartRow = null;
        $operativoEndRow   = null;
        foreach ($grupos as $tipo => $titulo) {
            $grupo = array_values(array_filter($products, fn ($p) => ($p['tipo'] ?? 'operativo') === $tipo));
            if (empty($grupo)) {
                continue;
            }

            // Section separator row
            $sheet->setCellValue("A{$r}", $titulo);
            RadiographyStyleHelper::mergeCellsSafe($sheet,"A{$r}:F{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setBold(true)->setSize(10);
            $sheet->getStyle("A{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FF1E293B');
            $sheet->getStyle("A{$r}")->getFont()->getColor()->setARGB('FFFFFFFF');
            $sheet->getStyle("A{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            $r++;

            if ($tipo === 'operativo') {
                $operativoStartRow = $r;
            }
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
            if ($tipo === 'operativo') {
                $operativoEndRow = $r - 1;
            }
        }

        $sheet->getColumnDimension('A')->setWidth(38);
        $sheet->getColumnDimension('B')->setWidth(18);
        $sheet->getColumnDimension('C')->setWidth(14);
        $sheet->getColumnDimension('D')->setWidth(20);
        $sheet->getColumnDimension('E')->setWidth(20);
        $sheet->getColumnDimension('F')->setWidth(14);
        $sheet->freezePane('A3');

        if ($operativoStartRow !== null && $operativoEndRow >= $operativoStartRow) {
            RadiographyStyleHelper::addDonutChart(
                $sheet,
                'Colocación por producto',
                "\$A\${$operativoStartRow}:\$A\${$operativoEndRow}",
                "\$D\${$operativoStartRow}:\$D\${$operativoEndRow}",
                $operativoEndRow - $operativoStartRow + 1,
                'H3',
                'P20'
            );
        }
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
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'O1', '← GLOBAL', 'GLOBAL');

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

        // ── Ranking: top 10 gestores por colocación (tabla limpia + gráfica) ────
        $r += 2;
        $top10Row = $r;
        $this->sectionHeader($sheet, "A{$r}:B{$r}", 'TOP 10 GESTORES POR COLOCACIÓN');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'GESTOR', 'B' => 'COLOCACIÓN']);
        $r++;
        $top10StartRow = $r;
        $top10 = collect($rows)->sortByDesc('colocacion')->take(10)->values();
        foreach ($top10 as $i => $emp) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $emp['name']);
            $sheet->setCellValue("B{$r}", (float)$emp['colocacion']);
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            $r++;
        }
        $top10EndRow = $r - 1;
        if ($top10EndRow >= $top10StartRow) {
            RadiographyStyleHelper::addDonutChart(
                $sheet,
                'Ranking de gestores por colocación (Top 10)',
                "\$A\${$top10StartRow}:\$A\${$top10EndRow}",
                "\$B\${$top10StartRow}:\$B\${$top10EndRow}",
                $top10EndRow - $top10StartRow + 1,
                "D{$top10Row}",
                'L' . ($top10Row + 16)
            );
        }
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
            ['Valor cartera',       $sum['portfolio_total'],   'currency'],
            ['Cartera vencida',     $sum['overdue_portfolio'],  'currency'],
            ['Índice de mora',      $sum['mora_index'],        'percent'],
        ];
        $r = 5;
        foreach ($globalRows as $i => [$label, $value, $fmt]) {
            RadiographyStyleHelper::mergeCellsSafe($sheet,"B{$r}:E{$r}");
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
        RadiographyStyleHelper::mergeCellsSafe($sheet,"A1:{$lastCol}1");

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
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'G1', '← GLOBAL', 'GLOBAL');
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
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'J1', '← GLOBAL', 'GLOBAL');

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
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'N1', '← GLOBAL', 'GLOBAL');

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
                                'mora_61_90', 'mora_91_120', 'mora_120_plus'], 0.0);

        foreach ($rows as $i => $row) {
            $bg = $i % 2 === 0 ? self::BG_GREEN_ROW : self::BG_EVEN;

            // capital_due available per bucket not per row; sum all vencida buckets as best proxy
            $vencida = (float)($row['vencida_total'] ?? 0);
            $m0_30   = (float)($row['mora_1_30']    ?? 0);
            $m31_60  = (float)($row['mora_31_60']   ?? 0);
            $m61_90  = (float)($row['mora_61_90']   ?? 0);
            $m91_120 = (float)($row['mora_91_120']  ?? 0);
            $m120p   = (float)($row['mora_120_plus'] ?? 0);

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
            $tot['mora_120_plus']  += $m120p;
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
        $sheet->setCellValue("K{$r}", $tot['mora_120_plus']);
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
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'F1', '← GLOBAL', 'GLOBAL');

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
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'E1', '← GLOBAL', 'GLOBAL');

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
        $this->greenTitle($sheet, 'A1:I1', "VALOR CARTERA {$mesLabel} - {$anioLabel}");
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'K1', '← GLOBAL', 'GLOBAL');
        // 9 columns: Suc/Prod, Cartera, Cap atras, Int atras, Imp atras, SI morat, SII morat, Vencida, Mora%
        $this->greenColHeaders($sheet, 2, [
            'A' => 'SUCURSAL / PRODUCTO',
            'B' => 'VALOR CRÉDITO ADEUDO',
            'C' => 'CAPITAL ATRASADO',
            'D' => 'INTERÉS ATRASADO',
            'E' => 'IMPUESTO ATRASADO',
            'F' => 'SALDO INT. MORAT.',
            'G' => 'SALDO IMP. INT. MORAT.',
            'H' => 'CARTERA VENCIDA',
            'I' => 'MORA %',
        ]);

        if (empty($rows)) {
            $sheet->setCellValue('A3', 'Sin datos de cartera para este periodo.');
            $this->setColWidths($sheet, ['A' => 36, 'B' => 22, 'C' => 18, 'D' => 18, 'E' => 18, 'F' => 18, 'G' => 22, 'H' => 22, 'I' => 10]);
            return;
        }

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['branch']][] = $row;
        }

        $r = 3;
        $gCartera = $gVencida = 0.0;

        foreach ($grouped as $branch => $products) {
            $bCartera = array_sum(array_column($products, 'cartera'));
            $bCap     = array_sum(array_column($products, 'capital_atrasado'));
            $bInt     = array_sum(array_column($products, 'interes_atrasado'));
            $bImp     = array_sum(array_column($products, 'impuesto_atrasado'));
            $bSim     = array_sum(array_column($products, 'saldo_interes_moratorio'));
            $bSii     = array_sum(array_column($products, 'saldo_impuesto_interes_moratorio'));
            $bVencida = array_sum(array_column($products, 'vencida'));
            $bMora    = $bCartera > 0 ? round($bVencida / $bCartera * 100, 2) : 0.0;

            $sheet->setCellValue("A{$r}", strtoupper($branch));
            foreach (['B'=>$bCartera,'C'=>$bCap,'D'=>$bInt,'E'=>$bImp,'F'=>$bSim,'G'=>$bSii,'H'=>$bVencida] as $col => $val) {
                $sheet->setCellValue("{$col}{$r}", $val);
            }
            $sheet->setCellValue("I{$r}", $bMora);
            $sheet->getStyle("A{$r}:I{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_WHITE]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_HDR]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            ]);
            foreach (['B','C','D','E','F','G','H'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("I{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("I{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;

            foreach ($products as $i => $prod) {
                if ((float)$prod['cartera'] <= 0) continue;
                $bg = $i % 2 === 0 ? self::BG_GREEN_ROW : self::BG_EVEN;
                $sheet->setCellValue("A{$r}", '    ' . $prod['product']);
                $sheet->setCellValue("B{$r}", (float)$prod['cartera']);
                $sheet->setCellValue("C{$r}", (float)($prod['capital_atrasado'] ?? 0));
                $sheet->setCellValue("D{$r}", (float)($prod['interes_atrasado'] ?? 0));
                $sheet->setCellValue("E{$r}", (float)($prod['impuesto_atrasado'] ?? 0));
                $sheet->setCellValue("F{$r}", (float)($prod['saldo_interes_moratorio'] ?? 0));
                $sheet->setCellValue("G{$r}", (float)($prod['saldo_impuesto_interes_moratorio'] ?? 0));
                $sheet->setCellValue("H{$r}", (float)$prod['vencida']);
                $sheet->setCellValue("I{$r}", (float)$prod['mora']);
                $sheet->getStyle("A{$r}:I{$r}")->applyFromArray([
                    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => 'FFD1FAE5']]],
                    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
                ]);
                $sheet->getStyle("A{$r}")->getFont()->setSize(9);
                foreach (['B','C','D','E','F','G','H'] as $col) {
                    $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                    $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                    $sheet->getStyle("{$col}{$r}")->getFont()->setSize(9);
                }
                $sheet->getStyle("I{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
                $sheet->getStyle("I{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                $sheet->getStyle("I{$r}")->getFont()->setSize(9);
                if ((float)$prod['mora'] > 25) {
                    $sheet->getStyle("I{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
                }
                $sheet->getRowDimension($r)->setRowHeight(16);
                $r++;
            }

            $gCartera += $bCartera;
            $gVencida += $bVencida;
        }

        $gMora = $gCartera > 0 ? round($gVencida / $gCartera * 100, 2) : 0.0;
        $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $gCartera);
        $sheet->setCellValue("H{$r}", $gVencida);
        $sheet->setCellValue("I{$r}", $gMora);
        $sheet->getStyle("A{$r}:I{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_GREEN_TOT]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF064E3B']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("H{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->getStyle("H{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getStyle("I{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
        $sheet->getStyle("I{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sheet->getRowDimension($r)->setRowHeight(20);

        $this->setColWidths($sheet, ['A'=>36,'B'=>22,'C'=>18,'D'=>18,'E'=>18,'F'=>18,'G'=>22,'H'=>22,'I'=>10]);
        $sheet->setAutoFilter("A2:I2");
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
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'E1', '← GLOBAL', 'GLOBAL');

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
        $sheet   = $ss->createSheet()->setTitle('COLOCACIÓN');
        $rows    = $snap['sections']['placement_by_branch_product'] ?? [];
        // Authoritative KPI total — from BranchRadiographyCalculator (same as dashboard/PDF)
        $kpiTotal = (float)($snap['branch_radiography']['global']['colocacion'] ?? 0);

        [$mesLabel, $anioLabel] = $this->periodMonthYear($period);
        $this->greenTitle($sheet, 'A1:C1', $mesLabel);
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'E1', '← GLOBAL', 'GLOBAL');

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

        // Use authoritative KPI total ($kpiTotal) — matches dashboard, PDF, UI
        $totDisplay = $kpiTotal > 0 ? $kpiTotal : $grandMonto;
        $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $totDisplay);
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
        RadiographyStyleHelper::mergeCellsSafe($sheet,"A{$r}:C{$r}");
        $sheet->setCellValue("A{$r}", 'No hay datos de comisión por apertura disponibles en la fuente actual.');
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF64748B');

        $this->setColWidths($sheet, ['A' => 36, 'B' => 24, 'C' => 20]);
        $sheet->setAutoFilter("A3:C3");
        $sheet->freezePane('A4');
    }

    // ── Categorías ────────────────────────────────────────────────────────────

    /**
     * Detalle completo de CATEGORÍA POR EBITDA por sucursal. Misma fuente y
     * misma fórmula que la sección compacta en GLOBAL y que la tabla del PDF
     * ("CATEGORÍA GESTORES POR EBITDA") — centralizada en
     * RadiographyStyleHelper::branchEbitdaEstimate()/ebitdaCategory(), nunca
     * recalculada de forma distinta aquí.
     */
    private function buildCategoriaEbitdaSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet    = $ss->createSheet()->setTitle('CATEGORÍA EBITDA');
        $branches = $snap['branch_radiography']['branches'] ?? [];
        $label    = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:F1', 'CATEGORÍA POR EBITDA — ' . $label);
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');

        if (empty($branches)) {
            $sheet->setCellValue('A3', 'Sin datos por sucursal para calcular categorías.');
            $this->setColWidths($sheet, ['A' => 28, 'B' => 18, 'C' => 18, 'D' => 18, 'E' => 20, 'F' => 16]);
            return;
        }

        usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));

        $this->colHeaders($sheet, 4, [
            'A' => 'SUCURSAL', 'B' => 'RECUPERACIÓN', 'C' => 'GASTOS', 'D' => 'NÓMINA',
            'E' => 'EBITDA ESTIMADO', 'F' => 'CATEGORÍA',
        ]);
        $r = 5;
        foreach ($branches as $i => $b) {
            $nomina = (float)($b['nomina_total'] ?? 0) + (float)($b['comisiones'] ?? 0) + (float)($b['bonos'] ?? 0)
                + (float)($b['vacaciones'] ?? 0) + (float)($b['prima_vacacional'] ?? 0)
                + array_sum((array)($b['nomina_detalle'] ?? []));
            $rec       = (float)($b['recuperacion_total'] ?? 0);
            $gastos    = (float)($b['gastos_operativos'] ?? 0);
            $utilidad  = RadiographyStyleHelper::branchEbitdaEstimate($b);
            $categoria = RadiographyStyleHelper::ebitdaCategory($utilidad);
            $colors    = RadiographyStyleHelper::categoryColors($categoria);

            $sheet->setCellValue("A{$r}", $b['sucursal']);
            $sheet->setCellValue("B{$r}", $rec);
            $sheet->setCellValue("C{$r}", $gastos);
            $sheet->setCellValue("D{$r}", $nomina);
            $sheet->setCellValue("E{$r}", $utilidad);
            $sheet->setCellValue("F{$r}", $categoria);
            $this->dataRow($sheet, "A{$r}:F{$r}", $i % 2 === 0);
            foreach (['B', 'C', 'D', 'E'] as $col) {
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$col}{$r}");
            }
            $sheet->getStyle("F{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9.5, 'color' => ['argb' => $colors['fg']]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colors['bg']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;
        }

        $sheet->setCellValue("A{$r}", 'EBITDA estimado = Recuperación − Gastos − Nómina completa estimada por sucursal.');
        RadiographyStyleHelper::mergeCellsSafe($sheet, "A{$r}:F{$r}");
        $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(8)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color(RadiographyStyleHelper::FG_DARK_TEXT));

        $this->setColWidths($sheet, ['A' => 26, 'B' => 18, 'C' => 16, 'D' => 16, 'E' => 18, 'F' => 14]);
        $sheet->setAutoFilter('A4:F4');
        $sheet->freezePane('A5');
    }

    // ── EBITDA helpers ────────────────────────────────────────────────────────

    /**
     * Escribe fila roja de INCONSISTENCIA EBITDA en la hoja activa.
     * $lastCol: última columna del rango ('C' para 3 cols, 'D' para 4 cols).
     */
    private function writeInconsistenciaRow(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, string $lastCol = 'C'): void
    {
        $range = "A{$row}:{$lastCol}{$row}";
        $sheet->setCellValue("A{$row}", '⚠ INCONSISTENCIA: El envío a corporativo supera la utilidad disponible. Revisar ingresos, gastos, otorgamientos o envío corporativo.');
        RadiographyStyleHelper::mergeCellsSafe($sheet,$range);
        $sheet->getStyle($range)->applyFromArray([
            'fill'      => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEF2F2']],
            'font'      => ['bold' => true, 'color' => ['argb' => 'FFB91C1C'], 'size' => 9],
            'borders'   => ['allBorders' => ['borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN, 'color' => ['argb' => 'FFEF4444']]],
            'alignment' => ['wrapText' => true, 'vertical' => \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getRowDimension($row)->setRowHeight(30);
    }

    /**
     * Total Nómina y Capital Humano = suma de todos los conceptos configurados (todos positivos).
     * Regla contable: las D de NOI que aparecen en la radiografía se suman, no se restan.
     */
    private function calcNomTotal(array $calc): float
    {
        $total = (float)($calc['nomina_total']      ?? 0)
               + (float)($calc['comisiones']        ?? 0)
               + (float)($calc['bonos']             ?? 0)
               + (float)($calc['vacaciones']        ?? 0)
               + (float)($calc['prima_vacacional']  ?? 0);

        foreach ((array)($calc['nomina_detalle'] ?? []) as $val) {
            $total += (float)$val;
        }

        return $total;
    }

    // ── Style helpers ─────────────────────────────────────────────────────────

    // Delegates to RadiographyStyleHelper, the single source of truth for all
    // workbook styling/colors. Kept as thin wrappers so the ~150 existing call
    // sites across this file don't need to change while colors/sizes evolve.

    private function sheetTitle(Worksheet $sheet, string $range, string $text): void
    {
        RadiographyStyleHelper::applyTitleStyle($sheet, $range, $text);
    }

    private function metaStyle(Worksheet $sheet, string $range): void
    {
        RadiographyStyleHelper::applyMetaStyle($sheet, $range);
    }

    private function sectionHeader(Worksheet $sheet, string $range, string $text): void
    {
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, $range, $text);
    }

    private function colHeaders(Worksheet $sheet, int $row, array $cols): void
    {
        RadiographyStyleHelper::applyTableHeaderStyle($sheet, $row, $cols, accent: false);
    }

    private function dataRow(Worksheet $sheet, string $range, bool $even): void
    {
        RadiographyStyleHelper::applyDataRowStyle($sheet, $range, $even);
    }

    private function totalsRow(Worksheet $sheet, string $range): void
    {
        RadiographyStyleHelper::applyTotalRowStyle($sheet, $range);
    }

    private function applyFmt(Worksheet $sheet, string $cell, string $fmt, mixed $value): void
    {
        match ($fmt) {
            'currency' => RadiographyStyleHelper::applyCurrencyFormat($sheet, $cell),
            'percent'  => RadiographyStyleHelper::applyPercentFormat($sheet, $cell, (float)$value),
            'integer'  => RadiographyStyleHelper::applyIntegerFormat($sheet, $cell),
            default    => null,
        };
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
        RadiographyStyleHelper::applyTitleStyle($sheet, $range, $text);
    }

    private function greenColHeaders(Worksheet $sheet, int $row, array $cols): void
    {
        RadiographyStyleHelper::applyTableHeaderStyle($sheet, $row, $cols, accent: true);
    }

    private function setColWidths(Worksheet $sheet, array $widths): void
    {
        RadiographyStyleHelper::setColWidths($sheet, $widths);
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
        $mora0_30  = (float)($morRow['mora_1_30']    ?? 0);
        $mora31_60 = (float)($morRow['mora_31_60']  ?? 0);
        $mora61_90 = (float)($morRow['mora_61_90']  ?? 0);
        $mora91120 = (float)($morRow['mora_91_120'] ?? 0);
        $mora120p  = (float)($morRow['mora_120_plus'] ?? 0);

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

        // EBITDA = Ingresos − Otorgamientos − (GastosOp + NóminaNet)
        // $nomTotal from payBC already excludes DESCUENTO/DEDUCCION keywords
        $gopTotalBW = $gopTotal > 0 ? $gopTotal : $gastosB;
        $utilidad   = $recB - $colB - ($gopTotalBW + $nomTotal);

        // ── Sheet 1: RESUMEN ─────────────────────────────────────────────────
        $sheet = $spreadsheet->getActiveSheet()->setTitle('RESUMEN');
        $title = strtoupper($branchName) . ' — ' . strtoupper($period->label);
        $this->sheetTitle($sheet, 'A1:D1', $title);
        RadiographyStyleHelper::mergeCellsSafe($sheet,'A2:D2');
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

        $bwGastosTotal = $gopTotalBW + $nomTotal;
        $this->sectionHeader($sheet, "A{$r}:D{$r}", '7. ANÁLISIS DE TENDENCIAS Y PROYECCIONES'); $r++;
        foreach ([
            ['Ingresos Totales',            $recB,          'currency'],
            ['Otorgamientos',               $colB,          'currency'],
            ['Gastos Totales',              $bwGastosTotal, 'currency'],
            ['EBITDA',                      $utilidad,      'currency'],
            ['Préstamos intersucursales',   $loanFondea,    'currency'],
            ['Mora 0-30 días',              $mora0_30,      'currency'],
            ['Mora 31-60 días',             $mora31_60,     'currency'],
            ['Mora 61-90 días',             $mora61_90,     'currency'],
            ['Mora 91-120 días',            $mora91120,     'currency'],
            ['Mora 120+ días',              $mora120p,      'currency'],
            ['Valor cartera',               $carteraB,      'currency'],
        ] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $r++;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '8. EBITDA'); $r++;
        foreach ([
            ['Ingresos Totales',             $recB,          'currency'],
            ['Menos: Otorgamientos',         $colB,          'currency'],
            ['Menos: Gastos Totales',        $bwGastosTotal, 'currency'],
            ['  Gastos operativos',          $gopTotalBW,    'currency'],
            ['  Nómina neta',                $nomTotal,      'currency'],
            ['= EBITDA del periodo',         $utilidad,      'currency'],
        ] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
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
            RadiographyStyleHelper::mergeCellsSafe($sheet,"B{$r}:D{$r}");
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

        // EBITDA = Ingresos − Otorgamientos − (Gastos + NóminaNet)
        $utilidad  = $rec - $coloc - ($gastos + $pagos + $bonos - $desctos);

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("Radiografía Gestor {$empName} — {$period->label}")
            ->setCreator('Sistema de Reportes');

        // ── Sheet 1: RESUMEN ─────────────────────────────────────────────────
        $sheet = $spreadsheet->getActiveSheet()->setTitle('RESUMEN');
        $this->sheetTitle($sheet, 'A1:D1', mb_strtoupper($empName) . ' — ' . strtoupper($period->label));
        RadiographyStyleHelper::mergeCellsSafe($sheet,'A2:D2');
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

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '4. EBITDA ESTIMADO'); $r++;
        $sheet->setCellValue("A{$r}", 'Rec − Otorg − (Gastos + Pagos + Bonos − Desctos)');
        $sheet->setCellValue("B{$r}", $utilidad);
        $this->dataRow($sheet, "A{$r}:D{$r}", true);
        $this->applyFmt($sheet, "B{$r}", 'currency', $utilidad);
        $r += 2;

        if ($extraExpenseNotes) {
            $this->sectionHeader($sheet, "A{$r}:D{$r}", 'OBSERVACIONES'); $r++;
            $sheet->setCellValue("A{$r}", $extraExpenseNotes);
            RadiographyStyleHelper::mergeCellsSafe($sheet,"A{$r}:D{$r}");
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
        RadiographyStyleHelper::mergeCellsSafe($sheet,'A2:G2');
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
        // EBITDA = Ingresos − Otorgamientos − (GastosOp + NóminaNet)
        $utilPrev = $get($compareSnap,'recuperacion') - $get($compareSnap,'colocacion') - ($get($compareSnap,'gastos') + $get($compareSnap,'pagos') + $get($compareSnap,'bonos') - $get($compareSnap,'descuentos'));
        $utilCurr = $get($currentSnap,'recuperacion') - $get($currentSnap,'colocacion') - ($get($currentSnap,'gastos') + $get($currentSnap,'pagos') + $get($currentSnap,'bonos') - $get($currentSnap,'descuentos'));
        $writeComp($r, 'EBITDA estimado', $utilPrev, $utilCurr, 'currency', true);
        $r++;

        // ── Section 8: EBITDA ────────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '8. EBITDA'); $r++;
        $writeComp($r, 'EBITDA estimado', $utilPrev, $utilCurr, 'currency', true);
        $r++;

        // ── Sections 9-10: N/A ───────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '9. SALDO TOTAL ACUMULADO CUENTAS'); $r++;
        $sheet->setCellValue("A{$r}", 'Sin datos disponibles'); $this->dataRow($sheet, "A{$r}:G{$r}", true); $r += 2;
        $this->sectionHeader($sheet, "A{$r}:G{$r}", '10. OBSERVACIONES Y NOTAS'); $r++;
        $sheet->setCellValue("A{$r}", 'Comparativo generado automáticamente. Revisar con fuentes originales para detalles.');
        RadiographyStyleHelper::mergeCellsSafe($sheet,"A{$r}:G{$r}");
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

    // ── NÓMINA POR GESTOR ────────────────────────────────────────────────────

    private function buildNominaGestorSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('NÓMINA POR GESTOR');
        $label = strtoupper($period->label);
        $this->sheetTitle($sheet, 'A1:M1', 'NÓMINA POR GESTOR — ' . $label);
        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);

        // All dataIds (weekly base periods + this period)
        $allPeriods = \App\Models\Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $amtExpr = "CASE
            WHEN LOWER(COALESCE(n.concept_type,'')) LIKE '%comisi%' OR LOWER(COALESCE(n.concept,'')) LIKE '%comisi%' THEN n.amount
            ELSE 0 END";

        $rows = \Illuminate\Support\Facades\DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period) {
                $j->on('eba.employee_id', '=', 'n.employee_id')->where('eba.period_id', '=', $period->id);
            })
            ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->selectRaw("
                COALESCE(b.name, 'Sin sucursal') as sucursal,
                e.full_name as empleado,
                SUM(CASE WHEN LOWER(COALESCE(n.concept_type,''))='percepcion'
                             AND LOWER(COALESCE(n.concept,'')) NOT LIKE '%bono%'
                             AND LOWER(COALESCE(n.concept,'')) NOT LIKE '%comisi%'
                             AND LOWER(COALESCE(n.concept,'')) NOT LIKE '%vacaci%'
                             AND LOWER(COALESCE(n.concept,'')) NOT LIKE '%prima%'
                         THEN n.amount ELSE 0 END) as sueldos,
                SUM(CASE WHEN LOWER(COALESCE(n.concept,'')) LIKE '%comisi%' THEN n.amount ELSE 0 END) as comisiones,
                SUM(CASE WHEN LOWER(COALESCE(n.concept_type,''))='percepcion'
                              AND LOWER(COALESCE(n.concept,'')) LIKE '%bono%'
                         THEN n.amount ELSE 0 END) as bonos,
                SUM(CASE WHEN LOWER(COALESCE(n.concept,'')) LIKE '%vacaci%' THEN n.amount ELSE 0 END) as vacaciones,
                SUM(CASE WHEN LOWER(COALESCE(n.concept,'')) LIKE '%prima%' THEN n.amount ELSE 0 END) as prima_vacacional,
                SUM(CASE WHEN LOWER(COALESCE(n.concept_type,'')) IN ('deduccion','descuento') THEN n.amount ELSE 0 END) as descuentos,
                COUNT(n.id) as registros
            ")
            ->groupBy('e.id', 'e.full_name', 'b.name')
            ->orderBy('sucursal')
            ->orderBy('e.full_name')
            ->get();

        $headers = [
            'A' => 'SUCURSAL', 'B' => 'EMPLEADO / GESTOR',
            'C' => 'SUELDOS', 'D' => 'COMISIONES', 'E' => 'BONOS',
            'F' => 'VACACIONES', 'G' => 'PRIMA VACACIONAL',
            'H' => 'DESCUENTOS', 'I' => 'TOTAL NÓMINA', 'J' => 'REGISTROS',
        ];
        $this->colHeaders($sheet, 3, $headers);
        $r = 4;

        $currBranch   = null;
        $branchTotals = array_fill_keys(['sueldos','comisiones','bonos','vacaciones','prima_vacacional','descuentos','total'], 0.0);
        $grandTotals  = $branchTotals;
        $rowIdx       = 0;

        $writeTotals = function (string $branch, array $t) use ($sheet, &$r) {
            $this->sectionHeader($sheet, "A{$r}:J{$r}", 'SUBTOTAL — ' . strtoupper($branch));
            $sheet->setCellValue("C{$r}", $t['sueldos']);
            $sheet->setCellValue("D{$r}", $t['comisiones']);
            $sheet->setCellValue("E{$r}", $t['bonos']);
            $sheet->setCellValue("F{$r}", $t['vacaciones']);
            $sheet->setCellValue("G{$r}", $t['prima_vacacional']);
            $sheet->setCellValue("H{$r}", $t['descuentos']);
            $sheet->setCellValue("I{$r}", $t['total']);
            foreach (['C','D','E','F','G','H','I'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }
            $r++;
        };

        foreach ($rows as $emp) {
            if ($currBranch !== null && $currBranch !== $emp->sucursal) {
                $writeTotals($currBranch, $branchTotals);
                $branchTotals = array_fill_keys(array_keys($branchTotals), 0.0);
                $r++;
                $rowIdx = 0;
            }
            $currBranch = $emp->sucursal;

            $total = (float)$emp->sueldos + (float)$emp->comisiones + (float)$emp->bonos
                   + (float)$emp->vacaciones + (float)$emp->prima_vacacional - (float)$emp->descuentos;

            $sheet->setCellValue("A{$r}", $emp->sucursal);
            $sheet->setCellValue("B{$r}", $emp->empleado);
            $sheet->setCellValue("C{$r}", (float)$emp->sueldos);
            $sheet->setCellValue("D{$r}", (float)$emp->comisiones);
            $sheet->setCellValue("E{$r}", (float)$emp->bonos);
            $sheet->setCellValue("F{$r}", (float)$emp->vacaciones);
            $sheet->setCellValue("G{$r}", (float)$emp->prima_vacacional);
            $sheet->setCellValue("H{$r}", (float)$emp->descuentos);
            $sheet->setCellValue("I{$r}", $total);
            $sheet->setCellValue("J{$r}", (int)$emp->registros);
            $this->dataRow($sheet, "A{$r}:J{$r}", $rowIdx % 2 === 0);
            foreach (['C','D','E','F','G','H','I'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }

            $branchTotals['sueldos']          += (float)$emp->sueldos;
            $branchTotals['comisiones']        += (float)$emp->comisiones;
            $branchTotals['bonos']             += (float)$emp->bonos;
            $branchTotals['vacaciones']        += (float)$emp->vacaciones;
            $branchTotals['prima_vacacional']  += (float)$emp->prima_vacacional;
            $branchTotals['descuentos']        += (float)$emp->descuentos;
            $branchTotals['total']             += $total;

            $grandTotals['sueldos']          += (float)$emp->sueldos;
            $grandTotals['comisiones']        += (float)$emp->comisiones;
            $grandTotals['bonos']             += (float)$emp->bonos;
            $grandTotals['vacaciones']        += (float)$emp->vacaciones;
            $grandTotals['prima_vacacional']  += (float)$emp->prima_vacacional;
            $grandTotals['descuentos']        += (float)$emp->descuentos;
            $grandTotals['total']             += $total;

            $rowIdx++;
            $r++;
        }

        if ($currBranch !== null) {
            $writeTotals($currBranch, $branchTotals);
            $r++;
        }

        // Grand total
        $this->totalsRow($sheet, "A{$r}:J{$r}");
        $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("C{$r}", $grandTotals['sueldos']);
        $sheet->setCellValue("D{$r}", $grandTotals['comisiones']);
        $sheet->setCellValue("E{$r}", $grandTotals['bonos']);
        $sheet->setCellValue("F{$r}", $grandTotals['vacaciones']);
        $sheet->setCellValue("G{$r}", $grandTotals['prima_vacacional']);
        $sheet->setCellValue("H{$r}", $grandTotals['descuentos']);
        $sheet->setCellValue("I{$r}", $grandTotals['total']);
        foreach (['C','D','E','F','G','H','I'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);

        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(36);
        foreach (['C','D','E','F','G','H','I'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(15);
        }
        $sheet->getColumnDimension('J')->setWidth(10);
        $sheet->setAutoFilter('A3:J3');
        $sheet->freezePane('C4');
    }

    // ── MORA DETALLE (por producto / gestor / sucursal+producto) ─────────────

    private function buildMoraDetalleSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle('MORA DETALLE');
        $label = strtoupper($period->label);
        $this->sheetTitle($sheet, 'A1:J1', 'MORA DETALLADA — ' . $label);
        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);

        $bucketHeaders = ['Al corriente', 'Mora 1-30', 'Mora 31-60', 'Mora 61-90', 'Mora 91-120', 'Mora 120+'];
        $bucketKeys    = ['al_corriente', 'mora_1_30', 'mora_31_60', 'mora_61_90', 'mora_91_120', 'mora_120_plus'];

        $r = 4;

        // ── Sección 1: Mora por producto ─────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:J{$r}", '1. MORA POR PRODUCTO');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'PRODUCTO', 'B' => 'CONTRATOS', 'C' => 'CARTERA',
            'D' => 'VENCIDO', 'E' => 'MORA %',
            'F' => 'AL CORRIENTE', 'G' => 'MORA 1-30', 'H' => 'MORA 31-60',
            'I' => 'MORA 61-90', 'J' => 'MORA 91-120', 'K' => 'MORA 120+',
        ]);
        $r++;

        $moraByProduct = $snap['sections']['mora_by_product'] ?? [];
        $totCont = 0; $totCart = 0.0; $totVenc = 0.0;
        $totBuckets = array_fill_keys($bucketKeys, 0.0);
        foreach ($moraByProduct as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['product']);
            $sheet->setCellValue("B{$r}", $row['contratos']);
            $sheet->setCellValue("C{$r}", $row['cartera']);
            $sheet->setCellValue("D{$r}", $row['vencida']);
            $sheet->setCellValue("E{$r}", $row['mora_pct']);
            foreach ($bucketKeys as $idx => $key) {
                $col = chr(ord('F') + $idx);
                $sheet->setCellValue("{$col}{$r}", $row[$key] ?? 0.0);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                $totBuckets[$key] += $row[$key] ?? 0.0;
            }
            $this->dataRow($sheet, "A{$r}:K{$r}", $i % 2 === 0);
            foreach (['C','D'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $totCont += $row['contratos']; $totCart += $row['cartera']; $totVenc += $row['vencida'];
            $r++;
        }
        $this->totalsRow($sheet, "A{$r}:K{$r}");
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("B{$r}", $totCont);
        $sheet->setCellValue("C{$r}", $totCart);
        $sheet->setCellValue("D{$r}", $totVenc);
        $sheet->setCellValue("E{$r}", $totCart > 0 ? round($totVenc / $totCart * 100, 2) : 0);
        foreach ($bucketKeys as $idx => $key) {
            $col = chr(ord('F') + $idx);
            $sheet->setCellValue("{$col}{$r}", $totBuckets[$key]);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }
        foreach (['C','D'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
        $r += 3;

        // ── Sección 2: Mora por gestor ────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:J{$r}", '2. MORA POR GESTOR');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'SUCURSAL', 'B' => 'GESTOR', 'C' => 'CONTRATOS',
            'D' => 'CARTERA', 'E' => 'VENCIDO', 'F' => 'MORA %',
            'G' => 'AL CORRIENTE', 'H' => 'MORA 1-30', 'I' => 'MORA 31-60',
            'J' => 'MORA 61-90', 'K' => 'MORA 91-120', 'L' => 'MORA 120+',
        ]);
        $r++;

        $moraByGestor = $snap['sections']['mora_by_gestor'] ?? [];
        $totCont2 = 0; $totCart2 = 0.0; $totVenc2 = 0.0;
        $totBuckets2 = array_fill_keys($bucketKeys, 0.0);
        foreach ($moraByGestor as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['sucursal'] ?? '—');
            $sheet->setCellValue("B{$r}", $row['gestor']);
            $sheet->setCellValue("C{$r}", $row['contratos']);
            $sheet->setCellValue("D{$r}", $row['cartera']);
            $sheet->setCellValue("E{$r}", $row['vencida']);
            $sheet->setCellValue("F{$r}", $row['mora_pct']);
            foreach ($bucketKeys as $idx => $key) {
                $col = chr(ord('G') + $idx);
                $sheet->setCellValue("{$col}{$r}", $row[$key] ?? 0.0);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                $totBuckets2[$key] += $row[$key] ?? 0.0;
            }
            $this->dataRow($sheet, "A{$r}:L{$r}", $i % 2 === 0);
            foreach (['D','E'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $totCont2 += $row['contratos']; $totCart2 += $row['cartera']; $totVenc2 += $row['vencida'];
            $r++;
        }
        $this->totalsRow($sheet, "A{$r}:L{$r}");
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("C{$r}", $totCont2);
        $sheet->setCellValue("D{$r}", $totCart2);
        $sheet->setCellValue("E{$r}", $totVenc2);
        $sheet->setCellValue("F{$r}", $totCart2 > 0 ? round($totVenc2 / $totCart2 * 100, 2) : 0);
        foreach ($bucketKeys as $idx => $key) {
            $col = chr(ord('G') + $idx);
            $sheet->setCellValue("{$col}{$r}", $totBuckets2[$key]);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }
        foreach (['D','E'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
        $r += 3;

        // ── Sección 3: Mora por sucursal + producto ───────────────────────────
        $this->sectionHeader($sheet, "A{$r}:L{$r}", '3. MORA POR SUCURSAL + PRODUCTO');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'SUCURSAL', 'B' => 'PRODUCTO', 'C' => 'CONTRATOS',
            'D' => 'CARTERA', 'E' => 'VENCIDO', 'F' => 'MORA %',
            'G' => 'AL CORRIENTE', 'H' => 'MORA 1-30', 'I' => 'MORA 31-60',
            'J' => 'MORA 61-90', 'K' => 'MORA 91-120', 'L' => 'MORA 120+',
        ]);
        $r++;

        $moraByBP = $snap['sections']['mora_by_branch_product'] ?? [];
        $totCont3 = 0; $totCart3 = 0.0; $totVenc3 = 0.0;
        $totBuckets3 = array_fill_keys($bucketKeys, 0.0);
        foreach ($moraByBP as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['branch']);
            $sheet->setCellValue("B{$r}", $row['product']);
            $sheet->setCellValue("C{$r}", $row['contratos']);
            $sheet->setCellValue("D{$r}", $row['cartera']);
            $sheet->setCellValue("E{$r}", $row['vencida']);
            $sheet->setCellValue("F{$r}", $row['mora_pct']);
            foreach ($bucketKeys as $idx => $key) {
                $col = chr(ord('G') + $idx);
                $sheet->setCellValue("{$col}{$r}", $row[$key] ?? 0.0);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
                $totBuckets3[$key] += $row[$key] ?? 0.0;
            }
            $this->dataRow($sheet, "A{$r}:L{$r}", $i % 2 === 0);
            foreach (['D','E'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $totCont3 += $row['contratos']; $totCart3 += $row['cartera']; $totVenc3 += $row['vencida'];
            $r++;
        }
        $this->totalsRow($sheet, "A{$r}:L{$r}");
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("C{$r}", $totCont3);
        $sheet->setCellValue("D{$r}", $totCart3);
        $sheet->setCellValue("E{$r}", $totVenc3);
        $sheet->setCellValue("F{$r}", $totCart3 > 0 ? round($totVenc3 / $totCart3 * 100, 2) : 0);
        foreach ($bucketKeys as $idx => $key) {
            $col = chr(ord('G') + $idx);
            $sheet->setCellValue("{$col}{$r}", $totBuckets3[$key]);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }
        foreach (['D','E'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(28);
        foreach (['C','D','E','F','G','H','I','J','K','L'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(14);
        }
        $sheet->freezePane('C4');
    }

    // ── PRÉSTAMOS ACTIVOS ────────────────────────────────────────────────────

    private function buildActiveLoansSheet(Spreadsheet $ss, Period $period, array $snap): void
    {
        $sheet = $ss->createSheet()->setTitle(RadiographyStyleHelper::safeSheetName('PRÉSTAMOS ACTIVOS'));
        $rows  = $snap['sections']['active_loans'] ?? [];

        RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:N1', 'PRÉSTAMOS ACTIVOS — ' . strtoupper($period->label));
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');
        RadiographyStyleHelper::setCellValueSafe($sheet, 'B2', count($rows) . ' créditos activos — ordenado por sucursal, días vencidos y saldo.');
        RadiographyStyleHelper::applyMetaStyle($sheet, 'B2:N2');
        RadiographyStyleHelper::mergeCellsSafe($sheet,'B2:N2');

        $headers = [
            'A' => 'SUCURSAL', 'B' => 'CLIENTE', 'C' => 'CONTRATO', 'D' => 'PRODUCTO',
            'E' => 'GESTOR', 'F' => 'RUTA', 'G' => 'PERIODICIDAD', 'H' => 'DÍAS VENCIDOS',
            'I' => 'BUCKET MORA', 'J' => 'SALDO ACTIVO', 'K' => 'CAPITAL ACTIVO',
            'L' => 'VENCIDO', 'M' => 'CAPITAL VENCIDO', 'N' => 'ESTADO',
        ];
        RadiographyStyleHelper::applyTableHeaderStyle($sheet, 3, $headers, accent: true);

        if (empty($rows)) {
            RadiographyStyleHelper::setCellValueSafe($sheet, 'A4', 'Sin créditos activos para este periodo.');
            RadiographyStyleHelper::mergeCellsSafe($sheet,'A4:N4');
            RadiographyStyleHelper::setColWidths($sheet, [
                'A' => 20, 'B' => 28, 'C' => 16, 'D' => 20, 'E' => 22, 'F' => 16,
                'G' => 14, 'H' => 12, 'I' => 14, 'J' => 16, 'K' => 16, 'L' => 16, 'M' => 16, 'N' => 14,
            ]);
            $sheet->freezePane('A4');
            return;
        }

        $r = 4;
        foreach ($rows as $i => $row) {
            $stringCols = [
                'A' => $row['sucursal'] ?? '', 'B' => $row['cliente'] ?? '', 'C' => $row['contrato'] ?? '',
                'D' => $row['producto'] ?? '', 'E' => $row['gestor'] ?? '', 'F' => $row['ruta'] ?? '',
                'G' => $row['periodicidad'] ?? '', 'I' => $row['bucket_mora'] ?? '', 'N' => $row['estado'] ?? '',
            ];
            foreach ($stringCols as $col => $val) {
                RadiographyStyleHelper::setCellValueSafe($sheet, "{$col}{$r}", $val);
            }
            $sheet->setCellValue("H{$r}", (int)($row['dias_vencidos'] ?? 0));
            $sheet->setCellValue("J{$r}", (float)($row['saldo_activo'] ?? 0));
            $sheet->setCellValue("K{$r}", (float)($row['capital_activo'] ?? 0));
            $sheet->setCellValue("L{$r}", (float)($row['vencido'] ?? 0));
            $sheet->setCellValue("M{$r}", (float)($row['capital_vencido'] ?? 0));

            RadiographyStyleHelper::applyDataRowStyle($sheet, "A{$r}:N{$r}", $i % 2 === 0);
            RadiographyStyleHelper::applyIntegerFormat($sheet, "H{$r}");
            foreach (['J', 'K', 'L', 'M'] as $col) {
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$col}{$r}");
            }

            $dpd = (int)($row['dias_vencidos'] ?? 0);
            if ($dpd > 90) {
                RadiographyStyleHelper::applyAlertStyle($sheet, "I{$r}:N{$r}");
            } elseif ($dpd > 0) {
                RadiographyStyleHelper::applyWarningStyle($sheet, "I{$r}");
            }
            $r++;
        }

        $sheet->setAutoFilter("A3:N{$r}");
        $sheet->freezePane('A4');
        RadiographyStyleHelper::setColWidths($sheet, [
            'A' => 20, 'B' => 28, 'C' => 16, 'D' => 20, 'E' => 22, 'F' => 16,
            'G' => 14, 'H' => 12, 'I' => 14, 'J' => 16, 'K' => 16, 'L' => 16, 'M' => 16, 'N' => 14,
        ]);
    }
}
