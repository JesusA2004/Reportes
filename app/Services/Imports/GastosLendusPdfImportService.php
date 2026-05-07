<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class GastosLendusPdfImportService
{
    // Known branch names as they appear in the PDF section headers
    private const KNOWN_BRANCHES = [
        'ATLIXCO', 'CUERNAVACA', 'CORDOBA', 'HUAMANTLA',
        'SAN JUAN DEL RIO', 'MIACATLAN', 'CHIHUAHUA', 'DURANGO',
        'ORIZABA', 'ATLACOMULCO', 'IXTLAHUACA', 'TLAXCALA',
        'TENANGO', 'TULA', 'SAN LUIS POTOSI', 'YAUTEPEC',
        'SAN LUIS SUBGERENCIA',
    ];

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
        @ini_set('max_execution_time', '600');
        @set_time_limit(600);

        $absolutePath = Storage::disk('public')->path($upload->stored_path);

        $parser = new Parser();
        $pdf    = $parser->parseFile($absolutePath);

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        $currentBranch    = null;
        $rowsRead         = 0;
        $rowsInserted     = 0;
        $rowsSkipped      = 0;
        $rowsErrors       = 0;
        $branchCache      = [];
        $employeeCache    = [];

        foreach ($pdf->getPages() as $page) {
            $text  = $page->getText();
            $lines = explode("\n", $text);

            foreach ($lines as $rawLine) {
                $line = trim($rawLine);
                if ($line === '') {
                    continue;
                }

                // Page headers — skip
                if (
                    str_starts_with($line, 'PRODUCTOS Y SERVICIOS') ||
                    str_starts_with($line, 'REPORTE DE GASTOS') ||
                    str_contains($line, 'Monto Gasto') ||
                    str_contains($line, 'M pagado empleado')
                ) {
                    continue;
                }

                // Total summary rows — skip
                if (str_starts_with($line, 'TOTAL:')) {
                    continue;
                }

                // Branch section header: no $, no tab, all-caps text matching known names
                if (!str_contains($line, '$') && !str_contains($line, "\t")) {
                    $candidate = mb_strtoupper(trim($line));
                    if ($this->isBranchHeader($candidate)) {
                        $currentBranch = $candidate;
                    }
                    continue;
                }

                // Data line: must contain tabs and at least one $ amount
                if (!str_contains($line, "\t") || !str_contains($line, '$')) {
                    continue;
                }

                $parsed = $this->parseDataLine($line);
                if (!$parsed) {
                    $rowsSkipped++;
                    continue;
                }

                $rowsRead++;

                // Skip cancelled rows
                if (strcasecmp($parsed['status'], 'Cancelado') === 0) {
                    $rowsSkipped++;
                    continue;
                }

                // Skip zero-amount rows
                if ($parsed['amount'] === null || $parsed['amount'] <= 0.0) {
                    $rowsSkipped++;
                    continue;
                }

                try {
                    $branchName = $currentBranch ?? '';
                    $branch     = $branchCache[$branchName] ?? ($branchCache[$branchName] = $this->resolveBranch($branchName));
                    $employee   = $employeeCache[$parsed['employee']] ?? ($employeeCache[$parsed['employee']] = $this->resolveEmployee($parsed['employee']));

                    // FONDEO A → mark as inter-branch loan category
                    $category = str_starts_with(mb_strtoupper(trim($parsed['concepto'])), 'FONDEO A')
                        ? 'FONDEO / PRESTAMO INTERSUCURSAL'
                        : ($parsed['category'] ?: 'GASTOS LENDUS');

                    Expense::query()->create([
                        'period_id'        => $upload->period_id,
                        'report_upload_id' => $upload->id,
                        'employee_id'      => $employee?->id,
                        'branch_id'        => $branch?->id,
                        'category'         => $category,
                        'concept'          => $parsed['concepto'] ?: 'Sin concepto',
                        'amount'           => $parsed['amount'],
                        'paid_amount'      => $parsed['amount'],
                        'expense_date'     => $parsed['date'],
                        'observations'     => $parsed['employee'] ?: null,
                        'raw_payload'      => null,
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
                        'log'              => "Gastos Lendus PDF: {$rowsRead} filas leídas…",
                    ]);
                }
            }
        }

        return [
            'rows_read'        => $rowsRead,
            'rows_inserted'    => $rowsInserted,
            'rows_skipped'     => $rowsSkipped,
            'rows_with_errors' => $rowsErrors,
            'log'              => sprintf(
                'Gastos Lendus PDF importados. Leídas: %d, insertadas: %d, omitidas: %d, errores: %d.',
                $rowsRead, $rowsInserted, $rowsSkipped, $rowsErrors
            ),
        ];
    }

    /**
     * Parse a data row from the PDF.
     *
     * PDF tab-separated segment structure:
     *   [0] {DD/MM/YYYY}{Employee name}
     *   [1] $ {MontoGasto}{Concepto} {Estatus}
     *   [2] $ {MPagadoEmpleado}{F.autorización}   (empty for Cancelado)
     *   [3] $ {MPagadoEmpresa}$ {MPagadoEmpleadoCaja}{Categoría}
     *
     * Amount[2] (3rd $ overall) = M pagado empresa = the value to use.
     */
    private function parseDataLine(string $line): ?array
    {
        $segments = explode("\t", $line);
        if (count($segments) < 3) {
            return null;
        }

        // ── Segment 0: date + employee ────────────────────────────────────
        $seg0 = trim($segments[0]);
        if (!preg_match('/^(\d{2}\/\d{2}\/\d{4})(.+)$/s', $seg0, $m0)) {
            return null;
        }
        $rawDate  = $m0[1]; // DD/MM/YYYY
        $employee = trim($m0[2]);

        // ── Segment 1: monto_gasto + concepto + estatus ───────────────────
        $seg1 = trim($segments[1] ?? '');
        preg_match('/^\$\s*[\d,\.]+(.+)$/', $seg1, $m1);
        $conceptStatus = trim($m1[1] ?? '');

        // Status is the last word (Autorizado | Cancelado)
        $status  = '';
        $concepto = $conceptStatus;
        if (preg_match('/(Autorizado|Cancelado)\s*$/i', $conceptStatus, $ms)) {
            $status   = ucfirst(strtolower($ms[1]));
            $concepto = trim(mb_substr($conceptStatus, 0, mb_strlen($conceptStatus) - mb_strlen($ms[0])));
        }

        // ── Extract all $ amounts from the whole line ─────────────────────
        preg_match_all('/\$\s*([\d,]+\.?\d*)/', $line, $amtMatches);
        $amounts = array_map(fn($v) => $this->toDecimal($v), $amtMatches[1]);

        // amounts[2] = M pagado empresa (the financial column we want)
        $amount = $amounts[2] ?? null;

        // ── Category from segment 3 (last segment) ────────────────────────
        $lastSeg  = trim(end($segments));
        $category = preg_replace('/^\$[\d,\.\s]+\$[\d,\.\s]+/', '', $lastSeg);
        $category = trim($category);

        // ── Convert date DD/MM/YYYY → Y-m-d ──────────────────────────────
        $date = null;
        if ($rawDate && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $rawDate, $dm)) {
            $date = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
        }

        return [
            'date'     => $date,
            'employee' => $employee,
            'concepto' => $concepto,
            'status'   => $status,
            'amount'   => $amount,
            'category' => $category ?: null,
        ];
    }

    private function isBranchHeader(string $line): bool
    {
        if (strlen($line) < 3) {
            return false;
        }

        // Direct match against known branches
        if (in_array($line, self::KNOWN_BRANCHES, true)) {
            return true;
        }

        // Or: all uppercase letters/spaces, no digits, no special chars
        return (bool) preg_match('/^[A-ZÁÉÍÓÚÜÑ\s]+$/u', $line);
    }

    private function resolveBranch(string $name): ?Branch
    {
        if (empty($name)) {
            return null;
        }

        $norm = mb_strtolower($name);
        $branch = Branch::query()
            ->whereRaw('LOWER(name) = ?', [$norm])
            ->orWhereRaw('LOWER(normalized_name) = ?', [$this->normalize($name)])
            ->first();

        return $branch ?? $this->branchResolver->findOrCreateBranchByCode($name);
    }

    private function resolveEmployee(string $name): ?Employee
    {
        if (empty($name)) {
            return null;
        }

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
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return round((float) $value, 2);
        }
        $clean = str_replace([',', ' '], '', (string) $value);
        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }
}
