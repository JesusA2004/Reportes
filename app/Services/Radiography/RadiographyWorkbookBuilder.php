<?php

namespace App\Services\Radiography;

use App\Models\Branch;
use App\Models\MonthlyEmployeeSummary;
use App\Models\Period;
use App\Models\PeriodSummary;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RadiographyWorkbookBuilder
{
    private const BG_DARK    = 'FF0F172A';
    private const BG_BLUE    = 'FF1D4ED8';
    private const BG_HDR     = 'FFDBEAFE';
    private const FG_HDR     = 'FF1E3A8A';
    private const BG_META    = 'FFF1F5F9';
    private const FG_META    = 'FF475569';
    private const BG_ALT     = 'FFF8FAFC';
    private const BG_TOTAL   = 'FF334155';
    private const FG_WHITE   = 'FFFFFFFF';
    private const FG_RED     = 'FFB91C1C';
    private const BG_EVEN    = 'FFFFFFFF';
    private const BORDER_LT  = 'FFE2E8F0';
    private const CURRENCY   = '"$"#,##0.00';
    private const PERCENT    = '0.00"%"';

    public function build(Period $period, PeriodSummary $summary): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setTitle('Radiografía ' . $period->label)
            ->setCreator('Sistema de Reportes')
            ->setSubject('Radiografía Financiera')
            ->setDescription('Generado automáticamente');

        $this->buildGlobalSheet($spreadsheet, $period, $summary);
        $this->buildEmployeesSheet($spreadsheet, $period);
        $this->buildBranchesSheet($spreadsheet, $period, $summary);

        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    // ── Sheet 1: RESUMEN GLOBAL ──────────────────────────────────────────────

    private function buildGlobalSheet(Spreadsheet $spreadsheet, Period $period, PeriodSummary $summary): void
    {
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('RESUMEN GLOBAL');

        $gm = $summary->global_metrics ?? [];

        // Row 1 — Title
        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', 'RADIOGRAFÍA — ' . strtoupper($period->label));
        $this->titleStyle($sheet, 'A1:G1');
        $sheet->getRowDimension(1)->setRowHeight(32);

        // Row 2 — Metadata
        $sheet->mergeCells('A2:G2');
        $sheet->setCellValue('A2', 'Sistema de Reportes | Periodo: ' . ($period->code ?: $period->id) . ' | Generado: ' . now()->format('d/m/Y H:i'));
        $this->metaStyle($sheet, 'A2:G2');
        $sheet->getRowDimension(2)->setRowHeight(16);

        // Row 3 — Type/scope
        $sheet->mergeCells('A3:G3');
        $sheet->setCellValue('A3', 'Tipo: Radiografía simple · Alcance: General');
        $this->metaStyle($sheet, 'A3:G3', self::FG_META);
        $sheet->getRowDimension(3)->setRowHeight(15);

        // Row 5 — Section header: Financial
        $sheet->mergeCells('A5:G5');
        $sheet->setCellValue('A5', 'MÉTRICAS FINANCIERAS');
        $this->sectionStyle($sheet, 'A5:G5');
        $sheet->getRowDimension(5)->setRowHeight(22);

        // Row 6 — Column headers
        $sheet->setCellValue('A6', 'CONCEPTO');
        $sheet->setCellValue('B6', 'VALOR');
        $this->colHeaderStyle($sheet, 'A6:G6');

        // Financial rows 7–12
        $finRows = [
            7  => ['Recuperación total',   (float)($gm['recuperacion_total'] ?? 0),   'currency'],
            8  => ['Colocación total',      (float)($gm['colocacion_total'] ?? 0),     'currency'],
            9  => ['Valor cartera total',   (float)($gm['valor_cartera_total'] ?? 0),  'currency'],
            10 => ['Cartera vencida',       (float)($gm['cartera_vencida_total'] ?? 0),'currency'],
            11 => ['Índice de mora',        (float)($gm['mora_porcentaje'] ?? 0),      'percent'],
            12 => ['Gastos totales',        (float)($gm['gasto_total'] ?? 0),          'currency'],
        ];

        foreach ($finRows as $row => [$label, $value, $fmt]) {
            $sheet->mergeCells("B{$row}:G{$row}");
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $this->dataRowStyle($sheet, "A{$row}:G{$row}", ($row % 2 === 1));
            if ($fmt === 'currency') {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            } else {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::PERCENT);
                if ($value > 25) {
                    $sheet->getStyle("B{$row}")->getFont()->getColor()->setARGB(self::FG_RED);
                    $sheet->getStyle("B{$row}")->getFont()->setBold(true);
                }
            }
        }

        // Row 14 — Section header: Payroll
        $sheet->mergeCells('A14:G14');
        $sheet->setCellValue('A14', 'NÓMINA / EMPLEADOS');
        $this->sectionStyle($sheet, 'A14:G14');
        $sheet->getRowDimension(14)->setRowHeight(22);

        // Row 15 — Column headers
        $sheet->setCellValue('A15', 'CONCEPTO');
        $sheet->setCellValue('B15', 'VALOR');
        $this->colHeaderStyle($sheet, 'A15:G15');

        $emp = MonthlyEmployeeSummary::query()
            ->where('period_id', $period->id)
            ->selectRaw('COUNT(*) as total, SUM(total_payments) as pagos, SUM(total_bonuses) as bonos, SUM(total_discounts) as descuentos, SUM(total_expenses) as gastos, SUM(net_amount) as neto')
            ->first();

        $payRows = [
            16 => ['Total empleados',    (int)($emp?->total ?? 0),          'number'],
            17 => ['Total pagos',        (float)($emp?->pagos ?? 0),         'currency'],
            18 => ['Total bonos',        (float)($emp?->bonos ?? 0),         'currency'],
            19 => ['Total descuentos',   (float)($emp?->descuentos ?? 0),    'currency'],
            20 => ['Total gastos',       (float)($emp?->gastos ?? 0),        'currency'],
            21 => ['Neto acumulado',     (float)($emp?->neto ?? 0),          'currency'],
        ];

        foreach ($payRows as $row => [$label, $value, $fmt]) {
            $sheet->mergeCells("B{$row}:G{$row}");
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValue("B{$row}", $value);
            $this->dataRowStyle($sheet, "A{$row}:G{$row}", ($row % 2 === 1));
            if ($fmt === 'currency') {
                $sheet->getStyle("B{$row}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            }
        }

        // Column widths
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(22);
        foreach (['C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(10);
        }
    }

    // ── Sheet 2: EMPLEADOS ───────────────────────────────────────────────────

    private function buildEmployeesSheet(Spreadsheet $spreadsheet, Period $period): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('EMPLEADOS');

        $rows = MonthlyEmployeeSummary::query()
            ->with(['employee:id,full_name', 'branch:id,name'])
            ->where('period_id', $period->id)
            ->orderByDesc('total_payments')
            ->get();

        if ($rows->isEmpty()) {
            $sheet->setCellValue('A1', 'Sin datos de empleados para este periodo.');
            return;
        }

        // Title
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', 'EMPLEADOS — ' . strtoupper($period->label));
        $this->titleStyle($sheet, 'A1:H1');
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Column headers
        $headers = ['EMPLEADO', 'SUCURSAL', 'PAGOS', 'BONOS', 'DESCUENTOS', 'GASTOS', 'NETO', 'ESTADO'];
        foreach ($headers as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue("{$col}2", $h);
        }
        $this->colHeaderStyle($sheet, 'A2:H2');

        // Data rows
        $totPagos = $totBonos = $totDesc = $totGastos = $totNeto = 0.0;
        $r = 3;
        foreach ($rows as $i => $row) {
            $sheet->setCellValue("A{$r}", $row->employee?->full_name ?? 'Sin empleado');
            $sheet->setCellValue("B{$r}", $row->branch?->name ?? '—');
            $sheet->setCellValue("C{$r}", (float)$row->total_payments);
            $sheet->setCellValue("D{$r}", (float)$row->total_bonuses);
            $sheet->setCellValue("E{$r}", (float)$row->total_discounts);
            $sheet->setCellValue("F{$r}", (float)$row->total_expenses);
            $sheet->setCellValue("G{$r}", (float)$row->net_amount);
            $sheet->setCellValue("H{$r}", $row->included_in_report ? 'Incluido' : 'Excluido');
            $this->dataRowStyle($sheet, "A{$r}:H{$r}", $i % 2 === 0);
            foreach (['C', 'D', 'E', 'F', 'G'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $totPagos   += (float)$row->total_payments;
            $totBonos   += (float)$row->total_bonuses;
            $totDesc    += (float)$row->total_discounts;
            $totGastos  += (float)$row->total_expenses;
            $totNeto    += (float)$row->net_amount;
            $r++;
        }

        // Totals
        $sheet->setCellValue("A{$r}", 'TOTALES');
        $sheet->setCellValue("C{$r}", $totPagos);
        $sheet->setCellValue("D{$r}", $totBonos);
        $sheet->setCellValue("E{$r}", $totDesc);
        $sheet->setCellValue("F{$r}", $totGastos);
        $sheet->setCellValue("G{$r}", $totNeto);
        $this->totalsStyle($sheet, "A{$r}:H{$r}");
        foreach (['C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
            $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }

        // Widths
        $sheet->getColumnDimension('A')->setWidth(36);
        $sheet->getColumnDimension('B')->setWidth(22);
        foreach (['C', 'D', 'E', 'F', 'G'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(16);
        }
        $sheet->getColumnDimension('H')->setWidth(11);

        $sheet->setAutoFilter("A2:H2");
        $sheet->freezePane('A3');
    }

    // ── Sheet 3: SUCURSALES ──────────────────────────────────────────────────

    private function buildBranchesSheet(Spreadsheet $spreadsheet, Period $period, PeriodSummary $summary): void
    {
        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle('SUCURSALES');

        $branchSummaries = $summary->branchSummaries;

        if ($branchSummaries->isEmpty()) {
            $sheet->setCellValue('A1', 'Sin datos por sucursal para este periodo.');
            return;
        }

        // Title
        $sheet->mergeCells('A1:G1');
        $sheet->setCellValue('A1', 'SUCURSALES — ' . strtoupper($period->label));
        $this->titleStyle($sheet, 'A1:G1');
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Column headers
        $headers = ['SUCURSAL', 'RECUPERACIÓN', 'COLOCACIÓN', 'CARTERA', 'C. VENCIDA', 'MORA %', 'GASTOS'];
        foreach ($headers as $i => $h) {
            $col = chr(ord('A') + $i);
            $sheet->setCellValue("{$col}2", $h);
        }
        $this->colHeaderStyle($sheet, 'A2:G2');

        $r = 3;
        $branchCache = [];
        foreach ($branchSummaries as $i => $bs) {
            $branchId = $bs->branch_id;
            if (!isset($branchCache[$branchId])) {
                $branchCache[$branchId] = Branch::query()->find($branchId)?->name ?? "Sucursal #{$branchId}";
            }
            $m = $bs->metrics ?? [];
            $mora = (float)($m['mora_porcentaje'] ?? 0);

            $sheet->setCellValue("A{$r}", $branchCache[$branchId]);
            $sheet->setCellValue("B{$r}", (float)($m['recuperacion_total'] ?? 0));
            $sheet->setCellValue("C{$r}", (float)($m['colocacion_total'] ?? 0));
            $sheet->setCellValue("D{$r}", (float)($m['valor_cartera'] ?? 0));
            $sheet->setCellValue("E{$r}", (float)($m['cartera_vencida'] ?? 0));
            $sheet->setCellValue("F{$r}", $mora);
            $sheet->setCellValue("G{$r}", (float)($m['gasto_total'] ?? 0));
            $this->dataRowStyle($sheet, "A{$r}:G{$r}", $i % 2 === 0);

            foreach (['B', 'C', 'D', 'E', 'G'] as $col) {
                $sheet->getStyle("{$col}{$r}")->getNumberFormat()->setFormatCode(self::CURRENCY);
                $sheet->getStyle("{$col}{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
            $sheet->getStyle("F{$r}")->getNumberFormat()->setFormatCode(self::PERCENT);
            $sheet->getStyle("F{$r}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            if ($mora > 25) {
                $sheet->getStyle("F{$r}")->getFont()->getColor()->setARGB(self::FG_RED);
                $sheet->getStyle("F{$r}")->getFont()->setBold(true);
            }
            $r++;
        }

        // Widths
        $sheet->getColumnDimension('A')->setWidth(28);
        foreach (['B', 'C', 'D', 'E', 'G'] as $col) {
            $sheet->getColumnDimension($col)->setWidth(18);
        }
        $sheet->getColumnDimension('F')->setWidth(10);

        $sheet->setAutoFilter("A2:G2");
        $sheet->freezePane('A3');
    }

    // ── Style helpers ────────────────────────────────────────────────────────

    private function titleStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_DARK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
    }

    private function metaStyle(Worksheet $sheet, string $range, string $fgArgb = 'FF334155'): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['size' => 9, 'color' => ['argb' => $fgArgb]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_META]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
    }

    private function sectionStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 11, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_BLUE]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
        ]);
    }

    private function colHeaderStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_HDR]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_HDR]],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => '
FF93C5FD']]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);
        preg_match('/(\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(18);
        }
    }

    private function dataRowStyle(Worksheet $sheet, string $range, bool $even): void
    {
        $bg = $even ? self::BG_EVEN : self::BG_ALT;
        $sheet->getStyle($range)->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['argb' => self::BORDER_LT]]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        $sheet->getStyle($range)->getFont()->setSize(9);
        // Bold label in first column
        preg_match('/[A-Z](\d+)/', $range, $m);
        if (!empty($m[1])) {
            $row = (int)$m[1];
            $sheet->getStyle("A{$row}")->getFont()->setBold(true);
            $sheet->getRowDimension($row)->setRowHeight(17);
        }
    }

    private function totalsStyle(Worksheet $sheet, string $range): void
    {
        $sheet->getStyle($range)->applyFromArray([
            'font'      => ['bold' => true, 'size' => 9, 'color' => ['argb' => self::FG_WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::BG_TOTAL]],
            'borders'   => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF94A3B8']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);
        preg_match('/[A-Z](\d+)/', $range, $m);
        if (!empty($m[1])) {
            $sheet->getRowDimension((int)$m[1])->setRowHeight(20);
        }
    }
}
