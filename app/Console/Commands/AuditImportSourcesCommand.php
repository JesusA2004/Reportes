<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\ColumnMapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Audits every report_upload for a given period:
 * shows file, data_source, records inserted, product breakdown, columns mapped.
 */
class AuditImportSourcesCommand extends Command
{
    protected $signature   = 'reportes:audit-import-sources {period_id} {--show-columns : Muestra columnas detectadas por archivo}';
    protected $description = 'Auditoría completa de archivos importados para un periodo';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA DE FUENTES — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("════════════════════════════════════════════════════════════");

        $uploads = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('ru.period_id', $period->id)
            ->select('ru.id', 'ru.original_name', 'ru.status', 'ru.uploaded_at', 'ru.file_size', 'ru.stored_path', 'ds.code', 'ds.name')
            ->orderBy('ds.code')
            ->get();

        if ($uploads->isEmpty()) {
            $this->warn("No hay archivos registrados para el periodo {$period->id}.");
            return 0;
        }

        $showColumns = $this->option('show-columns');
        $cm = app(ColumnMapService::class);

        foreach ($uploads as $upload) {
            $this->line('');
            $this->info("┌─ SOURCE: {$upload->code}");
            $this->line("│  Archivo:     {$upload->original_name}");
            $this->line("│  Upload ID:   {$upload->id}");
            $this->line("│  Estado:      {$upload->status}");
            $this->line("│  Subido:      " . ($upload->uploaded_at ?? 'N/D'));
            $this->line("│  Tamaño:      " . number_format($upload->file_size / 1024, 1) . " KB");

            if ($showColumns && in_array($upload->code, ['lendus_ministraciones','lendus_ingresos_cobranza','lendus_saldos_cliente'])) {
                $this->auditColumns($upload, $cm);
            }

            match ($upload->code) {
                'lendus_ministraciones'    => $this->auditPlacements($upload->id, $dataIds),
                'lendus_ingresos_cobranza' => $this->auditRecoveries($upload->id, $dataIds),
                'lendus_saldos_cliente'    => $this->auditPortfolio($upload->id, $dataIds),
                'noi_nomina',
                'noi_nomina_fiscal'        => $this->auditNoi($upload->id, $dataIds),
                'gastos_lendus',
                'gastos_lendus_excel',
                'gastos_erp'               => $this->auditGastos($upload->id, $dataIds),
                'lendus_empleados'         => $this->auditEmpleados($upload->id, $dataIds),
                default                    => $this->line("│  (sin auditoría específica para este tipo)")
            };

            $this->line("└" . str_repeat("─", 60));
        }

        $this->line('');
        $this->info("TOTALES GLOBALES DEL PERIODO:");
        $this->line("  fact_placements:     " . DB::table('fact_placements')->whereIn('period_id', $dataIds)->count() . " filas");
        $this->line("  fact_recoveries:     " . DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->count() . " filas");
        $this->line("  fact_portfolios:     " . DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->count() . " filas");
        $this->line("  fact_noi_movements:  " . DB::table('fact_noi_movements')->whereIn('period_id', $dataIds)->count() . " filas");
        $this->line("  fact_expenses:       " . DB::table('fact_expenses')->whereIn('period_id', $dataIds)->count() . " filas");

