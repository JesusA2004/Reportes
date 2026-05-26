<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Portfolio;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\ColumnMapService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class LendusSaldosClienteImportService
{
    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly ColumnMapService $columnMap,
    ) {}

    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo de saldos por cliente no tiene ruta de almacenamiento.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de saldos por cliente no existe en storage/public.');
        }

        @ini_set('memory_limit', '1024M');
        @ini_set('max_execution_time', '1200');
        @set_time_limit(1200);

        $absolutePath = Storage::disk('public')->path($upload->stored_path);
        $sheets = Excel::toArray([], $absolutePath);
        $rows = $sheets[0] ?? [];

        if (empty($rows)) {
            throw new \RuntimeException('El archivo de saldos está vacío o no se pudo leer.');
        }

        $headerIndex = $this->detectHeaderRowIndex($rows);
        $header = $rows[$headerIndex] ?? [];
        $map = $this->buildHeaderMap($header);

        if (!array_key_exists('balance', $map) && !array_key_exists('capital_due', $map) && !array_key_exists('capital_activo', $map)) {
            throw new \RuntimeException(
                'El archivo de saldos no contiene columnas reconocibles de saldo/cartera/mora. Encabezados detectados: '
                . implode(', ', array_values(array_filter(array_map(fn ($v) => trim((string) $v), $header))))
            );
        }

        Portfolio::query()
            ->where('report_upload_id', $upload->id)
            ->delete();

        $this->branchResolver->clearCache();

        $stats = [
            'rows_read'        => 0,
            'rows_inserted'    => 0,
            'rows_skipped'     => 0,
            'rows_with_errors' => 0,
        ];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            if (!is_array($row) || $this->isEmptyRow($row)) {
                $stats['rows_skipped']++;
                continue;
            }

            $stats['rows_read']++;

            try {
                $balance       = $this->toDecimal($this->value($row, $map, 'balance')) ?? 0;
                $capitalActivo = $this->toDecimal($this->value($row, $map, 'capital_activo'));
                $pastDue       = 0.0; // computed from mora components below

                // Contract/client code — used for real branch resolution
                $contract = $this->clean($this->value($row, $map, 'contract'));

                // Promoter fields
                $promoterCode = $this->clean($this->value($row, $map, 'promoter_code'));
                $promoterName = $this->clean($this->value($row, $map, 'promoter_name'));

                // If only one promoter column was found and it looks like a code, swap
                if ($promoterName && !$promoterCode && $this->looksLikeCode($promoterName)) {
                    $promoterCode = $promoterName;
                    $promoterName = null;
                }

                // Route name (office/ruta — NOT the real branch)
                $routeName = $this->clean($this->value($row, $map, 'route_name'));

                // Product data
                $rawProduct  = $this->clean($this->value($row, $map, 'product_name'));
                $numPayments = (int) ($this->toDecimal($this->value($row, $map, 'num_payments')) ?? 0) ?: null;
                $periodicity = $this->clean($this->value($row, $map, 'periodicity'));

                $productName = $rawProduct
                    ? $this->branchResolver->normalizeProduct($rawProduct, $numPayments, $periodicity)
                    : null;

                // Exclude insurance and restructures from main records
                if ($productName && $this->branchResolver->isInsuranceProduct($productName)) {
                    $stats['rows_skipped']++;
                    continue;
                }

                // Mora components (authorized columns B)
                $capitalDue           = $this->toDecimal($this->value($row, $map, 'capital_due'));
                $interesAtrasado      = $this->toDecimal($this->value($row, $map, 'interes_atrasado')) ?? 0.0;
                $impuestoAtrasado     = $this->toDecimal($this->value($row, $map, 'impuesto_atrasado')) ?? 0.0;
                $saldoInteresMor      = $this->toDecimal($this->value($row, $map, 'saldo_interes_moratorio')) ?? 0.0;
                $saldoImpuestoMor     = $this->toDecimal($this->value($row, $map, 'saldo_impuesto_interes_moratorio')) ?? 0.0;
                $cargosCalAtrasado    = $this->toDecimal($this->value($row, $map, 'cargos_calendario_atrasado')) ?? 0.0;
                $impuestoCargosCalAtr = $this->toDecimal($this->value($row, $map, 'impuesto_cargos_calendario_atrasado')) ?? 0.0;

                // Total mora from all authorized components
                $moraFromComponents = ($capitalDue ?? 0.0) + $interesAtrasado + $impuestoAtrasado
                    + $saldoInteresMor + $saldoImpuestoMor + $cargosCalAtrasado + $impuestoCargosCalAtr;
                $pastDue = $moraFromComponents > 0 ? round($moraFromComponents, 2) : 0.0;

                if ($balance <= 0 && $pastDue <= 0 && ($capitalActivo === null || $capitalActivo <= 0)) {
                    $stats['rows_skipped']++;
                    continue;
                }

                // Days past due — use Días Vencido column
                $daysPastDue = (int) ($this->toDecimal($this->value($row, $map, 'days_past_due')) ?? 0);

                // If capital_due is null but days_past_due > 0, derive from past_due_balance
                if ($capitalDue === null && $daysPastDue > 0 && $pastDue > 0) {
                    $capitalDue = $pastDue;
                }

                // Branch resolution: prefer contract code prefix → then promoter code → then route column
                $branch = $this->resolveBranchFromSources($contract, $promoterCode, $routeName);

                $clientName = $this->clean($this->value($row, $map, 'client_name'));

                Portfolio::query()->create([
                    'period_id'                          => $upload->period_id,
                    'report_upload_id'                   => $upload->id,
                    'branch_id'                          => $branch?->id,
                    'client_name'                        => $clientName,
                    'normalized_client_name'             => $this->normalize($clientName),
                    'contract'                           => $contract,
                    'promoter_name'                      => $promoterName,
                    'promoter_code'                      => $promoterCode,
                    'product_name'                       => $productName,
                    'num_payments'                       => $numPayments,
                    'periodicity'                        => $periodicity,
                    'route_name'                         => $routeName,
                    // D) Valor cartera
                    'balance'                            => $balance,
                    // C) Préstamo activo
                    'capital_activo'                     => $capitalActivo,
                    // B) Mora total + components
                    'past_due_balance'                   => $pastDue,
                    'capital_due'                        => $capitalDue,
                    'interes_atrasado'                   => $interesAtrasado ?: null,
                    'impuesto_atrasado'                  => $impuestoAtrasado ?: null,
                    'saldo_interes_moratorio'            => $saldoInteresMor ?: null,
                    'saldo_impuesto_interes_moratorio'   => $saldoImpuestoMor ?: null,
                    'cargos_calendario_atrasado'         => $cargosCalAtrasado ?: null,
                    'impuesto_cargos_calendario_atrasado' => $impuestoCargosCalAtr ?: null,
                    'days_past_due'                      => $daysPastDue,
                    'portfolio_date'                     => $this->toDate($this->value($row, $map, 'portfolio_date')),
                    'raw_payload'                        => null,
                ]);

                $stats['rows_inserted']++;
            } catch (\Throwable) {
                $stats['rows_with_errors']++;
            }

            if ($progress && $stats['rows_read'] % 250 === 0) {
                $progress($stats + ['log' => "Integrando saldos por cliente... {$stats['rows_read']} filas leídas."]);
            }
        }

        if ($stats['rows_inserted'] <= 0) {
            throw new \RuntimeException(
                'El archivo de saldos fue leído, pero no generó registros útiles. '
                . 'Revisa que exista una columna de saldo/cartera con valores mayores a 0. '
                . 'Filas leídas: ' . $stats['rows_read'] . ', omitidas: ' . $stats['rows_skipped'] . '.'
            );
        }

        return $stats + [
            'log' => sprintf(
                'Importación de saldos por cliente finalizada. Leídas: %d, insertadas: %d, omitidas: %d, con error: %d.',
                $stats['rows_read'],
                $stats['rows_inserted'],
                $stats['rows_skipped'],
                $stats['rows_with_errors'],
            ),
        ];
    }

    private function resolveBranchFromSources(
        ?string $contract,
        ?string $promoterCode,
        ?string $routeName,
    ): ?Branch {
        // 1. Try contract code prefix (e.g., "ORI09247" → ORIZABA)
        if ($contract) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($contract);
            if ($branch) {
                return $branch;
            }
        }

        // 2. Try promoter code prefix (e.g., "ORI09247" → ORIZABA)
        if ($promoterCode) {
            $branch = $this->branchResolver->findOrCreateBranchByCode($promoterCode);
            if ($branch) {
                return $branch;
            }
        }

        // 3. Fall back to route/office column: resolve to REAL branch first, then DB lookup
        if ($routeName) {
            $realBranchName = $this->branchResolver->resolveRealBranchFromRoute($routeName);
            if ($realBranchName) {
                return $this->branchResolver->findOrCreateBranchByName($realBranchName);
            }
            // Last resort: direct name lookup (for cases where routeName IS the real branch name)
            $normalized = $this->normalize($routeName);
            return Branch::query()
                ->where('normalized_name', $normalized)
                ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($routeName)])
                ->first();
        }

        return null;
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
                fn ($v) => $this->normalizeHeader((string) $v),
                $row,
            );

            $score = 0;
            foreach ($normalized as $v) {
                if (in_array($v, [
                    'cliente',
                    'nombre_del_cliente',
                    'oficina',
                    'zona',
                    'promotor',
                    'nombre_promotor',
                    'nombre_promotor2',
                    'nombre_gestor_de_cobranza',
                    'gestor_de_cobranza',
                    'saldo_actual',
                    'saldo_capital',
                    'capital',
                    'total_a_pagar',
                    'total_pagado',
                    'total_atrasado',
                    'estatus',
                    'substatus',
                    'contrato',
                    'no_contrato',
                    'num_contrato',
                    'dias_vencido',
                    'dias_vencidos',
                    'dias_mora',
                    'capital_atrasado',
                    'interes_atrasado',
                    'impuesto_atrasado',
                    'saldo_interes_moratorio',
                    'saldo_impuesto_interes_moratorio',
                    'cargos_calendario_atrasado',
                    'impuesto_cargos_calendario_atrasado',
                    'producto_de_credito',
                    'numero_de_pagos',
                    'pagos',
                    'periodicidad',
                ], true)) {
                    $score++;
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }

            if ($score >= 5) {
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

        // Use ColumnMapService as the single source of truth for authorized columns.
        $defs    = $this->columnMap->getFieldDefinitions('lendus_saldos_cliente');
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

    private function value(array $row, array $map, string $field): mixed
    {
        if (!array_key_exists($field, $map)) {
            return null;
        }

        return $row[$map[$field]] ?? null;
    }

    private function looksLikeCode(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2,4}\d{4,}/i', trim($value));
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
