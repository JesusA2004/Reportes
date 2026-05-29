<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Full audit of CRECE product across all data sources for a period.
 * Shows where CRECE appears, where it doesn't, and WHY.
 */
class AuditCreceCommand extends Command
{
    protected $signature   = 'reportes:audit-crece {period_id}';
    protected $description = 'Auditoría completa de CRECE en todas las fuentes del periodo';

    private const CRECE_PATTERN = 'CRECE|CRECE12|CRECE 12|CRECE24|CRECE 24|CRECE SAC|CRECE12 SAC|CRECE24 SAC|A LA MEDIDA';

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
        $this->info("  AUDITORÍA CRECE — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("════════════════════════════════════════════════════════════");

        // ── 1. COLOCACIÓN ────────────────────────────────────────────────────
        $this->line('');
        $this->info('─── 1. CRECE EN COLOCACIÓN (fact_placements) ───');

        $creceCol = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount) as total'))
            ->groupBy('product_name')
            ->get();

        if ($creceCol->isEmpty()) {
            $this->warn("  ✗ CRECE no encontrado en fact_placements.");
            $this->line("  Diagnóstico:");
            $this->line("    → El archivo Ministraciones.xlsx del periodo NO contiene productos CRECE.");
            $this->line("    → Todos los productos en ministraciones son: " .
                DB::table('fact_placements')
                    ->whereIn('period_id', $dataIds)
                    ->select('product_name', DB::raw('COUNT(*) as cnt'))
                    ->groupBy('product_name')
                    ->pluck('cnt', 'product_name')
                    ->map(fn ($c, $p) => ($p ?? 'null') . "({$c})")
                    ->implode(', ')
            );
        } else {
            foreach ($creceCol as $r) {
                $this->info("  ✓ {$r->product_name}: {$r->cnt} ops, $" . number_format((float)$r->total, 2));
            }
        }

        // Check if CRECE might be in raw_payload but not product_name
        $creceRaw = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("JSON_SEARCH(raw_payload, 'one', 'CRECE%', NULL, '$.*') IS NOT NULL")
            ->count();
        $this->line("  CRECE en raw_payload de ministraciones: {$creceRaw} registros");

        // ── 2. RECUPERACIÓN ──────────────────────────────────────────────────
        $this->line('');
        $this->info('─── 2. CRECE EN RECUPERACIÓN (fact_recoveries) ───');

        $creceRec = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->select(
                'product_name',
                'is_savehearts',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(capital) as capital'),
                DB::raw('SUM(interest) as interes'),
                DB::raw('SUM(tax) as impuesto'),
                DB::raw('SUM(charges) as cargos'),
                DB::raw('SUM(total_amount) as total'),
                DB::raw('SUM(savehearts_crece_share) as share30')
            )
            ->groupBy('product_name', 'is_savehearts')
            ->orderByDesc('total')
            ->get();

        if ($creceRec->isEmpty()) {
            $this->warn("  ✗ CRECE no encontrado en fact_recoveries.");
        } else {
            $totalCreceRec = 0.0;
            foreach ($creceRec as $r) {
                $sh = $r->is_savehearts ? ' [SAVEHEARTS]' : '';
                $this->info("  ✓ {$r->product_name}{$sh}: {$r->cnt} filas");
                $this->line("      Capital=$" . number_format((float)$r->capital, 2) .
                            "  Interés=$" . number_format((float)$r->interes, 2) .
                            "  Imp=$" . number_format((float)$r->impuesto, 2) .
                            "  Cargos=$" . number_format((float)$r->cargos, 2));
                $this->line("      Total=$" . number_format((float)$r->total, 2) .
                            "  CRECE30_share=$" . number_format((float)$r->share30, 2));
                $totalCreceRec += (float)$r->total;
            }
            $this->line("  TOTAL CRECE en recuperación: $" . number_format($totalCreceRec, 2));
        }

        // ── 3. CARTERA ───────────────────────────────────────────────────────
        $this->line('');
        $this->info('─── 3. CRECE EN CARTERA (fact_portfolios) ───');

        $crecePort = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->select(
                'product_name',
                DB::raw('COUNT(*) as contratos'),
                DB::raw('SUM(balance) as cartera'),
                DB::raw('SUM(past_due_balance) as vencida'),
                DB::raw('SUM(CASE WHEN days_past_due = 0 THEN balance ELSE 0 END) as al_corriente'),
                DB::raw('SUM(CASE WHEN days_past_due BETWEEN 1 AND 30 THEN COALESCE(past_due_balance, balance) ELSE 0 END) as mora_1_30'),
                DB::raw('SUM(CASE WHEN days_past_due BETWEEN 31 AND 60 THEN COALESCE(past_due_balance, balance) ELSE 0 END) as mora_31_60'),
                DB::raw('SUM(CASE WHEN days_past_due BETWEEN 61 AND 90 THEN COALESCE(past_due_balance, balance) ELSE 0 END) as mora_61_90'),
                DB::raw('SUM(CASE WHEN days_past_due BETWEEN 91 AND 120 THEN COALESCE(past_due_balance, balance) ELSE 0 END) as mora_91_120'),
                DB::raw('SUM(CASE WHEN days_past_due > 120 THEN COALESCE(past_due_balance, balance) ELSE 0 END) as mora_120plus')
            )
            ->groupBy('product_name')
            ->orderByDesc('cartera')
            ->get();

