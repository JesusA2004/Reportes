<?php

namespace App\Services\Radiography;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Adaptador delgado sobre RadiographyStyleHelper::addBarChart() (el helper de charts
 * nativos YA existente y en uso — ver NÓMINA/addDonutChart, comparativo/
 * addComparativeBarChart) — escribe un bloque de datos [label, value] pequeño y
 * contiguo con los MISMOS valores ya calculados por el snapshot canónico (nunca
 * recalculados aquí), y delega la construcción del Chart en sí a ese helper.
 *
 * Corrección 2026-08-25: esta clase originalmente construía su propio Chart/
 * DataSeries/PlotArea, duplicando exactamente lo que RadiographyStyleHelper::
 * addBarChart() ya hacía. Se simplificó a un adaptador para no tener dos motores de
 * gráficas — RadiographyStyleHelper sigue siendo la ÚNICA fuente de construcción de
 * charts nativos en este proyecto.
 */
class RadiographyExcelChartHelper
{
    /**
     * @param array<int, array{label: string, value: float}> $data
     */
    public function addBarChartFromData(
        Worksheet $sheet,
        array $data,
        string $title,
        string $chartName,
        int $dataStartCol,
        int $dataStartRow,
        string $anchorCell,
        int $widthCells = 6,
        int $heightRows = 12,
    ): bool {
        $data = array_values(array_filter($data, fn ($d) => (float) $d['value'] > 0));
        if (empty($data)) {
            return false;
        }
        // Máximo 8 categorías — más que eso deja de ser legible en una gráfica de barras.
        $data = array_slice($data, 0, 8);

        $labelCol = Coordinate::stringFromColumnIndex($dataStartCol);
        $valueCol = Coordinate::stringFromColumnIndex($dataStartCol + 1);
        $endRow   = $dataStartRow + count($data) - 1;

        foreach ($data as $i => $row) {
            $r = $dataStartRow + $i;
            $sheet->setCellValue("{$labelCol}{$r}", $row['label']);
            $sheet->setCellValue("{$valueCol}{$r}", (float) $row['value']);
        }

        [$anchorColIdx, $anchorRow] = Coordinate::indexesFromString($anchorCell);
        $bottomRightCol = Coordinate::stringFromColumnIndex($anchorColIdx + $widthCells);
        $bottomRightRow = $anchorRow + $heightRows;

        RadiographyStyleHelper::addBarChart(
            $sheet,
            $title,
            "\${$labelCol}\${$dataStartRow}:\${$labelCol}\${$endRow}",
            "\${$valueCol}\${$dataStartRow}:\${$valueCol}\${$endRow}",
            count($data),
            $anchorCell,
            $bottomRightCol . $bottomRightRow,
        );

        return true;
    }
}
