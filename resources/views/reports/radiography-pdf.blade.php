<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Radiografía {{ $period->label }}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #1e293b; background: #fff; }
@page { margin: 18mm 14mm 22mm 14mm; }

/* ── Identidad / encabezado ─────────────────────────────────────────────── */
.brand { text-align: center; padding-bottom: 10px; margin-bottom: 14px; border-bottom: 2px solid #1f2937; }
.brand-mark { font-size: 19pt; font-weight: bold; letter-spacing: 1px; color: #106A59; }
.brand-sub  { font-size: 9.5pt; color: #334155; text-transform: uppercase; letter-spacing: 2px; margin-top: 2px; }
.brand-meta { font-size: 8pt; color: #64748b; margin-top: 8px; }
.brand-meta b { color: #1e293b; }

/* ── Barra de sección (gris oscuro, como el PDF de referencia) ───────────── */
.section-bar {
    background: #1f2937; color: #fff;
    padding: 6px 10px; font-size: 9pt; font-weight: bold;
    letter-spacing: .3px; text-transform: uppercase;
    margin-top: 16px; margin-bottom: 8px;
}
.section-bar.alt { background: #334155; }
.block { margin-bottom: 4px; }
.avoid { page-break-inside: avoid; }
.pagebreak { page-break-before: always; }
.note { font-size: 7.3pt; color: #64748b; margin-top: 5px; font-style: italic; }
.spacer { height: 10px; }

/* ── Layout de 2 columnas (tabla — DomPDF no soporta CSS grid/flex) ───────── */
table.layout2 { width: 100%; border-collapse: collapse; }
table.layout2 > tr > td { width: 50%; vertical-align: top; padding: 0; }
table.layout2 > tr > td.colL { padding-right: 8px; }
table.layout2 > tr > td.colR { padding-left: 8px; }

/* ── KPI cards ─────────────────────────────────────────────────────────── */
table.kpi-grid { width: 100%; border-collapse: separate; border-spacing: 4px; margin-top: 4px; }
table.kpi-grid td.kpi {
    width: 25%; border: 0.75pt solid #d9e2ec; background: #f8fafc;
    padding: 7px 9px; vertical-align: top;
}
.kpi-label { font-size: 6.8pt; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; }
.kpi-value { font-size: 11.5pt; font-weight: bold; color: #106A59; margin-top: 3px; }
.kpi-value.neg  { color: #b91c1c; }
.kpi-value.warn { color: #b45309; }

/* ── Tablas compactas ──────────────────────────────────────────────────── */
table.tbl { width: 100%; border-collapse: collapse; font-size: 7.8pt; }
table.tbl thead th {
    background: #1f2937; color: #fff; font-weight: bold; text-align: left;
    padding: 5px 6px; border-bottom: 1px solid #1f2937;
}
table.tbl tbody td { padding: 4px 6px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
table.tbl tbody tr:nth-child(even) td { background: #f8fafc; }
table.tbl tfoot td {
    background: #1f2937; color: #fff; font-weight: bold;
    padding: 5px 6px; border-top: 1pt solid #0f172a;
}
table.tbl .r { text-align: right; }
table.tbl .c { text-align: center; }
table.tbl .b { font-weight: bold; }

/* ── Badges de categoría EBITDA ────────────────────────────────────────── */
.badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 7.3pt; font-weight: bold; }
.badge-senior    { background: #d1fae5; color: #065f46; }
.badge-junior    { background: #fef3c7; color: #92400e; }
.badge-mantenido { background: #fee2e2; color: #b91c1c; }

/* ── Barras HTML simples (gris claro + relleno proporcional) ──────────────── */
.bar-row { margin-bottom: 6px; }
.bar-label { font-size: 7.6pt; color: #334155; margin-bottom: 2px; }
.bar-track { background: #e7ecf1; width: 100%; height: 11px; border-radius: 2px; }
.bar-fill  { height: 11px; border-radius: 2px; display: block; min-width: 2px; }
.bar-fill-teal  { background: #106A59; }
.bar-fill-blue  { background: #5B9BD5; }
.bar-fill-red   { background: #b91c1c; }
.bar-value { font-size: 7.3pt; color: #475569; margin-top: 1px; text-align: right; }

/* ── Alerta / inconsistencia ───────────────────────────────────────────── */
.alert-box {
    background: #fef2f2; border: 0.75pt solid #fecaca; color: #991b1b;
    font-size: 7.6pt; font-weight: bold; padding: 6px 8px; margin-top: 6px;
}
.ok-box {
    background: #ecfdf5; border: 0.75pt solid #a7f3d0; color: #065f46;
    font-size: 7.6pt; padding: 6px 8px; margin-top: 6px;
}
</style>
</head>
<body>

@php
$snap = $snapshot;
$sum  = $snap['summary'];
$fmt  = fn($v) => '$' . number_format((float)$v, 2);
$fmt0 = fn($v) => '$' . number_format((float)$v, 0);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$fmtn = fn($v) => number_format((float)$v, 0);
$cat  = fn($c) => match($c) { 'SENIOR' => 'badge-senior', 'JUNIOR' => 'badge-junior', default => 'badge-mantenido' };

// ── Branch radiography — única fuente de verdad ──────────────────────────────
$brCalc     = $snap['branch_radiography'] ?? null;
$brBranches = $brCalc ? ($brCalc['branches'] ?? []) : [];
$brGlobal   = $brCalc ? ($brCalc['global']   ?? null) : null;

// ── Ingresos / Cobranza — desglose seguros ───────────────────────────────────
$ingrBruta       = (float)($brGlobal['recuperacion_bruta']      ?? $sum['recovery_bruta']            ?? 0);
$ingrSegExcluido = (float)($brGlobal['seguro_excluido_bruto']   ?? $sum['recovery_seguro_excluido']  ?? 0);
$ingrSavehearts  = (float)($brGlobal['seguro_savehearts_bruto'] ?? 0);
$ingrComadres    = (float)($brGlobal['seguro_comadres_bruto']   ?? 0);
$ingrCrece       = (float)($brGlobal['seguro_crece_bruto']      ?? $sum['recovery_crece_bruto']      ?? 0);
$ingrCrece30     = (float)($brGlobal['seguro_crece_reconocido'] ?? $sum['recovery_crece_reconocido'] ?? 0);
$ingrCrece70     = max(0.0, $ingrCrece - $ingrCrece30);
$ingrCanalizadoAseguradora = $ingrSavehearts + $ingrComadres + $ingrCrece70;
$ingrTotal       = (float)($brGlobal['recuperacion_total']      ?? $sum['recovery_total']);

// Componentes de ingresos (desglose B)
$ingrCapital     = (float)($brGlobal['capital_recuperado']   ?? 0);
$ingrInteres     = (float)($brGlobal['interes_recuperado']   ?? 0);
$ingrImpuesto    = (float)($brGlobal['impuesto_recuperado']  ?? 0);
$ingrCharges     = (float)($brGlobal['charges']              ?? 0);
$ingrCargosIni   = (float)($brGlobal['cargos_inicio']        ?? 0);
$ingrComAper     = (float)($brGlobal['comision_apertura']    ?? 0);
$ingrCondonacion = (float)($brGlobal['condonacion_excluida'] ?? 0);
$ingrUnificacion = (float)($brGlobal['unificacion_excluida'] ?? 0);
$ingrCargosAdic  = (float)($brGlobal['cargos_adicionales']   ?? 0);
$ingrExcedRec    = (float)($brGlobal['excedente_recuperado'] ?? 0);
$ingrOtrosDet    = (array)($brGlobal['otros_detalle']        ?? []);

// ── Gastos operativos ─────────────────────────────────────────────────────────
$gastosDetalle = (array)($brGlobal['gastos_detalle'] ?? []);
arsort($gastosDetalle);
$gastosOpTotal = (float)($brGlobal['gastos_operativos'] ?? array_sum($gastosDetalle));
$gastosTopN    = array_slice($gastosDetalle, 0, 10, true);
$gastosOtros   = array_sum(array_slice($gastosDetalle, 10, null, true));

// ── Nómina ─────────────────────────────────────────────────────────────────────
$nomNomina    = (float)($brGlobal['nomina_total']     ?? 0);
$nomComis     = (float)($brGlobal['comisiones']       ?? 0);
$nomVac       = (float)($brGlobal['vacaciones']       ?? 0);
$nomPrimaVac  = (float)($brGlobal['prima_vacacional'] ?? 0);
$nomBonos     = (float)($brGlobal['bonos']            ?? 0);
$nomDetalle   = (array)($brGlobal['nomina_detalle']   ?? []);
$nomDetalleOrder = [
    'IMSS','IMSS Patronal','Descuentos Infonavit','Finiquito','Gastos médicos',
    'Gasolina','Financiamiento de Motos','Financiamiento de Motos (desc.)',
    'Descuento Servicios Moto','Financiamiento Celular','Cascos',
    'Descuento de uniformes','Pensión Alimenticia','Préstamo Personal',
    'Anticipo de nómina',
];
$nomDisplay = [
    'Nómina' => $nomNomina, 'Comisiones' => $nomComis, 'Vacaciones' => $nomVac,
    'Prima vacacional' => $nomPrimaVac, 'Bonos' => $nomBonos,
];
foreach ($nomDetalleOrder as $dk) {
    if (isset($nomDetalle[$dk]) && $nomDetalle[$dk] > 0) $nomDisplay[$dk] = $nomDetalle[$dk];
}
foreach ($nomDetalle as $dk => $dv) {
    if (!isset($nomDisplay[$dk]) && $dv > 0) $nomDisplay[$dk] = $dv;
}
$nomTotal = array_sum(array_filter($nomDisplay, fn($v) => $v > 0));

// ── Préstamos intersucursales ──────────────────────────────────────────────────
$fondeoTotal = (float)($brGlobal['prestamos_fondea'] ?? ($snap['sections']['interbranch_loans']['total'] ?? 0));
$excedentes  = (float)($brGlobal['excedentes']       ?? 0);

// ── Mora buckets ───────────────────────────────────────────────────────────────
$mora0_30   = (float)($brGlobal['mora_0_30']    ?? 0);
$mora31_60  = (float)($brGlobal['mora_31_60']   ?? 0);
$mora61_90  = (float)($brGlobal['mora_61_90']   ?? 0);
$mora91_120 = (float)($brGlobal['mora_91_120']  ?? 0);
$mora120p   = (float)($brGlobal['mora_120_plus']?? 0);
$moraTotal  = $mora0_30 + $mora31_60 + $mora61_90 + $mora91_120 + $mora120p;
$cartera    = (float)($brGlobal['valor_cartera']  ?? $sum['portfolio_total']);
$colocacion = (float)($brGlobal['colocacion']     ?? $sum['placement_total']);
$recTotal   = (float)($brGlobal['recuperacion_total'] ?? $sum['recovery_total']);
$moraPct    = $cartera > 0 ? round($moraTotal / $cartera * 100, 2) : 0.0;

$moraBuckets = [
    ['label' => 'Mora 1-30',   'valor' => $mora0_30],
    ['label' => 'Mora 31-60',  'valor' => $mora31_60],
    ['label' => 'Mora 61-90',  'valor' => $mora61_90],
    ['label' => 'Mora 91-120', 'valor' => $mora91_120],
    ['label' => 'Mora 120+',   'valor' => $mora120p],
];
$moraBucketMax = max(array_column($moraBuckets, 'valor')) ?: 1.0;

// ── EBITDA global ─────────────────────────────────────────────────────────────
$noiDeducLabels = ['Descuentos Infonavit','Pensión Alimenticia','Descuento Servicios Moto',
    'Financiamiento de Motos (desc.)','Préstamo Personal','Subsidio para el Empleo APL',
    'Otros descuentos NOI','Descuento de uniformes'];
$nomDescuentosNOI = 0.0;
foreach ($noiDeducLabels as $lbl) { $nomDescuentosNOI += (float)($nomDetalle[$lbl] ?? 0); }
$nomPercep      = $nomNomina + $nomComis + $nomVac + $nomPrimaVac + $nomBonos;
$nomExtraExp    = 0.0;
foreach ($nomDetalle as $dk => $dv) {
    if (!in_array($dk, $noiDeducLabels, true)) { $nomExtraExp += max(0.0, (float)$dv); }
}
$nomNeto        = $nomPercep + $nomExtraExp - $nomDescuentosNOI;
$gastosTotal    = $gastosOpTotal + $nomNeto;
// EBITDA = Recuperación/Cobranza − Colocación − Gastos totales.
// Misma fórmula que Excel (RadiographyWorkbookBuilder::buildGlobalSheet).
$utilidad       = $recTotal - $colocacion - $gastosTotal;

// ── Categoría por EBITDA — fórmula centralizada (idéntica al Excel) ───────────
// EBITDA = Recuperación − Colocación − OPEX − Nómina
$categorias = [];
foreach ($brBranches as $b) {
    $bRec  = (float)($b['recuperacion_total'] ?? 0);
    $bCol  = (float)($b['colocacion'] ?? 0);
    $bGas  = (float)($b['gastos_operativos'] ?? 0);
    $bUtil = \App\Services\Radiography\RadiographyStyleHelper::branchEbitdaEstimate($b);
    $categorias[] = [
        'nombre'       => $b['sucursal'],
        'recuperacion' => $bRec,
        'colocacion'   => $bCol,
        'gastos'       => $bGas,
        'nomina'       => $bRec - $bCol - $bGas - $bUtil,
        'ebitda'       => $bUtil,
        'categoria'    => \App\Services\Radiography\RadiographyStyleHelper::ebitdaCategory($bUtil),
    ];
}
usort($categorias, fn($x, $y) => strcmp($x['nombre'], $y['nombre']));

// ── Resumen por sucursal (página 2) ────────────────────────────────────────────
$sucursalRows = [];
foreach ($brBranches as $b) {
    $moraSumB = (float)($b['mora_0_30']??0)+(float)($b['mora_31_60']??0)+(float)($b['mora_61_90']??0)+(float)($b['mora_91_120']??0)+(float)($b['mora_120_plus']??0);
    $cartValB = (float)($b['valor_cartera']??0);
    $bNomB    = (float)($b['nomina_total']??0)+(float)($b['comisiones']??0)+(float)($b['bonos']??0)
              + (float)($b['vacaciones']??0)+(float)($b['prima_vacacional']??0)
              + array_sum((array)($b['nomina_detalle']??[]));
    $sucursalRows[] = [
        'sucursal'     => $b['sucursal'],
        'recuperacion' => (float)($b['recuperacion_total']??0),
        'colocacion'   => (float)($b['colocacion']??0),
        'cartera'      => $cartValB,
        'vencida'      => $moraSumB,
        'mora_pct'     => $cartValB > 0 ? round($moraSumB / $cartValB * 100, 2) : 0,
        'gastos'       => (float)($b['gastos_operativos']??0),
        'nomina'       => $bNomB,
        'ebitda'       => \App\Services\Radiography\RadiographyStyleHelper::branchEbitdaEstimate($b),
    ];
}
usort($sucursalRows, fn($x, $y) => strcmp($x['sucursal'], $y['sucursal']));

// ── Top sucursales con más cartera vencida (página 3) ─────────────────────────
$topVencida = array_filter($sucursalRows, fn($r) => $r['vencida'] > 0);
usort($topVencida, fn($x, $y) => $y['vencida'] <=> $x['vencida']);
$topVencida    = array_slice($topVencida, 0, 8);
$topVencidaMax = !empty($topVencida) ? max(array_column($topVencida, 'vencida')) : 1.0;

// ── Colocación por sucursal (página 4) ────────────────────────────────────────
$colocPorSucursal = array_filter(array_map(fn($r) => ['sucursal' => $r['sucursal'], 'monto' => $r['colocacion']], $sucursalRows), fn($r) => $r['monto'] > 0);
usort($colocPorSucursal, fn($x, $y) => $y['monto'] <=> $x['monto']);
$colocMax = !empty($colocPorSucursal) ? max(array_column($colocPorSucursal, 'monto')) : 1.0;
$productosRows = $snap['sections']['products'] ?? [];

// ── Gastos por sucursal (página 5) ────────────────────────────────────────────
$gastosPorSucursal = array_filter(array_map(fn($r) => ['sucursal' => $r['sucursal'], 'monto' => $r['gastos']], $sucursalRows), fn($r) => $r['monto'] > 0);
usort($gastosPorSucursal, fn($x, $y) => $y['monto'] <=> $x['monto']);
$gastosPorSucursalMax = !empty($gastosPorSucursal) ? max(array_column($gastosPorSucursal, 'monto')) : 1.0;

// ── Nómina por sucursal — resumen ejecutivo (página 6) ────────────────────────
$nomPorSucursal = [];
foreach ($brBranches as $b) {
    $bDetalle    = (array)($b['nomina_detalle'] ?? []);
    $bDescuentos = 0.0;
    foreach ($noiDeducLabels as $lbl) { $bDescuentos += (float)($bDetalle[$lbl] ?? 0); }
    $bSueldos    = (float)($b['nomina_total'] ?? 0);
    $bComisiones = (float)($b['comisiones'] ?? 0);
    $bBonos      = (float)($b['bonos'] ?? 0);
    $bOtros      = (float)($b['vacaciones'] ?? 0) + (float)($b['prima_vacacional'] ?? 0);
    foreach ($bDetalle as $dk => $dv) {
        if (!in_array($dk, $noiDeducLabels, true)) { $bOtros += max(0.0, (float)$dv); }
    }
    $bTotalBruto = $bSueldos + $bComisiones + $bBonos + $bOtros;
    if ($bTotalBruto <= 0) continue;
    $nomPorSucursal[] = [
        'sucursal' => $b['sucursal'], 'sueldos' => $bSueldos, 'comisiones' => $bComisiones,
        'bonos' => $bBonos, 'otros' => $bOtros, 'descuentos' => $bDescuentos,
        'total' => $bTotalBruto, 'neto' => $bTotalBruto - $bDescuentos,
    ];
}
usort($nomPorSucursal, fn($x, $y) => strcmp($x['sucursal'], $y['sucursal']));

// ── Préstamos activos — resumen por sucursal (página 7) ───────────────────────
$activeLoans = $snap['sections']['active_loans'] ?? [];
$activeLoansByBranch = [];
foreach ($activeLoans as $al) {
    $alBranchKey = $al['sucursal'] ?? 'Sin sucursal';
    if (!isset($activeLoansByBranch[$alBranchKey])) {
        $activeLoansByBranch[$alBranchKey] = ['count' => 0, 'saldo' => 0.0, 'vencido' => 0.0];
    }
    $activeLoansByBranch[$alBranchKey]['count']++;
    $activeLoansByBranch[$alBranchKey]['saldo']   += (float)($al['saldo_activo'] ?? 0);
    $activeLoansByBranch[$alBranchKey]['vencido'] += (float)($al['vencido'] ?? 0);
}
ksort($activeLoansByBranch);
$alTotalCount   = array_sum(array_column($activeLoansByBranch, 'count'));
$alTotalSaldo   = array_sum(array_column($activeLoansByBranch, 'saldo'));
$alTotalVencido = array_sum(array_column($activeLoansByBranch, 'vencido'));
@endphp

<!-- ═══════════════════════════════════════════════════════════════════════
     PÁGINA 1 — RESUMEN EJECUTIVO
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="brand">
    <div class="brand-mark">MR LANA</div>
    <div class="brand-sub">Radiografía Financiera</div>
    <div class="brand-meta">
        <b>Periodo:</b> {{ strtoupper($period->label) }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <b>Fecha de generación:</b> {{ $snap['generated_at'] }}
    </div>
</div>

<table class="kpi-grid avoid">
    <tr>
        <td class="kpi"><div class="kpi-label">Recuperación / Cobranza</div><div class="kpi-value">{{ $fmt0($recTotal) }}</div></td>
        <td class="kpi"><div class="kpi-label">Colocación</div><div class="kpi-value">{{ $fmt0($colocacion) }}</div></td>
        <td class="kpi"><div class="kpi-label">Cartera</div><div class="kpi-value">{{ $fmt0($cartera) }}</div></td>
        <td class="kpi"><div class="kpi-label">Cartera vencida</div><div class="kpi-value @if($moraPct > 25) neg @endif">{{ $fmt0($moraTotal) }}</div></td>
    </tr>
    <tr>
        <td class="kpi"><div class="kpi-label">Gastos</div><div class="kpi-value">{{ $fmt0($gastosOpTotal) }}</div></td>
        <td class="kpi"><div class="kpi-label">Nómina y Capital Humano</div><div class="kpi-value">{{ $fmt0($nomTotal) }}</div></td>
        <td class="kpi"><div class="kpi-label">EBITDA</div><div class="kpi-value @if($utilidad < 0) neg @endif">{{ $fmt0($utilidad) }}</div></td>
        <td class="kpi"><div class="kpi-label">Mora %</div><div class="kpi-value @if($moraPct > 25) neg @endif">{{ $fmtp($moraPct) }}</div></td>
    </tr>
    <tr>
        <td class="kpi"><div class="kpi-label">Percepciones</div><div class="kpi-value">{{ $fmt0((float)($snap['summary']['noi_percepciones'] ?? 0)) }}</div></td>
        <td class="kpi"><div class="kpi-label">Deducciones</div><div class="kpi-value">{{ $fmt0((float)($snap['summary']['noi_deducciones'] ?? 0)) }}</div></td>
        <td class="kpi" colspan="2"><div class="kpi-label">Neto pagado a trabajadores</div><div class="kpi-value">{{ $fmt0((float)($snap['summary']['noi_neto_pagado'] ?? 0)) }}</div></td>
    </tr>
</table>

<table class="layout2 avoid" style="margin-top:12px;">
    <tr>
        <td class="colL">
            <div class="section-bar alt">Resumen ejecutivo</div>
            <div style="font-size:7.8pt; line-height:1.55; color:#334155;">
                La cartera total del periodo es de <b>{{ $fmt0($cartera) }}</b>, con una cartera vencida de
                <b>{{ $fmt0($moraTotal) }}</b> ({{ $fmtp($moraPct) }} de la cartera).
                La recuperación/cobranza alcanzó <b>{{ $fmt0($recTotal) }}</b> contra una colocación de
                <b>{{ $fmt0($colocacion) }}</b> en el periodo.
                Considerando gastos operativos (<b>{{ $fmt0($gastosOpTotal) }}</b>) y nómina
                (<b>{{ $fmt0($nomTotal) }}</b>), el EBITDA del periodo es de
                <b>{{ $fmt0($utilidad) }}</b>.
            </div>
        </td>
        <td class="colR">
            <div class="section-bar alt">Estado general</div>
            <div class="ok-box">Excedente enviado a corporativo: {{ $fmt0($excedentes) }}</div>
            <div style="font-size:7.6pt; color:#475569; margin-top:6px;">
                Préstamos intersucursales (fondea): <b>{{ $fmt0($fondeoTotal) }}</b>
            </div>
        </td>
    </tr>
</table>

<!-- ═══ CATEGORÍA POR EBITDA ══════════════════════════════════════════════ -->
@if(!empty($categorias))
<div class="section-bar">Categoría por EBITDA</div>
<table class="tbl">
    <thead>
        <tr>
            <th>Sucursal</th>
            <th class="r">Recuperación</th>
            <th class="r">Colocación</th>
            <th class="r">OPEX</th>
            <th class="r">Nómina</th>
            <th class="r">EBITDA estimado</th>
            <th class="c">Categoría</th>
        </tr>
    </thead>
    <tbody>
        @foreach($categorias as $c)
        <tr>
            <td class="b">{{ $c['nombre'] }}</td>
            <td class="r">{{ $fmt($c['recuperacion']) }}</td>
            <td class="r">{{ $fmt($c['colocacion']) }}</td>
            <td class="r">{{ $fmt($c['gastos']) }}</td>
            <td class="r">{{ $fmt($c['nomina']) }}</td>
            <td class="r b" @if($c['ebitda'] < 0) style="color:#b91c1c;" @endif>{{ $fmt($c['ebitda']) }}</td>
            <td class="c"><span class="badge {{ $cat($c['categoria']) }}">{{ $c['categoria'] }}</span></td>
        </tr>
        @endforeach
    </tbody>
</table>
<div class="note">EBITDA = Recuperación − Colocación − OPEX − Nómina completa estimada por sucursal. Categorías: Diamante ≥$1M / Máster ≥$600K / Sénior ≥$300K / Júnior ≥$100K / Mantenido &lt;$100K.</div>
@endif

<!-- ═══════════════════════════════════════════════════════════════════════
     PÁGINA 2 — RESUMEN POR SUCURSAL
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="pagebreak"></div>
<div class="section-bar">Resumen por sucursal</div>
@if(!empty($sucursalRows))
<table class="tbl">
    <thead>
        <tr>
            <th>Sucursal</th>
            <th class="r">Recuperación</th>
            <th class="r">Colocación</th>
            <th class="r">Cartera</th>
            <th class="r">Vencida</th>
            <th class="r">Mora %</th>
            <th class="r">Gastos</th>
            <th class="r">Nómina</th>
            <th class="r">EBITDA</th>
        </tr>
    </thead>
    <tbody>
        @foreach($sucursalRows as $r)
        <tr>
            <td class="b">{{ $r['sucursal'] }}</td>
            <td class="r">{{ $fmt($r['recuperacion']) }}</td>
            <td class="r">{{ $fmt($r['colocacion']) }}</td>
            <td class="r">{{ $fmt($r['cartera']) }}</td>
            <td class="r" @if($r['vencida'] > 0) style="color:#b91c1c;" @endif>{{ $fmt($r['vencida']) }}</td>
            <td class="r" @if($r['mora_pct'] > 25) style="color:#b91c1c;font-weight:bold;" @endif>{{ $fmtp($r['mora_pct']) }}</td>
            <td class="r">{{ $fmt($r['gastos']) }}</td>
            <td class="r">{{ $fmt($r['nomina']) }}</td>
            <td class="r b" @if($r['ebitda'] < 0) style="color:#b91c1c;" @endif>{{ $fmt($r['ebitda']) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>GLOBAL ({{ count($sucursalRows) }} sucursales)</td>
            <td class="r">{{ $fmt($recTotal) }}</td>
            <td class="r">{{ $fmt($colocacion) }}</td>
            <td class="r">{{ $fmt($cartera) }}</td>
            <td class="r">{{ $fmt($moraTotal) }}</td>
            <td class="r">{{ $fmtp($moraPct) }}</td>
            <td class="r">{{ $fmt($gastosOpTotal) }}</td>
            <td class="r">{{ $fmt($nomTotal) }}</td>
            <td class="r">{{ $fmt($utilidad) }}</td>
        </tr>
    </tfoot>
</table>
@else
<div class="note">Sin datos por sucursal para este periodo.</div>
@endif

<!-- ═══════════════════════════════════════════════════════════════════════
     PÁGINA 3 — MORA Y CARTERA
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="pagebreak"></div>
<div class="section-bar">Mora por bucket</div>
<table class="layout2 avoid">
    <tr>
        <td class="colL">
            @foreach($moraBuckets as $mb)
            <div class="bar-row">
                <div class="bar-label">{{ $mb['label'] }}</div>
                <div class="bar-track"><span class="bar-fill bar-fill-red" style="width:{{ $moraBucketMax > 0 ? min(100, round($mb['valor'] / $moraBucketMax * 100)) : 0 }}%;"></span></div>
                <div class="bar-value">{{ $fmt0($mb['valor']) }}</div>
            </div>
            @endforeach
        </td>
        <td class="colR">
            <div class="bar-row">
                <div class="bar-label">Cartera total</div>
                <div class="bar-track"><span class="bar-fill bar-fill-teal" style="width:100%;"></span></div>
                <div class="bar-value">{{ $fmt0($cartera) }}</div>
            </div>
            <div class="bar-row">
                <div class="bar-label">Cartera vencida</div>
                <div class="bar-track"><span class="bar-fill bar-fill-red" style="width:{{ $cartera > 0 ? min(100, round($moraTotal / $cartera * 100)) : 0 }}%;"></span></div>
                <div class="bar-value">{{ $fmt0($moraTotal) }} ({{ $fmtp($moraPct) }})</div>
            </div>
        </td>
    </tr>
</table>

@if(!empty($snap['sections']['portfolio_buckets']))
<div class="section-bar alt">Distribución de cartera por días vencidos</div>
<table class="tbl">
    <thead><tr><th>Bucket</th><th class="r">Contratos</th><th class="r">Balance</th><th class="r">Vencido</th></tr></thead>
    <tbody>
        @foreach($snap['sections']['portfolio_buckets'] as $b)
        <tr>
            <td class="b">{{ $b['label'] }}</td>
            <td class="r">{{ $fmtn($b['contratos']) }}</td>
            <td class="r">{{ $fmt($b['balance']) }}</td>
            <td class="r" @if($b['vencida'] > 0 && $b['label'] !== 'Al corriente') style="color:#b91c1c;" @endif>{{ $fmt($b['vencida']) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

@if(!empty($topVencida))
<div class="section-bar alt">Top sucursales con más cartera vencida</div>
@foreach($topVencida as $tv)
<div class="bar-row">
    <div class="bar-label">{{ $tv['sucursal'] }}</div>
    <div class="bar-track"><span class="bar-fill bar-fill-red" style="width:{{ $topVencidaMax > 0 ? min(100, round($tv['vencida'] / $topVencidaMax * 100)) : 0 }}%;"></span></div>
    <div class="bar-value">{{ $fmt0($tv['vencida']) }} &nbsp;·&nbsp; Mora {{ $fmtp($tv['mora_pct']) }}</div>
</div>
@endforeach
@endif

<!-- ═══════════════════════════════════════════════════════════════════════
     PÁGINA 4 — INGRESOS / COBRANZA Y COLOCACIÓN
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="pagebreak"></div>
<div class="section-bar">Ingresos / Recuperación</div>

{{-- A) Desglose por componente --}}
@if($ingrCapital > 0 || $ingrInteres > 0)
<div class="section-bar alt">A) Desglose por componente</div>
<table class="tbl avoid">
    <thead><tr><th>Componente</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @if($ingrCapital > 0)<tr><td>Capital recuperado</td><td class="r">{{ $fmt($ingrCapital) }}</td></tr>@endif
        @if($ingrInteres > 0)<tr><td>Intereses</td><td class="r">{{ $fmt($ingrInteres) }}</td></tr>@endif
        @if($ingrImpuesto > 0)<tr><td>Impuestos</td><td class="r">{{ $fmt($ingrImpuesto) }}</td></tr>@endif
        @if($ingrCharges > 0)<tr><td>Moratorios / Multas</td><td class="r">{{ $fmt($ingrCharges) }}</td></tr>@endif
        @if($ingrCargosIni > 0)<tr><td>Cargos al inicio</td><td class="r">{{ $fmt($ingrCargosIni) }}</td></tr>@endif
        @if($ingrComAper > 0)<tr><td>Comisión por apertura</td><td class="r">{{ $fmt($ingrComAper) }}</td></tr>@endif
        @if($ingrCargosAdic > 0)<tr><td>Cargos adicionales</td><td class="r">{{ $fmt($ingrCargosAdic) }}</td></tr>@endif
        @if($ingrExcedRec > 0)<tr><td>Excedentes recuperados</td><td class="r">{{ $fmt($ingrExcedRec) }}</td></tr>@endif
        @foreach($ingrOtrosDet as $otrosLabel => $otrosVal)
            @if($otrosVal != 0)<tr><td>{{ $otrosLabel }}</td><td class="r">{{ $fmt($otrosVal) }}</td></tr>@endif
        @endforeach
    </tbody>
    <tfoot><tr><td><b>Total Recuperación</b></td><td class="r">{{ $fmt($ingrTotal) }}</td></tr></tfoot>
</table>
@endif

{{-- B) Desglose por sucursal --}}
@if(!empty($brBranches))
<div class="section-bar alt">B) Desglose por sucursal</div>
<table class="tbl avoid">
    <thead>
        <tr>
            <th>Sucursal</th>
            <th class="r">Capital</th>
            <th class="r">Intereses</th>
            <th class="r">Impuestos</th>
            <th class="r">Moratorios</th>
            <th class="r">Cargos adic.</th>
            <th class="r">Cargos inicio</th>
            <th class="r">Com. apertura</th>
            <th class="r">Conceptos adic.</th>
            <th class="r">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($brBranches as $bi => $bb)
        @php
            $bbOtros = (float)($bb['excedente_recuperado'] ?? 0) + (float)($bb['otros_recuperacion'] ?? 0);
        @endphp
        <tr @if($bi % 2 === 1) style="background:#f8fafc;" @endif>
            <td class="b">{{ $bb['sucursal'] }}</td>
            <td class="r">{{ $fmt((float)($bb['capital_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($bb['interes_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($bb['impuesto_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($bb['charges'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($bb['cargos_adicionales'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($bb['cargos_inicio'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($bb['comision_apertura'] ?? 0)) }}</td>
            <td class="r">{{ $fmt($bbOtros) }}</td>
            <td class="r b">{{ $fmt((float)($bb['recuperacion_total'] ?? 0)) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        @php
            $globalOtros = (float)($brGlobal['excedente_recuperado'] ?? 0) + (float)($brGlobal['otros_recuperacion'] ?? 0);
        @endphp
        <tr>
            <td>TOTAL</td>
            <td class="r">{{ $fmt((float)($brGlobal['capital_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['interes_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['impuesto_recuperado'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['charges'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['cargos_adicionales'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['cargos_inicio'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($brGlobal['comision_apertura'] ?? 0)) }}</td>
            <td class="r">{{ $fmt($globalOtros) }}</td>
            <td class="r">{{ $fmt($ingrTotal) }}</td>
        </tr>
    </tfoot>
</table>
@endif

{{-- C) Recuperación por producto --}}
@php $recoveryByProduct = $snap['sections']['recovery_by_product']['rows'] ?? []; @endphp
@if(!empty($recoveryByProduct))
<div class="section-bar alt">C) Recuperación por producto</div>
<table class="tbl avoid">
    <thead>
        <tr>
            <th>Producto</th>
            <th class="r">Capital</th>
            <th class="r">Intereses</th>
            <th class="r">Impuestos</th>
            <th class="r">Moratorios</th>
            <th class="r">Cargos adic.</th>
            <th class="r">Com. apertura</th>
            <th class="r">Conceptos adic.</th>
            <th class="r">Total</th>
        </tr>
    </thead>
    <tbody>
        @foreach($recoveryByProduct as $pi => $pr)
        <tr @if($pi % 2 === 1) style="background:#f8fafc;" @endif>
            <td class="b">{{ $pr['product'] }}</td>
            <td class="r">{{ $fmt((float)($pr['capital'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($pr['interes'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($pr['impuesto'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($pr['moratorios'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($pr['cargos_adicionales'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($pr['comision_apertura'] ?? 0)) }}</td>
            <td class="r">{{ $fmt((float)($pr['otros'] ?? 0)) }}</td>
            <td class="r b">{{ $fmt((float)($pr['total'] ?? 0)) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>TOTAL</td>
            <td colspan="6"></td>
            <td></td>
            <td class="r">{{ $fmt($ingrTotal) }}</td>
        </tr>
    </tfoot>
</table>
@endif

{{-- Seguros y coberturas canalizadas — informativo, no afecta recuperación, OPEX, nómina ni EBITDA --}}
@if($ingrCrece > 0 || $ingrSavehearts > 0 || $ingrComadres > 0)
<div class="section-bar alt">Seguros y coberturas canalizadas</div>
<table class="tbl avoid">
    <thead><tr><th>Concepto</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @if($ingrSavehearts > 0)<tr><td>Cobertura Savehearts</td><td class="r">{{ $fmt($ingrSavehearts) }}</td></tr>@endif
        @if($ingrComadres > 0)<tr style="background:#f8fafc;"><td>Cobertura Crédito Grupal / Comadres</td><td class="r">{{ $fmt($ingrComadres) }}</td></tr>@endif
        @if($ingrCrece > 0)<tr><td>Seguro CRECE total</td><td class="r">{{ $fmt($ingrCrece) }}</td></tr>@endif
        @if($ingrCrece30 > 0)<tr style="background:#f8fafc;"><td>&nbsp;&nbsp;Reconocido como ingreso MR Lana (30%)</td><td class="r" style="color:#065f46;">{{ $fmt($ingrCrece30) }}</td></tr>@endif
        @if($ingrCrece70 > 0)<tr><td>&nbsp;&nbsp;Canalizado a aseguradora (70%)</td><td class="r">{{ $fmt($ingrCrece70) }}</td></tr>@endif
    </tbody>
    <tfoot><tr><td><b>Total canalizado a aseguradora</b></td><td class="r">{{ $fmt($ingrCanalizadoAseguradora) }}</td></tr></tfoot>
</table>
@endif

{{-- Colocación: info complementaria --}}
<div class="section-bar alt">Colocación del periodo (informativo)</div>
<table class="tbl avoid">
    <thead><tr><th>Concepto</th><th class="r">Monto</th></tr></thead>
    <tbody>
        <tr><td>Préstamos intersucursales (fondea)</td><td class="r">{{ $fmt($fondeoTotal) }}</td></tr>
    </tbody>
    <tfoot><tr><td>Total colocación</td><td class="r">{{ $fmt($colocacion) }}</td></tr></tfoot>
</table>

@if(!empty($productosRows))
<div class="section-bar alt">Colocación por producto</div>
<table class="tbl">
    <thead><tr><th>Producto</th><th class="r">Operaciones</th><th class="r">Colocación</th><th class="r">Recuperación</th></tr></thead>
    <tbody>
        @foreach($productosRows as $p)
        <tr>
            <td class="b">{{ $p['producto'] }}</td>
            <td class="r">{{ $fmtn($p['operaciones']) }}</td>
            <td class="r">{{ $fmt($p['colocacion']) }}</td>
            <td class="r">{{ $fmt($p['recuperacion'] ?? 0) }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

@if(!empty($colocPorSucursal))
<div class="section-bar alt">Colocación por sucursal</div>
@foreach($colocPorSucursal as $cp)
<div class="bar-row">
    <div class="bar-label">{{ $cp['sucursal'] }}</div>
    <div class="bar-track"><span class="bar-fill bar-fill-blue" style="width:{{ $colocMax > 0 ? min(100, round($cp['monto'] / $colocMax * 100)) : 0 }}%;"></span></div>
    <div class="bar-value">{{ $fmt0($cp['monto']) }}</div>
</div>
@endforeach
@endif

<!-- ═══════════════════════════════════════════════════════════════════════
     PÁGINA 5 — GASTOS
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="pagebreak"></div>
<div class="section-bar">Gastos operativos</div>

@if(!empty($gastosTopN))
<div class="section-bar alt">Top gastos por categoría</div>
<table class="tbl">
    <thead><tr><th>Categoría</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @foreach($gastosTopN as $concepto => $monto)
        <tr><td class="b">{{ $concepto }}</td><td class="r">{{ $fmt((float)$monto) }}</td></tr>
        @endforeach
        @if($gastosOtros > 0)
        <tr><td>Resto de categorías (fuera del top 10)</td><td class="r">{{ $fmt($gastosOtros) }}</td></tr>
        @endif
    </tbody>
    <tfoot><tr><td>Total gastos operativos</td><td class="r">{{ $fmt($gastosOpTotal) }}</td></tr></tfoot>
</table>
@endif

@if(!empty($gastosPorSucursal))
<div class="section-bar alt">Gastos por sucursal</div>
@foreach($gastosPorSucursal as $gp)
<div class="bar-row">
    <div class="bar-label">{{ $gp['sucursal'] }}</div>
    <div class="bar-track"><span class="bar-fill bar-fill-teal" style="width:{{ $gastosPorSucursalMax > 0 ? min(100, round($gp['monto'] / $gastosPorSucursalMax * 100)) : 0 }}%;"></span></div>
    <div class="bar-value">{{ $fmt0($gp['monto']) }}</div>
</div>
@endforeach
@endif

<!-- ═══════════════════════════════════════════════════════════════════════
     PÁGINA 6 — NÓMINA
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="pagebreak"></div>
<div class="section-bar">Nómina y capital humano</div>
@if(!empty($nomPorSucursal))
<table class="tbl">
    <thead>
        <tr>
            <th>Sucursal</th>
            <th class="r">Sueldos</th>
            <th class="r">Comisiones</th>
            <th class="r">Bonos</th>
            <th class="r">Vac./Prima/Otras</th>
            <th class="r">Descuentos</th>
            <th class="r">Total nómina</th>
            <th class="r">Neto</th>
        </tr>
    </thead>
    <tbody>
        @foreach($nomPorSucursal as $n)
        <tr>
            <td class="b">{{ $n['sucursal'] }}</td>
            <td class="r">{{ $fmt($n['sueldos']) }}</td>
            <td class="r">{{ $fmt($n['comisiones']) }}</td>
            <td class="r">{{ $fmt($n['bonos']) }}</td>
            <td class="r">{{ $fmt($n['otros']) }}</td>
            <td class="r" @if($n['descuentos'] > 0) style="color:#b91c1c;" @endif>{{ $n['descuentos'] > 0 ? '-' . $fmt($n['descuentos']) : '—' }}</td>
            <td class="r b">{{ $fmt($n['total']) }}</td>
            <td class="r">{{ $fmt($n['neto']) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>TOTAL GLOBAL</td>
            <td class="r">{{ $fmt($nomNomina) }}</td>
            <td class="r">{{ $fmt($nomComis) }}</td>
            <td class="r">{{ $fmt($nomBonos) }}</td>
            <td class="r">{{ $fmt($nomTotal - $nomNomina - $nomComis - $nomBonos) }}</td>
            <td class="r">{{ $fmt($nomDescuentosNOI) }}</td>
            <td class="r">{{ $fmt($nomTotal) }}</td>
            <td class="r">{{ $fmt($nomNeto) }}</td>
        </tr>
    </tfoot>
</table>
<div class="note">Detalle completo por concepto disponible en el Excel (hoja NÓMINA). Este resumen es ejecutivo, por sucursal.</div>
@else
<div class="note">Sin datos de nómina por sucursal para este periodo.</div>
@endif

<!-- ═══════════════════════════════════════════════════════════════════════
     PÁGINA 7 — PRÉSTAMOS ACTIVOS
     ═══════════════════════════════════════════════════════════════════════ -->
<div class="pagebreak"></div>
<div class="section-bar">Préstamos activos — resumen por sucursal</div>
@if(!empty($activeLoansByBranch))
<table class="tbl">
    <thead>
        <tr>
            <th>Sucursal</th>
            <th class="r">Créditos activos</th>
            <th class="r">Saldo activo</th>
            <th class="r">Vencido</th>
            <th class="r">% Vencido</th>
        </tr>
    </thead>
    <tbody>
        @foreach($activeLoansByBranch as $alBranchName => $alData)
        @php $alPct = $alData['saldo'] > 0 ? round($alData['vencido'] / $alData['saldo'] * 100, 2) : 0; @endphp
        <tr>
            <td class="b">{{ $alBranchName }}</td>
            <td class="r">{{ $fmtn($alData['count']) }}</td>
            <td class="r">{{ $fmt($alData['saldo']) }}</td>
            <td class="r" @if($alData['vencido'] > 0) style="color:#b91c1c;" @endif>{{ $fmt($alData['vencido']) }}</td>
            <td class="r" @if($alPct > 25) style="color:#b91c1c;font-weight:bold;" @endif>{{ $fmtp($alPct) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td>TOTAL GENERAL</td>
            <td class="r">{{ $fmtn($alTotalCount) }}</td>
            <td class="r">{{ $fmt($alTotalSaldo) }}</td>
            <td class="r">{{ $fmt($alTotalVencido) }}</td>
            <td class="r">{{ $alTotalSaldo > 0 ? $fmtp(round($alTotalVencido / $alTotalSaldo * 100, 2)) : '0.00%' }}</td>
        </tr>
    </tfoot>
</table>
@else
<div class="note">Sin préstamos activos registrados para este periodo.</div>
@endif

<!-- ═══ Pie de página repetido + numeración (canvas nativo de DomPDF) ════════ -->
<script type="text/php">
if (isset($pdf)) {
    $footerFont  = $fontMetrics->getFont('Helvetica', 'normal');
    $footerSize  = 7.5;
    $footerColor = array(0.39, 0.45, 0.55);
    $marginX     = 40;
    $rightEdge   = 572;
    $lineY       = 758;
    $textY       = 765;

    $pdf->page_line($marginX, $lineY, $rightEdge, $lineY, $footerColor, 0.5);

    $leftText = "MR LANA · Radiografía Financiera · {{ strtoupper($period->label) }}";
    $pdf->page_text($marginX, $textY, $leftText, $footerFont, $footerSize, $footerColor);

    $rightText  = "Página {PAGE_NUM} de {PAGE_COUNT}";
    $rightWidth = $fontMetrics->getTextWidth($rightText, $footerFont, $footerSize);
    $pdf->page_text($rightEdge - $rightWidth, $textY, $rightText, $footerFont, $footerSize, $footerColor);
}
</script>

</body>
</html>
