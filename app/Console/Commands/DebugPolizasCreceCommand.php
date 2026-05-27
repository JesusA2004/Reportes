<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\SaveheartsRuleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugPolizasCreceCommand extends Command
{
    protected $signature   = 'reportes:debug-polizas-crece {period_id : ID del periodo}';
    protected $description = 'Muestra filas de pólizas/savehearts CRECE: producto, contrato, concepto, operación, total, %, monto EBITDA, excluido, razón.';

    public function handle(SaveheartsRuleService $savehearts): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$periodId]
        )));

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG PÓLIZAS CRECE — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');

        // Summary counts
        $totalRows   = DB::table('fact_recoveries')->whereIn('period_id', $dataIds)->count();
        $saveheartsAll = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->where('is_savehearts', true)
            ->count();
        $creceRows   = DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->where('is_savehearts', true)
            ->where('savehearts_crece_share', '>', 0)
            ->count();
        $sumCrece30  = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->sum('savehearts_crece_share');
        $sumExcluido = (float) DB::table('fact_recoveries')
            ->whereIn('period_id', $dataIds)
            ->where('is_savehearts', true)
            ->where('savehearts_crece_share', 0)
            ->sum('total_amount');

        $this->line(sprintf(
            '  Total recuperaciones: %d  |  Savehearts/pólizas: %d  |  CRECE (30%%): %d',
            $totalRows, $saveheartsAll, $creceRows
        ));
        $this->line(sprintf(
            '  Suma EBITDA (30%%): $%s  |  Excluido no-CRECE: $%s',
            number_format($sumCrece30, 2),
            number_format($sumExcluido, 2)
        ));
        $this->line('');

        if ($saveheartsAll === 0) {
            $this->info('  <fg=green>Sin filas de pólizas/savehearts en este periodo.</>');
            $this->line('');
            return self::SUCCESS;
        }

        // ── CRECE rows (30% al EBITDA) ────────────────────────────────────────
        $this->info('── Pólizas CRECE (30% al EBITDA) ────────────────────────────────────');

        $creceDetail = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->where('r.is_savehearts', true)
            ->where('r.savehearts_crece_share', '>', 0)
            ->orderByDesc('r.savehearts_crece_share')
            ->limit(50)
            ->selectRaw("
                r.contract,
                r.product_name,
                r.promoter_name,
                COALESCE(b.name, 'Sin sucursal') AS sucursal,
                COALESCE(r.operation, '') AS operacion,
                COALESCE(r.concept, '')   AS concepto,
                r.total_amount,
                r.savehearts_crece_share,
                r.payment_date
            ")
            ->get();

        if ($creceDetail->isEmpty()) {
            $this->line('  <fg=yellow>Sin filas CRECE con share > 0.</>');
        } else {
            $headers = ['Contrato', 'Producto', 'Promotor', 'Sucursal', 'Operación', 'Concepto', 'Total', '30%', 'Excl.', 'Razón', 'Fecha'];
            $rows = $creceDetail->map(function ($r) {
                $pct     = $r->total_amount > 0 ? round($r->savehearts_crece_share / $r->total_amount * 100, 1) : 30;
                $excl    = round((float)$r->total_amount - (float)$r->savehearts_crece_share, 2);
                return [
                    mb_substr((string) $r->contract, 0, 16),
                    mb_substr((string) $r->product_name, 0, 16),
                    mb_substr((string) $r->promoter_name, 0, 20),
                    mb_substr((string) $r->sucursal, 0, 16),
                    mb_substr((string) $r->operacion, 0, 14),
                    mb_substr((string) $r->concepto, 0, 20),
                    '$' . number_format((float)$r->total_amount, 2),
                    '$' . number_format((float)$r->savehearts_crece_share, 2),
                    '$' . number_format($excl, 2),
                    "CRECE {$pct}%",
                    (string) $r->payment_date,
                ];
            })->all();
            $this->table($headers, $rows);
        }
        $this->line('');

        // ── Non-CRECE savehearts (100% excluido) ─────────────────────────────
        $this->info('── Pólizas/Seguros NO-CRECE (100% excluido) ────────────────────────');

        $nonCrece = DB::table('fact_recoveries as r')
            ->leftJoin('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.period_id', $dataIds)
            ->where('r.is_savehearts', true)
            ->where('r.savehearts_crece_share', 0)
            ->orderByDesc('r.total_amount')
            ->limit(30)
            ->selectRaw("
                r.contract,
                r.product_name,
                r.promoter_name,
                COALESCE(b.name, 'Sin sucursal') AS sucursal,
                COALESCE(r.operation, '') AS operacion,
                COALESCE(r.concept, '')   AS concepto,
                r.total_amount,
                r.payment_date
            ")
            ->get();

        if ($nonCrece->isEmpty()) {
            $this->line('  <fg=green>Sin filas no-CRECE excluidas.</>');
        } else {
            $headers = ['Contrato', 'Producto', 'Promotor', 'Sucursal', 'Operación', 'Concepto', 'Total', 'Razón', 'Fecha'];
            $rows = $nonCrece->map(fn ($r) => [
                mb_substr((string) $r->contract, 0, 16),
                mb_substr((string) $r->product_name, 0, 16),
                mb_substr((string) $r->promoter_name, 0, 20),
                mb_substr((string) $r->sucursal, 0, 16),
                mb_substr((string) $r->operacion, 0, 14),
                mb_substr((string) $r->concepto, 0, 20),
                '$' . number_format((float)$r->total_amount, 2),
                'No-CRECE → 100% excl.',
                (string) $r->payment_date,
            ])->all();
            $this->table($headers, $rows);
        }

        $this->line('');
        $this->info('── Resumen ───────────────────────────────────────────────────────────');
        $this->line(sprintf('  EBITDA Pólizas CRECE: $%s', number_format($sumCrece30, 2)));
        $this->line(sprintf('  Monto excluido (no-CRECE): $%s', number_format($sumExcluido, 2)));
        $this->line('');

        return self::SUCCESS;
    }
}
