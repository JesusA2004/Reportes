<?php

namespace App\Services\Imports;

use App\Models\Employee;
use App\Models\NoiMovement;
use App\Models\ReportUpload;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;


class NoiNominaImportService
{
    private array $employeeCache = [];

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
        $sheets = Excel::toArray([], $absolutePath);

        if (empty($sheets) || empty($sheets[0])) {
            throw new \RuntimeException('El archivo NOI está vacío o no se pudo leer.');
        }

        $rows = $sheets[0];
        $sheetName = $this->firstSheetName($absolutePath);
        $headerRowIndex = $this->detectHeaderRowIndex($rows);
        $headerRow = $rows[$headerRowIndex] ?? null;

        if (!$headerRow || !is_array($headerRow)) {
            throw new \RuntimeException('No se encontró una fila de encabezados válida en el archivo NOI.');
        }

        $headerMap = $this->buildHeaderMap($headerRow);

        $requiredColumns = ['concept', 'amount'];

        $missingRequired = collect($requiredColumns)
            ->filter(fn (string $field) => !array_key_exists($field, $headerMap))
            ->values()
            ->all();

        if (!empty($missingRequired)) {
            throw new \RuntimeException(
                'El archivo NOI no contiene columnas mínimas requeridas: '
                . implode(', ', $missingRequired)
                . '. Encabezados detectados: '
                . implode(', ', array_values(array_filter($headerRow, fn ($value) => filled($value))))
            );
        }

        NoiMovement::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsWithErrors = 0;

        $currentEmployeeName = null;
        $currentEmployeeCode = null;

        foreach (array_slice($rows, $headerRowIndex + 1, null, true) as $rowIndex => $row) {
            if (!is_array($row) || $this->isEmptyRow($row)) {
                $rowsSkipped++;
                continue;
            }

            $rowsRead++;
            $sourceRowNumber = $rowIndex + 1;

            try {
                $mapped = $this->mapRow($row, $headerMap);

                if ($mapped['employee_code']) {
                    $currentEmployeeCode = $mapped['employee_code'];
                }

                if ($this->isValidEmployeeName($mapped['employee_name'])) {
                    $currentEmployeeName = $mapped['employee_name'];
                }

                if (
                    !$this->isValidEmployeeName($mapped['employee_name']) &&
                    $currentEmployeeName &&
                    ($mapped['concept'] || $mapped['amount'] !== null)
                ) {
                    $mapped['employee_name'] = $currentEmployeeName;
                }

                if (
                    !$mapped['employee_code'] &&
                    $currentEmployeeCode &&
                    ($mapped['concept'] || $mapped['amount'] !== null)
                ) {
                    $mapped['employee_code'] = $currentEmployeeCode;
                }

                if (!$this->shouldInsertRow($mapped)) {
                    $rowsSkipped++;
                    continue;
                }

                $employee = $this->resolveEmployee($mapped);

                if (!$employee) {
                    $rowsSkipped++;
                    continue;
                }

                $normalizedRow = json_encode($mapped['raw_payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                NoiMovement::query()->create([
                    'period_id' => $upload->period_id,
                    'employee_id' => $employee->id,
                    'report_upload_id' => $upload->id,
                    'concept' => $mapped['concept'] ?: 'Sin concepto',
                    'concept_type' => $mapped['concept_type'] ?: $this->inferConceptType($mapped['concept']),
                    'amount' => $mapped['amount'],
                    'quantity' => 1,
                    'payroll_type' => $mapped['payroll_type'] ?: 'NOI',
                    'movement_date' => $mapped['movement_date'] ?? now()->toDateString(),
                    'raw_row_hash' => hash('sha256', $normalizedRow),
                    'source_row_key' => hash('sha256', implode('|', [
                        $upload->id,
                        $sheetName,
                        $sourceRowNumber,
                        $normalizedRow,
                    ])),
                    'raw_payload' => $mapped['raw_payload'],
                ]);

                $rowsInserted++;
            } catch (\Throwable $e) {
                $rowsWithErrors++;

                throw new \RuntimeException(
                    'Error al insertar fila NOI. Empleado: '
                    . ($mapped['employee_name'] ?? 'N/D')
                    . ' | Concepto: '
                    . ($mapped['concept'] ?? 'N/D')
                    . ' | Amount: '
                    . (($mapped['amount'] ?? null) === null ? 'NULL' : (string) $mapped['amount'])
                    . ' | Detalle: '
                    . $e->getMessage()
                );
            }

            if ($progress && $rowsRead % 250 === 0) {
                $progress([
                    'rows_read' => $rowsRead,
                    'rows_inserted' => $rowsInserted,
                    'rows_skipped' => $rowsSkipped,
                    'rows_with_errors' => $rowsWithErrors,
                    'log' => "Procesando NOI... {$rowsRead} filas leídas.",
                ]);
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsWithErrors,
            'log' => sprintf(
                'Importación NOI completada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d.',
                $rowsRead,
                $rowsInserted,
                $rowsSkipped,
                $rowsWithErrors
            ),
        ];
    }

