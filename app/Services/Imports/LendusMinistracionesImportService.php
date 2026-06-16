<?php

namespace App\Services\Imports;

use App\Models\Placement;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\ColumnMapService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class LendusMinistracionesImportService
{
    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly ColumnMapService $columnMap,
    ) {}

    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo de ministraciones no tiene ruta de almacenamiento.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de ministraciones no existe en storage/public.');
        }

        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

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
                $amountMonto  = $this->toDecimal($this->valueFromRow($row, $map, 'amount'));
                $amountDesemb = $this->toDecimal($this->valueFromRow($row, $map, 'amount_disbursed'));
                $creditOrigin = $this->clean($this->valueFromRow($row, $map, 'credit_origin'));

                // Otorgamientos = Monto desembolsado SIEMPRE (col 53).
                // $ Desembolsado (col 54) se guarda solo para auditoría, nunca para calcular colocación.
                $amount = $amountMonto;

                if (($amount ?? 0) <= 0) {
                    $stats['rows_skipped']++;
                    continue;
                }

                $cuotaTotal  = $this->toDecimal($this->valueFromRow($row, $map, 'cuota_total'));
                $interes     = $this->toDecimal($this->valueFromRow($row, $map, 'interes_total'));
                $impuesto    = $this->toDecimal($this->valueFromRow($row, $map, 'impuesto_total'));
                $adeudoTotal = $this->toDecimal($this->valueFromRow($row, $map, 'adeudo_total'));
                $seguro      = $this->toDecimal($this->valueFromRow($row, $map, 'seguro'));
                $apertura    = $this->toDecimal($this->valueFromRow($row, $map, 'apertura'));
                $descRefi    = $this->toDecimal($this->valueFromRow($row, $map, 'descuento_refinanciamiento'));
                $descSubprod = $this->toDecimal($this->valueFromRow($row, $map, 'descuento_subproductos'));

                $promoterName = $this->clean($this->valueFromRow($row, $map, 'promoter_name'));
                $promoterCode = $this->clean($this->valueFromRow($row, $map, 'promoter_code'));
                $productName  = $this->clean($this->valueFromRow($row, $map, 'product_name'));
                $clientName   = $this->clean($this->valueFromRow($row, $map, 'client_name'));

                // If promoter_name slot has a code-looking value, move it to code slot
                if ($promoterName && !$promoterCode && preg_match('/^[A-Z]{2,6}\d{4,}$/i', $promoterName)) {
                    $promoterCode = $promoterName;
                    $promoterName = null;
                }
                if ($promoterName && $promoterCode && $promoterName === $promoterCode) {
                    $promoterName = null;
                }

                // Read plazo/periodicidad for accurate product normalization
                // (needed for: CREDITO DIARIO → i40, SEMANAL sin código → s12, etc.)
                $numPaymentsRaw = $this->valueFromRow($row, $map, 'num_payments')
                                ?? $this->valueFromRow($row, $map, 'term');
                $numPayments    = is_numeric($numPaymentsRaw) ? (int)round((float)$numPaymentsRaw) : null;
                $periodicity    = $this->clean($this->valueFromRow($row, $map, 'periodicity'));

                $normalizedProduct = $productName
                    ? $this->branchResolver->normalizeProduct($productName, $numPayments, $periodicity)
                    : null;

                // Branch resolution: only promoter_code prefix (authorized columns only)
                $branch = $promoterCode
                    ? $this->branchResolver->findOrCreateBranchByCode($promoterCode)
                    : null;

                Placement::query()->create([
                    'period_id'               => $upload->period_id,
                    'report_upload_id'        => $upload->id,
                    'branch_id'               => $branch?->id,
                    'client_name'             => $clientName,
                    'normalized_client_name'  => $this->normalize($clientName),
                    'promoter_name'           => $promoterName,
                    'promoter_code'           => $promoterCode,
                    'normalized_promoter_name' => $this->normalize($promoterName ?? $promoterCode),
                    'product_name'            => $normalizedProduct,
                    'amount'                  => $amount,
                    'operation_date'          => $this->toDate($this->valueFromRow($row, $map, 'operation_date')),
                    'raw_payload'             => json_encode([
                        'credit_origin'              => $creditOrigin,
                        'monto_desembolsado'         => $amountMonto,
                        'efectivo_desembolsado'      => $amountDesemb,
                        'cuota_total'                => $cuotaTotal,
                        'interes_total'              => $interes,
                        'impuesto_total'             => $impuesto,
                        'adeudo_total'               => $adeudoTotal,
                        'seguro'                     => $seguro,
                        'apertura'                   => $apertura,
                        'descuento_refinanciamiento' => $descRefi,
                        'descuento_subproductos'     => $descSubprod,
                        // backward-compat
                        'amount_monto53'             => $amountMonto,
                        'amount_desemb54'            => $amountDesemb,
                        'amount_used'                => $amount,
                    ]),
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
        $normalizedHeaders = [];
        foreach ($header as $index => $value) {
            $normalized = $this->columnMap->normalizeHeader((string) $value);
            if ($normalized !== '') {
                $normalizedHeaders[$index] = $normalized;
            }
        }

        $defs    = $this->columnMap->getFieldDefinitions('lendus_ministraciones');
        $map     = [];
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
