<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditRecoveryCommand extends Command
{
    protected $signature   = 'reportes:audit-recovery {period_id}';
    protected $description = 'Auditoría completa de recuperación/cobranza para un periodo';

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
        $this->info("  AUDITORÍA RECUPERACIÓN — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("════════════════════════════════════════════════════════════");

        // ── Totales generales ────────────────────────────────────────────────
        $tot = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('COUNT(*) as total_rows,
                SUM(capital) as capital, SUM(interest) as interes, SUM(tax) as impuesto,
                SUM(charges) as cargos, SUM(total_amount) as total,
                SUM(CASE WHEN is_savehearts THEN 1 ELSE 0 END) as sh_rows,
                SUM(savehearts_crece_share) as sh_crece30')
            ->first();

        $this->line('');
        $this->info('── TOTALES GENERALES ──');
        $this->line("  Filas totales: " . number_format($tot->total_rows));
        $this->line("  Capital:       $" . number_format((float)$tot->capital, 2));
        $this->line("  Interés:       $" . number_format((float)$tot->interes, 2));
        $this->line("  Impuesto:      $" . number_format((float)$tot->impuesto, 2));
        $this->line("  Cargos:        $" . number_format((float)$tot->cargos, 2));
        $this->line("  Total:         $" . number_format((float)$tot->total, 2));
        $this->line("  Savehearts:    " . number_format($tot->sh_rows) . " filas");
        $this->line("  CRECE 30%:     $" . number_format((float)$tot->sh_crece30, 2));

        // ── Por producto ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR PRODUCTO ──');

        $byProd = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->select('product_name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(capital) as capital'),
                DB::raw('SUM(total_amount) as total'))
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->get();

        $this->line(str_pad('Producto', 38) . str_pad('Filas', 8) .
                    str_pad('Capital', 18) . 'Total');
        $this->line(str_repeat('-', 80));
        foreach ($byProd as $p) {
            $label = mb_substr($p->product_name ?? 'NULL', 0, 36);
            $isCrece = str_contains(strtoupper($p->product_name ?? ''), 'CRECE');
            $marker = $isCrece ? ' [CRECE]' : '';
            $this->line(
                str_pad($label . $marker, 38) .
                str_pad(number_format($p->cnt), 8) .
                str_pad('$' . number_format((float)$p->capital, 0), 18) .
                '$' . number_format((float)$p->total, 0)
            );
        }

        // ── Por sucursal ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR SUCURSAL ──');

        $byBranch = DB::table('fact_recoveries as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->whereIn('fr.period_id', $dataIds)
            ->select(DB::raw('COALESCE(b.name,"Sin sucursal") as sucursal'),
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(fr.total_amount) as total'))
            ->groupBy('fr.branch_id', 'b.name')
            ->orderByDesc('total')
            ->limit(20)
            ->get();

        foreach ($byBranch as $b) {
            $this->line("  " . str_pad($b->sucursal, 30) . " {$b->cnt} filas, $" . number_format((float)$b->total, 2));
        }

        // ── Por operación/concepto ───────────────────────────────────────────
        $this->line('');
        $this->info('── POR OPERACIÓN (top 15) ──');

        $byOp = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->select('operation',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(total_amount) as total'))
            ->groupBy('operation')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        foreach ($byOp as $o) {
            $isSh = in_array(strtolower(trim($o->operation ?? '')), ['seguro', 'seguros', 'poliza', 'polizas']);
            $marker = $isSh ? ' [SAVEHEARTS candidato]' : '';
            $this->line("  " . str_pad(mb_substr($o->operation ?? 'NULL', 0, 35), 37) .
                        "{$o->cnt} filas, $" . number_format((float)$o->total, 0) . $marker);
        }

        // ── Diagnóstico CRECE ────────────────────────────────────────────────
        $this->line('');
        $this->info('── DIAGNÓSTICO: ¿POR QUÉ CRECE NO APARECE EN EL REPORTE? ──');

        $creceTotal = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->count();

        if ($creceTotal > 0) {
            $this->info("  ✓ CRECE en fact_recoveries: {$creceTotal} filas — incluido como recuperación operativa CRECE.");
        } else {
            $this->line("  CRECE no encontrado en fact_recoveries para este periodo.");
        }

        // ── Filas sin producto ────────────────────────────────────────────────
        $noProduct = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->whereNull('product_name')
            ->count();
        if ($noProduct > 0) {
            $this->line('');
            $this->warn("  ⚠ {$noProduct} filas en fact_recoveries sin product_name");
            $this->line("    → Verificar que la columna 'Producto de crédito' exista en el Excel de cobranza");
        }

        return 0;
    }
}
