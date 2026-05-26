<?php

namespace App\Console\Commands;

use App\Models\Recovery;
use Illuminate\Console\Command;

class DebugSaveheartsCommand extends Command
{
    protected $signature   = 'reportes:debug-savehearts {period_id}';
    protected $description = 'Muestra estadísticas de filas Savehearts/seguros en fact_recoveries para un periodo.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');

        $total      = Recovery::where('period_id', $periodId)->count();
        $savehearts = Recovery::where('period_id', $periodId)->where('is_savehearts', true)->count();
        $crece      = Recovery::where('period_id', $periodId)
            ->where('is_savehearts', true)
            ->where('savehearts_crece_share', '>', 0)
            ->count();
        $nonCrece   = $savehearts - $crece;

        if ($total === 0) {
            $this->warn("No hay registros en fact_recoveries para el periodo {$periodId}.");
            $this->line('Asegúrate de haber importado los archivos con php artisan reportes:reprocess-period.');
            return 1;
        }

        $this->line('');
        $this->info("=== Savehearts — Periodo {$periodId} ===");
        $this->line('');
        $this->line(sprintf('  Total movimientos            : %d', $total));
        $this->line(sprintf('  Savehearts/seguros (total)   : %d (%.1f%%)', $savehearts, $total ? $savehearts / $total * 100 : 0));
        $this->line(sprintf('    – No CRECE (excluidos)     : %d', $nonCrece));
        $this->line(sprintf('    – CRECE (30%% a EBITDA)     : %d', $crece));

        $sumNormal     = Recovery::where('period_id', $periodId)->where('is_savehearts', false)->sum('total_amount');
        $sumShCrece    = Recovery::where('period_id', $periodId)->where('is_savehearts', true)->sum('savehearts_crece_share');
        $sumShNonCrece = Recovery::where('period_id', $periodId)
            ->where('is_savehearts', true)
            ->where('savehearts_crece_share', 0)
            ->sum('total_amount');

        $this->line('');
        $this->line(sprintf('  Recuperación normal (total_amount, no Savehearts) : $ %s', number_format((float) $sumNormal, 2)));
        $this->line(sprintf('  Ingreso Savehearts CRECE 30%%                      : $ %s', number_format((float) $sumShCrece, 2)));
        $this->line(sprintf('  Total Savehearts no CRECE zeroed                   : $ %s (excluido)', number_format((float) $sumShNonCrece, 2)));

        $this->line('');

        if ($crece > 0) {
            $this->line('  Detalle CRECE Savehearts (top 10 por monto):');
            $rows = Recovery::where('period_id', $periodId)
                ->where('is_savehearts', true)
                ->where('savehearts_crece_share', '>', 0)
                ->orderByDesc('savehearts_crece_share')
                ->limit(10)
                ->get(['contract', 'promoter_name', 'product_name', 'total_amount', 'savehearts_crece_share', 'payment_date']);

            $headers = ['Contrato', 'Promotor', 'Producto', 'Total', '30% EBITDA', 'Fecha'];
            $tableData = $rows->map(fn ($r) => [
                $r->contract ?? '–',
                mb_substr($r->promoter_name ?? '–', 0, 20),
                mb_substr($r->product_name ?? '–', 0, 15),
                number_format((float) $r->total_amount, 2),
                number_format((float) $r->savehearts_crece_share, 2),
                $r->payment_date?->format('Y-m-d') ?? '–',
            ])->toArray();

            $this->table($headers, $tableData);
        }

        if ($nonCrece > 0) {
            $this->line('');
            $this->line('  Detalle No-CRECE Savehearts (top 10 — excluidos de métricas):');
            $rows = Recovery::where('period_id', $periodId)
                ->where('is_savehearts', true)
                ->where('savehearts_crece_share', 0)
                ->orderByDesc('total_amount')
                ->limit(10)
                ->get(['contract', 'promoter_name', 'product_name', 'operation', 'concept', 'total_amount', 'payment_date']);

            $headers = ['Contrato', 'Promotor', 'Producto', 'Operación', 'Concepto', 'Total orig.', 'Fecha'];
            $tableData = $rows->map(fn ($r) => [
                $r->contract ?? '–',
                mb_substr($r->promoter_name ?? '–', 0, 20),
                mb_substr($r->product_name ?? '–', 0, 15),
                mb_substr($r->operation ?? '–', 0, 20),
                mb_substr($r->concept ?? '–', 0, 25),
                number_format((float) $r->total_amount, 2),
                $r->payment_date?->format('Y-m-d') ?? '–',
            ])->toArray();

            $this->table($headers, $tableData);
        }

        $this->line('');

        return 0;
    }
}
