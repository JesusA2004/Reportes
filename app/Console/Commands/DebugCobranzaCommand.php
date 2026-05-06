<?php

namespace App\Console\Commands;

use App\Models\ReportUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DebugCobranzaCommand extends Command
{
    protected $signature = 'reportes:debug-cobranza {uploadId : ID del ReportUpload de Lendus Ingresos Cobranza}';
    protected $description = 'Diagnóstico del archivo Lendus Ingresos Cobranza para Actualizar BD (solo lectura, sin modificar datos)';

    public function handle(): int
    {
        $uploadId = (int) $this->argument('uploadId');
        $upload   = ReportUpload::query()->with('dataSource')->find($uploadId);

        if (!$upload) {
            $this->error("ReportUpload #{$uploadId} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->line("=== reportes:debug-cobranza | Upload #{$uploadId} | " . now()->format('Y-m-d H:i:s') . " ===");
        $this->line("Fuente   : " . ($upload->dataSource?->name ?? 'N/D'));
        $this->line("Periodo  : {$upload->period_id}");
        $this->line("Path     : {$upload->stored_path}");

        if (!$upload->stored_path) {
            $this->error('Sin stored_path. El archivo no fue guardado correctamente.');
            return self::FAILURE;
        }

        $diskExists = Storage::disk('public')->exists($upload->stored_path);
        $this->line("Existe   : " . ($diskExists ? 'SÍ' : 'NO — ERROR'));

        if (!$diskExists) {
            $this->error('Archivo físico no encontrado en storage/public.');
            return self::FAILURE;
        }

        $absolutePath = Storage::disk('public')->path($upload->stored_path);
        $fileSizeKb   = round(filesize($absolutePath) / 1024, 1);
        $this->line("Tamaño   : {$fileSizeKb} KB");

        // ── 1. Sheet info via PhpSpreadsheet ────────────────────────────────
        $this->line('');
        $this->line('--- Info de hoja (PhpSpreadsheet listWorksheetInfo) ---');

        $t0         = microtime(true);
        $psReader   = IOFactory::createReaderForFile($absolutePath);
        $psReader->setReadDataOnly(true);
        $sheetsInfo = $psReader->listWorksheetInfo($absolutePath);
        $this->line("listWorksheetInfo: " . $this->ms($t0) . " ms");

        if (empty($sheetsInfo)) {
            $this->error('No se encontraron hojas.');
            return self::FAILURE;
        }

        foreach ($sheetsInfo as $i => $info) {
            $this->line("  [{$i}] '{$info['worksheetName']}' — {$info['totalRows']} filas × última col {$info['lastColumnLetter']}");
        }

        $sheetInfo  = $sheetsInfo[0];
        $highestRow = (int) $sheetInfo['totalRows'];

        // ── 2. Header detection via OpenSpout (first 30 non-empty rows) ─────
        $this->line('');
        $this->line('--- Detección de encabezados (OpenSpout, primeras 30 filas) ---');

        $t1          = microtime(true);
        $buffer      = [];
        $spoutReader = new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: false));
        $spoutReader->open($absolutePath);

        foreach ($spoutReader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $buffer[] = $row->toArray();
                if (count($buffer) >= 30) break;
            }
            break;
        }
        $spoutReader->close();

        $this->line("Lectura 30 filas (OpenSpout): " . $this->ms($t1) . " ms, " . count($buffer) . " filas obtenidas");

        // Detect header row — use cellToString to safely handle dates/bools
        $headerRowIndex = 0;
        $bestScore      = -1;
        $headerKeywords = [
            'oficina', 'ruta', 'sucursal', 'zona', 'promotor', 'asesor',
            'ejecutivo', 'cliente', 'nombre_del_cliente', 'transaccion',
            'fecha_transaccion', 'concepto', 'acreditado',
        ];

        foreach ($buffer as $idx => $row) {
            if (!is_array($row)) continue;
            $score = 0;
            foreach ($row as $cell) {
                $norm = $this->normalizeHeader($this->cellToString($cell));
                if (in_array($norm, $headerKeywords, true)) $score++;
            }
            if ($score > $bestScore) { $bestScore = $score; $headerRowIndex = $idx; }
        }

        $this->line("Fila de encabezado: buffer índice {$headerRowIndex}, score={$bestScore}");

        $headerRow = $buffer[$headerRowIndex] ?? [];
        $nonEmpty  = array_filter(
            array_map(fn($v) => $this->cellToString($v), $headerRow),
            fn($v) => $v !== ''
        );
        $this->line("Encabezados no vacíos: " . implode(' | ', $nonEmpty));

        // ── 3. Column mapping ───────────────────────────────────────────────
        $this->line('');
        $this->line('--- Mapeo de columnas para Actualizar BD ---');

        $columnAliases = [
            'promoter_name' => ['promotor', 'asesor', 'ejecutivo', 'colaborador'],
            'branch_name'   => ['oficina', 'ruta', 'sucursal', 'oficina_ruta'],
        ];

        $headerMap = [];
        foreach ($columnAliases as $field => $aliases) {
            foreach ($headerRow as $colIdx => $header) {
                $norm = $this->normalizeHeader($this->cellToString($header));
                if (in_array($norm, $aliases, true)) {
                    $headerMap[$field] = (int) $colIdx;
                    break;
                }
            }
        }

        foreach ($columnAliases as $field => $_) {
            if (isset($headerMap[$field])) {
                $colIdx    = $headerMap[$field];
                $colLetter = Coordinate::stringFromColumnIndex($colIdx + 1);
                $rawHeader = $this->cellToString($headerRow[$colIdx] ?? null);
                $this->line("  {$field}: col {$colLetter} (índice {$colIdx}) — '{$rawHeader}'");
            } else {
                $this->warn("  {$field}: NO ENCONTRADA");
            }
        }

        if (!isset($headerMap['promoter_name'])) {
            $this->error('promoter_name es obligatoria — el proceso fallaría aquí.');
            return self::FAILURE;
        }

        $promoterColIdx = $headerMap['promoter_name'];
        $branchColIdx   = $headerMap['branch_name'] ?? null;

        // ── 4. First 5 valid data rows ──────────────────────────────────────
        $this->line('');
        $this->line('--- Primeras filas de datos válidas (máx 5, desde buffer) ---');

        $shown = 0;
        foreach (array_slice($buffer, $headerRowIndex + 1, 20) as $row) {
            if (!is_array($row)) continue;
            $promoter = trim($this->cellToString($row[$promoterColIdx] ?? null));
            if ($promoter === '') continue;
            $branch = $branchColIdx !== null ? trim($this->cellToString($row[$branchColIdx] ?? null)) : '';
            $this->line("  Promotor: '{$promoter}'  |  Sucursal: '" . ($branch ?: '—') . "'");
            $shown++;
            if ($shown >= 5) break;
        }

        if ($shown === 0) {
            $this->warn('No se encontraron filas con promotor en los primeros 30 registros.');
        }

        // ── 5. OpenSpout streaming speed test (first 1000 data rows) ────────
        $this->line('');
        $this->line('--- Estimación de velocidad: OpenSpout streaming (primeras 1000 filas de datos) ---');

        $t2           = microtime(true);
        $testCount    = 0;
        $withPromoter = 0;
        $bufferIdx    = 0;

        $testReader = new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: false));
        $testReader->open($absolutePath);

        foreach ($testReader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $bufferIdx++;
                if ($bufferIdx <= $headerRowIndex + 1) continue;
                $values = $row->toArray();
                $testCount++;
                $promoter = trim($this->cellToString($values[$promoterColIdx] ?? null));
                if ($promoter !== '') $withPromoter++;
                if ($testCount >= 1_000) break;
            }
            break;
        }
        $testReader->close();

        $msFirst1k = $this->ms($t2);
        $this->line("OpenSpout 1000 filas de datos: {$msFirst1k} ms, {$withPromoter} con promotor");

        $dataRows    = max(1, $highestRow - ($headerRowIndex + 1));
        $estimatedMs = ($dataRows / 1000) * (float) $msFirst1k;
        $this->line("Total filas datos: ~{$dataRows}");
        $this->line("Tiempo estimado total: ~" . round($estimatedMs / 1000, 1) . " s");

        // ── 6. Full streaming count (skip if file > 20k rows) ───────────────
        if ($highestRow <= 20_000) {
            $this->line('');
            $this->line('--- Conteo completo (archivo ≤ 20k filas) ---');
            $t3         = microtime(true);
            $totalData  = 0;
            $totalPromo = 0;
            $skipped    = 0;
            $fullReader = new XlsxReader(new XlsxOptions(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: false));
            $fullReader->open($absolutePath);
            $rowIdx = 0;
            foreach ($fullReader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $rowIdx++;
                    if ($rowIdx <= $headerRowIndex + 1) continue;
                    $values = $row->toArray();
                    $totalData++;
                    $p = trim($this->cellToString($values[$promoterColIdx] ?? null));
                    if ($p !== '') $totalPromo++;
                    else $skipped++;
                }
                break;
            }
            $fullReader->close();
            $this->line("Lectura completa: " . $this->ms($t3) . " ms");
            $this->line("Filas de datos: {$totalData}, con promotor: {$totalPromo}, sin promotor: {$skipped}");
        } else {
            $this->line('');
            $this->line("(Archivo > 20k filas — conteo completo omitido)");
        }

        $this->line('');
        $this->info('Diagnóstico completado. No se modificó ningún dato.');
        $this->line("Librería: OpenSpout " . \Composer\InstalledVersions::getPrettyVersion('openspout/openspout'));
        return self::SUCCESS;
    }

    private function cellToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        return trim((string) json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    private function ms(float $t0): string
    {
        return (string) round((microtime(true) - $t0) * 1000, 1);
    }

    private function normalizeHeader(string $value): string
    {
        return Str::of($value)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
    }
}
