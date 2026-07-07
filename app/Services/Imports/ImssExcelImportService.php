<?php

namespace App\Services\Imports;

use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\Imports\Support\PdfCoordinateExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser;

/**
 * Importa el archivo IMSS (Cuota patronal por sucursal) como gasto de Capital Humano.
 *
 * El IMSS es costo patronal — no es deducción del trabajador.
 * Se almacena en fact_expenses con category = 'IMSS'.
 *
 * Hojas priorizadas (en orden):
 *   1. IMSS SUCURSALES — cuota mensual ya agregada por sucursal
 *   2. UNIDAD DE NEGOCIO — cuota por clasificación/sucursal
 *   3. ASEGURADOS MR. LANA — detalle por empleado (se agrupa por sucursal)
 *   4. IMSS — hoja genérica (p.ej. "IMSS MES DE JUNIO 2026" con columnas No./SUCURSAL/CUOTA)
 *
 * No duplicar empleado: si aparece en más de una hoja se toma de la primera
 * que tenga datos (por prioridad). Si la hoja no coincide con ninguno de los
 * nombres anteriores (p.ej. "Hoja1"), se recorre cada hoja del libro hasta
 * encontrar una que produzca filas válidas.
 */
class ImssExcelImportService
{
    // Sucursales oficiales + CORPORATIVO/TULANCINGO (no deben repartirse entre otras sucursales).
    private const BRANCH_MAP = [
        'ATLACOMULCO'       => 'ATLACOMULCO',
        'ATLIXCO'           => 'ATLIXCO',
        'CORDOBA'           => 'CORDOBA',
        'CÓRDOBA'           => 'CORDOBA',
        'CORPORATIVO'       => 'CORPORATIVO',
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
        'TULANCINGO'        => 'TULANCINGO',
    ];

    private const TOTAL_ROW_LABELS = ['TOTAL', 'TOTALES', 'GRAN TOTAL'];

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
        $extension    = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        $result = $extension === 'pdf'
            ? $this->handlePdf($absolutePath, $upload)
            : $this->handleExcel($absolutePath, $upload);

        if ($progress) {
            $progress($result);
        }

