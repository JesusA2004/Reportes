<?php

namespace App\Services\Imports;

use App\Models\Employee;
use App\Models\NoiMovement;
use App\Models\ReportUpload;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class NoiNominaImportService {

    public function handle(ReportUpload $upload): array {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }
        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }
        $absolutePath = Storage::disk('public')->path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);
        if (empty($sheets) || empty($sheets[0])) {
            throw new \RuntimeException('El archivo NOI está vacío o no se pudo leer.');
        }
        $rows = $sheets[0];
        $headerRow = $rows[0] ?? null;
        if (!$headerRow || !is_array($headerRow)) {
            throw new \RuntimeException('No se encontró una fila de encabezados válida.');
        }
        $headerMap = $this->buildHeaderMap($headerRow);
        $requiredColumns = ['employee_name', 'concept', 'amount'];
        $missingRequired = collect($requiredColumns)
            ->filter(fn (string $field) => !array_key_exists($field, $headerMap))
            ->values()
            ->all();
        if (!empty($missingRequired)) {
            throw new \RuntimeException(
                'El archivo NOI no contiene columnas mínimas requeridas: ' . implode(', ', $missingRequired) . '.'
            );
        }
        NoiMovement::query()
            ->where('report_upload_id', $upload->id)
            ->delete();
        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsWithErrors = 0;
        foreach (array_slice($rows, 1) as $rowIndex => $row) {
            if (!is_array($row) || $this->isEmptyRow($row)) {
                $rowsSkipped++;
                continue;
            }
            $rowsRead++;
            try {
                $mapped = $this->mapRow($row, $headerMap);
                if (!$mapped['employee_name'] || $mapped['amount'] === null) {
                    $rowsSkipped++;
                    continue;
                }
                $employee = $this->resolveEmployee($mapped);
                NoiMovement::query()->create([
                    'period_id' => $upload->period_id,
                    'employee_id' => $employee?->id,
                    'report_upload_id' => $upload->id,
                    'concept' => $mapped['concept'],
                    'concept_type' => $mapped['concept_type'],
                    'amount' => $mapped['amount'],
                    'quantity' => $mapped['quantity'],
                    'payroll_type' => $mapped['payroll_type'],
                    'movement_date' => $mapped['movement_date'],
                    'raw_row_hash' => hash('sha256', json_encode($mapped['raw_payload'], JSON_UNESCAPED_UNICODE)),
                    'raw_payload' => $mapped['raw_payload'],
                ]);
                $rowsInserted++;
            } catch (\Throwable $exception) {
                $rowsWithErrors++;
            }
        }
        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsWithErrors,
            'log' => sprintf(
                'Importación NOI finalizada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d.',
                $rowsRead,
                $rowsInserted,
                $rowsSkipped,
                $rowsWithErrors,
            ),
        ];
    }

    private function buildHeaderMap(array $headerRow): array {
        $aliases = [
            'employee_code' => [
                'employee_code', 'codigo_empleado', 'clave_empleado', 'num_empleado', 'numero_empleado',
                'no_empleado', 'id_empleado', 'codigo', 'clave',
            ],
            'employee_name' => [
                'employee_name', 'empleado', 'nombre_empleado', 'nombre', 'trabajador', 'colaborador',
                'nombre_completo',
            ],
            'concept' => [
                'concept', 'concepto', 'descripcion_concepto', 'descripcion',
            ],
            'concept_type' => [
                'concept_type', 'tipo_concepto', 'tipo', 'tipo_movimiento', 'naturaleza',
            ],
            'amount' => [
                'amount', 'importe', 'monto', 'total', 'valor',
            ],
            'quantity' => [
                'quantity', 'cantidad', 'unidades',
            ],
            'payroll_type' => [
                'payroll_type', 'tipo_nomina', 'nomina', 'tipo_de_nomina',
            ],
            'movement_date' => [
                'movement_date', 'fecha_movimiento', 'fecha', 'fecha_nomina',
            ],
        ];
        $normalizedHeaders = [];
        foreach ($headerRow as $index => $value) {
            $normalizedHeaders[$index] = $this->normalizeHeader((string) $value);
        }
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

    private function mapRow(array $row, array $headerMap): array {
        $employeeCode = $this->valueFromRow($row, $headerMap, 'employee_code');
        $employeeName = $this->cleanString($this->valueFromRow($row, $headerMap, 'employee_name'));
        $concept = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept'));
        $conceptType = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept_type'));
        $amount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'amount'));
        $quantity = $this->toDecimal($this->valueFromRow($row, $headerMap, 'quantity'));
        $payrollType = $this->cleanString($this->valueFromRow($row, $headerMap, 'payroll_type'));
        $movementDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'movement_date'));
        return [
            'employee_code' => $this->cleanString($employeeCode),
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

    private function resolveEmployee(array $mapped): ?Employee {
        $employeeCode = $mapped['employee_code'] ?: null;
        $fullName = $mapped['employee_name'];
        if (!$fullName) {
            return null;
        }
        $normalizedName = $this->normalizeName($fullName);
        [$firstName, $paternalLastName, $maternalLastName] = $this->splitName($fullName);
        if ($employeeCode) {
            return Employee::query()->updateOrCreate(
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
        }
        return Employee::query()->updateOrCreate(
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
    }

    private function valueFromRow(array $row, array $headerMap, string $field): mixed
    {
        if (!array_key_exists($field, $headerMap)) {
            return null;
        }
        $index = $headerMap[$field];
        return $row[$index] ?? null;
    }

    private function normalizeHeader(string $value): string {
        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value
        );
        $value = preg_replace('/[^a-z0-9]+/u', '_', $value) ?? $value;
        $value = trim($value, '_');
        return $value;
    }

    private function normalizeName(string $value): string {
        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value
        );
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function splitName(string $fullName): array {
        $parts = preg_split('/\s+/u', trim($fullName)) ?: [];
        if (count($parts) === 0) {
            return [null, null, null];
        }
        if (count($parts) === 1) {
            return [$parts[0], null, null];
        }
        if (count($parts) === 2) {
            return [$parts[0], $parts[1], null];
        }
        $maternalLastName = array_pop($parts);
        $paternalLastName = array_pop($parts);
        $firstName = implode(' ', $parts);
        return [
            $firstName ?: null,
            $paternalLastName ?: null,
            $maternalLastName ?: null,
        ];
    }

    private function toDecimal(mixed $value): ?float {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        $normalized = str_replace(['$', ',', ' '], '', (string) $value);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }
        return round((float) $normalized, 2);
    }

    private function toDateValue(mixed $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
                return $date->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return \Carbon\Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function cleanString(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function isEmptyRow(array $row): bool {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

}
