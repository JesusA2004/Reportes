<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\ColumnMapService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reads the actual Excel file for a given source and traces each row:
 * what product was detected, what would be inserted, and if it matches the DB.
 */
class AuditRowTraceCommand extends Command
{
    protected $signature   = 'reportes:audit-row-trace
                                {period_id}
                                {--source=lendus_ministraciones : data_source code}
                                {--product= : filter by product keyword (e.g. CRECE)}
                                {--contract= : filter by contract number}
                                {--limit=50 : max rows to show}';
    protected $description = 'Traza fila por fila el Excel de una fuente — muestra columnas clave, mapeo y si se insertó';

    public function handle(BranchResolverService $branchResolver, ColumnMapService $columnMap): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $sourceCode  = $this->option('source');
        $filterProd  = strtoupper(trim($this->option('product') ?? ''));
        $filterContr = trim($this->option('contract') ?? '');
        $limit       = (int) $this->option('limit');

        $upload = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('ru.period_id', $period->id)
            ->where('ds.code', $sourceCode)
            ->select('ru.*', 'ds.code as ds_code', 'ds.name as ds_name')
            ->first();

        if (!$upload) {
            $this->error("No se encontró upload para source '{$sourceCode}' en periodo {$period->id}.");
            return 1;
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            $this->error("Archivo físico no encontrado: {$upload->stored_path}");
            return 1;
        }

        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDIT ROW TRACE — {$period->label} | {$sourceCode}");
        $this->info("  Archivo: {$upload->original_name} (upload_id={$upload->id})");
        $this->info("  Filtro producto: " . ($filterProd ?: 'ninguno'));
        $this->info("════════════════════════════════════════════════════════════════");

        $abs    = Storage::disk('public')->path($upload->stored_path);
        $sheets = Excel::toArray([], $abs);
        $rows   = $sheets[0] ?? [];

        if (empty($rows)) {
            $this->error("El archivo está vacío o no se pudo leer.");
            return 1;
        }

        // Detect header row
        $headerIdx = $this->detectHeaderRow($rows);
        $header    = $rows[$headerIdx] ?? [];

        // Resolve column map using CURRENT code
        $resolved = $columnMap->resolveHeader($sourceCode, $header);
        $map      = $resolved['map'];

        $this->line('');
        $this->info("── COLUMNAS DETECTADAS (código actual) ──");
        $this->line(str_pad("Campo DB", 20) . str_pad("Col#", 6) . "Encabezado Excel");
        $this->line(str_repeat('-', 60));
        foreach ($map as $field => $colIdx) {
            $this->line(str_pad($field, 20) . str_pad($colIdx, 6) . trim((string)($header[$colIdx] ?? '')));
        }

        // Show all header columns
        $this->line('');
        $this->info("── TODAS LAS COLUMNAS DEL EXCEL ──");
        foreach ($header as $i => $v) {
            $v = trim((string)($v ?? ''));
            if ($v === '') continue;
            $norm = $this->normalizeH($v);
            $usedBy = '';
            foreach ($map as $f => $c) { if ($c === $i) { $usedBy = " ← {$f}"; break; } }
            if (isset($resolved['ignored'][$norm])) {
                $this->line("  Col {$i}: {$v} [{$norm}] → IGNORADA{$usedBy}");
            } else {
                $this->line("  Col {$i}: {$v} [{$norm}]{$usedBy}");
            }
        }

        if (!isset($map['product_name'])) {
            $this->warn("⚠ product_name NOT MAPPED — no se puede trazar producto.");
        }
        if (!isset($map['amount'])) {
            $this->warn("⚠ amount NOT MAPPED — no se puede trazar monto.");
            return 1;
        }

