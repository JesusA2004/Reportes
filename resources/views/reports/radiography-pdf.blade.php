<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Radiografía {{ $period->label }}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #1e293b; background: #fff; }
@page { margin: 14mm 12mm 14mm 12mm; }

.cover { background: #106A59; color: #fff; padding: 18px 20px 14px; margin-bottom: 16px; }
.cover-eye  { font-size: 7pt; color: #1DC1A2; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; }
.cover-title { font-size: 18pt; font-weight: bold; margin-top: 4px; }
.cover-sub  { font-size: 9pt; color: #d7f5ee; margin-top: 6px; line-height: 1.6; }

.section-title {
    background: #5B9BD5; color: #fff;
    padding: 5px 10px; font-size: 9pt; font-weight: bold;
    margin-top: 14px; margin-bottom: 0;
}
.section-sub {
    background: #F2F2F2; color: #1F2937;
    padding: 3px 10px; font-size: 8pt;
    margin-top: 8px; margin-bottom: 0;
}
.section-green { background: #106A59; color: #fff; padding: 5px 10px; font-size: 9pt; font-weight: bold; margin-top: 14px; }

.cards { width: 100%; margin: 10px 0; border-collapse: collapse; }
.card  { width: 25%; padding: 8px 10px; border: 1px solid #E3EFFF; background: #F2F2F2; vertical-align: top; }
.card-label { font-size: 7pt; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
.card-value { font-size: 13pt; font-weight: bold; color: #106A59; margin-top: 3px; }
.card-red   { color: #b91c1c !important; }

table.data { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
table.data th { background: #E3EFFF; color: #1F2937; font-weight: bold; text-align: left; padding: 5px 7px; border-bottom: 1.5px solid #5B9BD5; }
table.data td { padding: 4px 7px; border-bottom: 1px solid #E3EFFF; vertical-align: top; }
table.data tr:nth-child(even) td { background: #F2F2F2; }
table.data .r { text-align: right; }
table.data .c { text-align: center; }
table.data .b { font-weight: bold; }
table.data th.green { background: #106A59; color: #fff; }
.totals-row td { background: #106A59 !important; color: #fff !important; font-weight: bold !important; }

table.metric { width: 100%; border-collapse: collapse; font-size: 9pt; }
table.metric td { padding: 5px 8px; border-bottom: 1px solid #E3EFFF; }
table.metric tr:nth-child(even) td { background: #F2F2F2; }
table.metric td:first-child { font-weight: bold; width: 60%; }
table.metric td:last-child  { text-align: right; }

.bar-chart { width: 100%; margin: 6px 0; }
.bar-row { margin-bottom: 5px; }
.bar-label { font-size: 8pt; color: #334155; margin-bottom: 2px; white-space: nowrap; overflow: hidden; }
.bar-track { background: #E3EFFF; width: 100%; height: 12px; border-radius: 2px; }
.bar-fill  { background: #1DC1A2; height: 12px; border-radius: 2px; display: block; min-width: 2px; }
.bar-fill-red   { background: #b91c1c; }
.bar-fill-green { background: #106A59; }
.bar-value { font-size: 7.5pt; color: #475569; margin-top: 1px; text-align: right; }

.badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 7.5pt; font-weight: bold; }
.b-error   { background: #fee2e2; color: #991b1b; }
.b-warning { background: #fef3c7; color: #92400e; }
.b-info    { background: #dbeafe; color: #1e3a8a; }
.cat-senior    { background: #d1fae5; color: #065f46; font-weight: bold; }
.cat-junior    { background: #fef9c3; color: #78350f; font-weight: bold; }
.cat-mantenido { background: #fee2e2; color: #991b1b; font-weight: bold; }

.pb { page-break-before: always; }
.footer { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 7.5pt; color: #64748b; text-align: center; }
.obs-row td { padding: 8px 8px; color: #94a3b8; font-style: italic; }
</style>
</head>
<body>

@php
$snap = $snapshot;
$sum  = $snap['summary'];
$pay  = $snap['sections']['payroll'];
$fmt  = fn($v) => '$' . number_format((float)$v, 2);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$fmtn = fn($v) => number_format((float)$v, 0);

// ── Branch radiography — única fuente de verdad ──────────────────────────────
$brCalc     = $snap['branch_radiography'] ?? null;
$brBranches = $brCalc ? ($brCalc['branches'] ?? []) : [];
$brGlobal   = $brCalc ? ($brCalc['global']   ?? null) : null;

// ── Ingresos (sección 2) ─────────────────────────────────────────────────────
$ingrCapital   = (float)($brGlobal['capital_recuperado']  ?? 0);
$ingrInteres   = (float)($brGlobal['interes_recuperado']  ?? 0);
$ingrImpuesto  = (float)($brGlobal['impuesto_recuperado'] ?? 0);
$ingrMuletas   = (float)($brGlobal['charges']             ?? 0);
$ingrCargosIni = (float)($brGlobal['cargos_inicio']       ?? 0);
$ingrComAper   = (float)($brGlobal['comision_apertura']   ?? 0);
$ingrTotal     = (float)($brGlobal['recuperacion_total']  ?? $sum['recovery_total']);

// ── Gastos (sección 3) ───────────────────────────────────────────────────────
$gastosDetalle = (array)($brGlobal['gastos_detalle'] ?? []);
arsort($gastosDetalle);
$gastosOpTotal = (float)($brGlobal['gastos_operativos'] ?? array_sum($gastosDetalle));

// ── Nómina (sección 4) ───────────────────────────────────────────────────────
$nomNomina    = (float)($brGlobal['nomina_total']     ?? 0);
$nomComis     = (float)($brGlobal['comisiones']       ?? 0);
$nomVac       = (float)($brGlobal['vacaciones']       ?? 0);
$nomPrimaVac  = (float)($brGlobal['prima_vacacional'] ?? 0);
$nomBonos     = (float)($brGlobal['bonos']            ?? 0);
$nomDetalle   = (array)($brGlobal['nomina_detalle']   ?? []);
$nomDetalleOrder = [
    'IMSS','Descuentos Infonavit','Finiquito','Gastos médicos',
    'Gasolina','Financiamiento de Motos','Financiamiento de Motos (desc.)',
    'Descuento Servicios Moto','Financiamiento Celular','Cascos',
    'Descuento de uniformes','Pensión Alimenticia','Préstamo Personal',
    'Anticipo de nómina','Otros conceptos nómina','Otros descuentos NOI',
];
// Build ordered nomina display list
$nomDisplay = [
    'Nómina'           => $nomNomina,
    'Comisiones'       => $nomComis,
    'Vacaciones'       => $nomVac,
    'Prima vacacional' => $nomPrimaVac,
    'Bonos'            => $nomBonos,
];
foreach ($nomDetalleOrder as $dk) {
    if (isset($nomDetalle[$dk]) && $nomDetalle[$dk] > 0) {
        $nomDisplay[$dk] = $nomDetalle[$dk];
    }
}
foreach ($nomDetalle as $dk => $dv) {
    if (!isset($nomDisplay[$dk]) && $dv > 0) $nomDisplay[$dk] = $dv;
}
$nomTotal = array_sum(array_filter($nomDisplay, fn($v) => $v > 0));
// Real NOI concept codes, confirmed against BranchRadiographyCalculator::accumulateNomina().
$nomCodeLabels = [
    'Nómina'           => 'P001 SUELDO',
    'Comisiones'       => 'P002 COMISIONES',
    'Vacaciones'       => 'P009 VACACIONES',
    'Prima vacacional' => 'P010 PRIMA VACACIONAL',
    'Bonos'            => 'P1XX BONOS (CATEGORÍA / PRODUCTIVIDAD)',
];
$nomIsDeduction = fn($label) => (bool) preg_match('/Descuento|Pensión Alimenticia|Anticipo/i', $label);

// ── Préstamos intersucursales (sección 5) ────────────────────────────────────
$fondeoTotal = (float)($brGlobal['prestamos_fondea'] ?? ($snap['sections']['interbranch_loans']['total'] ?? 0));
$excedentes  = (float)($brGlobal['excedentes']       ?? 0);

// ── Mora buckets ─────────────────────────────────────────────────────────────
$mora0_30   = (float)($brGlobal['mora_0_30']    ?? 0);
$mora31_60  = (float)($brGlobal['mora_31_60']   ?? 0);
$mora61_90  = (float)($brGlobal['mora_61_90']   ?? 0);
$mora91_120 = (float)($brGlobal['mora_91_120']  ?? 0);
$mora120p   = (float)($brGlobal['mora_120_plus']?? 0);
$moraTotal  = $mora0_30 + $mora31_60 + $mora61_90 + $mora91_120 + $mora120p;
$cartera    = (float)($brGlobal['valor_cartera']  ?? $sum['portfolio_total']);
$colocacion = (float)($brGlobal['colocacion']     ?? $sum['placement_total']);
$recTotal   = (float)($brGlobal['recuperacion_total'] ?? $sum['recovery_total']);

// ── Utilidad (sección 8) ─────────────────────────────────────────────────────
// Descuentos NOI: al empleado, no son gasto adicional de la empresa
$noiDeducLabels = ['Descuentos Infonavit','Pensión Alimenticia','Descuento Servicios Moto',
    'Financiamiento de Motos (desc.)','Préstamo Personal','Subsidio para el Empleo APL',
    'Otros descuentos NOI','Descuento de uniformes'];
$nomDescuentosNOI = 0.0;
foreach ($noiDeducLabels as $lbl) { $nomDescuentosNOI += (float)($nomDetalle[$lbl] ?? 0); }
$nomPercepPdf   = $nomNomina + $nomComis + $nomVac + $nomPrimaVac + $nomBonos;
$nomExtraExpPdf = 0.0;
foreach ($nomDetalle as $dk => $dv) {
    if (!in_array($dk, $noiDeducLabels)) { $nomExtraExpPdf += max(0.0, (float)$dv); }
}
$nomNeto         = $nomPercepPdf + $nomExtraExpPdf - $nomDescuentosNOI;
$gastosTotal     = $gastosOpTotal + $nomNeto;
$utilidad        = $recTotal - $colocacion - $gastosTotal;
$inconsistencia  = $excedentes > $utilidad;
$diferencia      = $inconsistencia ? 0.0 : ($utilidad - $excedentes);

// ── Categorías sucursal ──────────────────────────────────────────────────────
$categorias = [];
foreach ($brBranches as $b) {
    $bNom  = (float)($b['nomina_total']??0)+(float)($b['comisiones']??0)+(float)($b['bonos']??0)
           + (float)($b['vacaciones']??0)+(float)($b['prima_vacacional']??0)
           + array_sum((array)($b['nomina_detalle']??[]));
    $bUtil = (float)($b['recuperacion_total']??0) - (float)($b['gastos_operativos']??0) - $bNom;
    $cat   = $bUtil >= 300000 ? 'SENIOR' : ($bUtil >= 100000 ? 'JUNIOR' : 'MANTENIDO');
    $categorias[] = ['nombre' => $b['sucursal'], 'utilidad' => $bUtil, 'categoria' => $cat];
}
@endphp

<!-- ═══ PORTADA ══════════════════════════════════════════════════════ -->
<div class="cover">
    <div class="cover-eye">Sistema de Reportes · Radiografía Financiera</div>
    <div class="cover-title">RADIOGRAFÍA — {{ strtoupper($period->label) }}</div>
    <div class="cover-sub">
        Periodo: {{ $snap['period']['start_date'] }} — {{ $snap['period']['end_date'] }}
        &nbsp;·&nbsp; Código: {{ $snap['period']['code'] ?? $period->id }}
        &nbsp;·&nbsp; Generado: {{ $snap['generated_at'] }}
    </div>
</div>

<!-- KPI Cards -->
<table class="cards">
    <tr>
        <td class="card"><div class="card-label">Recuperación</div><div class="card-value">{{ $fmt($recTotal) }}</div></td>
        <td class="card"><div class="card-label">Colocación</div><div class="card-value">{{ $fmt($colocacion) }}</div></td>
        <td class="card"><div class="card-label">Cartera</div><div class="card-value">{{ $fmt($cartera) }}</div></td>
        <td class="card"><div class="card-label">Mora</div><div class="card-value @if($cartera > 0 && $moraTotal/$cartera > 0.25) card-red @endif">{{ $fmt($moraTotal) }}</div></td>
    </tr>
    <tr>
        <td class="card"><div class="card-label">Gastos Op.</div><div class="card-value">{{ $fmt($gastosOpTotal) }}</div></td>
        <td class="card"><div class="card-label">Nómina</div><div class="card-value">{{ $fmt($nomTotal) }}</div></td>
        <td class="card"><div class="card-label">EBITDA</div><div class="card-value @if($utilidad < 0) card-red @endif">{{ $fmt($utilidad) }}</div></td>
        <td class="card"><div class="card-label">Empleados</div><div class="card-value">{{ $fmtn($sum['employees_count']) }}</div></td>
    </tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 1. MÉTRICAS GENERALES                                              -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">1. MÉTRICAS GENERALES</div>
<table class="metric">
    <tr><td>Valor cartera global</td><td>{{ $fmt($cartera) }}</td></tr>
    <tr><td>Otorgamientos</td><td>{{ $fmt($colocacion) }}</td></tr>
    <tr><td>Recuperación total</td><td>{{ $fmt($recTotal) }}</td></tr>
    <tr><td>Mora de 0 a 30 días</td><td>{{ $fmt($mora0_30) }}</td></tr>
    <tr><td>Mora de 31 a 60 días</td><td>{{ $fmt($mora31_60) }}</td></tr>
    <tr><td>Mora de 61 a 90 días</td><td>{{ $fmt($mora61_90) }}</td></tr>
    <tr><td>Mora de 91 a 120 días</td><td>{{ $fmt($mora91_120) }}</td></tr>
    <tr><td>Mora 120+ días</td><td>{{ $fmt($mora120p) }}</td></tr>
    <tr><td>Mora total</td><td @if($moraTotal > 0) style="color:#b91c1c;font-weight:bold;" @endif>{{ $fmt($moraTotal) }}</td></tr>
    @if($cartera > 0)<tr><td>Índice de mora</td><td>{{ $fmtp(round($moraTotal/$cartera*100,2)) }}</td></tr>@endif
    <tr><td>Envío de utilidad a corporativo</td><td>{{ $fmt($excedentes) }}</td></tr>
    <tr style="background:#334155;color:#fff;"><td style="background:#334155;color:#fff;font-weight:bold;">Total empleados</td><td style="background:#334155;color:#fff;text-align:right;">{{ $fmtn($sum['employees_count']) }}</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 2. INGRESOS                                                         -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">2. INGRESOS</div>
<table class="metric">
    @if($ingrCapital > 0)<tr><td>Capital por producto</td><td>{{ $fmt($ingrCapital) }}</td></tr>@endif
    @if($ingrInteres > 0)<tr><td>Intereses por producto</td><td>{{ $fmt($ingrInteres) }}</td></tr>@endif
    @if($ingrImpuesto > 0)<tr><td>Impuestos por producto</td><td>{{ $fmt($ingrImpuesto) }}</td></tr>@endif
    @if($ingrMuletas > 0)<tr><td>Multas por producto</td><td>{{ $fmt($ingrMuletas) }}</td></tr>@endif
    @if($ingrCargosIni > 0)<tr><td>Cargos al inicio</td><td>{{ $fmt($ingrCargosIni) }}</td></tr>@endif
    @if($ingrComAper > 0)<tr><td>Comisión por apertura</td><td>{{ $fmt($ingrComAper) }}</td></tr>@endif
    <tr><td>Ingreso de créditos vencidos (+90 días)</td><td>$0.00</td></tr>
    <tr class="totals-row"><td>Total Ingresos</td><td style="text-align:right;">{{ $fmt($ingrTotal) }}</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 3. GASTOS OPERATIVOS                                               -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">3. GASTOS OPERATIVOS</div>
<table class="metric">
    @foreach($gastosDetalle as $concept => $amount)
    @if((float)$amount > 0)
    <tr><td>{{ $concept }}</td><td>{{ $fmt((float)$amount) }}</td></tr>
    @endif
    @endforeach
    <tr class="totals-row"><td>Total Gastos Operativos</td><td style="text-align:right;">{{ $fmt($gastosOpTotal) }}</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 4. NÓMINA Y CAPITAL HUMANO                                         -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">4. NÓMINA Y CAPITAL HUMANO</div>
<table class="metric">
    @foreach($nomDisplay as $nomLabel => $nomVal)
    @if((float)$nomVal > 0)
    @php $nomIsDed = $nomIsDeduction($nomLabel); @endphp
    <tr>
        <td>{{ $nomCodeLabels[$nomLabel] ?? $nomLabel }}</td>
        <td style="@if($nomIsDed) color:#b91c1c; @endif">{{ $nomIsDed ? '-' . $fmt((float)$nomVal) : $fmt((float)$nomVal) }}</td>
    </tr>
    @endif
    @endforeach
    <tr class="totals-row"><td>Total Nómina y Capital Humano</td><td style="text-align:right;">{{ $fmt($nomTotal) }}</td></tr>
</table>
<div style="font-size:7.5pt;color:#64748b;margin-top:2px;">* Descuentos en rojo solo a modo informativo; el total es el monto bruto registrado (no resta descuentos).</div>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 5. PRÉSTAMOS INTERSUCURSALES                                       -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">5. PRÉSTAMOS INTERSUCURSALES</div>
<table class="metric">
    <tr><td>Activos (fondea)</td><td>{{ $fmt($fondeoTotal) }}</td></tr>
    <tr><td>Pasivos (recibe)</td><td>{{ $fmt($fondeoTotal) }}</td></tr>
    <tr class="totals-row"><td>Total</td><td style="text-align:right;">{{ $fmt($fondeoTotal) }}</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 6. ÍNDICE DE ROTACIÓN DE PERSONAL                                  -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">6. ÍNDICE DE ROTACIÓN DE PERSONAL</div>
<table class="metric">
    <tr><td>N° de personas que dejaron la empresa</td><td>—</td></tr>
    <tr><td>Promedio de personas en el periodo</td><td>{{ $fmtn($sum['employees_count']) }}</td></tr>
    <tr><td>Índice de rotación</td><td>—</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 7. ANÁLISIS DE TENDENCIAS Y PROYECCIONES                           -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">7. ANÁLISIS DE TENDENCIAS Y PROYECCIONES</div>
<table class="metric">
    <tr><td>Saldo inicial en caja</td><td>—</td></tr>
    <tr><td>Ingresos Totales</td><td>{{ $fmt($recTotal) }}</td></tr>
    <tr><td>Otorgamientos</td><td>{{ $fmt($colocacion) }}</td></tr>
    <tr><td>Gastos Totales</td><td>{{ $fmt($gastosTotal) }}</td></tr>
    <tr><td>EBITDA / Utilidad disponible</td><td @if($utilidad < 0) style="color:#b91c1c;font-weight:bold;" @endif>{{ $fmt($utilidad) }}</td></tr>
    <tr><td>Saldo final en caja</td><td>—</td></tr>
    <tr><td>Préstamos inter sucursales</td><td>{{ $fmt($fondeoTotal) }}</td></tr>
    <tr><td>Envío de utilidad a corporativo</td><td>{{ $fmt($excedentes) }}</td></tr>
    <tr><td>Diferencia / sobrante</td><td @if($inconsistencia) style="color:#b91c1c;font-weight:bold;" @endif>{{ $inconsistencia ? '—' : $fmt($diferencia) }}</td></tr>
    @if($inconsistencia)
    <tr style="background:#fef2f2;"><td colspan="2" style="color:#991b1b;font-weight:bold;font-size:8pt;">⚠ INCONSISTENCIA: El envío corporativo supera la utilidad disponible. Revisar clasificación de ingresos, gastos, otorgamientos o envío corporativo.</td></tr>
    @endif
    <tr><td>Mora de 0 a 30 días</td><td>{{ $fmt($mora0_30) }}</td></tr>
    <tr><td>Mora de 31 a 60 días</td><td>{{ $fmt($mora31_60) }}</td></tr>
    <tr><td>Mora de 61 a 90 días</td><td>{{ $fmt($mora61_90) }}</td></tr>
    <tr><td>Mora de 91 a 120 días</td><td>{{ $fmt($mora91_120) }}</td></tr>
    <tr><td>Mora 120+ días</td><td>{{ $fmt($mora120p) }}</td></tr>
    <tr><td>Valor cartera</td><td>{{ $fmt($cartera) }}</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 8. EBITDA                                                           -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">8. EBITDA / Utilidad disponible</div>
<table class="metric">
    <tr><td>Ingresos Totales</td><td>{{ $fmt($recTotal) }}</td></tr>
    <tr><td>Menos: Otorgamientos</td><td>{{ $fmt($colocacion) }}</td></tr>
    <tr><td>Menos: Gastos Totales</td><td>{{ $fmt($gastosTotal) }}</td></tr>
    <tr><td style="padding-left:16px;color:#64748b;">  Gastos operativos</td><td style="color:#64748b;">{{ $fmt($gastosOpTotal) }}</td></tr>
    <tr><td style="padding-left:16px;color:#64748b;">  Nómina neta</td><td style="color:#64748b;">{{ $fmt($nomNeto) }}</td></tr>
    <tr class="totals-row"><td>= EBITDA / Utilidad disponible</td><td style="text-align:right; @if($utilidad < 0) color:#f87171; @else color:#6ee7b7; @endif">{{ $fmt($utilidad) }}</td></tr>
    <tr><td>Envío utilidad a corporativo</td><td>{{ $fmt($excedentes) }}</td></tr>
    <tr><td>Diferencia / sobrante</td><td @if($inconsistencia) style="color:#b91c1c;font-weight:bold;" @endif>{{ $inconsistencia ? '$0.00' : $fmt($diferencia) }}</td></tr>
    @if($inconsistencia)
    <tr style="background:#fef2f2;"><td colspan="2" style="color:#991b1b;font-weight:bold;font-size:8pt;">⚠ INCONSISTENCIA: El envío corporativo supera la utilidad disponible.</td></tr>
    @endif
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 9. SALDO TOTAL ACUMULADO CUENTAS                                   -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">9. SALDO TOTAL ACUMULADO CUENTAS</div>
<table class="metric">
    <tr class="totals-row"><td>Total</td><td style="text-align:right;">—</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- 10. OBSERVACIONES Y NOTAS                                          -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
<div class="section-title">10. OBSERVACIONES Y NOTAS</div>
<table class="metric">
    <tr class="obs-row"><td>Comentarios sobre el desempeño financiero:</td><td>&nbsp;</td></tr>
    <tr class="obs-row"><td>Factores de riesgo y oportunidades:</td><td>&nbsp;</td></tr>
    <tr class="obs-row"><td>Recomendaciones para optimización financiera:</td><td>&nbsp;</td></tr>
</table>

<!-- ═══════════════════════════════════════════════════════════════════ -->
<!-- DESGLOSE POR SUCURSAL                                              -->
<!-- ═══════════════════════════════════════════════════════════════════ -->
@if(!empty($brBranches))
<div class="pb"></div>
<div class="section-title">DESGLOSE POR SUCURSAL</div>
<table class="data">
    <tr>
        <th>Sucursal</th>
        <th class="r">Recuperación</th>
        <th class="r">Colocación</th>
        <th class="r">Cartera</th>
        <th class="r">Mora $</th>
        <th class="r">Mora %</th>
        <th class="r">Gastos Op.</th>
        <th class="r">Nómina</th>
    </tr>
    @foreach($brBranches as $b)
    @php
    $moraSum = (float)($b['mora_0_30']??0)+(float)($b['mora_31_60']??0)+(float)($b['mora_61_90']??0)+(float)($b['mora_91_120']??0)+(float)($b['mora_120_plus']??0);
    $cartVal = (float)($b['valor_cartera']??0);
    $moraPct = $cartVal > 0 ? round($moraSum / $cartVal * 100, 2) : 0;
    $bNom    = (float)($b['nomina_total']??0)+(float)($b['comisiones']??0)+(float)($b['bonos']??0)
             + (float)($b['vacaciones']??0)+(float)($b['prima_vacacional']??0)
             + array_sum((array)($b['nomina_detalle']??[]));
    @endphp
    <tr>
        <td class="b">{{ $b['sucursal'] }}</td>
        <td class="r">{{ $fmt((float)($b['recuperacion_total']??0)) }}</td>
        <td class="r">{{ $fmt((float)($b['colocacion']??0)) }}</td>
        <td class="r">{{ $fmt($cartVal) }}</td>
        <td class="r" @if($moraSum > 0) style="color:#b91c1c;" @endif>{{ $fmt($moraSum) }}</td>
        <td class="r @if($moraPct > 25) card-red @endif">{{ $fmtp($moraPct) }}</td>
        <td class="r">{{ $fmt((float)($b['gastos_operativos']??0)) }}</td>
        <td class="r">{{ $fmt($bNom) }}</td>
    </tr>
    @endforeach
    @if($brGlobal)
    @php
    $gMoraSum = $mora0_30+$mora31_60+$mora61_90+$mora91_120+$mora120p;
    $gMoraPct = $cartera > 0 ? round($gMoraSum/$cartera*100,2) : 0;
    @endphp
    <tr class="totals-row">
        <td>GLOBAL (13 sucursales)</td>
        <td class="r">{{ $fmt($recTotal) }}</td>
        <td class="r">{{ $fmt($colocacion) }}</td>
        <td class="r">{{ $fmt($cartera) }}</td>
        <td class="r">{{ $fmt($gMoraSum) }}</td>
        <td class="r">{{ $fmtp($gMoraPct) }}</td>
        <td class="r">{{ $fmt($gastosOpTotal) }}</td>
        <td class="r">{{ $fmt($nomTotal) }}</td>
    </tr>
    @endif
</table>
@endif

<!-- ═══ CARTERA — BUCKETS ════════════════════════════════════════════ -->
@if(!empty($snap['sections']['portfolio_buckets']))
<div class="section-title">DISTRIBUCIÓN DE CARTERA POR DÍAS VENCIDOS</div>
<table class="data">
    <tr>
        <th>Bucket</th>
        <th class="r">Contratos</th>
        <th class="r">Balance</th>
        <th class="r">Vencido</th>
    </tr>
    @foreach($snap['sections']['portfolio_buckets'] as $b)
    <tr>
        <td class="b">{{ $b['label'] }}</td>
        <td class="r">{{ $fmtn($b['contratos']) }}</td>
        <td class="r">{{ $fmt($b['balance']) }}</td>
        <td class="r" @if($b['vencida'] > 0 && $b['label'] !== 'Al corriente') style="color:#b91c1c;" @endif>{{ $fmt($b['vencida']) }}</td>
    </tr>
    @endforeach
</table>
@endif

<!-- ═══ COLOCACIÓN POR PRODUCTO ══════════════════════════════════════ -->
@if(!empty($snap['charts']['placement_by_product']))
<div class="section-title">COLOCACIÓN POR PRODUCTO</div>
<div class="bar-chart">
    @foreach($snap['charts']['placement_by_product'] as $row)
    <div class="bar-row">
        <div class="bar-label">{{ mb_strimwidth($row['label'], 0, 50, '...') }}</div>
        <div class="bar-track"><span class="bar-fill" style="width:{{ $row['pct'] }}%;"></span></div>
        <div class="bar-value">{{ $fmt($row['value']) }}</div>
    </div>
    @endforeach
</div>
@endif

<!-- ═══ MORA POR BUCKET ══════════════════════════════════════════════ -->
@if(!empty($snap['charts']['mora_by_bucket']))
<div class="section-title">MORA POR BUCKET</div>
<div class="bar-chart">
    @foreach($snap['charts']['mora_by_bucket'] as $row)
    <div class="bar-row">
        <div class="bar-label">{{ mb_strimwidth($row['label'], 0, 50, '...') }}</div>
        <div class="bar-track"><span class="bar-fill bar-fill-red" style="width:{{ $row['pct'] }}%;"></span></div>
        <div class="bar-value">{{ $fmt($row['value']) }}</div>
    </div>
    @endforeach
</div>
@endif

<!-- ═══ RANKING DE SUCURSALES POR CARTERA ════════════════════════════ -->
@if(!empty($snap['charts']['portfolio_by_branch']))
<div class="section-title">RANKING DE SUCURSALES POR CARTERA</div>
<div class="bar-chart">
    @foreach($snap['charts']['portfolio_by_branch'] as $row)
    <div class="bar-row">
        <div class="bar-label">{{ mb_strimwidth($row['label'], 0, 50, '...') }}</div>
        <div class="bar-track"><span class="bar-fill" style="width:{{ $row['pct'] }}%;"></span></div>
        <div class="bar-value">{{ $fmt($row['value']) }}</div>
    </div>
    @endforeach
</div>
@endif

<!-- ═══ PRODUCTOS ════════════════════════════════════════════════════ -->
@if(!empty($snap['sections']['products']))
<div class="pb"></div>
<div class="section-title">COLOCACIÓN POR PRODUCTO FINANCIERO</div>
<table class="data">
    <tr><th>Producto</th><th class="r">Operaciones</th><th class="r">Total colocado</th></tr>
    @foreach($snap['sections']['products'] as $p)
    <tr>
        <td class="b">{{ $p['producto'] }}</td>
        <td class="r">{{ $fmtn($p['operaciones']) }}</td>
        <td class="r">{{ $fmt($p['colocacion']) }}</td>
    </tr>
    @endforeach
</table>
@endif

<!-- ═══ MORA POR SUCURSAL ════════════════════════════════════════════ -->
<!-- Nota: la recuperación por componente (capital/interés/impuesto) ya se
     muestra en la sección 2. INGRESOS, fuente única (branch_radiography) —
     no se duplica aquí con la fuente legacy (sections.recovery_detail). -->
@if(!empty($snap['sections']['mora_by_branch']))
<div class="section-green">MORAS — {{ strtoupper($period->label) }}</div>
<table class="data">
    <tr>
        <th class="green">Sucursal</th>
        <th class="r green">Cartera</th>
        <th class="r green">0-30</th>
        <th class="r green">31-60</th>
        <th class="r green">61-90</th>
        <th class="r green">91-120</th>
        <th class="r green">120+</th>
        <th class="r green">Mora %</th>
    </tr>
    @foreach($snap['sections']['mora_by_branch'] as $mb)
    @php $mpct = (float)$mb['cartera_total'] > 0 ? round((float)$mb['vencida_total']/(float)$mb['cartera_total']*100,2) : 0; @endphp
    <tr>
        <td class="b">{{ $mb['branch'] }}</td>
        <td class="r">{{ $fmt($mb['cartera_total']) }}</td>
        <td class="r">{{ $fmt($mb['mora_1_30'] ?? 0) }}</td>
        <td class="r">{{ $fmt($mb['mora_31_60'] ?? 0) }}</td>
        <td class="r">{{ $fmt($mb['mora_61_90'] ?? 0) }}</td>
        <td class="r">{{ $fmt($mb['mora_91_120'] ?? 0) }}</td>
        <td class="r">{{ $fmt($mb['mora_120_plus'] ?? 0) }}</td>
        <td class="r @if($mpct > 25) card-red @endif">{{ $fmtp($mpct) }}</td>
    </tr>
    @endforeach
</table>
@endif

<!-- ═══ COLOCACIÓN POR SUCURSAL/PRODUCTO ════════════════════════════ -->
@php $colRows = $snap['sections']['placement_by_branch_product'] ?? []; @endphp
@if(!empty($colRows))
<div class="pb"></div>
<div class="section-green">COLOCACIÓN — {{ strtoupper($period->label) }}</div>
<table class="data">
    <tr>
        <th class="green">Sucursal / Producto</th>
        <th class="r green">Monto</th>
        <th class="r green"># Créditos</th>
    </tr>
    @php
    $colGrouped = [];
    foreach ($colRows as $cr) { $colGrouped[$cr['branch']][] = $cr; }
    $cgMonto = 0; $cgCred = 0;
    @endphp
    @foreach($colGrouped as $colBranch => $colProducts)
    @php $bMonto = array_sum(array_column($colProducts,'monto')); $bCred = array_sum(array_column($colProducts,'creditos')); $cgMonto += $bMonto; $cgCred += $bCred; @endphp
    <tr style="background:#065f46;color:#fff;">
        <td class="b">{{ strtoupper($colBranch) }}</td>
        <td class="r b">{{ $fmt($bMonto) }}</td>
        <td class="r b">{{ $fmtn($bCred) }}</td>
    </tr>
    @foreach($colProducts as $cp)
    <tr><td style="padding-left:20px;">{{ $cp['product'] }}</td><td class="r">{{ $fmt($cp['monto']) }}</td><td class="r">{{ $fmtn($cp['creditos']) }}</td></tr>
    @endforeach
    @endforeach
    <tr class="totals-row"><td>TOTAL GENERAL</td><td class="r">{{ $fmt($cgMonto) }}</td><td class="r">{{ $fmtn($cgCred) }}</td></tr>
</table>
@endif

<!-- ═══ GESTORES ════════════════════════════════════════════════════ -->
@php $empGest = $snap['sections']['employees_gestores'] ?? []; @endphp
@if(!empty($empGest))
<div class="pb"></div>
<div class="section-title">EMPLEADOS / GESTORES — TOP {{ min(25, count($empGest)) }}</div>
<table class="data">
    <tr>
        <th>Empleado / Gestor</th>
        <th>Sucursal</th>
        <th class="r">Nómina</th>
        <th class="r">Colocación</th>
        <th class="r">Cartera</th>
        <th class="r">Mora %</th>
    </tr>
    @foreach(array_slice($empGest, 0, 25) as $e)
    <tr>
        <td class="b">{{ $e['name'] }}</td>
        <td>{{ $e['branch'] !== 'Sin sucursal' ? $e['branch'] : '—' }}</td>
        <td class="r">{{ $e['neto'] > 0 ? $fmt($e['neto']) : '—' }}</td>
        <td class="r">{{ $e['colocacion'] > 0 ? $fmt($e['colocacion']) : '—' }}</td>
        <td class="r">{{ $e['cartera'] > 0 ? $fmt($e['cartera']) : '—' }}</td>
        <td class="r @if($e['mora'] > 25) card-red @endif">{{ $e['cartera'] > 0 ? $fmtp($e['mora']) : '—' }}</td>
    </tr>
    @endforeach
    @if(count($empGest) > 25)
    <tr><td colspan="6" style="text-align:center;color:#64748b;font-style:italic;font-size:8pt;">... y {{ count($empGest) - 25 }} más.</td></tr>
    @endif
</table>
@endif

<!-- ═══ CATEGORÍAS ══════════════════════════════════════════════════ -->
@if(!empty($categorias))
<div class="section-title">CATEGORÍA GESTORES POR EBITDA</div>
<table class="data">
    <tr><th>Sucursal</th><th class="r">EBITDA Estimado</th><th class="c">Categoría</th></tr>
    @foreach($categorias as $cat)
    <tr>
        <td class="b">{{ $cat['nombre'] }}</td>
        <td class="r" @if((float)$cat['utilidad'] < 0) style="color:#b91c1c;" @else style="color:#065f46;" @endif>{{ $fmt($cat['utilidad']) }}</td>
        <td class="c"><span class="badge cat-{{ strtolower($cat['categoria']) }}">{{ $cat['categoria'] }}</span></td>
    </tr>
    @endforeach
</table>
<div style="font-size:7.5pt;color:#64748b;margin-top:4px;">* EBITDA = Recuperación − Gastos − Nómina completa estimada por sucursal.</div>
@endif

<!-- ═══ FOOTER ═══════════════════════════════════════════════════════ -->
<div class="footer">
    Radiografía {{ $period->label }} &nbsp;·&nbsp; Sistema de Reportes &nbsp;·&nbsp; {{ $snap['generated_at'] }} &nbsp;·&nbsp; Versión {{ $snap['version'] }}
</div>

</body>
</html>
