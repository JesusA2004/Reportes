<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Radiografía {{ $empName }} — {{ $period->label }}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 8.5pt; color: #1e293b; background: #fff; }
@page { margin: 18mm 14mm 22mm 14mm; }

.brand { text-align: center; padding-bottom: 10px; margin-bottom: 14px; border-bottom: 2px solid #1f2937; }
.brand-mark { font-size: 19pt; font-weight: bold; letter-spacing: 1px; color: #106A59; }
.brand-sub  { font-size: 9.5pt; color: #334155; text-transform: uppercase; letter-spacing: 2px; margin-top: 2px; }
.brand-meta { font-size: 8pt; color: #64748b; margin-top: 8px; }
.brand-meta b { color: #1e293b; }

.section-bar { background: #1f2937; color: #fff; padding: 6px 10px; font-size: 9pt; font-weight: bold; letter-spacing: .3px; text-transform: uppercase; margin-top: 16px; margin-bottom: 8px; }
.section-bar.alt { background: #334155; }
.chart-wrap { margin: 6px 0 4px 0; padding: 6px 4px; }
.avoid { page-break-inside: avoid; }
.note { font-size: 7.3pt; color: #64748b; margin-top: 5px; font-style: italic; }

table.kpi-grid { width: 100%; border-collapse: separate; border-spacing: 4px; margin-top: 4px; }
table.kpi-grid td.kpi { width: 25%; border: 0.75pt solid #d9e2ec; background: #f8fafc; padding: 7px 9px; vertical-align: top; }
.kpi-label { font-size: 6.8pt; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: .6px; }
.kpi-value { font-size: 11.5pt; font-weight: bold; color: #106A59; margin-top: 3px; }
.kpi-value.neg { color: #b91c1c; }

table.tbl { width: 100%; border-collapse: collapse; font-size: 7.8pt; }
table.tbl thead th { background: #1f2937; color: #fff; font-weight: bold; text-align: left; padding: 5px 6px; border-bottom: 1px solid #1f2937; }
table.tbl tbody td { padding: 4px 6px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
table.tbl tbody tr:nth-child(even) td { background: #f8fafc; }
table.tbl tfoot td { background: #1f2937; color: #fff; font-weight: bold; padding: 5px 6px; border-top: 1pt solid #0f172a; }
table.tbl .r { text-align: right; }
table.tbl .c { text-align: center; }
table.tbl .b { font-weight: bold; }
</style>
</head>
<body>

@php
$fmt  = fn($v) => number_format((float)$v, 2);
$fmt0 = fn($v) => number_format((float)$v, 0);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
@endphp

<div class="brand">
    <div class="brand-mark">MR LANA</div>
    <div class="brand-sub">Radiografía Financiera — {{ strtoupper($empName) }}</div>
    <div class="brand-meta">
        <b>Gestor / Empleado:</b> {{ $empName }} &nbsp;&nbsp;·&nbsp;&nbsp; <b>Sucursal:</b> {{ $empBranch }}
        <br>
        <b>Periodo:</b> {{ strtoupper($period->label) }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <b>Fecha de generación:</b> {{ $snap['generated_at'] ?? now()->format('d/m/Y H:i') }}
    </div>
</div>

<table class="kpi-grid avoid">
    <tr>
        <td class="kpi"><div class="kpi-label">Recuperación</div><div class="kpi-value">{{ $fmt0($rec) }}</div></td>
        <td class="kpi"><div class="kpi-label">Colocación</div><div class="kpi-value">{{ $fmt0($coloc) }}</div></td>
        <td class="kpi"><div class="kpi-label">Cartera</div><div class="kpi-value">{{ $fmt0($cartera) }}</div></td>
        <td class="kpi"><div class="kpi-label">Cartera vencida</div><div class="kpi-value @if($mora > 25) neg @endif">{{ $fmt0($vencida) }}</div></td>
    </tr>
    <tr>
        <td class="kpi"><div class="kpi-label">Operaciones</div><div class="kpi-value">{{ $fmt0($ops) }}</div></td>
        <td class="kpi"><div class="kpi-label">Gastos</div><div class="kpi-value">{{ $fmt0($gastos) }}</div></td>
        <td class="kpi"><div class="kpi-label">EBITDA estimado</div><div class="kpi-value @if($utilidad < 0) neg @endif">{{ $fmt0($utilidad) }}</div></td>
        <td class="kpi"><div class="kpi-label">Mora %</div><div class="kpi-value @if($mora > 25) neg @endif">{{ $fmtp($mora) }}</div></td>
    </tr>
</table>

@if(!empty($chartRecuperacionVsColocacion) || !empty($chartEbitda))
<table class="tbl avoid" style="border:0;"><tr>
    @if(!empty($chartRecuperacionVsColocacion))<td style="border:0; width:50%; vertical-align:top;" class="chart-wrap">{!! $chartRecuperacionVsColocacion !!}</td>@endif
    @if(!empty($chartEbitda))<td style="border:0; width:50%; vertical-align:top;" class="chart-wrap">{!! $chartEbitda !!}</td>@endif
</tr></table>
@endif

<div class="section-bar">Nómina y Capital Humano</div>
<table class="tbl avoid">
    <thead><tr><th>Concepto</th><th class="r">Monto</th></tr></thead>
    <tbody>
        <tr><td>Pagos</td><td class="r">{{ $fmt($pagos) }}</td></tr>
        <tr><td>Bonos</td><td class="r">{{ $fmt($bonos) }}</td></tr>
        <tr><td>Descuentos</td><td class="r">{{ $fmt($desctos) }}</td></tr>
    </tbody>
    <tfoot><tr><td><b>Nómina neta</b></td><td class="r">{{ $fmt($neto) }}</td></tr></tfoot>
</table>

@if($extraAmount > 0)
<div class="section-bar alt">Gasto adicional registrado</div>
<table class="tbl avoid">
    <tbody>
        <tr><td>{{ $extraNotes ?: 'Sin observación' }}</td><td class="r">{{ $fmt($extraAmount) }}</td></tr>
    </tbody>
</table>
@endif

@php
    // Nómina detallada por categoría — MISMA fuente que Web/Excel (payrollDetail),
    // nunca recalculada aquí (ver RadiografiaExportService::resolveEmployeeRow()).
    $payrollDetail = $payrollDetail ?? null;
    $percepciones  = $payrollDetail['percepciones'] ?? [];
    $deducciones   = $payrollDetail['deducciones'] ?? [];

    // Reconciliación defensiva: si por algún motivo el desglose de componentes/producto
    // no suma contra el KPI del RESUMEN, no se muestra un desglose que lo contradiga.
    $recSum = is_array($recoveryComponents ?? null) ? round(array_sum($recoveryComponents), 2) : null;
    $recReconciles = $recSum === null || abs($recSum - round($rec, 2)) <= 0.01;

    $rbpSum = !empty($recoveryByProduct) ? round(array_sum(array_column($recoveryByProduct, 'recuperacion')), 2) : null;
    $rbpReconciles = $rbpSum === null || abs($rbpSum - round($rec, 2)) <= 0.01;

    $placSum = !empty($placementsByProduct) ? round(array_sum(array_column($placementsByProduct, 'colocacion')), 2) : null;
    $placReconciles = $placSum === null || abs($placSum - round($coloc, 2)) <= 0.01;

    $recoveryComponentLabels = [
        'capital' => 'Capital recuperado', 'interes' => 'Intereses', 'impuesto' => 'Impuestos',
        'moratorios' => 'Moratorios', 'cargos_adicionales' => 'Cargos adicionales',
        'cargos_inicio' => 'Cargos al inicio', 'comision_apertura' => 'Comisión por apertura',
        'excedentes' => 'Excedentes recuperados', 'otros' => 'Otros',
    ];
@endphp

@if($percepciones || $deducciones)
<div class="section-bar alt">Nómina detallada — Percepciones y deducciones</div>
<table class="tbl avoid">
    <thead><tr><th>Concepto</th><th class="c">Tipo</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @foreach($percepciones as $p)
        <tr><td>{{ $p['concepto'] }}</td><td class="c">Percepción</td><td class="r">{{ $fmt($p['monto']) }}</td></tr>
        @endforeach
        @foreach($deducciones as $d)
        <tr><td>{{ $d['concepto'] }}</td><td class="c">Deducción</td><td class="r">{{ $fmt($d['monto']) }}</td></tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr><td colspan="2"><b>Percepciones totales</b></td><td class="r">{{ $fmt($payrollDetail['percepciones_total'] ?? 0) }}</td></tr>
        <tr><td colspan="2"><b>Deducciones totales</b></td><td class="r">{{ $fmt($payrollDetail['deducciones_total'] ?? 0) }}</td></tr>
    </tfoot>
</table>
@if(!empty($chartNominaComposicion))
<div class="chart-wrap avoid">{!! $chartNominaComposicion !!}</div>
@endif
@endif

@if($recSum !== null)
<div class="section-bar">Recuperación — Componentes</div>
@if($recReconciles)
<table class="tbl avoid">
    <thead><tr><th>Componente</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @foreach($recoveryComponents as $key => $val)
        <tr><td>{{ $recoveryComponentLabels[$key] ?? ucfirst(str_replace('_',' ',$key)) }}</td><td class="r">{{ $fmt($val) }}</td></tr>
        @endforeach
    </tbody>
    <tfoot><tr><td><b>Total</b></td><td class="r">{{ $fmt($recSum) }}</td></tr></tfoot>
</table>
@else
<div class="note">Desglose no disponible: no reconcilia contra la recuperación total del periodo. Ver logs.</div>
@endif
@endif

@if(!empty($recoveryByProduct))
<div class="section-bar alt">Recuperación por producto</div>
@if($rbpReconciles)
<table class="tbl avoid">
    <thead><tr><th>Producto</th><th class="r">Recuperación</th></tr></thead>
    <tbody>
        @foreach($recoveryByProduct as $rp)
        <tr><td>{{ $rp['producto'] ?? '—' }}</td><td class="r">{{ $fmt($rp['recuperacion'] ?? 0) }}</td></tr>
        @endforeach
    </tbody>
</table>
@if(!empty($chartRecuperacionPorProducto))
<div class="chart-wrap avoid">{!! $chartRecuperacionPorProducto !!}</div>
@endif
@else
<div class="note">Desglose por producto no disponible: no reconcilia contra la recuperación total. Ver logs.</div>
@endif
@endif

@if(!empty($placementsByProduct))
<div class="section-bar">Colocación por producto</div>
@if($placReconciles)
<table class="tbl avoid">
    <thead><tr><th>Producto</th><th class="c">Operaciones</th><th class="r">Colocación</th></tr></thead>
    <tbody>
        @foreach($placementsByProduct as $pp)
        <tr><td>{{ $pp['producto'] ?? '—' }}</td><td class="c">{{ $fmt0($pp['operaciones'] ?? 0) }}</td><td class="r">{{ $fmt($pp['colocacion'] ?? 0) }}</td></tr>
        @endforeach
    </tbody>
</table>
@if(!empty($chartColocacionPorProducto))
<div class="chart-wrap avoid">{!! $chartColocacionPorProducto !!}</div>
@endif
@else
<div class="note">Desglose por producto no disponible: no reconcilia contra la colocación total. Ver logs.</div>
@endif
@endif

@if(!empty($moraBuckets))
<div class="section-bar alt">Cartera — Mora por antigüedad</div>
<table class="tbl avoid">
    <thead><tr><th>Bucket</th><th class="c">Contratos</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @foreach($moraBuckets as $bucketKey => $b)
        <tr><td>{{ $b['label'] ?? $bucketKey }}</td><td class="c">{{ $fmt0($b['contratos'] ?? 0) }}</td><td class="r">{{ $fmt($b['monto'] ?? 0) }}</td></tr>
        @endforeach
    </tbody>
</table>
<table class="tbl avoid" style="border:0;"><tr>
    @if(!empty($chartMoraPorBucket))<td style="border:0; width:50%; vertical-align:top;" class="chart-wrap">{!! $chartMoraPorBucket !!}</td>@endif
    @if(!empty($chartCarteraSanaVsVencida))<td style="border:0; width:50%; vertical-align:top;" class="chart-wrap">{!! $chartCarteraSanaVsVencida !!}</td>@endif
</tr></table>
@endif

@if(!empty($efectividad))
<div class="section-bar">Efectividad de cobranza</div>
<table class="tbl avoid">
    <thead><tr><th>Estatus</th><th class="c">Contratos</th><th class="r">Capital</th><th class="r">Interés</th><th class="r">Impuesto</th><th class="r">Moratorios</th><th class="r">Total</th></tr></thead>
    <tbody>
        @foreach(['vigente' => 'Vigente', 'atrasado' => 'Atrasado', 'vencido' => 'Vencido'] as $key => $label)
        @php($e = $efectividad[$key] ?? null)
        @if($e)
        <tr>
            <td>{{ $label }}</td><td class="c">{{ $fmt0($e['contratos']) }}</td>
            <td class="r">{{ $fmt($e['capital']) }}</td><td class="r">{{ $fmt($e['interes']) }}</td>
            <td class="r">{{ $fmt($e['impuesto']) }}</td><td class="r">{{ $fmt($e['moratorios']) }}</td>
            <td class="r">{{ $fmt($e['total']) }}</td>
        </tr>
        @endif
        @endforeach
    </tbody>
    @if(!empty($efectividad['total']))
    <tfoot>
        <tr>
            <td><b>Total</b></td><td class="c">{{ $fmt0($efectividad['total']['contratos']) }}</td>
            <td class="r">{{ $fmt($efectividad['total']['capital']) }}</td><td class="r">{{ $fmt($efectividad['total']['interes']) }}</td>
            <td class="r">{{ $fmt($efectividad['total']['impuesto']) }}</td><td class="r">{{ $fmt($efectividad['total']['moratorios']) }}</td>
            <td class="r">{{ $fmt($efectividad['total']['total']) }}</td>
        </tr>
    </tfoot>
    @endif
</table>
@if(!empty($chartEfectividad))
<div class="chart-wrap avoid">{!! $chartEfectividad !!}</div>
@endif
@endif

<div class="note">EBITDA estimado = Ingreso base EBITDA − (Gastos + Nómina neta). Todas las cifras de este documento provienen del mismo snapshot que la vista Web y el Excel de este periodo/alcance — no se recalculan de forma independiente.</div>

</body>
</html>
