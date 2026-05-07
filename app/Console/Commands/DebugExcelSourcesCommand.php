<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

class DebugExcelSourcesCommand extends Command
{
    protected $signature = 'reportes:debug-excel-sources
        {--period= : ID del periodo a analizar}
        {--upload= : ID específico de un ReportUpload}
        {--source= : Tipo de fuente: saldos|cobranza|ministraciones|gastos|noi}
        {--rows=10 : Filas de datos a mostrar por fuente}';

    protected $description = 'Diagnóstico completo de archivos Excel subidos: hojas, encabezados, columnas detectadas y primeras filas útiles.';

    public function __construct(private readonly BranchResolverService $branchResolver)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $periodId = $this->option('period');
        $uploadId = $this->option('upload');
        $source   = $this->option('source');
        $maxRows  = (int) ($this->option('rows') ?? 10);

        if ($uploadId) {
            $upload = ReportUpload::query()->with('dataSource')->find((int) $uploadId);
            if (!$upload) {
                $this->error("ReportUpload #{$uploadId} no encontrado.");
                return self::FAILURE;
            }
            $this->analyzeUpload($upload, $maxRows);
            return self::SUCCESS;
        }

        if ($periodId) {
            $period = Period::query()->find((int) $periodId);
            if (!$period) {
                $this->error("Periodo #{$periodId} no encontrado.");
                return self::FAILURE;
            }

            $uploads = ReportUpload::query()
                ->with('dataSource')
                ->where('period_id', $period->id)
                ->get();

            if ($uploads->isEmpty()) {
                $this->warn("No hay archivos subidos para el periodo #{$periodId}.");
                return self::SUCCESS;
            }

            $this->info("══ Diagnóstico archivos periodo #{$periodId} — {$period->label} ══");
            $this->line('');

            foreach ($uploads as $upload) {
                if ($source && !str_contains(strtolower($upload->dataSource?->name ?? ''), $source)) {
                    continue;
                }
                $this->analyzeUpload($upload, $maxRows);
                $this->line('');
            }

            return self::SUCCESS;
        }

