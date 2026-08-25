<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Radiografía {{ $branchRow['sucursal'] ?? 'Sucursal' }} — {{ $period->label }}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #1e293b; background: #fff; }
@page {
    margin: 20mm 14mm 18mm 14mm;
    @bottom-left { content: "MR LANA · Radiografía Financiera"; font-family: Helvetica, Arial, sans-serif; font-size: 6.8pt; color: #94a3b8; }
    @bottom-right { content: "Página " counter(page) " de " counter(pages); font-family: Helvetica, Arial, sans-serif; font-size: 6.8pt; color: #94a3b8; }
}

/* ── Encabezado de marca ──────────────────────────────────────────────── */
.cover { background: #0f172a; color: #fff; padding: 16px 20px; border-radius: 10px; margin-bottom: 14px; }
.cover-mark { font-size: 20pt; font-weight: bold; letter-spacing: 1.5px; }
.cover-mark span { color: #34d399; }
.cover-sub { font-size: 10pt; color: #cbd5e1; text-transform: uppercase; letter-spacing: 2.5px; margin-top: 3px; }
table.cover-meta { width: 100%; margin-top: 12px; border-collapse: collapse; }
table.cover-meta td { padding: 3px 0; font-size: 8pt; color: #cbd5e1; vertical-align: top; }
table.cover-meta td.label { width: 34%; color: #64748b; text-transform: uppercase; letter-spacing: .4px; font-size: 6.8pt; }
table.cover-meta td.value { color: #fff; font-weight: bold; }

/* ── Barra de sección ─────────────────────────────────────────────────── */
.section-bar { background: #1f2937; color: #fff; padding: 7px 12px; font-size: 9.5pt; font-weight: bold; letter-spacing: .4px; text-transform: uppercase; margin-top: 18px; margin-bottom: 9px; border-radius: 4px 4px 0 0; }
.section-bar .tag { float: right; font-size: 6.8pt; font-weight: normal; color: #94a3b8; text-transform: none; letter-spacing: 0; }
.section-bar.alt { background: #106A59; }
.section-sub { font-size: 7.3pt; color: #64748b; margin: -5px 0 8px 0; font-style: italic; }
.chart-wrap { margin: 6px 0 10px 0; padding: 8px 6px; border: 0.75pt solid #e2e8f0; border-radius: 6px; background: #fbfdff; }
.avoid { page-break-inside: avoid; }
.pagebreak { page-break-before: always; }
.note { font-size: 7.3pt; color: #64748b; margin-top: 6px; font-style: italic; }
.spacer { height: 8px; }

/* ── Estado semántico (No aplica / No atribuible / Sin datos) ─────────── */
.semantic-box { border: 0.75pt dashed #cbd5e1; background: #f8fafc; color: #64748b; font-size: 7.6pt; padding: 8px 10px; border-radius: 4px; text-align: center; font-style: italic; }

/* ── KPI cards ─────────────────────────────────────────────────────────── */
table.kpi-grid { width: 100%; border-collapse: separate; border-spacing: 5px; margin-top: 2px; }
table.kpi-grid td.kpi { width: 25%; border: 0.75pt solid #d9e2ec; border-left: 3pt solid #106A59; background: #f8fafc; padding: 8px 10px; vertical-align: top; border-radius: 3px; }
table.kpi-grid td.kpi.neg-accent { border-left-color: #b91c1c; }
table.kpi-grid td.kpi.warn-accent { border-left-color: #b45309; }
.kpi-label { font-size: 6.6pt; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; }
.kpi-value { font-size: 12.5pt; font-weight: bold; color: #106A59; margin-top: 4px; }
.kpi-value.neg { color: #b91c1c; }
.kpi-value.warn { color: #b45309; }

/* ── Tablas ────────────────────────────────────────────────────────────── */
table.tbl { width: 100%; border-collapse: collapse; font-size: 7.8pt; }
table.tbl thead th { background: #1f2937; color: #fff; font-weight: bold; text-align: left; padding: 5.5px 7px; border-bottom: 1px solid #1f2937; }
table.tbl tbody td { padding: 4.5px 7px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
table.tbl tbody tr:nth-child(even) td { background: #f8fafc; }
table.tbl tfoot td { background: #106A59; color: #fff; font-weight: bold; padding: 5.5px 7px; border-top: 1pt solid #0b4a3e; }
table.tbl .r { text-align: right; }
table.tbl .c { text-align: center; }
table.tbl .b { font-weight: bold; }

/* ── Resumen ejecutivo (2 columnas) ────────────────────────────────────── */
table.exec-summary { width: 100%; border-collapse: collapse; font-size: 8pt; }
table.exec-summary td { padding: 6px 8px; border-bottom: 0.5pt solid #e2e8f0; }
table.exec-summary td.k { color: #475569; width: 60%; }
table.exec-summary td.v { text-align: right; font-weight: bold; color: #0f172a; width: 40%; }
table.exec-summary tr:nth-child(even) td { background: #f8fafc; }

/* ── Badge categoría EBITDA ────────────────────────────────────────────── */
.cat-badge { display: inline-block; padding: 5px 16px; border-radius: 14px; font-size: 10pt; font-weight: bold; letter-spacing: .5px; }
</style>
</head>
<body>

@php
$fmt  = fn($v) => number_format((float)$v, 2);
$fmt0 = fn($v) => '$' . number_format((float)$v, 0);
$fmt2 = fn($v) => '$' . number_format((float)$v, 2);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$catColors = $ebitdaCategoriaColors ?? ['bg' => 'FFF1F5F9', 'fg' => 'FF475569'];
$catBg = '#' . substr($catColors['bg'], 2);
$catFg = '#' . substr($catColors['fg'], 2);
$sucursal = $branchRow['sucursal'] ?? 'Sucursal';
@endphp

<div class="cover avoid">
    <div class="cover-mark">MR <span>LANA</span></div>
    <div class="cover-sub">Radiografía Financiera — Alcance Sucursal</div>
    <table class="cover-meta">
        <tr>
            <td class="label">Sucursal</td><td class="value">{{ strtoupper($sucursal) }}</td>
            <td class="label">Periodo</td><td class="value">{{ strtoupper($period->label) }}</td>
        </tr>
        <tr>
            <td class="label">Categoría EBITDA</td><td class="value">{{ $ebitdaCategoria ?? 'MANTENIDO' }}</td>
            <td class="label">Fecha de generación</td><td class="value">{{ $snap['generated_at'] ?? now()->format('d/m/Y H:i') }}</td>
        </tr>
    </table>
</div>

<div class="section-bar">1. KPIs principales</div>
<table class="kpi-grid avoid">
    <tr>
        <td class="kpi"><div class="kpi-label">Recuperación</div><div class="kpi-value">{{ $fmt0($rec) }}</div></td>
        <td class="kpi"><div class="kpi-label">Colocación</div><div class="kpi-value">{{ $fmt0($coloc) }}</div></td>
        <td class="kpi"><div class="kpi-label">Cartera</div><div class="kpi-value">{{ $fmt0($cartera) }}</div></td>
        <td class="kpi @if($mora > 25) neg-accent @endif"><div class="kpi-label">Cartera vencida</div><div class="kpi-value @if($mora > 25) neg @endif">{{ $fmt0($vencida) }}</div></td>
    </tr>
    <tr>
        <td class="kpi @if($mora > 25) neg-accent @endif"><div class="kpi-label">Mora %</div><div class="kpi-value @if($mora > 25) neg @endif">{{ $fmtp($mora) }}</div></td>
        <td class="kpi"><div class="kpi-label">Gastos operativos</div><div class="kpi-value">{{ $fmt0($gastos) }}</div></td>
        <td class="kpi"><div class="kpi-label">Nómina y Capital Humano</div><div class="kpi-value">{{ $fmt0($nomina) }}</div></td>
        <td class="kpi @if($ebitda < 0) neg-accent @endif"><div class="kpi-label">EBITDA estimado</div><div class="kpi-value @if($ebitda < 0) neg @endif">{{ $fmt0($ebitda) }}</div></td>
    </tr>
    <tr>
        <td class="kpi"><div class="kpi-label">Margen EBITDA</div><div class="kpi-value @if(($margen ?? 0) < 0) neg @endif">{{ $fmtp($margen ?? 0) }}</div></td>
        <td class="kpi"><div class="kpi-label">Percepciones</div><div class="kpi-value">{{ $fmt0($payrollDetail['percepciones_total'] ?? 0) }}</div></td>
        <td class="kpi"><div class="kpi-label">Deducciones</div><div class="kpi-value">{{ $fmt0($payrollDetail['deducciones_total'] ?? 0) }}</div></td>
        <td class="kpi"><div class="kpi-label">Operaciones</div><div class="kpi-value">{{ $fmt0($ops) }}</div></td>
    </tr>
</table>

<div class="section-bar alt">2. Resumen ejecutivo</div>
<table class="exec-summary avoid">
    <tr><td class="k">Recuperación total</td><td class="v">{{ $fmt2($rec) }}</td></tr>
    <tr><td class="k">Colocación total</td><td class="v">{{ $fmt2($coloc) }}</td></tr>
    <tr><td class="k">Cartera total</td><td class="v">{{ $fmt2($cartera) }}</td></tr>
    <tr><td class="k">Cartera vencida</td><td class="v">{{ $fmt2($vencida) }}</td></tr>
    <tr><td class="k">Índice de mora</td><td class="v">{{ $fmtp($mora) }}</td></tr>
    <tr><td class="k">Gastos operativos</td><td class="v">{{ $fmt2($gastos) }}</td></tr>
    <tr><td class="k">Nómina y Capital Humano</td><td class="v">{{ $fmt2($nomina) }}</td></tr>
    <tr><td class="k">Ingreso base EBITDA</td><td class="v">{{ $fmt2($ingresoBase) }}</td></tr>
    <tr><td class="k"><b>EBITDA</b></td><td class="v">{{ $fmt2($ebitda) }}</td></tr>
    <tr><td class="k"><b>Margen EBITDA</b></td><td class="v">{{ $fmtp($margen ?? 0) }}</td></tr>
    <tr><td class="k">Excedente enviado a corporativo</td><td class="v">{{ $fmt2($excedente) }}</td></tr>
    <tr><td class="k">Préstamos intersucursales (fondea)</td><td class="v">{{ $fmt2($fondeo) }}</td></tr>
</table>
<div class="note">Excedente y fondeo son movimientos de liquidez entre sucursales/corporativo — no afectan recuperación, OPEX, nómina ni EBITDA.</div>

@if(!empty($chartRecuperacionVsColocacion))
<div class="chart-wrap avoid" style="margin-top:10px;">{!! $chartRecuperacionVsColocacion !!}</div>
@endif
@if(!empty($chartEbitda))
<div class="chart-wrap avoid">{!! $chartEbitda !!}</div>
@endif

@php
    $percepciones  = $payrollDetail['percepciones'] ?? [];
    $deducciones   = $payrollDetail['deducciones'] ?? [];

    // Reconciliación defensiva: si el desglose no suma contra el KPI del resumen, no se
    // muestra un desglose que lo contradiga (mismo criterio que el PDF de gestor).
    $recSum = is_array($recoveryComponents ?? null) ? round(array_sum($recoveryComponents), 2) : null;
    $recReconciles = $recSum === null || abs($recSum - round($rec, 2)) <= 0.01;

    $placSum = !empty($placementsByProduct) ? round(array_sum(array_column($placementsByProduct, 'colocacion')), 2) : null;
    $placReconciles = $placSum === null || abs($placSum - round($coloc, 2)) <= 0.01;

    $recoveryComponentLabels = [
        'capital_recuperado' => 'Capital recuperado', 'interes_recuperado' => 'Intereses',
        'impuesto_recuperado' => 'Impuestos', 'charges' => 'Moratorios / Multas',
        'cargos_adicionales' => 'Cargos adicionales', 'cargos_inicio' => 'Cargos al inicio',
        'comision_apertura' => 'Comisión por apertura', 'excedente_recuperado' => 'Excedentes recuperados',
        'seguro_crece_reconocido' => 'Seguro CRECE reconocido (30%)', 'otros_recuperacion' => 'Otros',
    ];
@endphp

<div class="pagebreak"></div>

<div class="section-bar">3. Recuperación<span class="tag">Componentes por sucursal</span></div>
<div class="section-sub">Ingresos por cobranza atribuibles directamente a esta sucursal en el periodo.</div>

@if($recSum !== null)
    @if($recReconciles)
    <table class="tbl avoid">
        <thead><tr><th>Componente</th><th class="r">Monto</th></tr></thead>
        <tbody>
            @foreach($recoveryComponents as $key => $val)
            @if($val != 0)
            <tr><td>{{ $recoveryComponentLabels[$key] ?? ucfirst(str_replace('_',' ',$key)) }}</td><td class="r">{{ $fmt2($val) }}</td></tr>
            @endif
            @endforeach
        </tbody>
        <tfoot><tr><td>Total recuperación</td><td class="r">{{ $fmt2($recSum) }}</td></tr></tfoot>
    </table>
    @else
    <div class="semantic-box">Desglose no disponible: no reconcilia contra la recuperación total del periodo.</div>
    @endif
@else
<div class="semantic-box">Sin movimientos de recuperación para esta sucursal en el periodo.</div>
@endif

<div style="height:10px"></div>
<div class="semantic-box">No aplica: el desglose de recuperación por producto en este snapshot está agregado a nivel empresa completa — no filtrable por sucursal todavía (mismo límite ya presente en la vista Web de esta sucursal).</div>

<div class="section-bar alt">4. Colocación<span class="tag">Otorgamiento por producto</span></div>
@if(!empty($placementsByProduct))
    @if($placReconciles)
    <table class="tbl avoid">
        <thead><tr><th>Producto</th><th class="c">Operaciones</th><th class="r">Colocación</th><th class="r">% del total</th></tr></thead>
        <tbody>
            @foreach($placementsByProduct as $pp)
            <tr>
                <td>{{ $pp['producto'] ?? '—' }}</td>
                <td class="c">{{ number_format($pp['operaciones'] ?? 0) }}</td>
                <td class="r">{{ $fmt2($pp['colocacion'] ?? 0) }}</td>
                <td class="r">{{ $coloc > 0 ? $fmtp((($pp['colocacion'] ?? 0) / $coloc) * 100) : '—' }}</td>
            </tr>
            @endforeach
        </tbody>
        <tfoot><tr><td>Total</td><td class="c">{{ number_format($ops) }}</td><td class="r">{{ $fmt2($coloc) }}</td><td class="r">100.00%</td></tr></tfoot>
    </table>
    @if(!empty($chartColocacionPorProducto))
    <div class="chart-wrap avoid">{!! $chartColocacionPorProducto !!}</div>
    @endif
    @else
    <div class="semantic-box">Desglose por producto no disponible: no reconcilia contra la colocación total.</div>
    @endif
@else
<div class="semantic-box">Sin colocación registrada para esta sucursal en el periodo.</div>
@endif

<div class="pagebreak"></div>

<div class="section-bar">5. Cartera y mora<span class="tag">Distribución por antigüedad</span></div>
<table class="kpi-grid avoid">
    <tr>
        <td class="kpi"><div class="kpi-label">Cartera total</div><div class="kpi-value">{{ $fmt0($cartera) }}</div></td>
        <td class="kpi"><div class="kpi-label">Cartera sana</div><div class="kpi-value">{{ $fmt0(max(0, $cartera - $vencida)) }}</div></td>
        <td class="kpi neg-accent"><div class="kpi-label">Cartera vencida</div><div class="kpi-value neg">{{ $fmt0($vencida) }}</div></td>
        <td class="kpi @if($mora > 25) neg-accent @endif"><div class="kpi-label">Mora %</div><div class="kpi-value @if($mora > 25) neg @endif">{{ $fmtp($mora) }}</div></td>
    </tr>
</table>

@if(!empty($moraBuckets))
@php
    $moraLabels = [
        'al_corriente' => 'Al corriente', 'mora_1_30' => 'Mora 1-30', 'mora_31_60' => 'Mora 31-60',
        'mora_61_90' => 'Mora 61-90', 'mora_91_120' => 'Mora 91-120', 'mora_120_plus' => 'Mora 120+',
    ];
@endphp
<table class="tbl avoid" style="margin-top:10px;">
    <thead><tr><th>Bucket de antigüedad</th><th class="c">Contratos</th><th class="r">Monto</th><th class="r">% de cartera</th></tr></thead>
    <tbody>
        @foreach($moraBuckets as $bucketKey => $b)
        <tr>
            <td>{{ $b['label'] ?? ($moraLabels[$bucketKey] ?? $bucketKey) }}</td>
            <td class="c">{{ number_format($b['contratos'] ?? 0) }}</td>
            <td class="r">{{ $fmt2($b['monto'] ?? 0) }}</td>
            <td class="r">{{ $cartera > 0 ? $fmtp((($b['monto'] ?? 0) / $cartera) * 100) : '—' }}</td>
        </tr>
        @endforeach
    </tbody>
</table>
@if(!empty($chartMoraPorBucket))
<div class="chart-wrap avoid" style="margin-top:10px;">{!! $chartMoraPorBucket !!}</div>
@endif
@if(!empty($chartCarteraSanaVsVencida))
<div class="chart-wrap avoid">{!! $chartCarteraSanaVsVencida !!}</div>
@endif
@else
<div class="semantic-box" style="margin-top:10px;">Sin cartera registrada para esta sucursal en el periodo.</div>
@endif

<div class="pagebreak"></div>

<div class="section-bar">6. Nómina y Capital Humano<span class="tag">Percepciones y deducciones</span></div>
@if($percepciones || $deducciones)
<table class="tbl avoid">
    <thead><tr><th>Concepto</th><th class="c">Tipo</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @foreach($percepciones as $p)
        <tr><td>{{ $p['concepto'] }}</td><td class="c">Percepción</td><td class="r">{{ $fmt2($p['monto']) }}</td></tr>
        @endforeach
        @foreach($deducciones as $d)
        <tr><td>{{ $d['concepto'] }}</td><td class="c">Deducción</td><td class="r">{{ $fmt2($d['monto']) }}</td></tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td colspan="2">Percepciones totales</td><td class="r">{{ $fmt2($payrollDetail['percepciones_total'] ?? 0) }}</td></tr>
        <tr><td colspan="2">Deducciones totales</td><td class="r">{{ $fmt2($payrollDetail['deducciones_total'] ?? 0) }}</td></tr>
    </tfoot>
</table>
<div class="note">Este desglose es el detalle de nómina NOI por concepto de los colaboradores de esta sucursal — un subconjunto informativo del KPI "Nómina y Capital Humano" (que además incluye IMSS patronal y gastos de empleados vía nómina, ajenos a NOI). No están pensados para sumar el mismo total.</div>
@if(!empty($chartNominaComposicion))
<div class="chart-wrap avoid">{!! $chartNominaComposicion !!}</div>
@endif
@else
<div class="semantic-box">Sin movimientos de nómina NOI atribuidos a los colaboradores de esta sucursal en el periodo.</div>
@endif

<div class="section-bar alt">7. Gastos operativos<span class="tag">Top conceptos</span></div>
@if(!empty($gastosDetalle))
<table class="tbl avoid">
    <thead><tr><th>Concepto</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @foreach($gastosDetalle as $concepto => $monto)
        @if($monto > 0)
        <tr><td>{{ $concepto }}</td><td class="r">{{ $fmt2($monto) }}</td></tr>
        @endif
        @endforeach
    </tbody>
    <tfoot><tr><td>Total gastos operativos</td><td class="r">{{ $fmt2($gastos) }}</td></tr></tfoot>
</table>
@else
<div class="semantic-box">Sin gastos operativos registrados para esta sucursal en el periodo.</div>
@endif

<div class="pagebreak"></div>

<div class="section-bar">8. EBITDA<span class="tag">Ingreso base - Gastos totales</span></div>
<table class="exec-summary avoid">
    <tr><td class="k">Ingreso base EBITDA</td><td class="v">{{ $fmt2($ingresoBase) }}</td></tr>
    <tr><td class="k">Gastos operativos</td><td class="v">{{ $fmt2($gastos) }}</td></tr>
    <tr><td class="k">Nómina y Capital Humano</td><td class="v">{{ $fmt2($nomina) }}</td></tr>
    <tr><td class="k">Gastos totales (Gastos + Nómina)</td><td class="v">{{ $fmt2($gastosTotales) }}</td></tr>
    <tr><td class="k"><b>EBITDA</b></td><td class="v">{{ $fmt2($ebitda) }}</td></tr>
    <tr><td class="k"><b>Margen EBITDA</b></td><td class="v">{{ $fmtp($margen ?? 0) }}</td></tr>
</table>
<div class="note">Fórmula: EBITDA = Ingreso base EBITDA - (Gastos operativos + Nómina y Capital Humano). El capital recuperado NO se cuenta como ingreso EBITDA.</div>

<div style="height:14px"></div>
<div style="text-align:center;">
    <div class="kpi-label" style="margin-bottom:6px;">CATEGORÍA EBITDA DE LA SUCURSAL</div>
    <span class="cat-badge" style="background:{{ $catBg }}; color:{{ $catFg }};">{{ $ebitdaCategoria ?? 'MANTENIDO' }}</span>
</div>

<div class="section-bar alt" style="margin-top:18px;">9. Efectividad de cobranza</div>
@if(!empty($efectividad))
<table class="tbl avoid">
    <thead><tr><th>Estatus</th><th class="c">Contratos</th><th class="r">Capital</th><th class="r">Interés</th><th class="r">Impuesto</th><th class="r">Moratorios</th><th class="r">Total</th></tr></thead>
    <tbody>
        @foreach(['vigente' => 'Vigente', 'atrasado' => 'Atrasado', 'vencido' => 'Vencido'] as $key => $label)
        @php($e = $efectividad[$key] ?? null)
        @if($e)
        <tr>
            <td>{{ $label }}</td><td class="c">{{ number_format($e['contratos']) }}</td>
            <td class="r">{{ $fmt2($e['capital']) }}</td><td class="r">{{ $fmt2($e['interes']) }}</td>
            <td class="r">{{ $fmt2($e['impuesto']) }}</td><td class="r">{{ $fmt2($e['moratorios']) }}</td>
            <td class="r">{{ $fmt2($e['total']) }}</td>
        </tr>
        @endif
        @endforeach
    </tbody>
    @if(!empty($efectividad['total']))
    <tfoot>
        <tr>
            <td>Total</td><td class="c">{{ number_format($efectividad['total']['contratos']) }}</td>
            <td class="r">{{ $fmt2($efectividad['total']['capital']) }}</td><td class="r">{{ $fmt2($efectividad['total']['interes']) }}</td>
            <td class="r">{{ $fmt2($efectividad['total']['impuesto']) }}</td><td class="r">{{ $fmt2($efectividad['total']['moratorios']) }}</td>
            <td class="r">{{ $fmt2($efectividad['total']['total']) }}</td>
        </tr>
    </tfoot>
    @endif
</table>
@if(!empty($chartEfectividad))
<div class="chart-wrap avoid">{!! $chartEfectividad !!}</div>
@endif
@else
<div class="semantic-box">Sin datos de efectividad de cobranza para esta sucursal en el periodo.</div>
@endif

<div class="section-bar alt" style="margin-top:18px;">10. Notas y aclaraciones</div>
<table class="tbl avoid">
    <tbody>
        <tr><td style="width:22%;"><b>No aplica</b></td><td>Recuperación por producto: en este snapshot ese desglose está agregado a nivel empresa completa, no filtrable por sucursal todavía (misma limitación ya presente en la vista Web de esta sucursal).</td></tr>
        <tr><td><b>Sin datos</b></td><td>La sucursal no tuvo movimiento real en esa sección durante el periodo — es un cero real, no un error.</td></tr>
        <tr><td><b>Excedente / Fondeo</b></td><td>Movimientos de liquidez entre sucursales y corporativo — no afectan recuperación, OPEX, nómina ni EBITDA; se muestran de forma informativa.</td></tr>
    </tbody>
</table>
<div class="note">Este documento se generó a partir del mismo snapshot canónico que la vista Web y el Excel de este periodo y alcance — ningún valor se recalculó de forma independiente para este PDF.</div>

</body>
</html>