        // Build DB index for quick lookup
        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$period->id]
        )));

        // Load existing placements for this upload to check what was inserted
        $dbByAmount = DB::table('fact_placements')
            ->where('report_upload_id', $upload->id)
            ->select('id', 'product_name', 'amount', 'branch_id', 'operation_date')
            ->get()
            ->groupBy(fn ($r) => round((float)$r->amount, 2));

        // ── Trace rows ───────────────────────────────────────────────────────
        $this->line('');
        $this->info("── TRAZA FILA POR FILA ──");

        $shown       = 0;
        $totalCRece  = 0;
        $insertedCrece = 0;
        $skippedCrece  = 0;
        $wrongProductCrece = 0;

        foreach (array_slice($rows, $headerIdx + 1, null, true) as $excelRow => $row) {
            if (!is_array($row)) continue;

            $rawProd    = trim((string)($row[$map['product_name'] ?? -1] ?? ''));
            $rawAmount  = $row[$map['amount']] ?? null;
            $rawOrigin  = isset($map['credit_origin'])  ? trim((string)($row[$map['credit_origin']] ?? ''))  : '';
            $rawFinan   = $header[26] === 'Financiamiento' ? trim((string)($row[26] ?? '')) : '';
            $rawLinea   = $header[27] ? trim((string)($row[27] ?? '')) : '';
            $rawOrigen31 = trim((string)($row[31] ?? ''));
            $rawMonto54  = (float)($row[54] ?? 0);
            $rawContr    = isset($map['contract'])   ? trim((string)($row[$map['contract']] ?? ''))   : trim((string)($row[34] ?? ''));
            $rawClient   = isset($map['client_name']) ? trim((string)($row[$map['client_name']] ?? '')) : trim((string)($row[0] ?? ''));

            // Apply filters
            $prodUpper = strtoupper($rawProd);
            if ($filterProd !== '' && !str_contains($prodUpper, $filterProd)) continue;
            if ($filterContr !== '' && !str_contains($rawContr, $filterContr)) continue;

            $normProd  = $rawProd !== '' ? $branchResolver->normalizeProduct($rawProd, null, null) : '';
            $amount    = is_numeric($rawAmount) ? round((float)$rawAmount, 2) : null;
            $isCrece   = str_contains(strtoupper($normProd), 'CRECE');
            $skipped   = ($amount === null || $amount <= 0);

            if ($isCrece) {
                $totalCRece++;
                if (!$skipped) $insertedCrece++;
                else $skippedCrece++;
            }

            // Check if found in DB with correct product_name
            $dbMatch = null;
            if ($amount !== null && $amount > 0) {
                $candidates = $dbByAmount->get($amount) ?? collect();
                foreach ($candidates as $c) {
                    if ($c->product_name === $normProd) { $dbMatch = $c; break; }
                }
                if (!$dbMatch && $candidates->count() > 0 && $isCrece) {
                    $wrongProductCrece++;
                }
            }

            $status = $skipped ? '✗ OMITIDA(monto≤0)' : ($dbMatch ? '✓ DB OK' : '⚠ DISCREPANCIA');
            if (!$skipped && !$dbMatch && $isCrece) {
                $status = '⚠ NO EN DB (producto incorrecto en BD)';
            }

            $this->line(str_repeat('-', 90));
            $this->line("Fila Excel: {$excelRow}");
            $this->line("  Cliente:            {$rawClient}");
            $this->line("  Contrato:           {$rawContr}");
            $this->line("  Producto (col 25):  {$rawProd} → normalizado: {$normProd}");
            $this->line("  Financiamiento(26): {$rawFinan}");
            $this->line("  Línea cred(27):     {$rawLinea}");
            $this->line("  Origen cred(31):    {$rawOrigen31}");
            $this->line("  Monto desbols(53):  {$rawAmount}");
            $this->line("  \$ Desemb(54):       {$rawMonto54}");
            $this->line("  Estado:             {$status}");

            if ($dbMatch) {
                $this->line("  DB id={$dbMatch->id}, product={$dbMatch->product_name}, amount={$dbMatch->amount}");
            } elseif (!$skipped && $isCrece) {
                // Show what's actually in DB with this amount
                $candidates = $dbByAmount->get($amount) ?? collect();
                if ($candidates->count() > 0) {
                    $this->line("  En BD con este monto: " .
                        $candidates->take(3)->map(fn($c)=>"id={$c->id} prod={$c->product_name}")->implode(', '));
                } else {
                    $this->line("  No se encontró este monto en BD para este upload.");
                }
            }

            $shown++;
            if ($shown >= $limit) {
                $this->line("  ... (límite de {$limit} filas alcanzado, usa --limit=N para más)");
                break;
            }
        }

        $this->line('');
        $this->info("── RESUMEN ──");
        $this->line("  Filas CRECE en Excel:                {$totalCRece}");
        $this->line("  Filas CRECE que deberían insertarse:  {$insertedCrece}");
        $this->line("  Filas CRECE omitidas (monto≤0):       {$skippedCrece}");
        $this->line("  Filas CRECE con producto incorrecto en BD: {$wrongProductCrece}");

        $this->line('');
        $this->info("── DIAGNÓSTICO IMPORT BUG ──");
        $this->line("  • La columna correcta para product_name es Col 25 (Producto de crédito)");
        $this->line("  • La columna Col 27 (Línea de crédito) = RECURSOS PROPIOS para casi todas las filas");
        $this->line("  • El import del 2026-05-13 usó código antiguo que tenía 'linea_de_credito'");
        $this->line("    como PRIMER alias → mapeó Col 27 en lugar de Col 25");
        $this->line("  • Filas CRECE se guardaron como 's12' (SEMANAL+12 pagos) en lugar de 'CRECE12'");
        $this->line("  • SOLUCIÓN: Re-importar el archivo con el código actual");

        return 0;
    }

    private function detectHeaderRow(array $rows): int
    {
        $keywords = ['cliente','nombre_del_cliente','nombre_cliente','acreditado','nombre_acreditado',
            'oficina','zona','coordinador','nombre_coordinador','promotor','nombre_promotor','contrato',
            'estatus','fecha_desembolso','monto_desembolsado','desembolsado','producto_de_credito'];

        $best = 0; $bestScore = -1;
        foreach (array_slice($rows, 0, 80, true) as $i => $row) {
            if (!is_array($row)) continue;
            $score = 0;
            foreach ($row as $v) {
                if (in_array($this->normalizeH((string)($v ?? '')), $keywords)) $score++;
            }
            if ($score > $bestScore) { $bestScore = $score; $best = $i; }
            if ($score >= 6) return $i;
        }
        return $best;
    }

    private function normalizeH(string $v): string
    {
        $v = trim(mb_strtolower($v));
        $v = str_replace(['á','é','í','ó','ú','ü','ñ'],['a','e','i','o','u','u','n'],$v);
        $v = preg_replace('/[^a-z0-9]+/u','_',$v) ?? $v;
        return trim($v,'_');
    }
}