        return $result;
    }

    private function handleExcel(string $absolutePath, ReportUpload $upload): array
    {
        $reader      = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);

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
        $validation   = null;

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
                $validation    = $result['validation'];
                break; // Use first sheet that yields data
            }
        }

        // Fallback: try every sheet (covers files with a single generic sheet, e.g. "Hoja1")
        if ($rowsInserted === 0) {
            foreach ($spreadsheet->getWorksheetIterator() as $ws) {
                $rows   = $ws->toArray(null, true, true, false);
                $result = $this->processSheet($rows, $ws->getTitle(), $upload);
                if ($result['inserted'] > 0) {
                    $rowsInserted += $result['inserted'];
                    $rowsSkipped  += $result['skipped'];
                    $rowsErrors   += $result['errors'];
                    $usedSheet     = $ws->getTitle();
                    $validation    = $result['validation'];
                    break;
                }
            }
        }

        $log = sprintf(
            'IMSS importado (hoja: %s). Insertadas: %d, omitidas: %d, errores: %d.',
            $usedSheet ?? 'ninguna', $rowsInserted, $rowsSkipped, $rowsErrors
        );
        $log .= $this->formatValidationNote($validation);

        return [
            'rows_read'        => $rowsInserted + $rowsSkipped,
            'rows_inserted'    => $rowsInserted,
            'rows_skipped'     => $rowsSkipped,
            'rows_with_errors' => $rowsErrors,
            'log'              => $log,
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
        $branchTotals    = [];
        $declaredTotal   = null;
        $declaredColabs  = null;

        foreach (array_slice($rows, $headerIdx + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $skipped++;
                continue;
            }

            $sucursalRaw = $this->str($this->col($row, $colMap['sucursal']));
            $montoRaw    = $this->col($row, $colMap['monto']);

            // Guard: blank sucursal or literal "TOTAL" label => this is the summary/total
            // row of the file, not a branch row. Capture it for validation, never insert it.
            $isTotalLabel = $sucursalRaw !== null && in_array(mb_strtoupper(trim($sucursalRaw)), self::TOTAL_ROW_LABELS, true);
            if ($sucursalRaw === null || $isTotalLabel) {
                $montoTotal = $this->toDecimal($montoRaw);
                if ($montoTotal !== null && $montoTotal > 0) {
                    $declaredTotal = $montoTotal;
                    if ($colMap['numero'] !== null) {
                        $numTotal = $this->toDecimal($this->col($row, $colMap['numero']));
                        if ($numTotal !== null) {
                            $declaredColabs = (int) round($numTotal);
                        }
                    }
                }
                $skipped++;
                continue;
            }

            // Valores "*", "$-" o vacíos se interpretan como 0.00 (no se omite la fila:
            // sucursales con cuota $0 deben seguir apareciendo en el reporte).
            $monto = $this->toDecimal($montoRaw) ?? 0.0;

            $sucursalNorm = $this->normalizeBranch($sucursalRaw);

            $nss      = $this->str($this->col($row, $colMap['nss']));
            $empleado = $this->str($this->col($row, $colMap['empleado']));
            $numero   = $colMap['numero'] !== null ? $this->toDecimal($this->col($row, $colMap['numero'])) : null;

            // Clave de agregación: sucursal canónica si se reconoció; si no, el texto
            // original (nunca un cajón genérico "Sin sucursal") para no mezclar
            // sucursales distintas no reconocidas entre sí.
            $key = $sucursalNorm ?? ('SIN_RECONOCER:' . mb_strtoupper($sucursalRaw));

            $branchTotals[$key]['sucursal']     = $sucursalNorm;
            $branchTotals[$key]['sucursal_raw'] = $sucursalRaw;
            $branchTotals[$key]['monto']        = ($branchTotals[$key]['monto'] ?? 0.0) + $monto;
            // Si el archivo trae una columna "No." explícita (formato agregado por
            // sucursal), esa es la cuenta de colaboradores. Si no, se asume una fila
            // por empleado y se cuenta +1 por fila (formato ASEGURADOS MR. LANA).
            $branchTotals[$key]['empleados'] = ($branchTotals[$key]['empleados'] ?? 0)
                + ($numero !== null ? (int) round($numero) : 1);
            $branchTotals[$key]['raw'] = ['hoja' => $sheetName, 'nss_ejemplo' => $nss, 'empleado_ejemplo' => $empleado];
        }

        // Insert one expense row per branch
        foreach ($branchTotals as $data) {
            try {
                $branchId = null;
                if ($data['sucursal']) {
                    $branch = $this->branchResolver->findOrCreateBranchByName($data['sucursal']);
                    $branchId = $branch?->id;
                } else {
                    Log::warning('ImssExcelImportService: sucursal no reconocida.', ['raw' => $data['sucursal_raw']]);
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
                    'observations'     => $data['sucursal'] ?? $data['sucursal_raw'],
                    'raw_payload'      => $data['raw'] + ['num_colaboradores' => $data['empleados']],
                ]);
                $inserted++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        $sumInserted   = array_sum(array_column($branchTotals, 'monto'));
        $sumColabs     = array_sum(array_column($branchTotals, 'empleados'));
        $validation    = [
            'declared_total'      => $declaredTotal,
            'sum_inserted'        => round($sumInserted, 2),
            'declared_colabs'     => $declaredColabs,
            'sum_colabs'          => $sumColabs,
        ];

        return ['inserted' => $inserted, 'skipped' => $skipped, 'errors' => $errors, 'validation' => $validation];
    }

    private function formatValidationNote(?array $validation): string
    {
        if (!$validation) {
            return '';
        }

        $notes = [];

        if ($validation['declared_total'] !== null) {
            $diff = round($validation['sum_inserted'] - $validation['declared_total'], 2);
            if (abs($diff) > 0.01) {
                $notes[] = sprintf(
                    ' AVISO: la suma por sucursal ($%s) no coincide con el total declarado en el archivo ($%s).',
                    number_format($validation['sum_inserted'], 2),
                    number_format($validation['declared_total'], 2)
                );
            }
        }

        if ($validation['declared_colabs'] !== null && $validation['declared_colabs'] !== $validation['sum_colabs']) {
            $notes[] = sprintf(
                ' AVISO: el total de colaboradores calculado (%d) no coincide con el declarado en el archivo (%d).',
                $validation['sum_colabs'], $validation['declared_colabs']
            );
        }

        return implode('', $notes);
    }

    private function buildColMap(array $headers): array
    {
        $map = ['sucursal' => null, 'monto' => null, 'nss' => null, 'empleado' => null, 'numero' => null];

        foreach ($headers as $idx => $cell) {
            $norm = strtr(mb_strtolower(trim((string) $cell)), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
            $normNoPunct = preg_replace('/[^a-z]/', '', $norm) ?? $norm;

            if ($map['sucursal'] === null && (str_contains($norm, 'sucursal') || str_contains($norm, 'unidad') || str_contains($norm, 'clasificaci'))) {
                $map['sucursal'] = $idx;
            } elseif ($map['monto'] === null && (str_contains($norm, 'cuota') || str_contains($norm, 'mensual') || str_contains($norm, 'imss') || str_contains($norm, 'monto') || str_contains($norm, 'total') || str_contains($norm, 'importe'))) {
                $map['monto'] = $idx;
            } elseif ($map['nss'] === null && str_contains($norm, 'nss')) {
                $map['nss'] = $idx;
            } elseif ($map['numero'] === null && ($normNoPunct === 'no' || str_contains($norm, 'colaborador') || str_contains($norm, 'numero'))) {
                $map['numero'] = $idx;
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

    // ── PDF parsing ──────────────────────────────────────────────────────────
    //
    // Formato esperado: título con "IMSS" + mes/año, encabezados No./Sucursal/
    // Cuota, una fila por sucursal y una fila de total (sucursal vacía). Se usan
    // coordenadas para reconstruir filas y unir nombres de sucursal partidos en
    // varias palabras a la misma altura Y.

    private function handlePdf(string $absolutePath, ReportUpload $upload): array
    {
        $parser = new Parser();
        $pdfDoc = $parser->parseFile($absolutePath);

        $branchTotals   = [];
        $declaredTotal  = null;
        $declaredColabs = null;
        $inserted = 0;
        $skipped  = 0;
        $errors   = 0;

        foreach ($pdfDoc->getPages() as $page) {
            $rows = PdfCoordinateExtractor::extractRows($page);

            foreach ($rows as $row) {
                $text = trim($row['text']);
                if ($text === '') {
                    continue;
                }

                $upperText = mb_strtoupper($text);
                if (str_contains($upperText, 'IMSS') && !preg_match('/\d/', $text)) {
                    continue; // título / encabezado sin datos
                }
                if (preg_match('/\bSUCURSAL\b/ui', $text) && preg_match('/\bCUOTA\b/ui', $text)) {
                    continue; // fila de encabezados de columnas
                }

                $tokens = $row['tokens'];
                if (count($tokens) < 2) {
                    $skipped++;
                    continue;
                }

                // Última columna numérica = monto; primer token numérico = número/colaboradores
                // (cuando aplica); los tokens de texto intermedios = nombre de sucursal.
                $montoRaw = $tokens[count($tokens) - 1]['text'];
                $monto    = $this->toDecimal($montoRaw);
                if ($monto === null) {
                    $skipped++;
                    continue;
                }

                $numeroRaw   = null;
                $middleStart = 0;
                if (is_numeric(trim((string) $tokens[0]['text']))) {
                    $numeroRaw   = $tokens[0]['text'];
                    $middleStart = 1;
                }

                $middleTokens = array_slice($tokens, $middleStart, count($tokens) - 1 - $middleStart);
                $sucursalRaw  = trim(implode(' ', array_column($middleTokens, 'text')));

                if ($sucursalRaw === '') {
                    // Fila de total: sucursal vacía + monto declarado.
                    if ($monto > 0) {
                        $declaredTotal = $monto;
                        if ($numeroRaw !== null) {
                            $declaredColabs = (int) round((float) $numeroRaw);
                        }
                    }
                    $skipped++;
                    continue;
                }

                $sucursalNorm = $this->normalizeBranch($sucursalRaw);
                $key = $sucursalNorm ?? ('SIN_RECONOCER:' . mb_strtoupper($sucursalRaw));

                $branchTotals[$key]['sucursal']     = $sucursalNorm;
                $branchTotals[$key]['sucursal_raw']  = $sucursalRaw;
                $branchTotals[$key]['monto']         = ($branchTotals[$key]['monto'] ?? 0.0) + $monto;
                $branchTotals[$key]['empleados']     = ($branchTotals[$key]['empleados'] ?? 0)
                    + ($numeroRaw !== null ? (int) round((float) $numeroRaw) : 1);
                $branchTotals[$key]['raw'] = ['hoja' => 'PDF'];
            }
        }

        foreach ($branchTotals as $data) {
            try {
                $branchId = null;
                if ($data['sucursal']) {
                    $branch   = $this->branchResolver->findOrCreateBranchByName($data['sucursal']);
                    $branchId = $branch?->id;
                } else {
                    Log::warning('ImssExcelImportService (PDF): sucursal no reconocida.', ['raw' => $data['sucursal_raw']]);
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
                    'observations'     => $data['sucursal'] ?? $data['sucursal_raw'],
                    'raw_payload'      => $data['raw'] + ['num_colaboradores' => $data['empleados']],
                ]);
                $inserted++;
            } catch (\Throwable) {
                $errors++;
            }
        }

        if ($inserted === 0) {
            throw new \RuntimeException('No se pudo extraer ninguna fila de sucursal del PDF de IMSS. Verifica que el archivo tenga el formato esperado (No. / Sucursal / Cuota IMSS).');
        }

        $sumInserted = array_sum(array_column($branchTotals, 'monto'));
        $sumColabs   = array_sum(array_column($branchTotals, 'empleados'));

        $log = sprintf(
            'IMSS importado desde PDF. Sucursales detectadas: %d, omitidas: %d, errores: %d.',
            $inserted, $skipped, $errors
        );
        $log .= $this->formatValidationNote([
            'declared_total'  => $declaredTotal,
            'sum_inserted'    => round($sumInserted, 2),
            'declared_colabs' => $declaredColabs,
            'sum_colabs'      => $sumColabs,
        ]);

        return [
            'rows_read'        => $inserted + $skipped,
            'rows_inserted'    => $inserted,
            'rows_skipped'     => $skipped,
            'rows_with_errors' => $errors,
            'log'              => $log,
        ];
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
        if ($clean === '' || $clean === '-' || $clean === '*') return null;
        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }
}
