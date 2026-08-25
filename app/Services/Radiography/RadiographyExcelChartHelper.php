<?php

namespace App\Services\Radiography;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Capa reutilizable para insertar gráficas NATIVAS de Excel (no imágenes) sobre datos
 * YA escritos en la hoja — nunca recalcula nada financiero, solo referencia celdas.
 *
 * El writer Xlsx de este proyecto ya usa setIncludeCharts(true) (ver
 * RadiografiaExportService::export()/exportWithConfig()) — esta clase es la pieza que
 * faltaba: quién construye los Chart objects y los agrega a la hoja.
 *
 * Deliberadamente NO se agregan a ciegas 20 gráficas: cada chart requiere que su bloque
 * de datos exista y tenga al menos un valor > 0 — si no, no se agrega nada (mismo
 * criterio que RadiographyChartSvgBuilder para el PDF: nunca un chart vacío).
 */
class RadiographyExcelChartHelper
{
    /**
     * Escribe un bloque de datos pequeño y contiguo (columna de etiqueta + columna de
     * valor) a partir de la fila/columna indicadas, con los MISMOS valores ya
     * calculados por el snapshot canónico (nunca recalculados aquí), y agrega una
     * gráfica de barras nativa que los referencia. No hace nada si no hay ningún valor
     * > 0 — nunca un chart vacío.
     *
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

        $sheetTitle = $sheet->getTitle();
        $categories = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_STRING,
            "'{$sheetTitle}'!\${$labelCol}\${$dataStartRow}:\${$labelCol}\${$endRow}",
            null,
            count($data),
        );
        $values = new DataSeriesValues(
            DataSeriesValues::DATASERIES_TYPE_NUMBER,
            "'{$sheetTitle}'!\${$valueCol}\${$dataStartRow}:\${$valueCol}\${$endRow}",
            null,
            count($data),
        );

        $series = new DataSeries(
            DataSeries::TYPE_BARCHART,
            DataSeries::GROUPING_CLUSTERED,
            range(0, count($data) - 1),
            [null],
            [$categories],
            [$values],
        );
        $series->setPlotDirection(DataSeries::DIRECTION_COL);

        $plotArea = new PlotArea(null, [$series]);
        $legend    = new Legend(Legend::POSITION_BOTTOM, null, false);
        $chartTitle = new Title($title);

        $chart = new Chart($chartName, $chartTitle, $legend, $plotArea);
        $chart->setTopLeftPosition($anchorCell);

        [$anchorColIdx, $anchorRow] = Coordinate::indexesFromString($anchorCell);
        $bottomRightCol = Coordinate::stringFromColumnIndex($anchorColIdx + $widthCells);
        $bottomRightRow = $anchorRow + $heightRows;
        $chart->setBottomRightPosition($bottomRightCol . $bottomRightRow);

        $sheet->addChart($chart);

        return true;
    }
}
