<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Radiografía {{ $period->label }}</title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #1e293b; background: #fff; }

/* ── Header ── */
.header { background: #0f172a; color: #ffffff; padding: 14px 18px; margin-bottom: 14px; }
.header-eyebrow { font-size: 7pt; font-weight: bold; color: #818cf8; letter-spacing: 2px; text-transform: uppercase; }
.header-title { font-size: 17pt; font-weight: bold; margin-top: 3px; }
.header-meta { font-size: 8pt; color: #94a3b8; margin-top: 5px; }
.header-meta-row { margin-top: 2px; }

/* ── Section title ── */
.section-title {
    background: #1d4ed8;
    color: #ffffff;
    padding: 5px 10px;
    font-size: 10pt;
    font-weight: bold;
    margin-top: 14px;
    margin-bottom: 0;
}

/* ── Tables ── */
table { width: 100%; border-collapse: collapse; font-size: 9pt; }
th {
    background: #dbeafe;
    color: #1e3a8a;
    font-weight: bold;
    text-align: left;
    padding: 5px 8px;
    border-bottom: 1px solid #93c5fd;
}
td { padding: 4px 8px; border-bottom: 1px solid #e2e8f0; }
tr:nth-child(even) td { background: #f8fafc; }
.td-right { text-align: right; }
.td-bold { font-weight: bold; }
.td-red { color: #b91c1c; font-weight: bold; }
.td-green { color: #15803d; }

/* ── Metrics 2-column layout ── */
.metrics-table td:first-child { font-weight: bold; width: 55%; }
.metrics-table td:last-child { text-align: right; }

/* ── Totals row ── */
.totals-row td { background: #334155 !important; color: #ffffff; font-weight: bold; }

/* ── Summary cards ── */
.cards-row { width: 100%; margin: 10px 0; }
.cards-row table { width: 100%; }
.card-cell { width: 25%; padding: 6px 8px; border: 1px solid #e2e8f0; background: #f8fafc; }
.card-label { font-size: 7pt; color: #64748b; font-weight: bold; text-transform: uppercase; letter-spacing: 1px; }
.card-value { font-size: 13pt; font-weight: bold; color: #0f172a; margin-top: 2px; }

/* ── Page break ── */
.page-break { page-break-before: always; }

/* ── Footer ── */
.footer { margin-top: 20px; padding-top: 8px; border-top: 1px solid #e2e8f0; font-size: 8pt; color: #64748b; text-align: center; }

/* ── Severity badges ── */
.badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 8pt; font-weight: bold; }
.badge-high { background: #fee2e2; color: #991b1b; }
.badge-warning { background: #fef3c7; color: #92400e; }
.badge-resolved { background: #dcfce7; color: #14532d; }
</style>
</head>
<body>

<!-- ── Header ── -->
<div class="header">
    <div class="header-eyebrow">Sistema de Reportes · Radiografía Financiera</div>
    <div class="header-title">RADIOGRAFÍA — {{ strtoupper($period->label) }}</div>
    <div class="header-meta">
        <div class="header-meta-row">Periodo: {{ $period->code ?: $period->id }} &nbsp;|&nbsp; Generado: {{ now()->format('d/m/Y H:i') }}</div>
        <div class="header-meta-row">Tipo: Radiografía simple &nbsp;|&nbsp; Alcance: General</div>
    </div>
</div>

<!-- ── Summary Cards ── -->
<div class="cards-row">
    <table>
        <tr>
            <td class="card-cell">
                <div class="card-label">Empleados</div>
                <div class="card-value">{{ number_format($payroll['total_empleados'] ?? 0) }}</div>
            </td>
            <td class="card-cell">
                <div class="card-label">Recuperación</div>
                <div class="card-value">{{ '$' . number_format($metrics['recuperacion_total'] ?? 0, 2) }}</div>
            </td>
            <td class="card-cell">
                <div class="card-label">Colocación</div>
                <div class="card-value">{{ '$' . number_format($metrics['colocacion_total'] ?? 0, 2) }}</div>
            </td>
            <td class="card-cell">
                <div class="card-label">Índice de mora</div>
                <div class="card-value @if(($metrics['mora_porcentaje'] ?? 0) > 25) td-red @endif">
                    {{ number_format($metrics['mora_porcentaje'] ?? 0, 2) }}%
                </div>
            </td>
        </tr>
    </table>
</div>

<!-- ── Métricas Financieras ── -->
<div class="section-title">MÉTRICAS FINANCIERAS</div>
<table class="metrics-table">
    <tr>
        <th>Concepto</th>
        <th style="text-align:right;">Valor</th>
    </tr>
    <tr><td>Recuperación total</td><td class="td-right">${{ number_format($metrics['recuperacion_total'] ?? 0, 2) }}</td></tr>
    <tr><td>Colocación total</td><td class="td-right">${{ number_format($metrics['colocacion_total'] ?? 0, 2) }}</td></tr>
    <tr><td>Valor cartera total</td><td class="td-right">${{ number_format($metrics['valor_cartera_total'] ?? 0, 2) }}</td></tr>
    <tr><td>Cartera vencida</td><td class="td-right">${{ number_format($metrics['cartera_vencida_total'] ?? 0, 2) }}</td></tr>
    <tr>
        <td>Índice de mora</td>
        <td class="td-right @if(($metrics['mora_porcentaje'] ?? 0) > 25) td-red @endif">
            {{ number_format($metrics['mora_porcentaje'] ?? 0, 2) }}%
        </td>
    </tr>
    <tr><td>Gastos totales</td><td class="td-right">${{ number_format($metrics['gasto_total'] ?? 0, 2) }}</td></tr>
</table>

<!-- ── Nómina / Empleados ── -->
<div class="section-title">NÓMINA / EMPLEADOS — RESUMEN</div>
<table class="metrics-table">
    <tr>
        <th>Concepto</th>
        <th style="text-align:right;">Valor</th>
    </tr>
    <tr><td>Total empleados</td><td class="td-right">{{ number_format($payroll['total_empleados'] ?? 0) }}</td></tr>
    <tr><td>Total pagos</td><td class="td-right">${{ number_format($payroll['pagos'] ?? 0, 2) }}</td></tr>
    <tr><td>Total bonos</td><td class="td-right">${{ number_format($payroll['bonos'] ?? 0, 2) }}</td></tr>
    <tr><td>Total descuentos</td><td class="td-right">${{ number_format($payroll['descuentos'] ?? 0, 2) }}</td></tr>
    <tr><td>Total gastos nómina</td><td class="td-right">${{ number_format($payroll['gastos'] ?? 0, 2) }}</td></tr>
    <tr class="totals-row"><td>Neto acumulado</td><td class="td-right">${{ number_format($payroll['neto'] ?? 0, 2) }}</td></tr>
</table>

@if(!empty($employees))
<!-- ── Detalle de empleados (top 30) ── -->
<div class="section-title">DETALLE POR EMPLEADO (TOP {{ count($employees) }})</div>
<table>
    <tr>
        <th>Empleado</th>
        <th>Sucursal</th>
        <th style="text-align:right;">Pagos</th>
        <th style="text-align:right;">Gastos</th>
        <th style="text-align:right;">Neto</th>
        <th style="text-align:center;">Estado</th>
    </tr>
    @foreach($employees as $emp)
    <tr>
        <td class="td-bold">{{ $emp['name'] }}</td>
        <td>{{ $emp['branch'] ?? '—' }}</td>
        <td class="td-right">${{ number_format($emp['pagos'] ?? 0, 2) }}</td>
        <td class="td-right">${{ number_format($emp['gastos'] ?? 0, 2) }}</td>
        <td class="td-right td-bold">${{ number_format($emp['neto'] ?? 0, 2) }}</td>
        <td style="text-align:center;font-size:8pt;">{{ $emp['included'] ? 'Incluido' : 'Excluido' }}</td>
    </tr>
    @endforeach
</table>
@endif

@if(!empty($branches))
<!-- ── Sucursales ── -->
<div class="section-title">DESGLOSE POR SUCURSAL</div>
<table>
    <tr>
        <th>Sucursal</th>
        <th style="text-align:right;">Recuperación</th>
        <th style="text-align:right;">Colocación</th>
        <th style="text-align:right;">Mora %</th>
        <th style="text-align:right;">Gastos</th>
    </tr>
    @foreach($branches as $branch)
    <tr>
        <td class="td-bold">{{ $branch['name'] }}</td>
        <td class="td-right">${{ number_format($branch['recuperacion'] ?? 0, 2) }}</td>
        <td class="td-right">${{ number_format($branch['colocacion'] ?? 0, 2) }}</td>
        <td class="td-right @if(($branch['mora'] ?? 0) > 25) td-red @endif">
            {{ number_format($branch['mora'] ?? 0, 2) }}%
        </td>
        <td class="td-right">${{ number_format($branch['gastos'] ?? 0, 2) }}</td>
    </tr>
    @endforeach
</table>
@endif

@if(!empty($incidents))
<!-- ── Incidencias ── -->
<div class="section-title">INCIDENCIAS DEL PERIODO</div>
<table>
    <tr>
        <th>Severidad</th>
        <th>Tipo</th>
        <th>Mensaje</th>
    </tr>
    @foreach($incidents as $inc)
    <tr>
        <td style="text-align:center;">
            <span class="badge badge-{{ $inc['severity'] }}">{{ strtoupper($inc['severity']) }}</span>
        </td>
        <td>{{ $inc['type'] }}</td>
        <td>{{ $inc['message'] }}</td>
    </tr>
    @endforeach
</table>
@endif

<!-- ── Footer ── -->
<div class="footer">
    Radiografía {{ $period->label }} · Sistema de Reportes · {{ now()->format('d/m/Y H:i') }}
</div>

</body>
</html>