    public function scanForDatabaseUpdate(ReportUpload $upload): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo NOI no tiene ruta de almacenamiento.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de NOI no existe en storage/public.');
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
            throw new \RuntimeException('No se encontraron hojas en el archivo NOI.');
        }

        $sheetName = $sheetsInfo[0]['worksheetName'];
        $highestRow = (int) ($sheetsInfo[0]['totalRows'] ?? 0);

        if ($highestRow <= 0) {
            throw new \RuntimeException('El archivo NOI no contiene filas útiles.');
        }

        $employeesByKey = [];
        $rowsRead = 0;
        $rowsSkipped = 0;
        $incidents = [];

        /*
         * Actualizar BD debe ser ligero:
         * para NOI solo detectamos empleados desde columnas A y B.
         * No leemos conceptos, importes, acumulados ni movimientos.
         */
        $chunkSize = 300;

        for ($start = 1; $start <= $highestRow; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $highestRow);

            $chunkRows = $this->readNoiEmployeeColumnsForDatabaseUpdate(
                path: $absolutePath,
                sheetName: $sheetName,
                startRow: $start,
                endRow: $end,
            );

            foreach ($chunkRows as $row) {
                if (!is_array($row) || $this->isEmptyRow($row)) {
                    $rowsSkipped++;
                    continue;
                }

                $rowsRead++;

                $col0 = $this->cleanString($row[0] ?? null);
                $col1 = $this->cleanString($row[1] ?? null);

                $employeeCode = $this->extractEmployeeCode($col1);
                $employeeName = null;

                if ($this->isValidEmployeeName($col1)) {
                    $employeeName = $col1;
                } elseif ($this->isValidEmployeeName($col0)) {
                    $employeeName = $col0;
                }

                if (!$this->isValidEmployeeName($employeeName)) {
                    $rowsSkipped++;
                    continue;
                }

                $normalized = $this->normalizeName($employeeName);

                if ($normalized === '') {
                    $rowsSkipped++;
                    continue;
                }

                $key = $employeeCode ? "code:{$employeeCode}" : "name:{$normalized}";

                if (isset($employeesByKey[$key])) {
                    continue;
                }

                [$firstName, $paternalLastName, $maternalLastName] = $this->splitName($employeeName);

                $employeesByKey[$key] = [
                    'employee_code' => $employeeCode,
                    'full_name' => $employeeName,
                    'normalized_name' => $normalized,
                    'first_name' => $firstName,
                    'paternal_last_name' => $paternalLastName,
                    'maternal_last_name' => $maternalLastName,
                    'is_active' => true,
                    'source_system' => 'noi',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            unset($chunkRows);
            gc_collect_cycles();
        }

        $employeesDetected = 0;
        $employees = array_values($employeesByKey);

        if (!empty($employees)) {
            $withCode = collect($employees)
                ->filter(fn (array $employee) => filled($employee['employee_code']))
                ->values();

            $withoutCode = collect($employees)
                ->filter(fn (array $employee) => blank($employee['employee_code']))
                ->values();

            foreach ($withCode->chunk(300) as $chunk) {
                Employee::query()->upsert(
                    $chunk->all(),
                    ['employee_code', 'source_system'],
                    [
                        'full_name',
                        'normalized_name',
                        'first_name',
                        'paternal_last_name',
                        'maternal_last_name',
                        'is_active',
                        'updated_at',
                    ],
                );

                $employeesDetected += $chunk->count();
            }

            foreach ($withoutCode->chunk(300) as $chunk) {
                Employee::query()->upsert(
                    $chunk->all(),
                    ['normalized_name', 'source_system'],
                    [
                        'full_name',
                        'first_name',
                        'paternal_last_name',
                        'maternal_last_name',
                        'is_active',
                        'updated_at',
                    ],
                );

                $employeesDetected += $chunk->count();
            }
        }

        return [
            'employees_detected' => $employeesDetected,
            'rows_read' => $rowsRead,
            'rows_skipped' => $rowsSkipped,
            'incidents' => $incidents,
        ];
    }

    private function firstSheetName(string $absolutePath): string
    {
        try {
            $reader = IOFactory::createReaderForFile($absolutePath);
            $sheetsInfo = $reader->listWorksheetInfo($absolutePath);

            return $sheetsInfo[0]['worksheetName'] ?? 'Sheet1';
        } catch (\Throwable) {
            return 'Sheet1';
        }
    }

    private function detectHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = array_map(
                fn ($value) => $this->normalizeHeader((string) $value),
                $row
            );

            $score = 0;

            foreach ($normalized as $value) {
                if (in_array($value, [
                    'nombre_del_trabajador',
                    'concepto',
                    'acumulado',
                    'importe',
                    'monto',
                    'total',
                ], true)) {
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
            $normalizedHeaders[(int) $index] = $this->normalizeHeader((string) $header);
        }

        $aliases = [
            'concept' => [
                'concept',
                'concepto',
                'descripcion_concepto',
                'movimiento',
                'descripcion',
            ],
            'concept_type' => [
                'concept_type',
                'tipo_concepto',
                'tipo',
                'naturaleza',
                'tipo_movimiento',
            ],
            'amount' => [
                'amount',
                'importe',
                'monto',
                'neto',
                'total',
                'importe_neto',
                'pago_neto',
                'valor',
                'acumulado',
            ],
            'quantity' => [
                'quantity',
                'cantidad',
                'unidades',
                'dias',
                'horas',
            ],
            'payroll_type' => [
                'payroll_type',
                'tipo_nomina',
                'nomina',
                'nomina_tipo',
                'periodo_nomina',
            ],
            'movement_date' => [
                'movement_date',
                'fecha',
                'fecha_movimiento',
                'fecha_aplicacion',
                'fecha_nomina',
                'periodo',
            ],
        ];

        $map = [];

        foreach ($aliases as $field => $possibleHeaders) {
            foreach ($normalizedHeaders as $index => $normalizedHeader) {
                if (in_array($normalizedHeader, $possibleHeaders, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private function mapRow(array $row, array $headerMap): array
    {
        $col0 = $this->cleanString($row[0] ?? null);
        $col1 = $this->cleanString($row[1] ?? null);

        $employeeCode = $this->extractEmployeeCode($col1);

        $employeeName = null;

        if ($this->isValidEmployeeName($col1)) {
            $employeeName = $col1;
        } elseif ($this->isValidEmployeeName($col0)) {
            $employeeName = $col0;
        }

        $concept = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept'));
        $conceptType = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept_type'));
        $amount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'amount'));
        $quantity = $this->toDecimal($this->valueFromRow($row, $headerMap, 'quantity'));

        // Deducciones (D###) must be stored as positive absolute values.
        // The SnapshotBuilder subtracts them; if they arrive as negative, abs() prevents double-negation.
        if ($amount !== null && $concept && preg_match('/^D\d{3}\b/i', trim($concept)) && $amount < 0) {
            $amount = abs($amount);
        }
        $payrollType = $this->cleanString($this->valueFromRow($row, $headerMap, 'payroll_type'));
        $movementDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'movement_date'));

        return [
            'employee_code' => $employeeCode,
            'employee_name' => $employeeName,
            'concept' => $concept,
            'concept_type' => $conceptType,
            'amount' => $amount,
            'quantity' => $quantity,
            'payroll_type' => $payrollType,
            'movement_date' => $movementDate,
            'raw_payload' => $row,
        ];
    }

    private function shouldInsertRow(array $mapped): bool
    {
        if (!$this->isValidEmployeeName($mapped['employee_name'])) {
            return false;
        }

        if (!$mapped['concept']) {
            return false;
        }

        if ($mapped['amount'] === null) {
            return false;
        }

        if ((float) $mapped['amount'] == 0.0) {
            return false;
        }

        $concept = $this->normalizeText($mapped['concept']);

        if (in_array($concept, ['acumulado', 'total', 'totales', 'subtotal', 'sub_total'], true)) {
            return false;
        }

        return true;
    }

    private function resolveEmployee(array $mapped): ?Employee
    {
        $employeeCode = $mapped['employee_code'] ?: null;
        $fullName = $mapped['employee_name'];

        if (!$this->isValidEmployeeName($fullName)) {
            return null;
        }

        $normalizedName = $this->normalizeName($fullName);

        if ($normalizedName === '') {
            return null;
        }

        [$firstName, $paternalLastName, $maternalLastName] = $this->splitName($fullName);

        $cacheKey = $employeeCode
            ? "code:{$employeeCode}"
            : "name:{$normalizedName}";

        if (array_key_exists($cacheKey, $this->employeeCache)) {
            return $this->employeeCache[$cacheKey];
        }

        if ($employeeCode) {
            $employee = Employee::query()->updateOrCreate(
                [
                    'employee_code' => $employeeCode,
                    'source_system' => 'noi',
                ],
                [
                    'full_name' => $fullName,
                    'normalized_name' => $normalizedName,
                    'first_name' => $firstName,
                    'paternal_last_name' => $paternalLastName,
                    'maternal_last_name' => $maternalLastName,
                    'is_active' => true,
                ],
            );

            return $this->employeeCache[$cacheKey] = $employee;
        }

        $employee = Employee::query()->updateOrCreate(
            [
                'normalized_name' => $normalizedName,
                'source_system' => 'noi',
            ],
            [
                'employee_code' => null,
                'full_name' => $fullName,
                'first_name' => $firstName,
                'paternal_last_name' => $paternalLastName,
                'maternal_last_name' => $maternalLastName,
                'is_active' => true,
            ],
        );

        return $this->employeeCache[$cacheKey] = $employee;
    }

    private function extractEmployeeCode(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        if (preg_match('/clave\s+del\s+trabajador\s*:\s*(\d+)/i', $value, $matches)) {
            return $matches[1];
        }

        return null;
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

    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    private function normalizeText(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]+/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName)) ?: [];
        $parts = array_values(array_filter($parts, fn ($part) => $part !== ''));

        return [
            $parts[0] ?? null,
            $parts[1] ?? null,
            $parts[2] ?? null,
        ];
    }

    /**
     * Infer concept type from NOI concept code prefix when concept_type column is missing.
     * NOI typically uses: P### for percepciones, D### for deducciones.
     */
    private function inferConceptType(?string $concept): ?string
    {
        if (!$concept) {
            return null;
        }

        $normalized = strtoupper(trim($concept));

        // P### NOMBRE → percepcion
        if (preg_match('/^P\d{3}\b/', $normalized)) {
            return 'percepcion';
        }

        // D### NOMBRE → deduccion
        if (preg_match('/^D\d{3}\b/', $normalized)) {
            return 'deduccion';
        }

        // Keyword fallback
        $lower = strtolower($normalized);
        if (str_contains($lower, 'sueldo') || str_contains($lower, 'salario') ||
            str_contains($lower, 'vacacion') || str_contains($lower, 'bono') ||
            str_contains($lower, 'prima') || str_contains($lower, 'compensacion') ||
            str_contains($lower, 'percepcion')) {
            return 'percepcion';
        }

        if (str_contains($lower, 'descuento') || str_contains($lower, 'prestamo') ||
            str_contains($lower, 'deduccion') || str_contains($lower, 'imss') ||
            str_contains($lower, 'isr') || str_contains($lower, 'impuesto')) {
            return 'deduccion';
        }

        return null;
    }

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function isValidEmployeeName(?string $value): bool
    {
        if (!$value) {
            return false;
        }

        $value = trim($value);

        if ($value === '' || $value === '-' || $value === '--') {
            return false;
        }

        if (stripos($value, 'clave del trabajador') !== false) {
            return false;
        }

        $normalized = $this->normalizeName($value);

        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, [
            'nombre',
            'trabajador',
            'empleado',
            'nombre del trabajador',
            'nombre trabajador',
            'nombre empleado',
        ], true)) {
            return false;
        }

        return true;
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

    private function readNoiEmployeeColumnsForDatabaseUpdate(
        string $path,
        string $sheetName,
        int $startRow,
        int $endRow,
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
                if ($row < $this->startRow || $row > $this->endRow) {
                    return false;
                }

                return in_array($columnAddress, ['A', 'B'], true);
            }
        });

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getSheetByName($sheetName) ?? $spreadsheet->getActiveSheet();

        $rows = $sheet->rangeToArray(
            'A' . $startRow . ':B' . $endRow,
            null,
            true,
            false,
            false,
        );

        $spreadsheet->disconnectWorksheets();
        unset($sheet, $spreadsheet);

        return $rows;
    }

}