        return 0;
    }

    private function auditPlacements(int $uploadId, array $dataIds): void
    {
        $total   = DB::table('fact_placements')->where('report_upload_id', $uploadId)->count();
        $amount  = DB::table('fact_placements')->where('report_upload_id', $uploadId)->sum('amount');

        $this->line("│  → fact_placements: {$total} registros insertados, monto=$" . number_format((float)$amount, 2));

        $byProduct = DB::table('fact_placements')
            ->where('report_upload_id', $uploadId)
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount) as total'))
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->get();

        $this->line("│  Productos detectados:");
        foreach ($byProduct as $p) {
            $label = $p->product_name ?? 'NULL/Sin producto';
            $this->line("│    {$label}: {$p->cnt} ops, $" . number_format((float)$p->total, 2));
        }

        $noProduct = DB::table('fact_placements')
            ->where('report_upload_id', $uploadId)
            ->whereNull('product_name')
            ->count();
        if ($noProduct > 0) {
            $this->warn("│  ⚠  {$noProduct} registros sin product_name");
        }

        $noBranch = DB::table('fact_placements')
            ->where('report_upload_id', $uploadId)
            ->whereNull('branch_id')
            ->count();
        if ($noBranch > 0) {
            $this->warn("│  ⚠  {$noBranch} registros sin branch_id (sucursal no resuelta)");
        }
    }

    private function auditRecoveries(int $uploadId, array $dataIds): void
    {
        $total  = DB::table('fact_recoveries')->where('report_upload_id', $uploadId)->count();
        $cap    = DB::table('fact_recoveries')->where('report_upload_id', $uploadId)->sum('capital');
        $int    = DB::table('fact_recoveries')->where('report_upload_id', $uploadId)->sum('interest');
        $tax    = DB::table('fact_recoveries')->where('report_upload_id', $uploadId)->sum('tax');
        $char   = DB::table('fact_recoveries')->where('report_upload_id', $uploadId)->sum('charges');
        $tot    = DB::table('fact_recoveries')->where('report_upload_id', $uploadId)->sum('total_amount');
        $sh     = DB::table('fact_recoveries')->where('report_upload_id', $uploadId)->where('is_savehearts', true)->count();
        $shAmt  = DB::table('fact_recoveries')->where('report_upload_id', $uploadId)->sum('savehearts_crece_share');

        $this->line("│  → fact_recoveries: {$total} registros");
        $this->line("│    Capital: $" . number_format((float)$cap, 2) .
                    "  Interés: $" . number_format((float)$int, 2) .
                    "  Impuesto: $" . number_format((float)$tax, 2));
        $this->line("│    Cargos: $" . number_format((float)$char, 2) .
                    "  Total: $" . number_format((float)$tot, 2));
        $this->line("│    Savehearts: {$sh} filas, crece_30_share=$" . number_format((float)$shAmt, 2));

        $byProduct = DB::table('fact_recoveries')
            ->where('report_upload_id', $uploadId)
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        $this->line("│  Top productos en recuperación:");
        foreach ($byProduct as $p) {
            $label = $p->product_name ?? 'NULL/Sin producto';
            $this->line("│    {$label}: {$p->cnt} filas, $" . number_format((float)$p->total, 2));
        }

        $noProduct = DB::table('fact_recoveries')
            ->where('report_upload_id', $uploadId)
            ->whereNull('product_name')
            ->count();
        if ($noProduct > 0) {
            $this->warn("│  ⚠  {$noProduct} registros sin product_name");
        }
    }

    private function auditPortfolio(int $uploadId, array $dataIds): void
    {
        $total   = DB::table('fact_portfolios')->where('report_upload_id', $uploadId)->count();
        $balance = DB::table('fact_portfolios')->where('report_upload_id', $uploadId)->sum('balance');
        $vencida = DB::table('fact_portfolios')->where('report_upload_id', $uploadId)->sum('past_due_balance');
        $mora    = DB::table('fact_portfolios')->where('report_upload_id', $uploadId)->where('days_past_due', '>', 0)->count();

        $this->line("│  → fact_portfolios: {$total} contratos");
        $this->line("│    Cartera: $" . number_format((float)$balance, 2) .
                    "  Vencida: $" . number_format((float)$vencida, 2) .
                    "  En mora: {$mora} contratos");

        $byProduct = DB::table('fact_portfolios')
            ->where('report_upload_id', $uploadId)
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(balance) as cartera'))
            ->groupBy('product_name')
            ->orderByDesc('cartera')
            ->limit(15)
            ->get();

        $this->line("│  Top productos en cartera:");
        foreach ($byProduct as $p) {
            $label = $p->product_name ?? 'NULL/Sin producto';
            $this->line("│    {$label}: {$p->cnt} contratos, $" . number_format((float)$p->cartera, 2));
        }
    }

    private function auditNoi(int $uploadId, array $dataIds): void
    {
        $total = DB::table('fact_noi_movements')->where('report_upload_id', $uploadId)->count();
        $sum   = DB::table('fact_noi_movements')->where('report_upload_id', $uploadId)->sum('amount');

        $this->line("│  → fact_noi_movements: {$total} movimientos, total=$" . number_format((float)$sum, 2));

        $byType = DB::table('fact_noi_movements')
            ->where('report_upload_id', $uploadId)
            ->select('concept_type', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount) as total'))
            ->groupBy('concept_type')
            ->orderByDesc('total')
            ->get();

        foreach ($byType as $t) {
            $label = $t->concept_type ?? 'NULL';
            $this->line("│    {$label}: {$t->cnt} filas, $" . number_format((float)$t->total, 2));
        }
    }

    private function auditGastos(int $uploadId, array $dataIds): void
    {
        $total = DB::table('fact_expenses')->where('report_upload_id', $uploadId)->count();
        $sum   = DB::table('fact_expenses')->where('report_upload_id', $uploadId)
            ->selectRaw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as t')->value('t');

        $this->line("│  → fact_expenses: {$total} registros, total=$" . number_format((float)$sum, 2));

        $byCat = DB::table('fact_expenses')
            ->where('report_upload_id', $uploadId)
            ->select('category', DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total'))
            ->groupBy('category')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        foreach ($byCat as $c) {
            $label = $c->category ?? 'Sin categoría';
            $this->line("│    {$label}: {$c->cnt} filas, $" . number_format((float)$c->total, 2));
        }
    }

    private function auditEmpleados(int $uploadId, array $dataIds): void
    {
        $this->line("│  → Usado para resolución de sucursales (no inserta en fact tables)");
        $ebaCount = DB::table('employee_branch_assignments')
            ->whereIn('period_id', $dataIds)
            ->count();
        $this->line("│    employee_branch_assignments para el periodo: {$ebaCount} registros");
    }

    private function auditColumns(object $upload, ColumnMapService $cm): void
    {
        if (!Storage::disk('public')->exists($upload->stored_path)) {
            $this->line("│  ⚠ Archivo físico no encontrado para auditoría de columnas");
            return;
        }

        $abs = Storage::disk('public')->path($upload->stored_path);

        // Read only first 20 rows to avoid memory exhaustion on large files
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($abs);
            $reader->setReadDataOnly(true);
            if (method_exists($reader, 'setReadFilter')) {
                $filter = new class implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
                    public function readCell($columnAddress, $row, $worksheetName = '') {
                        return $row <= 20;
                    }
                };
                $reader->setReadFilter($filter);
            }
            $ss   = $reader->load($abs);
            $ws   = $ss->getActiveSheet();
            $rows = [];
            foreach ($ws->getRowIterator(1, 20) as $row) {
                $rowData = [];
                foreach ($row->getCellIterator() as $cell) {
                    $rowData[] = $cell->getValue();
                }
                $rows[] = $rowData;
            }
            $ss->disconnectWorksheets();
            unset($ss);
        } catch (\Throwable $e) {
            $this->line("│  ⚠ Error leyendo archivo para columnas: " . $e->getMessage());
            return;
        }

        // Find header row (first row with score ≥ 3)
        $keywords = ['cliente','promotor','nombre_promotor','monto_desembolsado','producto_de_credito',
                     'contrato','capital','interes','dias_vencido','balance','saldo_actual'];
        $headerIdx = 0; $best = -1;
        foreach (array_slice($rows, 0, 20, true) as $i => $row) {
            if (!is_array($row)) continue;
            $score = 0;
            foreach ($row as $v) {
                $n = $this->normalizeH((string)($v ?? ''));
                if (in_array($n, $keywords)) $score++;
            }
            if ($score > $best) { $best = $score; $headerIdx = $i; }
        }

        $header  = $rows[$headerIdx] ?? [];
        $result  = $cm->resolveHeader($upload->code, $header);
        $map     = $result['map'];

        $this->line("│");
        $this->line("│  COLUMNAS (fila encabezado: {$headerIdx}):");
        $this->line("│  ┌─ USADAS:");
        foreach ($map as $field => $colIdx) {
            $rawName = trim((string)($header[$colIdx] ?? ''));
            $this->line("│  │  {$field} ← Col {$colIdx}: \"{$rawName}\"");
        }
        if (!empty($result['missingRequired'])) {
            $this->line("│  ├─ REQUERIDAS FALTANTES:");
            foreach ($result['missingRequired'] as $f) $this->line("│  │  ! {$f}");
        }
        if (!empty($result['missingOptional'])) {
            $this->line("│  ├─ OPCIONALES FALTANTES:");
            foreach ($result['missingOptional'] as $f) $this->line("│  │  - {$f}");
        }
        $ignoredCount = count($result['ignored']);
        $this->line("│  └─ IGNORADAS: {$ignoredCount} columnas");
        foreach (array_slice($result['ignored'], 0, 10, true) as $norm => $idx) {
            $rawName = trim((string)($header[$idx] ?? ''));
            $this->line("│     Col {$idx}: \"{$rawName}\" [{$norm}]");
        }
        if ($ignoredCount > 10) {
            $this->line("│     ... y " . ($ignoredCount - 10) . " más");
        }
    }

    private function normalizeH(string $v): string
    {
        $v = trim(mb_strtolower($v));
        $v = str_replace(['á','é','í','ó','ú','ü','ñ'],['a','e','i','o','u','u','n'],$v);
        $v = preg_replace('/[^a-z0-9]+/u','_',$v) ?? $v;
        return trim($v,'_');
    }
}
