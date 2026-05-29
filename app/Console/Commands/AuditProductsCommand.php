<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\ProductNormalizerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Full product audit across all data sources for a period.
 *
 * Shows product_raw → product_normalizado, fuente, tabla, columna, registros,
 * montos, clasificación y motivo. Detecta normalizaciones incorrectas en BD.
 */
class AuditProductsCommand extends Command
{
    protected $signature = 'reportes:audit-products
                                {period_id}
                                {--raw= : filter by raw product keyword}
                                {--source= : filter by source table (placements|portfolios|recoveries)}';
    protected $description = 'Auditoría completa de productos: raw → normalizado, fuente, monto, clasificación';

    public function handle(ProductNormalizerService $normalizer): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return 1;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds, [$period->id]
        )));

        $rawFilter    = strtoupper(trim($this->option('raw') ?? ''));
        $sourceFilter = strtolower(trim($this->option('source') ?? ''));

        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA DE PRODUCTOS — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("  Filtro raw: " . ($rawFilter ?: 'todos') . " | Fuente: " . ($sourceFilter ?: 'todas'));
        $this->info("════════════════════════════════════════════════════════════════");

        // ── A. COLOCACIÓN (fact_placements) ──────────────────────────────────
        if (!$sourceFilter || $sourceFilter === 'placements') {
            $this->line('');
            $this->info('════ A. COLOCACIÓN — fact_placements (Ministraciones) ════');
            $this->auditPlacements($dataIds, $rawFilter, $normalizer);
        }

        // ── B. CARTERA (fact_portfolios) ─────────────────────────────────────
        if (!$sourceFilter || $sourceFilter === 'portfolios') {
            $this->line('');
            $this->info('════ B. CARTERA — fact_portfolios (Saldos por Cliente) ════');
            $this->auditPortfolios($dataIds, $rawFilter, $normalizer);
        }

        // ── C. RECUPERACIÓN (fact_recoveries) ────────────────────────────────
        if (!$sourceFilter || $sourceFilter === 'recoveries') {
            $this->line('');
            $this->info('════ C. RECUPERACIÓN — fact_recoveries (Ingresos/Cobranza) ════');
            $this->auditRecoveries($dataIds, $rawFilter, $normalizer);
        }

        // ── D. RESUMEN DE ANOMALÍAS ───────────────────────────────────────────
        if (!$rawFilter && !$sourceFilter) {
            $this->line('');
            $this->info('════ D. ANOMALÍAS DETECTADAS ════');
            $this->detectAnomalies($dataIds, $normalizer);
        }

        return 0;
    }

    // ── A. Colocación ────────────────────────────────────────────────────────

    private function auditPlacements(array $dataIds, string $rawFilter, ProductNormalizerService $normalizer): void
    {
        $q = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->select(
                'product_name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(amount) as colocacion'),
                DB::raw('MAX(raw_payload) as sample_payload'),
                DB::raw('GROUP_CONCAT(DISTINCT promoter_code ORDER BY promoter_code SEPARATOR ", ") as promoters')
            )
            ->groupBy('product_name')
            ->orderByDesc('colocacion');

        if ($rawFilter !== '') {
            $q->whereRaw("UPPER(COALESCE(product_name,'')) LIKE ?", ["%{$rawFilter}%"]);
        }

        $rows = $q->get();

        if ($rows->isEmpty()) {
            $this->warn("  No se encontraron registros" . ($rawFilter ? " con filtro '{$rawFilter}'" : '') . ".");
            return;
        }

        $this->line(str_pad('Producto DB (raw)', 38) . str_pad('→ Normalizado', 22) . str_pad('Regs', 7) .
                    str_pad('Colocación', 17) . str_pad('Familia', 14) . str_pad('Operativo', 11) . 'Motivo');
        $this->line(str_repeat('─', 130));

        foreach ($rows as $row) {
            $rawProd = $row->product_name ?? 'NULL';
            $result  = $normalizer->normalize($rawProd);
            $norm    = $result->normalizedProduct;
            $changed = ($norm !== $rawProd) ? ' ← DIFERENTE' : '';
            $opStr   = $result->isExcluded ? 'NO (excluido)' : ($result->isOperational ? 'SÍ' : 'NO');
            $famStr  = strtoupper($result->family);

            $this->line(
                str_pad(mb_substr($rawProd, 0, 36), 38) .
                str_pad(mb_substr($norm, 0, 20), 22) .
                str_pad(number_format($row->cnt), 7) .
                str_pad('$' . number_format((float)$row->colocacion, 0), 17) .
                str_pad($famStr, 14) .
                str_pad($opStr, 11) .
                mb_substr($result->reason, 0, 60) . $changed
            );

            if ($rawFilter !== '') {
                $this->line("      Promotores: " . ($row->promoters ?? 'ninguno'));
                $payload = json_decode($row->sample_payload ?? '{}', true);
                if (!empty($payload)) {
                    $origin = $payload['credit_origin'] ?? '?';
                    $this->line("      Origen crédito (muestra): {$origin}");
                    $this->line("      Monto monto53: $" . number_format($payload['amount_monto53'] ?? 0, 2));
                }
                $this->showPlacementDetails($dataIds, $rawProd, $normalizer);
            }
        }

        $total = DB::table('fact_placements')->whereIn('period_id', $dataIds)
            ->when($rawFilter, fn($q) => $q->whereRaw("UPPER(COALESCE(product_name,'')) LIKE ?", ["%{$rawFilter}%"]))
            ->sum('amount');
        $this->line(str_repeat('─', 60));
        $this->line(str_pad('TOTAL COLOCACIÓN', 45) . '$' . number_format((float)$total, 2));
    }

    private function showPlacementDetails(array $dataIds, string $product, ProductNormalizerService $normalizer): void
    {
        $samples = DB::table('fact_placements as fp')
            ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
            ->leftJoin('report_uploads as ru', 'fp.report_upload_id', '=', 'ru.id')
            ->whereIn('fp.period_id', $dataIds)
            ->where('fp.product_name', $product)
            ->select(
                'fp.id', 'fp.product_name', 'fp.amount', 'fp.promoter_code',
                'fp.client_name', 'fp.operation_date', 'fp.raw_payload',
                DB::raw('COALESCE(b.name,"Sin sucursal") as sucursal'),
                'ru.original_name as archivo'
            )
            ->limit(10)
            ->get();

        $this->line("    ── Muestra de filas ({$product}) ──");
        foreach ($samples as $s) {
            $payload = json_decode($s->raw_payload ?? '{}', true);
            $origin  = $payload['credit_origin'] ?? '?';
            $this->line(sprintf(
                "      id=%d | %s | promotor=%s | sucursal=%s | $%s | origen=%s | archivo=%s",
                $s->id,
                $s->operation_date ?? 'sin fecha',
                $s->promoter_code ?? '?',
                $s->sucursal,
                number_format((float)$s->amount, 2),
                $origin,
                mb_substr($s->archivo ?? '?', 0, 40)
            ));
        }
    }

    // ── B. Cartera ──────────────────────────────────────────────────────────

    private function auditPortfolios(array $dataIds, string $rawFilter, ProductNormalizerService $normalizer): void
    {
        $q = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->select(
                'product_name',
                DB::raw('COUNT(*) as contratos'),
                DB::raw('SUM(balance) as cartera'),
                DB::raw('SUM(past_due_balance) as vencida'),
                DB::raw('SUM(CASE WHEN days_past_due > 0 THEN COALESCE(past_due_balance,balance) ELSE 0 END) as mora')
            )
            ->groupBy('product_name')
            ->orderByDesc('cartera');

        if ($rawFilter !== '') {
            $q->whereRaw("UPPER(COALESCE(product_name,'')) LIKE ?", ["%{$rawFilter}%"]);
        }

        $rows = $q->get();

        if ($rows->isEmpty()) {
            $this->warn("  No se encontraron registros.");
            return;
        }

        $this->line(str_pad('Producto DB (raw)', 38) . str_pad('→ Normalizado', 22) . str_pad('Cttos', 7) .
                    str_pad('Cartera', 17) . str_pad('Mora', 15) . 'Familia / Motivo');
        $this->line(str_repeat('─', 115));

        foreach ($rows as $row) {
            $rawProd = $row->product_name ?? 'NULL';
            $result  = $normalizer->normalize($rawProd);
            $norm    = $result->normalizedProduct;
            $changed = ($norm !== $rawProd) ? ' ← DIFERENTE' : '';

            $this->line(
                str_pad(mb_substr($rawProd, 0, 36), 38) .
                str_pad(mb_substr($norm, 0, 20), 22) .
                str_pad(number_format($row->contratos), 7) .
                str_pad('$' . number_format((float)$row->cartera, 0), 17) .
                str_pad('$' . number_format((float)$row->mora, 0), 15) .
                "[{$result->family}] " . mb_substr($result->reason, 0, 50) . $changed
            );
        }
    }

    // ── C. Recuperación ─────────────────────────────────────────────────────

    private function auditRecoveries(array $dataIds, string $rawFilter, ProductNormalizerService $normalizer): void
    {
        $q = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->select(
                'product_name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(capital) as capital'),
                DB::raw('SUM(interest) as interes'),
                DB::raw('SUM(total_amount) as total'),
                DB::raw('SUM(savehearts_crece_share) as share30')
            )
            ->groupBy('product_name')
            ->orderByDesc('total');

        if ($rawFilter !== '') {
            $q->whereRaw("UPPER(COALESCE(product_name,'')) LIKE ?", ["%{$rawFilter}%"]);
        }

        $rows = $q->get();

        if ($rows->isEmpty()) {
            $this->warn("  No se encontraron registros.");
            return;
        }

        $this->line(str_pad('Producto DB (raw)', 38) . str_pad('→ Normalizado', 22) . str_pad('Regs', 7) .
                    str_pad('Capital', 16) . str_pad('Total', 16) . str_pad('CRECE30%', 12) . 'Familia');
        $this->line(str_repeat('─', 120));

        foreach ($rows as $row) {
            $rawProd = $row->product_name ?? 'NULL';
            $result  = $normalizer->normalize($rawProd);
            $norm    = $result->normalizedProduct;
            $changed = ($norm !== $rawProd) ? ' ←' : '';

            $this->line(
                str_pad(mb_substr($rawProd, 0, 36), 38) .
                str_pad(mb_substr($norm, 0, 20), 22) .
                str_pad(number_format($row->cnt), 7) .
                str_pad('$' . number_format((float)$row->capital, 0), 16) .
                str_pad('$' . number_format((float)$row->total, 0), 16) .
                str_pad('$' . number_format((float)$row->share30, 0), 12) .
                "[{$result->family}]" . $changed
            );
        }
    }

    // ── D. Anomalías ─────────────────────────────────────────────────────────

    private function detectAnomalies(array $dataIds, ProductNormalizerService $normalizer): void
    {
        $anomalies = [];

        // Check CREDITO DIARIO in placements — distinguish resolvable vs non-standard plazo
        $creditoDiario = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->where('product_name', 'CREDITO DIARIO')
            ->count();
        if ($creditoDiario > 0) {
            $total = DB::table('fact_placements')->whereIn('period_id', $dataIds)
                ->where('product_name', 'CREDITO DIARIO')->sum('amount');
            // These are CHIHUAHUA (CH) and DURANGO (DG) branch credits with non-standard plazo 15 or 25.
            // User decision: keep as CREDITO DIARIO + incident. Do NOT normalize to fake iXX.
            $byPrefix = DB::table('fact_placements')
                ->whereIn('period_id', $dataIds)
                ->where('product_name', 'CREDITO DIARIO')
                ->select(DB::raw('SUBSTRING(promoter_code,1,2) as prefix'), DB::raw('COUNT(*) as cnt'))
                ->groupBy(DB::raw('SUBSTRING(promoter_code,1,2)'))
                ->get()->pluck('cnt', 'prefix')->toArray();
            $prefixStr = implode(', ', array_map(fn($p,$c)=>"{$p}:{$c}", array_keys($byPrefix), $byPrefix));
            $anomalies[] = "ℹ CREDITO DIARIO: {$creditoDiario} regs, $" . number_format((float)$total, 2) .
                           " | Sucursales: [{$prefixStr}] (CH=CHIHUAHUA, DG=DURANGO)" .
                           " | Plazo: 15 o 25 pagos diarios (no estándar). DECISIÓN: mantener como incidencia sin reclasificar.";
        }

        // Check DIARIO 40 in placements
        $diario40 = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->where('product_name', 'DIARIO 40')
            ->count();
        if ($diario40 > 0) {
            $total = DB::table('fact_placements')->whereIn('period_id', $dataIds)
                ->where('product_name', 'DIARIO 40')->sum('amount');
            $anomalies[] = "⚠ DIARIO 40 en ministraciones: {$diario40} regs, $" . number_format((float)$total, 2) .
                           " — debería ser i40. El importador llama normalizeProduct('DIARIO 40', null, null)." .
                           " FIX: agregar regla 'DIARIO + número' en normalizer.";
        }

        // Check OFICINA MR LANA
        $oml = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->where('product_name', 'OFICINA MR LANA')
            ->count();
        if ($oml > 0) {
            $total = DB::table('fact_placements')->whereIn('period_id', $dataIds)
                ->where('product_name', 'OFICINA MR LANA')->sum('amount');
            $proms = DB::table('fact_placements')->whereIn('period_id', $dataIds)
                ->where('product_name', 'OFICINA MR LANA')
                ->select('promoter_code')->distinct()->pluck('promoter_code')->implode(', ');
            $anomalies[] = "⚠ OFICINA MR LANA en ministraciones: {$oml} regs, $" . number_format((float)$total, 2) .
                           " | promotor(es): {$proms} | sin sucursal asignada." .
                           " Verificar si el Excel usa el nombre de oficina como producto.";
        }

        // Check CRECE variants in portfolios that should be normalized
        $creceVariants = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(product_name) LIKE '%CRECE%'")
            ->where(function ($q) {
                $q->whereRaw("product_name NOT REGEXP '^CRECE(12|24)( SAC)?$'")
                  ->whereRaw("product_name != 'CRECE'");
            })
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(balance) as cartera'))
            ->groupBy('product_name')
            ->get();

        foreach ($creceVariants as $cv) {
            $result = $normalizer->normalize($cv->product_name);
            $changed = $result->normalizedProduct !== $cv->product_name ? " → {$result->normalizedProduct}" : ' (sin cambio)';
            $anomalies[] = "⚠ CRECE variante en cartera: '{$cv->product_name}' {$cv->cnt} cttos, $" .
                           number_format((float)$cv->cartera, 0) . $changed;
        }

        // Check if any product in portfolios or recoveries should be excluded
        $excludedInRecoveries = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP ?", [
                'REESTRUCTURA|UNIFICACION|MIGRACION|RECURSOS PROPIOS'
            ])
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(total_amount) as total'))
            ->groupBy('product_name')
            ->get();

        foreach ($excludedInRecoveries as $ex) {
            $anomalies[] = "⚠ Producto que debería excluirse en fact_recoveries: '{$ex->product_name}' {$ex->cnt} regs $" .
                           number_format((float)$ex->total, 0);
        }

        if (empty($anomalies)) {
            $this->info("  ✓ No se detectaron anomalías en la normalización de productos.");
        } else {
            foreach ($anomalies as $a) {
                $this->warn("  {$a}");
            }
        }

        $this->line('');
        $this->info('── DIAGNÓSTICO: CREDITO DIARIO sin plazo ──');
        $this->line('  El importador LendusMinistracionesImportService llama:');
        $this->line('    normalizeProduct($productName, null, null)  ← ambos parámetros null');
        $this->line('  Para "CREDITO DIARIO": el normalizer no puede inferir i20/i30/i40/i60.');
        $this->line('  Solución: Agregar "num_payments" y "periodicity" a ColumnMapService (ministraciones)');
        $this->line('            y pasarlos a normalizeProduct() en el importador.');
        $this->line('            Luego re-importar el archivo de ministraciones.');

        $this->line('');
        $this->info('── DIAGNÓSTICO: DIARIO 40 no normalizado ──');
        $this->line('  "DIARIO 40" tiene el número de pagos en el nombre pero sin "I" prefix.');
        $this->line('  La regla actual solo cubre "I40" (con I mayúscula) y "DIARIO I40".');
        $this->line('  FIX en normalizeProduct: agregar regex para "DIARIO + espacio + número".');
        $this->line('  Con ProductNormalizerService este fix ya está implementado.');
        $this->line('  Para aplicarlo a datos existentes: re-importar ministraciones.');
    }
}
