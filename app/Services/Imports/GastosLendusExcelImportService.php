<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Support\ExpenseCategoryMapper;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class GastosLendusExcelImportService
{
    public function __construct(private readonly BranchResolverService $branchResolver) {}

    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo no tiene stored_path.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico no existe en storage/public.');
        }

        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '0');
        @set_time_limit(0);

        $absolutePath = Storage::disk('public')->path($upload->stored_path);

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);
        $sheet       = $spreadsheet->getActiveSheet();
        $allRows     = $sheet->toArray(null, true, true, false);

        $headerRowIndex = $this->detectHeaderRowIndex($allRows);
        if ($headerRowIndex === null) {
            throw new \RuntimeException('No se encontró la fila de encabezados en el Excel Gastos Lendus.');
        }

        $colMap = $this->buildColumnMap($allRows[$headerRowIndex]);

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        $rowsRead     = 0;
        $rowsInserted = 0;
        $rowsSkipped  = 0;
        $rowsErrors   = 0;
        $branchCache  = [];

        foreach (array_slice($allRows, $headerRowIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $rowsSkipped++;
                continue;
            }

            $amount = $this->toDecimal($this->col($row, $colMap['monto']));
            if ($amount === null || $amount <= 0.0) {
                $rowsSkipped++;
                continue;
            }

            $rowsRead++;

            try {
                $sucursal     = $this->str($this->col($row, $colMap['sucursal']));
                $categoria    = $this->str($this->col($row, $colMap['categoria']));
                $concepto     = $this->str($this->col($row, $colMap['concepto']));
                $observacion  = $this->str($this->col($row, $colMap['observacion']));
                $justificacion= $this->str($this->col($row, $colMap['justificacion']));
                $folio        = $this->str($this->col($row, $colMap['folio']));
                $proveedor    = $this->str($this->col($row, $colMap['proveedor']));
                $solicitante  = $this->str($this->col($row, $colMap['solicitante']));
                $fechaStr     = $this->col($row, $colMap['fecha']);
                $date         = $this->toDate($fechaStr);

                // Resolve from_branch (sucursal column) — must be a real branch
                $fromBranchName = $sucursal ? $this->resolveRealBranchName($sucursal) : null;
                $fromBranchKey  = $fromBranchName ?? ($sucursal ?? '');
                $branch = array_key_exists($fromBranchKey, $branchCache)
                    ? $branchCache[$fromBranchKey]
                    : ($branchCache[$fromBranchKey] = $fromBranchName
                        ? $this->findOrCreateBranch($fromBranchName)
                        : null);

                // Detect intersucursal destination from combined text fields
                $searchText  = implode(' ', array_filter([$categoria, $concepto, $observacion, $justificacion]));
                $toBranchDetected = $this->detectToBranch($searchText);

                $isIntersucursal = $toBranchDetected !== null
                    || $this->looksLikeIntersucursal($searchText);

                $category = $isIntersucursal
                    ? 'Préstamos Intersucursales'
                    : ExpenseCategoryMapper::fromFields($concepto, $categoria, $observacion);

                $observationParts = array_filter([$observacion, $justificacion]);
                $observations = $observationParts ? implode(' | ', $observationParts) : null;

                $rawPayload = array_filter([
                    'branch_to_detected' => $toBranchDetected,
                    'folio'              => $folio,
                    'proveedor'          => $proveedor,
                    'solicitante'        => $solicitante,
                ]);

                Expense::query()->create([
                    'period_id'        => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'employee_id'      => null,
                    'branch_id'        => $branch?->id,
                    'category'         => $category,
                    'concept'          => $concepto ?? 'Sin concepto',
                    'amount'           => $amount,
                    'paid_amount'      => $amount,
                    'expense_date'     => $date,
                    'observations'     => $observations,
                    'raw_payload'      => $rawPayload ?: null,
                ]);

                $rowsInserted++;
            } catch (\Throwable) {
                $rowsErrors++;
            }

            if ($progress && $rowsRead % 100 === 0) {
                $progress([
                    'rows_read'        => $rowsRead,
                    'rows_inserted'    => $rowsInserted,
                    'rows_skipped'     => $rowsSkipped,
                    'rows_with_errors' => $rowsErrors,
                    'log'              => "Gastos Lendus Excel: {$rowsRead} filas leídas…",
                ]);
            }
        }

        return [
            'rows_read'        => $rowsRead,
            'rows_inserted'    => $rowsInserted,
            'rows_skipped'     => $rowsSkipped,
            'rows_with_errors' => $rowsErrors,
            'log'              => sprintf(
                'Gastos Lendus Excel importados. Leídas: %d, insertadas: %d, omitidas: %d, errores: %d.',
                $rowsRead, $rowsInserted, $rowsSkipped, $rowsErrors
            ),
        ];
    }

    private function detectHeaderRowIndex(array $rows): ?int
    {
        $anchors = ['fecha', 'monto', 'folio', 'total', 'sucursal', 'concepto', 'empleado', 'estatus', 'observac', 'justific'];
        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            $hits = 0;
            foreach ($row as $cell) {
                $norm = strtr(mb_strtolower(trim((string) $cell)), ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
                foreach ($anchors as $anchor) {
                    if (str_contains($norm, $anchor)) {
                        $hits++;
                        break;
                    }
                }
            }
            if ($hits >= 2) {
                return (int) $index;
            }
        }
        return null;
    }

    private function buildColumnMap(array $headerRow): array
    {
        // Actual Lendus file column layout:
        // Col 0: Empleado | Col 1: Categoría | Col 2: Concepto | Col 3: Estatus
        // Col 4: Fecha Creación | Col 5: Monto Gasto | Col 6: Monto pagado empleado
        // Col 7: Monto pagado empresa | Col 11: Observación | Col 12: Justificación
        $aliases = [
            'solicitante'   => ['solicitante', 'empleado', 'responsable', 'usuario'],
            'categoria'     => ['categoria', 'categoría', 'tipo'],
            'concepto'      => ['concepto', 'descripcion', 'descripción', 'motivo'],
            'estatus'       => ['estatus', 'estado', 'status'],
            'fecha'         => ['fecha creacion', 'fecha creación', 'fecha_creacion', 'fecha de creacion', 'fecha de creación', 'fecha gasto', 'fecha de gasto', 'fecha'],
            'monto'         => ['monto aplicado en caja', 'monto pagado empresa', 'monto pagado por empresa', 'monto gasto', 'monto total', 'monto', 'total', 'importe'],
            'sucursal'      => ['sucursal', 'oficina', 'unidad', 'sucursal origen'],
            'observacion'   => ['observacion', 'observación', 'observaciones', 'obs'],
            'justificacion' => ['justificacion', 'justificación', 'justificaciones', 'justif'],
            'folio'         => ['folio', 'id', 'num', 'número', 'numero'],
            'proveedor'     => ['proveedor', 'empresa', 'beneficiario'],
        ];

        $map = [];
        foreach ($aliases as $field => $possibleNames) {
            foreach ($headerRow as $idx => $cell) {
                $norm = mb_strtolower(trim((string) $cell));
                if (in_array($norm, $possibleNames, true)) {
                    $map[$field] = $idx;
                    break;
                }
            }
        }

        if (!isset($map['solicitante']))   $map['solicitante']   = 0;
        if (!isset($map['categoria']))     $map['categoria']     = 1;
        if (!isset($map['concepto']))      $map['concepto']      = 2;
        if (!isset($map['fecha']))         $map['fecha']         = 4;
        if (!isset($map['monto']))         $map['monto']         = 7;
        if (!isset($map['observacion']))   $map['observacion']   = 11;
        if (!isset($map['justificacion'])) $map['justificacion'] = 12;
        if (!isset($map['sucursal']))      $map['sucursal']      = null;
        if (!isset($map['folio']))         $map['folio']         = null;
        if (!isset($map['proveedor']))     $map['proveedor']     = null;

        return $map;
    }

    /**
     * Try to extract a destination branch name from free text.
     * Returns the resolved real branch name or null.
     */
    private function detectToBranch(string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        $upper = mb_strtoupper(trim($text));

        // Patterns: "FONDEA A XXXX", "FONDEO A XXXX", "FONDEO PARA XXXX",
        //           "PRESTAMO A XXXX", "PRÉSTAMO A XXXX", "INTERSUCURSAL A XXXX",
        //           "CREDITO A XXXX", "FONDEO XXXX" (direct branch name following)
        $patterns = [
            '/FOND(?:EA|EO)\s+(?:A|PARA|DE)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/FOND(?:EA|EO)\s+(?:A|PARA|DE)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/FOND(?:EA|EO)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/FOND(?:EA|EO)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/PR[EÉ]STAMO\s+(?:A|PARA|INTER)?\s*SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/PR[EÉ]STAMO\s+(?:A|PARA|INTER)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/INTERSUCURSAL\s+(?:A|PARA)?\s*SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/INTERSUCURSAL\s+(?:A|PARA)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/CR[EÉ]DITO\s+(?:A|PARA)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/(?:A|PARA)\s+SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/^SUC(?:URSAL)?\.?\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/DEP[OÓ]SITO\s+(?:A|PARA)?\s*([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
            '/APOYO\s+(?:A|PARA)\s+([A-ZÁÉÍÓÚÜÑ][A-ZÁÉÍÓÚÜÑ\s]{2,30}?)(?:\s*[-,.]|$)/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $upper, $m)) {
                $candidate = trim($m[1]);
                if (strlen($candidate) < 3) {
                    continue;
                }
                $resolved = $this->branchResolver->resolveRealBranchFromRoute($candidate);
                if ($resolved) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function looksLikeIntersucursal(string $text): bool
    {
        if (empty($text)) {
            return false;
        }

        $upper = mb_strtoupper($text);

        $keywords = ['FONDEO', 'FONDEA', 'INTERSUCURSAL', 'INTER-SUCURSAL',
                     'PRESTAMO SUCURSAL', 'PRÉSTAMO SUCURSAL'];

        foreach ($keywords as $kw) {
            if (str_contains($upper, $kw)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRealBranchName(string $name): ?string
    {
        return $this->branchResolver->resolveRealBranchFromRoute($name);
    }

    private function findOrCreateBranch(string $name): ?Branch
    {
        return $this->branchResolver->findOrCreateBranchByName($name);
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        $clean = str_replace(['$', ',', ' '], '', (string) $value);
        return is_numeric($clean) ? round((float) $clean, 2) : null;
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
            return \Carbon\Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function str(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $v = trim((string) $value);
        return $v === '' ? null : $v;
    }

    private function col(array $row, ?int $idx): mixed
    {
        return $idx !== null ? ($row[$idx] ?? null) : null;
    }

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string) $cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
