<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Comparativo {{ $comparePeriod->label }} vs {{ $period->label }}</title>
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
.avoid { page-break-inside: avoid; }

table.tbl { width: 100%; border-collapse: collapse; font-size: 8pt; }
table.tbl thead th { background: #1f2937; color: #fff; font-weight: bold; text-align: left; padding: 5px 6px; border-bottom: 1px solid #1f2937; }
table.tbl tbody td { padding: 5px 6px; border-bottom: 0.5pt solid #e2e8f0; vertical-align: top; }
table.tbl tbody tr:nth-child(even) td { background: #f8fafc; }
table.tbl .r { text-align: right; }
table.tbl .c { text-align: center; }
table.tbl .b { font-weight: bold; }
.pos { color: #15803d; }
.negv { color: #b91c1c; }
</style>
</head>
<body>

@php
$fmt  = fn($v) => '$' . number_format((float)$v, 2);
$fmtp = fn($v) => number_format((float)$v, 2) . '%';
$fmti = fn($v) => number_format((float)$v, 0);
$typeLabel = match($reportType) {
    'bimester_vs_bimester' => 'COMPARATIVO BIMESTRE',
    'quarter_vs_quarter'   => 'COMPARATIVO TRIMESTRE',
    default                => 'COMPARATIVO MES VS MES',
};
@endphp

<div class="brand">
    <div class="brand-mark">MR LANA</div>
    <div class="brand-sub">{{ $typeLabel }}</div>
    <div class="brand-meta">
        <b>Alcance:</b> {{ $scopeLabel }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <b>Periodo comparado:</b> {{ $comparePeriod->label }}
        &nbsp;&nbsp;·&nbsp;&nbsp;
        <b>Periodo actual:</b> {{ $period->label }}
    </div>
    @if(!empty($compareComposite) || !empty($currentComposite))
    <div class="brand-meta" style="margin-top:2px;">
        @if(!empty($compareComposite)){{ $compareComposite['component_range'] }}@else{{ $comparePeriod->label }}@endif
        &nbsp;vs&nbsp;
        @if(!empty($currentComposite)){{ $currentComposite['component_range'] }}@else{{ $period->label }}@endif
    </div>
    @endif
</div>

<div class="section-bar">Comparativo de métricas</div>
<table class="tbl avoid">
    <thead>
        <tr>
            <th>Métrica</th>
            <th class="r">{{ strtoupper($comparePeriod->label) }}</th>
            <th class="r">{{ strtoupper($period->label) }}</th>
            <th class="r">Diferencia</th>
            <th class="r">Var %</th>
        </tr>
    </thead>
    <tbody>
        @php $rowFmt = fn($v, $f) => $f === 'percent' ? $fmtp($v) : ($f === 'integer' ? $fmti($v) : $fmt($v)); @endphp
        @foreach($rows as $row)
        <tr>
            <td class="b">{{ $row['label'] }}</td>
            <td class="r">{{ $rowFmt($row['prev'], $row['fmt']) }}</td>
            <td class="r">{{ $rowFmt($row['curr'], $row['fmt']) }}</td>
            <td class="r">{{ $row['fmt'] === 'percent' ? $rowFmt($row['diff'], $row['fmt']) : ($row['diff'] >= 0 ? '+' : '') . $rowFmt($row['diff'], $row['fmt']) }}</td>
            <td class="r {{ $row['var_pct'] > 0 ? 'pos' : ($row['var_pct'] < 0 ? 'negv' : '') }}">{{ $row['var_pct'] >= 0 ? '+' : '' }}{{ number_format($row['var_pct'], 2) }}%</td>
        </tr>
        @endforeach
    </tbody>
</table>

</body>
</html>
