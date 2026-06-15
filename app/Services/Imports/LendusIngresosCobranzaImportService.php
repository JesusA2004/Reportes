<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\PeriodDatabaseUpdateRun;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\ColumnMapService;
use App\Services\SaveheartsRuleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LendusIngresosCobranzaImportService {

    private array $branchCache = [];

    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly ColumnMapService $columnMap,
        private readonly SaveheartsRuleService $savehearts,
    ) {}

    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @set_time_limit(0);

        $absolutePath = Storage::disk('public')->path($upload->stored_path);

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $sheetsInfo = $reader->listWorksheetInfo($absolutePath);

        if (empty($sheetsInfo)) {
            throw new \RuntimeException('No se encontraron hojas en el archivo de cobranza.');
        }

        $sheetInfo = $sheetsInfo[0];
        $sheetName = $sheetInfo['worksheetName'];
        $highestRow = (int) $sheetInfo['totalRows'];
        $lastColumnLetter = (string) $sheetInfo['lastColumnLetter'];

        if ($highestRow <= 0) {
            throw new \RuntimeException('El archivo de cobranza no contiene filas útiles.');
        }

        $previewRows = $this->readChunk(
            path: $absolutePath,
            sheetName: $sheetName,
            startRow: 1,
            endRow: min(30, $highestRow),
            lastColumnLetter: $lastColumnLetter,
        );

        if (empty($previewRows)) {
            throw new \RuntimeException('No se pudo leer la vista previa del archivo de cobranza.');
        }

        $headerRowIndex = $this->detectHeaderRowIndex($previewRows);
        $headerRow = $previewRows[$headerRowIndex] ?? null;

        if (!$headerRow || !is_array($headerRow)) {
            throw new \RuntimeException('No se encontró una fila de encabezados válida en el archivo de cobranza.');
        }

        $headerMap = $this->buildHeaderMap($headerRow);

        $requiredColumns = ['promoter_name'];

        $missingRequired = collect($requiredColumns)
            ->filter(fn (string $field) => !array_key_exists($field, $headerMap))
            ->values()
            ->all();

        if (!empty($missingRequired)) {
            throw new \RuntimeException(
                'El archivo de cobranza no contiene columnas mínimas requeridas: '
                . implode(', ', $missingRequired)
                . '. Encabezados detectados: '
                . implode(', ', array_values(array_filter($headerRow, fn ($value) => filled($value))))
            );
        }

        Recovery::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsWithErrors = 0;

        $chunkSize = 1000;
        $dataStartRow = $headerRowIndex + 2;

        for ($start = $dataStartRow; $start <= $highestRow; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $highestRow);

            $chunkRows = $this->readChunk(
                path: $absolutePath,
                sheetName: $sheetName,
                startRow: $start,
                endRow: $end,
                lastColumnLetter: $lastColumnLetter,
            );

            $batch = [];

            foreach ($chunkRows as $row) {
                if (!is_array($row) || $this->isEmptyRow($row)) {
                    $rowsSkipped++;
                    continue;
                }

                $rowsRead++;

                if ($progress && $rowsRead % 250 === 0) {
                    $progress([
                        'rows_read' => $rowsRead,
                        'rows_inserted' => $rowsInserted,
                        'rows_skipped' => $rowsSkipped,
                        'rows_with_errors' => $rowsWithErrors,
                        'log' => "Procesando cobranza... {$rowsRead} filas leídas.",
                    ]);
                }

                try {
                    $mapped = $this->mapRow($row, $headerMap);

                    if (!$this->shouldInsertRow($mapped)) {
                        $rowsSkipped++;
                        continue;
                    }

                    // ── Savehearts rule ────────────────────────────────────────
                    $isSavehearts = $this->savehearts->isSaveheartsRow($mapped);
                    $saveheartsShare = $isSavehearts
                        ? $this->savehearts->saveheartsCompanyShare($mapped)
                        : 0.0;

                    // Non-CRECE Savehearts: zero out all metric amounts (auditing only)
                    if ($isSavehearts && $saveheartsShare === 0.0) {
                        $mapped['capital']      = 0;
                        $mapped['interest']     = 0;
                        $mapped['tax']          = 0;
                        $mapped['charges']      = 0;
                        $mapped['charges_due']  = 0;
                        $mapped['excedente']    = 0;
                        $mapped['total_amount'] = 0;
                    }

                    $branch = $this->resolveBranchFromCobranza(
                        $mapped['contract'],
                        $mapped['promoter_name'],
                        $mapped['branch_name_raw'],
                    );

                    $batch[] = [
                        'period_id'               => $upload->period_id,
                        'report_upload_id'        => $upload->id,
                        'branch_id'               => $branch?->id,
                        'contract'                => $mapped['contract'],
                        'client_name'             => $mapped['client_name'],
                        'normalized_client_name'  => $mapped['client_name']
                            ? $this->normalizeHumanName($mapped['client_name'])
                            : null,
                        'promoter_name'           => $mapped['promoter_name'],
                        'product_name'            => $mapped['product_name'],
                        'operation'               => $mapped['operation'],
                        'concept'                 => $mapped['concept'],
                        'transaction'             => $mapped['transaction'],
                        'capital'                 => $mapped['capital'],
                        'interest'                => $mapped['interest'],
                        'tax'                     => $mapped['tax'],
                        'charges'                 => $mapped['charges'],
                        'charges_due'             => $mapped['charges_due'],
                        'excedente'               => $mapped['excedente'],
                        'total_amount'            => $mapped['total_amount'],
                        'days_past_due'           => $mapped['days_past_due'],
                        'payment_date'            => $mapped['payment_date'],
                        'is_savehearts'           => $isSavehearts,
                        'savehearts_crece_share'  => $saveheartsShare,
                        'raw_payload'             => json_encode([
                            'branch_name_raw'    => $mapped['branch_name_raw'],
                            'promoter_name'      => $mapped['promoter_name'],
                            'client_name'        => $mapped['client_name'],
                            'product_name'       => $mapped['product_name'],
                            'operation'          => $mapped['operation'],
                            'concept'            => $mapped['concept'],
                            'transaction'        => $mapped['transaction'],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'created_at'              => now(),
                        'updated_at'              => now(),
                    ];
                } catch (\Throwable $e) {
                    $rowsWithErrors++;

                    throw new \RuntimeException(
                        'Error al preparar fila de cobranza. '
                        . 'Promotor: ' . ($mapped['promoter_name'] ?? 'N/D')
                        . ' | Cliente: ' . ($mapped['client_name'] ?? 'N/D')
                        . ' | Detalle: ' . $e->getMessage()
                    );
                }
            }

            if (!empty($batch)) {
                Recovery::query()->insert($batch);
                $rowsInserted += count($batch);
                if ($progress) {
                    $progress([
                        'rows_read' => $rowsRead,
                        'rows_inserted' => $rowsInserted,
                        'rows_skipped' => $rowsSkipped,
                        'rows_with_errors' => $rowsWithErrors,
                        'log' => "Insertadas {$rowsInserted} filas de cobranza...",
                    ]);
                }
            }

            unset($chunkRows, $batch);
            gc_collect_cycles();
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsWithErrors,
            'log' => sprintf(
                'Cobranza importada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d. Revisa Asignación sucursal para validar cruces, incidencias, altas y bajas.',
                $rowsRead,
                $rowsInserted,
                $rowsSkipped,
                $rowsWithErrors
            ),
        ];
    }

    public function scanForDatabaseUpdate(
        ReportUpload $upload,
        ?PeriodDatabaseUpdateRun $run = null,
        int $progressStart = 55,
        int $progressEnd = 78,
    ): array {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo de cobranza no tiene ruta de almacenamiento.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de cobranza no existe en storage/public.');
        }

        @set_time_limit(0);

        $startTime    = microtime(true);
        $absolutePath = Storage::disk('public')->path($upload->stored_path);

        // ── 1. Metadata-only read for row-count estimate (progress bar) ────
        $this->updateRun($run, $progressStart, 'Preparando Cobranza…');
        $infoReader = IOFactory::createReaderForFile($absolutePath);
        $infoReader->setReadDataOnly(true);
        $sheetsInfo = $infoReader->listWorksheetInfo($absolutePath);

        if (empty($sheetsInfo)) {
            throw new \RuntimeException('No se encontró hoja en el archivo de cobranza.');
        }

        $totalRowsEstimate = min(max(0, (int) ($sheetsInfo[0]['totalRows'] ?? 0)), 100_000);

        if ($totalRowsEstimate <= 1) {
            throw new \RuntimeException('El archivo de cobranza no contiene filas con datos.');
        }

        // ── 2. Single-pass streaming with OpenSpout (one file open total) ──
        // Buffer the first 30 non-empty rows to detect the header, then stream
        // the rest row-by-row without ever reopening the file.
        $this->updateRun($run, $progressStart + 2, 'Abriendo Cobranza en modo streaming…');

        $spoutOptions = new \OpenSpout\Reader\XLSX\Options(
            SHOULD_FORMAT_DATES: false,
            SHOULD_PRESERVE_EMPTY_ROWS: false,
        );
        $spoutReader = new \OpenSpout\Reader\XLSX\Reader($spoutOptions);
        $spoutReader->open($absolutePath);

        $headerBuffer     = []; // first ≤30 non-empty rows for header detection
        $headerDetected   = false;
        $promoterColIndex = null;
        $branchColIndex   = null;

        $rowsRead          = 0;
        $rowsSkipped       = 0;
        $streamedCount     = 0; // non-empty rows yielded by OpenSpout
        $promoterBranchMap = [];
        $noAnyBranch       = [];

        try {
            foreach ($spoutReader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $streamedCount++;
                    // OpenSpout Row::toArray() returns a sparse 0-indexed array
                    // keyed by the actual column position — same indices as buildHeaderMap.
                    $values = $row->toArray();

                    // ── Phase A: collect buffer until we can detect the header ─
                    if (!$headerDetected) {
                        $headerBuffer[] = $values;

                        if (count($headerBuffer) >= 30) {
                            [$promoterColIndex, $branchColIndex] = $this->detectCobranzaColumns($headerBuffer);
                            $headerDetected = true;
                            $this->updateRun($run, $progressStart + 5, 'Leyendo Lendus Cobranza…');
                        }
                        continue;
                    }

                    // ── Phase B: stream data rows directly ─────────────────────
                    $this->accumulateCobranzaRow(
                        $values, $promoterColIndex, $branchColIndex,
                        $promoterBranchMap, $noAnyBranch, $rowsRead, $rowsSkipped
                    );

                    // Every 1000 data rows: progress update + cancellation + time check
                    if ($rowsRead % 1_000 === 0 && $rowsRead > 0) {

                        if ($run) {
                            $run->refresh();
                            if (!in_array($run->status, ['running', 'queued'], true)) {
                                throw new \RuntimeException('El proceso fue cancelado durante la lectura de Cobranza.');
                            }
                        }

                        if ((microtime(true) - $startTime) > 1500) {
                            throw new \RuntimeException(sprintf(
                                'Cobranza: lectura superó 25 min. Filas leídas: %d. El archivo puede ser demasiado grande.',
                                $rowsRead
                            ));
                        }

                        if ($run && $totalRowsEstimate > 1) {
                            $pct  = (int) ($progressStart + ($streamedCount / $totalRowsEstimate) * ($progressEnd - $progressStart));
                            $pct  = min($progressEnd, max($progressStart + 5, $pct));
                            $uniq = count($promoterBranchMap);
                            $run->update([
                                'log'      => "Leyendo Cobranza… {$rowsRead} filas, {$uniq} promotores únicos.",
                                'metadata' => array_merge($run->metadata ?? [], [
                                    'current_step'     => "Leyendo Lendus Cobranza… {$uniq} promotores",
                                    'progress_percent' => $pct,
                                    'current_source'   => 'lendus_ingresos_cobranza',
                                    'processed_rows'   => $rowsRead,
                                    'total_rows'       => $totalRowsEstimate,
                                    'cobranza_rows_read'          => $rowsRead,
                                    'cobranza_rows_skipped'       => $rowsSkipped,
                                    'cobranza_total_rows'         => $totalRowsEstimate,
                                    'cobranza_promoters_detected' => $uniq,
                                ]),
                            ]);
                        }
                    }
                }
                break; // only first sheet
            }

            // ── Handle files with fewer than 30 non-empty rows ─────────────
            if (!$headerDetected && !empty($headerBuffer)) {
                [$promoterColIndex, $branchColIndex] = $this->detectCobranzaColumns($headerBuffer);
            }

            // Process the data rows that were buffered during header detection
            // (rows after the header line within the 30-row buffer)
            if ($promoterColIndex !== null) {
                $headerBufferIndex = $this->detectHeaderRowIndex($headerBuffer);
                foreach (array_slice($headerBuffer, $headerBufferIndex + 1) as $bufferedValues) {
                    $this->accumulateCobranzaRow(
                        $bufferedValues, $promoterColIndex, $branchColIndex,
                        $promoterBranchMap, $noAnyBranch, $rowsRead, $rowsSkipped
                    );
                }
            }

        } finally {
            $spoutReader->close();
        }

        // ── 4. Incidents: promoters that never appeared with a branch ──────
        // Severity 'warning' (not 'high') so these don't block report generation.
        $incidents = [];
        foreach ($noAnyBranch as $normalized => $rawName) {
            if (empty($promoterBranchMap[$normalized]['branches'])) {
                $incidents[] = [
                    'type'     => 'promoter_without_branch',
                    'severity' => 'warning',
                    'message'  => "Promotor sin sucursal: {$rawName}",
                ];
            }
        }

        // ── 5. Bulk-insert new Employees (preload to avoid per-row queries) ─
        // employees table has no unique constraint on (normalized_name, source_system),
        // so we load existing IDs first and only insert genuinely new rows.
        $existingEmployees = !empty($promoterBranchMap)
            ? Employee::query()
                ->where('source_system', 'lendus_cobranza')
                ->whereIn('normalized_name', array_keys($promoterBranchMap))
                ->pluck('id', 'normalized_name')
                ->all()
            : [];

        $newEmployeeRows = [];
        foreach ($promoterBranchMap as $normalized => $data) {
            if (!isset($existingEmployees[$normalized])) {
                $newEmployeeRows[] = [
                    'full_name'       => $data['full_name'],
                    'normalized_name' => $normalized,
                    'is_active'       => true,
                    'source_system'   => 'lendus_cobranza',
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }
        }

        foreach (array_chunk($newEmployeeRows, 300) as $chunk) {
            Employee::query()->insert($chunk);
        }

        $employeeIdMap = !empty($promoterBranchMap)
            ? Employee::query()
                ->where('source_system', 'lendus_cobranza')
                ->whereIn('normalized_name', array_keys($promoterBranchMap))
                ->pluck('id', 'normalized_name')
                ->all()
            : [];

        // ── 6. Bulk upsert Branches (code has DB unique constraint) ────────
        // First pass: collect all branches. For each promoter that looks like a code
        // (e.g., "ORI09247"), resolve the real branch name using BranchResolverService.
        $branchRows = [];
        foreach ($promoterBranchMap as $normalizedPromoter => $data) {
            $promoterRaw = $data['full_name'] ?? $normalizedPromoter;

            // If promoter name looks like a code, resolve real branch from its prefix
            $realBranchFromCode = null;
            if (preg_match('/^[A-Z]{2,4}\d{4,}/i', $promoterRaw)) {
                $resolvedName = $this->branchResolver->resolveBranchNameFromCode($promoterRaw);
                if ($resolvedName) {
                    $realNormalized = $this->normalizeHumanName($resolvedName);
                    if ($realNormalized !== '' && !isset($branchRows[$realNormalized])) {
                        $code = Str::upper(Str::limit(
                            preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($realNormalized)),
                            60, ''
                        ));
                        $branchRows[$realNormalized] = [
                            'code'            => $code ?: 'SUCURSAL_' . substr(md5($realNormalized), 0, 8),
                            'name'            => $resolvedName,
                            'normalized_name' => $realNormalized,
                            'is_active'       => true,
                            'created_at'      => now(),
                            'updated_at'      => now(),
                        ];
                        $realBranchFromCode = $realNormalized;
                    }
                }
            }

            // Also register the raw office/route branches (used as fallback)
            foreach ($data['branches'] as $normalizedBranch => $rawBranchName) {
                if (!isset($branchRows[$normalizedBranch])) {
                    $code = Str::upper(Str::limit(
                        preg_replace('/[^A-Za-z0-9]+/', '_', Str::ascii($normalizedBranch)),
                        60, ''
                    ));
                    $branchRows[$normalizedBranch] = [
                        'code'            => $code ?: 'SUCURSAL_' . substr(md5($normalizedBranch), 0, 8),
                        'name'            => $rawBranchName,
                        'normalized_name' => $normalizedBranch,
                        'is_active'       => true,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];
                }
            }

            // Override the 'branches' map to use the real branch if resolved
            if ($realBranchFromCode) {
                $promoterBranchMap[$normalizedPromoter]['real_branch_normalized'] = $realBranchFromCode;
            }
        }

        if (!empty($branchRows)) {
            foreach (array_chunk(array_values($branchRows), 300) as $chunk) {
                Branch::query()->upsert($chunk, ['code'], ['name', 'normalized_name', 'is_active', 'updated_at']);
            }
        }

        $branchIdMap = !empty($branchRows)
            ? Branch::query()
                ->whereIn('code', array_column(array_values($branchRows), 'code'))
                ->pluck('id', 'normalized_name')
                ->all()
            : [];

        // ── 7. Bulk upsert EmployeeBranchAssignments ───────────────────────
        // DB unique constraint: (period_id, employee_id)
        // Prefer real branch (resolved from promoter code prefix) over route/office branch.
        $assignmentRows = [];
        foreach ($promoterBranchMap as $normalizedPromoter => $data) {
            $employeeId = $employeeIdMap[$normalizedPromoter] ?? null;
            if (!$employeeId) continue;

            // Use real branch if resolved from promoter code prefix
            $realBranchNormalized = $data['real_branch_normalized'] ?? null;
            $branchesToAssign = $realBranchNormalized
                ? [$realBranchNormalized => true]
                : $data['branches'];

            foreach ($branchesToAssign as $normalizedBranch => $_) {
                $branchId = $branchIdMap[$normalizedBranch] ?? null;
                if (!$branchId) continue;

                $assignmentRows[] = [
                    'period_id'           => $upload->period_id,
                    'employee_id'         => $employeeId,
                    'branch_id'           => $branchId,
                    'source_type'         => 'lendus',
                    'match_type'          => 'normalized',
                    'confidence'          => '0.80',
                    'was_manual_reviewed' => false,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ];
            }
        }

        foreach (array_chunk($assignmentRows, 300) as $chunk) {
            EmployeeBranchAssignment::query()->upsert(
                $chunk,
                ['period_id', 'employee_id'],
                ['branch_id', 'source_type', 'match_type', 'confidence', 'was_manual_reviewed', 'updated_at'],
            );
        }

        // Routes and non-operative location strings from cobranza are silently ignored.
        // They are NOT branches, they do not generate incidents, and they do not block the report.

        $duration = round(microtime(true) - $startTime, 1);

        return [
            'promoters_detected'           => count($promoterBranchMap),
            'branches_detected'            => count($branchRows),
            'records_loaded'               => $rowsRead,
            'records_excluded'             => $rowsSkipped,
            'pending_locations'            => 0,
            'pending_location_names'       => [],
            'cobranza_rows_read'           => $rowsRead,
            'cobranza_rows_skipped'        => $rowsSkipped,
            'cobranza_promoters_detected'  => count($promoterBranchMap),
            'cobranza_branches_detected'   => count($branchRows),
            'cobranza_assignments_created' => count($assignmentRows),
            'cobranza_incidents_created'   => count($incidents),
            'cobranza_duration_seconds'    => $duration,
            'incidents'                    => $incidents,
        ];
    }

    private function readChunk(
        string $path,
        string $sheetName,
        int $startRow,
        int $endRow,
        string $lastColumnLetter,
    ): array {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $reader->setLoadSheetsOnly([$sheetName]);
        $reader->setReadFilter(new class($startRow, $endRow) implements IReadFilter {
            public function __construct(
                private readonly int $startRow,
                private readonly int $endRow,
            ) {
            }

            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row >= $this->startRow && $row <= $this->endRow;
            }
        });

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();

        $range = 'A' . $startRow . ':' . $lastColumnLetter . $endRow;

        $rows = $sheet->rangeToArray(
            $range,
            null,
            true,
            false,
            false,
        );

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $rows;
    }

    /**
     * Detect promoter and branch column indices from a buffer of header rows.
     * Returns [promoterColIndex, branchColIndex] using 0-based column positions.
     */
    private function detectCobranzaColumns(array $rows): array
    {
        $headerRowIndex   = $this->detectHeaderRowIndex($rows);
        $headerRow        = $rows[$headerRowIndex] ?? [];
        $headerMap        = $this->buildHeaderMap($headerRow);
        $promoterColIndex = $headerMap['promoter_name'] ?? null;
        $branchColIndex   = $headerMap['branch_name_raw'] ?? null;

        if ($promoterColIndex === null) {
            $detected = implode(', ', array_filter(array_map(fn($v) => $this->cellToString($v), $headerRow), fn($v) => $v !== ''));
            throw new \RuntimeException(
                "No se encontró columna de promotor/asesor en cobranza. Encabezados detectados: {$detected}"
            );
        }

        return [$promoterColIndex, $branchColIndex];
    }

    /**
     * Accumulate one data row into the promoterBranchMap (in-place, by reference).
     * Called once per streamed row; no DB queries.
     */
    private function accumulateCobranzaRow(
        array $values,
        int $promoterColIndex,
        ?int $branchColIndex,
        array &$promoterBranchMap,
        array &$noAnyBranch,
        int &$rowsRead,
        int &$rowsSkipped,
    ): void {
        $promoterRaw = $this->cleanString($values[$promoterColIndex] ?? null);

        if ($promoterRaw === null) {
            $rowsSkipped++;
            return;
        }

        $normalizedPromoter = $this->normalizeHumanName($promoterRaw);
        if ($normalizedPromoter === '') {
            $rowsSkipped++;
            return;
        }

        $rowsRead++;

        if (!isset($promoterBranchMap[$normalizedPromoter])) {
            $promoterBranchMap[$normalizedPromoter] = ['full_name' => $promoterRaw, 'branches' => []];
        }

        $branchRaw = $branchColIndex !== null
            ? $this->cleanString($values[$branchColIndex] ?? null)
            : null;

        if ($branchRaw !== null) {
            $normalizedBranch = $this->normalizeHumanName($branchRaw);
            if ($normalizedBranch !== '') {
                $promoterBranchMap[$normalizedPromoter]['branches'][$normalizedBranch] = $branchRaw;
                unset($noAnyBranch[$normalizedPromoter]);
            }
        } elseif (empty($promoterBranchMap[$normalizedPromoter]['branches'])) {
            $noAnyBranch[$normalizedPromoter] = $promoterRaw;
        }
    }

    private function updateRun(
        ?PeriodDatabaseUpdateRun $run,
        int $pct,
        string $step,
        int $processedRows = 0,
        int $totalRows = 0,
    ): void {
        if (!$run) return;

        $extra = ['current_source' => 'lendus_ingresos_cobranza'];
        if ($totalRows > 0) {
            $extra['processed_rows'] = $processedRows;
            $extra['total_rows']     = $totalRows;
        }

        $run->update([
            'log'      => $step,
            'metadata' => array_merge($run->metadata ?? [], [
                'current_step'     => $step,
                'progress_percent' => $pct,
            ] + $extra),
        ]);
    }

    private function detectHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = -1;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = array_map(
                fn ($value) => $this->normalizeHeader($this->cellToString($value)),
                $row
            );

            $score = 0;

            foreach ($normalized as $value) {
                if (
                    in_array($value, [
                        'promotor',
                        'contrato',
                        'producto_de_credito',
                        'operacion',
                        'concepto',
                        'transaccion',
                        'capital',
                        'interes',
                        'impuesto',
                        'cargos_calendario',
                        'cargos_vencimiento',
                        'excedente',
                        'total',
                        'dias_vencidos_pago',
                        'oficina',
                        'ruta',
                        'fecha_transaccion',
                    ], true)
                ) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }
        }

        return $bestIndex;
    }

    private function buildHeaderMap(array $headerRow): array
    {
        $normalizedHeaders = [];
        foreach ($headerRow as $index => $header) {
            $normalized = $this->normalizeHeader($this->cellToString($header));
            if ($normalized !== '') {
                $normalizedHeaders[(int) $index] = $normalized;
            }
        }

        // Use ColumnMapService as the single source of truth for authorized columns.
        $defs = $this->columnMap->getFieldDefinitions('lendus_ingresos_cobranza');
        $map  = [];
        $matched = [];

        foreach ($defs as $field => $def) {
            foreach ($def['aliases'] as $alias) {
                foreach ($normalizedHeaders as $index => $normalizedHeader) {
                    if ($normalizedHeader === $alias && !in_array($index, $matched, true)) {
                        $map[$field] = $index;
                        $matched[]   = $index;
                        break 2;
                    }
                }
            }
        }

        return $map;
    }

    private function mapRow(array $row, array $headerMap): array
    {
        $promoterName   = $this->cleanString($this->valueFromRow($row, $headerMap, 'promoter_name'));
        $contract       = $this->cleanString($this->valueFromRow($row, $headerMap, 'contract'));
        $clientName     = $this->cleanString($this->valueFromRow($row, $headerMap, 'client_name'));
        $branchNameRaw  = $this->cleanString($this->valueFromRow($row, $headerMap, 'branch_name_raw'));
        $rawProductName = $this->cleanString($this->valueFromRow($row, $headerMap, 'product_name'));
        $productName    = $rawProductName
            ? $this->branchResolver->normalizeProduct($rawProductName, null, null)
            : null;

        $operation   = $this->cleanString($this->valueFromRow($row, $headerMap, 'operation'));
        $concept     = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept'));
        $transaction = $this->cleanString($this->valueFromRow($row, $headerMap, 'transaction'));
        $paymentDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'payment_date'));

        $capital     = $this->toDecimal($this->valueFromRow($row, $headerMap, 'capital'))      ?? 0;
        $interest    = $this->toDecimal($this->valueFromRow($row, $headerMap, 'interest'))     ?? 0;
        $tax         = $this->toDecimal($this->valueFromRow($row, $headerMap, 'tax'))          ?? 0;
        $charges     = $this->toDecimal($this->valueFromRow($row, $headerMap, 'charges'))      ?? 0;
        $chargesDue  = $this->toDecimal($this->valueFromRow($row, $headerMap, 'charges_due'))  ?? 0;
        $excedente   = $this->toDecimal($this->valueFromRow($row, $headerMap, 'excedente'))    ?? 0;
        $daysPastDue = (int) ($this->toDecimal($this->valueFromRow($row, $headerMap, 'days_past_due')) ?? 0) ?: null;

        $explicitTotal = $this->toDecimal($this->valueFromRow($row, $headerMap, 'total'));
        $totalAmount   = $explicitTotal ?? ($capital + $interest + $tax + $charges + $chargesDue);

        return [
            'promoter_name'   => $promoterName,
            'contract'        => $contract,
            'client_name'     => $clientName,
            'branch_name_raw' => $branchNameRaw,
            'product_name'    => $productName,
            'operation'       => $operation,
            'concept'         => $concept,
            'transaction'     => $transaction,
            'payment_date'    => $paymentDate,
            'capital'         => $capital,
            'interest'        => $interest,
            'tax'             => $tax,
            'charges'         => $charges,
            'charges_due'     => $chargesDue,
            'excedente'       => $excedente,
            'total_amount'    => $totalAmount,
            'days_past_due'   => $daysPastDue,
        ];
    }

    private function shouldInsertRow(array $mapped): bool
    {
        return filled($mapped['promoter_name']);
    }

    /**
     * Resolves the operative branch using:
     * 1. Contract code prefix (e.g. "24ORI032294" → ORI → ORIZABA)
     * 2. Promoter name if it starts with a recognizable code prefix
     * 3. Raw branch/office column (internal — never shown as a metric)
     */
    private function resolveBranchFromCobranza(?string $contract, ?string $promoterName, ?string $branchNameRaw): ?Branch
    {
        if ($contract) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($contract);
            if ($branch) {
                return $branch;
            }
        }

        if ($promoterName && preg_match('/^[A-Z]{2,4}\d{4,}/i', $promoterName)) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($promoterName);
            if ($branch) {
                return $branch;
            }
        }

        return $this->resolveBranch($branchNameRaw);
    }

    private function resolveBranch(?string $branchName): ?Branch
    {
        if (!$branchName) {
            return null;
        }

        $normalized = $this->normalizeHumanName($branchName);

        if ($normalized === '') {
            return null;
        }

        if (isset($this->branchCache[$normalized])) {
            return $this->branchCache[$normalized];
        }

        // Resolve route/office name → official operative branch. Never auto-create unknown routes.
        $branch = $this->branchResolver->resolveOfficialBranchByName($branchName);

        return $this->branchCache[$normalized] = $branch;
    }


    private function valueFromRow(array $row, array $headerMap, string $field): mixed
    {
        if (!array_key_exists($field, $headerMap)) {
            return null;
        }

        return $row[$headerMap[$field]] ?? null;
    }

    private function normalizeHeader(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->value();
    }

    private function normalizeHumanName(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->cellToString($value);

        return $value !== '' ? $value : null;
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

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        $value = str_replace(['$', ' '], '', (string) $value);

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = str_replace(',', '', $value);
        } elseif (str_contains($value, ',') && !str_contains($value, '.')) {
            $value = str_replace(',', '.', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function toDateValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject($value))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