        $this->error('Debes especificar --period=ID o --upload=ID');
        return self::FAILURE;
    }

    private function readFirstRows(string $absolutePath, int $maxRows): array
    {
        // Use read filter to avoid loading full file into memory
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $reader->setReadFilter(new class($maxRows) implements IReadFilter {
            public function __construct(private readonly int $maxRows) {}
            public function readCell($columnAddress, $row, $worksheetName = ''): bool
            {
                return $row <= $this->maxRows;
            }
        });

        $spreadsheet = $reader->load($absolutePath);
        $result = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, false, false);
            $result[] = $rows;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
        gc_collect_cycles();

        return $result;
    }

    private function analyzeUpload(ReportUpload $upload, int $maxRows): void
    {
        $sourceName = $upload->dataSource?->name ?? 'Desconocida';
        $this->info("┌─ Upload #{$upload->id} | Fuente: {$sourceName} | Periodo: {$upload->period_id}");
        $this->line("│  Path: {$upload->stored_path}");

        if (!$upload->stored_path || !Storage::disk('public')->exists($upload->stored_path)) {
            $this->error('│  ✗ Archivo no encontrado en storage/public.');
            $this->line('└─────────────────────────────────────────────────────────');
            return;
        }

        $absolutePath = Storage::disk('public')->path($upload->stored_path);
        $sizeKb = round(filesize($absolutePath) / 1024, 1);
        $this->line("│  Tamaño: {$sizeKb} KB");

        // Read only first 100 rows to avoid OOM on large files
        @ini_set('memory_limit', '512M');

        try {
            $sheets = $this->readFirstRows($absolutePath, 100);
        } catch (\Throwable $e) {
            $this->error("│  ✗ No se pudo leer el archivo: " . $e->getMessage());
            $this->line('└─────────────────────────────────────────────────────────');
            return;
        }

        $this->line("│  Hojas encontradas: " . count($sheets));

        foreach ($sheets as $sheetIdx => $rows) {
            $this->line("│");
            $this->line("│  ── Hoja #{$sheetIdx} ──────────────────────────────────");
            $this->line("│  Filas totales: " . count($rows));

            if (empty($rows)) {
                $this->warn("│  (vacía)");
                continue;
            }

            // Detect header row
            $headerIdx = $this->detectHeaderRow($rows);
            $header    = $rows[$headerIdx] ?? [];
            $nonEmpty  = array_filter(array_map(fn ($v) => trim((string) $v), $header), fn ($v) => $v !== '');

            $this->line("│  Fila de encabezado: #{$headerIdx}");
            $this->line("│  Encabezados detectados (" . count($nonEmpty) . "):");
            foreach (array_chunk($nonEmpty, 6) as $chunk) {
                $this->line("│    " . implode(' | ', $chunk));
            }

            // Show column mapping for known sources
            $sourceNameLower = strtolower($sourceName);
            if (str_contains($sourceNameLower, 'saldo') || str_contains($sourceNameLower, 'cliente')) {
                $this->analyzeSaldosColumns($header);
            } elseif (str_contains($sourceNameLower, 'cobranza') || str_contains($sourceNameLower, 'ingreso')) {
                $this->analyzeCobranzaColumns($header);
            } elseif (str_contains($sourceNameLower, 'ministracion')) {
                $this->analyzeMinistracionesColumns($header);
            } elseif (str_contains($sourceNameLower, 'gasto')) {
                $this->analyzeGastosColumns($header);
            } elseif (str_contains($sourceNameLower, 'noi') || str_contains($sourceNameLower, 'nomina')) {
                $this->analyzeNominaColumns($header);
            }

            // Show first N useful data rows
            $this->line("│");
            $this->line("│  Primeras {$maxRows} filas de datos (desde fila #" . ($headerIdx + 1) . "):");
            $shown = 0;
            foreach (array_slice($rows, $headerIdx + 1) as $row) {
                if (!is_array($row) || $this->isEmptyRow($row)) {
                    continue;
                }
                $values = array_map(fn ($v) => mb_strimwidth(trim((string) $v), 0, 25, '…'), $row);
                $this->line("│    [" . ($headerIdx + 1 + $shown + 1) . "] " . implode(' | ', array_filter($values, fn ($v) => $v !== '')));
                $shown++;
                if ($shown >= $maxRows) {
                    break;
                }
            }

            if ($shown === 0) {
                $this->warn("│  (sin filas de datos útiles)");
            }
        }

        $this->line('└─────────────────────────────────────────────────────────');
    }

    private function analyzeSaldosColumns(array $header): void
    {
        $this->line("│");
        $this->line("│  → DETECCIÓN ESPECIAL: Lendus Saldos por Cliente");

        // Aliases mirror LendusSaldosClienteImportService::buildHeaderMap exactly
        $aliases = [
            'contract'        => ['cliente', 'contrato', 'no_contrato', 'num_contrato', 'numero_contrato', 'clave_cliente', 'codigo_cliente'],
            'client_name'     => ['nombre_del_cliente', 'nombre_cliente', 'acreditado', 'nombre_acreditado', 'socio', 'nombre_completo'],
            'route_name'      => ['oficina', 'ruta', 'ruta_u_oficina', 'zona', 'sucursal'],
            'promoter_code'   => ['promotor', 'gestor_de_cobranza', 'cobrador', 'clave_promotor', 'codigo_promotor'],
            'promoter_name'   => ['nombre_promotor2', 'nombre_promotor', 'nombre_cobrador', 'nombre_gestor', 'nombre_gestor_de_cobranza'],
            'balance'         => ['saldo_actual', 'saldo_capital', 'saldo', 'saldo_insoluto', 'capital', 'total_a_pagar'],
            'past_due_balance'=> ['capital_atrasado', 'saldo_capital_atrasado', 'total_atrasado', 'saldo_vencido', 'cartera_vencida'],
            'capital_due'     => ['capital_atrasado', 'capital_mora', 'importe_atrasado'],
            'days_past_due'   => ['dias_vencido', 'dias_vencidos', 'dias_mora', 'dias_atraso', 'dias_de_mora', 'dias_de_atraso'],
            'product_name'    => ['producto_de_credito', 'producto', 'tipo_credito', 'tipo_de_credito'],
            'num_payments'    => ['pagos', 'numero_de_pagos', 'num_pagos', 'no_pagos', 'plazo'],
            'periodicity'     => ['periodicidad', 'frecuencia', 'tipo_pago'],
        ];

        $this->showColumnMapping($header, $aliases);

        // Show branch resolver catalog
        $this->line("│");
        $this->line("│  → RESOLUCIÓN DE SUCURSAL (prefijo del contrato):");
        $this->line("│    Catálogo: " . implode(', ', array_map(
            fn ($k, $v) => "{$k}→{$v}",
            array_keys($this->branchResolver->getCatalog()),
            $this->branchResolver->getCatalog()
        )));

        // Try to show some resolved branches from actual data (skip for now, needs actual file read)
    }

    private function analyzeCobranzaColumns(array $header): void
    {
        $this->line("│");
        $this->line("│  → DETECCIÓN ESPECIAL: Lendus Ingresos Cobranza");

        $aliases = [
            'branch_name'   => ['oficina', 'ruta', 'sucursal', 'oficina_ruta'],
            'promoter_name' => ['promotor', 'asesor', 'ejecutivo', 'colaborador'],
            'client_name'   => ['nombre_del_cliente', 'cliente', 'acreditado'],
            'product_name'  => ['producto_de_credito', 'producto'],
            'total_amount'  => ['importe', 'monto', 'total', 'ingreso', 'recuperacion'],
            'payment_date'  => ['fecha_transaccion', 'fecha_cuota', 'fecha_pago'],
            'concept'       => ['concepto', 'operacion', 'movimiento'],
        ];

        $this->showColumnMapping($header, $aliases);
    }

    private function analyzeMinistracionesColumns(array $header): void
    {
        $this->line("│");
        $this->line("│  → DETECCIÓN ESPECIAL: Lendus Ministraciones");

        // Aliases mirror LendusMinistracionesImportService::buildHeaderMap
        $aliases = [
            'branch_name'   => ['sucursal', 'oficina', 'ruta', 'ruta_u_oficina'],
            'promoter_code' => ['promotor', 'cobrador', 'gestor', 'asesor', 'clave_promotor', 'codigo_promotor'],
            'promoter_name' => ['nombre_promotor', 'nombre_cobrador', 'nombre_gestor', 'nombre_asesor'],
            'product_name'  => ['linea_de_credito', 'producto_de_credito', 'producto', 'tipo_credito'],
            'num_payments'  => ['no_pagos', 'numero_de_pagos', 'num_pagos', 'plazo'],
            'periodicity'   => ['periodicidad', 'frecuencia'],
            'amount'        => ['monto_desembolsado', 'desembolsado', 'monto', 'capital_otorgado'],
            'client_name'   => ['cliente', 'nombre_del_cliente', 'acreditado'],
            'operation_date'=> ['fecha_desembolso', 'fecha', 'fecha_operacion'],
        ];

        $this->showColumnMapping($header, $aliases);
    }

    private function analyzeGastosColumns(array $header): void
    {
        $this->line("│");
        $this->line("│  → DETECCIÓN ESPECIAL: Gastos");

        $aliases = [
            'employee_name' => ['empleado', 'nombre_empleado', 'responsable'],
            'branch_name'   => ['sucursal', 'oficina', 'unidad'],
            'category'      => ['categoria', 'tipo_gasto', 'rubro'],
            'concept'       => ['concepto', 'descripcion', 'gasto'],
            'amount'        => ['importe', 'monto', 'total', 'monto_gasto'],
            'expense_date'  => ['fecha_gasto', 'fecha', 'fecha_creacion'],
        ];

        $this->showColumnMapping($header, $aliases);
    }

    private function analyzeNominaColumns(array $header): void
    {
        $this->line("│");
        $this->line("│  → DETECCIÓN ESPECIAL: NOI Nómina");
        $this->line("│  Nota: employee_name y employee_key se leen dinámicamente de col A/B, no de encabezados");

        // Aliases mirror NoiNominaImportService::buildHeaderMap
        $aliases = [
            'employee_name' => ['nombre_del_trabajador', 'empleado', 'nombre', 'trabajador', 'nombre_empleado'],
            'concept'       => ['concepto', 'descripcion', 'movimiento'],
            'concept_type'  => ['tipo_movimiento', 'tipo_concepto', 'tipo', 'naturaleza'],
            'amount'        => ['acumulado', 'importe', 'monto', 'neto', 'total', 'valor'],
            'period_label'  => ['periodo', 'mes', 'quincena', 'fecha'],
        ];

        $this->showColumnMapping($header, $aliases);
    }

    private function showColumnMapping(array $header, array $aliases): void
    {
        $normalizedHeaders = [];
        foreach ($header as $idx => $val) {
            $normalizedHeaders[$idx] = $this->normalizeHeader((string) $val);
        }

        $found    = [];
        $notFound = [];

        // Alias-priority: try each alias in declared order so higher-priority aliases
        // (e.g. 'dias_vencido' before 'dias_mora') win regardless of column position.
        foreach ($aliases as $field => $possible) {
            $matchIdx = null;
            $matchRaw = null;
            foreach ($possible as $alias) {
                foreach ($normalizedHeaders as $idx => $norm) {
                    if ($norm === $alias) {
                        $matchIdx = $idx;
                        $matchRaw = trim((string) ($header[$idx] ?? ''));
                        break 2;
                    }
                }
            }
            if ($matchIdx !== null) {
                $colLetter = $this->colLetter($matchIdx + 1);
                $found[] = "  {$field}: col {$colLetter} ('{$matchRaw}')";
            } else {
                $notFound[] = $field;
            }
        }

        foreach ($found as $line) {
            $this->line("│  ✓{$line}");
        }
        if (!empty($notFound)) {
            $this->warn("│  ✗ No detectadas: " . implode(', ', $notFound));
        }
    }

    private function detectHeaderRow(array $rows): int
    {
        $bestIdx   = 0;
        $bestScore = -1;

        $keywords = [
            'cliente', 'nombre_del_cliente', 'oficina', 'zona', 'promotor',
            'nombre_promotor', 'saldo_actual', 'saldo_capital', 'estatus',
            'contrato', 'dias_vencido', 'capital_atrasado', 'producto_de_credito',
            'empleado', 'concepto', 'importe', 'monto', 'fecha',
        ];

        foreach (array_slice($rows, 0, 80, true) as $idx => $row) {
            if (!is_array($row)) {
                continue;
            }
            $score = 0;
            foreach ($row as $val) {
                $norm = $this->normalizeHeader((string) $val);
                if (in_array($norm, $keywords, true)) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIdx   = (int) $idx;
            }
            if ($score >= 5) {
                return (int) $idx;
            }
        }

        return $bestIdx;
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

    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $val) {
            if ($val !== null && trim((string) $val) !== '') {
                return false;
            }
        }
        return true;
    }

    private function colLetter(int $colNumber): string
    {
        $letter = '';
        while ($colNumber > 0) {
            $mod    = ($colNumber - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $colNumber = (int) (($colNumber - $mod - 1) / 26);
        }
        return $letter;
    }
}
