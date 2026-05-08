<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Radiografía {{ $period->label }}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #1e293b; background: #fff; }
@page { margin: 14mm 12mm 14mm 12mm; }

/* ─ Header portada ─ */
.cover { background: #0f172a; color: #fff; padding: 18px 20px 14px; margin-bottom: 16px; }
.cover-eye  { font-size: 7pt; color: #818cf8; letter-spacing: 2px; text-transform: uppercase; font-weight: bold; }
.cover-title { font-size: 18pt; font-weight: bold; margin-top: 4px; }
.cover-sub  { font-size: 9pt; color: #94a3b8; margin-top: 6px; line-height: 1.6; }

/* ─ Section titles ─ */
.section-title {
    background: #1d4ed8; color: #fff;
    padding: 5px 10px; font-size: 9pt; font-weight: bold;
    margin-top: 14px; margin-bottom: 0;
}
.section-sub {
    background: #334155; color: #cbd5e1;
    padding: 3px 10px; font-size: 8pt;
    margin-top: 8px; margin-bottom: 0;
}

/* ─ KPI cards (4-column table layout) ─ */
.cards { width: 100%; margin: 10px 0; border-collapse: collapse; }
.card  { width: 25%; padding: 8px 10px; border: 1px solid #e2e8f0; background: #f8fafc; vertical-align: top; }
.card-label { font-size: 7pt; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
.card-value { font-size: 13pt; font-weight: bold; color: #0f172a; margin-top: 3px; }
.card-red   { color: #b91c1c !important; }

/* ─ Tables ─ */
table.data { width: 100%; border-collapse: collapse; font-size: 8.5pt; }
table.data th { background: #dbeafe; color: #1e3a8a; font-weight: bold; text-align: left; padding: 5px 7px; border-bottom: 1.5px solid #93c5fd; }
table.data td { padding: 4px 7px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
table.data tr:nth-child(even) td { background: #f8fafc; }
table.data .r { text-align: right; }
table.data .c { text-align: center; }
table.data .b { font-weight: bold; }
.totals-row td { background: #334155 !important; color: #fff !important; font-weight: bold !important; }

/* ─ Metric table (2-col) ─ */
table.metric { width: 100%; border-collapse: collapse; font-size: 9pt; }
table.metric td { padding: 5px 8px; border-bottom: 1px solid #e2e8f0; }
table.metric tr:nth-child(even) td { background: #f8fafc; }
table.metric td:first-child { font-weight: bold; width: 55%; }
table.metric td:last-child  { text-align: right; }

/* ─ Bar chart ─ */
.bar-chart { width: 100%; margin: 6px 0; }
.bar-row { margin-bottom: 5px; }
.bar-label { font-size: 8pt; color: #334155; margin-bottom: 2px; white-space: nowrap; overflow: hidden; }
.bar-track { background: #e2e8f0; width: 100%; height: 12px; border-radius: 2px; }
.bar-fill  { background: #1d4ed8; height: 12px; border-radius: 2px; display: block; min-width: 2px; }
.bar-fill-red  { background: #b91c1c; }
.bar-fill-green { background: #15803d; }
.bar-value { font-size: 7.5pt; color: #475569; margin-top: 1px; text-align: right; }

/* ─ Badge ─ */
.badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 7.5pt; font-weight: bold; }
.b-high    { background: #fee2e2; color: #991b1b; }
.b-warning { background: #fef3c7; color: #92400e; }
.b-info    { background: #dbeafe; color: #1e3a8a; }

/* ─ Page break ─ */
.pb { page-break-before: always; }

/* ─ Footer ─ */
.footer { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 7.5pt; color: #64748b; text-align: center; }
.warn-box { background: #fef3c7; border: 1px solid #fcd34d; padding: 6px 10px; margin: 8px 0; font-size: 8pt; color: #92400e; }
</style>
</head>
<body>

@php
$snap   = $snapshot;
$sum    = $snap['summary'];
$pay    = $snap['sections']['payroll'];
$fmt    = fn($v) => '$' . number_format((float)$v, 2);
$fmtp   = fn($v) => number_format((float)$v, 2) . '%';
$fmtn   = fn($v) => number_format((float)$v, 0);
@endphp

<!-- ═══ PORTADA / HEADER ════════════════════════════════════════════ -->
<div class="cover">
    <div class="cover-eye">Sistema de Reportes · Radiografía Financiera</div>
    <div class="cover-title">RADIOGRAFÍA — {{ strtoupper($period->label) }}</div>
    <div class="cover-sub">
        Periodo: {{ $snap['period']['start_date'] }} — {{ $snap['period']['end_date'] }}
        &nbsp;·&nbsp; Código: {{ $snap['period']['code'] ?? $period->id }}
        &nbsp;·&nbsp; Generado: {{ $snap['generated_at'] }}
        &nbsp;·&nbsp; Tipo: Radiografía simple · Alcance: General
    </div>
</div>

<!-- ═══ KPI CARDS ════════════════════════════════════════════════════ -->
<table class="cards">
    <tr>
        <td class="card">
            <div class="card-label">Empleados</div>
            <div class="card-value">{{ $fmtn($sum['employees_count']) }}</div>
        </td>
        <td class="card">
            <div class="card-label">Recuperación</div>
            <div class="card-value">{{ $fmt($sum['recovery_total']) }}</div>
        </td>
        <td class="card">
            <div class="card-label">Colocación</div>
            <div class="card-value">{{ $fmt($sum['placement_total']) }}</div>
        </td>
        <td class="card">
            <div class="card-label">Índice de mora</div>
            <div class="card-value @if($sum['mora_index'] > 25) card-red @endif">{{ $fmtp($sum['mora_index']) }}</div>
        </td>
    </tr>
    <tr>
        <td class="card">
            <div class="card-label">Cartera total</div>
            <div class="card-value">{{ $fmt($sum['portfolio_total']) }}</div>
        </td>
        <td class="card">
            <div class="card-label">Cartera vencida</div>
            <div class="card-value @if($sum['overdue_portfolio'] > 0) card-red @endif">{{ $fmt($sum['overdue_portfolio']) }}</div>
        </td>
        <td class="card">
            <div class="card-label">Neto nómina</div>
            <div class="card-value">{{ $fmt($sum['net_payroll']) }}</div>
        </td>
        <td class="card">
            <div class="card-label">Gastos totales</div>
            <div class="card-value">{{ $fmt($sum['expenses_total']) }}</div>
        </td>
    </tr>
</table>

<!-- ═══ MÉTRICAS FINANCIERAS ════════════════════════════════════════ -->
<div class="section-title">MÉTRICAS FINANCIERAS</div>
<table class="metric">
    <tr><td>Recuperación total</td><td>{{ $fmt($sum['recovery_total']) }}</td></tr>
    <tr><td>Colocación total</td><td>{{ $fmt($sum['placement_total']) }}</td></tr>
    <tr><td>Valor cartera total</td><td>{{ $fmt($sum['portfolio_total']) }}</td></tr>
    <tr><td>Cartera vencida</td><td @if($sum['overdue_portfolio'] > 0) style="color:#b91c1c;font-weight:bold;" @endif>{{ $fmt($sum['overdue_portfolio']) }}</td></tr>
    <tr><td>Índice de mora</td><td @if($sum['mora_index'] > 25) style="color:#b91c1c;font-weight:bold;" @endif>{{ $fmtp($sum['mora_index']) }}</td></tr>
    <tr><td>Gastos totales</td><td>{{ $fmt($sum['expenses_total']) }}</td></tr>
</table>

<!-- ═══ NÓMINA / EMPLEADOS ══════════════════════════════════════════ -->
<div class="section-title">NÓMINA / EMPLEADOS — RESUMEN</div>
<table class="metric">
    <tr><td>Total empleados</td><td>{{ $fmtn($pay['total_empleados']) }}</td></tr>
    <tr><td>Total pagos (percepciones)</td><td>{{ $fmt($pay['pagos']) }}</td></tr>
    <tr><td>Total bonos</td><td>{{ $fmt($pay['bonos']) }}</td></tr>
    <tr><td>Total descuentos</td><td>{{ $fmt($pay['descuentos']) }}</td></tr>
    <tr><td>Total gastos empleados</td><td>{{ $fmt($pay['gastos']) }}</td></tr>
    <tr><td style="background:#334155;color:#fff;font-weight:bold;">Neto acumulado</td>
        <td style="background:#334155;color:#fff;font-weight:bold;text-align:right;">{{ $fmt($pay['neto']) }}</td></tr>
</table>
@if(($pay['source'] ?? '') === 'noi_direct')
<div class="warn-box">⚠ Nómina calculada directamente de movimientos NOI. Si los montos parecen incorrectos, verifica que el tipo de concepto (concept_type) se haya importado correctamente.</div>
@endif

<!-- ═══ GRÁFICA: COLOCACIÓN POR PRODUCTO ════════════════════════════ -->
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
@else
<div class="section-title">COLOCACIÓN POR PRODUCTO</div>
<div class="warn-box">Sin datos de producto en ministraciones.</div>
@endif

<!-- ═══ GRÁFICA: MORA POR BUCKET ════════════════════════════════════ -->
@if(!empty($snap['charts']['mora_by_bucket']))
<div class="section-title">MORA POR BUCKET (DÍAS VENCIDOS)</div>
<div class="bar-chart">
    @foreach($snap['charts']['mora_by_bucket'] as $row)
    <div class="bar-row">
        <div class="bar-label">{{ $row['label'] }}</div>
        <div class="bar-track"><span class="bar-fill bar-fill-red" style="width:{{ $row['pct'] }}%;"></span></div>
        <div class="bar-value">{{ $fmt($row['value']) }}</div>
    </div>
    @endforeach
</div>
@endif

<!-- ═══ GRÁFICA: RECUPERACIÓN POR SUCURSAL ══════════════════════════ -->
@if(!empty($snap['charts']['recovery_by_branch']))
<div class="section-title">RECUPERACIÓN POR SUCURSAL (TOP 10)</div>
<div class="bar-chart">
    @foreach($snap['charts']['recovery_by_branch'] as $row)
    <div class="bar-row">
        <div class="bar-label">{{ mb_strimwidth($row['label'], 0, 50, '...') }}</div>
        <div class="bar-track"><span class="bar-fill bar-fill-green" style="width:{{ $row['pct'] }}%;"></span></div>
        <div class="bar-value">{{ $fmt($row['value']) }}</div>
    </div>
    @endforeach
</div>
@endif

<!-- ═══ TOP GESTORES ═════════════════════════════════════════════════ -->
@if(!empty($snap['charts']['top_promoters_placement']))
<div class="pb"></div>
<div class="section-title">TOP GESTORES / PROMOTORES — POR COLOCACIÓN</div>
<div class="bar-chart">
    @foreach($snap['charts']['top_promoters_placement'] as $row)
    <div class="bar-row">
        <div class="bar-label">{{ mb_strimwidth($row['label'], 0, 50, '...') }}</div>
        <div class="bar-track"><span class="bar-fill" style="width:{{ $row['pct'] }}%;"></span></div>
        <div class="bar-value">{{ $fmt($row['value']) }}</div>
    </div>
    @endforeach
</div>
@endif

<!-- ═══ SUCURSALES ═══════════════════════════════════════════════════ -->
@if(!empty($snap['sections']['branches']))
<div class="section-title">DESGLOSE POR SUCURSAL</div>
<table class="data">
    <tr>
        <th>Sucursal</th>
        <th class="r">Recuperación</th>
        <th class="r">Colocación</th>
        <th class="r">Cartera</th>
        <th class="r">Vencida</th>
        <th class="r">Mora %</th>
        <th class="r">Gastos</th>
    </tr>
    @foreach($snap['sections']['branches'] as $b)
    <tr>
        <td class="b">{{ $b['nombre'] }}</td>
        <td class="r">{{ $fmt($b['recuperacion']) }}</td>
        <td class="r">{{ $fmt($b['colocacion']) }}</td>
        <td class="r">{{ $fmt($b['cartera']) }}</td>
        <td class="r" @if($b['vencida'] > 0) style="color:#b91c1c;" @endif>{{ $fmt($b['vencida']) }}</td>
        <td class="r @if($b['mora'] > 25) card-red @endif">{{ $fmtp($b['mora']) }}</td>
        <td class="r">{{ $fmt($b['gastos']) }}</td>
    </tr>
    @endforeach
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
@else
<div class="section-title">CARTERA POR DÍAS VENCIDOS</div>
<div class="warn-box">Sin datos de días vencidos. Verifica que el archivo "Lendus Saldos por Cliente" incluya la columna "días_mora" o "días_vencidos".</div>
@endif

<!-- ═══ PRODUCTOS ════════════════════════════════════════════════════ -->
@if(!empty($snap['sections']['products']))
<div class="pb"></div>
<div class="section-title">COLOCACIÓN POR PRODUCTO FINANCIERO</div>
<table class="data">
    <tr>
        <th>Producto</th>
        <th class="r">Operaciones</th>
        <th class="r">Total colocado</th>
    </tr>
    @foreach($snap['sections']['products'] as $p)
    <tr>
        <td class="b">{{ $p['producto'] }}</td>
        <td class="r">{{ $fmtn($p['operaciones']) }}</td>
        <td class="r">{{ $fmt($p['colocacion']) }}</td>
    </tr>
    @endforeach
</table>
@endif

<!-- ═══ EMPLEADOS / GESTORES (fusionado, top 25) ═════════════════════ -->
@php $empGest = $snap['sections']['employees_gestores'] ?? []; @endphp
@if(!empty($empGest))
<div class="pb"></div>
<div class="section-title">EMPLEADOS / GESTORES — TOP {{ min(25, count($empGest)) }} POR COLOCACIÓN + NÓMINA</div>
<table class="data">
    <tr>
        <th>Empleado / Gestor</th>
        <th>Sucursal</th>
        <th class="r">Pagos</th>
        <th class="r">Neto nómina</th>
        <th class="r">Colocación</th>
        <th class="r">Ops</th>
        <th class="r">Cartera</th>
        <th class="r">Mora %</th>
    </tr>
    @foreach(array_slice($empGest, 0, 25) as $e)
    <tr>
        <td class="b">{{ $e['name'] }}</td>
        <td>{{ $e['branch'] !== 'Sin sucursal' ? $e['branch'] : '—' }}</td>
        <td class="r">{{ $e['pagos'] > 0 ? $fmt($e['pagos']) : '—' }}</td>
        <td class="r">{{ $e['neto'] > 0 ? $fmt($e['neto']) : '—' }}</td>
        <td class="r">{{ $e['colocacion'] > 0 ? $fmt($e['colocacion']) : '—' }}</td>
        <td class="r">{{ $e['operaciones'] > 0 ? $fmtn($e['operaciones']) : '—' }}</td>
        <td class="r">{{ $e['cartera'] > 0 ? $fmt($e['cartera']) : '—' }}</td>
        <td class="r @if($e['mora'] > 25) card-red @endif">{{ $e['cartera'] > 0 ? $fmtp($e['mora']) : '—' }}</td>
    </tr>
    @endforeach
    @if(count($empGest) > 25)
    <tr><td colspan="8" style="text-align:center;color:#64748b;font-style:italic;font-size:8pt;">... y {{ count($empGest) - 25 }} más. Ver Excel para detalle completo.</td></tr>
    @endif
</table>
@endif

<!-- ═══ GASTOS POR CATEGORÍA ══════════════════════════════════════════ -->
@php $expDetail = $snap['sections']['expenses_detail'] ?? []; @endphp
@if(!empty($expDetail['byCategory']))
<div class="section-title">GASTOS POR CATEGORÍA — Total: {{ $fmt($expDetail['total'] ?? 0) }}</div>
<table class="data">
    <tr>
        <th>Categoría</th>
        <th class="r">Registros</th>
        <th class="r">Total</th>
    </tr>
    @foreach($expDetail['byCategory'] as $c)
    <tr>
        <td class="b">{{ $c['categoria'] }}</td>
        <td class="r">{{ $fmtn($c['count']) }}</td>
        <td class="r">{{ $fmt($c['total']) }}</td>
    </tr>
    @endforeach
</table>
@endif

<!-- ═══ GASTOS POR SUCURSAL ════════════════════════════════════════════ -->
@if(!empty($expDetail['byBranch']))
<div class="section-sub">Por sucursal</div>
<table class="data">
    <tr>
        <th>Sucursal</th>
        <th class="r">Registros</th>
        <th class="r">Total</th>
    </tr>
    @foreach($expDetail['byBranch'] as $b)
    <tr>
        <td class="b">{{ $b['sucursal'] }}</td>
        <td class="r">{{ $fmtn($b['count']) }}</td>
        <td class="r">{{ $fmt($b['total']) }}</td>
    </tr>
    @endforeach
</table>
@endif

<!-- ═══ INCIDENCIAS ══════════════════════════════════════════════════ -->
@if(!empty($snap['sections']['incidents']))
<div class="section-title">INCIDENCIAS DEL PERIODO</div>
<table class="data">
    <tr><th>Severidad</th><th>Tipo</th><th>Mensaje</th></tr>
    @foreach($snap['sections']['incidents'] as $i)
    <tr>
        <td class="c"><span class="badge b-{{ $i['severity'] }}">{{ strtoupper($i['severity']) }}</span></td>
        <td>{{ $i['type'] }}</td>
        <td>{{ $i['message'] }}</td>
    </tr>
    @endforeach
</table>
@endif

<!-- ═══ FOOTER ═══════════════════════════════════════════════════════ -->
<div class="footer">
    Radiografía {{ $period->label }} &nbsp;·&nbsp; Sistema de Reportes &nbsp;·&nbsp; {{ $snap['generated_at'] }} &nbsp;·&nbsp; Versión {{ $snap['version'] }}
</div>

</body>
</html>
