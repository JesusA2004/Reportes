<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Placement;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class LendusMinistracionesImportService
{
    public function __construct(private readonly BranchResolverService $branchResolver) {}

    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo de ministraciones no tiene ruta de almacenamiento.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de ministraciones no existe en storage/public.');
        }

        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '1200');
        @set_time_limit(1200);

        $absolutePath = Storage::disk('public')->path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);
        $rows = $sheets[0] ?? [];

        if (empty($rows)) {
            throw new \RuntimeException('El archivo de ministraciones está vacío o no se pudo leer.');
        }

        $headerIndex = $this->detectHeaderRowIndex($rows);
        $header = $rows[$headerIndex] ?? [];
        $map = $this->buildHeaderMap($header);

        if (!array_key_exists('amount', $map)) {
            throw new \RuntimeException(
                'El archivo de ministraciones no contiene una columna de monto reconocible. Encabezados detectados: '
                . implode(', ', array_values(array_filter(array_map(fn ($value) => trim((string) $value), $header))))
            );
        }

        Placement::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $stats = [
            'rows_read' => 0,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
            'rows_with_errors' => 0,
        ];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            if (!is_array($row) || $this->isEmptyRow($row)) {
                $stats['rows_skipped']++;
                continue;
            }

            $stats['rows_read']++;

            try {
                $amount = $this->toDecimal($this->valueFromRow($row, $map, 'amount'));

                if (($amount ?? 0) <= 0) {
                    $stats['rows_skipped']++;
                    continue;
                }

                $promoterName  = $this->clean($this->valueFromRow($row, $map, 'promoter_name'));
                $promoterCode  = $this->clean($this->valueFromRow($row, $map, 'promoter_code'));
                $productName   = $this->clean($this->valueFromRow($row, $map, 'product_name'));
                $numPayments   = (int) ($this->toDecimal($this->valueFromRow($row, $map, 'num_payments')) ?? 0) ?: null;
                $periodicity   = $this->clean($this->valueFromRow($row, $map, 'periodicity'));
                $branchName    = $this->clean($this->valueFromRow($row, $map, 'branch_name'));
                $clientName    = $this->clean($this->valueFromRow($row, $map, 'client_name'));
                $coordinatorName = $this->clean($this->valueFromRow($row, $map, 'coordinator_name'));

                // If promoter_name slot has a code-looking value, move it to code slot
                if ($promoterName && !$promoterCode && preg_match('/^[A-Z]{2,6}\d{4,}$/i', $promoterName)) {
                    $promoterCode = $promoterName;
                    $promoterName = null;
                }
                // If both slots have the same code value, clear the name slot
                if ($promoterName && $promoterCode && $promoterName === $promoterCode) {
                    $promoterName = null;
                }

                // Normalize product using BranchResolverService (with num_payments and periodicity for disambiguation)
                $normalizedProduct = $productName
                    ? $this->branchResolver->normalizeProduct($productName, $numPayments, $periodicity)
                    : null;

                // Branch resolution: try promoter_code prefix first, then branchName column
                $branch = $this->resolveBranchSmart($branchName, $promoterCode);

                Placement::query()->create([
                    'period_id'              => $upload->period_id,
                    'report_upload_id'       => $upload->id,
                    'branch_id'              => $branch?->id,
                    'client_name'            => $clientName,
                    'normalized_client_name' => $this->normalize($clientName),
                    'promoter_name'          => $promoterName,
                    'promoter_code'          => $promoterCode,
                    'normalized_promoter_name' => $this->normalize($promoterName ?? $promoterCode),
                    'coordinator_name'       => $coordinatorName,
                    'product_name'           => $normalizedProduct,
                    'amount'                 => $amount,
                    'operation_date'         => $this->toDate($this->valueFromRow($row, $map, 'operation_date')),
                    'raw_payload'            => null,
                ]);

                $stats['rows_inserted']++;
            } catch (\Throwable) {
                $stats['rows_with_errors']++;
            }

            if ($progress && $stats['rows_read'] % 250 === 0) {
                $progress($stats + ['log' => "Integrando ministraciones... {$stats['rows_read']} filas leídas."]);
            }
        }

        if ($stats['rows_inserted'] <= 0) {
            throw new \RuntimeException(
                'El archivo de ministraciones fue leído, pero no generó registros útiles. '
                . 'Revisa que exista una columna de monto con valores mayores a 0. '
                . 'Filas leídas: ' . $stats['rows_read'] . ', omitidas: ' . $stats['rows_skipped'] . '.'
            );
        }

        return $stats + [
            'log' => sprintf(
                'Importación de ministraciones finalizada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d.',
                $stats['rows_read'],
                $stats['rows_inserted'],
                $stats['rows_skipped'],
                $stats['rows_with_errors'],
            ),
        ];
    }

    private function detectHeaderRowIndex(array $rows): int
    {
        $bestIndex = 0;
        $bestScore = -1;

        foreach (array_slice($rows, 0, 80, true) as $index => $row) {
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
                    'cliente',
                    'nombre_del_cliente',
                    'nombre_cliente',
                    'acreditado',
                    'nombre_acreditado',
                    'oficina',
                    'zona',
                    'coordinador',
                    'nombre_coordinador',
                    'promotor',
                    'nombre_promotor',
                    'contrato',
                    'estatus',
                    'fecha_desembolso',
                    'monto_desembolsado',
                    'desembolsado',
                    'producto_de_credito',
                ], true)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }

            if ($score >= 6) {
                return (int) $index;
            }
        }

        return $bestIndex;
    }

    private function buildHeaderMap(array $header): array
    {
        $aliases = [
            'branch_name' => [
                'sucursal',
                'oficina',
                'ruta',
                'ruta_u_oficina',
                'sucursal_ruta',
                'branch',
                'branch_name',
                'nombre_sucursal',
            ],
            'promoter_name' => [
                'nombre_promotor',
                'nombre_cobrador',
                'nombre_gestor',
                'nombre_promotor_cobrador',
                'nombre_asesor',
                'nombre_empleado',
            ],
            'promoter_code' => [
                'promotor',
                'cobrador',
                'gestor',
                'asesor',
                'empleado',
                'clave_promotor',
                'codigo_promotor',
                'promotor_cobrador',
                'promotor_gestor',
            ],
            'product_name' => [
                'linea_de_credito',   // specific code: I30, S12, CRECE12, etc.
                'producto_de_credito',
                'producto',
                'tipo_credito',
                'tipo_de_credito',
                'producto_credito',
            ],
            'num_payments' => [
                'no_pagos',
                'numero_de_pagos',
                'num_pagos',
                'plazo',
                'pagos',
            ],
            'periodicity' => [
                'periodicidad',
                'frecuencia',
                'tipo_pago',
            ],
            'amount' => [
                'monto_desembolsado',
                'desembolsado',
                'monto_desembolso',
                'monto_de_desembolso',
                'importe_desembolsado',
                'importe_desembolso',
                'monto',
                'importe',
                'total',
                'capital',
                'desembolso',
                'monto_ministrado',
                'monto_de_ministracion',
                'importe_ministrado',
                'ministracion',
                'ministraciones',
                'monto_credito',
                'monto_del_credito',
                'capital_otorgado',
                'capital_por_producto',
                'otorgamiento',
                'otorgamientos',
                'colocacion',
                'colocaciones',
            ],
            'client_name' => [
                'cliente',
                'nombre_del_cliente',
                'nombre_cliente',
                'acreditado',
                'nombre_acreditado',
                'socio',
            ],
            'coordinator_name' => [
                'coordinador',
                'nombre_coordinador',
                'supervisor',
                'gerente',
            ],
            'operation_date' => [
                'fecha_desembolso',
                'fecha_de_desembolso',
                'fecha',
                'fecha_operacion',
                'fecha_de_operacion',
                'fecha_ministracion',
                'fecha_de_ministracion',
                'fecha_apertura',
                'fecha_creacion',
                'fecha_de_creacion',
            ],
        ];

        $normalizedHeaders = [];

        foreach ($header as $index => $value) {
            $normalizedHeaders[$index] = $this->normalizeHeader((string) $value);
        }

        // Alias-priority: try each alias in declared order so higher-priority aliases win.
        $map = [];
        foreach ($aliases as $field => $possibleHeaders) {
            foreach ($possibleHeaders as $alias) {
                $found = false;
                foreach ($normalizedHeaders as $index => $normalizedHeader) {
                    if ($normalizedHeader === $alias) {
                        $map[$field] = $index;
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    break;
                }
            }
        }

        return $map;
    }

    private function resolveBranchSmart(?string $branchName, ?string $promoterCode): ?Branch
    {
        // Try promoter_code prefix first (e.g., "ORI09247" → ORIZABA)
        if ($promoterCode) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($promoterCode);
            if ($branch) {
                return $branch;
            }
        }

        // Fall back to branchName column
        if (!$branchName) {
            return null;
        }

        $normalized = $this->normalize($branchName);

        return Branch::query()
            ->where('normalized_name', $normalized)
            ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($branchName)])
            ->first();
    }

    private function valueFromRow(array $row, array $map, string $field): mixed
    {
        if (!array_key_exists($field, $map)) {
            return null;
        }

        return $row[$map[$field]] ?? null;
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

        return trim($value, '_');
    }

    private function normalize(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $value = trim(mb_strtolower($value));
        $value = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );

        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $value = str_replace(['$', ',', ' '], '', (string) $value);
        $value = str_replace(['(', ')'], ['-', ''], $value);

        return is_numeric($value) ? round((float) $value, 2) : null;
    }

    private function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
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