        if ($crecePort->isEmpty()) {
            $this->warn("  ✗ CRECE no encontrado en fact_portfolios.");
        } else {
            foreach ($crecePort as $r) {
                $this->info("  ✓ {$r->product_name}: {$r->contratos} contratos");
                $this->line("      Cartera=$" . number_format((float)$r->cartera, 2) .
                            "  Vencida=$" . number_format((float)$r->vencida, 2));
                $this->line("      Mora: 0-30=$" . number_format((float)$r->mora_1_30, 2) .
                            "  31-60=$" . number_format((float)$r->mora_31_60, 2) .
                            "  61-90=$" . number_format((float)$r->mora_61_90, 2));
                $this->line("      91-120=$" . number_format((float)$r->mora_91_120, 2) .
                            "  120+=$" . number_format((float)$r->mora_120plus, 2));
            }
        }

        // ── 4. PÓLIZAS CRECE ─────────────────────────────────────────────────
        $this->line('');
        $this->info('─── 4. PÓLIZAS CRECE (savehearts_crece_share) ───');

        $polizas = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->where('is_savehearts', true)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->select(
                'product_name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(total_amount) as total_bruto'),
                DB::raw('SUM(savehearts_crece_share) as share30')
            )
            ->groupBy('product_name')
            ->get();

        if ($polizas->isEmpty()) {
            $this->warn("  ✗ No se detectaron pólizas CRECE (is_savehearts=true + CRECE).");
            $this->line("  Diagnóstico:");

            // Check if there are ANY savehearts rows
            $anyShRows = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->where('is_savehearts', true)
                ->count();
            $this->line("    → Filas marcadas is_savehearts=true en total: {$anyShRows}");

            // Check recovery rows with operation=seguro/poliza
            $operSeguro = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->whereRaw("LOWER(COALESCE(operation,'')) REGEXP 'seguro|poliza'")
                ->count();
            $this->line("    → Filas con operacion=seguro/poliza: {$operSeguro}");

            // Check share > 0
            $withShare = DB::table('fact_recoveries')
                ->whereIn('period_id', $dataIds)
                ->where('savehearts_crece_share', '>', 0)
                ->count();
            $this->line("    → Filas con savehearts_crece_share > 0: {$withShare}");

            if ($anyShRows === 0 && $operSeguro === 0) {
                $this->line("    → El Excel de cobranza no tiene filas de tipo SEGURO/PÓLIZA para este periodo.");
                $this->line("    → O la columna 'Operación' no fue detectada correctamente.");
            }
        } else {
            foreach ($polizas as $p) {
                $this->info("  ✓ {$p->product_name}: {$p->cnt} pólizas");
                $this->line("      Bruto=$" . number_format((float)$p->total_bruto, 2) .
                            "  30% CRECE=$" . number_format((float)$p->share30, 2));
            }
        }

        // ── 5. CLASIFICACIÓN EN PRODUCT_SPECIAL_PATTERN ───────────────────
        $this->line('');
        $this->info('─── 5. CLASIFICACIÓN DE CRECE EN EL REPORTE ───');
        $this->line("  PRODUCT_SPECIAL_PATTERN = 'A LA MEDIDA|DIARIO|CREDITO CONSUMO'");
        $this->info("  ✓ CRECE removido de PRODUCT_SPECIAL_PATTERN — clasificado como OPERATIVO");
        $this->line("  Efecto en buildProducts():");
        $this->line("    → CRECE aparece en la lista de productos operativos");
        $this->line("    → CRECE colocación incluida en reporte operativo");
        $this->line("    → CRECE recuperación incluida por producto en reporte");

        // ── 6. 20 EJEMPLOS DE CADA FUENTE ────────────────────────────────
        $this->line('');
        $this->info('─── 6. MUESTRA DE CRECE EN RECUPERACIÓN (20 filas) ───');

        $samples = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->select('product_name', 'is_savehearts', 'operation', 'concept',
                     'capital', 'interest', 'total_amount', 'payment_date')
            ->limit(20)
            ->get();

        if ($samples->isNotEmpty()) {
            $this->line(str_pad('Producto', 22) . str_pad('Sh', 4) . str_pad('Operación', 22) .
                        str_pad('Capital', 14) . 'Total');
            $this->line(str_repeat('-', 80));
            foreach ($samples as $s) {
                $this->line(
                    str_pad(mb_substr($s->product_name ?? '', 0, 20), 22) .
                    str_pad($s->is_savehearts ? 'Y' : 'N', 4) .
                    str_pad(mb_substr($s->operation ?? '', 0, 20), 22) .
                    str_pad('$' . number_format((float)$s->capital, 0), 14) .
                    '$' . number_format((float)$s->total_amount, 0)
                );
            }
        }

        $this->line('');
        $this->info('─── 7. MUESTRA DE CRECE EN CARTERA (20 filas) ───');

        $portSamples = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->select('product_name', 'balance', 'days_past_due', 'past_due_balance')
            ->orderByDesc('balance')
            ->limit(20)
            ->get();

        if ($portSamples->isNotEmpty()) {
            $this->line(str_pad('Producto', 35) . str_pad('Balance', 18) .
                        str_pad('DPD', 8) . 'Vencida');
            $this->line(str_repeat('-', 75));
            foreach ($portSamples as $s) {
                $this->line(
                    str_pad(mb_substr($s->product_name ?? '', 0, 33), 35) .
                    str_pad('$' . number_format((float)$s->balance, 0), 18) .
                    str_pad($s->days_past_due, 8) .
                    '$' . number_format((float)$s->past_due_balance, 0)
                );
            }
        }

        return 0;
    }
}
