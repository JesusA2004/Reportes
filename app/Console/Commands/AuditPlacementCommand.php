<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPlacementCommand extends Command
{
    protected $signature   = 'reportes:audit-placement {period_id}';
    protected $description = 'Auditoría completa de colocación/ministraciones para un periodo';

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
        $this->info("  AUDITORÍA COLOCACIÓN — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("════════════════════════════════════════════════════════════");

        $total   = DB::table('fact_placements')->whereIn('period_id', $dataIds)->count();
        $amount  = DB::table('fact_placements')->whereIn('period_id', $dataIds)->sum('amount');

        $this->line('');
        $this->info('── TOTALES GENERALES ──');
        $this->line("  Filas totales: " . number_format($total));
        $this->line("  Monto total:   $" . number_format((float)$amount, 2));

        // ── Por producto ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR PRODUCTO ──');

        $byProd = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->select('product_name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(amount) as total'))
            ->groupBy('product_name')
            ->orderByDesc('total')
            ->get();

        $operPatterns = ['RECURSOS PROPIOS', 'REESTRUCTURA', 'UNIFICACION', 'MIGRACION', 'SEGURO', 'CRECE'];

        $this->line(str_pad('Producto', 40) . str_pad('Filas', 8) .
                    str_pad('Monto', 20) . 'Clasificación');
        $this->line(str_repeat('-', 85));
        foreach ($byProd as $p) {
            $name = $p->product_name ?? 'NULL/Sin producto';
            $upper = strtoupper($name);
            $clasif = 'OPERATIVO';
            if (str_contains($upper, 'RECURSOS PROPIOS'))   $clasif = 'FONDEO (excluido)';
            elseif (str_contains($upper, 'REESTRUCTURA'))    $clasif = 'REESTRUCTURA (excluido)';
            elseif (str_contains($upper, 'UNIFICACION'))     $clasif = 'UNIFICACION (excluido)';
            elseif (str_contains($upper, 'MIGRACION'))       $clasif = 'MIGRACIÓN (excluido)';
            elseif (str_contains($upper, 'SEGURO'))          $clasif = 'SEGURO (excluido)';
            elseif (str_contains($upper, 'CRECE'))           $clasif = 'CRECE (OPERATIVO)';
            elseif ($name === 'NULL/Sin producto')           $clasif = 'SIN PRODUCTO';

            $this->line(
                str_pad(mb_substr($name, 0, 38), 40) .
                str_pad(number_format($p->cnt), 8) .
                str_pad('$' . number_format((float)$p->total, 0), 20) .
                $clasif
            );
        }

        // ── Por sucursal ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR SUCURSAL ──');

        $byBranch = DB::table('fact_placements as fp')
            ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
            ->whereIn('fp.period_id', $dataIds)
            ->select(DB::raw('COALESCE(b.name,"Sin sucursal") as sucursal'),
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(fp.amount) as total'))
            ->groupBy('fp.branch_id', 'b.name')
            ->orderByDesc('total')
            ->get();

        foreach ($byBranch as $b) {
            $this->line("  " . str_pad(mb_substr($b->sucursal, 0, 30), 32) .
                        str_pad(number_format($b->cnt), 8) .
                        '$' . number_format((float)$b->total, 2));
        }

        // ── Registros excluidos del reporte final ────────────────────────────
        $this->line('');
        $this->info('── REGISTROS EXCLUIDOS DEL REPORTE (no aparecen como colocación neta) ──');

        $excluded = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("product_name REGEXP 'RECURSOS PROPIOS|REESTRUCTURA|UNIFICACION|MIGRACION|SEGURO'")
            ->select('product_name',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(amount) as total'))
            ->groupBy('product_name')
            ->get();

        if ($excluded->isEmpty()) {
            $this->line("  No hay registros excluidos (solo RECURSOS PROPIOS/reestructuras).");
        } else {
            foreach ($excluded as $e) {
                $this->warn("  ✗ EXCLUIDO: {$e->product_name}: {$e->cnt} ops, $" . number_format((float)$e->total, 2));
            }
        }

        // ── RECURSOS PROPIOS diagnóstico ──────────────────────────────────────
        $rrpp = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("product_name REGEXP 'RECURSOS PROPIOS'")
            ->select('product_name', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount) as total'))
            ->groupBy('product_name')
            ->get();

        $this->line('');
        $this->info('── DIAGNÓSTICO RECURSOS PROPIOS ──');
        if ($rrpp->isEmpty()) {
            $this->line("  ✓ RECURSOS PROPIOS no encontrado en colocación.");
        } else {
            foreach ($rrpp as $r) {
                $this->warn("  ! {$r->product_name}: {$r->cnt} ops, $" . number_format((float)$r->total, 2));
            }
            $this->line("  → RECURSOS PROPIOS se excluye correctamente del reporte de colocación neta.");
            $this->line("  → Ver sección 'Préstamos intersucursales' / fondeo para este dato.");
        }

        // ── CRECE diagnóstico ─────────────────────────────────────────────────
        $creceCount = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->count();

        $this->line('');
        $this->info('── DIAGNÓSTICO CRECE EN COLOCACIÓN ──');
        if ($creceCount === 0) {
            $this->line("  ✓ No hay colocación CRECE en este periodo.");
        } else {
            $this->info("  ✓ CRECE encontrado en colocación: {$creceCount} filas — correctamente clasificado como OPERATIVO");
        }

        // ── Filas sin sucursal ────────────────────────────────────────────────
        $noBranch = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->whereNull('branch_id')
            ->count();
        if ($noBranch > 0) {
            $this->line('');
            $this->warn("  ⚠ {$noBranch} filas sin branch_id — sucursal no resuelta por código de promotor");
        }

        return 0;
    }
}
