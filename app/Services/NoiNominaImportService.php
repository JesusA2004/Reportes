<?php

namespace App\Services\Imports;

use App\Enums\ProcessRunStatus;
use App\Enums\ProcessType;
use App\Enums\ReportUploadStatus;
use App\Models\Employee;
use App\Models\NoiMovement;
use App\Models\ProcessRun;
use App\Models\ReportUpload;
use App\Support\NameNormalizer;
use App\Support\RowCleaner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;

class NoiNominaImportService {

    public function handle(int $reportUploadId): void {
        $upload = ReportUpload::findOrFail($reportUploadId);
        $run = ProcessRun::create([
            'period_id' => $upload->period_id,
            'report_upload_id' => $upload->id,
            'process_type' => ProcessType::Import,
            'status' => ProcessRunStatus::Running,
            'started_at' => now(),
        ]);
        $upload->update([
            'status' => ReportUploadStatus::Processing,
        ]);
        $rowsRead = 0;
        $rowsInserted = 0;
        $rowsSkipped = 0;
        $rowsErrors = 0;
        $logLines = [];
        try {
            $fullPath = storage_path('app/public/' . $upload->stored_path);
            $sheets = Excel::toArray([], $fullPath);
            foreach ($sheets as $sheetIndex => $rows) {
                $headerRowIndex = $this->detectHeaderRowIndex($rows);
                if ($headerRowIndex === null) {
                    $logLines[] = "Hoja {$sheetIndex}: no se detectó encabezado.";
                    continue;
                }
                $header = $this->normalizeHeaderRow($rows[$headerRowIndex]);
                foreach ($rows as $index => $row) {
                    if ($index <= $headerRowIndex) {
                        continue;
                    }
                    $rowAssoc = $this->mapRow($header, $row);
                    if (RowCleaner::isEmpty($rowAssoc)) {
                        $rowsSkipped++;
                        continue;
                    }
                    $rowsRead++;
                    try {
                        DB::transaction(function () use (
                            $rowAssoc,
                            $upload,
                            &$rowsInserted,
                            &$rowsSkipped
                        ) {
                            $parsed = $this->parseNoiRow($rowAssoc);
                            if (!$parsed) {
                                $rowsSkipped++;
                                return;
                            }
                            $employee = Employee::firstOrCreate(
                                [
                                    'employee_code' => $parsed['employee_code'] ?: null,
                                    'source_system' => 'noi',
                                ],
                                [
                                    'full_name' => $parsed['full_name'],
                                    'normalized_name' => NameNormalizer::normalize($parsed['full_name']),
                                    'first_name' => null,
                                    'paternal_last_name' => null,
                                    'maternal_last_name' => null,
                                    'is_active' => true,
                                ]
                            );
                            if (blank($employee->full_name) && filled($parsed['full_name'])) {
                                $employee->update([
                                    'full_name' => $parsed['full_name'],
                                    'normalized_name' => NameNormalizer::normalize($parsed['full_name']),
                                ]);
                            }
                            NoiMovement::create([
                                'period_id' => $upload->period_id,
                                'employee_id' => $employee->id,
                                'report_upload_id' => $upload->id,
                                'concept' => $parsed['concept'],
                                'concept_type' => $parsed['concept_type'],
                                'amount' => $parsed['amount'],
                                'quantity' => $parsed['quantity'],
                                'payroll_type' => $parsed['payroll_type'],
                                'movement_date' => $parsed['movement_date'],
                                'raw_row_hash' => md5(json_encode($rowAssoc)),
                                'raw_payload' => $rowAssoc,
                            ]);
                            $rowsInserted++;
                        });
                    } catch (\Throwable $e) {
                        $rowsErrors++;
                        $logLines[] = 'Fila con error: ' . $e->getMessage();
                    }
                }
            }
            $run->update([
                'status' => ProcessRunStatus::Success,
                'rows_read' => $rowsRead,
                'rows_inserted' => $rowsInserted,
                'rows_skipped' => $rowsSkipped,
                'rows_with_errors' => $rowsErrors,
                'log' => implode(PHP_EOL, $logLines),
                'finished_at' => now(),
            ]);
            $upload->update([
                'status' => ReportUploadStatus::Processed,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error importando NOI', [
                'report_upload_id' => $upload->id,
                'message' => $e->getMessage(),
            ]);
            $run->update([
                'status' => ProcessRunStatus::Failed,
                'rows_read' => $rowsRead,
                'rows_inserted' => $rowsInserted,
                'rows_skipped' => $rowsSkipped,
                'rows_with_errors' => $rowsErrors + 1,
                'log' => implode(PHP_EOL, array_merge($logLines, [$e->getMessage()])),
                'finished_at' => now(),
            ]);
            $upload->update([
                'status' => ReportUploadStatus::Failed,
            ]);
            throw $e;
        }
    }

