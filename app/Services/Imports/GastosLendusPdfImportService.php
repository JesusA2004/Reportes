<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Support\ExpenseCategoryMapper;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class GastosLendusPdfImportService
{
    /**
     * Branch names as they appear in the PDF section headers.
     * Used as direct matches; the regex fallback catches anything else
     * that is all-uppercase letters/spaces.
     */
    private const KNOWN_BRANCHES = [
        'ATLIXCO', 'CUERNAVACA', 'CORDOBA', 'HUAMANTLA',
        'SAN JUAN DEL RIO', 'SAN JUAN DEL RÍO', 'MIACATLAN', 'CHIHUAHUA',
        'DURANGO', 'ORIZABA', 'ATLACOMULCO', 'IXTLAHUACA', 'TLAXCALA',
        'TENANGO', 'TENANGO DEL VALLE', 'TULA', 'SAN LUIS POTOSI',
        'YAUTEPEC', 'SAN LUIS SUBGERENCIA', 'CORPORATIVO',
    ];

    /**
     * Known expense categories — used as anchors in the row regex.
     * If the PDF adds new categories they MUST be added here.
     */
    private const CATEGORIES_PATTERN = 'PAGOS Y SERVICIOS|PROTOCOLARES|EMERGENTES|DEDUCCIONES';

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

        $parser = new Parser();
        $pdf    = $parser->parseFile($absolutePath);

        Expense::query()->where('report_upload_id', $upload->id)->delete();

        $currentBranch = null;
        $rowsRead      = 0;
        $rowsInserted  = 0;
        $rowsSkipped   = 0;
        $rowsErrors    = 0;
        $branchCache   = [];
        $employeeCache = [];

        foreach ($pdf->getPages() as $page) {
            $text  = $page->getText();
            $lines = explode("\n", $text);

            foreach ($lines as $rawLine) {
                $line = trim($rawLine);
                if ($line === '') {
                    continue;
                }

                // Skip page/column headers and footer lines
                if ($this->isSkipLine($line)) {
                    continue;
                }

                // TOTAL: lines are reference-only — skip
                if (str_starts_with($line, 'TOTAL:')) {
                    continue;
                }

                // Lines without $ → look for branch section header
                if (!str_contains($line, '$')) {
                    $candidate = mb_strtoupper(trim($line));
                    if ($this->isBranchHeader($candidate)) {
                        $currentBranch = $candidate;
                    }
                    continue;
                }

                // Lines with $ → attempt to parse as expense data row
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
                if (!$parsed['amount'] || $parsed['amount'] <= 0.0) {
                    $rowsSkipped++;
                    continue;
                }

                try {
                    $branchName = $currentBranch ?? '';
                    $branch     = array_key_exists($branchName, $branchCache)
                        ? $branchCache[$branchName]
                        : ($branchCache[$branchName] = $this->resolveBranch($branchName));

                    $employee = array_key_exists($parsed['employee'], $employeeCache)
                        ? $employeeCache[$parsed['employee']]
                        : ($employeeCache[$parsed['employee']] = $this->resolveEmployee($parsed['employee']));

                    $category = $this->resolveCategory($parsed['category'], $parsed['concept']);

                    $payload = [
                        'concept_raw'   => $parsed['concept'],
                        'category_raw'  => $parsed['category'],
                        'branch_origen' => $currentBranch,
                    ];
                    if ($category === 'Préstamos Intersucursales' || $category === 'Envío de utilidad a corporativo') {
                        $fondeoInfo = $this->parseFondeoDestino($parsed['concept']);
                        $payload['fondeo_destino_texto'] = $fondeoInfo['texto_raw'];
                        $payload['fondeo_destino_sucursal'] = $fondeoInfo['sucursal'];
                        $payload['fondeo_tipo'] = $fondeoInfo['tipo'];
                    }

                    Expense::query()->create([
                        'period_id'        => $upload->period_id,
                        'report_upload_id' => $upload->id,
                        'employee_id'      => $employee?->id,
                        'branch_id'        => $branch?->id,
                        'category'         => $category,
                        'concept'          => $parsed['concept'] ?: 'Sin concepto',
                        // amount = Monto Gasto (requested)
                        // paid_amount = M pagado empresa = Monto Gasto for Autorizado rows
                        // (M pagado empleado is always $0.00 in this PDF)
                        'amount'           => $parsed['amount'],
                        'paid_amount'      => $parsed['amount'],
                        'expense_date'     => $parsed['date'],
                        'observations'     => $parsed['employee'] ?: null,
                        'raw_payload'      => $payload,
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
     * Parse a data row from the PDF text.
     *
     * smalot/pdfparser extracts each visual table row with TAB separators.
     * The actual structure is:
     *
     *   {DD/MM/YYYY}{EMPLOYEE}\t${MONTO_GASTO}{CONCEPT} {STATUS}\t${M_PAG_EMP}{DATETIME}\t${MONTO_CAJA}${M_PAG_EMP2}{CATEGORY}
     *
     * Date comes first (no separator from employee name), then TAB.
     * We use STATUS (Autorizado/Cancelado) and CATEGORY (at end) as anchors.
     * For Autorizado rows, Monto Gasto = M pagado empresa (company-paid amount).
     */
    private function parseDataLine(string $line): ?array
    {
        $cats    = self::CATEGORIES_PATTERN;
        // {DD/MM/YYYY}{EMPLOYEE}\t${AMOUNT}{CONCEPT} {STATUS}\t...{CATEGORY}
        $pattern = '/^(\d{2}\/\d{2}\/\d{4})(.+?)\t\$\s*([\d,]+\.?\d*)\s*(.+?)\s+(Autorizado|Cancelado)\t.*?(' . $cats . ')\s*$/u';

        if (!preg_match($pattern, $line, $m)) {
            return null;
        }

        $rawDate  = $m[1];
        $employee = trim($m[2]);
        $amount   = $this->toDecimal($m[3]);
        $concept  = trim($m[4]);
        $status   = $m[5];
        $category = trim($m[6]);

        $date = null;
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $rawDate, $dm)) {
            $date = $dm[3] . '-' . $dm[2] . '-' . $dm[1];
        }

        return compact('employee', 'category', 'concept', 'status', 'date', 'amount');
    }

    private function isSkipLine(string $line): bool
    {
        if (str_starts_with($line, 'PRODUCTOS Y SERVICIOS')) {
            return true;
        }
        if (str_starts_with($line, 'REPORTE DE GASTOS')) {
            return true;
        }
        if (str_contains($line, 'Monto Gasto') || str_contains($line, 'M pagado')) {
            return true;
        }
        if (str_contains($line, 'Empleado') && str_contains($line, 'Categoría')) {
            return true;
        }
        // Footer: "jueves 07 mayo 2026Lendus 27Pagina 1 de" or "Lendus jueves... Página X de Y"
        if (str_contains($line, 'Lendus') && (str_contains($line, 'Pagina') || str_contains($line, 'Página'))) {
            return true;
        }
        return false;
    }

    private function isBranchHeader(string $line): bool
    {
        if (strlen($line) < 3) {
            return false;
        }

        // Exact match in known branch list (covers all multi-word branch names
        // like "TENANGO DEL VALLE", "SAN JUAN DEL RIO", "SAN LUIS POTOSI").
        if (in_array($line, self::KNOWN_BRANCHES, true)) {
            return true;
        }

        // Multi-word lines not in KNOWN_BRANCHES are almost certainly employee names
        // (e.g. "ANA KAREN BERNABE GAMBOA") — do NOT override currentBranch with them.
        // Only accept single-word all-caps lines as candidate unknown branches.
        if (str_contains($line, ' ')) {
            return false;
        }

        return (bool) preg_match('/^[A-ZÁÉÍÓÚÜÑ]+$/u', $line);
    }

    private function resolveCategory(string $category, string $concept): string
    {
        $cu = mb_strtoupper(trim($concept));

        // Excedente / envío a corporativo must be detected BEFORE fondeo to avoid
        // misclassifying "DEPOSITO A CORPORATIVO" as a fondeo entre sucursales.
        if (
            str_contains($cu, 'EXCEDENTE') ||
            str_contains($cu, 'DEPOSITO A CORPORATIVO') ||
            str_contains($cu, 'DEPÓSITO A CORPORATIVO') ||
            str_contains($cu, 'DEPOSITO CORPORATIVO') ||
            str_contains($cu, 'ENVIO CORPORATIVO') ||
            str_contains($cu, 'ENVÍO CORPORATIVO')
        ) {
            return 'Envío de utilidad a corporativo';
        }

        if (str_starts_with($cu, 'FONDEO A') || str_contains($cu, 'FONDEO') || str_contains($cu, 'PRESTAMO INTERSUCURSAL')) {
            return 'Préstamos Intersucursales';
        }

        // Use mapper on concept + PDF category to produce a normalized category
        return ExpenseCategoryMapper::fromFields($concept, $category);
    }

    /**
     * Parse fondeo destination from concept text.
     * Returns the raw text used, the resolved sucursal name, and type.
     */
    private function parseFondeoDestino(string $concept): array
    {
        $cu = mb_strtoupper(trim($concept));
        // Normalize: remove double spaces, accents
        $cu = strtr($cu, ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);
        $cu = preg_replace('/\s+/', ' ', $cu) ?? $cu;

        // Check corporativo first
        if (
            str_contains($cu, 'CORPORATIVO') || str_contains($cu, 'EXCEDENTE')
        ) {
            return ['texto_raw' => $concept, 'sucursal' => 'CORPORATIVO', 'tipo' => 'excedente_corporativo'];
        }

        // Strip noise words to extract branch name
        $noisePatterns = [
            '/^FONDEO\s+(A\s+)?SUC\.?\s+/i',
            '/^FONDEO\s+(A\s+)?SUCURSAL\s+/i',
            '/^FONDEO\s+A\s+/i',
            '/^FONDEO\s+/i',
            '/^A\s+SUC\.?\s+/i',
            '/^A\s+SUCURSAL\s+/i',
            '/^PRESTAMO\s+INTERSUCURSAL\s+/i',
            '/^PRESTAMO\s+A\s+/i',
            '/\s*FONDEO\s*/i',
            '/\s*DEPOSITO\s*/i',
            '/\s*DEPÓSITO\s*/i',
        ];

        $cleaned = $cu;
        foreach ($noisePatterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $cleaned) ?? $cleaned;
        }
        $cleaned = trim($cleaned);

        $sucursal = $this->matchBranchFromText($cleaned);

        return [
            'texto_raw' => $concept,
            'sucursal'  => $sucursal ?? 'No detectado',
            'tipo'      => $sucursal ? 'fondeo_sucursal' : 'no_clasificado',
        ];
    }

    private function matchBranchFromText(string $text): ?string
    {
        // Key = nombre normalizado sin acentos; Value = nombre oficial con acentos
        static $branchMap = [
            'ATLACOMULCO'       => 'ATLACOMULCO',
            'ATLIXCO'           => 'ATLIXCO',
            'CORDOBA'           => 'CÓRDOBA',
            'CUERNAVACA'        => 'CUERNAVACA',
            'HUAMANTLA'         => 'HUAMANTLA',
            'IXTLAHUACA'        => 'IXTLAHUACA',
            'MIACATLAN'         => 'MIACATLÁN',
            'ORIZABA'           => 'ORIZABA',
            'SAN JUAN DEL RIO'  => 'SAN JUAN DEL RÍO',
            'SAN JUAN'          => 'SAN JUAN DEL RÍO',
            'SAN LUIS POTOSI'   => 'SAN LUIS POTOSÍ',
            'TENANGO DEL VALLE' => 'TENANGO DEL VALLE',
            'TENANGO'           => 'TENANGO DEL VALLE',
            'TLAXCALA'          => 'TLAXCALA',
            'TULA'              => 'TULA',
            'CHIHUAHUA'         => 'CHIHUAHUA',
            'DURANGO'           => 'DURANGO',
            'YAUTEPEC'          => 'YAUTEPEC',
        ];

        $textNorm = strtr(mb_strtoupper(trim($text)), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N']);

        foreach ($branchMap as $key => $official) {
            if (str_contains($textNorm, $key)) {
                return $official;
            }
        }

        return null;
    }

    private function resolveBranch(string $name): ?Branch
    {
        if (empty($name)) {
            return null;
        }

        $nameNorm = $this->normalize($name);

        $branch = Branch::query()
            ->whereRaw('LOWER(normalized_name) = ?', [$nameNorm])
            ->first();

        if ($branch) {
            return $branch;
        }

        // Intentar resolver vía route → nombre oficial
        $officialName = $this->branchResolver->resolveRealBranchFromRoute($name);
        if ($officialName) {
            return $this->branchResolver->findOrCreateBranchByName($officialName);
        }

        // Último recurso: el encabezado de sección del PDF ES el nombre oficial.
        // Solo aplica a nombres en KNOWN_BRANCHES (evitar crear sucursales falsas).
        $nameUp = mb_strtoupper(trim($name));
        if (in_array($nameUp, self::KNOWN_BRANCHES, true)) {
            return $this->branchResolver->findOrCreateBranchByName($name);
        }

        \Illuminate\Support\Facades\Log::warning('GastosLendusPdfImportService: sucursal no reconocida.', ['raw' => $name]);
        return null;
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
        $value = strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
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
