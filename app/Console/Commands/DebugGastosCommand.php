<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Support\ExpenseStatusNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class DebugGastosCommand extends Command
{
    protected $signature   = 'reportes:debug-gastos {periodId : ID del periodo a diagnosticar}';
    protected $description = 'Diagnóstico de gastos: totales por fuente, sucursal, categoría, concepto. Valida que "Sin sucursal" sea 0.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('periodId');

        $period = Period::find($periodId);
        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info("══════════ DEBUG GASTOS — Periodo #{$periodId}: {$period->label} ══════════");
        $this->line('');

        // ── 1. TOTALES POR FUENTE ─────────────────────────────────────────
        $this->info('── 1. Totales por fuente ──');
        $bySource = DB::table('fact_expenses as e')
            ->leftJoin('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('e.period_id', $periodId)
            ->selectRaw("COALESCE(ds.code, 'sin_fuente') as fuente, COUNT(*) as registros, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('ds.id', 'ds.code')
            ->orderByDesc('total')
            ->get();

        if ($bySource->isEmpty()) {
            $this->warn('  Sin registros de gastos en fact_expenses para este periodo.');
        } else {
            $grandTotal = 0;
            foreach ($bySource as $s) {
                $grandTotal += $s->total;
                $this->line(sprintf(
                    '  %-30s registros=%-5d total=$%s',
                    $s->fuente,
                    $s->registros,
                    number_format($s->total, 2)
                ));
            }
            $this->line(sprintf('  %-30s total=$%s', '→ TOTAL GENERAL:', number_format($grandTotal, 2)));
        }

        $this->line('');

        // ── 2. TOTALES POR SUCURSAL ───────────────────────────────────────
        $this->info('── 2. Totales por sucursal ──');
        $bySucursal = DB::table('fact_expenses as e')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->where('e.period_id', $periodId)
            ->selectRaw("COALESCE(b.name, '⚠ Sin sucursal') as sucursal, COUNT(*) as registros, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('e.branch_id', 'b.name')
            ->orderByRaw("b.name IS NULL DESC")
            ->orderByDesc('total')
            ->get();

        $sinSucursalCount = 0;
        foreach ($bySucursal as $s) {
            $isMissing = str_starts_with($s->sucursal, '⚠');
            $sinSucursalCount += $isMissing ? $s->registros : 0;
            $label = $isMissing ? "<fg=red>{$s->sucursal}</>" : $s->sucursal;
            $this->line(sprintf(
                '  %-32s registros=%-5d total=$%s',
                $label,
                $s->registros,
                number_format($s->total, 2)
            ));
        }

        if ($sinSucursalCount === 0) {
            $this->line('  <fg=green>✓ Sin sucursal = 0 — todos los gastos tienen sucursal asignada.</>');
        } else {
            $this->warn("  ⚠ {$sinSucursalCount} registro(s) sin sucursal.");
        }

        $this->line('');

        // ── 3. REGISTROS SIN SUCURSAL — EJEMPLOS ─────────────────────────
        if ($sinSucursalCount > 0) {
            $this->info('── 3. Ejemplos de registros sin sucursal ──');
            $sinSuc = DB::table('fact_expenses as e')
                ->leftJoin('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
                ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
                ->where('e.period_id', $periodId)
                ->whereNull('e.branch_id')
                ->selectRaw("COALESCE(ds.code,'sin_fuente') as fuente, e.observations as empleado, e.category, e.concept, COALESCE(NULLIF(e.paid_amount,0),e.amount) as monto")
                ->orderByDesc('monto')
                ->limit(20)
                ->get();

            foreach ($sinSuc as $r) {
                $this->line(sprintf(
                    '  [%s] %s | %s | %s | $%s',
                    $r->fuente,
                    mb_substr($r->empleado ?? '—', 0, 30),
                    $r->category,
                    mb_substr($r->concept, 0, 25),
                    number_format($r->monto, 2)
                ));
            }
            $this->line('');
        } else {
            $this->info('── 3. Sin sucursal: ninguno ──');
            $this->line('');
        }

        // ── 4. TOTALES POR CATEGORÍA ──────────────────────────────────────
        $this->info('── 4. Totales por categoría ──');
        $byCat = DB::table('fact_expenses')
            ->where('period_id', $periodId)
            ->selectRaw("COALESCE(category, 'Sin categoría') as cat, COUNT(*) as registros, SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total")
            ->groupBy('category')
            ->orderByDesc('total')
            ->get();

        foreach ($byCat as $c) {
            $this->line(sprintf(
                '  %-40s registros=%-5d total=$%s',
                $c->cat,
                $c->registros,
                number_format($c->total, 2)
            ));
        }

        $this->line('');

        // ── 5. TOTALES POR CONCEPTO (top 30) ─────────────────────────────
        $this->info('── 5. Totales por concepto (top 30) ──');
        $byConcept = DB::table('fact_expenses')
            ->where('period_id', $periodId)
            ->selectRaw("COALESCE(concept, 'Sin concepto') as concepto, COUNT(*) as registros, SUM(COALESCE(NULLIF(paid_amount,0), amount)) as total")
            ->groupBy('concept')
            ->orderByDesc('total')
            ->limit(30)
            ->get();

        foreach ($byConcept as $c) {
            $this->line(sprintf(
                '  %-35s registros=%-5d total=$%s',
                mb_substr($c->concepto, 0, 35),
                $c->registros,
                number_format($c->total, 2)
            ));
        }

        $this->line('');

        // ── 6. FONDEOS / PRÉSTAMOS INTERSUCURSAL ─────────────────────────
        $this->info('── 6. Fondeos / Préstamos intersucursal ──');
        $fondeos = DB::table('fact_expenses as e')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->where('e.period_id', $periodId)
            ->where('e.category', 'FONDEO / PRESTAMO INTERSUCURSAL')
            ->selectRaw("COALESCE(b.name,'Sin sucursal') as sucursal, COUNT(*) as registros, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('e.branch_id', 'b.name')
            ->orderByDesc('total')
            ->get();

        if ($fondeos->isEmpty()) {
            $this->line('  Sin fondeos registrados.');
        } else {
            $totalFondeos = 0;
            foreach ($fondeos as $f) {
                $totalFondeos += $f->total;
                $this->line(sprintf(
                    '  %-25s registros=%-4d total=$%s',
                    $f->sucursal,
                    $f->registros,
                    number_format($f->total, 2)
                ));
            }
            $this->line(sprintf('  → Total fondeos: $%s', number_format($totalFondeos, 2)));
        }

        $this->line('');

        // ── 7. GASTOS ERP — DESGLOSE POR ESTATUS ─────────────────────────
        $this->info('── 7. Gastos ERP: desglose por estatus ──');
        $this->printErpStatusBreakdown($periodId);

        $this->line('');

        // ── 8. VALIDACIÓN CONTRA TOTALES DEL PDF ─────────────────────────
        $this->info('── 8. Validación contra totales del PDF Lendus ──');
        $this->printPdfTotalsValidation($periodId);

        $this->line('');
        $this->info('══════════ FIN DIAGNÓSTICO GASTOS ══════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function printErpStatusBreakdown(int $periodId): void
    {
        // Leer raw_payload->estatus de todos los gastos ERP del periodo
        $erpRows = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('e.period_id', $periodId)
            ->where('ds.code', 'gastos_erp')
            ->selectRaw("e.raw_payload, COALESCE(NULLIF(e.paid_amount,0), e.amount) as monto")
            ->get();

        if ($erpRows->isEmpty()) {
            $this->line('  No hay registros ERP contabilizados para este periodo.');
            return;
        }

        $statusTotals = [];
        $totalContabilizado = 0.0;
        $totalIgnorado = 0.0;
        $countContabilizado = 0;
        $countIgnorado = 0;

        // También leer de report_uploads para ver raw data antes del filtro
        // Los registros en fact_expenses ya son solo los que pasaron el filtro
        // Para contar ignorados necesitamos el conteo original
        // Usar raw_payload.estatus de los insertados + contar cuántos no están
        foreach ($erpRows as $row) {
            $payload = is_string($row->raw_payload) ? json_decode($row->raw_payload, true) : (array)$row->raw_payload;
            $rawStatus = $payload['estatus'] ?? null;
            $normStatus = $rawStatus ? ExpenseStatusNormalizer::normalize($rawStatus) : 'SIN ESTATUS';
            $statusTotals[$normStatus] = ($statusTotals[$normStatus] ?? ['count' => 0, 'total' => 0.0]);
            $statusTotals[$normStatus]['count']++;
            $statusTotals[$normStatus]['total'] += (float) $row->monto;
            $totalContabilizado += (float) $row->monto;
            $countContabilizado++;
        }

        $this->line(sprintf(
            '  %-30s registros=%-5d monto=$%s',
            '→ Contabilizados (en BD)',
            $countContabilizado,
            number_format($totalContabilizado, 2)
        ));

        $this->line('');
        $this->line(sprintf('  %-30s %s', 'ESTATUS (normalizado)', 'registros / monto'));
        $this->line('  ' . str_repeat('─', 60));

        arsort($statusTotals);
        foreach ($statusTotals as $status => $data) {
            $isBillable = ExpenseStatusNormalizer::isBillable($status);
            $marker     = $isBillable ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $this->line(sprintf(
                '  %s %-28s registros=%-4d $%s',
                $marker,
                mb_substr($status, 0, 28),
                $data['count'],
                number_format($data['total'], 2)
            ));
        }

        $this->line('');
        $this->line('  <fg=cyan>Nota: Solo PAGADA/POR COMPROBAR/COMPROBADA cuentan como gasto real.</fg=cyan>');
        $this->line('  <fg=cyan>CAPTURADA/RECHAZADA/NO AUTORIZADA/CANCELADA se excluyen en la importación.</fg=cyan>');
    }

    private function printPdfTotalsValidation(int $periodId): void
    {
        // Find the gastos_lendus upload for this period
        $upload = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->where('ru.period_id', $periodId)
            ->where('ds.code', 'gastos_lendus')
            ->select('ru.stored_path')
            ->first();

        if (!$upload || !$upload->stored_path) {
            $this->line('  No hay archivo Gastos Lendus (PDF) cargado para este periodo.');
            return;
        }

        if (!Storage::disk('public')->exists($upload->stored_path)) {
            $this->line('  El archivo PDF no existe en storage. Omitiendo validación.');
            return;
        }

        // Extract TOTAL: lines from the PDF
        try {
            $parser   = new Parser();
            $pdf      = $parser->parseFile(Storage::disk('public')->path($upload->stored_path));
            $pdfTotals = [];

            foreach ($pdf->getPages() as $page) {
                foreach (explode("\n", $page->getText()) as $raw) {
                    $line = trim($raw);
                    if (!str_starts_with($line, 'TOTAL:')) continue;
                    // TOTAL: SUCURSAL\t$ 0.00$ X,XXX.XX\t...  (first $ = M_pag_empleado, second = real total)
                    if (preg_match('/^TOTAL:\s+(.+?)\s+\$\s*[\d,]+\.?\d*\$\s*([\d,]+\.?\d*)/', $line, $m)) {
                        $branch = trim($m[1]);
                        $amt    = (float) str_replace(',', '', $m[2]);
                        if ($branch !== '' && !str_starts_with($branch, '$')) {
                            $pdfTotals[mb_strtoupper($branch)] = $amt;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->line("  No se pudo leer el PDF para validación: {$e->getMessage()}");
            return;
        }

        if (empty($pdfTotals)) {
            $this->line('  No se encontraron líneas TOTAL: en el PDF.');
            return;
        }

        // DB sums per branch (Lendus source only) — normalize accents for comparison
        $dbSums = DB::table('fact_expenses as e')
            ->join('report_uploads as ru', 'e.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->leftJoin('branches as b', 'e.branch_id', '=', 'b.id')
            ->where('e.period_id', $periodId)
            ->where('ds.code', 'gastos_lendus')
            ->selectRaw("UPPER(COALESCE(b.name,'SIN SUCURSAL')) as sucursal, SUM(COALESCE(NULLIF(e.paid_amount,0), e.amount)) as total")
            ->groupBy('e.branch_id', 'b.name')
            ->get()
            ->keyBy(fn ($r) => $this->stripAccents($r->sucursal));

        // Normalize PDF branch keys too
        $pdfTotalsNorm = [];
        foreach ($pdfTotals as $branch => $amt) {
            $pdfTotalsNorm[$this->stripAccents($branch)] = ['label' => $branch, 'amt' => $amt];
        }

        $this->line(sprintf(
            '  %-28s %14s  %14s  %10s',
            'SUCURSAL (PDF)',
            'TOTAL PDF',
            'TOTAL DB',
            'DIFERENCIA'
        ));
        $this->line('  ' . str_repeat('─', 72));

        $allOk = true;
        foreach ($pdfTotalsNorm as $key => $entry) {
            $branch = $entry['label'];
            $pdfAmt = $entry['amt'];
            $dbAmt = isset($dbSums[$key]) ? (float) $dbSums[$key]->total : 0.0;
            $diff  = $dbAmt - $pdfAmt;
            $ok    = abs($diff) < 1.0;
            $allOk = $allOk && $ok;

            $diffLabel = $ok
                ? '<fg=green>' . number_format($diff, 2) . '</>'
                : '<fg=red>' . number_format($diff, 2) . ' ⚠</>';

            $this->line(sprintf(
                '  %-28s %14s  %14s  %s',
                mb_substr($branch, 0, 28),
                '$' . number_format($pdfAmt, 2),
                '$' . number_format($dbAmt, 2),
                $diffLabel
            ));
        }

        $totalPdf = array_sum(array_column($pdfTotalsNorm, 'amt'));
        $totalDb  = (float) $dbSums->sum('total');
        $totalDiff = $totalDb - $totalPdf;

        $this->line('  ' . str_repeat('─', 72));
        $this->line(sprintf(
            '  %-28s %14s  %14s  %s',
            '→ TOTALES GLOBALES',
            '$' . number_format($totalPdf, 2),
            '$' . number_format($totalDb, 2),
            abs($totalDiff) < 1.0
                ? '<fg=green>' . number_format($totalDiff, 2) . '</>'
                : '<fg=yellow>' . number_format($totalDiff, 2) . '</>'
        ));
        $this->line('');

        if ($allOk) {
            $this->line('  <fg=green>✓ Todos los totales coinciden con el PDF.</>');
        } else {
            $negCount  = count(array_filter($pdfTotalsNorm, fn ($e) => (isset($dbSums[$e['label']]) ? (float)$dbSums[$e['label']]->total : 0.0) < $e['amt'] - 1.0));
            $this->warn("  ⚠ {$negCount} sucursal(es) con DB < PDF.");
            $this->line('  <fg=cyan>Nota: Las diferencias negativas (DB < PDF) se explican por filas Cancelado</> ');
            $this->line('  <fg=cyan>que el PDF suma en su TOTAL pero la importación excluye correctamente.</>');
            $this->line('  Si la diferencia es positiva (DB > PDF) o muy grande, reimportar el archivo.');
        }
    }

    private function stripAccents(string $s): string
    {
        return strtr(mb_strtoupper($s), [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
        ]);
    }
}
