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

<div class="note">EBITDA estimado = Recuperación − Colocación − (Gastos + Nómina neta).</div>

</body>
</html>
