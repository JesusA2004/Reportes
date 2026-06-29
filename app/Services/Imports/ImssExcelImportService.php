<?php

namespace App\Services\Imports;

use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Importa el archivo IMSS (Cuota semanal por sucursal) como gasto de Capital Humano.
 *
 * El IMSS es costo patronal — no es deducción del trabajador.
 * Se almacena en fact_expenses con category = 'IMSS'.
 *
 * Hojas priorizadas (en orden):
 *   1. IMSS SUCURSALES — cuota mensual ya agregada por sucursal
 *   2. UNIDAD DE NEGOCIO — cuota por clasificación/sucursal
 *   3. ASEGURADOS MR. LANA — detalle por empleado (se agrupa por sucursal)
 *
 * No duplicar empleado: si aparece en más de una hoja se toma de la primera
 * que tenga datos (por prioridad).
 */
class ImssExcelImportService
{
    // Sucursales oficiales abril 2026
    private const BRANCH_MAP = [
        'ATLACOMULCO'       => 'ATLACOMULCO',
        'ATLIXCO'           => 'ATLIXCO',
        'CORDOBA'           => 'CORDOBA',
        'CÓRDOBA'           => 'CORDOBA',
        'CUERNAVACA'        => 'CUERNAVACA',
        'HUAMANTLA'         => 'HUAMANTLA',
        'IXTLAHUACA'        => 'IXTLAHUACA',
        'MIACATLAN'         => 'MIACATLAN',
        'MIACATLÁN'         => 'MIACATLAN',
        'ORIZABA'           => 'ORIZABA',
        'SAN JUAN DEL RIO'  => 'SAN JUAN DEL RIO',
        'SAN JUAN DEL RÍO'  => 'SAN JUAN DEL RIO',
        'SAN JUAN'          => 'SAN JUAN DEL RIO',
        'SAN LUIS POTOSI'   => 'SAN LUIS POTOSI',
        'SAN LUIS POTOSÍ'   => 'SAN LUIS POTOSI',
        'SAN LUIS'          => 'SAN LUIS POTOSI',
        'TENANGO DEL VALLE' => 'TENANGO DEL VALLE',
        'TENANGO'           => 'TENANGO DEL VALLE',
        'TLAXCALA'          => 'TLAXCALA',
        'TULA'              => 'TULA',
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

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        // Try sheets in priority order
        $sheetPriority = [
            'IMSS SUCURSALES',
            'UNIDAD DE NEGOCIO',
            'ASEGURADOS MR. LANA',
            'IMSS',
        ];

        $rowsInserted = 0;
        $rowsSkipped  = 0;
        $rowsErrors   = 0;
        $usedSheet    = null;

        foreach ($sheetPriority as $sheetName) {
            $sheet = null;
            // Case-insensitive sheet search
            foreach ($spreadsheet->getSheetNames() as $name) {
                if (mb_strtoupper(trim($name)) === mb_strtoupper(trim($sheetName))) {
                    $sheet = $spreadsheet->getSheetByName($name);
                    break;
                }
            }

            if (!$sheet) {
                continue;
            }

            $rows    = $sheet->toArray(null, true, true, false);
            $result  = $this->processSheet($rows, $sheetName, $upload);

            if ($result['inserted'] > 0) {
                $rowsInserted += $result['inserted'];
                $rowsSkipped  += $result['skipped'];
                $rowsErrors   += $result['errors'];
                $usedSheet     = $sheetName;
                break; // Use first sheet that yields data
            }
        }

        // Fallback: try every sheet
        if ($rowsInserted === 0) {
            foreach ($spreadsheet->getWorksheetIterator() as $ws) {
                $rows   = $ws->toArray(null, true, true, false);
                $result = $this->processSheet($rows, $ws->getTitle(), $upload);
                if ($result['inserted'] > 0) {
                    $rowsInserted += $result['inserted'];
                    $rowsSkipped  += $result['skipped'];
                    $rowsErrors   += $result['errors'];
                    $usedSheet     = $ws->getTitle();
                    break;
                }
            }
        }

        if ($progress) {
            $progress([
                'rows_read'        => $rowsInserted + $rowsSkipped,
                'rows_inserted'    => $rowsInserted,
                'rows_skipped'     => $rowsSkipped,
                'rows_with_errors' => $rowsErrors,
                'log'              => "IMSS importado desde hoja: {$usedSheet}. Filas: {$rowsInserted} insertadas.",
            ]);
        }

        return [
            'rows_read'        => $rowsInserted + $rowsSkipped,
            'rows_inserted'    => $rowsInserted,
            'rows_skipped'     => $rowsSkipped,
            'rows_with_errors' => $rowsErrors,
            'log'              => sprintf(
                'IMSS importado (hoja: %s). Insertadas: %d, omitidas: %d, errores: %d.',
                $usedSheet ?? 'ninguna', $rowsInserted, $rowsSkipped, $rowsErrors
            ),
        ];
    }