    protected function detectHeaderRowIndex(array $rows): ?int {
        foreach ($rows as $index => $row) {
            $joined = NameNormalizer::normalize(implode(' ', array_map(fn ($v) => (string) $v, $row)));
            if (
                str_contains($joined, 'TRABAJADOR') ||
                str_contains($joined, 'EMPLEADO') ||
                str_contains($joined, 'NOMBRE') ||
                str_contains($joined, 'CONCEPTO')
            ) {
                return $index;
            }
        }
        return null;
    }

    protected function normalizeHeaderRow(array $header): array {
        return array_map(function ($value) {
            return NameNormalizer::normalize((string) $value);
        }, $header);
    }

    protected function mapRow(array $header, array $row): array {
        $assoc = [];
        foreach ($header as $index => $columnName) {
            $assoc[$columnName ?: "COL_{$index}"] = $row[$index] ?? null;
        }
        return $assoc;
    }

    protected function parseNoiRow(array $row): ?array {
        $employeeCode = $this->pick($row, [
            'CLAVE',
            'CLAVE TRAB',
            'CLAVE TRABAJADOR',
            'TRABAJADOR',
            'CODIGO',
            'CODIGO EMPLEADO',
        ]);
        $fullName = $this->pick($row, [
            'NOMBRE',
            'NOMBRE EMPLEADO',
            'TRABAJADOR',
            'EMPLEADO',
            'NOMBRE DEL TRABAJADOR',
        ]);
        $concept = $this->pick($row, [
            'CONCEPTO',
            'DESC CONCEPTO',
            'DESCRIPCION CONCEPTO',
        ]);
        $amount = $this->toDecimal($this->pick($row, [
            'IMPORTE',
            'TOTAL',
            'VALOR',
            'MONTO',
        ]));
        $quantity = $this->toDecimal($this->pick($row, [
            'UNIDADES',
            'CANTIDAD',
            'DIAS',
            'HORAS',
        ]));
        $payrollType = $this->pick($row, [
            'TIPO NOMINA',
            'NOMINA',
            'PERIODO NOMINA',
        ]);
        $movementDate = $this->toDate($this->pick($row, [
            'FECHA',
            'FECHA MOVIMIENTO',
            'FECHA APLICACION',
        ]));
        if (blank($employeeCode) && blank($fullName) && blank($concept)) {
            return null;
        }
        return [
            'employee_code' => filled($employeeCode) ? trim((string) $employeeCode) : null,
            'full_name' => filled($fullName) ? trim((string) $fullName) : 'SIN NOMBRE',
            'concept' => filled($concept) ? trim((string) $concept) : 'SIN CONCEPTO',
            'concept_type' => $this->resolveConceptType((string) $concept),
            'amount' => $amount,
            'quantity' => $quantity,
            'payroll_type' => filled($payrollType) ? trim((string) $payrollType) : null,
            'movement_date' => $movementDate,
        ];
    }

    protected function pick(array $row, array $keys): mixed {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && filled($row[$key])) {
                return $row[$key];
            }
        }
        return null;
    }

    protected function toDecimal(mixed $value): float {
        if (blank($value)) {
            return 0;
        }
        $value = str_replace([',', '$', ' '], '', (string) $value);
        return is_numeric($value) ? (float) $value : 0;
    }

    protected function toDate(mixed $value): ?string {
        if (blank($value)) {
            return null;
        }
        try {
            return \Carbon\Carbon::parse($value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function resolveConceptType(string $concept): ?string {
        $concept = NameNormalizer::normalize($concept);
        if (str_contains($concept, 'BONO')) {
            return 'bono';
        }
        if (str_contains($concept, 'DEDUCCION') || str_contains($concept, 'DESCUENTO')) {
            return 'descuento';
        }
        if (str_contains($concept, 'INCIDENCIA')) {
            return 'incidencia';
        }
        if (str_contains($concept, 'SUELDO') || str_contains($concept, 'PAGO')) {
            return 'pago';
        }
        return 'otro';
    }

}
