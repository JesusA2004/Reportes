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
    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);

        $absolutePath = Storage::disk('public')->path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);

        if (empty($sheets) || empty($sheets[0])) {
            throw new \RuntimeException('El archivo de gastos está vacío o no se pudo leer.');
        }

        $rows = $sheets[0];
        $headerRowIndex = $this->detectHeaderRowIndex($rows);
        $headerRow = $rows[$headerRowIndex] ?? null;

        if (!$headerRow || !is_array($headerRow)) {
            throw new \RuntimeException('No se encontró una fila de encabezados válida en el archivo de gastos.');
        }

        $headerMap = $this->buildHeaderMap($headerRow);

        $requiredColumns = ['concept', 'amount'];

        $missingRequired = collect($requiredColumns)
            ->filter(fn (string $field) => !array_key_exists($field, $headerMap))
            ->values()
            ->all();

        if (!empty($missingRequired)) {
            throw new \RuntimeException(
                'El archivo de gastos no contiene columnas mínimas requeridas: '
                . implode(', ', $missingRequired)
                . '. Encabezados detectados: '
                . implode(', ', array_values(array_filter(array_map(fn ($value) => trim((string) $value), $headerRow))))
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

                if (!$this->shouldInsertRow($mapped)) {
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

            if ($progress && $rowsRead % 250 === 0) {
                $progress([
                    'rows_read' => $rowsRead,
                    'rows_inserted' => $rowsInserted,
                    'rows_skipped' => $rowsSkipped,
                    'rows_with_errors' => $rowsWithErrors,
                    'log' => "Procesando gastos... {$rowsRead} filas leídas.",
                ]);
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
        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($rows, 0, 50, true) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = array_map(
                fn ($value) => $this->normalizeHeader((string) $value),
                $row,
            );

            $score = 0;

            foreach ($normalized as $value) {
                if (in_array($value, [
                    'empleado',
                    'categoria',
                    'concepto',
                    'estatus',
                    'fecha_creacion',
                    'monto_gasto',
                    'monto_pagado_empleado',
                    'monto_pagado_empresa',
                    'fecha_de_autorizacion',
                    'monto_aplicado_en_caja',
                    'autorizado_por',
                    'observacion',
                    'justificacion',
                    'monto',
                    'importe',
                    'total',
                    'sucursal',
                    'oficina',
                ], true)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }

            if ($score >= 4) {
                return (int) $index;
            }
        }

        return $bestIndex;
    }

    private function buildHeaderMap(array $headerRow): array
    {
        $aliases = [
            'employee_name' => [
                'employee_name',
                'empleado',
                'nombre_empleado',
                'nombre',
                'colaborador',
                'responsable',
            ],
            'branch_name' => [
                'branch_name',
                'sucursal',
                'oficina',
                'branch',
                'unidad',
                'ruta',
            ],
            'category' => [
                'category',
                'categoria',
                'tipo_gasto',
                'rubro',
            ],
            'concept' => [
                'concept',
                'concepto',
                'descripcion',
                'detalle',
                'gasto',
            ],
            'status' => [
                'status',
                'estatus',
                'estado',
            ],
            'amount' => [
                'amount',
                'importe',
                'monto',
                'total',
                'valor',
                'monto_gasto',
                'importe_gasto',
            ],
            'paid_amount' => [
                'paid_amount',
                'monto_pagado',
                'pagado',
                'importe_pagado',
                'monto_pagado_empleado',
                'monto_pagado_empresa',
                'monto_aplicado_en_caja',
            ],
            'expense_date' => [
                'expense_date',
                'fecha_gasto',
                'fecha',
                'fecha_pago',
                'fecha_creacion',
                'fecha_de_creacion',
            ],
            'authorization_date' => [
                'authorization_date',
                'fecha_autorizacion',
                'fecha_de_autorizacion',
            ],
            'authorized_by' => [
                'authorized_by',
                'autorizado_por',
            ],
            'observations' => [
                'observations',
                'observacion',
                'observaciones',
                'comentarios',
                'notas',
            ],
            'justification' => [
                'justification',
                'justificacion',
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
        $status = $this->cleanString($this->valueFromRow($row, $headerMap, 'status'));
        $amount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'amount'));
        $paidAmount = $this->toDecimal($this->valueFromRow($row, $headerMap, 'paid_amount'));
        $expenseDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'expense_date'));
        $authorizationDate = $this->toDateValue($this->valueFromRow($row, $headerMap, 'authorization_date'));
        $authorizedBy = $this->cleanString($this->valueFromRow($row, $headerMap, 'authorized_by'));
        $observations = $this->cleanString($this->valueFromRow($row, $headerMap, 'observations'));
        $justification = $this->cleanString($this->valueFromRow($row, $headerMap, 'justification'));

        if (!$branchName) {
            $branchName = $this->extractBranchFromText($observations)
                ?? $this->extractBranchFromText($justification);
        }

        $notes = collect([
            $observations,
            $justification ? 'Justificación: ' . $justification : null,
            $authorizedBy ? 'Autorizado por: ' . $authorizedBy : null,
            $authorizationDate ? 'Fecha autorización: ' . $authorizationDate : null,
            $status ? 'Estatus: ' . $status : null,
        ])
            ->filter()
            ->implode(' | ');

        return [
            'employee_name' => $employeeName,
            'branch_name' => $branchName,
            'category' => $category,
            'concept' => $concept,
            'status' => $status,
            'amount' => $amount,
            'paid_amount' => $paidAmount,
            'expense_date' => $expenseDate,
            'observations' => $notes ?: null,
            'raw_payload' => $row,
        ];
    }

    private function shouldInsertRow(array $mapped): bool
    {
        if (!$mapped['concept']) {
            return false;
        }

        if ($mapped['amount'] === null) {
            return false;
        }

        if ((float) $mapped['amount'] == 0.0) {
            return false;
        }

        $status = $this->normalizeName((string) ($mapped['status'] ?? ''));

        if (in_array($status, ['cancelado', 'cancelada', 'rechazado', 'rechazada'], true)) {
            return false;
        }

        $concept = $this->normalizeName((string) $mapped['concept']);

        if (in_array($concept, ['total', 'subtotal', 'totales'], true)) {
            return false;
        }

        return true;
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

    private function extractBranchFromText(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $clean = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        if ($clean === '') {
            return null;
        }

        if (preg_match('/\b(?:SUCURSAL|SUCUSAL|SUCURSLA|SUCURSLA\.?)\s+(.+)$/iu', $clean, $matches)) {
            return $this->cleanBranchCandidate($matches[1] ?? null);
        }

        return null;
    }

    private function cleanBranchCandidate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^A-ZÁÉÍÓÚÜÑ0-9\s\-\._]/iu', '', $value) ?? $value;
        $value = trim($value, " \t\n\r\0\x0B.-_");

        return $value !== '' ? $value : null;
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
            $value,
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
            $value,
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