    private function processSheet(array $rows, string $sheetName, ReportUpload $upload): array
    {
        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;

        // Find header row (first row with at least 2 non-empty cells recognizable as headers)
        $headerIdx = null;
        foreach (array_slice($rows, 0, 10, true) as $idx => $row) {
            $nonEmpty = array_filter($row, fn ($c) => $c !== null && trim((string) $c) !== '');
            if (count($nonEmpty) >= 2) {
                $rowText = mb_strtolower(implode(' ', $nonEmpty));
                if (
                    str_contains($rowText, 'sucursal') ||
                    str_contains($rowText, 'unidad') ||
                    str_contains($rowText, 'clasificaci') ||
                    str_contains($rowText, 'cuota') ||
                    str_contains($rowText, 'imss') ||
                    str_contains($rowText, 'empleado') ||
                    str_contains($rowText, 'nss')
                ) {
                    $headerIdx = $idx;
                    break;
                }
            }
        }

        if ($headerIdx === null) {
            // Try treating first non-empty row as data directly
            $headerIdx = 0;
        }

        $headers = $rows[$headerIdx] ?? [];
        $colMap  = $this->buildColMap($headers);

        // Aggregate by branch to avoid duplicates from multiple NSS rows
        $branchTotals = [];

        foreach (array_slice($rows, $headerIdx + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $skipped++;
                continue;
            }

            $sucursalRaw = $this->str($this->col($row, $colMap['sucursal']));
            $monto       = $this->toDecimal($this->col($row, $colMap['monto']));

            if ($monto === null || $monto <= 0.0) {
                $skipped++;
                continue;
            }

            $sucursalNorm = $this->normalizeBranch($sucursalRaw ?? '');
            if (!$sucursalNorm) {
                // Try to detect branch from any text in the row
                foreach ($row as $cell) {
                    $candidate = $this->normalizeBranch((string) $cell);
                    if ($candidate) {
                        $sucursalNorm = $candidate;
                        break;
                    }
                }
            }

            $nss       = $this->str($this->col($row, $colMap['nss']));
            $empleado  = $this->str($this->col($row, $colMap['empleado']));
            $key       = $sucursalNorm ?? ($empleado ?? 'SIN_SUC');

            $branchTotals[$key] = [
                'sucursal'  => $sucursalNorm,
                'monto'     => ($branchTotals[$key]['monto'] ?? 0.0) + $monto,
                'empleados' => ($branchTotals[$key]['empleados'] ?? 0) + 1,
                'raw'       => ['hoja' => $sheetName, 'nss_ejemplo' => $nss, 'empleado_ejemplo' => $empleado],
            ];
        }

        // Insert one expense row per branch
        foreach ($branchTotals as $data) {
            try {
                $branchId = null;
                if ($data['sucursal']) {
                    $branch = $this->branchResolver->findOrCreateBranchByName($data['sucursal']);
                    $branchId = $branch?->id;
                }

                Expense::query()->create([
                    'period_id'        => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'employee_id'      => null,
                    'branch_id'        => $branchId,
                    'category'         => 'IMSS',
                    'concept'          => 'CUOTA PATRONAL IMSS',
                    'amount'           => round($data['monto'], 2),
                    'paid_amount'      => round($data['monto'], 2),
                    'expense_date'     => null,
                    'observations'     => $data['sucursal'] ?? 'Sin sucursal',
                    'raw_payload'      => $data['raw'],
                ]);
                $inserted++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors];
    }

    private function buildColMap(array $headers): array
    {
        $map = ['sucursal' => null, 'monto' => null, 'nss' => null, 'empleado' => null];

        foreach ($headers as $idx => $cell) {
            $norm = strtr(mb_strtolower(trim((string) $cell)), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);

            if ($map['sucursal'] === null && (str_contains($norm, 'sucursal') || str_contains($norm, 'unidad') || str_contains($norm, 'clasificaci'))) {
                $map['sucursal'] = $idx;
            } elseif ($map['monto'] === null && (str_contains($norm, 'cuota') || str_contains($norm, 'mensual') || str_contains($norm, 'imss') || str_contains($norm, 'monto') || str_contains($norm, 'total') || str_contains($norm, 'importe'))) {
                $map['monto'] = $idx;
            } elseif ($map['nss'] === null && str_contains($norm, 'nss')) {
                $map['nss'] = $idx;
            } elseif ($map['empleado'] === null && (str_contains($norm, 'empleado') || str_contains($norm, 'nombre') || str_contains($norm, 'trabajador'))) {
                $map['empleado'] = $idx;
            }
        }

        // Fallback positions if nothing detected
        if ($map['sucursal'] === null) $map['sucursal'] = 0;
        if ($map['monto'] === null)    $map['monto']    = 1;

        return $map;
    }

    public function normalizeBranch(string $raw): ?string
    {
        $norm = strtr(mb_strtoupper(trim($raw)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $norm = (string) preg_replace('/[^A-Z\s]/', ' ', $norm);
        $norm = (string) preg_replace('/\s+/', ' ', trim($norm));

        // Exact matches first (longer keys first to avoid partial hits)
        $sorted = self::BRANCH_MAP;
        uksort($sorted, fn ($a, $b) => strlen($b) - strlen($a));

        foreach ($sorted as $key => $official) {
            $keyNorm = strtr(mb_strtoupper($key), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
            if (str_contains($norm, $keyNorm)) {
                return $official;
            }
        }

        return null;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
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
        if (is_numeric($value)) return round((float) $value, 2);
        $clean = str_replace(['$', ',', ' '], '', (string) $value);
        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }
}
