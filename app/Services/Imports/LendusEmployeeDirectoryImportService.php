<?php

namespace App\Services\Imports;

use App\Models\LendusEmployeeDirectory;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class LendusEmployeeDirectoryImportService
{
    // Roles operativos que se consideran para resolución de sucursal
    private const OPERATIONAL_KEYWORDS = [
        'coordinador regional', 'coordinador',
        'gerente regional', 'gerente', 'subgerente',
        'gestor de credito', 'gestor', 'promotor',
    ];

    // Roles administrativos que se ignoran para resolución
    private const NON_OPERATIONAL_KEYWORDS = [
        'analista', 'mesa de control', 'sistemas', 'administrativo',
        'contabilidad', 'recursos humanos', 'rh', 'director',
    ];

    // Expected columns in the Employees sheet
    private const COLUMN_ALIASES = [
        'codigo'       => ['codigo', 'code', 'clave'],
        'nombre'       => ['nombre', 'name', 'empleado'],
        'telefono'     => ['telefono', 'teléfono', 'tel'],
        'puesto'       => ['puesto', 'cargo', 'position'],
        'area'         => ['area', 'área', 'department'],
        'promotor'     => ['promotor', 'promoter'],
        'distribuidor' => ['distribuidor', 'distributor'],
        'estatus'      => ['estatus', 'status', 'estado'],
        'fecha_inicio' => ['fecha inicio', 'fecha_inicio', 'start date', 'fecha de ingreso'],
    ];

    public function __construct(
        private readonly BranchResolverService $branchResolver,
    ) {}

    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (!$upload->stored_path) {
            throw new \RuntimeException('El archivo de empleados Lendus no tiene stored_path.');
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            throw new \RuntimeException('El archivo físico de empleados Lendus no existe en storage/public.');
        }

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');

        $absolutePath = Storage::disk('public')->path($upload->stored_path);

        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        $sheetsInfo = $reader->listWorksheetInfo($absolutePath);
        if (empty($sheetsInfo)) {
            throw new \RuntimeException('No se encontraron hojas en el archivo de empleados Lendus.');
        }

        // Find the "Employees" sheet — case-insensitive
        $targetSheetName = null;
        foreach ($sheetsInfo as $info) {
            if (strtolower(trim($info['worksheetName'])) === 'employees') {
                $targetSheetName = $info['worksheetName'];
                break;
            }
        }

        // Fallback to first sheet if "Employees" not found
        if (!$targetSheetName) {
            $targetSheetName = $sheetsInfo[0]['worksheetName'];
        }

        $reader->setLoadSheetsOnly([$targetSheetName]);
        $spreadsheet = $reader->load($absolutePath);
        $worksheet   = $spreadsheet->getActiveSheet();

        $rows = $worksheet->toArray(null, true, true, false);
        $rows = array_values(array_filter($rows, fn ($r) => is_array($r) && $this->hasContent($r)));

        if (empty($rows)) {
            throw new \RuntimeException('La hoja Employees está vacía o no se pudo leer.');
        }

        // The file may have title rows above the real column headers.
        // Require BOTH 'nombre' and 'codigo' to be present so that title rows
        // like "REPORTE DE EMPLEADOS" (which partially matches aliases) are skipped.
        $headerIndex = 0;
        foreach ($rows as $i => $row) {
            $map = $this->buildColumnMap($row);
            if (isset($map['nombre']) && isset($map['codigo'])) {
                $headerIndex = $i;
                break;
            }
        }

        $headerRow = $rows[$headerIndex];
        $map       = $this->buildColumnMap($headerRow);

        // Remove previous rows for this upload
        LendusEmployeeDirectory::query()->where('report_upload_id', $upload->id)->delete();

        $stats = ['rows_read' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0, 'rows_errors' => 0];

        $branchCache = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            if ($this->isEmptyRow($row)) {
                $stats['rows_skipped']++;
                continue;
            }

            $stats['rows_read']++;

            try {
                $nombre = $this->clean($this->col($row, $map, 'nombre'));
                if (!$nombre) {
                    $stats['rows_skipped']++;
                    continue;
                }

                $codigo      = $this->clean($this->col($row, $map, 'codigo')) ?? '';
                $puesto      = $this->clean($this->col($row, $map, 'puesto')) ?? '';
                $area        = $this->clean($this->col($row, $map, 'area')) ?? '';
                $promotor    = $this->clean($this->col($row, $map, 'promotor')) ?? '';
                $distribuidor = $this->clean($this->col($row, $map, 'distribuidor')) ?? '';
                $estatus     = $this->clean($this->col($row, $map, 'estatus')) ?? '';
                $fechaRaw    = $this->col($row, $map, 'fecha_inicio');

                $fechaInicio = $this->parseDate($fechaRaw);
                $isOperational = $this->isOperational($puesto);

                // Infer branch from CODIGO prefix
                $inferredBranchId   = null;
                $inferredBranchName = null;
                if ($codigo) {
                    if (isset($branchCache[$codigo])) {
                        [$inferredBranchId, $inferredBranchName] = $branchCache[$codigo];
                    } else {
                        $branchName = $this->branchResolver->resolveBranchNameFromCode($codigo);
                        if ($branchName) {
                            $branchId = DB::table('branches')
                                ->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($branchName))])
                                ->value('id');
                            $inferredBranchId   = $branchId ? (int) $branchId : null;
                            $inferredBranchName = $branchName;
                        }
                        $branchCache[$codigo] = [$inferredBranchId, $inferredBranchName];
                    }
                }

                LendusEmployeeDirectory::create([
                    'report_upload_id'   => $upload->id,
                    'codigo'             => $codigo ?: null,
                    'nombre'             => $nombre,
                    'normalized_name'    => $this->normalizeName($nombre),
                    'puesto'             => $puesto ?: null,
                    'area'               => $area ?: null,
                    'promotor'           => $promotor ?: null,
                    'distribuidor'       => $distribuidor ?: null,
                    'estatus'            => $estatus ?: null,
                    'fecha_inicio'       => $fechaInicio,
                    'inferred_branch_id'   => $inferredBranchId,
                    'inferred_branch_name' => $inferredBranchName,
                    'is_operational'     => $isOperational,
                    'raw_payload'        => $row,
                ]);

                $stats['rows_inserted']++;

                if ($progress) {
                    $progress($stats['rows_read'], null);
                }
            } catch (\Throwable $e) {
                $stats['rows_errors']++;
            }
        }

        return $stats;
    }

    private function isOperational(string $puesto): bool
    {
        $lower = mb_strtolower(trim($puesto));
        foreach (self::NON_OPERATIONAL_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) return false;
        }
        foreach (self::OPERATIONAL_KEYWORDS as $kw) {
            if (str_contains($lower, $kw)) return true;
        }
        return false;
    }

    private function normalizeName(string $value): string
    {
        return Str::of($value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }

    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $i => $cell) {
            $clean = mb_strtolower(trim((string) $cell));
            foreach (self::COLUMN_ALIASES as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if ($clean === $alias || str_contains($clean, $alias)) {
                        if (!isset($map[$field])) {
                            $map[$field] = $i;
                        }
                    }
                }
            }
        }
        return $map;
    }

    private function col(array $row, array $map, string $field): mixed
    {
        if (!isset($map[$field])) return null;
        return $row[$map[$field]] ?? null;
    }

    private function clean(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        $str = trim((string) $value);
        return $str === '' ? null : $str;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') return null;
        if (is_numeric($value)) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
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

    private function hasContent(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== null && $cell !== '') return true;
        }
        return false;
    }

    private function isEmptyRow(array $row): bool
    {
        return !$this->hasContent($row);
    }
}
