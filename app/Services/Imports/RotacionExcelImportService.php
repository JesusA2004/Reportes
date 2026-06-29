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

        DB::table('fact_rotacion')->where('report_upload_id', $upload->id)->delete();

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
            // Strategy 2: scan every sheet for columns matching the target month
            foreach ($spreadsheet->getWorksheetIterator() as $ws) {
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
            $bajas     = (int) ($this->toDecimal($this->col($row, $colMap['bajas'])) ?? 0);
            $promedio  = $this->toDecimal($this->col($row, $colMap['promedio'])) ?? 0.0;
            $indice    = $this->toDecimal($this->col($row, $colMap['indice'])) ?? 0.0;

            if (!$sucursal || ($bajas === 0 && $promedio == 0.0 && $indice == 0.0)) {
                $skipped++;
                continue;
            }

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
                    'raw_payload'      => json_encode(['row' => array_values($row)]),
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
     * Parse a sheet where months are columns — find the target month column.
     */
    private function parseSheetMonthColumn(array $rows, string $targetMes, string $hoja, ReportUpload $upload): array
    {
        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;

        // Find row that has month names as column headers
        $monthHeaderIdx = null;
        $sucursalCol    = null;
        $mesColSet      = []; // [bajas=>col, promedio=>col, indice=>col] for target month

        foreach (array_slice($rows, 0, 20, true) as $idx => $row) {
            foreach ($row as $colIdx => $cell) {
                $norm = strtr(mb_strtoupper(trim((string) $cell)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
                if ($norm === $targetMes || str_contains($norm, $targetMes)) {
                    $monthHeaderIdx = $idx;
                    // The target month column block starts here; adjacent cols may be bajas/promedio/indice
                    $mesColSet['month_col'] = $colIdx;
                    break 2;
                }
            }
        }

        if ($monthHeaderIdx === null) {
            return ['inserted' => 0, 'skipped' => count($rows), 'errors' => 0];
        }

        // Find sucursal column in the same header row or the row above
        $hdrRow = $rows[$monthHeaderIdx];
        foreach ($hdrRow as $colIdx => $cell) {
            $norm = strtr(mb_strtolower(trim((string) $cell)), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
            if (str_contains($norm, 'sucursal') || str_contains($norm, 'unidad') || str_contains($norm, 'clasificaci')) {
                $sucursalCol = $colIdx;
                break;
            }
        }

        if ($sucursalCol === null) $sucursalCol = 0;

        $targetCol = $mesColSet['month_col'];

        foreach (array_slice($rows, $monthHeaderIdx + 1) as $row) {
            if ($this->isEmptyRow($row)) { $skipped++; continue; }

            $sucursal = $this->str($this->col($row, $sucursalCol));
            $valor    = $this->toDecimal($this->col($row, $targetCol));

            if (!$sucursal || $valor === null || $valor <= 0) {
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
                    'bajas'             => 0,
                    'promedio_personal' => 0,
                    'indice_rotacion'   => $valor,
                    'hoja_fuente'       => $hoja,
                    'raw_payload'       => json_encode(['col_valor' => $targetCol]),
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

        // Try from period start_date month number
        if ($period->start_date) {
            try {
                $month = (int) \Carbon\Carbon::parse($period->start_date)->format('n');
                return self::MESES_ES[$month] ?? 'ABRIL';
            } catch (\Throwable) {}
        }

        // Try from period label text
        $label = mb_strtoupper((string) ($period->label ?? ''));
        foreach (self::MESES_ES as $num => $mes) {
            $mesNorm = strtr($mes, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U']);
            if (str_contains($label, $mes) || str_contains($label, $mesNorm)) {
                return $mes;
            }
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
        $map = ['sucursal' => 0, 'bajas' => null, 'promedio' => null, 'indice' => null];

        foreach ($headers as $idx => $cell) {
            $norm = strtr(mb_strtolower(trim((string) $cell)), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
            if (str_contains($norm, 'sucursal') || str_contains($norm, 'unidad') || str_contains($norm, 'clasificaci')) {
                $map['sucursal'] = $idx;
            } elseif ($map['bajas'] === null && str_contains($norm, 'baja')) {
                $map['bajas'] = $idx;
            } elseif ($map['promedio'] === null && (str_contains($norm, 'promedio') || str_contains($norm, 'personal'))) {
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
