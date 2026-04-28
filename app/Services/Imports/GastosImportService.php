<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ReportUpload;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class GastosImportService
{
    public function handle(ReportUpload $upload): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        $absolutePath = Storage::disk('public')->path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);

        if (empty($sheets) || empty($sheets[0])) {
            throw new \RuntimeException('El archivo de gastos está vacío o no se pudo leer.');
        }

        $rows = $sheets[0];
        $headerRowIndex = $this->detectHeaderRowIndex($rows);
        $headerRow = $rows[$headerRowIndex] ?? null;

        if (!$headerRow || !is_array($headerRow)) {
            throw new \RuntimeException('No se encontró una fila de encabezados válida.');
        }

        $headerMap = $this->buildHeaderMap($headerRow);

        $requiredColumns = ['concept', 'amount'];
        $missingRequired = collect($requiredColumns)
            ->filter(fn (string $field) => !array_key_exists($field, $headerMap))
            ->values()
            ->all();

        if (!empty($missingRequired)) {
            throw new \RuntimeException(
                'El archivo de gastos no contiene columnas mínimas requeridas: ' . implode(', ', $missingRequired) . '.'
            );
        }

        Expense::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsWithErrors = 0;

        foreach (array_slice($rows, $headerRowIndex + 1) as $row) {
            if (!is_array($row) || $this->isEmptyRow($row)) {
                $rowsSkipped++;
                continue;
            }

            $rowsRead++;

            try {
                $mapped = $this->mapRow($row, $headerMap);

                if (!$mapped['concept'] || $mapped['amount'] === null) {
                    $rowsSkipped++;
                    continue;
                }

                $employee = $this->resolveEmployee($mapped['employee_name']);
                $branch = $this->resolveBranch($mapped['branch_name']);

                Expense::query()->create([
                    'period_id' => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'employee_id' => $employee?->id,
                    'branch_id' => $branch?->id,
                    'category' => $mapped['category'],
                    'concept' => $mapped['concept'],
                    'amount' => $mapped['amount'],
                    'paid_amount' => $mapped['paid_amount'],
                    'expense_date' => $mapped['expense_date'],
                    'observations' => $mapped['observations'],
                    'raw_payload' => null,
                ]);

                $rowsInserted++;
            } catch (\Throwable) {
                $rowsWithErrors++;
            }
        }

        return [
            'rows_read' => $rowsRead,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $rowsSkipped,
            'rows_with_errors' => $rowsWithErrors,
            'log' => sprintf(
                'Importación de gastos finalizada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d.',
                $rowsRead,
                $rowsInserted,
                $rowsSkipped,
                $rowsWithErrors,
            ),
        ];
    }


    private function detectHeaderRowIndex(array $rows): int
    {
        foreach (array_slice($rows, 0, 25, true) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = array_map(fn ($value) => $this->normalizeHeader((string) $value), $row);
            $score = 0;

            foreach ($normalized as $value) {
                if (in_array($value, ['empleado', 'categoria', 'concepto', 'monto_gasto', 'monto', 'sucursal', 'oficina'], true)) {
                    $score++;
                }
            }

            if ($score >= 2) {
                return (int) $index;
            }
        }

        return 0;
    }

    private function buildHeaderMap(array $headerRow): array
    {
        $aliases = [
            'employee_name' => [
                'employee_name', 'empleado', 'nombre_empleado', 'nombre', 'colaborador', 'responsable',
            ],
            'branch_name' => [
                'branch_name', 'sucursal', 'oficina', 'branch', 'unidad',
            ],
            'category' => [
                'category', 'categoria', 'tipo_gasto', 'rubro',
            ],
            'concept' => [
                'concept', 'concepto', 'descripcion', 'detalle', 'gasto',
            ],
            'amount' => [
                'amount', 'importe', 'monto', 'total', 'valor',
            ],
            'paid_amount' => [
                'paid_amount', 'monto_pagado', 'pagado', 'importe_pagado',
            ],
            'expense_date' => [
                'expense_date', 'fecha_gasto', 'fecha', 'fecha_pago',
            ],
            'observations' => [
                'observations', 'observaciones', 'comentarios', 'notas',
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

    private function mapRow(array $row, array $headerMap): array
    {
        $employeeName = $this->cleanString($this->valueFromRow($row, $headerMap, 'employee_name'));
        $branchName = $this->cleanString($this->valueFromRow($row, $headerMap, 'branch_name'));
        $category = $this->cleanString($this->valueFromRow($row, $headerMap, 'category'));
        $concept = $this->cleanString($this->valueFromRow($row, $headerMap, 'concept'));
        $amount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'amount'));
        $paidAmount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'paid_amount'));
        $expenseDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'expense_date'));
        $observations = $this->cleanString($this->valueFromRow($row, $headerMap, 'observations'));

        return [
            'employee_name' => $employeeName,
            'branch_name' => $branchName,
            'category' => $category,
            'concept' => $concept,
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'expense_date' => $expenseDate,
            'observations' => $observations,
            'raw_payload' => $row,
        ];
    }

    private function resolveEmployee(?string $fullName): ?Employee
    {
        if (!$fullName) {
            return null;
        }

        $normalizedName = $this->normalizeName($fullName);

        return Employee::query()
            ->where('normalized_name', $normalizedName)
            ->first();
    }

    private function resolveBranch(?string $branchName): ?Branch
    {
        if (!$branchName) {
            return null;
        }

        $normalizedName = $this->normalizeName($branchName);

        return Branch::query()
            ->where('normalized_name', $normalizedName)
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($branchName)])
            ->first();
    }

    private function valueFromRow(array $row, array $headerMap, string $field): mixed
    {
        if (!array_key_exists($field, $headerMap)) {
            return null;
        }

        $index = $headerMap[$field];

        return $row[$index] ?? null;
    }

    private function normalizeHeader(string $value): string
    {
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

    private function normalizeName(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value
        );

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function toDecimal(mixed $value): ?float
    {
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

    private function toDateValue(mixed $value): ?string
    {
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

    private function cleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
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
