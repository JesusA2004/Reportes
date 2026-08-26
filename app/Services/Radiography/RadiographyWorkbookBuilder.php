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

    /**
     * Construye el libro completo. Cuando $comparePeriod/$compareSnap vienen dados
     * (comparativo mes/bimestre/trimestre vs vs), CADA hoja conserva su mismo
     * nombre, orden, secciones y gráficas — solo cambia cada columna de valor
     * único por 4 columnas (mes comparado | mes actual | diferencia | variación %).
     * Nunca se genera una hoja "resumen ejecutivo" aparte: el comparativo ES el
     * mismo libro, con comparación.
     */
    public function buildFromSnapshot(
        Period $period,
        PeriodSummary $summary,
        array $snap,
        ?Period $comparePeriod = null,
        ?array $compareSnap = null
    ): Spreadsheet {
        $spreadsheet = new Spreadsheet();
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $title = $isComparative
            ? 'Radiografía Comparativa ' . $comparePeriod->label . ' vs ' . $period->label
            : 'Radiografía ' . $period->label;
        $spreadsheet->getProperties()
            ->setTitle($title)
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
            // PRÉSTAMOS ACTIVOS: eliminada por decisión de negocio (jun 2026)
            // MORA DETALLE: consolidada en hoja MORAS
            // VAL. CART: consolidada en hoja VALOR CARTERA
            ['buildPlacementSheet',        'COLOCACIÓN'],
            ['buildInterbranchLoansSheet', 'P. INTERSUC.'],
            ['buildBranchSheets',          null], // creates multiple sheets, no single placeholder
            ['buildProductosSheet',        'PRODUCTOS'],
            ['buildEmpleadosSheet',        'EMPLEADOS'],
            ['buildNominaGestorSheet',     'NÓMINA POR GESTOR'],
            ['buildCategoriaEbitdaSheet',  'CATEGORÍA EBITDA'],
            ['buildRotacionSheet',         'ROTACIÓN'],
            ['buildImssSheet',             'IMSS'],
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
                $this->$method($spreadsheet, $period, $snap, $comparePeriod, $compareSnap);
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

        // Look profesional en todas las hojas: sin líneas de cuadrícula (hoja blanca, no
        // "hoja de cálculo cruda"). Se aplica al final, una sola vez, en vez de repetirlo
        // en cada builder individual.
        foreach ($spreadsheet->getAllSheets() as $sheetToClean) {
            $sheetToClean->setShowGridlines(false);
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

    /**
     * Recalcula el mismo bloque de métricas GLOBAL que buildGlobalSheet ya
     * computa para $snap, pero para CUALQUIER snapshot dado (el del periodo
     * comparado). Misma fórmula exacta, copiada literal — nunca se toca el
     * cálculo original usado por el reporte simple, solo se reutiliza para
     * poder comparar Mayo vs Junio con las mismas reglas.
     */
    private function extractGlobalCoreMetrics(array $snap): array
    {
        $sum     = $snap['summary'];
        $pay     = $snap['sections']['payroll'];
        $buckets = $snap['sections']['portfolio_buckets'] ?? [];
        $loans   = $snap['sections']['interbranch_loans'] ?? [];
        $funding = $snap['sections']['corporate_funding'] ?? [];

        $brCalcGlobal = $snap['branch_radiography']['global'] ?? null;
        $branchesList = $snap['branch_radiography']['branches'] ?? [];

        $carteraTotal = $brCalcGlobal ? (float)$brCalcGlobal['valor_cartera'] : (float)$sum['portfolio_total'];
        $recTotal     = $brCalcGlobal ? (float)$brCalcGlobal['recuperacion_total'] : (float)$sum['recovery_total'];
        $colTotal     = $brCalcGlobal ? (float)$brCalcGlobal['colocacion'] : (float)$sum['placement_total'];
        $excedentes   = $brCalcGlobal ? (float)$brCalcGlobal['excedentes'] : (float)($funding['total'] ?? 0);

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

        $ingrSavehearts  = $brCalcGlobal ? (float)$brCalcGlobal['seguro_savehearts_bruto']  : 0.0;
        $ingrComadres    = $brCalcGlobal ? (float)$brCalcGlobal['seguro_comadres_bruto']    : 0.0;
        $ingrCrece       = $brCalcGlobal ? (float)$brCalcGlobal['seguro_crece_bruto']       : (float)($sum['recovery_crece_bruto']      ?? 0.0);
        $ingrCrece30     = $brCalcGlobal ? (float)$brCalcGlobal['seguro_crece_reconocido']  : (float)($sum['recovery_crece_reconocido'] ?? 0.0);
        $ingrCrece70     = max(0.0, $ingrCrece - $ingrCrece30);
        $ingrCanalizadoAseguradora = $ingrSavehearts + $ingrComadres + $ingrCrece70;
        $ingrTotal       = $recTotal;
        $ingrCapital     = $brCalcGlobal ? (float)$brCalcGlobal['capital_recuperado']   : 0.0;
        $ingrInteres     = $brCalcGlobal ? (float)$brCalcGlobal['interes_recuperado']   : 0.0;
        $ingrImpuesto    = $brCalcGlobal ? (float)$brCalcGlobal['impuesto_recuperado']  : 0.0;
        $ingrCharges     = $brCalcGlobal ? (float)$brCalcGlobal['charges']              : 0.0;
        $ingrCargosIni   = $brCalcGlobal ? (float)$brCalcGlobal['cargos_inicio']        : 0.0;
        $ingrComAper     = $brCalcGlobal ? (float)$brCalcGlobal['comision_apertura']    : 0.0;
        $ingrCargosAdic  = $brCalcGlobal ? (float)$brCalcGlobal['cargos_adicionales']   : 0.0;
        $ingrExcedRec    = $brCalcGlobal ? (float)$brCalcGlobal['excedente_recuperado'] : 0.0;
        $ingrOtrosDet    = (array)($brCalcGlobal['otros_detalle'] ?? []);
        $ingrItemsComp = [
            ['Capital recuperado',     $ingrCapital,    'currency'],
            ['Intereses',              $ingrInteres,    'currency'],
            ['Impuestos',              $ingrImpuesto,   'currency'],
            ['Moratorios / Multas',    $ingrCharges,    'currency'],
            ['Cargos al inicio',       $ingrCargosIni,  'currency'],
            ['Comisión por apertura',  $ingrComAper,    'currency'],
            ['Cargos adicionales',     $ingrCargosAdic, 'currency'],
            ['Excedentes recuperados', $ingrExcedRec,   'currency'],
            ['Seguro CRECE reconocido (30%)', $ingrCrece30, 'currency'],
        ];
        foreach ($ingrOtrosDet as $concept => $amount) {
            $ingrItemsComp[] = [(string)$concept, (float)$amount, 'currency'];
        }

        $globalGastosDetalle = (array)($brCalcGlobal['gastos_detalle'] ?? []);
        $getGDet = fn (string $name) => (float)($globalGastosDetalle[$name] ?? 0.0);
        $gastosOp = [
            'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
            'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
            'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
            'Publicidad','Mecánicos','Servicios de Motocicletas','Financiamiento de Motos',
            'Software Póliza Anual','Pólizas',
            'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
            'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
            'Gastos legales',
        ];
        $gastosOpTotal = $brCalcGlobal ? (float)($brCalcGlobal['gastos_operativos'] ?? 0) : 0.0;
        $gastosOpCurada = 0.0;
        foreach ($gastosOp as $gasto) {
            $gastosOpCurada += $getGDet($gasto);
        }
        $gastosOpOtros = round($gastosOpTotal - $gastosOpCurada, 2);
        $gastosOpDetalleMap = [];
        foreach ($gastosOp as $gasto) {
            $gastosOpDetalleMap[$gasto] = $getGDet($gasto);
        }

        // Ver mismo fix y explicación en buildGlobalSheet() (bug real 2026-08-26): el
        // desglose viene EXCLUSIVAMENTE de nominaBreakdownFor(), garantizado a sumar
        // exacto contra nominaTotalFor() — las deducciones (nomina_detalle) se manejan
        // aparte, nunca mezcladas en esta tabla reconciliable.
        $nomDisplayOrder = $brCalcGlobal ? BranchRadiographyCalculator::nominaBreakdownFor($brCalcGlobal) : [];
        $mandatory24 = ['Sueldo', 'Comisiones', 'Bonos', 'Vacaciones'];
        $globalNomDeducciones = (array) ($brCalcGlobal['nomina_detalle'] ?? []);
        $nomTotal = $brCalcGlobal ? BranchRadiographyCalculator::nominaTotalFor($brCalcGlobal) : (float) $pay['pagos'];

        $brGlobalFondea = $brCalcGlobal ? (float)$brCalcGlobal['prestamos_fondea'] : (float)($loans['operative_fondeos']['fondea_total'] ?? 0);
        $brGlobalRecibe = $brGlobalFondea;

        $excGlobal        = $brCalcGlobal ? (float)$brCalcGlobal['excedentes'] : $excedentes;
        $gastosTotal      = $gastosOpTotal + $nomTotal;
        $ingresoEbitdaBase = $brCalcGlobal ? BranchRadiographyCalculator::ingresoEbitdaBaseFor($brCalcGlobal) : 0.0;
        $utilidad         = $ingresoEbitdaBase - $gastosTotal;
        $margenEbitdaCalc = $ingresoEbitdaBase > 0 ? round($utilidad / $ingresoEbitdaBase * 100, 2) : 0.0;

        $rotation    = $snap['sections']['rotation'] ?? [];
        $imssPatronal = $brCalcGlobal ? (float)($brCalcGlobal['imss_patronal'] ?? 0) : 0.0;

        return compact(
            'carteraTotal', 'recTotal', 'colTotal', 'excedentes',
            'mora0_30', 'mora31_60', 'mora61_90', 'mora91_120', 'mora120p', 'moraTotal', 'moraPct',
            'ingrSavehearts', 'ingrComadres', 'ingrCrece', 'ingrCrece30', 'ingrCrece70', 'ingrCanalizadoAseguradora',
            'ingrTotal', 'ingrItemsComp',
            'gastosOpTotal', 'gastosOpOtros', 'gastosOpDetalleMap',
            'nomDisplayOrder', 'nomTotal',
            'brGlobalFondea', 'brGlobalRecibe', 'excGlobal', 'gastosTotal', 'ingresoEbitdaBase', 'utilidad', 'margenEbitdaCalc',
            'branchesList', 'rotation', 'sum', 'imssPatronal'
        );
    }

    private function buildGlobalSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $cm = $isComparative ? $this->extractGlobalCoreMetrics($compareSnap) : null;

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
        $ingrSavehearts  = $brCalcGlobal ? (float)$brCalcGlobal['seguro_savehearts_bruto']  : 0.0;
        $ingrComadres    = $brCalcGlobal ? (float)$brCalcGlobal['seguro_comadres_bruto']    : 0.0;
        $ingrCrece       = $brCalcGlobal ? (float)$brCalcGlobal['seguro_crece_bruto']       : (float)($sum['recovery_crece_bruto']      ?? 0.0);
        $ingrCrece30     = $brCalcGlobal ? (float)$brCalcGlobal['seguro_crece_reconocido']  : (float)($sum['recovery_crece_reconocido'] ?? 0.0);
        $ingrCrece70     = max(0.0, $ingrCrece - $ingrCrece30);
        $ingrCanalizadoAseguradora = $ingrSavehearts + $ingrComadres + $ingrCrece70;
        $ingrTotal       = $recTotal; // recuperacion_total ya incluye el 30% CRECE
        // Componentes de recuperación final
        $ingrCapital     = $brCalcGlobal ? (float)$brCalcGlobal['capital_recuperado']   : 0.0;
        $ingrInteres     = $brCalcGlobal ? (float)$brCalcGlobal['interes_recuperado']   : 0.0;
        $ingrImpuesto    = $brCalcGlobal ? (float)$brCalcGlobal['impuesto_recuperado']  : 0.0;
        $ingrCharges     = $brCalcGlobal ? (float)$brCalcGlobal['charges']              : 0.0;
        $ingrCargosIni   = $brCalcGlobal ? (float)$brCalcGlobal['cargos_inicio']        : 0.0;
        $ingrComAper     = $brCalcGlobal ? (float)$brCalcGlobal['comision_apertura']    : 0.0;
        $ingrCargosAdic  = $brCalcGlobal ? (float)$brCalcGlobal['cargos_adicionales']   : 0.0;
        $ingrExcedRec    = $brCalcGlobal ? (float)$brCalcGlobal['excedente_recuperado'] : 0.0;
        $ingrOtrosDet    = (array)($brCalcGlobal['otros_detalle'] ?? []);
        // Desglose por componente de la recuperación final — TODOS los componentes reales,
        // de forma que la suma cuadre exactamente contra $ingrTotal (Total Recuperación).
        $ingrItemsComp = [
            ['Capital recuperado',     $ingrCapital,    'currency'],
            ['Intereses',              $ingrInteres,    'currency'],
            ['Impuestos',              $ingrImpuesto,   'currency'],
            ['Moratorios / Multas',    $ingrCharges,    'currency'],
            ['Cargos al inicio',       $ingrCargosIni,  'currency'],
            ['Comisión por apertura',  $ingrComAper,    'currency'],
            ['Cargos adicionales',     $ingrCargosAdic, 'currency'],
            ['Excedentes recuperados', $ingrExcedRec,   'currency'],
            ['Seguro CRECE reconocido (30%)', $ingrCrece30, 'currency'],
        ];
        // "Otros" se desglosa por su concepto real de origen — nunca como bolsa genérica.
        foreach ($ingrOtrosDet as $concept => $amount) {
            $ingrItemsComp[] = [(string)$concept, (float)$amount, 'currency'];
        }

        // ── Gastos operativos (totales primero, render después) ─────────────
        $globalGastosDetalle = (array)($brCalcGlobal['gastos_detalle'] ?? []);
        $getGDet = fn (string $name) => (float)($globalGastosDetalle[$name] ?? 0.0);
        $gastosOp = [
            'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
            'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
            'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
            'Publicidad','Mecánicos','Servicios de Motocicletas','Financiamiento de Motos',
            'Software Póliza Anual','Pólizas',
            'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
            'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
            'Gastos legales',
        ];
        // OPEX TOTAL: SIEMPRE la fuente canónica gastos_operativos (ERP completo sin
        // Corporativo/Pólizas + Lendus sin nómina/IMSS/deducciones/fondeo/excedentes/pólizas/
        // gasto-empleados/anticipo) — NUNCA la suma de la lista curada de abajo, que existe
        // solo para el desglose por concepto y puede no cubrir TODOS los conceptos reales
        // (ej. Gasolina o un concepto "Sin clasificar" nuevo). Si la lista curada no cuadra
        // exactamente contra el total canónico, el remanente se muestra como "Otros conceptos
        // operativos" para que el desglose siempre sume exacto al total — nunca se pierde un peso.
        $gastosOpTotal = $brCalcGlobal ? (float)($brCalcGlobal['gastos_operativos'] ?? 0) : 0.0;
        $gastosOpCurada = 0.0;
        foreach ($gastosOp as $gasto) {
            $gastosOpCurada += $getGDet($gasto);
        }
        $gastosOpOtros = round($gastosOpTotal - $gastosOpCurada, 2);

        // ── Nómina y Capital Humano (totales primero, render después) ───────
        // Bug real 2026-08-26: la lista curada de abajo mezclaba nomina_detalle
        // (deducciones NOI, que YA NO se restan de nomina_total — ver accumulateNomina())
        // junto con nomina_informativo (IMSS/Motos/Cascos/Finiquito/Médicos, que SÍ están
        // incluidos en el total) en UNA SOLA tabla — así SUM(filas mostradas) no cuadraba
        // contra "Total Nómina y Capital Humano". nomDisplayOrder ahora viene EXCLUSIVAMENTE
        // de BranchRadiographyCalculator::nominaBreakdownFor() (los mismos 9 campos que arma
        // nominaTotalFor(), garantizado por construcción a sumar exacto); las deducciones se
        // renderizan aparte, después del Total, para que nunca se confundan con un componente.
        $nomDisplayOrder = $brCalcGlobal ? BranchRadiographyCalculator::nominaBreakdownFor($brCalcGlobal) : [];
        $mandatory24 = ['Sueldo', 'Comisiones', 'Bonos', 'Vacaciones'];
        $globalNomDeducciones = (array) ($brCalcGlobal['nomina_detalle'] ?? []);
        // Total = fuente única BranchRadiographyCalculator::nominaTotalFor().
        $nomTotal = $brCalcGlobal ? BranchRadiographyCalculator::nominaTotalFor($brCalcGlobal) : (float) $pay['pagos'];

        // ── Préstamos intersucursales — SOLO movimientos sucursal operativa → sucursal
        // operativa. Activos (fondea) y Pasivos (recibe) son, por definición, el mismo
        // conjunto de movimientos agrupado por origen y por destino: siempre son iguales.
        // CORPORATIVO/excedentes NUNCA entran aquí (van en "Excedente enviado a corporativo").
        $brGlobalFondea = $brCalcGlobal ? (float)$brCalcGlobal['prestamos_fondea'] : (float)($loans['operative_fondeos']['fondea_total'] ?? 0);
        $brGlobalRecibe = $brGlobalFondea;

        // ── EBITDA — CRITERIO FINAL (2026-07): Ingreso base EBITDA − Gastos Totales.
        // NUNCA Recuperación − Colocación (capital recuperado no es ingreso real). Fuente
        // única: BranchRadiographyCalculator::ingresoEbitdaBaseFor()/ebitdaFinalFor().
        $excGlobal        = $brCalcGlobal ? (float)$brCalcGlobal['excedentes'] : $excedentes;
        $gastosTotal      = $gastosOpTotal + $nomTotal;
        $ingresoEbitdaBase = $brCalcGlobal ? BranchRadiographyCalculator::ingresoEbitdaBaseFor($brCalcGlobal) : 0.0;
        $utilidad         = $ingresoEbitdaBase - $gastosTotal;
        $margenEbitdaCalc = $ingresoEbitdaBase > 0 ? round($utilidad / $ingresoEbitdaBase * 100, 2) : 0.0;
        $imssPatronal     = $brCalcGlobal ? (float)($brCalcGlobal['imss_patronal'] ?? 0) : 0.0;
        $rotation         = $snap['sections']['rotation'] ?? [];
        $gastosOpDetalleMap = [];
        foreach ($gastosOp as $gasto) {
            $gastosOpDetalleMap[$gasto] = $getGDet($gasto);
        }

        // ════════════════════════════════════════════════════════════════════
        // Layout: título(1) · subtítulo(2) · meta(3) · KPI band(4-7) · blank(8)
        // · navegación(9-14) · blank(15) · encabezado de tabla(16) · datos(17+)
        // ════════════════════════════════════════════════════════════════════

        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(22);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(18);

        RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:D1', $isComparative
            ? 'Radiografía Completa del Negocio — Estado Financiero Comparativo'
            : 'Radiografía Completa del Negocio — Estado Financiero');
        RadiographyStyleHelper::applySubtitleStyle($sheet, 'A2:D2', $isComparative
            ? 'MR LANA — GLOBAL · ' . strtoupper($comparePeriod->label) . ' VS ' . strtoupper($period->label)
            : 'MR LANA — GLOBAL · ' . strtoupper($period->label));

        $composite = $snap['period']['composite'] ?? null;
        $periodoLinea = 'Periodo: ' . ($period->code ?: $period->id) . '  |  Generado: ' . $snap['generated_at'];
        if ($isComparative) {
            $periodoLinea = 'Periodo comparado: ' . $comparePeriod->label . '  |  Periodo actual: ' . $period->label . '  |  Generado: ' . $snap['generated_at'];
        } elseif ($composite) {
            $periodoLinea = $composite['component_range'] . '  |  Periodo: ' . $composite['week_range']
                . '  |  Rango: ' . $composite['date_start'] . ' → ' . $composite['date_end'] . '  |  Generado: ' . $snap['generated_at'];
        }
        RadiographyStyleHelper::mergeCellsSafe($sheet,'A3:D3');
        RadiographyStyleHelper::setCellValueSafe($sheet, 'A3', $periodoLinea);
        RadiographyStyleHelper::applyMetaStyle($sheet, 'A3:D3');

        // ── KPI band ──────────────────────────────────────────────────────────
        // Comparativo: tabla MÉTRICA | mes comparado | mes actual | diferencia |
        // variación % (misma tabla que se pide en TODAS las pestañas). Simple:
        // cuadrícula de tarjetas 2-arriba, sin cambios respecto al original.
        if ($isComparative) {
            $kpiRow = 4;
            $this->comparativeHeader($sheet, $kpiRow, $comparePeriod->label, $period->label);
            $kpiRow++;
            $kpiComparativeRows = [
                ['Valor cartera global',       $cm['carteraTotal'],        $carteraTotal,       'currency'],
                ['Otorgamientos',              $cm['colTotal'],            $colTotal,           'currency'],
                ['Recuperación total',         $cm['recTotal'],            $recTotal,           'currency'],
                ['Utilidad bruta',             $cm['ingresoEbitdaBase'],   $ingresoEbitdaBase,  'currency'],
                ['Gastos Totales',             $cm['gastosTotal'],         $gastosTotal,        'currency'],
                ['OPEX',                       $cm['gastosOpTotal'],       $gastosOpTotal,      'currency'],
                ['Nómina y Capital Humano',    $cm['nomTotal'],            $nomTotal,           'currency'],
                ['EBITDA',                     $cm['utilidad'],            $utilidad,           'currency'],
                ['Margen EBITDA',              $cm['margenEbitdaCalc'],    $margenEbitdaCalc,   'percent'],
                ['Percepciones',               (float)($cm['sum']['noi_percepciones'] ?? 0), (float)($snap['summary']['noi_percepciones'] ?? 0), 'currency'],
                ['Deducciones (informativo)',  (float)($cm['sum']['noi_deducciones']  ?? 0), (float)($snap['summary']['noi_deducciones']  ?? 0), 'currency'],
                ['Neto pagado a trabajadores', (float)($cm['sum']['noi_neto_pagado']  ?? 0), (float)($snap['summary']['noi_neto_pagado']  ?? 0), 'currency'],
                ['Mora total',                 $cm['moraTotal'],           $moraTotal,          'currency'],
                ['IMSS',                       $cm['imssPatronal'],        $imssPatronal,       'currency'],
                ['Rotación %',                 (float)($cm['rotation']['indice'] ?? 0), (float)($rotation['indice'] ?? 0), 'percent'],
            ];
            foreach ($kpiComparativeRows as $i => [$lbl, $prev, $curr, $fmt]) {
                $this->writeComparativeRow($sheet, $kpiRow, $lbl, (float)$prev, (float)$curr, $fmt, $i % 2 === 0);
            }
            $kpiRow++;

            // ── Dashboard comparativo (columnas N:P) — mismas gráficas del reporte
            // simple, ahora de 2 series (mes comparado vs mes actual) en vez de 1.
            $sheet->getColumnDimension('N')->setWidth(26);
            $sheet->getColumnDimension('O')->setWidth(18);
            $sheet->getColumnDimension('P')->setWidth(18);
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;

            $writeDashPair = function (int &$dr, array $rows) use ($sheet): array {
                $start = $dr;
                foreach ($rows as [$lbl, $prev, $curr]) {
                    $sheet->setCellValue("N{$dr}", $lbl);
                    $sheet->setCellValue("O{$dr}", $prev);
                    $sheet->setCellValue("P{$dr}", $curr);
                    RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dr}");
                    RadiographyStyleHelper::applyCurrencyFormat($sheet, "P{$dr}");
                    $dr++;
                }
                return [$start, $dr - 1];
            };

            $dashRow = 4;
            RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:P{$dashRow}", 'RECUPERACIÓN Y COLOCACIÓN');
            $dashRow++;
            [$recColStart, $recColEnd] = $writeDashPair($dashRow, [
                ['Recuperación', $cm['recTotal'], $recTotal],
                ['Colocación',   $cm['colTotal'], $colTotal],
            ]);
            $dashRow++;

            RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:P{$dashRow}", 'CARTERA Y MORA');
            $dashRow++;
            [$carteraStart, $carteraEnd] = $writeDashPair($dashRow, [
                ['Valor cartera', $cm['carteraTotal'], $carteraTotal],
                ['Cartera vencida (mora)', $cm['moraTotal'], $moraTotal],
            ]);
            $dashRow++;

            RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:P{$dashRow}", 'EBITDA');
            $dashRow++;
            [$ebitdaStart, $ebitdaEnd] = $writeDashPair($dashRow, [
                ['Utilidad bruta', $cm['ingresoEbitdaBase'], $ingresoEbitdaBase],
                ['Gastos Totales', $cm['gastosTotal'],       $gastosTotal],
                ['EBITDA',         $cm['utilidad'],          $utilidad],
            ]);
            $dashRow++;

            RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:P{$dashRow}", 'GASTOS');
            $dashRow++;
            [$gastosStart, $gastosEnd] = $writeDashPair($dashRow, [
                ['OPEX',                    $cm['gastosOpTotal'], $gastosOpTotal],
                ['Nómina y Capital Humano', $cm['nomTotal'],      $nomTotal],
                ['Gastos Totales',          $cm['gastosTotal'],   $gastosTotal],
            ]);
            $dashRow++;

            RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:P{$dashRow}", 'ROTACIÓN DE PERSONAL');
            $dashRow++;
            [$rotStart, $rotEnd] = $writeDashPair($dashRow, [
                ['Plantilla', (float)($cm['rotation']['current_count'] ?? $cm['rotation']['promedio'] ?? 0), (float)($rotation['current_count'] ?? $rotation['promedio'] ?? 0)],
            ]);
            $sheet->getStyle("O{$rotStart}:P{$rotEnd}")->getNumberFormat()->setFormatCode(self::INTEGER);

            $this->addComparativeChart($sheet, 'Recuperación y Colocación', $recColStart, $recColEnd, $labelCmp, $labelCur, 'R4', 'Y18');
            $this->addComparativeChart($sheet, 'Cartera y Mora',            $carteraStart, $carteraEnd, $labelCmp, $labelCur, 'Z4', 'AG18');
            $this->addComparativeChart($sheet, 'EBITDA',                    $ebitdaStart, $ebitdaEnd, $labelCmp, $labelCur, 'R19', 'Y33');
            $this->addComparativeChart($sheet, 'Gastos',                    $gastosStart, $gastosEnd, $labelCmp, $labelCur, 'Z19', 'AG33');
            $this->addComparativeChart($sheet, 'Rotación — Plantilla',      $rotStart, $rotEnd, $labelCmp, $labelCur, 'R34', 'Y48', '#,##0');

            goto globalKpiBandDone;
        }
        $kpiPairs = [
            ['Valor cartera global', $carteraTotal, 'currency', 'Recuperación total (informativo)', $recTotal,   'currency'],
            ['Otorgamientos',        $colTotal,     'currency', 'Mora total',                  $moraTotal,  'currency'],
            ['Utilidad bruta',       $ingresoEbitdaBase, 'currency', 'Gastos Totales',          $gastosTotal,'currency'],
            ['Nómina y Capital Humano', $nomTotal,  'currency', 'EBITDA', $utilidad,   'currency'],
            [
                'Percepciones', (float) ($snap['summary']['noi_percepciones'] ?? 0), 'currency',
                'Deducciones (informativo)', (float) ($snap['summary']['noi_deducciones'] ?? 0), 'currency',
            ],
            [
                'Neto pagado a trabajadores', (float) ($snap['summary']['noi_neto_pagado'] ?? 0), 'currency',
                'Margen EBITDA', $margenEbitdaCalc, 'percent',
            ],
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

        // ── Dashboard visual (columnas N:O) — tabla fuente + gráficas dona/pastel
        // en columnas P:AC. Arranca en N (no en F) para dejar una columna de
        // margen (M) después de la tabla más ancha de la hoja ("B) Desglose por
        // sucursal", que ocupa hasta la columna L) — así ninguna gráfica flotante
        // queda encima de una tabla de datos.
        $sheet->getColumnDimension('N')->setWidth(26);
        $sheet->getColumnDimension('O')->setWidth(20);
        foreach (['P','Q','R','S','T','U','V','W','X','Y','Z','AA','AB','AC'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(10);
        }

        $dashRow = 4;

        // Sección 1: Recuperación vs Colocación
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:O{$dashRow}", 'RECUPERACIÓN VS COLOCACIÓN');
        $dashRow++;
        $recColLabelStart = $dashRow;
        RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", 'Recuperación');
        $sheet->setCellValue("O{$dashRow}", $recTotal);
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
        $dashRow++;
        RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", 'Colocación');
        $sheet->setCellValue("O{$dashRow}", $colTotal);
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
        $recColLabelEnd = $dashRow;
        $dashRow += 2;

        // Sección 2: Cartera vs Cartera Vencida
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:O{$dashRow}", 'CARTERA VS CARTERA VENCIDA');
        $dashRow++;
        $carteraMoraLabelStart = $dashRow;
        RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", 'Cartera sana');
        $sheet->setCellValue("O{$dashRow}", max(0.0, $carteraTotal - $moraTotal));
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
        $dashRow++;
        RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", 'Cartera vencida');
        $sheet->setCellValue("O{$dashRow}", $moraTotal);
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
        $carteraMoraLabelEnd = $dashRow;
        $dashRow += 2;

        // Sección 3: Top sucursales por cartera (solo Sucursal + Valor, sin categoría EBITDA)
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:O{$dashRow}", 'TOP SUCURSALES POR CARTERA');
        $dashRow++;
        $rankBranches = $branchesList;
        usort($rankBranches, fn ($a, $b) => (float)($b['valor_cartera'] ?? 0) <=> (float)($a['valor_cartera'] ?? 0));
        $rankBranches    = array_slice($rankBranches, 0, 8);
        $topSucLabelStart = $dashRow;
        foreach ($rankBranches as $rb) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", $rb['sucursal']);
            $sheet->setCellValue("O{$dashRow}", (float)($rb['valor_cartera'] ?? 0));
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
            $dashRow++;
        }
        $topSucLabelEnd = $dashRow - 1;
        $topSucCount    = count($rankBranches);
        $dashRow++;

        // Sección 4: Mora por bucket
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:O{$dashRow}", 'MORA POR BUCKET');
        $dashRow++;
        $moraBucketLabelStart = $dashRow;
        foreach ([
            ['Mora 1-30',   $mora0_30],
            ['Mora 31-60',  $mora31_60],
            ['Mora 61-90',  $mora61_90],
            ['Mora 91-120', $mora91_120],
            ['Mora 120+',   $mora120p],
        ] as [$bLabel, $bVal]) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", $bLabel);
            $sheet->setCellValue("O{$dashRow}", $bVal);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
            $dashRow++;
        }
        $moraBucketLabelEnd = $dashRow - 1;

        // Sección 5: EBITDA — Ingreso base EBITDA / Gastos Totales / EBITDA
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:O{$dashRow}", 'EBITDA');
        $dashRow++;
        $ebitdaLabelStart = $dashRow;
        foreach ([
            ['Utilidad bruta',      $ingresoEbitdaBase],
            ['Gastos Totales',      $gastosTotal],
            ['EBITDA',              $utilidad],
        ] as [$eLabel, $eVal]) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", $eLabel);
            $sheet->setCellValue("O{$dashRow}", $eVal);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
            $dashRow++;
        }
        $ebitdaLabelEnd = $dashRow - 1;
        $dashRow++;

        // Sección 6: Gastos — OPEX vs Nómina y Capital Humano vs Gastos Totales
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:O{$dashRow}", 'GASTOS');
        $dashRow++;
        $gastosCompLabelStart = $dashRow;
        foreach ([
            ['OPEX',                       $gastosOpTotal],
            ['Nómina y Capital Humano',    $nomTotal],
            ['Gastos Totales',             $gastosTotal],
        ] as [$gcLabel, $gcVal]) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", $gcLabel);
            $sheet->setCellValue("O{$dashRow}", $gcVal);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
            $dashRow++;
        }
        $gastosCompLabelEnd = $dashRow - 1;
        $dashRow++;

        // Sección 7: Nómina — Percepciones / IMSS / Gastos empleados / Deducciones informativas
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:O{$dashRow}", 'NÓMINA — COMPOSICIÓN');
        $dashRow++;
        $nomImssGlobal   = (float)($brCalcGlobal['imss_patronal'] ?? 0);
        $nomGastoEmpGlobal = (float)($brCalcGlobal['gastos_empleados_nomina'] ?? 0);
        $nomPercepGlobal = max(0.0, $nomTotal - $nomImssGlobal - $nomGastoEmpGlobal);
        $nomDeduccInformGlobal = array_sum(array_values((array)($brCalcGlobal['nomina_detalle'] ?? [])));
        $nomCompLabelStart = $dashRow;
        foreach ([
            ['Percepciones',               $nomPercepGlobal],
            ['IMSS',                       $nomImssGlobal],
            ['Gastos empleados',           $nomGastoEmpGlobal],
            ['Deducciones informativas',   $nomDeduccInformGlobal],
        ] as [$ncLabel, $ncVal]) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", $ncLabel);
            $sheet->setCellValue("O{$dashRow}", $ncVal);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
            $dashRow++;
        }
        $nomCompLabelEnd = $dashRow - 1;
        $dashRow++;

        // Sección 8: OPEX por concepto — top 6 conceptos reales de gasto operativo.
        // Esta tabla/gráfica reemplaza la antigua "Recuperación / Ingreso — Componentes"
        // (esa información ya vive en la tabla "2. INGRESOS / RECUPERACIÓN" de la izquierda,
        // no se duplica en gráfica).
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "N{$dashRow}:O{$dashRow}", 'OPEX POR CONCEPTO (TOP 6)');
        $dashRow++;
        $opexTopConceptos = $globalGastosDetalle;
        arsort($opexTopConceptos);
        $opexTopConceptos = array_slice($opexTopConceptos, 0, 6, true);
        $opexTopLabelStart = $dashRow;
        foreach ($opexTopConceptos as $ocLabel => $ocVal) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "N{$dashRow}", $ocLabel);
            $sheet->setCellValue("O{$dashRow}", (float)$ocVal);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "O{$dashRow}");
            $dashRow++;
        }
        $opexTopLabelEnd   = $dashRow - 1;
        $opexTopCount      = count($opexTopConceptos);
        $dashRow++;

        // ── Gráficas dona/pastel (columnas P:AC) sin DataBars — grid 4 filas x 2
        // columnas, siempre a la derecha de la tabla más ancha (L) con margen (M/N/O). ──
        $chartColors = ['106A59', '5B9BD5', '1DC1A2', 'D97706', '94A3B8', '0EA5E9', '7C3AED', 'DC2626'];
        $tealBlue    = ['106A59', '5B9BD5'];
        $greenRed    = ['10B981', 'DC2626'];

        RadiographyStyleHelper::addDonutChart(
            $sheet, 'Recuperación vs Colocación',
            "N{$recColLabelStart}:N{$recColLabelEnd}",
            "O{$recColLabelStart}:O{$recColLabelEnd}",
            2, 'P4', 'V16', $tealBlue
        );
        RadiographyStyleHelper::addDonutChart(
            $sheet, 'Cartera vs Cartera Vencida',
            "N{$carteraMoraLabelStart}:N{$carteraMoraLabelEnd}",
            "O{$carteraMoraLabelStart}:O{$carteraMoraLabelEnd}",
            2, 'W4', 'AC16', $greenRed
        );
        if ($topSucCount > 0) {
            RadiographyStyleHelper::addPieChart(
                $sheet, 'Top Sucursales por Cartera',
                "N{$topSucLabelStart}:N{$topSucLabelEnd}",
                "O{$topSucLabelStart}:O{$topSucLabelEnd}",
                $topSucCount, 'P17', 'V33', array_slice($chartColors, 0, $topSucCount)
            );
        }
        RadiographyStyleHelper::addDonutChart(
            $sheet, 'Mora por Bucket',
            "N{$moraBucketLabelStart}:N{$moraBucketLabelEnd}",
            "O{$moraBucketLabelStart}:O{$moraBucketLabelEnd}",
            5, 'W17', 'AC33',
            ['e11d48', 'f97316', 'eab308', '3b82f6', '8b5cf6']
        );

        // ── Gráficas EBITDA / Gastos / Nómina / OPEX (criterio final 2026-07) ──
        RadiographyStyleHelper::addBarChart(
            $sheet, 'EBITDA — Utilidad bruta vs Gastos Totales',
            "N{$ebitdaLabelStart}:N{$ebitdaLabelEnd}",
            "O{$ebitdaLabelStart}:O{$ebitdaLabelEnd}",
            $ebitdaLabelEnd - $ebitdaLabelStart + 1, 'P34', 'V50',
            ['106A59', 'DC2626', '5B9BD5']
        );
        RadiographyStyleHelper::addBarChart(
            $sheet, 'Gastos — OPEX vs Nómina vs Total',
            "N{$gastosCompLabelStart}:N{$gastosCompLabelEnd}",
            "O{$gastosCompLabelStart}:O{$gastosCompLabelEnd}",
            $gastosCompLabelEnd - $gastosCompLabelStart + 1, 'W34', 'AC50',
            ['D97706', '5B9BD5', '1F2937']
        );
        RadiographyStyleHelper::addDonutChart(
            $sheet, 'Nómina — Composición',
            "N{$nomCompLabelStart}:N{$nomCompLabelEnd}",
            "O{$nomCompLabelStart}:O{$nomCompLabelEnd}",
            $nomCompLabelEnd - $nomCompLabelStart + 1, 'P51', 'V67',
            ['106A59', '5B9BD5', 'D97706', '94A3B8']
        );
        if ($opexTopCount > 0) {
            RadiographyStyleHelper::addPieChart(
                $sheet, 'OPEX por Concepto (Top 6)',
                "N{$opexTopLabelStart}:N{$opexTopLabelEnd}",
                "O{$opexTopLabelStart}:O{$opexTopLabelEnd}",
                $opexTopCount, 'W51', 'AC67', array_slice($chartColors, 0, $opexTopCount)
            );
        }

        globalKpiBandDone:
        // ── Navegación: acceso directo a cada hoja del workbook ──────────────
        $r = $kpiRow + 1;
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '0. NAVEGACIÓN — ACCESOS DIRECTOS');
        $r++;
        $navTargets = [
            'VALOR CARTERA', 'MORAS', 'INGRESOS', 'GASTOS', 'NÓMINA',
            'COLOCACIÓN', 'P. INTERSUC.', 'PRODUCTOS',
            'EMPLEADOS', 'NÓMINA POR GESTOR', 'CATEGORÍA EBITDA',
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
        // funcional está en los autofiltros (▼) de EMPLEADOS, MORA DETALLE y NÓMINA POR GESTOR.
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'FILTROS — use el autofiltro ▼ en EMPLEADOS / MORA DETALLE / NÓMINA POR GESTOR');
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

        if ($isComparative) {
            // ── 1. Métricas Generales ─────────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", '1. MÉTRICAS GENERALES'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            foreach ([
                ['Valor cartera global',   $cm['carteraTotal'], $carteraTotal, 'currency'],
                ['Otorgamientos',          $cm['colTotal'],     $colTotal,     'currency'],
                ['Recuperación total',     $cm['recTotal'],     $recTotal,     'currency'],
                ['Mora de 0 a 30 días',    $cm['mora0_30'],     $mora0_30,     'currency'],
                ['Mora de 31 a 60 días',   $cm['mora31_60'],    $mora31_60,    'currency'],
                ['Mora de 61 a 90 días',   $cm['mora61_90'],    $mora61_90,    'currency'],
                ['Mora de 91 a 120 días',  $cm['mora91_120'],   $mora91_120,   'currency'],
                ['Mora 120+ días',         $cm['mora120p'],     $mora120p,     'currency'],
                ['Mora total',             $cm['moraTotal'],    $moraTotal,    'currency'],
                ['Excedente enviado a corporativo', $cm['excedentes'], $excedentes, 'currency'],
            ] as $i => [$lbl, $prev, $curr, $fmt]) {
                $this->writeComparativeRow($sheet, $r, $lbl, (float)$prev, (float)$curr, $fmt, $i % 2 === 0);
            }
            $r++;

            // ── 2. Ingresos / Recuperación ────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", '2. INGRESOS / RECUPERACIÓN'); $r++;
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'A) Desglose por componente');
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
            $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $cmpComp = collect($cm['ingrItemsComp'])->keyBy(fn ($row) => $row[0]);
            $curComp = collect($ingrItemsComp)->keyBy(fn ($row) => $row[0]);
            $allCompLabels = $cmpComp->keys()->merge($curComp->keys())->unique()->values();
            $ci = 0;
            foreach ($allCompLabels as $compLabel) {
                $prevVal = (float)($cmpComp->get($compLabel)[1] ?? 0);
                $currVal = (float)($curComp->get($compLabel)[1] ?? 0);
                if ($prevVal == 0.0 && $currVal == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $compLabel, $prevVal, $currVal, 'currency', $ci % 2 === 0);
                $ci++;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'Total Recuperación', (float)$cm['ingrTotal'], (float)$ingrTotal, 'currency');
            $r++;

            // B) Por sucursal — colapsado a Total (no explotar 10 columnas x 2 periodos,
            // criterio explícito del negocio para tablas anchas en modo comparativo).
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'B) Recuperación por sucursal (total)');
            $sheet->getStyle("A{$r}")->getFont()->setBold(true);
            $sheet->getStyle("A{$r}:E{$r}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
            $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cmpBranchesByName = collect($cm['branchesList'])->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));
            foreach ($branchesList as $i => $curB) {
                $key  = strtoupper(trim($curB['sucursal'] ?? ''));
                $prev = (float)($cmpBranchesByName->get($key)['recuperacion_total'] ?? 0);
                $curr = (float)($curB['recuperacion_total'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $curB['sucursal'], $prev, $curr, 'currency', $i % 2 === 0);
            }
            $r++;

            // Seguros y coberturas canalizadas
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'SEGUROS Y COBERTURAS CANALIZADAS'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $segIdx = 0;
            foreach ([
                ['Cobertura Savehearts', $cm['ingrSavehearts'], $ingrSavehearts],
                ['Cobertura Crédito Grupal / Comadres', $cm['ingrComadres'], $ingrComadres],
                ['Seguro CRECE total', $cm['ingrCrece'], $ingrCrece],
                ['Reconocido como ingreso MR Lana (30%)', $cm['ingrCrece30'], $ingrCrece30],
                ['Canalizado a aseguradora (70%)', $cm['ingrCrece70'], $ingrCrece70],
            ] as [$lbl, $prev, $curr]) {
                if ($prev == 0.0 && $curr == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $lbl, (float)$prev, (float)$curr, 'currency', $segIdx % 2 === 0);
                $segIdx++;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'Total canalizado a aseguradora', (float)$cm['ingrCanalizadoAseguradora'], (float)$ingrCanalizadoAseguradora, 'currency');
            $r++;

            // ── 3. Gastos Operativos ──────────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", '3. GASTOS OPERATIVOS'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $gIdx = 0;
            foreach ($gastosOp as $gasto) {
                $prevVal = (float)($cm['gastosOpDetalleMap'][$gasto] ?? 0);
                $currVal = (float)($gastosOpDetalleMap[$gasto] ?? 0);
                if ($prevVal == 0.0 && $currVal == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $gasto, $prevVal, $currVal, 'currency', $gIdx % 2 === 0);
                $gIdx++;
            }
            if (abs($gastosOpOtros) >= 0.01 || abs($cm['gastosOpOtros']) >= 0.01) {
                $this->writeComparativeRow($sheet, $r, 'Otros conceptos operativos', (float)$cm['gastosOpOtros'], (float)$gastosOpOtros, 'currency', $gIdx % 2 === 0);
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'Total Gastos Operativos', (float)$cm['gastosOpTotal'], (float)$gastosOpTotal, 'currency');
            $r++;

            // ── 4. Nómina y Capital Humano ─────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", '4. NÓMINA Y CAPITAL HUMANO'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $nIdx = 0;
            foreach ($nomDisplayOrder as $nomName => $currVal) {
                $prevVal = (float)($cm['nomDisplayOrder'][$nomName] ?? 0);
                if ($currVal == 0 && $prevVal == 0 && !in_array($nomName, $mandatory24)) continue;
                $this->writeComparativeRow($sheet, $r, $nomName, $prevVal, (float)$currVal, 'currency', $nIdx % 2 === 0);
                $nIdx++;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'Total Nómina y Capital Humano', (float)$cm['nomTotal'], (float)$nomTotal, 'currency');
            $r++;

            // ── 5. Préstamos Intersucursales ───────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", '5. PRÉSTAMOS INTERSUCURSALES (solo sucursal operativa → sucursal operativa)'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $this->writeComparativeRow($sheet, $r, 'Activos (fondea)', (float)$cm['brGlobalFondea'], (float)$brGlobalFondea, 'currency', true);
            $this->writeComparativeRow($sheet, $r, 'Pasivos (recibe)', (float)$cm['brGlobalRecibe'], (float)$brGlobalRecibe, 'currency', false);
            $r++;

            // ── 5B. Excedente enviado a corporativo ────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", '5B. EXCEDENTE ENVIADO A CORPORATIVO'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $this->writeComparativeTotalsRow($sheet, $r, 'Total', (float)$cm['excGlobal'], (float)$excGlobal, 'currency');
            $excIdx = 0;
            $cmpExcByName = collect($cm['branchesList'])->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));
            foreach ($branchesList as $eb) {
                $key   = strtoupper(trim($eb['sucursal'] ?? ''));
                $prev  = (float)($cmpExcByName->get($key)['excedentes'] ?? 0);
                $curr  = (float)($eb['excedentes'] ?? 0);
                if ($prev == 0.0 && $curr == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $eb['sucursal'], $prev, $curr, 'currency', $excIdx % 2 === 0);
                $excIdx++;
            }
            $r++;

            // ── 6. Índice de rotación de personal ──────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", '6. ÍNDICE DE ROTACIÓN DE PERSONAL'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $cmpRotation = $cm['rotation'];
            $this->writeComparativeRow($sheet, $r, 'N° de personas que dejaron la empresa', (float)($cmpRotation['bajas'] ?? 0), (float)($rotation['bajas'] ?? 0), 'integer', true);
            $this->writeComparativeRow($sheet, $r, 'Plantilla', (float)($cmpRotation['current_count'] ?? $cmpRotation['promedio'] ?? 0), (float)($rotation['current_count'] ?? $rotation['promedio'] ?? 0), 'integer', false);
            $this->writeComparativeRow($sheet, $r, 'Índice de rotación', (float)($cmpRotation['indice'] ?? 0), (float)($rotation['indice'] ?? 0), 'percent', true);
            $r++;

            // ── 7. EBITDA ───────────────────────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", '7. EBITDA'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            foreach ([
                ['Utilidad bruta',                     $cm['ingresoEbitdaBase'], $ingresoEbitdaBase, 'currency'],
                ['Menos: Gastos Totales',               $cm['gastosTotal'],       $gastosTotal,       'currency'],
                ['  Gastos operativos (OPEX)',          $cm['gastosOpTotal'],     $gastosOpTotal,     'currency'],
                ['  Nómina y Capital Humano',            $cm['nomTotal'],          $nomTotal,          'currency'],
                ['EBITDA',                               $cm['utilidad'],          $utilidad,          'currency'],
                ['Margen EBITDA (%)',                    $cm['margenEbitdaCalc'],  $margenEbitdaCalc,  'percent'],
                ['Excedente enviado a corporativo (informativo)', $cm['excGlobal'], $excGlobal, 'currency'],
                ['Recuperación / Colocación (informativo)', $cm['recTotal'] - $cm['colTotal'], $recTotal - $colTotal, 'currency'],
            ] as $i => [$lbl, $prev, $curr, $fmt]) {
                $this->writeComparativeRow($sheet, $r, $lbl, (float)$prev, (float)$curr, $fmt, $i % 2 === 0);
            }
            $r++;

            // ── Categoría por EBITDA (por sucursal) — mes comparado vs mes actual ──
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'CATEGORÍA POR EBITDA (POR SUCURSAL)'); $r++;
            $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'CATEGORÍA ' . strtoupper($labelCmp), 'C' => 'CATEGORÍA ' . strtoupper($labelCur), 'D' => '', 'E' => '']);
            $r++;
            $catRankedBranches = $branchesList;
            usort($catRankedBranches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
            $catIdx = 0;
            foreach ($catRankedBranches as $cb) {
                $key = strtoupper(trim($cb['sucursal'] ?? ''));
                $cmB = $cmpBranchesByName->get($key);
                $curCat = RadiographyStyleHelper::ebitdaCategory(RadiographyStyleHelper::branchEbitdaEstimate($cb));
                $cmpCat = $cmB ? RadiographyStyleHelper::ebitdaCategory(RadiographyStyleHelper::branchEbitdaEstimate($cmB)) : '—';
                $sheet->setCellValue("A{$r}", $cb['sucursal']);
                $sheet->setCellValue("B{$r}", $cmpCat);
                $sheet->setCellValue("C{$r}", $curCat);
                $this->dataRow($sheet, "A{$r}:E{$r}", $catIdx % 2 === 0);
                foreach (['B' => $cmpCat, 'C' => $curCat] as $col => $cat) {
                    if ($cat === '—') continue;
                    $colors = RadiographyStyleHelper::categoryColors($cat);
                    $sheet->getStyle("{$col}{$r}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => $colors['fg']]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colors['bg']]],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    ]);
                }
                $catIdx++;
                $r++;
            }
            $r++;
            RadiographyStyleHelper::applyHyperlinkStyle($sheet, "A{$r}", '→ Ver detalle completo en hoja CATEGORÍA EBITDA', 'CATEGORÍA EBITDA');
            $sheet->getStyle("A{$r}")->getFont()->setSize(9);
            $r += 2;

            goto globalSectionsDone;
        }

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
            ['Excedente enviado a corporativo', $excedentes, 'currency', ''],
        ]);
        $r++;

        // ── 2. Ingresos / Recuperación ───────────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '2. INGRESOS / RECUPERACIÓN');
        $r++;

        // A) Desglose por componente
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'A) Desglose por componente');
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
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Total Recuperación');
        $sheet->setCellValue("B{$r}", $ingrTotal);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // B) Desglose por sucursal — columnas suman exactamente el Total de cada fila.
        // Excedentes recuperados y Seguro CRECE reconocido (30%) van en columnas propias,
        // nunca ocultos dentro de una bolsa genérica "Conceptos adicionales".
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'B) Desglose por sucursal');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:L{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
        $r++;
        // Header row
        $brIngrHeaders = ['Sucursal', 'Capital', 'Intereses', 'Impuestos', 'Moratorios', 'Cargos adicionales', 'Cargos al inicio', 'Com. apertura', 'Excedentes recuperados', 'Seguro CRECE 30%', 'Otros', 'Total'];
        $brIngrCols    = ['A','B','C','D','E','F','G','H','I','J','K','L'];
        foreach ($brIngrHeaders as $ci => $hdr) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "{$brIngrCols[$ci]}{$r}", $hdr);
        }
        $sheet->getStyle("A{$r}:L{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:L{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_LIGHT_BLUE);
        $r++;
        $sucIngrTotals = array_fill(0, 11, 0.0);
        foreach ($branchesList as $idx => $bBranch) {
            $bCap   = (float)($bBranch['capital_recuperado']  ?? 0);
            $bInt   = (float)($bBranch['interes_recuperado']  ?? 0);
            $bImp   = (float)($bBranch['impuesto_recuperado'] ?? 0);
            $bChg   = (float)($bBranch['charges']             ?? 0);
            $bCarAd = (float)($bBranch['cargos_adicionales']  ?? 0);
            $bCarIni= (float)($bBranch['cargos_inicio']       ?? 0);
            $bCom   = (float)($bBranch['comision_apertura']   ?? 0);
            $bExc   = (float)($bBranch['excedente_recuperado'] ?? 0);
            $bCrece = (float)($bBranch['seguro_crece_reconocido'] ?? 0);
            $bOtros = (float)($bBranch['otros_recuperacion'] ?? 0);
            $bTot   = (float)($bBranch['recuperacion_total']  ?? 0);
            $vals   = [$bCap, $bInt, $bImp, $bChg, $bCarAd, $bCarIni, $bCom, $bExc, $bCrece, $bOtros, $bTot];
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $bBranch['sucursal']);
            foreach (range(0, 10) as $ci) {
                $sheet->setCellValue("{$brIngrCols[$ci+1]}{$r}", $vals[$ci]);
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$brIngrCols[$ci+1]}{$r}");
                $sucIngrTotals[$ci] += $vals[$ci];
            }
            $this->dataRow($sheet, "A{$r}:L{$r}", $idx % 2 === 0);
            $r++;
        }
        // Totals row
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'TOTAL');
        $totCols = ['B','C','D','E','F','G','H','I','J','K','L'];
        foreach ($totCols as $ci => $col) {
            $sheet->setCellValue("{$col}{$r}", $sucIngrTotals[$ci]);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$col}{$r}");
        }
        $this->totalsRow($sheet, "A{$r}:L{$r}");
        // Widen columns for this section
        foreach (['E','F','G','H','I','J','K','L'] as $wCol) {
            if ((float)$sheet->getColumnDimension($wCol)->getWidth() < 16) {
                $sheet->getColumnDimension($wCol)->setWidth(16);
            }
        }
        $r += 2;

        // C) Recuperación por producto — columnas suman exactamente el Total de cada fila.
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'C) Recuperación por producto');
        $sheet->getStyle("A{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:J{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_GRAY);
        $r++;
        $prodHeaders = ['Producto', 'Capital', 'Intereses', 'Impuestos', 'Moratorios', 'Cargos adicionales', 'Com. apertura', 'Excedentes recuperados', 'Seguro CRECE 30%', 'Otros', 'Total'];
        $prodCols    = ['A','B','C','D','E','F','G','H','I','J','K'];
        foreach ($prodHeaders as $ci => $hdr) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "{$prodCols[$ci]}{$r}", $hdr);
        }
        $sheet->getStyle("A{$r}:K{$r}")->getFont()->setBold(true);
        $sheet->getStyle("A{$r}:K{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB(RadiographyStyleHelper::BG_LIGHT_BLUE);
        $r++;
        $recoveryByProduct = $snap['sections']['recovery_by_product'] ?? ['rows' => [], 'total' => 0.0];
        $prodIdx = 0;
        foreach ($recoveryByProduct['rows'] as $pRow) {
            $pVals = [
                (float)$pRow['capital'], (float)$pRow['interes'], (float)$pRow['impuesto'],
                (float)$pRow['moratorios'], (float)$pRow['cargos_adicionales'], (float)$pRow['comision_apertura'],
                (float)($pRow['excedente_recuperado'] ?? 0), (float)($pRow['seguro_crece_reconocido'] ?? 0),
                (float)$pRow['otros'], (float)$pRow['total'],
            ];
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $pRow['product']);
            foreach (range(0, 9) as $ci) {
                $sheet->setCellValue("{$prodCols[$ci+1]}{$r}", $pVals[$ci]);
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$prodCols[$ci+1]}{$r}");
            }
            $this->dataRow($sheet, "A{$r}:K{$r}", $prodIdx % 2 === 0);
            $prodIdx++;
            $r++;
        }
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'TOTAL');
        $sheet->setCellValue("K{$r}", (float)$recoveryByProduct['total']);
        RadiographyStyleHelper::applyCurrencyFormat($sheet, "K{$r}");
        $this->totalsRow($sheet, "A{$r}:K{$r}");
        $r += 2;

        // ── Seguros y coberturas canalizadas — informativo, no afecta recuperación,
        // OPEX, nómina ni EBITDA. ────────────────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", 'SEGUROS Y COBERTURAS CANALIZADAS');
        $r++;
        $r = $writeRows($r, array_filter([
            $ingrSavehearts > 0 ? ['Cobertura Savehearts', $ingrSavehearts, 'currency', ''] : null,
            $ingrComadres   > 0 ? ['Cobertura Crédito Grupal / Comadres', $ingrComadres, 'currency', ''] : null,
            $ingrCrece      > 0 ? ['Seguro CRECE total', $ingrCrece, 'currency', ''] : null,
            $ingrCrece30    > 0 ? ['Reconocido como ingreso MR Lana (30%)', $ingrCrece30, 'currency', ''] : null,
            $ingrCrece70    > 0 ? ['Canalizado a aseguradora (70%)', $ingrCrece70, 'currency', ''] : null,
        ]));
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Total canalizado a aseguradora');
        $sheet->setCellValue("B{$r}", $ingrCanalizadoAseguradora);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
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
        if (abs($gastosOpOtros) >= 0.01) {
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Otros conceptos operativos');
            $sheet->setCellValue("B{$r}", $gastosOpOtros);
            $sheet->setCellValue("C{$r}", '');
            $this->dataRow($sheet, "A{$r}:D{$r}", $gastosOpIdx % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", 'currency', $gastosOpOtros);
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

        // Deducciones NOI — informativas, NUNCA se restan del Total de arriba (regla
        // vigente 2026-07). Aparte para que nunca se confundan con un componente del total.
        if (!empty($globalNomDeducciones)) {
            RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", 'DEDUCCIONES NOI (informativo — no se restan del total)');
            $r++;
            $di = 0;
            $dedTotal = 0.0;
            foreach ($globalNomDeducciones as $dedName => $dedVal) {
                $dedVal = (float) $dedVal;
                if ($dedVal == 0.0) continue;
                RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $dedName);
                $sheet->setCellValue("B{$r}", $dedVal);
                $this->dataRow($sheet, "A{$r}:D{$r}", $di % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $dedVal);
                $dedTotal += $dedVal;
                $di++;
                $r++;
            }
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Total deducciones (informativo)');
            $sheet->setCellValue("B{$r}", $dedTotal);
            $this->totalsRow($sheet, "A{$r}:D{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r++;
        }
        $r++;

        // ── 5. Préstamos Intersucursales ──────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '5. PRÉSTAMOS INTERSUCURSALES (solo sucursal operativa → sucursal operativa)');
        $r++;
        $r = $writeRows($r, [
            ['Activos (fondea)',  $brGlobalFondea, 'currency', ''],
            ['Pasivos (recibe)',  $brGlobalRecibe, 'currency', ''],
        ]);
        $r++;

        // ── 5b. Excedente enviado a corporativo (separado de préstamos intersucursales) ──
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '5B. EXCEDENTE ENVIADO A CORPORATIVO');
        $r++;
        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'Total');
        $sheet->setCellValue("B{$r}", $excGlobal);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r++;
        $excIdx = 0;
        $excBranchRanked = $branchesList;
        usort($excBranchRanked, fn ($a, $b) => (float)($b['excedentes'] ?? 0) <=> (float)($a['excedentes'] ?? 0));
        foreach ($excBranchRanked as $eb) {
            $ebVal = (float)($eb['excedentes'] ?? 0);
            if ($ebVal == 0.0) continue;
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $eb['sucursal']);
            $sheet->setCellValue("B{$r}", $ebVal);
            $this->dataRow($sheet, "A{$r}:D{$r}", $excIdx % 2 === 0);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            $excIdx++;
            $r++;
        }
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

        // ── 7. EBITDA ─────────────────────────────────────────────────────────
        // EBITDA = Ingreso base EBITDA − Gastos Totales. Ingreso base EBITDA = Intereses +
        // Impuestos + Moratorios/Multas + Comisión por apertura + Cargos adicionales +
        // Excedentes recuperados + Seguro CRECE reconocido (30%) — NUNCA capital recuperado,
        // NUNCA Recuperación/Colocación completas.
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '7. EBITDA');
        $r++;
        $r = $writeRows($r, [
            ['Utilidad bruta',                              $ingresoEbitdaBase, 'currency', ''],
            ['  Intereses',                                $ingrInteres,   'currency', ''],
            ['  Impuestos',                                $ingrImpuesto,  'currency', ''],
            ['  Moratorios / Multas',                      $ingrCharges,   'currency', ''],
            ['  Comisión por apertura',                    $ingrComAper,   'currency', ''],
            ['  Cargos adicionales',                       $ingrCargosAdic,'currency', ''],
            ['  Excedentes recuperados',                   $ingrExcedRec,  'currency', ''],
            ['  Seguro CRECE reconocido (30%)',             $ingrCrece30,   'currency', ''],
            ['Menos: Gastos Totales',                      $gastosTotal,   'currency', ''],
            ['  Gastos operativos (OPEX)',                 $gastosOpTotal, 'currency', ''],
            ['  Nómina y Capital Humano',                  $nomTotal,      'currency', ''],
            ['EBITDA',                                     $utilidad,      'currency', ''],
            ['Margen EBITDA (%)',                          $margenEbitdaCalc, 'percent', ''],
            ['Excedente enviado a corporativo (informativo)', $excGlobal,  'currency', ''],
            ['Recuperación / Colocación (informativo)', $recTotal - $colTotal, 'currency', ''],
        ]);
        $r++;

        // ── CATEGORÍA POR EBITDA (por sucursal) — visible aquí en GLOBAL, no
        // escondida en una hoja aparte. Misma fórmula que usa el PDF, centralizada
        // en RadiographyStyleHelper::branchEbitdaEstimate()/ebitdaCategory() para
        // que ambos documentos coincidan siempre. Detalle completo (Recuperación/
        // Gastos/Nómina por sucursal) en la hoja CATEGORÍA EBITDA.
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", 'CATEGORÍA POR EBITDA (POR SUCURSAL)');
        $r++;
        RadiographyStyleHelper::applyTableHeaderStyle($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'CATEGORÍA', 'C' => '', 'D' => '']);
        $r++;
        $catRankedBranches = $branchesList;
        usort($catRankedBranches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
        $catIdx = 0;
        foreach ($catRankedBranches as $cb) {
            $cbUtil   = RadiographyStyleHelper::branchEbitdaEstimate($cb);
            $cbCat    = RadiographyStyleHelper::ebitdaCategory($cbUtil);
            $cbColors = RadiographyStyleHelper::categoryColors($cbCat);
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $cb['sucursal']);
            $sheet->setCellValue("B{$r}", $cbCat);
            $this->dataRow($sheet, "A{$r}:D{$r}", $catIdx % 2 === 0);
            $sheet->getStyle("B{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => $cbColors['fg']]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $cbColors['bg']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $catIdx++;
            $r++;
        }
        $r++;
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, "A{$r}", '→ Ver detalle completo en hoja CATEGORÍA EBITDA', 'CATEGORÍA EBITDA');
        $sheet->getStyle("A{$r}")->getFont()->setSize(9);
        $r += 2;

        globalSectionsDone:
        // ── 8. Observaciones y Notas ─────────────────────────────────────────
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", '8. OBSERVACIONES Y NOTAS');
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

        // ── Gráficas nativas GLOBAL (2026-08-25) ──────────────────────────────
        // Recuperación vs Colocación, Cartera vs Cartera Vencida, Mora por Bucket y
        // EBITDA YA existían como charts nativos en esta hoja (ver más arriba en esta
        // misma función: "Gráficas EBITDA / Gastos / Nómina / OPEX" /
        // addComparativeChart()/RadiographyStyleHelper::addComparativeBarChart() —
        // pre-existentes de una sesión anterior). NO se duplican. Solo se agregan los
        // 2 desgloses por producto que sí faltaban, reutilizando
        // RadiographyExcelChartHelper (adaptador sobre el mismo
        // RadiographyStyleHelper::addBarChart nativo) y las secciones ya calculadas.
        if (!$isComparative) {
            $chartHelper = app(RadiographyExcelChartHelper::class);

            $productsGeneral = $snap['sections']['products'] ?? [];
            if (!empty($productsGeneral) && !($productsGeneral['not_attributable'] ?? false) && array_is_list($productsGeneral)) {
                $chartHelper->addBarChartFromData(
                    $sheet,
                    array_map(fn ($p) => ['label' => $p['producto'] ?? '—', 'value' => (float) ($p['colocacion'] ?? 0)], $productsGeneral),
                    'Colocación por producto', 'general_coloc_producto', 20, $r + 2, "W{$r}",
                );
            }

            // buildRecoveryByProduct() envuelve las filas en ['rows' => [...], 'total' => ...]
            // (no es una lista plana como 'products') — ver
            // BranchRadiographyCalculator::buildRecoveryByProduct().
            $recoveryByProductGeneral = $snap['sections']['recovery_by_product']['rows'] ?? [];
            if (!empty($recoveryByProductGeneral) && array_is_list($recoveryByProductGeneral)) {
                $chartHelper->addBarChartFromData(
                    $sheet,
                    array_map(fn ($p) => ['label' => $p['product'] ?? '—', 'value' => (float) ($p['total'] ?? 0)], $recoveryByProductGeneral),
                    'Recuperación por producto', 'general_rec_producto', 20, $r + 16, 'W' . ($r + 14),
                );
            }
        }

        // Freeze mínimo: solo título/subtítulo/meta (filas 1-3). Antes se congelaba
        // hasta la fila del encabezado de tabla (~21), dejando muy poco espacio
        // visible para navegar hacia las secciones inferiores.
        $sheet->freezePane('A4');
        // Nota: los accesos directos a cada sucursal ya están en el panel de filtros
        // (filas 16-19, junto a NAVEGACIÓN); no se repiten aquí para evitar duplicar
        // el mismo enlace dos veces en la misma hoja.
    }

    // ── VALOR CARTERA ────────────────────────────────────────────────────────

    private function buildValorCarteraSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet    = $ss->createSheet()->setTitle('VALOR CARTERA');
        $brCalc   = $snap['branch_radiography'] ?? [];
        $global   = $brCalc['global']   ?? [];
        $branches = $brCalc['branches'] ?? [];
        $label    = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:G1', 'VALOR CARTERA — ' . ($isComparative ? strtoupper($comparePeriod->label) . ' VS ' . $label : $label));
        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);
        $sheet->setCellValue('B2', 'Cartera vencida = Capital atrasado + Interés atrasado + Impuesto atrasado + Saldo int. moratorio + Saldo imp. int. moratorio');
        $this->metaStyle($sheet, 'B2:G2');
        RadiographyStyleHelper::mergeCellsSafe($sheet, 'B2:G2');

        $carteraGlobal = (float)($global['valor_cartera'] ?? 0);
        $vencidaGlobal = (float)($global['cartera_vencida'] ?? 0);
        $pctMora       = $carteraGlobal > 0 ? round($vencidaGlobal / $carteraGlobal * 100, 2) : 0;

        $pbbp   = $snap['sections']['portfolio_by_branch_product'] ?? [];
        $capSum = array_sum(array_column($pbbp, 'capital_atrasado'));
        $intSum = array_sum(array_column($pbbp, 'interes_atrasado'));
        $impSum = array_sum(array_column($pbbp, 'impuesto_atrasado'));
        $simSum = array_sum(array_column($pbbp, 'saldo_interes_moratorio'));
        $siiSum = array_sum(array_column($pbbp, 'saldo_impuesto_interes_moratorio'));

        if ($isComparative) {
            $cGlobal   = $compareSnap['branch_radiography']['global']    ?? [];
            $cBranches = $compareSnap['branch_radiography']['branches']  ?? [];
            $cCartera  = (float)($cGlobal['valor_cartera'] ?? 0);
            $cVencida  = (float)($cGlobal['cartera_vencida'] ?? 0);
            $cPbbp     = $compareSnap['sections']['portfolio_by_branch_product'] ?? [];
            $cCapSum   = array_sum(array_column($cPbbp, 'capital_atrasado'));
            $cIntSum   = array_sum(array_column($cPbbp, 'interes_atrasado'));
            $cImpSum   = array_sum(array_column($cPbbp, 'impuesto_atrasado'));
            $cSimSum   = array_sum(array_column($cPbbp, 'saldo_interes_moratorio'));
            $cSiiSum   = array_sum(array_column($cPbbp, 'saldo_impuesto_interes_moratorio'));
            $labelCmp  = $comparePeriod->label;
            $labelCur  = $period->label;

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'RESUMEN GLOBAL'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            foreach ([
                ['Valor cartera total',      $cCartera, $carteraGlobal, 'currency'],
                ['Cartera vencida (5 cols)', $cVencida, $vencidaGlobal, 'currency'],
                ['% Mora sobre cartera',     $cCartera > 0 ? round($cVencida / $cCartera * 100, 2) : 0, $pctMora, 'percent'],
                ['Colocación del periodo',   (float)($cGlobal['colocacion'] ?? 0), (float)($global['colocacion'] ?? 0), 'currency'],
                ['Recuperación del periodo', (float)($cGlobal['recuperacion_total'] ?? 0), (float)($global['recuperacion_total'] ?? 0), 'currency'],
            ] as $i => [$lbl, $prev, $curr, $fmt]) {
                $this->writeComparativeRow($sheet, $r, $lbl, (float)$prev, (float)$curr, $fmt, $i % 2 === 0);
            }
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'DESGLOSE CARTERA VENCIDA — COMPONENTES GLOBALES'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            foreach ([
                ['Capital atrasado',              $cCapSum, $capSum],
                ['Interés atrasado',              $cIntSum, $intSum],
                ['Impuesto atrasado',             $cImpSum, $impSum],
                ['Saldo interés moratorio',       $cSimSum, $simSum],
                ['Saldo impuesto int. moratorio', $cSiiSum, $siiSum],
            ] as $i => [$lbl, $prev, $curr]) {
                $this->writeComparativeRow($sheet, $r, $lbl, (float)$prev, (float)$curr, 'currency', $i % 2 === 0);
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'Cartera vencida TOTAL', (float)$cVencida, (float)$vencidaGlobal, 'currency');
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'CARTERA POR SUCURSAL'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cBranchesByName = collect($cBranches)->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));
            usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
            $chartStart = $r;
            foreach ($branches as $i => $b) {
                $key  = strtoupper(trim($b['sucursal'] ?? ''));
                $prev = (float)($cBranchesByName->get($key)['valor_cartera'] ?? 0);
                $curr = (float)($b['valor_cartera'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $b['sucursal'], $prev, $curr, 'currency', $i % 2 === 0);
            }
            $chartEnd = $r - 1;
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL', (float)$cCartera, (float)$carteraGlobal, 'currency');
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'Cartera por sucursal', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O24');
            }

            $this->setColWidths($sheet, ['A' => 28, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

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

    private function buildMorasSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet   = $ss->createSheet()->setTitle('MORAS');
        $buckets = $snap['sections']['portfolio_buckets'] ?? [];
        $brCalc  = $snap['branch_radiography'] ?? [];
        $branches = $brCalc['branches'] ?? [];
        $label   = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:F1', 'MORAS — ' . ($isComparative ? strtoupper($comparePeriod->label) . ' VS ' . $label : $label));
        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);
        $sheet->setCellValue('B2', 'Días vencidos — Lendus Saldos por Cliente');
        $this->metaStyle($sheet, 'B2:F2');
        RadiographyStyleHelper::mergeCellsSafe($sheet,'B2:F2');

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cBuckets  = $compareSnap['sections']['portfolio_buckets'] ?? [];
            $cBranches = $compareSnap['branch_radiography']['branches'] ?? [];
            $cGlobal   = $compareSnap['branch_radiography']['global'] ?? [];
            $global    = $brCalc['global'] ?? [];

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'DISTRIBUCIÓN GLOBAL POR DÍAS VENCIDOS'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'BUCKET'); $r++;
            $cBucketsByLabel = collect($cBuckets)->keyBy('label');
            $chartStart = $r;
            foreach ($buckets as $i => $b) {
                $prev = (float)($cBucketsByLabel->get($b['label'])['vencida'] ?? 0);
                $curr = (float)($b['vencida'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $b['label'], $prev, $curr, 'currency', $i % 2 === 0);
            }
            $chartEnd = $r - 1;
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'Mora por bucket (días vencidos)', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O18');
            }
            $r++;

            // Mora por sucursal — colapsada a cartera vencida total (5 columnas x 2
            // periodos sería ilegible; criterio explícito para tablas anchas).
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'MORA POR SUCURSAL (cartera vencida total)'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cBranchesByName = collect($cBranches)->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));
            usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
            foreach ($branches as $i => $b) {
                $key  = strtoupper(trim($b['sucursal'] ?? ''));
                $prev = (float)($cBranchesByName->get($key)['cartera_vencida'] ?? 0);
                $curr = (float)($b['cartera_vencida'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $b['sucursal'], $prev, $curr, 'currency', $i % 2 === 0);
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL', (float)($cGlobal['cartera_vencida'] ?? 0), (float)($global['cartera_vencida'] ?? 0), 'currency');
            $r++;

            // Desglose por componente — colapsado a total por bucket (Capital+Interés+
            // Impuesto+Moratorio+Imp.Moratorio ya sumados), mismo criterio.
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'DESGLOSE POR COMPONENTE — MORA GLOBAL (total por bucket)'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'BUCKET'); $r++;
            $bucketDefs = [
                'mora_0_30'     => 'Mora 1-30 días',
                'mora_31_60'    => 'Mora 31-60 días',
                'mora_61_90'    => 'Mora 61-90 días',
                'mora_91_120'   => 'Mora 91-120 días',
                'mora_120_plus' => 'Mora 120+ días',
            ];
            $bi = 0;
            foreach ($bucketDefs as $bKey => $bLabel) {
                $prev = (float)($cGlobal[$bKey] ?? 0);
                $curr = (float)($global[$bKey] ?? 0);
                $this->writeComparativeRow($sheet, $r, $bLabel, $prev, $curr, 'currency', $bi % 2 === 0);
                $bi++;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL MORA', (float)($cGlobal['cartera_vencida'] ?? 0), (float)($global['cartera_vencida'] ?? 0), 'currency');

            $this->setColWidths($sheet, ['A' => 26, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

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

    private function buildEfectividadCobranzaSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet = $ss->createSheet()->setTitle('EFECT. COBRANZA');
        $label = strtoupper($period->label);
        $ec    = $snap['sections']['efectividad_cobranza'] ?? [];

        $this->sheetTitle($sheet, 'A1:H1', 'EFECTIVIDAD DE COBRANZA — ' . ($isComparative ? strtoupper($comparePeriod->label) . ' VS ' . $label : $label));
        $sheet->setCellValue('B2', 'Cobros clasificados por estatus del crédito: Vigente (DPD=0) / Atrasado (1-90) / Vencido (>90). Exclusiones: Seguros, Condonaciones, Coberturas.');
        $this->metaStyle($sheet, 'B2:H2');
        RadiographyStyleHelper::mergeCellsSafe($sheet, 'B2:H2');

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cEc = $compareSnap['sections']['efectividad_cobranza'] ?? [];
            $statusDefs = ['vigente' => 'Vigente (DPD=0)', 'atrasado' => 'Atrasado (1-90)', 'vencido' => 'Vencido (>90)'];

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'RESUMEN POR ESTATUS (TOTAL)'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'ESTATUS'); $r++;
            $chartStart = $r;
            foreach ($statusDefs as $key => $lbl) {
                $prev = (float)($cEc[$key]['total'] ?? 0);
                $curr = (float)($ec[$key]['total'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $lbl, $prev, $curr, 'currency', ($r - $chartStart) % 2 === 0);
            }
            $chartEnd = $r - 1;
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL', (float)($cEc['total']['total'] ?? 0), (float)($ec['total']['total'] ?? 0), 'currency');
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'Efectividad de cobranza por estatus', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O18');
            }
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'INDICADORES DE EFECTIVIDAD'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $cVigente  = (float)($cEc['vigente']['total'] ?? 0);
            $cAtrasado = (float)($cEc['atrasado']['total'] ?? 0);
            $cVencido  = (float)($cEc['vencido']['total'] ?? 0);
            $vigente   = (float)($ec['vigente']['total'] ?? 0);
            $atrasado  = (float)($ec['atrasado']['total'] ?? 0);
            $vencido   = (float)($ec['vencido']['total'] ?? 0);
            $this->writeComparativeRow($sheet, $r, 'Cobros en mora (Atrasado + Vencido)', $cAtrasado + $cVencido, $atrasado + $vencido, 'currency', true);
            $this->writeComparativeRow($sheet, $r, 'Cobros al corriente (Vigente)', $cVigente, $vigente, 'currency', false);
            $r++;
            // % real de efectividad — recuperado de mora ÷ cartera en mora al cierre del
            // MES ANTERIOR a cada periodo respectivamente (nunca una fórmula compartida).
            $cEfPct = $cEc['efectividad']['efectividad_pct'] ?? null;
            $efPct  = $ec['efectividad']['efectividad_pct'] ?? null;
            $sheet->setCellValue("A{$r}", 'Efectividad de cobranza (real)');
            $sheet->setCellValue("B{$r}", $cEfPct !== null ? number_format($cEfPct, 1) . '%' : 'N/D');
            $sheet->setCellValue("C{$r}", $efPct !== null ? number_format($efPct, 1) . '%' : 'N/D');
            $this->dataRow($sheet, "A{$r}:C{$r}", true);

            $this->setColWidths($sheet, ['A' => 30, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

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
            'vigente'  => 'Vigente (DPD=0)',
            'atrasado' => 'Atrasado (1-90)',
            'vencido'  => 'Vencido (>90)',
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

        // % real de efectividad (2026-08-26) — recuperado de cartera en mora este
        // periodo ÷ cartera en mora (DPD>0) al cierre del mes anterior. Distinto de las
        // filas de arriba (esas son composición del dinero cobrado, no una tasa contra
        // lo que había que cobrar). Ver RadiographySnapshotBuilder::buildEfectividadCobranza().
        $efInfo = $ec['efectividad'] ?? null;
        if ($efInfo) {
            $sheet->setCellValue("A{$r}", 'Efectividad de cobranza (real)');
            $sheet->setCellValue("B{$r}", $efInfo['efectividad_pct'] !== null
                ? number_format($efInfo['efectividad_pct'], 1) . '% — recuperado ' . '$' . number_format($efInfo['recuperado_de_mora'], 2)
                    . ' de $' . number_format($efInfo['cartera_mora_periodo_anterior'], 2) . ' en mora al cierre de ' . ($efInfo['periodo_anterior_label'] ?? '')
                : 'N/D — sin cartera del mes anterior para calcular el denominador');
            $r++;
        }

        foreach (['A'=>32,'B'=>22,'C'=>18,'D'=>18,'E'=>18,'F'=>18,'G'=>18,'H'=>12] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->freezePane('A4');
    }

    // ── INGRESOS ─────────────────────────────────────────────────────────────

    private function buildIngresosSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet   = $ss->createSheet()->setTitle('INGRESOS');
        $brCalc  = $snap['branch_radiography'] ?? [];
        $global  = $brCalc['global']   ?? [];
        $branches = $brCalc['branches'] ?? [];
        $label   = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:O1', 'INGRESOS / RECUPERACIÓN — ' . ($isComparative ? strtoupper($comparePeriod->label) . ' VS ' . $label : $label));
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');
        $sheet->setCellValue('B2', 'Desglose de recuperación por componente y sucursal');
        $this->metaStyle($sheet, 'B2:O2');
        RadiographyStyleHelper::mergeCellsSafe($sheet,'B2:O2');

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cGlobal  = $compareSnap['branch_radiography']['global']   ?? [];
            $cBranches = $compareSnap['branch_radiography']['branches'] ?? [];

            $componentFields = [
                'Capital'            => 'capital_recuperado',
                'Intereses'          => 'interes_recuperado',
                'Impuestos'          => 'impuesto_recuperado',
                'Moratorios'         => 'charges',
                'Cargos adicionales' => 'cargos_adicionales',
                'Excedentes'         => 'excedente_recuperado',
                'Cargos al inicio'   => 'cargos_inicio',
                'Comisión apertura'  => 'comision_apertura',
                'Seguro CRECE 30%'   => 'seguro_crece_reconocido',
                'Otros conceptos'    => 'otros_recuperacion',
            ];

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'A) DESGLOSE POR COMPONENTE (GLOBAL)'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $ci = 0;
            foreach ($componentFields as $lbl => $field) {
                $prev = (float)($cGlobal[$field] ?? 0);
                $curr = (float)($global[$field] ?? 0);
                if ($prev == 0.0 && $curr == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $lbl, $prev, $curr, 'currency', $ci % 2 === 0);
                $ci++;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'Total Recuperación', (float)($cGlobal['recuperacion_total'] ?? 0), (float)($global['recuperacion_total'] ?? 0), 'currency');
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'B) RECUPERACIÓN POR SUCURSAL (TOTAL)'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cBranchesByName = collect($cBranches)->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));
            usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
            $chartStart = $r;
            foreach ($branches as $i => $b) {
                $key  = strtoupper(trim($b['sucursal'] ?? ''));
                $prev = (float)($cBranchesByName->get($key)['recuperacion_total'] ?? 0);
                $curr = (float)($b['recuperacion_total'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $b['sucursal'], $prev, $curr, 'currency', $i % 2 === 0);
            }
            $chartEnd = $r - 1;
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL GLOBAL', (float)($cGlobal['recuperacion_total'] ?? 0), (float)($global['recuperacion_total'] ?? 0), 'currency');
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'Recuperación por sucursal', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O24');
            }

            $this->setColWidths($sheet, ['A' => 26, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

        $r = 4;
        $this->sectionHeader($sheet, "A{$r}:L{$r}", 'INGRESOS POR SUCURSAL — Desglose por componente');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'SUCURSAL',
            'B' => 'CAPITAL',
            'C' => 'INTERESES',
            'D' => 'IMPUESTOS',
            'E' => 'MORATORIOS',
            'F' => 'CARGOS ADIC.',
            'G' => 'EXCEDENTES',
            'H' => 'CARGOS INICIO',
            'I' => 'COM. APERTURA',
            'J' => 'SEGURO CRECE 30%',
            'K' => 'OTROS CONCEPTOS',
            'L' => 'TOTAL RECUPERACIÓN',
        ]);
        $r++;

        usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
        foreach ($branches as $i => $b) {
            $cap   = (float)($b['capital_recuperado']     ?? 0);
            $int   = (float)($b['interes_recuperado']     ?? 0);
            $imp   = (float)($b['impuesto_recuperado']    ?? 0);
            $mul   = (float)($b['charges']                ?? 0);
            $cad   = (float)($b['cargos_adicionales']     ?? 0);
            $exc   = (float)($b['excedente_recuperado']   ?? 0);
            $car   = (float)($b['cargos_inicio']          ?? 0);
            $com   = (float)($b['comision_apertura']      ?? 0);
            $crece = (float)($b['seguro_crece_reconocido'] ?? 0);
            $otr   = (float)($b['otros_recuperacion']     ?? 0);
            $tot   = (float)($b['recuperacion_total']     ?? 0);
            $vals = [
                'B'=>$cap,'C'=>$int,'D'=>$imp,'E'=>$mul,'F'=>$cad,'G'=>$exc,
                'H'=>$car,'I'=>$com,'J'=>$crece,'K'=>$otr,'L'=>$tot,
            ];
            $sheet->setCellValue("A{$r}", $b['sucursal']);
            foreach ($vals as $col => $val) {
                $sheet->setCellValue("{$col}{$r}", $val);
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $this->dataRow($sheet, "A{$r}:L{$r}", $i % 2 === 0);
            $r++;
        }
        // TOTAL GLOBAL
        $sheet->setCellValue("A{$r}", 'TOTAL GLOBAL');
        $gTotals = [
            'B' => (float)($global['capital_recuperado']    ?? 0),
            'C' => (float)($global['interes_recuperado']    ?? 0),
            'D' => (float)($global['impuesto_recuperado']   ?? 0),
            'E' => (float)($global['charges']               ?? 0),
            'F' => (float)($global['cargos_adicionales']    ?? 0),
            'G' => (float)($global['excedente_recuperado']  ?? 0),
            'H' => (float)($global['cargos_inicio']         ?? 0),
            'I' => (float)($global['comision_apertura']     ?? 0),
            'J' => (float)($global['seguro_crece_reconocido'] ?? 0),
            'K' => (float)($global['otros_recuperacion']    ?? 0),
            'L' => (float)($global['recuperacion_total']    ?? 0),
        ];
        foreach ($gTotals as $col => $val) {
            $sheet->setCellValue("{$col}{$r}", $val);
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        }
        $this->totalsRow($sheet, "A{$r}:L{$r}");
        $r++;

        foreach (['A'=>22,'B'=>14,'C'=>14,'D'=>12,'E'=>14,'F'=>12,'G'=>12,
                  'H'=>14,'I'=>14,'J'=>14,'K'=>12,'L'=>18] as $col => $w) {
            $sheet->getColumnDimension($col)->setWidth($w);
        }
        $sheet->setAutoFilter('A5:L5');
        $sheet->freezePane('A6');
    }

    // ── GASTOS (canonical cross-tab desde branch_radiography) ────────────────

    private function buildGastosSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet    = $ss->createSheet()->setTitle('GASTOS');
        $brCalc   = $snap['branch_radiography'] ?? [];
        $global   = $brCalc['global']   ?? [];
        $branches = $brCalc['branches'] ?? [];
        $label    = strtoupper($period->label);

        $conceptos = [
            'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
            'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
            'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
            'Publicidad','Mecánicos','Servicios de Motocicletas','Financiamiento de Motos',
            'Software Póliza Anual','Pólizas',
            'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
            'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
            'Gastos legales',
        ];

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cGlobal   = $compareSnap['branch_radiography']['global']   ?? [];
            $cBranches = $compareSnap['branch_radiography']['branches'] ?? [];
            $cGlobalDet = (array)($cGlobal['gastos_detalle'] ?? []);
            $globalDet  = (array)($global['gastos_detalle'] ?? []);

            RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:E1', 'GASTOS OPERATIVOS — ' . strtoupper($labelCmp) . ' VS ' . strtoupper($labelCur));
            RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'CONCEPTO (GLOBAL) — no se eliminan ni renombran conceptos'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'CONCEPTO'); $r++;
            $gi = 0;
            foreach ($conceptos as $con) {
                $prev = (float)($cGlobalDet[$con] ?? 0);
                $curr = (float)($globalDet[$con] ?? 0);
                if ($prev == 0.0 && $curr == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $con, $prev, $curr, 'currency', $gi % 2 === 0);
                $gi++;
            }
            // Cualquier concepto real no cubierto por la lista curada (nunca se pierde un peso).
            $extraLabels = collect(array_keys($cGlobalDet))->merge(array_keys($globalDet))->unique()
                ->reject(fn ($c) => in_array($c, $conceptos, true))->values();
            foreach ($extraLabels as $con) {
                $prev = (float)($cGlobalDet[$con] ?? 0);
                $curr = (float)($globalDet[$con] ?? 0);
                if ($prev == 0.0 && $curr == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $con, $prev, $curr, 'currency', $gi % 2 === 0);
                $gi++;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'Total OPEX', (float)($cGlobal['gastos_operativos'] ?? 0), (float)($global['gastos_operativos'] ?? 0), 'currency');
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'GASTOS OPEX POR SUCURSAL'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cBranchesByName = collect($cBranches)->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));
            usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
            $chartStart = $r;
            foreach ($branches as $i => $b) {
                $key  = strtoupper(trim($b['sucursal'] ?? ''));
                $prev = (float)($cBranchesByName->get($key)['gastos_operativos'] ?? 0);
                $curr = (float)($b['gastos_operativos'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $b['sucursal'], $prev, $curr, 'currency', $i % 2 === 0);
            }
            $chartEnd = $r - 1;
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL', (float)($cGlobal['gastos_operativos'] ?? 0), (float)($global['gastos_operativos'] ?? 0), 'currency');
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'OPEX por sucursal', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O24');
            }

            $this->setColWidths($sheet, ['A' => 30, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

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

        // ── 0. Resumen OPEX ──────────────────────────────────────────────────────
        $r = 4;
        $this->sectionHeader($sheet, "A{$r}:C{$r}", '0. RESUMEN OPEX');
        $r++;

        $get = fn (string $k) => (float) ($global[$k] ?? 0.0);

        $resumenRows = [
            ['OPEX TOTAL', $get('gastos_operativos'), true ],
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

        // ── TABLA A: Gastos OPEX por sucursal ────────────────────────────────────
        $r += 2;
        $this->sectionHeader($sheet, "A{$r}:B{$r}", 'TABLA A — GASTOS OPEX POR SUCURSAL');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'SUCURSAL',
            'B' => 'TOTAL OPEX',
        ]);
        $r++;
        $totOpe = 0.0;
        foreach ($branches as $i => $b) {
            $ope  = (float)($b['gastos_operativos'] ?? 0);
            $sheet->setCellValue("A{$r}", $b['sucursal']);
            $sheet->setCellValue("B{$r}", $ope);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            $totOpe += $ope;
            $r++;
        }
        $gOpe  = (float)($global['gastos_operativos'] ?? 0);
        $sheet->setCellValue("A{$r}", 'TOTAL');
        $sheet->setCellValue("B{$r}", $gOpe);
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $this->totalsRow($sheet, "A{$r}:B{$r}");
        $r += 2;

        // ── TABLA B: Gastos por categoría (GLOBAL) ──────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:C{$r}", 'TABLA B — GASTOS POR CATEGORÍA (GLOBAL — OPEX neto)');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'CATEGORÍA / CONCEPTO',
            'B' => 'TOTAL OPEX',
            'C' => '% DEL TOTAL',
        ]);
        $r++;
        $catStartRow = $r;
        $gOpeTotal = max($gOpe, 0.01);
        $catsSorted = collect($globalDet)->filter(fn ($v) => (float)$v > 0)->sortDesc();
        $ci = 0;
        foreach ($catsSorted as $concepto => $monto) {
            $val = (float)$monto;
            $pct = round($val / $gOpeTotal * 100, 1);
            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", $concepto);
            $sheet->setCellValue("B{$r}", $val);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $sheet->setCellValue("C{$r}", $pct);
            $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("C{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $this->dataRow($sheet, "A{$r}:C{$r}", $ci % 2 === 0);
            $ci++; $r++;
        }
        $catEndRow = $r - 1;
        $sheet->setCellValue("A{$r}", 'TOTAL OPEX');
        $sheet->setCellValue("B{$r}", $gOpe);
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $sheet->setCellValue("C{$r}", 100.0);
        $sheet->getStyle("C{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
        $this->totalsRow($sheet, "A{$r}:C{$r}");
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
    private function buildNominaSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet      = $ss->createSheet()->setTitle('NÓMINA');
        $brCalc     = $snap['branch_radiography'] ?? [];
        $global     = $brCalc['global']     ?? [];
        $branches   = $brCalc['branches']   ?? [];
        $label      = strtoupper($period->label);

        RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:B1', 'NÓMINA — ' . ($isComparative ? strtoupper($comparePeriod->label) . ' VS ' . $label : $label));
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'C1', '← GLOBAL', 'GLOBAL');

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $m  = $this->extractGlobalCoreMetrics($snap);
            $cm = $this->extractGlobalCoreMetrics($compareSnap);
            $cBranches = $compareSnap['branch_radiography']['branches'] ?? [];

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'CONCEPTO (GLOBAL) — mismos conceptos del reporte simple'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'CONCEPTO'); $r++;
            $ni = 0;
            $mandatory24 = ['Nómina','Comisiones','Vacaciones','Prima vacacional','Bonos','Bonos Aceleradores','IMSS','Descuentos Infonavit','Finiquito','Gastos médicos','Gasolina','Financiamiento De Motos','Descuento Servicios Moto','Financiamiento Celular','Cascos','Descuento de uniformes','Descuentos FONACOT','Descuento extravío tarjeta de circulación','Descuentos Tienda Mr Lana','Descuento Servicios Automóvil','Descuento faltante en caja','Anticipo de nómina','Formatería','Pensión Alimenticia'];
            foreach ($m['nomDisplayOrder'] as $nomName => $currVal) {
                $prevVal = (float)($cm['nomDisplayOrder'][$nomName] ?? 0);
                if ($currVal == 0 && $prevVal == 0 && !in_array($nomName, $mandatory24, true)) continue;
                $this->writeComparativeRow($sheet, $r, $nomName, $prevVal, (float)$currVal, 'currency', $ni % 2 === 0);
                $ni++;
            }
            $this->writeComparativeRow($sheet, $r, 'Percepciones', (float)($cm['sum']['noi_percepciones'] ?? 0), (float)($m['sum']['noi_percepciones'] ?? 0), 'currency', $ni % 2 === 0); $ni++;
            $this->writeComparativeRow($sheet, $r, 'Deducciones informativas', (float)($cm['sum']['noi_deducciones'] ?? 0), (float)($m['sum']['noi_deducciones'] ?? 0), 'currency', $ni % 2 === 0); $ni++;
            $this->writeComparativeRow($sheet, $r, 'Neto pagado a trabajadores', (float)($cm['sum']['noi_neto_pagado'] ?? 0), (float)($m['sum']['noi_neto_pagado'] ?? 0), 'currency', $ni % 2 === 0); $ni++;
            $this->writeComparativeTotalsRow($sheet, $r, 'Total Nómina y Capital Humano', (float)$cm['nomTotal'], (float)$m['nomTotal'], 'currency');
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'NÓMINA POR SUCURSAL'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cBranchesByName = collect($cBranches)->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));
            usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
            $chartStart = $r;
            foreach ($branches as $i => $b) {
                $key  = strtoupper(trim($b['sucursal'] ?? ''));
                $cB   = $cBranchesByName->get($key);
                $prev = $cB ? BranchRadiographyCalculator::nominaTotalFor($cB) : 0.0;
                $curr = BranchRadiographyCalculator::nominaTotalFor($b);
                $this->writeComparativeRow($sheet, $r, strtoupper($b['sucursal']), (float)$prev, (float)$curr, 'currency', $i % 2 === 0);
            }
            $chartEnd = $r - 1;
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL GENERAL', (float)$cm['nomTotal'], (float)$m['nomTotal'], 'currency');
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'Nómina por sucursal', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O24');
            }

            $this->setColWidths($sheet, ['A' => 30, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

        $sheet->getColumnDimension('A')->setWidth(42);
        $sheet->getColumnDimension('B')->setWidth(20);
        $sheet->setAutoFilter('A3:B3');
        $sheet->freezePane('A4');

        RadiographyStyleHelper::applyTableHeaderStyle($sheet, 3, [
            'A' => 'CONCEPTO', 'B' => 'MONTO',
        ], accent: true);

        // Codes confirmed against BranchRadiographyCalculator::accumulateNomina()'s
        // own NOI concept grouping — real codes, not invented ones.
        $scalarFields = [
            'Nómina / Sueldos' => ['nomina_total',     'NOI P001 SUELDO'],
            'Comisiones'       => ['comisiones',       'NOI P002 COMISIONES'],
            'Vacaciones'       => ['vacaciones',        'NOI P009 VACACIONES'],
            'Prima vacacional' => ['prima_vacacional', 'NOI P010 PRIMA VACACIONAL'],
            'Bonos'            => ['bonos',             'NOI P1XX BONOS'],
            'Bonos Aceleradores' => ['bonos_aceleradores', 'NOI P118'],
            'Otras percepciones' => ['otros_percepciones', 'NOI (código no catalogado)'],
        ];
        // Gastos reales de empleados — Tipo "Gasto empleado", SÍ afectan el total (ya incluidos
        // en gastos_empleados_nomina). Fuente: Lendus (PDF), salvo IMSS (archivo IMSS oficial).
        $gastoEmpleadoLabels = ['Financiamiento de Motos', 'Financiamiento De Motos', 'Cascos', 'Enganche de Motocicleta', 'Finiquito', 'Gastos médicos'];
        $branchTotal = fn (array $b) => BranchRadiographyCalculator::nominaTotalFor($b);

        usort($branches, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
        $groups = $branches;

        $r = 4;
        $grandTotal = 0.0;
        foreach ($groups as $g) {
            $total = $branchTotal($g);
            $grandTotal += $total;

            RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", strtoupper($g['sucursal']));
            $sheet->setCellValue("B{$r}", $total);
            $sheet->getStyle("A{$r}:B{$r}")->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => RadiographyStyleHelper::FG_WHITE]],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => RadiographyStyleHelper::BG_PRIMARY_DARK]],
            ]);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
            $sheet->getRowDimension($r)->setRowHeight(19);
            $r++;

            $det = [];
            foreach ((array)($g['nomina_detalle'] ?? []) as $k => $v) {
                $det[$k] = ($det[$k] ?? 0.0) + (float) $v;
            }
            foreach ((array)($g['nomina_informativo'] ?? []) as $k => $v) {
                $det[$k] = ($det[$k] ?? 0.0) + (float) $v;
            }
            $i = 0;
            $writeRow = function (string $concepto, float $monto, bool $afecta) use ($sheet, &$r, &$i): void {
                if ($monto == 0.0) return;
                RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", '    ' . $concepto);
                $sheet->setCellValue("B{$r}", $monto);
                $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
                RadiographyStyleHelper::applyCurrencyFormat($sheet, "B{$r}");
                if (!$afecta) {
                    $sheet->getStyle("A{$r}:B{$r}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF64748B'));
                }
                $i++;
                $r++;
            };

            foreach ($scalarFields as $concept => [$field, $fuente]) {
                $writeRow($concept, (float)($g[$field] ?? 0), true);
            }
            $writeRow('IMSS', (float)($g['imss_patronal'] ?? 0), true);
            foreach ($gastoEmpleadoLabels as $key) {
                if (!isset($det[$key])) continue;
                $writeRow($key, (float)$det[$key], true);
            }
            $deducciones = array_filter(
                $det,
                fn ($val, $key) => !in_array($key, $gastoEmpleadoLabels, true) && $key !== 'IMSS' && (float)$val != 0.0,
                ARRAY_FILTER_USE_BOTH
            );
            if (!empty($deducciones)) {
                RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", '    Deducciones (no afectan el total)');
                $sheet->getStyle("A{$r}:B{$r}")->getFont()->setItalic(true)->setBold(true)->setSize(8.5)
                    ->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF94A3B8'));
                $r++;
                foreach ($deducciones as $key => $val) {
                    $writeRow($key, (float)$val, false);
                }
            }
        }

        RadiographyStyleHelper::setCellValueSafe($sheet, "A{$r}", 'TOTAL GENERAL');
        $sheet->setCellValue("B{$r}", $grandTotal);
        $this->totalsRow($sheet, "A{$r}:B{$r}");
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
    private function buildBranchSheets(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $cBrCalcBranches = [];
        if ($isComparative) {
            foreach ($compareSnap['branch_radiography']['branches'] ?? [] as $b) {
                $cBrCalcBranches[strtoupper(trim($b['sucursal']))] = $b;
            }
        }
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

            // SJR is period-aware: closed note only if it has NO movements in this period
            $isSJR       = $brUp === 'SAN JUAN DEL RÍO';
            $sjrIsClosed = $isSJR && ($carteraB + $colB + $recB) == 0.0;

            $calcCmp = $isComparative ? ($cBrCalcBranches[$brUp] ?? null) : null;

            $sheet = $ss->createSheet()->setTitle($tabName($branchName));

            $sheet->getColumnDimension('D')->setWidth(18);

            // Title row — SJR gets closed-branch note only when it has no activity
            $titleText = $isComparative
                ? strtoupper($branchName) . ' — ' . strtoupper($comparePeriod->label) . ' VS ' . strtoupper($period->label)
                : strtoupper($branchName) . ' — ' . strtoupper($period->label);
            if ($sjrIsClosed) {
                $titleText .= ' — SUCURSAL CERRADA / CARTERA EN RECUPERACIÓN';
            }
            RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:D1', $titleText);

            // Meta row: back link to GLOBAL + periodo
            RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');
            RadiographyStyleHelper::mergeCellsSafe($sheet,'B2:D2');
            RadiographyStyleHelper::setCellValueSafe($sheet, 'B2', 'Periodo: ' . ($period->code ?: $period->id));
            RadiographyStyleHelper::applyMetaStyle($sheet, 'B2:D2');

            // Navigation row: direct links to this branch's detail in every relevant sheet
            $branchNavTargets = ['MORA DETALLE', 'NÓMINA POR GESTOR', 'COLOCACIÓN', 'INGRESOS'];
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
            // Comparativo: tabla de 5 columnas en vez de tarjetas 2-arriba (mismo criterio
            // que GLOBAL: no se puede mostrar prev+curr+diff+var% en una tarjeta angosta).
            if ($isComparative) {
                $cCarteraB = $calcCmp ? (float)$calcCmp['valor_cartera'] : 0.0;
                $cRecB     = $calcCmp ? (float)$calcCmp['recuperacion_total'] : 0.0;
                $cColB     = $calcCmp ? (float)$calcCmp['colocacion'] : 0.0;
                $cVencidaB = $calcCmp ? ((float)$calcCmp['mora_0_30'] + (float)$calcCmp['mora_31_60'] + (float)$calcCmp['mora_61_90'] + (float)$calcCmp['mora_91_120'] + (float)$calcCmp['mora_120_plus']) : 0.0;
                $cMoraPct  = $cCarteraB > 0 ? round($cVencidaB / $cCarteraB * 100, 2) : 0.0;
                $this->comparativeHeader($sheet, $navRow, $comparePeriod->label, $period->label);
                $navRow++;
                foreach ([
                    ['Valor cartera', $cCarteraB, $carteraB, 'currency'],
                    ['Mora %',        $cMoraPct,  $moraPct,  'percent'],
                    ['Recuperación',  $cRecB,     $recB,     'currency'],
                    ['Otorgamientos', $cColB,     $colB,     'currency'],
                ] as $i => [$lbl, $prev, $curr, $fmt]) {
                    $this->writeComparativeRow($sheet, $navRow, $lbl, (float)$prev, (float)$curr, $fmt, $i % 2 === 0);
                }
                $navRow++;
            } else {
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
            }

            if (!$isComparative) {
                $this->colHeaders($sheet, $navRow, ['A' => 'MÉTRICA', 'B' => 'VALOR', 'C' => '%']);
            }
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

            if ($isComparative) {
                $labelCmp = $comparePeriod->label;
                $labelCur = $period->label;
                $cCarteraB  = $calcCmp ? (float)$calcCmp['valor_cartera'] : 0.0;
                $cColB      = $calcCmp ? (float)$calcCmp['colocacion'] : 0.0;
                $cRecB      = $calcCmp ? (float)$calcCmp['recuperacion_total'] : 0.0;
                $cGastosB   = $calcCmp ? (float)$calcCmp['gastos_operativos'] : 0.0;
                $cMora0_30  = $calcCmp ? (float)$calcCmp['mora_0_30'] : 0.0;
                $cMora31_60 = $calcCmp ? (float)$calcCmp['mora_31_60'] : 0.0;
                $cMora61_90 = $calcCmp ? (float)$calcCmp['mora_61_90'] : 0.0;
                $cMora91120 = $calcCmp ? (float)$calcCmp['mora_91_120'] : 0.0;
                $cMora120p  = $calcCmp ? (float)$calcCmp['mora_120_plus'] : 0.0;
                $cVencidaB  = $cMora0_30 + $cMora31_60 + $cMora61_90 + $cMora91120 + $cMora120p;
                $cNomCalc   = $calcCmp ? (float)$calcCmp['nomina_total'] : 0.0;
                $cExcedCalc = $calcCmp ? (float)$calcCmp['excedentes'] : 0.0;
                $cFondeoCalc= $calcCmp ? (float)$calcCmp['prestamos_fondea'] : 0.0;

                $this->sectionHeader($sheet, "A{$r}:E{$r}", '1. MÉTRICAS GENERALES'); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
                foreach ([
                    ['Valor cartera',         $cCarteraB,  $carteraB,  'currency'],
                    ['Otorgamientos',         $cColB,      $colB,      'currency'],
                    ['Recuperación total',    $cRecB,      $recB,      'currency'],
                    ['Mora de 0 a 30 días',   $cMora0_30,  $mora0_30,  'currency'],
                    ['Mora de 31 a 60 días',  $cMora31_60, $mora31_60, 'currency'],
                    ['Mora de 61 a 90 días',  $cMora61_90, $mora61_90, 'currency'],
                    ['Mora de 91 a 120 días', $cMora91120, $mora91120, 'currency'],
                    ['Mora 120+ días',        $cMora120p,  $mora120p,  'currency'],
                    ['Mora total',            $cVencidaB,  $vencidaB,  'currency'],
                    ['Gastos operativos',     $cGastosB,   $gastosB,   'currency'],
                    ['Nómina (P001)',         $cNomCalc,   $nomCalc,   'currency'],
                    ['Excedentes corp.',      $cExcedCalc, $excedCalc, 'currency'],
                    ['Préstamos intersuc.',   $cFondeoCalc,$fondeoCalc,'currency'],
                ] as $i => [$lbl, $prev, $curr, $fmt]) {
                    $this->writeComparativeRow($sheet, $r, $lbl, (float)$prev, (float)$curr, $fmt, $i % 2 === 0);
                }
                $r++;

                // 2. Ingresos / Recuperación — desglose por componente
                $this->sectionHeader($sheet, "A{$r}:E{$r}", '2. INGRESOS / RECUPERACIÓN'); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
                $ingrFields = [
                    'Capital'                       => 'capital_recuperado',
                    'Intereses'                     => 'interes_recuperado',
                    'Impuestos'                     => 'impuesto_recuperado',
                    'Multas / Moratorios'           => 'charges',
                    'Cargos adicionales'            => 'cargos_adicionales',
                    'Cargos al inicio'              => 'cargos_inicio',
                    'Comisión por apertura'         => 'comision_apertura',
                    'Excedentes recuperados'        => 'excedente_recuperado',
                    'Seguro CRECE reconocido (30%)' => 'seguro_crece_reconocido',
                ];
                $ii = 0;
                foreach ($ingrFields as $lbl => $field) {
                    $prev = $calcCmp ? (float)($calcCmp[$field] ?? 0) : 0.0;
                    $curr = $calc ? (float)($calc[$field] ?? 0) : 0.0;
                    if ($prev == 0.0 && $curr == 0.0) continue;
                    $this->writeComparativeRow($sheet, $r, $lbl, $prev, $curr, 'currency', $ii % 2 === 0);
                    $ii++;
                }
                $bIngrTotalCmp = $calcCmp ? (float)$calcCmp['recuperacion_total'] : 0.0;
                $bIngrTotalCur = $calc ? (float)$calc['recuperacion_total'] : 0.0;
                $this->writeComparativeTotalsRow($sheet, $r, 'Total Ingresos', $bIngrTotalCmp, $bIngrTotalCur, 'currency');
                $r++;

                // 3. Gastos Operativos — mismos conceptos, comparativo
                $this->sectionHeader($sheet, "A{$r}:E{$r}", '3. GASTOS OPERATIVOS'); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'CONCEPTO'); $r++;
                $gastosOpList = [
                    'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
                    'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
                    'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
                    'Publicidad','Mecánicos','Servicios de Motocicletas','Software Póliza Anual','Pólizas',
                    'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
                    'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
                    'Gastos legales','IMSS','Financiamiento de Motos',
                ];
                $cBranchGDetalle = (array)($calcCmp['gastos_detalle'] ?? []);
                $curBranchGDetalle = (array)($calc['gastos_detalle'] ?? []);
                $gopTotalCur = $calc ? (float)($calc['gastos_operativos'] ?? 0) : 0.0;
                $gopTotalCmp = $calcCmp ? (float)($calcCmp['gastos_operativos'] ?? 0) : 0.0;
                $gi = 0;
                foreach ($gastosOpList as $gastoName) {
                    $prev = (float)($cBranchGDetalle[$gastoName] ?? 0);
                    $curr = (float)($curBranchGDetalle[$gastoName] ?? 0);
                    if ($prev == 0.0 && $curr == 0.0) continue;
                    $this->writeComparativeRow($sheet, $r, $gastoName, $prev, $curr, 'currency', $gi % 2 === 0);
                    $gi++;
                }
                $this->writeComparativeTotalsRow($sheet, $r, 'Total Gastos Operativos', $gopTotalCmp, $gopTotalCur, 'currency');
                $r++;

                // 4. Nómina y Capital Humano
                $this->sectionHeader($sheet, "A{$r}:E{$r}", '4. NÓMINA Y CAPITAL HUMANO'); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'CONCEPTO'); $r++;
                // Bug real 2026-08-26 (mismo fix que buildGlobalSheet()): antes se mezclaban
                // nomina_detalle (deducciones, NO parte del total) con nomina_informativo (SÍ
                // parte del total) en un solo mapa — la suma de filas mostradas no cuadraba
                // contra "Total Nómina y Capital Humano". Ahora usa nominaBreakdownFor(), que
                // suma exacto a nominaTotalFor() por construcción.
                $nomMapCur = $calc    ? BranchRadiographyCalculator::nominaBreakdownFor($calc)    : [];
                $nomMapCmp = $calcCmp ? BranchRadiographyCalculator::nominaBreakdownFor($calcCmp) : [];
                $brNomDisplayList = array_values(array_unique(array_merge(array_keys($nomMapCur), array_keys($nomMapCmp))));
                $ni = 0;
                foreach ($brNomDisplayList as $nomName) {
                    $prev = (float)($nomMapCmp[$nomName] ?? 0);
                    $curr = (float)($nomMapCur[$nomName] ?? 0);
                    if ($prev == 0.0 && $curr == 0.0 && !in_array($nomName, ['Sueldo','Comisiones','Vacaciones','Bonos'], true)) continue;
                    $this->writeComparativeRow($sheet, $r, $nomName, $prev, $curr, 'currency', $ni % 2 === 0);
                    $ni++;
                }
                $brNomTotalCur = $calc ? BranchRadiographyCalculator::nominaTotalFor($calc) : 0.0;
                $brNomTotalCmp = $calcCmp ? BranchRadiographyCalculator::nominaTotalFor($calcCmp) : 0.0;
                $this->writeComparativeTotalsRow($sheet, $r, 'Total Nómina y Capital Humano', $brNomTotalCmp, $brNomTotalCur, 'currency');
                $r++;

                // 5. Préstamos Intersucursales
                $this->sectionHeader($sheet, "A{$r}:E{$r}", '5. PRÉSTAMOS INTERSUCURSALES'); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
                $this->writeComparativeRow($sheet, $r, 'Fondeo otorgado', $cFondeoCalc, $fondeoCalc, 'currency', true);
                $r++;

                // 7. EBITDA (6. Rotación no aplica a nivel sucursal en este libro — ver hoja ROTACIÓN)
                $brGastosTotalCur = $gopTotalCur + $brNomTotalCur;
                $brGastosTotalCmp = $gopTotalCmp + $brNomTotalCmp;
                $brIngresoEbitdaBaseCur = $calc ? BranchRadiographyCalculator::ingresoEbitdaBaseFor($calc) : 0.0;
                $brIngresoEbitdaBaseCmp = $calcCmp ? BranchRadiographyCalculator::ingresoEbitdaBaseFor($calcCmp) : 0.0;
                $brUtilidadCur = $brIngresoEbitdaBaseCur - $brGastosTotalCur;
                $brUtilidadCmp = $brIngresoEbitdaBaseCmp - $brGastosTotalCmp;
                $brMargenCur = $brIngresoEbitdaBaseCur > 0 ? round($brUtilidadCur / $brIngresoEbitdaBaseCur * 100, 2) : 0.0;
                $brMargenCmp = $brIngresoEbitdaBaseCmp > 0 ? round($brUtilidadCmp / $brIngresoEbitdaBaseCmp * 100, 2) : 0.0;

                $this->sectionHeader($sheet, "A{$r}:E{$r}", '7. EBITDA'); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
                foreach ([
                    ['Utilidad bruta',                     $brIngresoEbitdaBaseCmp, $brIngresoEbitdaBaseCur, 'currency'],
                    ['Menos: Gastos Totales',               $brGastosTotalCmp,       $brGastosTotalCur,       'currency'],
                    ['  Gastos operativos (OPEX)',          $gopTotalCmp,            $gopTotalCur,            'currency'],
                    ['  Nómina y Capital Humano',            $brNomTotalCmp,          $brNomTotalCur,          'currency'],
                    ['EBITDA',                               $brUtilidadCmp,          $brUtilidadCur,          'currency'],
                    ['Margen EBITDA (%)',                    $brMargenCmp,            $brMargenCur,            'percent'],
                    ['Excedente enviado a corporativo (inform.)', $cExcedCalc,        $excedCalc,              'currency'],
                    ['Recuperación total / Colocación (informativo)', $cRecB - $cColB, $recB - $colB,          'currency'],
                ] as $i => [$lbl, $prev, $curr, $fmt]) {
                    $this->writeComparativeRow($sheet, $r, $lbl, (float)$prev, (float)$curr, $fmt, $i % 2 === 0);
                }
                $r += 2;

                $this->setColWidths($sheet, ['A' => 32, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
                goto branchSectionsDone;
            }

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
            $bCargosAdic = $calc ? (float)$calc['cargos_adicionales']    : 0.0;
            $bExcedRec   = $calc ? (float)$calc['excedente_recuperado']  : 0.0;
            $bCrece30    = $calc ? (float)$calc['seguro_crece_reconocido'] : 0.0;
            $bOtrosResid = $calc ? (float)$calc['otros_recuperacion']    : 0.0;
            $bIngrTotal  = $calc ? (float)$calc['recuperacion_total']    : 0.0;
            // TODOS los componentes reales, para que la suma de filas visibles cuadre
            // exactamente con "Total Ingresos" — nunca un total que venga de otro lado.
            $bIngrItems = [
                'Capital'                      => $bCapital,
                'Intereses'                    => $bIntereses,
                'Impuestos'                    => $bImpuestos,
                'Multas / Moratorios'          => $bMulMor,
                'Cargos adicionales'           => $bCargosAdic,
                'Cargos al inicio'             => $bCargosIni,
                'Comisión por apertura'        => $bComAp,
                'Excedentes recuperados'       => $bExcedRec,
                'Seguro CRECE reconocido (30%)'=> $bCrece30,
            ];
            $bOtrosDetSum = 0.0;
            foreach ((array)($calc['otros_detalle'] ?? []) as $otrosLabel => $otrosVal) {
                if ((float) $otrosVal != 0.0) {
                    $bIngrItems[(string) $otrosLabel] = (float) $otrosVal;
                    $bOtrosDetSum += (float) $otrosVal;
                }
            }
            // Red de seguridad: si el residual no clasificado (otros_recuperacion) no
            // coincide con la suma de sus conceptos reales (otros_detalle), el remanente
            // se muestra explícitamente — nunca se pierde en silencio dentro del total.
            $bOtrosGap = round($bOtrosResid - $bOtrosDetSum, 2);
            if ($bOtrosGap != 0.0) {
                $bIngrItems['Otros (no clasificado)'] = $bOtrosGap;
            }
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
            // Total OPEX SIEMPRE viene de la fuente canónica gastos_operativos (regla final
            // 2026-07) — la lista curada de abajo es solo para el desglose por concepto; si
            // no cubre algún concepto real (ej. Gasolina), el remanente se muestra como
            // "Otros conceptos operativos" para que el desglose siempre sume exacto al total.
            $gopTotal = $calc ? (float)($calc['gastos_operativos'] ?? 0) : 0.0;
            $gopCurada = 0.0;
            $gopIdx = 0;
            foreach ($gastosOpList as $gastoName) {
                $val = $getBGDet($gastoName);
                $gopCurada += $val;
                if ($val == 0.0) continue; // skip zero rows
                $sheet->setCellValue("A{$r}", $gastoName);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:C{$r}", $gopIdx % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $val);
                $gopIdx++;
                $r++;
            }
            $gopOtros = round($gopTotal - $gopCurada, 2);
            if (abs($gopOtros) >= 0.01) {
                $sheet->setCellValue("A{$r}", 'Otros conceptos operativos');
                $sheet->setCellValue("B{$r}", $gopOtros);
                $this->dataRow($sheet, "A{$r}:C{$r}", $gopIdx % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $gopOtros);
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'Total Gastos Operativos');
            $sheet->setCellValue("B{$r}", $gopTotal);
            $this->totalsRow($sheet, "A{$r}:C{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r += 2;

            // 4. Nómina y Capital Humano — expanded (same structure as GLOBAL)
            // Bug real 2026-08-26 (mismo fix que buildGlobalSheet()): antes se mezclaban
            // nomina_detalle (deducciones, NO parte del total) con nomina_informativo (SÍ
            // parte del total) en una sola tabla — la suma de filas no cuadraba contra
            // "Total Nómina y Capital Humano". nominaBreakdownFor() suma exacto por
            // construcción; las deducciones se muestran aparte, después del Total.
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '4. NÓMINA Y CAPITAL HUMANO');
            $r++;
            $brNomDisplay  = $calc ? BranchRadiographyCalculator::nominaBreakdownFor($calc) : [];
            $brMandatory24 = ['Sueldo', 'Comisiones', 'Vacaciones', 'Bonos'];
            $brDeducciones = (array) ($calc['nomina_detalle'] ?? []);

            $brNomTotal = $calc ? BranchRadiographyCalculator::nominaTotalFor($calc) : 0.0;
            $i2         = 0;
            foreach ($brNomDisplay as $nomName => $nomVal) {
                if ($nomVal == 0.0 && !in_array($nomName, $brMandatory24, true)) continue;
                $sheet->setCellValue("A{$r}", $nomName);
                $sheet->setCellValue("B{$r}", $nomVal);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i2 % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $nomVal);
                $i2++;
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'Total Nómina y Capital Humano');
            $sheet->setCellValue("B{$r}", $brNomTotal);
            $this->totalsRow($sheet, "A{$r}:C{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r += 2;

            // Deducciones NOI — informativas, NUNCA se restan del Total de arriba.
            if (!empty($brDeducciones)) {
                $this->sectionHeader($sheet, "A{$r}:C{$r}", 'DEDUCCIONES NOI (informativo — no se restan del total)');
                $r++;
                $di = 0;
                $brDedTotal = 0.0;
                foreach ($brDeducciones as $dedName => $dedVal) {
                    $dedVal = (float) $dedVal;
                    if ($dedVal == 0.0) continue;
                    $sheet->setCellValue("A{$r}", $dedName);
                    $sheet->setCellValue("B{$r}", $dedVal);
                    $this->dataRow($sheet, "A{$r}:C{$r}", $di % 2 === 0);
                    $this->applyFmt($sheet, "B{$r}", 'currency', $dedVal);
                    $brDedTotal += $dedVal;
                    $di++;
                    $r++;
                }
                $sheet->setCellValue("A{$r}", 'Total deducciones (informativo)');
                $sheet->setCellValue("B{$r}", $brDedTotal);
                $this->totalsRow($sheet, "A{$r}:C{$r}");
                $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $r++;
            }
            $r++;

            // 5. Préstamos Intersucursales
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '5. PRÉSTAMOS INTERSUCURSALES');
            $r++;
            foreach ([
                ['Fondeo otorgado', $fondeoCalc, 'currency'],
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

            $brGastosTotal     = $gopTotal + $brNomTotal;
            $brIngresoEbitdaBase = $calc ? BranchRadiographyCalculator::ingresoEbitdaBaseFor($calc) : 0.0;
            $brUtilidad        = $brIngresoEbitdaBase - $brGastosTotal;
            $brMargenEbitda    = $brIngresoEbitdaBase > 0 ? round($brUtilidad / $brIngresoEbitdaBase * 100, 2) : 0.0;
            $brExcedCalc      = $calc ? (float)$calc['excedentes']       : $excedCalc;

            // 7. EBITDA = Ingreso base EBITDA − Gastos Totales (criterio final 2026-07;
            // NUNCA Recuperación − Colocación, ver BranchRadiographyCalculator::ebitdaFinalFor()).
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '7. EBITDA');
            $r++;
            foreach ([
                ['Utilidad bruta',                             $brIngresoEbitdaBase, 'currency'],
                ['Menos: Gastos Totales',                     $brGastosTotal, 'currency'],
                ['  Gastos operativos (OPEX)',                $gopTotal,      'currency'],
                ['  Nómina y Capital Humano',                  $brNomTotal,    'currency'],
                ['EBITDA',                                    $brUtilidad,    'currency'],
                ['Margen EBITDA (%)',                         $brMargenEbitda, 'percent'],
                ['Excedente enviado a corporativo (inform.)', $brExcedCalc,   'currency'],
                ['Recuperación total / Colocación (informativo)', $recB - $colB, 'currency'],
            ] as $i => [$label, $val, $fmt]) {
                $sheet->setCellValue("A{$r}", $label);
                $sheet->setCellValue("B{$r}", $val);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", $fmt, $val);
                $r++;
            }
            if (false) {
                $this->writeInconsistenciaRow($sheet, $r, 'C');
                $r++;
            }
            $r += 2;

            branchSectionsDone:
            // 8. Observaciones y Notas
            $this->sectionHeader($sheet, "A{$r}:C{$r}", '8. OBSERVACIONES Y NOTAS');
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

    private function buildProductosSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet    = $ss->createSheet()->setTitle('PRODUCTOS');
        $products = $snap['sections']['products'] ?? [];

        $this->sheetTitle($sheet, 'A1:F1', $isComparative
            ? 'PRODUCTOS POR TIPO — ' . strtoupper($comparePeriod->label) . ' VS ' . strtoupper($period->label)
            : 'PRODUCTOS POR TIPO — ' . strtoupper($period->label));

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cProducts = $compareSnap['sections']['products'] ?? [];
            $cByName = collect($cProducts)->keyBy('producto');
            $curByName = collect($products)->keyBy('producto');
            $allProducts = collect(array_keys($cByName->all()))->merge(array_keys($curByName->all()))->unique()->values();

            $r = 3;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'COLOCACIÓN POR PRODUCTO'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'PRODUCTO'); $r++;
            $chartStart = $r;
            $pi = 0;
            foreach ($allProducts as $prod) {
                $prev = (float)($cByName->get($prod)['colocacion'] ?? 0);
                $curr = (float)($curByName->get($prod)['colocacion'] ?? 0);
                if ($prev == 0.0 && $curr == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $prod, $prev, $curr, 'currency', $pi % 2 === 0);
                $pi++;
            }
            $chartEnd = $r - 1;
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'Colocación por producto', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G3', 'O20');
            }
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'CARTERA POR PRODUCTO'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'PRODUCTO'); $r++;
            $ci = 0;
            foreach ($allProducts as $prod) {
                $prev = (float)($cByName->get($prod)['cartera'] ?? 0);
                $curr = (float)($curByName->get($prod)['cartera'] ?? 0);
                if ($prev == 0.0 && $curr == 0.0) continue;
                $this->writeComparativeRow($sheet, $r, $prod, $prev, $curr, 'currency', $ci % 2 === 0);
                $ci++;
            }

            $this->setColWidths($sheet, ['A' => 28, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A4');
            return;
        }

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

    private function buildEmpleadosSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet = $ss->createSheet()->setTitle('EMPLEADOS');
        $rows  = $snap['sections']['employees_gestores'] ?? [];

        $this->sheetTitle($sheet, 'A1:M1', $isComparative
            ? 'EMPLEADOS / GESTORES — ' . strtoupper($comparePeriod->label) . ' VS ' . strtoupper($period->label)
            : 'EMPLEADOS / GESTORES — ' . strtoupper($period->label));
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'O1', '← GLOBAL', 'GLOBAL');

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $canonicalizer = app(\App\Services\EmployeeNameCanonicalizer::class);
            $cRows = $compareSnap['sections']['employees_gestores'] ?? [];
            $cByName = collect($cRows)->keyBy(fn ($e) => $canonicalizer->normalize($e['name'] ?? ''));

            $metrics = [
                'RECUPERACIÓN POR GESTOR' => 'recuperacion',
                'COLOCACIÓN POR GESTOR'   => 'colocacion',
                'NETO PAGADO POR GESTOR'  => 'neto',
            ];
            $r = 3;
            foreach ($metrics as $title => $field) {
                $this->sectionHeader($sheet, "A{$r}:E{$r}", $title); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'GESTOR'); $r++;
                $chartStart = $r;
                $mi = 0;
                foreach ($rows as $emp) {
                    $key  = $canonicalizer->normalize($emp['name'] ?? '');
                    $prev = (float)($cByName->get($key)[$field] ?? 0);
                    $curr = (float)($emp[$field] ?? 0);
                    if ($prev == 0.0 && $curr == 0.0) continue;
                    $this->writeComparativeRow($sheet, $r, $emp['name'], $prev, $curr, 'currency', $mi % 2 === 0);
                    $mi++;
                }
                $chartEnd = $r - 1;
                $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL', (float)array_sum(array_column($cRows, $field)), (float)array_sum(array_column($rows, $field)), 'currency');
                $r++;
                if ($field === 'colocacion' && $chartEnd >= $chartStart) {
                    $this->addComparativeChart($sheet, 'Top gestores por colocación', $chartStart, min($chartEnd, $chartStart + 9), $labelCmp, $labelCur, 'G3', 'O24');
                }
            }

            $this->setColWidths($sheet, ['A' => 30, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A4');
            return;
        }

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
            $sheet->setCellValue('A2', 'Sin movimientos de nómina para este periodo.');
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
            'gestores_sin_match_noi'     => 'Gestores sin coincidencia de nómina',
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

    private function buildInterbranchLoansSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet = $ss->createSheet()->setTitle('P. INTERSUC.');
        $loans = $snap['sections']['interbranch_loans'] ?? [];

        [$mesLabel, $anioLabel] = $this->periodMonthYear($period);
        $this->sheetTitle($sheet, 'A1:D1', $isComparative
            ? 'PRÉSTAMOS INTERSUCURSALES — ' . strtoupper($comparePeriod->label) . ' VS ' . strtoupper($period->label)
            : 'PRÉSTAMOS INTERSUCURSALES — ' . strtoupper($mesLabel) . ' ' . $anioLabel);
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'F1', '← GLOBAL', 'GLOBAL');

        $opFondeos   = $loans['operative_fondeos'] ?? [];
        $excedentes  = $loans['excedentes'] ?? [];
        $detail      = $loans['detail']     ?? [];
        $total       = (float)($opFondeos['fondea_total'] ?? 0) + (float)($excedentes['total'] ?? 0);

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cLoans      = $compareSnap['sections']['interbranch_loans'] ?? [];
            $cOpFondeos  = $cLoans['operative_fondeos'] ?? [];
            $cExcedentes = $cLoans['excedentes'] ?? [];
            $cFondTotal  = (float)($cOpFondeos['fondea_total'] ?? 0);
            $fondTotal   = (float)($opFondeos['fondea_total'] ?? 0);
            $cExcTotal   = (float)($cExcedentes['total'] ?? 0);
            $excTotal    = (float)($excedentes['total'] ?? 0);

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'FONDEOS ENTRE SUCURSALES OPERATIVAS — SUCURSAL QUE FONDEA'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cFondeaByBranch = collect($cOpFondeos['fondea'] ?? [])->keyBy(fn ($row) => $this->dashIfUnresolved($row['branch'] ?? null));
            $fi = 0;
            foreach (($opFondeos['fondea'] ?? []) as $row) {
                $branch = $this->dashIfUnresolved($row['branch'] ?? null);
                $prev = (float)($cFondeaByBranch->get($branch)['total'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $branch, $prev, (float)$row['total'], 'currency', $fi % 2 === 0);
                $fi++;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL FONDEA', $cFondTotal, $fondTotal, 'currency');
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'FONDEOS ENTRE SUCURSALES OPERATIVAS — SUCURSAL QUE RECIBE'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cRecibeByBranch = collect($cOpFondeos['recibe'] ?? [])->keyBy(fn ($row) => $this->dashIfUnresolved($row['branch'] ?? null));
            $recTotalCur = 0.0; $recTotalCmp = 0.0;
            $ri = 0;
            foreach (($opFondeos['recibe'] ?? []) as $row) {
                $branch = $this->dashIfUnresolved($row['branch'] ?? null);
                $prev = (float)($cRecibeByBranch->get($branch)['total'] ?? 0);
                $curr = (float)$row['total'];
                $recTotalCur += $curr;
                $this->writeComparativeRow($sheet, $r, $branch, $prev, $curr, 'currency', $ri % 2 === 0);
                $ri++;
            }
            foreach (($cOpFondeos['recibe'] ?? []) as $row) { $recTotalCmp += (float)$row['total']; }
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL RECIBE', $recTotalCmp, $recTotalCur, 'currency');
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'EXCEDENTES / ENVÍO A CORPORATIVO — POR SUCURSAL ORIGEN'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $cExcByBranch = collect($cExcedentes['by_branch'] ?? [])->keyBy('branch');
            $chartStart = $r;
            foreach (($excedentes['by_branch'] ?? []) as $row) {
                $prev = (float)($cExcByBranch->get($row['branch'])['total'] ?? 0);
                $this->writeComparativeRow($sheet, $r, $row['branch'], $prev, (float)$row['total'], 'currency', ($r - $chartStart) % 2 === 0);
            }
            $chartEnd = $r - 1;
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL EXCEDENTES', $cExcTotal, $excTotal, 'currency');
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'Excedentes por sucursal', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O24');
            }
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'RESUMEN TOTAL'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $this->writeComparativeRow($sheet, $r, 'Fondeos entre sucursales operativas', $cFondTotal, $fondTotal, 'currency', true);
            $this->writeComparativeRow($sheet, $r, 'Excedentes a CORPORATIVO', $cExcTotal, $excTotal, 'currency', false);
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL GENERAL', $cFondTotal + $cExcTotal, $fondTotal + $excTotal, 'currency');

            $this->setColWidths($sheet, ['A' => 30, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

        $r = 3;

        if ($total === 0.0 && empty($detail)) {
            $sheet->setCellValue("A{$r}", 'Sin préstamos intersucursales registrados para este periodo.');
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
            $this->setColWidths($sheet, ['A' => 36, 'B' => 22, 'C' => 22, 'D' => 18]);
            return;
        }

        // ══ SECCIÓN A: FONDEOS ENTRE SUCURSALES OPERATIVAS ══════════════════
        // Regla: fondea_total = recibe_total → neto = $0
        $fondeaOper  = $opFondeos['fondea'] ?? [];
        $recibeOper  = $opFondeos['recibe'] ?? [];
        $detailFond  = $opFondeos['detail'] ?? [];
        $fondTotal   = (float)($opFondeos['fondea_total'] ?? 0);

        $this->sectionHeader($sheet, "A{$r}:E{$r}", 'SECCIÓN A — FONDEOS ENTRE SUCURSALES OPERATIVAS (fondea = recibe, neto = $0)');
        $r++;

        // A1: Fondea
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL QUE FONDEA', 'B' => 'MONTO FONDEA']);
        $r++;
        foreach ($fondeaOper as $i => $row) {
            $sheet->setCellValue("A{$r}", $this->dashIfUnresolved($row['branch'] ?? null));
            $sheet->setCellValue("B{$r}", (float)$row['total']);
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL FONDEA');
        $sheet->setCellValue("B{$r}", $fondTotal);
        $this->totalsRow($sheet, "A{$r}:B{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // A2: Recibe
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL QUE RECIBE', 'B' => 'MONTO RECIBE']);
        $r++;
        $recTotal = 0.0;
        foreach ($recibeOper as $i => $row) {
            $sheet->setCellValue("A{$r}", $this->dashIfUnresolved($row['branch'] ?? null));
            $sheet->setCellValue("B{$r}", (float)$row['total']);
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $recTotal += (float)$row['total'];
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL RECIBE');
        $sheet->setCellValue("B{$r}", $recTotal);
        $this->totalsRow($sheet, "A{$r}:B{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // A4: Detalle de fondeos operativos
        $this->sectionHeader($sheet, "A{$r}:D{$r}", 'DETALLE — FONDEOS ENTRE SUCURSALES OPERATIVAS');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'FECHA', 'B' => 'FONDEA', 'C' => 'RECIBE', 'D' => 'MONTO',
        ]);
        $r++;
        foreach ($detailFond as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['date']);
            $sheet->setCellValue("B{$r}", $this->dashIfUnresolved($row['from_branch'] ?? null));
            $sheet->setCellValue("C{$r}", $this->dashIfUnresolved($row['to_branch'] ?? null));
            $sheet->setCellValue("D{$r}", (float)$row['amount']);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL FONDEOS');
        $sheet->setCellValue("D{$r}", $fondTotal);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 3;

        // ══ SECCIÓN B: EXCEDENTES / ENVÍO A CORPORATIVO ══════════════════════
        $excByBranch = $excedentes['by_branch'] ?? [];
        $excDetail   = $excedentes['detail']    ?? [];
        $excTotal    = (float)($excedentes['total'] ?? 0);

        $this->sectionHeader($sheet, "A{$r}:D{$r}", 'SECCIÓN B — EXCEDENTES / ENVÍO A CORPORATIVO (NO son fondeos entre sucursales)');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL ORIGEN', 'B' => 'MONTO EXCEDENTE']);
        $r++;
        foreach ($excByBranch as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['branch']);
            $sheet->setCellValue("B{$r}", (float)$row['total']);
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("B{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL EXCEDENTES');
        $sheet->setCellValue("B{$r}", $excTotal);
        $this->totalsRow($sheet, "A{$r}:B{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // B2: Detalle excedentes
        $this->sectionHeader($sheet, "A{$r}:D{$r}", 'DETALLE — EXCEDENTES A CORPORATIVO');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'FECHA', 'B' => 'SUCURSAL ORIGEN', 'C' => 'DESTINO', 'D' => 'MONTO',
        ]);
        $r++;
        foreach ($excDetail as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['date']);
            $sheet->setCellValue("B{$r}", $this->dashIfUnresolved($row['from_branch'] ?? null));
            $sheet->setCellValue("C{$r}", $this->dashIfUnresolved($row['to_branch'] ?? null));
            $sheet->setCellValue("D{$r}", (float)$row['amount']);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("D{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'TOTAL EXCEDENTES');
        $sheet->setCellValue("D{$r}", $excTotal);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("D{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // ── Resumen final ────────────────────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:D{$r}", 'RESUMEN TOTAL');
        $r++;
        foreach ([
            ['Fondeos entre sucursales operativas', $fondTotal],
            ['Excedentes a CORPORATIVO',            $excTotal],
            ['TOTAL GENERAL',                       $total],
        ] as $i => [$label, $val]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $i === 2
                ? $this->totalsRow($sheet, "A{$r}:D{$r}")
                : $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $r++;
        }

        $this->setColWidths($sheet, ['A' => 36, 'B' => 22, 'C' => 22, 'D' => 18]);
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

    private function buildPlacementSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet   = $ss->createSheet()->setTitle('COLOCACIÓN');
        $rows    = $snap['sections']['placement_by_branch_product'] ?? [];
        // Authoritative KPI total — from BranchRadiographyCalculator (same as dashboard/PDF)
        $kpiTotal = (float)($snap['branch_radiography']['global']['colocacion'] ?? 0);

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cRows = $compareSnap['sections']['placement_by_branch_product'] ?? [];
            $cKpiTotal = (float)($compareSnap['branch_radiography']['global']['colocacion'] ?? 0);

            RadiographyStyleHelper::applyTitleStyle($sheet, 'A1:E1', 'COLOCACIÓN — ' . strtoupper($labelCmp) . ' VS ' . strtoupper($labelCur));
            RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');

            $byProductCur = [];
            foreach ($rows as $row) { $byProductCur[$row['product']] = ($byProductCur[$row['product']] ?? 0) + (float)$row['monto']; }
            $byProductCmp = [];
            foreach ($cRows as $row) { $byProductCmp[$row['product']] = ($byProductCmp[$row['product']] ?? 0) + (float)$row['monto']; }
            $byBranchCur = [];
            foreach ($rows as $row) { $byBranchCur[$row['branch']] = ($byBranchCur[$row['branch']] ?? 0) + (float)$row['monto']; }
            $byBranchCmp = [];
            foreach ($cRows as $row) { $byBranchCmp[$row['branch']] = ($byBranchCmp[$row['branch']] ?? 0) + (float)$row['monto']; }

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'COLOCACIÓN POR PRODUCTO'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'PRODUCTO'); $r++;
            $allProducts = collect(array_keys($byProductCmp))->merge(array_keys($byProductCur))->unique()->values();
            $pi = 0;
            foreach ($allProducts as $prod) {
                $this->writeComparativeRow($sheet, $r, $prod, (float)($byProductCmp[$prod] ?? 0), (float)($byProductCur[$prod] ?? 0), 'currency', $pi % 2 === 0);
                $pi++;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL GENERAL', $cKpiTotal, $kpiTotal, 'currency');
            $r++;

            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'COLOCACIÓN POR SUCURSAL'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
            $allBranches = collect(array_keys($byBranchCmp))->merge(array_keys($byBranchCur))->unique()->sort()->values();
            $chartStart = $r;
            foreach ($allBranches as $branch) {
                $this->writeComparativeRow($sheet, $r, strtoupper($branch), (float)($byBranchCmp[$branch] ?? 0), (float)($byBranchCur[$branch] ?? 0), 'currency', ($r - $chartStart) % 2 === 0);
            }
            $chartEnd = $r - 1;
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL GENERAL', $cKpiTotal, $kpiTotal, 'currency');
            if ($chartEnd >= $chartStart) {
                $this->addComparativeChart($sheet, 'Colocación por sucursal', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O24');
            }

            $this->setColWidths($sheet, ['A' => 28, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

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
    private function buildCategoriaEbitdaSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet    = $ss->createSheet()->setTitle('CATEGORÍA EBITDA');
        $branches = $snap['branch_radiography']['branches'] ?? [];
        $label    = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:B1', $isComparative
            ? 'CATEGORÍA POR EBITDA — ' . strtoupper($comparePeriod->label) . ' VS ' . $label
            : 'CATEGORÍA POR EBITDA — ' . $label);
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cBranches = $compareSnap['branch_radiography']['branches'] ?? [];
            $cByName = collect($cBranches)->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));

            $this->colHeaders($sheet, 4, ['A' => 'SUCURSAL', 'B' => 'CATEGORÍA ' . strtoupper($labelCmp), 'C' => 'CATEGORÍA ' . strtoupper($labelCur)]);
            $r = 5;
            $sorted = $branches;
            usort($sorted, fn ($a, $b) => strcmp($a['sucursal'], $b['sucursal']));
            foreach ($sorted as $i => $b) {
                $curCat = RadiographyStyleHelper::ebitdaCategory(RadiographyStyleHelper::branchEbitdaEstimate($b));
                $cB = $cByName->get(strtoupper(trim($b['sucursal'] ?? '')));
                $cmpCat = $cB ? RadiographyStyleHelper::ebitdaCategory(RadiographyStyleHelper::branchEbitdaEstimate($cB)) : '—';
                $sheet->setCellValue("A{$r}", $b['sucursal']);
                $sheet->setCellValue("B{$r}", $cmpCat);
                $sheet->setCellValue("C{$r}", $curCat);
                $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
                foreach (['B' => $cmpCat, 'C' => $curCat] as $col => $cat) {
                    if ($cat === '—') continue;
                    $colors = RadiographyStyleHelper::categoryColors($cat);
                    $sheet->getStyle("{$col}{$r}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9.5, 'color' => ['argb' => $colors['fg']]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colors['bg']]],
                        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    ]);
                }
                $sheet->getRowDimension($r)->setRowHeight(18);
                $r++;
            }
            $this->setColWidths($sheet, ['A' => 26, 'B' => 20, 'C' => 20]);
            $sheet->freezePane('A5');
            return;
        }

        if (empty($branches)) {
            $sheet->setCellValue('A3', 'Sin datos por sucursal para calcular categorías.');
            $this->setColWidths($sheet, ['A' => 28, 'B' => 18]);
            return;
        }

        // Ordenado por nivel de categoría (mejor a peor), no por monto — el monto no se muestra.
        $categoryRank = ['DIAMANTE' => 0, 'SENIOR' => 1, 'JUNIOR' => 2, 'MANTENIDO' => 3];
        $rows = array_map(function ($b) {
            $utilidad  = RadiographyStyleHelper::branchEbitdaEstimate($b);
            $categoria = RadiographyStyleHelper::ebitdaCategory($utilidad);
            return ['sucursal' => $b['sucursal'], 'categoria' => $categoria];
        }, $branches);
        usort($rows, fn ($a, $b) => ($categoryRank[$a['categoria']] ?? 99) <=> ($categoryRank[$b['categoria']] ?? 99)
            ?: strcmp($a['sucursal'], $b['sucursal']));

        $this->colHeaders($sheet, 4, ['A' => 'SUCURSAL', 'B' => 'CATEGORÍA']);
        $r = 5;
        foreach ($rows as $i => $row) {
            $colors = RadiographyStyleHelper::categoryColors($row['categoria']);
            $sheet->setCellValue("A{$r}", $row['sucursal']);
            $sheet->setCellValue("B{$r}", $row['categoria']);
            $this->dataRow($sheet, "A{$r}:B{$r}", $i % 2 === 0);
            $sheet->getStyle("B{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9.5, 'color' => ['argb' => $colors['fg']]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $colors['bg']]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            ]);
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;
        }

        $this->setColWidths($sheet, ['A' => 26, 'B' => 18]);
        $sheet->setAutoFilter('A4:B4');
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
        $sheet->setCellValue("A{$row}", '⚠ AVISO: El excedente enviado a corporativo supera el EBITDA disponible. Revisar ingresos, gastos u otorgamientos.');
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
     * Total Nómina y Capital Humano — fuente única, ver BranchRadiographyCalculator::nominaTotalFor().
     */
    private function calcNomTotal(array $calc): float
    {
        return BranchRadiographyCalculator::nominaTotalFor($calc);
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

    /**
     * Displays an unresolved-branch placeholder as a simple dash instead of internal
     * jargon like "No identificada"/"Sin sucursal"/"No detectado" — those strings stay
     * as-is upstream (other code compares against them), this only affects what the
     * end user sees in the cell.
     */
    private function dashIfUnresolved(?string $value): string
    {
        return in_array($value, ['No identificada', 'Sin sucursal', 'No detectado', null, ''], true)
            ? '—'
            : $value;
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

    /**
     * Encabezado estándar de una tabla comparativa de 5 columnas (A:E):
     * MÉTRICA | {labelCmp} | {labelCur} | DIFERENCIA | VARIACIÓN %.
     * Usado por TODAS las hojas cuando se genera en modo comparativo — mismo
     * criterio en cada pestaña, nunca una tabla ejecutiva distinta.
     */
    private function comparativeHeader(Worksheet $sheet, int $row, string $labelCmp, string $labelCur, string $metricLabel = 'MÉTRICA'): void
    {
        $this->colHeaders($sheet, $row, [
            'A' => $metricLabel,
            'B' => strtoupper($labelCmp),
            'C' => strtoupper($labelCur),
            'D' => 'DIFERENCIA',
            'E' => 'VARIACIÓN %',
        ]);
    }

    /**
     * Escribe una fila comparativa (label | prev | curr | diferencia | variación %)
     * en las columnas A:E y avanza $r. Misma lógica que ya se usaba en el
     * comparativo ejecutivo — ahora reutilizada por cada hoja del libro cuando el
     * reporte es comparativo, para que TODAS las pestañas comparen igual.
     */
    private function writeComparativeRow(Worksheet $sheet, int &$r, string $label, float $prev, float $curr, string $fmt, bool $alt): void
    {
        $diff   = $curr - $prev;
        $varPct = $prev != 0 ? round($diff / abs($prev) * 100, 2) : ($curr != 0 ? 100.0 : 0.0);

        $sheet->setCellValue("A{$r}", $label);
        $sheet->setCellValue("B{$r}", $prev);
        $sheet->setCellValue("C{$r}", $curr);
        $sheet->setCellValue("D{$r}", $diff);
        $sheet->setCellValue("E{$r}", $fmt === 'percent' ? $diff : $varPct);

        $this->dataRow($sheet, "A{$r}:E{$r}", $alt);
        $fmtCode = $fmt === 'currency' ? self::CURRENCY : ($fmt === 'percent' ? self::PERCENT : self::INTEGER);
        foreach (['B', 'C', 'D'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode($fmtCode);
        }
        $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode($fmt === 'percent' ? '+0.00" pp";-0.00" pp";0.00" pp"' : '+0.00"%";-0.00"%";0.00"%"');
        $varForColor = $fmt === 'percent' ? $diff : $varPct;
        if ($varForColor > 0) {
            $sheet->getStyle("E{$r}")->getFont()->getColor()->setARGB('FF15803D');
        } elseif ($varForColor < 0) {
            $sheet->getStyle("E{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
        }
        $r++;
    }

    /**
     * Fila de totales comparativa (mismo estilo que totalsRow, pero con las 4
     * columnas prev/curr/diff/var%).
     */
    private function writeComparativeTotalsRow(Worksheet $sheet, int &$r, string $label, float $prev, float $curr, string $fmt): void
    {
        $diff   = $curr - $prev;
        $varPct = $prev != 0 ? round($diff / abs($prev) * 100, 2) : ($curr != 0 ? 100.0 : 0.0);

        $sheet->setCellValue("A{$r}", $label);
        $sheet->setCellValue("B{$r}", $prev);
        $sheet->setCellValue("C{$r}", $curr);
        $sheet->setCellValue("D{$r}", $diff);
        $sheet->setCellValue("E{$r}", $fmt === 'percent' ? $diff : $varPct);

        $this->totalsRow($sheet, "A{$r}:E{$r}");
        $fmtCode = $fmt === 'currency' ? self::CURRENCY : ($fmt === 'percent' ? self::PERCENT : self::INTEGER);
        foreach (['B', 'C', 'D'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode($fmtCode);
        }
        $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode($fmt === 'percent' ? '+0.00" pp";-0.00" pp";0.00" pp"' : '+0.00"%";-0.00"%";0.00"%"');
        $r++;
    }

    /**
     * Gráfica comparativa de 2 series (mes comparado vs mes actual) — misma
     * apariencia (bar chart clustered) en todas las pestañas comparativas.
     */
    private function addComparativeChart(Worksheet $sheet, string $title, int $dataStartRow, int $dataEndRow, string $labelCmp, string $labelCur, string $topLeft, string $bottomRight, string $axisFmt = '"$"#,##0'): void
    {
        RadiographyStyleHelper::addComparativeBarChart(
            $sheet,
            $title,
            "\$A\${$dataStartRow}:\$A\${$dataEndRow}",
            "\$B\${$dataStartRow}:\$B\${$dataEndRow}",
            "\$C\${$dataStartRow}:\$C\${$dataEndRow}",
            $labelCmp,
            $labelCur,
            $dataEndRow - $dataStartRow + 1,
            $topLeft,
            $bottomRight,
            $axisFmt
        );
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

    private function buildRotacionSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet    = $ss->createSheet()->setTitle('ROTACIÓN');
        $rotation = $snap['sections']['rotation'] ?? [];
        $label    = strtoupper($period->label);

        $this->sheetTitle($sheet, 'A1:F1', $isComparative
            ? 'ÍNDICE DE ROTACIÓN DE PERSONAL — ' . strtoupper($comparePeriod->label) . ' VS ' . $label
            : 'ÍNDICE DE ROTACIÓN DE PERSONAL — ' . $label);
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cRotation = $compareSnap['sections']['rotation'] ?? [];
            $curCount  = (float)($rotation['current_count'] ?? $rotation['promedio'] ?? 0);
            $cmpCount  = (float)($cRotation['current_count'] ?? $cRotation['promedio'] ?? 0);

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'RESUMEN GLOBAL'); $r++;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur); $r++;
            $this->writeComparativeRow($sheet, $r, 'Plantilla', $cmpCount, $curCount, 'integer', true);
            $this->writeComparativeRow($sheet, $r, 'N° de altas en el periodo', (float)($cRotation['altas'] ?? 0), (float)($rotation['altas'] ?? 0), 'integer', false);
            $this->writeComparativeRow($sheet, $r, 'N° de bajas en el periodo', (float)($cRotation['bajas'] ?? 0), (float)($rotation['bajas'] ?? 0), 'integer', true);
            $this->writeComparativeRow($sheet, $r, 'Índice de rotación (%)', (float)($cRotation['indice'] ?? 0), (float)($rotation['indice'] ?? 0), 'percent', false);
            $r++;

            $porSucursalCur = $rotation['por_sucursal'] ?? [];
            $porSucursalCmp = collect($cRotation['por_sucursal'] ?? [])->keyBy(fn ($x) => strtoupper(trim($x['sucursal'] ?? '')));
            if (!empty($porSucursalCur)) {
                $this->sectionHeader($sheet, "A{$r}:E{$r}", 'DETALLE POR SUCURSAL — PLANTILLA'); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
                $chartStart = $r;
                foreach ($porSucursalCur as $i => $row) {
                    $key  = strtoupper(trim($row['sucursal'] ?? ''));
                    $prev = (float)($porSucursalCmp->get($key)['promedio_personal'] ?? 0);
                    $curr = (float)($row['promedio_personal'] ?? 0);
                    $this->writeComparativeRow($sheet, $r, $row['sucursal'], $prev, $curr, 'integer', $i % 2 === 0);
                }
                $chartEnd = $r - 1;
                $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL', $cmpCount, $curCount, 'integer');
                if ($chartEnd >= $chartStart) {
                    $this->addComparativeChart($sheet, 'Plantilla por sucursal', $chartStart, $chartEnd, $labelCmp, $labelCur, 'G4', 'O24', '#,##0');
                }
                $r++;

                $this->sectionHeader($sheet, "A{$r}:E{$r}", 'DETALLE POR SUCURSAL — ÍNDICE DE ROTACIÓN'); $r++;
                $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'SUCURSAL'); $r++;
                foreach ($porSucursalCur as $i => $row) {
                    $key  = strtoupper(trim($row['sucursal'] ?? ''));
                    $prev = (float)($porSucursalCmp->get($key)['indice_rotacion'] ?? 0);
                    $curr = (float)($row['indice_rotacion'] ?? 0);
                    $this->writeComparativeRow($sheet, $r, $row['sucursal'], $prev, $curr, 'percent', $i % 2 === 0);
                }
            }

            $this->setColWidths($sheet, ['A' => 26, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A5');
            return;
        }

        $mes         = $rotation['mes'] ?? '';
        $altas       = (int)($rotation['altas']    ?? 0);
        $bajas       = (int)($rotation['bajas']    ?? 0);
        $promedio    = (float)($rotation['promedio'] ?? 0);
        $indice      = (float)($rotation['indice']   ?? 0);
        $prevMes     = $rotation['prev_mes'] ?? null;
        $prevCount   = (float)($rotation['prev_count'] ?? 0);
        $currCount   = (float)($rotation['current_count'] ?? $promedio);
        $variacion   = (float)($rotation['variacion_neta'] ?? ($currCount - $prevCount));

        $r = 4;
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:F{$r}", 'RESUMEN GLOBAL');
        $r++;

        $summaryRows = [
            ['Mes de referencia',                                   $mes,       'text'],
            ['Plantilla mes anterior' . ($prevMes ? " ({$prevMes})" : ''), $prevCount, 'integer'],
            ['Plantilla mes actual',                                $currCount, 'integer'],
            ['Variación neta de plantilla',                         $variacion, 'integer'],
            ['N° de altas en el periodo',                           $altas,     'integer'],
            ['N° de bajas en el periodo',                           $bajas,     'integer'],
            ['Índice de rotación (%)',                              $indice,    'percent'],
        ];

        foreach ($summaryRows as $i => [$label2, $value, $fmt]) {
            $sheet->setCellValue("A{$r}", $label2);
            $sheet->setCellValue("B{$r}", $value);
            $this->dataRow($sheet, "A{$r}:F{$r}", $i % 2 === 0);
            if ($fmt === 'percent') {
                RadiographyStyleHelper::applyPercentFormat($sheet, "B{$r}", $value);
            } elseif ($fmt === 'integer') {
                RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
            }
            $r++;
        }
        $r++;

        $detalleMensual = $rotation['detalle_mensual'] ?? [];
        if (!empty($detalleMensual)) {
            RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:F{$r}", 'DETALLE MENSUAL (PERIODO CONSOLIDADO)');
            $r++;
            $this->colHeaders($sheet, $r, [
                'A' => 'MES', 'B' => 'ALTAS', 'C' => 'BAJAS', 'D' => 'PLANTILLA', 'E' => 'ÍNDICE (%)',
            ]);
            $r++;
            foreach ($detalleMensual as $i => $dm) {
                $sheet->setCellValue("A{$r}", $dm['mes']);
                $sheet->setCellValue("B{$r}", (int) $dm['altas']);
                $sheet->setCellValue("C{$r}", (int) $dm['bajas']);
                $sheet->setCellValue("D{$r}", (float) $dm['plantilla']);
                $sheet->setCellValue("E{$r}", (float) $dm['indice']);
                $this->dataRow($sheet, "A{$r}:E{$r}", $i % 2 === 0);
                RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
                RadiographyStyleHelper::applyIntegerFormat($sheet, "C{$r}");
                RadiographyStyleHelper::applyIntegerFormat($sheet, "D{$r}");
                RadiographyStyleHelper::applyPercentFormat($sheet, "E{$r}", (float) $dm['indice']);
                $r++;
            }
            $r++;
        }

        $porSucursal = $rotation['por_sucursal'] ?? [];
        if (!empty($porSucursal)) {
            RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:G{$r}", 'DETALLE POR SUCURSAL');
            $r++;
            $this->colHeaders($sheet, $r, [
                'A' => 'SUCURSAL',
                'B' => 'PLANTILLA ANTERIOR',
                'C' => 'PLANTILLA ACTUAL',
                'D' => 'ALTAS',
                'E' => 'BAJAS',
                'F' => 'VARIACIÓN',
                'G' => 'ÍNDICE ROTACIÓN (%)',
            ]);
            $r++;

            $chartStartRow = $r;
            $totalAltas    = 0;
            $totalBajas    = 0;
            $totalPromedio = 0.0;
            $totalAnterior = 0.0;
            foreach ($porSucursal as $i => $row) {
                $sucAltas     = (int)($row['altas']              ?? 0);
                $sucBajas     = (int)($row['bajas']              ?? 0);
                $sucPromedio  = (float)($row['promedio_personal'] ?? 0);
                $sucAnterior  = (float)($row['plantilla_anterior'] ?? 0);
                $sucVariacion = (float)($row['variacion_plantilla'] ?? ($sucPromedio - $sucAnterior));
                $sucIndice    = (float)($row['indice_rotacion']  ?? 0);

                $sheet->setCellValue("A{$r}", $row['sucursal'] ?? '');
                $sheet->setCellValue("B{$r}", $sucAnterior);
                $sheet->setCellValue("C{$r}", $sucPromedio);
                $sheet->setCellValue("D{$r}", $sucAltas);
                $sheet->setCellValue("E{$r}", $sucBajas);
                $sheet->setCellValue("F{$r}", $sucVariacion);
                $sheet->setCellValue("G{$r}", $sucIndice);

                $this->dataRow($sheet, "A{$r}:G{$r}", $i % 2 === 0);
                RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
                RadiographyStyleHelper::applyIntegerFormat($sheet, "C{$r}");
                RadiographyStyleHelper::applyIntegerFormat($sheet, "D{$r}");
                RadiographyStyleHelper::applyIntegerFormat($sheet, "E{$r}");
                RadiographyStyleHelper::applyIntegerFormat($sheet, "F{$r}");
                RadiographyStyleHelper::applyPercentFormat($sheet, "G{$r}", $sucIndice);

                $totalAltas    += $sucAltas;
                $totalBajas    += $sucBajas;
                $totalPromedio += $sucPromedio;
                $totalAnterior += $sucAnterior;
                $r++;
            }
            $chartEndRow = $r - 1;

            // Totals row
            $totalIndice = $totalPromedio > 0 ? round($totalBajas / $totalPromedio * 100, 2) : 0.0;
            $sheet->setCellValue("A{$r}", 'TOTAL / PLANTILLA');
            $sheet->setCellValue("B{$r}", $totalAnterior);
            $sheet->setCellValue("C{$r}", $totalPromedio);
            $sheet->setCellValue("D{$r}", $totalAltas);
            $sheet->setCellValue("E{$r}", $totalBajas);
            $sheet->setCellValue("F{$r}", round($totalPromedio - $totalAnterior, 2));
            $sheet->setCellValue("G{$r}", $totalIndice);
            $this->totalsRow($sheet, "A{$r}:G{$r}");
            RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
            RadiographyStyleHelper::applyIntegerFormat($sheet, "C{$r}");
            RadiographyStyleHelper::applyIntegerFormat($sheet, "D{$r}");
            RadiographyStyleHelper::applyIntegerFormat($sheet, "E{$r}");
            RadiographyStyleHelper::applyIntegerFormat($sheet, "F{$r}");
            RadiographyStyleHelper::applyPercentFormat($sheet, "G{$r}", $totalIndice);
            $r++;
            $r++;

            // Gráfica: plantilla anterior vs actual por sucursal — no encimada con la
            // tabla, se ancla varias filas abajo del total.
            if ($chartEndRow >= $chartStartRow) {
                RadiographyStyleHelper::addComparativeBarChart(
                    $sheet,
                    'Plantilla por sucursal — anterior vs actual',
                    "\$A\${$chartStartRow}:\$A\${$chartEndRow}",
                    "\$B\${$chartStartRow}:\$B\${$chartEndRow}",
                    "\$C\${$chartStartRow}:\$C\${$chartEndRow}",
                    $prevMes ?: 'Mes anterior',
                    $mes ?: 'Mes actual',
                    $chartEndRow - $chartStartRow + 1,
                    "I{$chartStartRow}",
                    'R' . ($chartStartRow + 20),
                    '#,##0'
                );
                $r = max($r, $chartStartRow + 22);
            }
        } else {
            $sheet->setCellValue("A{$r}", 'Sin datos de NOI suficientes para calcular rotación en este periodo.');
            RadiographyStyleHelper::mergeCellsSafe($sheet, "A{$r}:F{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(9);
            $r++;
        }
        $r++;

        $detail = $snap['sections']['rotation_detail'] ?? [];

        // Reporte final limpio: solo Sucursal/Clave/Colaborador — sin Fuente/Motivo/
        // frases de auditoría (eso queda en reportes:audit-rotacion-cierre, no en el
        // Excel entregable).

        // ── B) Altas ──
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:C{$r}", 'ALTAS');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'CLAVE', 'C' => 'COLABORADOR']);
        $r++;
        $altasList = $detail['altas'] ?? [];
        foreach ($altasList as $i => $a) {
            $sheet->setCellValue("A{$r}", $a['sucursal'] ?? '');
            $sheet->setCellValue("B{$r}", $a['clave'] ?? '—');
            $sheet->setCellValue("C{$r}", $a['nombre'] ?? '');
            $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
            $r++;
        }
        if (empty($altasList)) {
            $sheet->setCellValue("A{$r}", 'Sin altas en este periodo.');
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(9);
            $r++;
        }
        $r++;

        // ── C) Bajas ──
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:C{$r}", 'BAJAS');
        $r++;
        $this->colHeaders($sheet, $r, ['A' => 'SUCURSAL', 'B' => 'CLAVE', 'C' => 'COLABORADOR']);
        $r++;
        $bajasList = $detail['bajas'] ?? [];
        foreach ($bajasList as $i => $b) {
            $sheet->setCellValue("A{$r}", $b['sucursal'] ?? '');
            $sheet->setCellValue("B{$r}", $b['clave'] ?? '—');
            $sheet->setCellValue("C{$r}", $b['nombre'] ?? '');
            $this->dataRow($sheet, "A{$r}:C{$r}", $i % 2 === 0);
            $r++;
        }
        if (empty($bajasList)) {
            $sheet->setCellValue("A{$r}", 'Sin bajas en este periodo.');
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(9);
            $r++;
        }

        $this->setColWidths($sheet, ['A' => 30, 'B' => 18, 'C' => 32, 'D' => 22, 'E' => 22, 'F' => 14, 'G' => 20]);
        $sheet->freezePane('A5');
    }

    /**
     * Pestaña "IMSS" — detalle completo del cálculo automático desde NOI fiscal:
     * colaboradores únicos por sucursal × $3,500. Fuente única vigente desde
     * 2026-07-23 — el archivo manual queda solo como referencia histórica.
     */
    private function buildImssSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet = $ss->createSheet()->setTitle('IMSS');
        $imss  = $snap['sections']['imss'] ?? [];
        $label = strtoupper($period->label);
        $fee   = (float) ($imss['cuota_por_colaborador'] ?? 3500.0);

        $this->sheetTitle($sheet, 'A1:D1', $isComparative
            ? 'IMSS — CUOTA PATRONAL POR SUCURSAL — ' . strtoupper($comparePeriod->label) . ' VS ' . $label
            : 'IMSS — CUOTA PATRONAL POR SUCURSAL — ' . $label);
        RadiographyStyleHelper::applyHyperlinkStyle($sheet, 'A2', '← GLOBAL', 'GLOBAL');

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cImss = $compareSnap['sections']['imss'] ?? [];
            $porSucursalCur = collect($imss['por_sucursal'] ?? [])->filter(fn ($row) => !empty($row['incluido']))->values();
            $porSucursalCmp = collect($cImss['por_sucursal'] ?? [])->filter(fn ($row) => !empty($row['incluido']))->keyBy(fn ($row) => strtoupper(trim($row['sucursal'] ?? '')));

            $r = 4;
            $this->sectionHeader($sheet, "A{$r}:G{$r}", 'RESUMEN POR SUCURSAL'); $r++;
            $this->colHeaders($sheet, $r, [
                'A' => 'SUCURSAL',
                'B' => 'COLABORADORES ' . strtoupper($labelCmp),
                'C' => 'COLABORADORES ' . strtoupper($labelCur),
                'D' => 'IMSS ' . strtoupper($labelCmp),
                'E' => 'IMSS ' . strtoupper($labelCur),
                'F' => 'DIFERENCIA',
                'G' => 'VARIACIÓN %',
            ]);
            $r++;
            $totColCmp = 0; $totColCur = 0; $totImssCmp = 0.0; $totImssCur = 0.0;
            foreach ($porSucursalCur as $i => $row) {
                $key = strtoupper(trim($row['sucursal'] ?? ''));
                $cRow = $porSucursalCmp->get($key);
                $colCmp = (int)($cRow['colaboradores'] ?? 0);
                $colCur = (int)($row['colaboradores'] ?? 0);
                $imssCmp = (float)($cRow['imss'] ?? 0);
                $imssCur = (float)($row['imss'] ?? 0);
                $diff = $imssCur - $imssCmp;
                $varPct = $imssCmp != 0 ? round($diff / abs($imssCmp) * 100, 2) : ($imssCur != 0 ? 100.0 : 0.0);
                $sheet->setCellValue("A{$r}", $row['sucursal'] ?? '');
                $sheet->setCellValue("B{$r}", $colCmp);
                $sheet->setCellValue("C{$r}", $colCur);
                $sheet->setCellValue("D{$r}", $imssCmp);
                $sheet->setCellValue("E{$r}", $imssCur);
                $sheet->setCellValue("F{$r}", $diff);
                $sheet->setCellValue("G{$r}", $varPct);
                $this->dataRow($sheet, "A{$r}:G{$r}", $i % 2 === 0);
                RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
                RadiographyStyleHelper::applyIntegerFormat($sheet, "C{$r}");
                foreach (['D', 'E', 'F'] as $col) {
                    RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$col}{$r}");
                }
                $sheet->getStyle("G{$r}")->getNumberFormat()->setFormatCode('+0.00"%";-0.00"%";0.00"%"');
                $totColCmp += $colCmp; $totColCur += $colCur; $totImssCmp += $imssCmp; $totImssCur += $imssCur;
                $r++;
            }
            $totDiff = $totImssCur - $totImssCmp;
            $totVarPct = $totImssCmp != 0 ? round($totDiff / abs($totImssCmp) * 100, 2) : 0.0;
            $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
            $sheet->setCellValue("B{$r}", $totColCmp);
            $sheet->setCellValue("C{$r}", $totColCur);
            $sheet->setCellValue("D{$r}", $totImssCmp);
            $sheet->setCellValue("E{$r}", $totImssCur);
            $sheet->setCellValue("F{$r}", $totDiff);
            $sheet->setCellValue("G{$r}", $totVarPct);
            $this->totalsRow($sheet, "A{$r}:G{$r}");
            RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
            RadiographyStyleHelper::applyIntegerFormat($sheet, "C{$r}");
            foreach (['D', 'E', 'F'] as $col) { RadiographyStyleHelper::applyCurrencyFormat($sheet, "{$col}{$r}"); }

            $this->setColWidths($sheet, ['A' => 22, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 18, 'F' => 16, 'G' => 14]);
            $sheet->freezePane('A5');
            return;
        }

        $r = 4;

        // Reporte final limpio: solo colaboradores/sucursales que SÍ cuentan en el
        // total (sin columnas técnicas de fuente/inclusión/motivo — esa auditoría
        // vive en `reportes:audit-imss`, no en el Excel entregable).
        $porSucursal   = collect($imss['por_sucursal'] ?? [])->filter(fn ($row) => !empty($row['incluido']))->values();
        $colaboradores = collect($imss['colaboradores'] ?? [])->filter(fn ($c) => !empty($c['incluido']))->values();

        // ── A) Resumen por sucursal ──
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", 'RESUMEN POR SUCURSAL');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'SUCURSAL',
            'B' => 'COLABORADORES',
            'C' => 'CUOTA',
            'D' => 'TOTAL IMSS',
        ]);
        $r++;

        foreach ($porSucursal as $i => $row) {
            $sheet->setCellValue("A{$r}", $row['sucursal'] ?? '');
            $sheet->setCellValue("B{$r}", (int) ($row['colaboradores'] ?? 0));
            $sheet->setCellValue("C{$r}", (float) ($row['cuota'] ?? $fee));
            $sheet->setCellValue("D{$r}", (float) ($row['imss'] ?? 0));

            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "C{$r}");
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "D{$r}");
            $r++;
        }
        if ($porSucursal->isEmpty()) {
            $sheet->setCellValue("A{$r}", 'Sin colaboradores en NOI Nómina Fiscal para este periodo.');
            RadiographyStyleHelper::mergeCellsSafe($sheet, "A{$r}:D{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(9);
            $r++;
        } else {
            $sheet->setCellValue("A{$r}", 'TOTAL GENERAL');
            $sheet->setCellValue("B{$r}", (int) $porSucursal->sum('colaboradores'));
            $sheet->setCellValue("C{$r}", '');
            $sheet->setCellValue("D{$r}", (float) $porSucursal->sum('imss'));
            $this->totalsRow($sheet, "A{$r}:D{$r}");
            RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "D{$r}");
            $r++;
        }
        $r++;

        // ── B) Colaboradores ──
        RadiographyStyleHelper::applySectionHeaderStyle($sheet, "A{$r}:D{$r}", 'COLABORADORES');
        $r++;
        $this->colHeaders($sheet, $r, [
            'A' => 'SUCURSAL',
            'B' => 'CLAVE',
            'C' => 'COLABORADOR',
            'D' => 'IMPORTE IMSS',
        ]);
        $r++;

        foreach ($colaboradores as $i => $c) {
            $sheet->setCellValue("A{$r}", $c['sucursal'] ?? '');
            $sheet->setCellValue("B{$r}", $c['clave'] ?? '—');
            $sheet->setCellValue("C{$r}", $c['nombre'] ?? '');
            $sheet->setCellValue("D{$r}", (float) ($c['importe'] ?? 0));

            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            RadiographyStyleHelper::applyCurrencyFormat($sheet, "D{$r}");
            $r++;
        }
        if ($colaboradores->isEmpty()) {
            $sheet->setCellValue("A{$r}", 'Sin colaboradores que reportar.');
            RadiographyStyleHelper::mergeCellsSafe($sheet, "A{$r}:D{$r}");
            $sheet->getStyle("A{$r}")->getFont()->setItalic(true)->setSize(9);
            $r++;
        }

        $this->setColWidths($sheet, ['A' => 22, 'B' => 14, 'C' => 34, 'D' => 18]);
        $sheet->freezePane('A5');
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

        // BranchRadiographyCalculator record for this branch — fuente canónica de
        // gastos_operativos y de los componentes de Ingreso base EBITDA (ver más abajo).
        $brCalc = collect($snap['branch_radiography']['branches'] ?? [])
            ->first(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')) === $brUp);

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

        // FIX 2026-08-25 (encontrado por tests/Integration/WebExcelPdfDatasetConsistencyTest):
        // estos KPIs venían de $branchRow (sections.branches — un cálculo DISTINTO/legacy)
        // mientras Web (applyBranchScope()/summaryFromRow()) los lee de $brCalc
        // (branch_radiography.branches, BranchRadiographyCalculator — la fuente única
        // documentada en todo el proyecto). Divergían de verdad, no solo de código:
        // ATLIXCO llegó a diferir ~$270K entre Web y Excel para el mismo periodo. Ahora
        // ambos leen exactamente los mismos campos de la misma fila canónica.
        if (!$brCalc) {
            throw new \RuntimeException("Sucursal \"{$branchName}\" (ID {$branchId}) no tiene fila en branch_radiography para este periodo — no se puede generar un Excel consistente con Web.");
        }
        $mora0_30  = (float)($brCalc['mora_0_30']     ?? 0);
        $mora31_60 = (float)($brCalc['mora_31_60']   ?? 0);
        $mora61_90 = (float)($brCalc['mora_61_90']   ?? 0);
        $mora91120 = (float)($brCalc['mora_91_120']  ?? 0);
        $mora120p  = (float)($brCalc['mora_120_plus'] ?? 0);

        $carteraB  = (float)($brCalc['valor_cartera']      ?? 0);
        $vencidaB  = $mora0_30 + $mora31_60 + $mora61_90 + $mora91120 + $mora120p;
        $recB      = (float)($brCalc['recuperacion_total']  ?? 0);
        $colB      = (float)($brCalc['colocacion']    ?? 0);
        $gastosB   = (float)($brCalc['gastos_operativos'] ?? 0);
        $moraPct   = $carteraB > 0 ? round($vencidaB / $carteraB * 100, 2) : 0.0;

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

        // EBITDA = Ingreso base EBITDA − Gastos Totales (criterio final 2026-07 — NUNCA
        // Recuperación − Colocación). Ver BranchRadiographyCalculator::ebitdaFinalFor().
        $ingresoEbitdaBaseBW = $brCalc ? BranchRadiographyCalculator::ingresoEbitdaBaseFor($brCalc) : 0.0;
        $nomTotalBW = $brCalc ? BranchRadiographyCalculator::nominaTotalFor($brCalc) : $nomTotal;

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
        // Total OPEX: fuente canónica gastos_operativos (regla final 2026-07), no la suma de
        // la lista curada de arriba (que es solo desglose y puede no cubrir todos los conceptos).
        $gopTotalCanonico = $brCalc ? (float)($brCalc['gastos_operativos'] ?? 0) : ($gopTotal > 0 ? $gopTotal : $gastosB);
        $sheet->setCellValue("A{$r}", 'Total Gastos Operativos');
        $sheet->setCellValue("B{$r}", $gopTotalCanonico);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '4. NÓMINA Y CAPITAL HUMANO'); $r++;
        // Bug real 2026-08-26: antes se buscaba el nombre de cada categoría (ej. "Nómina")
        // como substring DENTRO del concepto NOI crudo (ej. "P001 SUELDO") — nunca matchea,
        // así que "Nómina"/"Bonos" siempre daban $0 pese a haber sueldo/bonos reales, y el
        // total ($nomTotalBW) no reconciliaba contra la suma de filas mostradas. Ahora usa
        // BranchRadiographyCalculator::nominaBreakdownFor($brCalc) — los MISMOS 9 campos
        // escalares que arma nominaTotalFor(), garantizado por construcción a sumar exacto.
        $nomCats = $brCalc ? BranchRadiographyCalculator::nominaBreakdownFor($brCalc) : [];
        $ii = 0;
        foreach ($nomCats as $nomName => $nomVal) {
            if ($nomVal == 0.0 && !in_array($nomName, ['Sueldo', 'Comisiones', 'Bonos', 'Vacaciones'], true)) continue;
            $sheet->setCellValue("A{$r}", $nomName);
            $sheet->setCellValue("B{$r}", $nomVal);
            $this->dataRow($sheet, "A{$r}:D{$r}", $ii % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", 'currency', $nomVal);
            $ii++;
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Total Nómina y Capital Humano');
        $sheet->setCellValue("B{$r}", $nomTotalBW);
        $this->totalsRow($sheet, "A{$r}:D{$r}");
        $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
        $r += 2;

        // Deducciones NOI — informativas, NUNCA se restan del Total de arriba (regla
        // vigente 2026-07, ver BranchRadiographyCalculator::accumulateNomina()). Se
        // muestran aparte para que nunca se confundan con un componente del total.
        $brDeducciones = (array) ($brCalc['nomina_detalle'] ?? []);
        if (!empty($brDeducciones)) {
            $this->sectionHeader($sheet, "A{$r}:D{$r}", 'DEDUCCIONES NOI (informativo — no se restan del total)'); $r++;
            $di = 0;
            $dedTotal = 0.0;
            foreach ($brDeducciones as $dedName => $dedVal) {
                $dedVal = (float) $dedVal;
                if ($dedVal == 0.0) continue;
                $sheet->setCellValue("A{$r}", $dedName);
                $sheet->setCellValue("B{$r}", $dedVal);
                $this->dataRow($sheet, "A{$r}:D{$r}", $di % 2 === 0);
                $this->applyFmt($sheet, "B{$r}", 'currency', $dedVal);
                $dedTotal += $dedVal;
                $di++;
                $r++;
            }
            $sheet->setCellValue("A{$r}", 'Total deducciones (informativo)');
            $sheet->setCellValue("B{$r}", $dedTotal);
            $this->totalsRow($sheet, "A{$r}:D{$r}");
            $sheet->getStyle("B{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $r++;
        }
        $r++;

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

        $bwGastosTotal = $gopTotalCanonico + $nomTotalBW;
        $utilidad      = $ingresoEbitdaBaseBW - $bwGastosTotal;
        $bwMargen      = $ingresoEbitdaBaseBW > 0 ? round($utilidad / $ingresoEbitdaBaseBW * 100, 2) : 0.0;

        // EBITDA = Ingreso base EBITDA − Gastos Totales (criterio final 2026-07).
        $this->sectionHeader($sheet, "A{$r}:D{$r}", '7. EBITDA'); $r++;
        foreach ([
            ['Utilidad bruta',                $ingresoEbitdaBaseBW, 'currency'],
            ['Menos: Gastos Totales',        $bwGastosTotal, 'currency'],
            ['  Gastos operativos (OPEX)',   $gopTotalCanonico, 'currency'],
            ['  Nómina y Capital Humano',    $nomTotalBW,    'currency'],
            ['= EBITDA del periodo',         $utilidad,      'currency'],
            ['Margen EBITDA (%)',            $bwMargen,      'percent'],
            ['Recuperación total / Otorgamientos (informativo)', $recB - $colB, 'currency'],
        ] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $r += 2;

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '8. SALDO TOTAL ACUMULADO CUENTAS'); $r++;
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

        // ── Gráficas nativas de sucursal (2026-08-25) — mismo helper que empleado y
        // GENERAL, sobre los MISMOS escalares ya calculados arriba (nunca recalculados).
        $chartHelper = app(RadiographyExcelChartHelper::class);
        $chartHelper->addBarChartFromData(
            $sheet, [['label' => 'Recuperación', 'value' => $recB], ['label' => 'Colocación', 'value' => $colB]],
            'Recuperación vs Colocación', 'branch_rec_vs_coloc_' . $branchId, 6, $r + 2, "F{$r}",
        );
        $chartHelper->addBarChartFromData(
            $sheet, [
                ['label' => 'Ingreso base EBITDA', 'value' => $ingresoEbitdaBaseBW],
                ['label' => 'Gastos totales', 'value' => $bwGastosTotal],
                ['label' => 'EBITDA', 'value' => $utilidad],
            ],
            'EBITDA vs Gastos', 'branch_ebitda_vs_gastos_' . $branchId, 6, $r + 16, 'F' . ($r + 14),
        );
        $chartHelper->addBarChartFromData(
            $sheet, [['label' => 'Al corriente', 'value' => max(0, $carteraB - $vencidaB)], ['label' => 'Vencida', 'value' => $vencidaB]],
            'Cartera sana vs vencida', 'branch_cartera_sana_vencida_' . $branchId, 6, $r + 30, 'F' . ($r + 28),
        );
        $chartHelper->addBarChartFromData(
            $sheet, [
                ['label' => 'Mora 1-30', 'value' => $mora0_30], ['label' => 'Mora 31-60', 'value' => $mora31_60],
                ['label' => 'Mora 61-90', 'value' => $mora61_90], ['label' => 'Mora 91-120', 'value' => $mora91120],
                ['label' => 'Mora 120+', 'value' => $mora120p],
            ],
            'Mora por bucket', 'branch_mora_bucket_' . $branchId, 6, $r + 44, 'F' . ($r + 42),
        );

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

        $chartHelper->addBarChartFromData(
            $colSheet,
            $colRows->map(fn ($row) => ['label' => $row['product'] ?? $row['producto'] ?? '—', 'value' => (float) ($row['monto'] ?? $row['amount'] ?? 0)])->all(),
            'Colocación por producto', 'branch_colocacion_producto_' . $branchId, 6, 3, 'F3',
        );

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

        $chartHelper->addBarChartFromData(
            $recSheet, [
                ['label' => 'Capital', 'value' => (float) ($recRow['capital'] ?? 0)],
                ['label' => 'Intereses', 'value' => (float) ($recRow['interest'] ?? 0)],
                ['label' => 'IVA', 'value' => (float) ($recRow['tax'] ?? 0)],
                ['label' => 'Cargos', 'value' => (float) ($recRow['charges'] ?? 0)],
            ],
            'Recuperación por componente', 'branch_recuperacion_componentes_' . $branchId, 7, 3, 'G3',
        );

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

        // ── Sheet 8: EFECTIVIDAD (2026-08-25) ────────────────────────────────
        // Misma llamada que ya usa el PDF de sucursal (buildEfectividadCobranza($period,
        // null, $branchId)) — nunca recalculado aparte. warmDataIds() es necesario porque
        // esta instancia se resuelve ad-hoc vía app() y nunca pasó por build().
        $snapshotBuilderForEf = app(\App\Services\Radiography\RadiographySnapshotBuilder::class);
        $snapshotBuilderForEf->warmDataIds($period);
        $efectividadB = $snapshotBuilderForEf->buildEfectividadCobranza($period, null, $branchId);

        $efSheetB = $spreadsheet->createSheet()->setTitle('EFECTIVIDAD');
        $this->sheetTitle($efSheetB, 'A1:F1', 'EFECTIVIDAD DE COBRANZA — ' . $branchName . ' — ' . strtoupper($period->label));
        $this->colHeaders($efSheetB, 2, ['A' => 'ESTATUS', 'B' => 'CONTRATOS', 'C' => 'CAPITAL', 'D' => 'INTERÉS', 'E' => 'IMPUESTO', 'F' => 'TOTAL']);
        $ebr = 3;
        $efBHasData = false;
        foreach (['vigente' => 'Vigente', 'atrasado' => 'Atrasado', 'vencido' => 'Vencido'] as $key => $label) {
            $e = $efectividadB[$key] ?? null;
            if (!$e) continue;
            $efBHasData = true;
            $efSheetB->setCellValue("A{$ebr}", $label);
            $efSheetB->setCellValue("B{$ebr}", (int) $e['contratos']);
            $efSheetB->setCellValue("C{$ebr}", (float) $e['capital']);
            $efSheetB->setCellValue("D{$ebr}", (float) $e['interes']);
            $efSheetB->setCellValue("E{$ebr}", (float) $e['impuesto']);
            $efSheetB->setCellValue("F{$ebr}", (float) $e['total']);
            $this->dataRow($efSheetB, "A{$ebr}:F{$ebr}", ($ebr - 3) % 2 === 0);
            foreach (['C', 'D', 'E', 'F'] as $col) { $efSheetB->getStyle("{$col}{$ebr}")->getNumberFormat()->setFormatCode(self::CURRENCY); }
            $ebr++;
        }
        if ($efBHasData && !empty($efectividadB['total'])) {
            $t = $efectividadB['total'];
            $efSheetB->setCellValue("A{$ebr}", 'Total');
            $efSheetB->setCellValue("B{$ebr}", (int) $t['contratos']);
            $efSheetB->setCellValue("C{$ebr}", (float) $t['capital']);
            $efSheetB->setCellValue("D{$ebr}", (float) $t['interes']);
            $efSheetB->setCellValue("E{$ebr}", (float) $t['impuesto']);
            $efSheetB->setCellValue("F{$ebr}", (float) $t['total']);
            $this->totalsRow($efSheetB, "A{$ebr}:F{$ebr}");
            foreach (['C', 'D', 'E', 'F'] as $col) { $efSheetB->getStyle("{$col}{$ebr}")->getNumberFormat()->setFormatCode(self::CURRENCY); }
        }
        if (!$efBHasData) { $efSheetB->setCellValue('A3', 'Sin datos de efectividad de cobranza para esta sucursal en el periodo.'); }

        // % real de efectividad (2026-08-25→08-26) — recuperado de mora ÷ cartera en
        // mora al cierre del mes anterior. La tabla de arriba es composición del
        // dinero cobrado, no un ratio contra lo que había que cobrar.
        $efBInfo = $efectividadB['efectividad'] ?? null;
        if ($efBInfo) {
            $ebr++;
            $efSheetB->setCellValue("A{$ebr}", 'Efectividad de cobranza (real)');
            $efSheetB->setCellValue("B{$ebr}", $efBInfo['efectividad_pct'] !== null
                ? number_format($efBInfo['efectividad_pct'], 1) . '%'
                : 'N/D');
            $ebr++;
            $efSheetB->setCellValue("A{$ebr}", 'Fórmula');
            $efSheetB->setCellValue("B{$ebr}", $efBInfo['cartera_mora_periodo_anterior'] !== null
                ? 'Recuperado de mora $' . number_format($efBInfo['recuperado_de_mora'], 2)
                    . ' ÷ cartera en mora al cierre de ' . ($efBInfo['periodo_anterior_label'] ?? '')
                    . ' $' . number_format($efBInfo['cartera_mora_periodo_anterior'], 2)
                : 'Sin cartera del mes anterior para calcular el denominador.');
        }
        $this->setColWidths($efSheetB, ['A' => 16, 'B' => 12, 'C' => 16, 'D' => 16, 'E' => 16, 'F' => 16]);

        $chartHelper->addBarChartFromData(
            $efSheetB, [
                ['label' => 'Vigente', 'value' => (float) ($efectividadB['vigente']['total'] ?? 0)],
                ['label' => 'Atrasado', 'value' => (float) ($efectividadB['atrasado']['total'] ?? 0)],
                ['label' => 'Vencido', 'value' => (float) ($efectividadB['vencido']['total'] ?? 0)],
            ],
            // dataStartCol=8 (H): la tabla ocupa A:F (incluye TOTAL en F).
            'Efectividad de cobranza', 'branch_efectividad_' . $branchId, 8, 3, 'H3',
        );

        if ($spreadsheet->getSheetCount() > 1) {
            try {
                $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName('Worksheet'));
                if ($idx !== null) $spreadsheet->removeSheetByIndex($idx);
            } catch (\Throwable) {}
        }
        foreach ($spreadsheet->getAllSheets() as $sheetToClean) { $sheetToClean->setShowGridlines(false); }
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

        // Identidad PRIMERO por employee_id — la MISMA vinculación robusta que usa Web
        // (RadiographySnapshotBuilder::applyEmployeeScope()), en vez de comparar texto contra
        // el nombre de display ya expuesto (frágil: causa raíz real de "funciona un mes,
        // falla otro" — ver auditoría 2026-08-24). El nombre solo se usa como fallback.
        // NOTA: se guarda la instancia (no solo el resultado) porque
        // findEmployeeGestorRowByEmployeeId() la "calienta" (fija $this->dataIds internamente,
        // ver su implementación) — reutilizarla más abajo para buildEfectividadCobranza() evita
        // llamar ese método sobre una instancia nueva sin dataIds resuelto (dataIds vacío →
        // resultado todo-ceros silencioso).
        $snapshotBuilder = app(\App\Services\Radiography\RadiographySnapshotBuilder::class);
        $empRow = $snapshotBuilder->findEmployeeGestorRowByEmployeeId($period, $employeeId);

        $empGest = $snap['sections']['employees_gestores'] ?? [];
        if (!$empRow) {
            foreach ($empGest as $e) {
                if ($canonicalizer->normalize($e['name'] ?? '') === $empNormTarget) {
                    $empRow = $e;
                    break;
                }
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
        $ingresoBase = (float)($empRow['ingreso_ebitda_base'] ?? 0);

        // EBITDA = Ingreso base EBITDA (mismos componentes que BranchRadiographyCalculator::
        // ingresoEbitdaBaseFor(), agregados por gestor) − (Gastos + NóminaNeta). NUNCA
        // Recuperación − Colocación (fórmula obsoleta, ver criterio final 2026-07).
        $utilidad  = $ingresoBase - ($gastos + $pagos + $bonos - $desctos);

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

        // Desglose completo (2026-08-25) — antes solo mostraba la fórmula en una fila;
        // ahora expone Ingreso base/Gastos totales/EBITDA/Margen por separado, igual que el
        // PDF de gestor (RadiografiaExportService::resolveEmployeeRow()), MISMOS valores ya
        // calculados arriba, nunca recalculados.
        $margen    = $ingresoBase > 0 ? round($utilidad / $ingresoBase * 100, 2) : 0.0;
        $categoria = RadiographyStyleHelper::ebitdaCategory($utilidad);
        $catColors = RadiographyStyleHelper::categoryColors($categoria);

        $this->sectionHeader($sheet, "A{$r}:D{$r}", '4. EBITDA ESTIMADO'); $r++;
        foreach ([
            ['Ingreso base EBITDA', $ingresoBase, 'currency'],
            ['Gastos totales (Gastos + Nómina neta)', $gastos + $pagos + $bonos - $desctos, 'currency'],
            ['EBITDA', $utilidad, 'currency'],
            ['Margen EBITDA', $margen, 'percent'],
        ] as $i => [$label, $val, $fmt]) {
            $sheet->setCellValue("A{$r}", $label); $sheet->setCellValue("B{$r}", $val);
            $this->dataRow($sheet, "A{$r}:D{$r}", $i % 2 === 0);
            $this->applyFmt($sheet, "B{$r}", $fmt, $val);
            $r++;
        }
        $sheet->setCellValue("A{$r}", 'Categoría EBITDA'); $sheet->setCellValue("B{$r}", $categoria);
        $this->dataRow($sheet, "A{$r}:D{$r}", true);
        $sheet->getStyle("B{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => $catColors['fg']]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $catColors['bg']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
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

        // ── Gráficas nativas de Excel (2026-08-25) — sobre los MISMOS valores ya
        // calculados arriba (nunca recalculados). El bloque de datos de cada chart se
        // escribe en columnas F/G, fuera del área impresa (A:D) para no interferir con
        // el layout de la tabla RESUMEN. Cada addBarChartFromData() no agrega nada si
        // no hay valores > 0 que graficar (nunca un chart vacío).
        $chartHelper = app(RadiographyExcelChartHelper::class);
        $chartHelper->addBarChartFromData(
            $sheet, [['label' => 'Recuperación', 'value' => $rec], ['label' => 'Colocación', 'value' => $coloc]],
            'Recuperación vs Colocación', 'rec_vs_coloc_' . $employeeId, 6, 4, 'F4',
        );
        $chartHelper->addBarChartFromData(
            $sheet, [
                ['label' => 'Ingreso base EBITDA', 'value' => $ingresoBase],
                ['label' => 'Gastos + Nómina neta', 'value' => $gastos + $pagos + $bonos - $desctos],
                ['label' => 'EBITDA', 'value' => $utilidad],
            ],
            'EBITDA vs Gastos', 'ebitda_vs_gastos_' . $employeeId, 6, 20, 'F20',
        );

        // ── Sheet 2: NÓMINA DETAIL ────────────────────────────────────────────
        // IMPORTANTE (fix 2026-08-25): antes esta hoja consultaba fact_noi_movements
        // filtrando SOLO por $employeeId (el id que llegó por parámetro) — si esa persona
        // tenía movimientos bajo OTRO employee_id fusionado por el mismo $empRow
        // (duplicado histórico NOI vs NOI Fiscal, ver buildEmployeesGestores()::_employee_ids),
        // esos movimientos desaparecían de esta hoja aunque SÍ contaran en "Nómina total" del
        // RESUMEN — exactamente el patrón "Nómina y Capital Humano > 0 pero NOI detail = 0".
        // Ahora usa el MISMO grupo de employee_id que ya resolvió el RESUMEN (arriba), nunca
        // una identidad distinta calculada aparte.
        $noiSheet = $spreadsheet->createSheet()->setTitle('NÓMINA');
        $this->sheetTitle($noiSheet, 'A1:E1', 'DETALLE DE NÓMINA — ' . mb_strtoupper($empName));
        $this->colHeaders($noiSheet, 2, ['A' => 'CONCEPTO', 'B' => 'TIPO', 'C' => 'MONTO', 'D' => 'FECHA', 'E' => 'PERIODO NÓMINA']);
        $resolveDataIds = $snapshotBuilder->resolveDataIdsPublic($period);
        $noiEmployeeIds = !empty($empRow['_employee_ids']) ? $empRow['_employee_ids'] : [$employeeId];
        $noiRows = \Illuminate\Support\Facades\DB::table('fact_noi_movements as n')
            ->join('employees as e', 'n.employee_id', '=', 'e.id')
            ->whereIn('n.period_id', $resolveDataIds)
            ->whereIn('n.employee_id', $noiEmployeeIds)
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
        if ($noiRows->isEmpty()) { $noiSheet->setCellValue('A3', 'Sin movimientos de nómina para este empleado en el periodo.'); }
        $this->setColWidths($noiSheet, ['A' => 38, 'B' => 16, 'C' => 18, 'D' => 16, 'E' => 20]);

        // ── Sheet 3: COLOCACIÓN ──────────────────────────────────────────────
        // IMPORTANTE (fix 2026-08-25): antes esta hoja recalculaba colocación por producto
        // con un LIKE '%nombre%' directo contra fact_placements.promoter_name — una fuente
        // de verdad DISTINTA de la que ya usa Web (applyEmployeeScope()::_placements_by_product,
        // agrupada por nombre CANÓNICO/normalizado, la misma agregación que produjo el KPI
        // "Colocación" del RESUMEN). Un LIKE puede dar un total distinto (substrings
        // parciales, variantes de nombre no fusionadas) — Excel y Web podían mostrar cifras
        // diferentes para el mismo colaborador. Ahora usa el MISMO desglose reconciliado.
        $colEmpSheet = $spreadsheet->createSheet()->setTitle('COLOCACIÓN');
        $this->sheetTitle($colEmpSheet, 'A1:D1', 'COLOCACIÓN — ' . mb_strtoupper($empName));
        $this->colHeaders($colEmpSheet, 2, ['A' => 'PRODUCTO', 'B' => 'MONTO', 'C' => 'CRÉDITOS', 'D' => 'SUCURSAL']);
        $placRows = $empRow['_placements_by_product'] ?? [];
        $plr = 3;
        foreach ($placRows as $i => $row) {
            $colEmpSheet->setCellValue("A{$plr}", $row['producto'] ?? '—');
            $colEmpSheet->setCellValue("B{$plr}", (float)($row['colocacion'] ?? 0));
            $colEmpSheet->setCellValue("C{$plr}", (int)($row['operaciones'] ?? 0));
            $colEmpSheet->setCellValue("D{$plr}", $empBranch ?? '—');
            $this->dataRow($colEmpSheet, "A{$plr}:D{$plr}", $i % 2 === 0);
            $colEmpSheet->getStyle("B{$plr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $plr++;
        }
        if (empty($placRows)) { $colEmpSheet->setCellValue('A3', 'Sin colocación registrada para este gestor en el periodo.'); }
        $this->setColWidths($colEmpSheet, ['A' => 28, 'B' => 18, 'C' => 12, 'D' => 22]);

        $chartHelper->addBarChartFromData(
            $colEmpSheet,
            array_map(fn ($p) => ['label' => $p['producto'] ?? '—', 'value' => (float) ($p['colocacion'] ?? 0)], $placRows),
            'Colocación por producto', 'colocacion_producto_' . $employeeId, 6, 3, 'F3',
        );

        // ── Sheet 4: CARTERA Y MORA ───────────────────────────────────────────
        // IMPORTANTE (fix 2026-08-25): antes recalculaba cartera/vencida por PRODUCTO con un
        // LIKE '%nombre%' contra fact_portfolios.promoter_name — otra fuente de verdad
        // distinta de la que Web ya expone (applyEmployeeScope()::_mora_buckets, la MISMA
        // agregación por nombre canónico que produce 'cartera'/'vencida' del RESUMEN, con
        // guardia de reconciliación SUM(buckets)==vencida). Se cambia el desglose de
        // "por producto" a "por antigüedad de mora" (buckets) — es el desglose reconciliado
        // que realmente existe a nivel colaborador; no se inventa un desglose por producto
        // que no tiene fuente canónica equivalente.
        $portEmpSheet = $spreadsheet->createSheet()->setTitle('CARTERA');
        $this->sheetTitle($portEmpSheet, 'A1:D1', 'CARTERA Y MORA — ' . mb_strtoupper($empName));
        $this->colHeaders($portEmpSheet, 2, ['A' => 'BUCKET DE MORA', 'B' => 'CONTRATOS', 'C' => 'MONTO', 'D' => 'MORA %']);
        $moraBucketsRows = $empRow['_mora_buckets'] ?? [];
        $por = 3;
        $i = 0;
        foreach ($moraBucketsRows as $bucketKey => $b) {
            $monto  = (float) ($b['monto'] ?? 0);
            $moraPp = $cartera > 0 && $bucketKey !== 'al_corriente' ? round($monto / $cartera * 100, 2) : 0;
            $portEmpSheet->setCellValue("A{$por}", $b['label'] ?? $bucketKey);
            $portEmpSheet->setCellValue("B{$por}", (int) ($b['contratos'] ?? 0));
            $portEmpSheet->setCellValue("C{$por}", $monto);
            $portEmpSheet->setCellValue("D{$por}", $moraPp);
            $this->dataRow($portEmpSheet, "A{$por}:D{$por}", $i % 2 === 0);
            $portEmpSheet->getStyle("C{$por}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $portEmpSheet->getStyle("D{$por}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $por++;
            $i++;
        }
        if (empty($moraBucketsRows)) { $portEmpSheet->setCellValue('A3', 'Sin cartera registrada para este gestor en el periodo.'); }
        $this->setColWidths($portEmpSheet, ['A' => 22, 'B' => 14, 'C' => 18, 'D' => 12]);

        $chartHelper->addBarChartFromData(
            $portEmpSheet, [['label' => 'Al corriente', 'value' => max(0, $cartera - $vencida)], ['label' => 'Vencida', 'value' => $vencida]],
            'Cartera sana vs vencida', 'cartera_sana_vencida_' . $employeeId, 6, 3, 'F3',
        );
        $chartHelper->addBarChartFromData(
            $portEmpSheet,
            array_map(fn ($k, $b) => ['label' => $b['label'] ?? $k, 'value' => (float) ($b['monto'] ?? 0)], array_keys($moraBucketsRows), $moraBucketsRows),
            'Mora por bucket', 'mora_bucket_' . $employeeId, 6, 20, 'F20',
        );

        // ── Sheet 5: RECUPERACIÓN (2026-08-25) ───────────────────────────────
        // Componentes + desglose por producto — MISMOS campos internos que ya usa el PDF
        // de gestor (RadiografiaExportService::resolveEmployeeRow()) y Web
        // (applyEmployeeScope()) — nunca recalculados aparte.
        $recEmpSheet = $spreadsheet->createSheet()->setTitle('RECUPERACIÓN');
        $this->sheetTitle($recEmpSheet, 'A1:D1', 'RECUPERACIÓN — ' . mb_strtoupper($empName));
        $recoveryComponents = $empRow['_recovery_components'] ?? null;
        $recoveryByProduct  = $empRow['_recovery_by_product']  ?? [];
        $recoveryComponentLabels = [
            'capital' => 'Capital recuperado', 'interes' => 'Intereses', 'impuesto' => 'Impuestos',
            'moratorios' => 'Moratorios', 'cargos_adicionales' => 'Cargos adicionales',
            'cargos_inicio' => 'Cargos al inicio', 'comision_apertura' => 'Comisión por apertura',
            'excedentes' => 'Excedentes recuperados', 'otros' => 'Otros',
        ];
        $this->colHeaders($recEmpSheet, 2, ['A' => 'COMPONENTE', 'B' => 'MONTO', 'C' => '', 'D' => '']);
        $rr = 3;
        if (is_array($recoveryComponents)) {
            foreach ($recoveryComponents as $key => $val) {
                $recEmpSheet->setCellValue("A{$rr}", $recoveryComponentLabels[$key] ?? ucfirst(str_replace('_', ' ', $key)));
                $recEmpSheet->setCellValue("B{$rr}", (float) $val);
                $this->dataRow($recEmpSheet, "A{$rr}:D{$rr}", ($rr - 3) % 2 === 0);
                $recEmpSheet->getStyle("B{$rr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $rr++;
            }
            $recEmpSheet->setCellValue("A{$rr}", 'Total recuperación');
            $recEmpSheet->setCellValue("B{$rr}", $rec);
            $this->totalsRow($recEmpSheet, "A{$rr}:D{$rr}");
            $recEmpSheet->getStyle("B{$rr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $rr += 2;
        } else {
            $recEmpSheet->setCellValue('A3', 'Sin movimientos de recuperación para este gestor en el periodo.');
            $rr = 5;
        }
        $this->sectionHeader($recEmpSheet, "A{$rr}:D{$rr}", 'RECUPERACIÓN POR PRODUCTO'); $rr++;
        $this->colHeaders($recEmpSheet, $rr, ['A' => 'PRODUCTO', 'B' => 'RECUPERACIÓN', 'C' => '% DEL TOTAL', 'D' => '']); $rr++;
        $rbpStart = $rr;
        foreach ($recoveryByProduct as $i => $rp) {
            $recEmpSheet->setCellValue("A{$rr}", $rp['producto'] ?? '—');
            $recEmpSheet->setCellValue("B{$rr}", (float) ($rp['recuperacion'] ?? 0));
            $recEmpSheet->setCellValue("C{$rr}", $rec > 0 ? round(((float) ($rp['recuperacion'] ?? 0)) / $rec, 4) : 0);
            $this->dataRow($recEmpSheet, "A{$rr}:D{$rr}", $i % 2 === 0);
            $recEmpSheet->getStyle("B{$rr}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $recEmpSheet->getStyle("C{$rr}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $rr++;
        }
        if (empty($recoveryByProduct)) { $recEmpSheet->setCellValue("A{$rbpStart}", 'Sin desglose por producto disponible para este gestor.'); }
        $this->setColWidths($recEmpSheet, ['A' => 28, 'B' => 18, 'C' => 12, 'D' => 12]);

        $chartHelper->addBarChartFromData(
            $recEmpSheet,
            array_map(fn ($p) => ['label' => $p['producto'] ?? '—', 'value' => (float) ($p['recuperacion'] ?? 0)], $recoveryByProduct),
            'Recuperación por producto', 'recuperacion_producto_' . $employeeId, 6, 3, 'F3',
        );

        // ── Sheet 6: EFECTIVIDAD (2026-08-25) ────────────────────────────────
        // Vigente/atrasado/vencido — misma llamada que ya usa el PDF de gestor
        // (buildEfectividadCobranza($period, $empRow['name'])), nunca recalculado aparte.
        $efSheet = $spreadsheet->createSheet()->setTitle('EFECTIVIDAD');
        $this->sheetTitle($efSheet, 'A1:F1', 'EFECTIVIDAD DE COBRANZA — ' . mb_strtoupper($empName));
        $efectividad = $snapshotBuilder->buildEfectividadCobranza($period, $empRow['name'] ?? null);
        $this->colHeaders($efSheet, 2, ['A' => 'ESTATUS', 'B' => 'CONTRATOS', 'C' => 'CAPITAL', 'D' => 'INTERÉS', 'E' => 'IMPUESTO', 'F' => 'TOTAL']);
        $er = 3;
        $efHasData = false;
        foreach (['vigente' => 'Vigente', 'atrasado' => 'Atrasado', 'vencido' => 'Vencido'] as $key => $label) {
            $e = $efectividad[$key] ?? null;
            if (!$e) continue;
            $efHasData = true;
            $efSheet->setCellValue("A{$er}", $label);
            $efSheet->setCellValue("B{$er}", (int) $e['contratos']);
            $efSheet->setCellValue("C{$er}", (float) $e['capital']);
            $efSheet->setCellValue("D{$er}", (float) $e['interes']);
            $efSheet->setCellValue("E{$er}", (float) $e['impuesto']);
            $efSheet->setCellValue("F{$er}", (float) $e['total']);
            $this->dataRow($efSheet, "A{$er}:F{$er}", ($er - 3) % 2 === 0);
            foreach (['C', 'D', 'E', 'F'] as $col) { $efSheet->getStyle("{$col}{$er}")->getNumberFormat()->setFormatCode(self::CURRENCY); }
            $er++;
        }
        if ($efHasData && !empty($efectividad['total'])) {
            $t = $efectividad['total'];
            $efSheet->setCellValue("A{$er}", 'Total');
            $efSheet->setCellValue("B{$er}", (int) $t['contratos']);
            $efSheet->setCellValue("C{$er}", (float) $t['capital']);
            $efSheet->setCellValue("D{$er}", (float) $t['interes']);
            $efSheet->setCellValue("E{$er}", (float) $t['impuesto']);
            $efSheet->setCellValue("F{$er}", (float) $t['total']);
            $this->totalsRow($efSheet, "A{$er}:F{$er}");
            foreach (['C', 'D', 'E', 'F'] as $col) { $efSheet->getStyle("{$col}{$er}")->getNumberFormat()->setFormatCode(self::CURRENCY); }
        }
        if (!$efHasData) { $efSheet->setCellValue('A3', 'Sin datos de efectividad de cobranza para este gestor en el periodo.'); }

        // % real de efectividad (2026-08-26) — recuperado de mora ÷ cartera en mora al
        // cierre del mes anterior, filtrado por ESTE gestor (mismo promoter_name que
        // buildEfectividadCobranza() ya usó arriba). La tabla de arriba es composición
        // del dinero cobrado por antigüedad, no un ratio contra lo que había que cobrar.
        $efGestorInfo = $efectividad['efectividad'] ?? null;
        if ($efGestorInfo) {
            $er++;
            $efSheet->setCellValue("A{$er}", 'Efectividad de cobranza (real)');
            $efSheet->setCellValue("B{$er}", $efGestorInfo['efectividad_pct'] !== null
                ? number_format($efGestorInfo['efectividad_pct'], 1) . '%'
                : 'N/D');
            $er++;
            $efSheet->setCellValue("A{$er}", 'Fórmula');
            $efSheet->setCellValue("B{$er}", $efGestorInfo['cartera_mora_periodo_anterior'] !== null
                ? 'Recuperado de mora $' . number_format($efGestorInfo['recuperado_de_mora'], 2)
                    . ' ÷ cartera en mora al cierre de ' . ($efGestorInfo['periodo_anterior_label'] ?? '')
                    . ' $' . number_format($efGestorInfo['cartera_mora_periodo_anterior'], 2)
                : 'Sin cartera del mes anterior para calcular el denominador.');
        }
        $this->setColWidths($efSheet, ['A' => 16, 'B' => 12, 'C' => 16, 'D' => 16, 'E' => 16, 'F' => 16]);

        $chartHelper->addBarChartFromData(
            $efSheet, [
                ['label' => 'Vigente', 'value' => (float) ($efectividad['vigente']['total'] ?? 0)],
                ['label' => 'Atrasado', 'value' => (float) ($efectividad['atrasado']['total'] ?? 0)],
                ['label' => 'Vencido', 'value' => (float) ($efectividad['vencido']['total'] ?? 0)],
            ],
            // dataStartCol=8 (H): la tabla de este sheet ocupa A:F (incluye TOTAL en F), a
            // diferencia de RESUMEN/RECUPERACIÓN/COLOCACIÓN/CARTERA cuyas tablas terminan en D.
            'Efectividad de cobranza', 'efectividad_' . $employeeId, 8, 3, 'H3',
        );

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

        // Fila fuente para General/Sucursal: GLOBAL o el registro de esa sucursal en
        // branch_radiography.branches — ambos tienen exactamente la misma forma
        // (mismos campos que emptyBranchSummary()), así que toda la lógica de abajo
        // (ingresos, OPEX, nómina, EBITDA) funciona igual para los dos casos.
        $rowSource = function (array $snap) use ($scope, $branchName): array {
            if ($scope === 'branch' && $branchName) {
                $brUp = strtoupper(trim($branchName));
                return collect($snap['branch_radiography']['branches'] ?? [])
                    ->first(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')) === $brUp) ?? [];
            }
            return $snap['branch_radiography']['global'] ?? [];
        };

        $canonicalizer = app(\App\Services\EmployeeNameCanonicalizer::class);
        $empRow = function (array $snap) use ($empId, $canonicalizer): array {
            if (!$empId) return [];
            $emp    = \App\Models\Employee::find($empId);
            $target = $canonicalizer->normalize($emp->full_name ?? '');
            foreach ($snap['sections']['employees_gestores'] ?? [] as $r) {
                if ($canonicalizer->normalize($r['name'] ?? '') === $target) return $r;
            }
            return [];
        };

        $isDetailed = $scope !== 'employee'; // secciones C-G solo aplican a General/Sucursal

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle("Comparativo {$labelCmp} vs {$labelCur}")
            ->setCreator('Sistema de Reportes');

        $sheet = $spreadsheet->getActiveSheet()->setTitle('COMPARATIVO');
        $reportType = $config['report_type'] ?? 'month_vs_month';
        $typeLabel  = match ($reportType) {
            'bimester_vs_bimester' => 'RADIOGRAFÍA COMPARATIVA — BIMESTRE VS BIMESTRE',
            'quarter_vs_quarter'   => 'RADIOGRAFÍA COMPARATIVA — TRIMESTRE VS TRIMESTRE',
            default                => 'RADIOGRAFÍA COMPARATIVA — MES VS MES',
        };

        // ── A) Encabezado ─────────────────────────────────────────────────────
        $this->sheetTitle($sheet, 'A1:E1', $typeLabel);
        RadiographyStyleHelper::mergeCellsSafe($sheet,'A2:E2');
        $sheet->setCellValue('A2', 'MR LANA — ' . $labelCmp . ' vs ' . $labelCur . ($scopeLabel !== 'General' ? " — {$scopeLabel}" : ''));
        $this->metaStyle($sheet, 'A2:E2');
        $metaLine = "Periodo comparado: {$comparePeriod->label}  |  Periodo actual: {$currentPeriod->label}  |  Generado: " . now('America/Mexico_City')->format('d/m/Y H:i');
        $cmpComposite = $compareSnap['period']['composite'] ?? null;
        $curComposite = $currentSnap['period']['composite'] ?? null;
        if ($cmpComposite || $curComposite) {
            $metaLine = ($cmpComposite ? $cmpComposite['component_range'] : $comparePeriod->label)
                . '  vs  ' . ($curComposite ? $curComposite['component_range'] : $currentPeriod->label)
                . '  |  Generado: ' . now('America/Mexico_City')->format('d/m/Y H:i');
        }
        RadiographyStyleHelper::mergeCellsSafe($sheet,'A3:E3');
        $sheet->setCellValue('A3', $metaLine);
        $this->metaStyle($sheet, 'A3:E3');

        $r = 5;

        $writeCompHeader = function (int &$r) use ($sheet, $labelCmp, $labelCur): void {
            $sheet->setCellValue("A{$r}", 'MÉTRICA');
            $sheet->setCellValue("B{$r}", $labelCmp);
            $sheet->setCellValue("C{$r}", $labelCur);
            $sheet->setCellValue("D{$r}", 'DIFERENCIA');
            $sheet->setCellValue("E{$r}", 'VARIACIÓN %');
            $sheet->getStyle("A{$r}:E{$r}")->applyFromArray([
                'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_HDR]],
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_HDR]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => self::FG_HDR]]],
            ]);
            $sheet->getRowDimension($r)->setRowHeight(18);
            $r++;
        };

        $writeComp = function (int &$r, string $label, float $prev, float $curr, string $fmt, bool $alt) use ($sheet): void {
            $diff   = $curr - $prev;
            $varPct = $prev != 0 ? round($diff / abs($prev) * 100, 2) : ($curr != 0 ? 100.0 : 0.0);

            $sheet->setCellValue("A{$r}", $label);
            $sheet->setCellValue("B{$r}", $prev);
            $sheet->setCellValue("C{$r}", $curr);
            $sheet->setCellValue("D{$r}", $diff);
            $sheet->setCellValue("E{$r}", $fmt === 'percent' ? $diff : $varPct);

            $this->dataRow($sheet, "A{$r}:E{$r}", $alt);
            $fmtCode = $fmt === 'currency' ? self::CURRENCY : ($fmt === 'percent' ? self::PERCENT : self::INTEGER);
            foreach (['B', 'C', 'D'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode($fmtCode);
            }
            // Para métricas ya expresadas en % (mora, margen, rotación) la "variación" se
            // muestra en puntos porcentuales (diferencia directa), no en % de un %.
            $sheet->getStyle("E{$r}")->getNumberFormat()->setFormatCode($fmt === 'percent' ? '+0.00" pp";-0.00" pp";0.00" pp"' : '+0.00"%";-0.00"%";0.00"%"');
            $varForColor = $fmt === 'percent' ? $diff : $varPct;
            if ($varForColor > 0) {
                $sheet->getStyle("E{$r}")->getFont()->getColor()->setARGB('FF15803D');
            } elseif ($varForColor < 0) {
                $sheet->getStyle("E{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
            }
            $r++;
        };

        $cmpRow = $rowSource($compareSnap);
        $curRow = $rowSource($currentSnap);

        $moraTotalFor = fn (array $row) => (float)($row['mora_0_30'] ?? 0) + (float)($row['mora_31_60'] ?? 0)
            + (float)($row['mora_61_90'] ?? 0) + (float)($row['mora_91_120'] ?? 0) + (float)($row['mora_120_plus'] ?? 0);
        $moraPctFor = fn (array $row) => (float)($row['valor_cartera'] ?? 0) > 0
            ? round($moraTotalFor($row) / (float)$row['valor_cartera'] * 100, 2) : 0.0;

        // ── B) Resumen ejecutivo comparativo ────────────────────────────────────
        $this->sectionHeader($sheet, "A{$r}:E{$r}", 'B) RESUMEN EJECUTIVO COMPARATIVO'); $r++;
        $r++;
        $writeCompHeader($r);
        $bStart = $r;

        if ($scope === 'employee') {
            $ce = $empRow($compareSnap);
            $cu = $empRow($currentSnap);
            $ingBaseCmp = (float)($ce['ingreso_ebitda_base'] ?? 0);
            $ingBaseCur = (float)($cu['ingreso_ebitda_base'] ?? 0);
            $gastosCmp  = (float)($ce['gastos'] ?? 0) + (float)($ce['pagos'] ?? 0) + (float)($ce['bonos'] ?? 0) - (float)($ce['descuentos'] ?? 0);
            $gastosCur  = (float)($cu['gastos'] ?? 0) + (float)($cu['pagos'] ?? 0) + (float)($cu['bonos'] ?? 0) - (float)($cu['descuentos'] ?? 0);
            $rows = [
                ['Recuperación',   (float)($ce['recuperacion'] ?? 0), (float)($cu['recuperacion'] ?? 0), 'currency'],
                ['Colocación',     (float)($ce['colocacion']   ?? 0), (float)($cu['colocacion']   ?? 0), 'currency'],
                ['Valor cartera',  (float)($ce['cartera']      ?? 0), (float)($cu['cartera']      ?? 0), 'currency'],
                ['Cartera vencida',(float)($ce['vencida']      ?? 0), (float)($cu['vencida']      ?? 0), 'currency'],
                ['Mora %',         (float)($ce['mora']         ?? 0), (float)($cu['mora']         ?? 0), 'percent'],
                ['Ingreso base EBITDA', $ingBaseCmp, $ingBaseCur, 'currency'],
                ['Gastos + Nómina neta', $gastosCmp, $gastosCur, 'currency'],
                ['EBITDA gestor',  $ingBaseCmp - $gastosCmp, $ingBaseCur - $gastosCur, 'currency'],
            ];
        } else {
            $ingBaseCmp = BranchRadiographyCalculator::ingresoEbitdaBaseFor($cmpRow);
            $ingBaseCur = BranchRadiographyCalculator::ingresoEbitdaBaseFor($curRow);
            $gastosTotCmp = BranchRadiographyCalculator::gastosTotalesFor($cmpRow);
            $gastosTotCur = BranchRadiographyCalculator::gastosTotalesFor($curRow);
            $nomCmp = BranchRadiographyCalculator::nominaTotalFor($cmpRow);
            $nomCur = BranchRadiographyCalculator::nominaTotalFor($curRow);
            $rows = [
                ['Recuperación',                (float)($cmpRow['recuperacion_total'] ?? 0), (float)($curRow['recuperacion_total'] ?? 0), 'currency'],
                ['Ingreso base EBITDA / Utilidad bruta', $ingBaseCmp, $ingBaseCur, 'currency'],
                ['Colocación',                  (float)($cmpRow['colocacion'] ?? 0), (float)($curRow['colocacion'] ?? 0), 'currency'],
                ['Valor cartera',                (float)($cmpRow['valor_cartera'] ?? 0), (float)($curRow['valor_cartera'] ?? 0), 'currency'],
                ['Cartera vencida',              $moraTotalFor($cmpRow), $moraTotalFor($curRow), 'currency'],
                ['Mora %',                       $moraPctFor($cmpRow), $moraPctFor($curRow), 'percent'],
                ['OPEX',                         (float)($cmpRow['gastos_operativos'] ?? 0), (float)($curRow['gastos_operativos'] ?? 0), 'currency'],
                ['Nómina y Capital Humano',      $nomCmp, $nomCur, 'currency'],
                ['Gastos Totales',               $gastosTotCmp, $gastosTotCur, 'currency'],
                ['EBITDA',                       $ingBaseCmp - $gastosTotCmp, $ingBaseCur - $gastosTotCur, 'currency'],
                ['Margen EBITDA',                BranchRadiographyCalculator::margenEbitdaFor($cmpRow), BranchRadiographyCalculator::margenEbitdaFor($curRow), 'percent'],
                ['Préstamos activos (contratos)', (float)($cmpRow['contratos'] ?? 0), (float)($curRow['contratos'] ?? 0), 'integer'],
            ];
            if ($scope === 'general') {
                $rows[] = ['Percepciones',                (float)($compareSnap['summary']['noi_percepciones'] ?? 0), (float)($currentSnap['summary']['noi_percepciones'] ?? 0), 'currency'];
                $rows[] = ['Deducciones (informativo)',    (float)($compareSnap['summary']['noi_deducciones']  ?? 0), (float)($currentSnap['summary']['noi_deducciones']  ?? 0), 'currency'];
                $rows[] = ['Neto pagado a trabajadores',   (float)($compareSnap['summary']['noi_neto_pagado']  ?? 0), (float)($currentSnap['summary']['noi_neto_pagado']  ?? 0), 'currency'];
            }
            $rows[] = ['IMSS', (float)($cmpRow['imss_patronal'] ?? 0), (float)($curRow['imss_patronal'] ?? 0), 'currency'];
            if ($scope === 'general') {
                $rotCmp = (float)($compareSnap['sections']['rotation']['indice'] ?? 0);
                $rotCur = (float)($currentSnap['sections']['rotation']['indice'] ?? 0);
                $rows[] = ['Rotación %', $rotCmp, $rotCur, 'percent'];
            }
        }

        foreach ($rows as $i => [$label, $prev, $curr, $fmt]) {
            $writeComp($r, $label, $prev, $curr, $fmt, $i % 2 === 0);
        }
        $bEnd = $r - 1;
        $r++;

        if ($isDetailed) {
            // ── C) Comparativo de ingresos ──────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'C) COMPARATIVO DE INGRESOS'); $r++;
            $r++;
            $writeCompHeader($r);
            foreach ([
                ['Capital recuperado',              'capital_recuperado'],
                ['Intereses',                       'interes_recuperado'],
                ['Impuestos',                       'impuesto_recuperado'],
                ['Moratorios / Multas',             'charges'],
                ['Comisión por apertura',           'comision_apertura'],
                ['Cargos adicionales',              'cargos_adicionales'],
                ['Excedentes recuperados',          'excedente_recuperado'],
                ['Seguro CRECE reconocido (30%)',   'seguro_crece_reconocido'],
            ] as $i => [$label, $key]) {
                $writeComp($r, $label, (float)($cmpRow[$key] ?? 0), (float)($curRow[$key] ?? 0), 'currency', $i % 2 === 0);
            }
            $writeComp($r, 'Recuperación total', (float)($cmpRow['recuperacion_total'] ?? 0), (float)($curRow['recuperacion_total'] ?? 0), 'currency', false);
            $writeComp($r, 'Ingreso base EBITDA', $ingBaseCmp, $ingBaseCur, 'currency', true);
            $r++;

            // ── D) Comparativo OPEX ─────────────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'D) COMPARATIVO OPEX'); $r++;
            $r++;
            $writeCompHeader($r);
            $gastosDetCmp = (array)($cmpRow['gastos_detalle'] ?? []);
            $gastosDetCur = (array)($curRow['gastos_detalle'] ?? []);
            $conceptos = collect(array_keys($gastosDetCmp))->merge(array_keys($gastosDetCur))->unique()
                ->sortByDesc(fn ($c) => (float)($gastosDetCur[$c] ?? $gastosDetCmp[$c] ?? 0))->values()->take(15);
            foreach ($conceptos as $i => $concepto) {
                $writeComp($r, $concepto, (float)($gastosDetCmp[$concepto] ?? 0), (float)($gastosDetCur[$concepto] ?? 0), 'currency', $i % 2 === 0);
            }
            $writeComp($r, 'TOTAL OPEX', (float)($cmpRow['gastos_operativos'] ?? 0), (float)($curRow['gastos_operativos'] ?? 0), 'currency', false);
            $r++;

            // ── E) Comparativo Nómina ────────────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'E) COMPARATIVO NÓMINA'); $r++;
            $r++;
            $writeCompHeader($r);
            $infoCmp = (array)($cmpRow['nomina_informativo'] ?? []);
            $infoCur = (array)($curRow['nomina_informativo'] ?? []);
            $dedCmp  = array_sum((array)($cmpRow['nomina_detalle'] ?? []));
            $dedCur  = array_sum((array)($curRow['nomina_detalle'] ?? []));
            $percepCmp = (float)($cmpRow['nomina_total'] ?? 0) + (float)($cmpRow['comisiones'] ?? 0) + (float)($cmpRow['bonos'] ?? 0)
                + (float)($cmpRow['bonos_aceleradores'] ?? 0) + (float)($cmpRow['vacaciones'] ?? 0) + (float)($cmpRow['prima_vacacional'] ?? 0) + (float)($cmpRow['otros_percepciones'] ?? 0);
            $percepCur = (float)($curRow['nomina_total'] ?? 0) + (float)($curRow['comisiones'] ?? 0) + (float)($curRow['bonos'] ?? 0)
                + (float)($curRow['bonos_aceleradores'] ?? 0) + (float)($curRow['vacaciones'] ?? 0) + (float)($curRow['prima_vacacional'] ?? 0) + (float)($curRow['otros_percepciones'] ?? 0);
            foreach ([
                ['Sueldos / Nómina',   (float)($cmpRow['nomina_total'] ?? 0), (float)($curRow['nomina_total'] ?? 0)],
                ['Comisiones',         (float)($cmpRow['comisiones'] ?? 0), (float)($curRow['comisiones'] ?? 0)],
                ['Vacaciones',         (float)($cmpRow['vacaciones'] ?? 0), (float)($curRow['vacaciones'] ?? 0)],
                ['Prima vacacional',   (float)($cmpRow['prima_vacacional'] ?? 0), (float)($curRow['prima_vacacional'] ?? 0)],
                ['Bonos',              (float)($cmpRow['bonos'] ?? 0) + (float)($cmpRow['bonos_aceleradores'] ?? 0), (float)($curRow['bonos'] ?? 0) + (float)($curRow['bonos_aceleradores'] ?? 0)],
                ['IMSS',               (float)($cmpRow['imss_patronal'] ?? 0), (float)($curRow['imss_patronal'] ?? 0)],
                ['Finiquito',          (float)($infoCmp['Finiquito'] ?? 0), (float)($infoCur['Finiquito'] ?? 0)],
                ['Financiamiento de Motos', (float)($infoCmp['Financiamiento de Motos'] ?? 0), (float)($infoCur['Financiamiento de Motos'] ?? 0)],
                ['Cascos',             (float)($infoCmp['Cascos'] ?? 0), (float)($infoCur['Cascos'] ?? 0)],
                ['Gastos médicos',     (float)($infoCmp['Gastos médicos'] ?? 0), (float)($infoCur['Gastos médicos'] ?? 0)],
                ['Percepciones totales', $percepCmp, $percepCur],
                ['Deducciones informativas', $dedCmp, $dedCur],
            ] as $i => [$label, $prev, $curr]) {
                $writeComp($r, $label, $prev, $curr, 'currency', $i % 2 === 0);
            }
            $writeComp($r, 'Total Nómina y Capital Humano', $nomCmp, $nomCur, 'currency', false);
            $r++;

            // ── F) Comparativo por sucursal (EBITDA) ─────────────────────────────
            if ($scope === 'general') {
                $this->sectionHeader($sheet, "A{$r}:E{$r}", 'F) COMPARATIVO POR SUCURSAL — EBITDA'); $r++;
                $sheet->setCellValue("A{$r}", 'SUCURSAL');
                $sheet->setCellValue("B{$r}", 'EBITDA ' . $labelCmp);
                $sheet->setCellValue("C{$r}", 'EBITDA ' . $labelCur);
                $sheet->setCellValue("D{$r}", 'DIFERENCIA');
                $sheet->setCellValue("E{$r}", 'CATEGORÍA ' . $labelCur);
                $sheet->getStyle("A{$r}:E{$r}")->applyFromArray([
                    'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_HDR]],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_HDR]],
                ]);
                $r++;
                $fSectionStart = $r;
                $cmpBranches = collect($compareSnap['branch_radiography']['branches'] ?? [])->keyBy(fn ($b) => strtoupper(trim($b['sucursal'] ?? '')));
                $curBranches = collect($currentSnap['branch_radiography']['branches'] ?? []);
                foreach ($curBranches as $i => $curB) {
                    $key = strtoupper(trim($curB['sucursal'] ?? ''));
                    $cmpB = $cmpBranches->get($key, []);
                    $ebitdaCmp = $cmpB ? BranchRadiographyCalculator::ebitdaFinalFor($cmpB) : 0.0;
                    $ebitdaCur = BranchRadiographyCalculator::ebitdaFinalFor($curB);
                    $sheet->setCellValue("A{$r}", $curB['sucursal']);
                    $sheet->setCellValue("B{$r}", $ebitdaCmp);
                    $sheet->setCellValue("C{$r}", $ebitdaCur);
                    $sheet->setCellValue("D{$r}", $ebitdaCur - $ebitdaCmp);
                    $sheet->setCellValue("E{$r}", RadiographyStyleHelper::ebitdaCategory($ebitdaCur));
                    $this->dataRow($sheet, "A{$r}:E{$r}", $i % 2 === 0);
                    foreach (['B','C','D'] as $col) { $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY); }
                    $r++;
                }
                $fSectionEnd = $r - 1;
                $r++;
            } else {
                $fSectionStart = $fSectionEnd = null;
            }

            // ── H) Comparativo de rotación ───────────────────────────────────────
            // Cada snapshot ya trae su propio headcount real (current_count, antes
            // hardcodeado a 0) — Plantilla {labelCmp} = compareSnap.current_count,
            // Plantilla {labelCur} = currentSnap.current_count.
            if ($scope === 'general') {
                $rotCmpSection = $compareSnap['sections']['rotation'] ?? [];
                $rotCurSection = $currentSnap['sections']['rotation'] ?? [];
                $rotPlantillaCmp = (float)($rotCmpSection['current_count'] ?? $rotCmpSection['promedio'] ?? 0);
                $rotPlantillaCur = (float)($rotCurSection['current_count'] ?? $rotCurSection['promedio'] ?? 0);

                $this->sectionHeader($sheet, "A{$r}:E{$r}", 'H) COMPARATIVO DE ROTACIÓN'); $r++;
                $r++;
                $writeCompHeader($r);
                $writeComp($r, 'Plantilla', $rotPlantillaCmp, $rotPlantillaCur, 'integer', true);
                $writeComp($r, 'Altas del periodo', (float)($rotCmpSection['altas'] ?? 0), (float)($rotCurSection['altas'] ?? 0), 'integer', false);
                $writeComp($r, 'Bajas del periodo', (float)($rotCmpSection['bajas'] ?? 0), (float)($rotCurSection['bajas'] ?? 0), 'integer', true);
                $writeComp($r, 'Índice de rotación', (float)($rotCmpSection['indice'] ?? 0), (float)($rotCurSection['indice'] ?? 0), 'percent', false);
                $r++;

                $rotCmpPorSucursal = collect($rotCmpSection['por_sucursal'] ?? [])->keyBy(fn ($x) => strtoupper(trim($x['sucursal'] ?? '')));
                $rotCurPorSucursal = collect($rotCurSection['por_sucursal'] ?? []);
                if ($rotCurPorSucursal->isNotEmpty()) {
                    $sheet->setCellValue("A{$r}", 'SUCURSAL');
                    $sheet->setCellValue("B{$r}", 'PLANTILLA ' . $labelCmp);
                    $sheet->setCellValue("C{$r}", 'PLANTILLA ' . $labelCur);
                    $sheet->setCellValue("D{$r}", 'VARIACIÓN');
                    $sheet->setCellValue("E{$r}", 'ÍNDICE ' . $labelCur);
                    $sheet->getStyle("A{$r}:E{$r}")->applyFromArray([
                        'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_HDR]],
                        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_HDR]],
                    ]);
                    $r++;
                    foreach ($rotCurPorSucursal as $i => $curSuc) {
                        $key   = strtoupper(trim($curSuc['sucursal'] ?? ''));
                        $cmpSuc = $rotCmpPorSucursal->get($key, []);
                        $plCmp = (float)($cmpSuc['promedio_personal'] ?? 0);
                        $plCur = (float)($curSuc['promedio_personal'] ?? 0);
                        $sheet->setCellValue("A{$r}", $curSuc['sucursal'] ?? '');
                        $sheet->setCellValue("B{$r}", $plCmp);
                        $sheet->setCellValue("C{$r}", $plCur);
                        $sheet->setCellValue("D{$r}", $plCur - $plCmp);
                        $sheet->setCellValue("E{$r}", (float)($curSuc['indice_rotacion'] ?? 0));
                        $this->dataRow($sheet, "A{$r}:E{$r}", $i % 2 === 0);
                        RadiographyStyleHelper::applyIntegerFormat($sheet, "B{$r}");
                        RadiographyStyleHelper::applyIntegerFormat($sheet, "C{$r}");
                        RadiographyStyleHelper::applyIntegerFormat($sheet, "D{$r}");
                        RadiographyStyleHelper::applyPercentFormat($sheet, "E{$r}", (float)($curSuc['indice_rotacion'] ?? 0));
                        $r++;
                    }
                }
                $r++;
            }

            // ── I) Gráficas ──────────────────────────────────────────────────────
            $this->sectionHeader($sheet, "A{$r}:E{$r}", 'I) GRÁFICAS COMPARATIVAS'); $r++;
            $chartAnchorRow = $r;
            // Reconstruye desde $bStart..$bEnd (sección B) las filas de interés por
            // etiqueta, para graficar exactamente lo que ya se ve en la tabla ejecutiva.
            // Separadas por unidad (dinero vs porcentaje) para no mezclar dos escalas
            // distintas en un mismo eje, y en anclas separadas para que no queden
            // encimadas.
            $moneyLabels   = ['Recuperación', 'Colocación', 'OPEX', 'Nómina y Capital Humano', 'EBITDA', 'Valor cartera'];
            $percentLabels = ['Mora %', 'Rotación %'];
            $chartRowsFound = [];
            for ($rr = $bStart; $rr <= $bEnd; $rr++) {
                $lbl = $sheet->getCell("A{$rr}")->getValue();
                if (in_array($lbl, $moneyLabels, true) || in_array($lbl, $percentLabels, true)) {
                    $chartRowsFound[$lbl] = $rr;
                }
            }

            $buildAuxChart = function (array $labels, string $title, string $numberFormat, int &$r) use ($sheet, $chartRowsFound, $labelCmp, $labelCur): void {
                $auxStart = $r;
                $ci = 0;
                foreach ($labels as $lbl) {
                    if (!isset($chartRowsFound[$lbl])) continue;
                    $srcRow = $chartRowsFound[$lbl];
                    $auxRow = $auxStart + $ci;
                    $sheet->setCellValue("A{$auxRow}", $lbl);
                    $sheet->setCellValue("B{$auxRow}", "=B{$srcRow}");
                    $sheet->setCellValue("C{$auxRow}", "=C{$srcRow}");
                    $ci++;
                }
                if ($ci === 0) return;
                $auxEnd = $auxStart + $ci - 1;
                RadiographyStyleHelper::addComparativeBarChart(
                    $sheet,
                    $title . ' — ' . $labelCmp . ' vs ' . $labelCur,
                    "\$A\${$auxStart}:\$A\${$auxEnd}",
                    "\$B\${$auxStart}:\$B\${$auxEnd}",
                    "\$C\${$auxStart}:\$C\${$auxEnd}",
                    $labelCmp,
                    $labelCur,
                    $ci,
                    "G{$auxStart}",
                    'P' . ($auxStart + 18),
                    $numberFormat
                );
                $sheet->getStyle("A{$auxStart}:C{$auxEnd}")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFCBD5E1'))->setSize(7);
                $r = $auxEnd + 21;
            };

            $buildAuxChart($moneyLabels, 'Comparativo de KPIs financieros', '"$"#,##0', $r);
            $buildAuxChart($percentLabels, 'Comparativo Mora % / Rotación %', '0.00"%"', $r);
        }

        $this->setColWidths($sheet, ['A' => 42, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
        $sheet->freezePane('A6');
        $sheet->setShowGridlines(false);

        try {
            $idx = $spreadsheet->getIndex($spreadsheet->getSheetByName('Worksheet'));
            if ($idx !== null) $spreadsheet->removeSheetByIndex($idx);
        } catch (\Throwable) {}

        foreach ($spreadsheet->getAllSheets() as $sheetToClean) { $sheetToClean->setShowGridlines(false); }
        $spreadsheet->setActiveSheetIndex(0);
        return $spreadsheet;
    }

    // ── NÓMINA POR GESTOR ────────────────────────────────────────────────────

    private function buildNominaGestorSheet(Spreadsheet $ss, Period $period, array $snap, ?Period $comparePeriod = null, ?array $compareSnap = null): void
    {
        $isComparative = $comparePeriod !== null && $compareSnap !== null;
        $sheet = $ss->createSheet()->setTitle('NÓMINA POR GESTOR');
        $label = strtoupper($period->label);
        $this->sheetTitle($sheet, 'A1:M1', $isComparative
            ? 'NÓMINA POR GESTOR — ' . strtoupper($comparePeriod->label) . ' VS ' . $label
            : 'NÓMINA POR GESTOR — ' . $label);
        $sheet->setCellValue('A2', '← GLOBAL');
        $sheet->getCell('A2')->getHyperlink()->setUrl('sheet://GLOBAL!A1');
        $sheet->getStyle('A2')->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF1D4ED8'))->setUnderline(true);

        $fetchNominaGestorRows = function (Period $p) {
            $allPeriods = \App\Models\Period::all();
            $weeklyIds  = $p->resolveBaseWeeklyIds($allPeriods);
            $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$p->id])));

            return \Illuminate\Support\Facades\DB::table('fact_noi_movements as n')
                ->join('employees as e', 'n.employee_id', '=', 'e.id')
                ->leftJoin('employee_branch_assignments as eba', function ($j) use ($p) {
                    $j->on('eba.employee_id', '=', 'n.employee_id')->where('eba.period_id', '=', $p->id);
                })
                ->leftJoin('branches as b', 'eba.branch_id', '=', 'b.id')
                ->whereIn('n.period_id', $dataIds)
                ->whereNotNull('n.employee_id')
                ->selectRaw("
                    COALESCE(b.name, 'Sin sucursal') as sucursal,
                    MAX(e.full_name) as empleado,
                    e.normalized_name,
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
                ->groupBy('e.normalized_name', 'b.name')
                ->orderBy('sucursal')
                ->orderByRaw('e.normalized_name')
                ->get();
        };

        $rows = $fetchNominaGestorRows($period);

        if ($isComparative) {
            $labelCmp = $comparePeriod->label;
            $labelCur = $period->label;
            $cRows = $fetchNominaGestorRows($comparePeriod);
            $totalOf = fn ($row) => (float)$row->sueldos + (float)$row->comisiones + (float)$row->bonos
                + (float)$row->vacaciones + (float)$row->prima_vacacional - (float)$row->descuentos;
            $cByKey = collect($cRows)->keyBy('normalized_name');

            $r = 3;
            $this->comparativeHeader($sheet, $r, $labelCmp, $labelCur, 'EMPLEADO / GESTOR'); $r++;
            $bySucursal = collect($rows)->groupBy('sucursal');
            $grandCur = 0.0; $grandCmp = 0.0;
            foreach ($bySucursal as $sucursal => $group) {
                $this->sectionHeader($sheet, "A{$r}:E{$r}", strtoupper($this->dashIfUnresolved($sucursal))); $r++;
                $subCur = 0.0; $subCmp = 0.0;
                foreach ($group as $i => $emp) {
                    $curr = $totalOf($emp);
                    $cEmp = $cByKey->get($emp->normalized_name);
                    $prev = $cEmp ? $totalOf($cEmp) : 0.0;
                    $this->writeComparativeRow($sheet, $r, $emp->empleado, $prev, $curr, 'currency', $i % 2 === 0);
                    $subCur += $curr; $subCmp += $prev;
                }
                $this->writeComparativeTotalsRow($sheet, $r, 'SUBTOTAL — ' . strtoupper($this->dashIfUnresolved($sucursal)), $subCmp, $subCur, 'currency');
                $r++;
                $grandCur += $subCur; $grandCmp += $subCmp;
            }
            $this->writeComparativeTotalsRow($sheet, $r, 'TOTAL GENERAL', $grandCmp, $grandCur, 'currency');

            $this->setColWidths($sheet, ['A' => 32, 'B' => 20, 'C' => 20, 'D' => 18, 'E' => 14]);
            $sheet->freezePane('A4');
            return;
        }

        // All dataIds (weekly base periods + this period)
        $amtExpr = "CASE
            WHEN LOWER(COALESCE(n.concept_type,'')) LIKE '%comisi%' OR LOWER(COALESCE(n.concept,'')) LIKE '%comisi%' THEN n.amount
            ELSE 0 END";

        $headers = [
            'A' => 'SUCURSAL', 'B' => 'EMPLEADO / GESTOR',
            'C' => 'SUELDOS', 'D' => 'COMISIONES', 'E' => 'BONOS',
            'F' => 'VACACIONES', 'G' => 'PRIMA VACACIONAL',
            'H' => 'DESCUENTOS', 'I' => 'TOTAL NÓMINA',
        ];
        $this->colHeaders($sheet, 3, $headers);
        $r = 4;

        $currBranch   = null;
        $branchTotals = array_fill_keys(['sueldos','comisiones','bonos','vacaciones','prima_vacacional','descuentos','total'], 0.0);
        $grandTotals  = $branchTotals;
        $rowIdx       = 0;

        $writeTotals = function (string $branch, array $t) use ($sheet, &$r) {
            $this->sectionHeader($sheet, "A{$r}:I{$r}", 'SUBTOTAL — ' . strtoupper($this->dashIfUnresolved($branch)));
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

            $sheet->setCellValue("A{$r}", $this->dashIfUnresolved($emp->sucursal));
            $sheet->setCellValue("B{$r}", $emp->empleado);
            $sheet->setCellValue("C{$r}", (float)$emp->sueldos);
            $sheet->setCellValue("D{$r}", (float)$emp->comisiones);
            $sheet->setCellValue("E{$r}", (float)$emp->bonos);
            $sheet->setCellValue("F{$r}", (float)$emp->vacaciones);
            $sheet->setCellValue("G{$r}", (float)$emp->prima_vacacional);
            $sheet->setCellValue("H{$r}", (float)$emp->descuentos);
            $sheet->setCellValue("I{$r}", $total);
            // Columnas J (REGISTROS) y K (FUENTES) eliminadas: son datos técnicos de auditoría
            // no relevantes para el usuario final — solo se exponen en hojas de auditoría.
            $this->dataRow($sheet, "A{$r}:I{$r}", $rowIdx % 2 === 0);
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
        $this->totalsRow($sheet, "A{$r}:I{$r}");
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
        $sheet->setAutoFilter('A3:I3');
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
