<?php

namespace App\Services\Imports;

use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa el archivo ÍNDICE DE ROTACIÓN DE PERSONAL 2026.
 *
 * Solo importa el mes que corresponde al periodo del upload.
 * Ejemplo: si period.label contiene "Abril", importa la hoja/columna "ABRIL".
 *
 * Almacena en fact_rotacion (una fila por sucursal para el mes del periodo).
 */
class RotacionExcelImportService
{
    private const MESES_ES = [
        1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL',
        5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO',
        9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE',
    ];

    public function __construct(private readonly BranchResolverService $branchResolver) {}

    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }
        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @ini_set('memory_limit', '512M');
        @set_time_limit(0);

        $absolutePath = Storage::disk('public')->path($upload->stored_path);
        $reader       = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet  = $reader->load($absolutePath);

        // Determine target month from period
        $period    = $upload->period;
        $targetMes = $this->resolveTargetMes($period);

        // Delete ALL rotación for this period regardless of which upload_id produced them
        DB::table('fact_rotacion')->where('period_id', $upload->period_id)->delete();

        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;
        $usedSheet = null;

        // Strategy 1: find a sheet whose name matches the target month
        $targetSheet = null;
        foreach ($spreadsheet->getSheetNames() as $name) {
            $nameNorm = strtr(mb_strtoupper(trim($name)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
            if ($nameNorm === $targetMes || str_contains($nameNorm, $targetMes)) {
                $targetSheet = $spreadsheet->getSheetByName($name);
                $usedSheet   = $name;
                break;
            }
        }

        if ($targetSheet) {
            $rows   = $targetSheet->toArray(null, true, true, false);
            $result = $this->parseSheetAllBranches($rows, $targetMes, $usedSheet, $upload);
            $inserted += $result['inserted'];
            $skipped  += $result['skipped'];
            $errors   += $result['errors'];
        } else {
            // Strategy 2: scan every sheet for columns matching the target month.
            // Prefer sheets whose name contains the period year (e.g. "SUCURSALES 2026" over "INDICADOR 2025").
            $targetYear = (string) ($period?->year ?? date('Y'));
            $allSheets  = iterator_to_array($spreadsheet->getWorksheetIterator());
            usort($allSheets, static function ($a, $b) use ($targetYear) {
                $aY = str_contains($a->getTitle(), $targetYear);
                $bY = str_contains($b->getTitle(), $targetYear);
                if ($aY !== $bY) return $aY ? -1 : 1;
                return 0;
            });

            foreach ($allSheets as $ws) {
                $rows   = $ws->toArray(null, true, true, false);
                $result = $this->parseSheetMonthColumn($rows, $targetMes, $ws->getTitle(), $upload);
                if ($result['inserted'] > 0) {
                    $inserted  += $result['inserted'];
                    $skipped   += $result['skipped'];
                    $errors    += $result['errors'];
                    $usedSheet  = $ws->getTitle();
                    break;
                }
                $skipped += $result['skipped'];
            }
        }

        if ($progress) {
            $progress([
                'rows_read'        => $inserted + $skipped,
                'rows_inserted'    => $inserted,
                'rows_skipped'     => $skipped,
                'rows_with_errors' => $errors,
                'log'              => "Rotación importada (mes: {$targetMes}, hoja: {$usedSheet}). Insertadas: {$inserted}.",
            ]);
        }

        return [
            'rows_read'        => $inserted + $skipped,
            'rows_inserted'    => $inserted,
            'rows_skipped'     => $skipped,
            'rows_with_errors' => $errors,
            'log'              => sprintf(
                'Rotación importada. Mes: %s, Hoja: %s. Insertadas: %d, omitidas: %d, errores: %d.',
                $targetMes, $usedSheet ?? 'ninguna', $inserted, $skipped, $errors
            ),
        ];
    }

    /**
     * Parse a sheet where the target month covers all data rows (month-specific sheet).
     */
    private function parseSheetAllBranches(array $rows, string $mes, string $hoja, ReportUpload $upload): array
    {
        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;

        $headerIdx = $this->detectHeaderRow($rows);
        if ($headerIdx === null) {
            return ['inserted' => 0, 'skipped' => count($rows), 'errors' => 0];
        }

        $headers = $rows[$headerIdx];
        $colMap  = $this->buildColMapRotacion($headers);

        foreach (array_slice($rows, $headerIdx + 1) as $row) {
            if ($this->isEmptyRow($row)) { $skipped++; continue; }

            $sucursal  = $this->str($this->col($row, $colMap['sucursal']));
            $altas     = (int) ($this->toDecimal($this->col($row, $colMap['altas'])) ?? 0);
            $bajas     = (int) ($this->toDecimal($this->col($row, $colMap['bajas'])) ?? 0);
            $promedio  = $this->toDecimal($this->col($row, $colMap['promedio'])) ?? 0.0;
            $indice    = $this->toDecimal($this->col($row, $colMap['indice'])) ?? 0.0;

            if (!$sucursal || ($altas === 0 && $bajas === 0 && $promedio == 0.0 && $indice == 0.0)) {
                $skipped++;
                continue;
            }

            // Guard: reject metric-label names that are never real branch names
            $sucNorm = strtr(mb_strtoupper($sucursal), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
            $metricPfx = ['ALTA','BAJA','PLANTILL','ROTACI','PROMEDIO','INDICE'];
            $isMetric = false;
            foreach ($metricPfx as $pfx) {
                if (str_starts_with($sucNorm, $pfx)) { $isMetric = true; break; }
            }
            if ($isMetric) { $skipped++; continue; }

            // Derive index if not present but bajas and promedio are
            if ($indice == 0.0 && $promedio > 0.0 && $bajas > 0) {
                $indice = round(($bajas / $promedio) * 100, 4);
            }

            try {
                $branchId = null;
                $branchNorm = $this->branchResolver->resolveRealBranchFromRoute($sucursal);
                if ($branchNorm) {
                    $branch = $this->branchResolver->findOrCreateBranchByName($branchNorm);
                    $branchId = $branch?->id;
                }

                DB::table('fact_rotacion')->insert([
                    'period_id'        => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'sucursal_nombre'  => $sucursal,
                    'branch_id'        => $branchId,
                    'mes'              => $mes,
                    'bajas'            => $bajas,
                    'promedio_personal' => $promedio,
                    'indice_rotacion'  => $indice,
                    'hoja_fuente'      => $hoja,
                    'raw_payload'      => json_encode(['altas' => $altas, 'plantilla' => $promedio]),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
                $inserted++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Parse a sheet where sucursal blocks are stacked vertically.
     * Each block looks like:
     *   Row N  : SUCURSAL_NAME (in col 0, standalone)
     *   Row N+1: [empty] [ENERO] [FEB] [MAR] [ABR] ... (month headers)
     *   Row N+2: ALTAS      | n | n | n | n |
     *   Row N+3: BAJAS      | n | n | n | n |
     *   Row N+4: PLANTILLA  | n | n | n | n |
     *   Row N+5: ROTACIÓN   | n% | n% | n% | n% |
     */
    private function parseSheetMonthColumn(array $rows, string $targetMes, string $hoja, ReportUpload $upload): array
    {
        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;

        $norm = static function (string $v): string {
            return strtr(mb_strtoupper(trim($v)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        };

        $allMonths = ['ENERO','FEBRERO','MARZO','ABRIL','MAYO','JUNIO',
                      'JULIO','AGOSTO','SEPTIEMBRE','OCTUBRE','NOVIEMBRE','DICIEMBRE'];

        $isMonthHeaderRow = function (array $row) use ($allMonths, $norm): bool {
            $found = 0;
            foreach ($row as $cell) {
                $v = $norm((string) $cell);
                foreach ($allMonths as $m) {
                    if (str_contains($v, $m)) { $found++; break; }
                }
                if ($found >= 2) return true;
            }
            return false;
        };

        $metricPrefixes = ['ALTA', 'BAJA', 'PLANTILL', 'ROTACI', 'PROMEDIO', 'INDICE'];
        $isMetricLabel = function (string $v) use ($metricPrefixes, $norm): bool {
            $u = $norm($v);
            foreach ($metricPrefixes as $p) {
                if (str_starts_with($u, $p)) return true;
            }
            return false;
        };

        // Collect all month-header rows and their target-month column index
        $rowsArr = array_values($rows);
        $blocks  = []; // [{sucursal, targetCol, headerRowIdx}]

        foreach ($rowsArr as $idx => $row) {
            if (!$isMonthHeaderRow($row)) continue;

            // Find target month column
            $targetCol = null;
            foreach ($row as $colIdx => $cell) {
                $v = $norm((string) $cell);
                if ($v === $targetMes || str_contains($v, $targetMes)) {
                    $targetCol = $colIdx;
                    break;
                }
            }
            if ($targetCol === null) continue;

            // Find sucursal name: scan up to 5 rows above, checking ALL columns.
            // Skip pure numbers and metric labels — branch names are always text.
            $sucursalName = null;
            for ($back = 1; $back <= 5; $back++) {
                $prevIdx = $idx - $back;
                if ($prevIdx < 0) break;
                $prevRow = $rowsArr[$prevIdx] ?? [];
                if ($isMonthHeaderRow($prevRow)) break;
                foreach ($prevRow as $cell) {
                    $candidate = trim((string) $cell);
                    if ($candidate === '') continue;
                    if (is_numeric($candidate)) continue;
                    if ($isMetricLabel($candidate)) continue;
                    $sucursalName = $candidate;
                    break 2;
                }
            }

            if (!$sucursalName) continue;

            $blocks[] = ['sucursal' => $sucursalName, 'targetCol' => $targetCol, 'headerRow' => $idx];
        }

        if (empty($blocks)) {
            return ['inserted' => 0, 'skipped' => count($rows), 'errors' => 0];
        }

        foreach ($blocks as $block) {
            $sucursal  = $block['sucursal'];
            $targetCol = $block['targetCol'];
            $hdrIdx    = $block['headerRow'];

            $altas     = 0;
            $bajas     = 0;
            $plantilla = 0.0;
            $indice    = 0.0;

            // Read up to 7 metric rows below the month-header row
            for ($ri = $hdrIdx + 1; $ri <= $hdrIdx + 7; $ri++) {
                $mrow = $rowsArr[$ri] ?? null;
                if ($mrow === null) break;
                $label = $norm(trim((string) ($mrow[0] ?? '')));
                if ($label === '') continue;
                if ($isMonthHeaderRow($mrow)) break; // next block started

                $val = $this->toDecimal($mrow[$targetCol] ?? null);

                if (str_starts_with($label, 'ALTA')) {
                    $altas = (int) ($val ?? 0);
                } elseif (str_starts_with($label, 'BAJA')) {
                    $bajas = (int) ($val ?? 0);
                } elseif (str_starts_with($label, 'PLANTILL') || str_starts_with($label, 'PROMEDIO')) {
                    $plantilla = (float) ($val ?? 0);
                } elseif (str_starts_with($label, 'ROTACI') || str_starts_with($label, 'INDICE')) {
                    $indice = (float) ($val ?? 0);
                }
            }

            // Derive index if missing
            if ($indice == 0.0 && $plantilla > 0.0 && $bajas > 0) {
                $indice = round($bajas / $plantilla * 100, 4);
            }

            if ($altas === 0 && $bajas === 0 && $plantilla == 0.0 && $indice == 0.0) {
                $skipped++;
                continue;
            }

            try {
                $branchId   = null;
                $branchNorm = $this->branchResolver->resolveRealBranchFromRoute($sucursal);
                if ($branchNorm) {
                    $branch   = $this->branchResolver->findOrCreateBranchByName($branchNorm);
                    $branchId = $branch?->id;
                }

                DB::table('fact_rotacion')->insert([
                    'period_id'         => $upload->period_id,
                    'report_upload_id'  => $upload->id,
                    'sucursal_nombre'   => $sucursal,
                    'branch_id'         => $branchId,
                    'mes'               => $targetMes,
                    'bajas'             => $bajas,
                    'promedio_personal' => $plantilla,
                    'indice_rotacion'   => $indice,
                    'hoja_fuente'       => $hoja,
                    'raw_payload'       => json_encode(['altas' => $altas, 'plantilla' => $plantilla, 'col_abr' => $targetCol]),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);
                $inserted++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function resolveTargetMes(mixed $period): string
    {
        if (!$period) {
            return 'ABRIL';
        }

        // First: use the period's month number field (most reliable — start_date can be in prior month)
        if (!empty($period->month) && isset(self::MESES_ES[(int) $period->month])) {
            return self::MESES_ES[(int) $period->month];
        }

        // Second: try from period name text (e.g. "Abril 2026")
        $label = mb_strtoupper((string) ($period->name ?? $period->label ?? ''));
        foreach (self::MESES_ES as $mes) {
            $mesNorm = strtr($mes, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
            if (str_contains($label, $mes) || str_contains($label, $mesNorm)) {
                return $mes;
            }
        }

        // Last: try from start_date (risky — monthly periods often start in prior month's last week)
        if ($period->start_date) {
            try {
                $month = (int) \Carbon\Carbon::parse($period->start_date)->format('n');
                return self::MESES_ES[$month] ?? 'ABRIL';
            } catch (\Throwable) {}
        }

        return 'ABRIL';
    }

    private function detectHeaderRow(array $rows): ?int
    {
        $anchors = ['sucursal', 'unidad', 'clasificaci', 'bajas', 'promedio', 'rotaci', 'indice', 'tasa'];
        foreach (array_slice($rows, 0, 15, true) as $idx => $row) {
            $hits = 0;
            foreach ($row as $cell) {
                $norm = strtr(mb_strtolower(trim((string) $cell)), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
                foreach ($anchors as $anchor) {
                    if (str_contains($norm, $anchor)) { $hits++; break; }
                }
            }
            if ($hits >= 2) return (int) $idx;
        }
        return null;
    }

    private function buildColMapRotacion(array $headers): array
    {
        $map = ['sucursal' => 0, 'altas' => null, 'bajas' => null, 'promedio' => null, 'indice' => null];

        foreach ($headers as $idx => $cell) {
            $norm = strtr(mb_strtolower(trim((string) $cell)), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
            if (str_contains($norm, 'sucursal') || str_contains($norm, 'unidad') || str_contains($norm, 'clasificaci')) {
                $map['sucursal'] = $idx;
            } elseif ($map['altas'] === null && str_contains($norm, 'alta')) {
                $map['altas'] = $idx;
            } elseif ($map['bajas'] === null && str_contains($norm, 'baja')) {
                $map['bajas'] = $idx;
            } elseif ($map['promedio'] === null && (str_contains($norm, 'promedio') || str_contains($norm, 'personal') || str_contains($norm, 'plantilla'))) {
                $map['promedio'] = $idx;
            } elseif ($map['indice'] === null && (str_contains($norm, 'indice') || str_contains($norm, 'rotaci') || str_contains($norm, 'tasa'))) {
                $map['indice'] = $idx;
            }
        }

        return $map;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') return false;
        }
        return true;
    }

    private function col(array $row, ?int $idx): mixed
    {
        return $idx !== null ? ($row[$idx] ?? null) : null;
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) return null;
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return round((float) $value, 4);
        $clean = str_replace(['%', '$', ',', ' '], '', (string) $value);
        return is_numeric($clean) ? round((float) $clean, 4) : null;
    }
}
