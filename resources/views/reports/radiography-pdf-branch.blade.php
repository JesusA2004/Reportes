<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Radiografía {{ $branchRow['sucursal'] ?? 'Sucursal' }} — {{ $period->label }}</title>
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

.badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 7.3pt; font-weight: bold; }
.badge-diamante  { background: #ede9fe; color: #5b21b6; }
.badge-master    { background: #dbeafe; color: #1e3a8a; }
.badge-senior    { background: #d1fae5; color: #065f46; }
.badge-junior    { background: #fef3c7; color: #92400e; }
.badge-mantenido { background: #fee2e2; color: #b91c1c; }

.ok-box { background: #ecfdf5; border: 0.75pt solid #a7f3d0; color: #065f46; font-size: 7.6pt; padding: 6px 8px; margin-top: 6px; }
</style>
</head>
<body>

@php
$fmt  = fn($v) => number_format((float)$v, 2);
$fmt0 = fn($v) => number_format((float)$v, 0);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$catBadge = fn($c) => match($c) {
    'DIAMANTE' => 'badge-diamante', 'MASTER' => 'badge-master', 'SENIOR' => 'badge-senior',
    'JUNIOR' => 'badge-junior', default => 'badge-mantenido',
};

$rec     = (float)($branchRow['recuperacion_total'] ?? 0);
$col     = (float)($branchRow['colocacion'] ?? 0);
$cartera = (float)($branchRow['valor_cartera'] ?? 0);
$gastos  = (float)($branchRow['gastos_operativos'] ?? 0);
$nomina  = (float)($branchRow['nomina_total'] ?? 0) + (float)($branchRow['comisiones'] ?? 0) + (float)($branchRow['bonos'] ?? 0)
    + (float)($branchRow['vacaciones'] ?? 0) + (float)($branchRow['prima_vacacional'] ?? 0)
    + array_sum((array)($branchRow['nomina_detalle'] ?? []));
$ebitda  = \App\Services\Radiography\RadiographyStyleHelper::branchEbitdaEstimate($branchRow);
$categoria = \App\Services\Radiography\RadiographyStyleHelper::ebitdaCategory($ebitda);

$mora0_30   = (float)($branchRow['mora_0_30']    ?? 0);
$mora31_60  = (float)($branchRow['mora_31_60']   ?? 0);
$mora61_90  = (float)($branchRow['mora_61_90']   ?? 0);
$mora91_120 = (float)($branchRow['mora_91_120']  ?? 0);
$mora120p   = (float)($branchRow['mora_120_plus']?? 0);
$moraTotal  = $mora0_30 + $mora31_60 + $mora61_90 + $mora91_120 + $mora120p;
$moraPct    = $cartera > 0 ? round($moraTotal / $cartera * 100, 2) : 0.0;

$ingrCapital    = (float)($branchRow['capital_recuperado']   ?? 0);
$ingrInteres    = (float)($branchRow['interes_recuperado']   ?? 0);
$ingrImpuesto   = (float)($branchRow['impuesto_recuperado']  ?? 0);
$ingrCharges    = (float)($branchRow['charges']               ?? 0);
$ingrCargosIni  = (float)($branchRow['cargos_inicio']         ?? 0);
$ingrComAper    = (float)($branchRow['comision_apertura']     ?? 0);
$ingrCargosAdic = (float)($branchRow['cargos_adicionales']    ?? 0);
$ingrExcedRec   = (float)($branchRow['excedente_recuperado']  ?? 0);
$ingrCrece30    = (float)($branchRow['seguro_crece_reconocido'] ?? 0);
$ingrOtrosDet   = (array)($branchRow['otros_detalle']         ?? []);

$gastosDetalle = (array)($branchRow['gastos_detalle'] ?? []);
arsort($gastosDetalle);

$fondeo     = (float)($branchRow['prestamos_fondea'] ?? 0);
$excedente  = (float)($branchRow['excedentes']       ?? 0);
@endphp

<div class="brand">
    <div class="brand-mark">MR LANA</div>
    <div class="brand-sub">Radiografía Financiera — {{ strtoupper($branchRow['sucursal'] ?? '') }}</div>
    <div class="brand-meta">
        <b>Periodo:</b> {{ strtoupper($period->label) }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <b>Fecha de generación:</b> {{ $snap['generated_at'] ?? now()->format('d/m/Y H:i') }}
    </div>
</div>

<table class="kpi-grid avoid">
    <tr>
        <td class="kpi"><div class="kpi-label">Recuperación / Cobranza</div><div class="kpi-value">{{ $fmt0($rec) }}</div></td>
        <td class="kpi"><div class="kpi-label">Colocación</div><div class="kpi-value">{{ $fmt0($col) }}</div></td>
        <td class="kpi"><div class="kpi-label">Cartera</div><div class="kpi-value">{{ $fmt0($cartera) }}</div></td>
        <td class="kpi"><div class="kpi-label">Cartera vencida</div><div class="kpi-value @if($moraPct > 25) neg @endif">{{ $fmt0($moraTotal) }}</div></td>
    </tr>
    <tr>
        <td class="kpi"><div class="kpi-label">Gastos</div><div class="kpi-value">{{ $fmt0($gastos) }}</div></td>
        <td class="kpi"><div class="kpi-label">Nómina</div><div class="kpi-value">{{ $fmt0($nomina) }}</div></td>
        <td class="kpi"><div class="kpi-label">EBITDA</div><div class="kpi-value @if($ebitda < 0) neg @endif">{{ $fmt0($ebitda) }}</div></td>
        <td class="kpi"><div class="kpi-label">Mora %</div><div class="kpi-value @if($moraPct > 25) neg @endif">{{ $fmtp($moraPct) }}</div></td>
    </tr>
</table>

<div style="margin-top:10px;">
    Categoría por EBITDA: <span class="badge {{ $catBadge($categoria) }}">{{ $categoria }}</span>
</div>

{{-- Ingresos / Recuperación --}}
<div class="section-bar">Ingresos / Recuperación</div>
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
        @if($ingrCrece30 > 0)<tr><td>Seguro CRECE reconocido (30%)</td><td class="r">{{ $fmt($ingrCrece30) }}</td></tr>@endif
        @foreach($ingrOtrosDet as $otrosLabel => $otrosVal)
            @if($otrosVal != 0)<tr><td>{{ $otrosLabel }}</td><td class="r">{{ $fmt($otrosVal) }}</td></tr>@endif
        @endforeach
    </tbody>
    <tfoot><tr><td><b>Total Recuperación</b></td><td class="r">{{ $fmt($rec) }}</td></tr></tfoot>
</table>

{{-- Gastos operativos --}}
@if(!empty($gastosDetalle))
<div class="section-bar">Gastos Operativos</div>
<table class="tbl avoid">
    <thead><tr><th>Concepto</th><th class="r">Monto</th></tr></thead>
    <tbody>
        @foreach($gastosDetalle as $concepto => $monto)
            @if($monto > 0)<tr><td>{{ $concepto }}</td><td class="r">{{ $fmt($monto) }}</td></tr>@endif
        @endforeach
    </tbody>
    <tfoot><tr><td><b>Total Gastos Operativos</b></td><td class="r">{{ $fmt($gastos) }}</td></tr></tfoot>
</table>
@endif

{{-- Mora por bucket --}}
<div class="section-bar">Mora por bucket</div>
<table class="tbl avoid">
    <thead><tr><th>Bucket</th><th class="r">Monto</th><th class="r">% de cartera</th></tr></thead>
    <tbody>
        <tr><td>Mora 1-30</td><td class="r">{{ $fmt($mora0_30) }}</td><td class="r">{{ $cartera > 0 ? $fmtp(round($mora0_30/$cartera*100,2)) : '0.00%' }}</td></tr>
        <tr><td>Mora 31-60</td><td class="r">{{ $fmt($mora31_60) }}</td><td class="r">{{ $cartera > 0 ? $fmtp(round($mora31_60/$cartera*100,2)) : '0.00%' }}</td></tr>
        <tr><td>Mora 61-90</td><td class="r">{{ $fmt($mora61_90) }}</td><td class="r">{{ $cartera > 0 ? $fmtp(round($mora61_90/$cartera*100,2)) : '0.00%' }}</td></tr>
        <tr><td>Mora 91-120</td><td class="r">{{ $fmt($mora91_120) }}</td><td class="r">{{ $cartera > 0 ? $fmtp(round($mora91_120/$cartera*100,2)) : '0.00%' }}</td></tr>
        <tr><td>Mora 120+</td><td class="r">{{ $fmt($mora120p) }}</td><td class="r">{{ $cartera > 0 ? $fmtp(round($mora120p/$cartera*100,2)) : '0.00%' }}</td></tr>
    </tbody>
    <tfoot><tr><td><b>Mora total</b></td><td class="r">{{ $fmt($moraTotal) }}</td><td class="r">{{ $fmtp($moraPct) }}</td></tr></tfoot>
</table>

{{-- Préstamos intersucursales y excedente — informativo --}}
<div class="section-bar alt">Préstamos intersucursales y excedente</div>
<div class="ok-box">
    Préstamos intersucursales (fondea): <b>{{ $fmt0($fondeo) }}</b>
    &nbsp;&nbsp;·&nbsp;&nbsp;
    Excedente enviado a corporativo: <b>{{ $fmt0($excedente) }}</b>
</div>
<div class="note">Movimientos de liquidez — no afectan recuperación, OPEX, nómina ni EBITDA.</div>

</body>
</html>
