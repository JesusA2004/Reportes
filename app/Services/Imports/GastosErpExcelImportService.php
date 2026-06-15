<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Support\ExpenseCategoryMapper;
use App\Support\ExpenseStatusNormalizer;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class GastosErpExcelImportService
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
        $sheet = $spreadsheet->getActiveSheet();

        $allRows = $sheet->toArray(null, true, true, false);

        // Find header row (row with "Folio" as first non-empty cell)
        $headerRowIndex = $this->detectHeaderRowIndex($allRows);
        if ($headerRowIndex === null) {
            throw new \RuntimeException('No se encontró la fila de encabezados en el archivo ERP (buscando "Folio").');
        }

        $headerRow  = $allRows[$headerRowIndex];
        $colMap     = $this->buildColumnMap($headerRow);

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        $rowsRead              = 0;
        $rowsInserted          = 0;
        $rowsSkipped           = 0;
        $rowsSkippedByStatus   = 0;
        $rowsErrors            = 0;
        $statusCounts          = [];

        foreach (array_slice($allRows, $headerRowIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $rowsSkipped++;
                continue;
            }

            $folio = $this->str($row[$colMap['folio']] ?? null);

            // Continuation row (no folio) — skip, the total is already on the primary row
            if (!$folio) {
                $rowsSkipped++;
                continue;
            }

            $rowsRead++;

            try {
                $rawStatus = $this->str($row[$colMap['estatus']] ?? null);
                $normStatus = $rawStatus ? ExpenseStatusNormalizer::normalize($rawStatus) : 'SIN ESTATUS';

                $statusCounts[$normStatus] = ($statusCounts[$normStatus] ?? 0) + 1;

                if ($rawStatus !== null && !ExpenseStatusNormalizer::isBillable($rawStatus)) {
                    $rowsSkippedByStatus++;
                    continue;
                }

                $sucursal    = $this->str($row[$colMap['sucursal']] ?? null);
                $solicitante = $this->str($row[$colMap['solicitante']] ?? null);
                $concepto    = $this->str($row[$colMap['concepto']] ?? null);
                $observ      = $this->str($row[$colMap['observaciones']] ?? null);
                $fechaStr    = $row[$colMap['fecha_captura']] ?? null;
                $total       = $this->toDecimal($row[$colMap['total_final']] ?? null);

                // Skip rows with zero or null total
                if ($total === null || $total == 0.0) {
                    $rowsSkipped++;
                    continue;
                }

                $branch   = $this->resolveBranch($sucursal);
                $employee = $this->resolveEmployee($solicitante);
                $date     = $this->toDate($fechaStr);

                $notes = collect([$observ, 'Folio: ' . $folio])
                    ->filter()
                    ->implode(' | ');

                Expense::query()->create([
                    'period_id'        => $upload->period_id,
                    'report_upload_id' => $upload->id,
                    'employee_id'      => $employee?->id,
                    'branch_id'        => $branch?->id,
                    'category'         => ExpenseCategoryMapper::fromFields($concepto, $observ),
                    'concept'          => $concepto ?? 'Sin concepto',
                    'amount'           => $total,
                    'paid_amount'      => $total,
                    'expense_date'     => $date,
                    'observations'     => $notes ?: null,
                    'raw_payload'      => ['estatus' => $rawStatus],
                ]);

                $rowsInserted++;
            } catch (\Throwable) {
                $rowsErrors++;
            }

            if ($progress && $rowsRead % 100 === 0) {
                $progress([
                    'rows_read'             => $rowsRead,
                    'rows_inserted'         => $rowsInserted,
                    'rows_skipped'          => $rowsSkipped,
                    'rows_skipped_by_status'=> $rowsSkippedByStatus,
                    'rows_with_errors'      => $rowsErrors,
                    'log'                   => "Gastos ERP: {$rowsRead} requisiciones leídas…",
                ]);
            }
        }

        return [
            'rows_read'              => $rowsRead,
            'rows_inserted'          => $rowsInserted,
            'rows_skipped'           => $rowsSkipped,
            'rows_skipped_by_status' => $rowsSkippedByStatus,
            'rows_with_errors'       => $rowsErrors,
            'status_counts'          => $statusCounts,
            'log'                    => sprintf(
                'Gastos ERP importados. Leídas: %d, contabilizadas: %d, ignoradas por estatus: %d, omitidas: %d, errores: %d.',
                $rowsRead, $rowsInserted, $rowsSkippedByStatus, $rowsSkipped, $rowsErrors
            ),
        ];
    }

    private function detectHeaderRowIndex(array $rows): ?int
    {
        foreach (array_slice($rows, 0, 20, true) as $index => $row) {
            foreach ($row as $cell) {
                if (strtolower(trim((string) $cell)) === 'folio') {
                    return (int) $index;
                }
            }
        }
        return null;
    }

    private function buildColumnMap(array $headerRow): array
    {
        $aliases = [
            'folio'          => ['folio'],
            'estatus'        => ['estatus', 'estado', 'status'],
            'sucursal'       => ['sucursal', 'oficina', 'unidad'],
            'codigo_sucursal'=> ['código sucursal', 'codigo sucursal', 'código sucursal'],
            'solicitante'    => ['solicitante', 'empleado', 'responsable'],
            'concepto'       => ['concepto'],
            'observaciones'  => ['observaciones', 'observación', 'observacion'],
            'fecha_captura'  => ['fecha captura', 'fecha de captura', 'fecha_captura'],
            'total_final'    => ['total final requisición', 'total final requisicion', 'total final', 'monto pagado empresa', 'monto pagado por empresa'],
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

        // Fallback: assign by known column position if header detection missed
        if (!isset($map['folio']))         $map['folio']          = 0;
        if (!isset($map['sucursal']))      $map['sucursal']       = 4;
        if (!isset($map['solicitante']))   $map['solicitante']    = 6;
        if (!isset($map['concepto']))      $map['concepto']       = 9;
        if (!isset($map['observaciones'])) $map['observaciones']  = 10;
        if (!isset($map['fecha_captura'])) $map['fecha_captura']  = 11;
        if (!isset($map['total_final']))   $map['total_final']    = 25; // Column Z (0-indexed)

        return $map;
    }

    private function resolveBranch(?string $name): ?Branch
    {
        if (!$name) return null;

        $norm = mb_strtolower(trim($name));

        // CORPORATIVO: create/find as a real branch (not null)
        if (in_array($norm, ['corporativo', 'corp', 'corporate'], true)) {
            return $this->branchResolver->findOrCreateBranchByName('CORPORATIVO');
        }

        $branch = Branch::query()
            ->whereRaw('LOWER(name) = ?', [$norm])
            ->orWhereRaw('LOWER(normalized_name) = ?', [$this->normalize($name)])
            ->first();

        // Resolve route → official branch before creating a new record.
        $officialName = $this->branchResolver->resolveRealBranchFromRoute($name);
        return $branch ?? ($officialName ? $this->branchResolver->findOrCreateBranchByName($officialName) : null);
    }

    private function resolveEmployee(?string $name): ?Employee
    {
        if (!$name) return null;

        return Employee::query()
            ->where('normalized_name', $this->normalize($name))
            ->first();
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        return preg_replace('/\s+/', ' ', $value) ?? $value;
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) return round((float) $value, 2);

        $clean = str_replace(['$', ',', ' '], '', (string) $value);
        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    private function toDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;

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
        if ($value === null) return null;
        $v = trim((string) $value);
        return $v === '' ? null : $v;
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
