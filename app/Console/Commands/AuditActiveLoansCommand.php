<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditActiveLoansCommand extends Command
{
    protected $signature   = 'reportes:audit-active-loans
                                {period_id}
                                {--detail : show sample contracts per vigente/vencido state}
                                {--product= : filter by product keyword}
                                {--branch= : filter by branch name keyword}';
    protected $description = 'Auditoría de préstamos activos: vigentes vs vencidos, por producto, sucursal y gestor';

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
            empty($weeklyIds) ? [] : $weeklyIds, [$period->id]
        )));

        $filterProd   = strtoupper(trim($this->option('product') ?? ''));
        $filterBranch = strtoupper(trim($this->option('branch') ?? ''));
        $detail       = (bool) $this->option('detail');

        $this->info("════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA PRÉSTAMOS ACTIVOS — {$period->label} (ID {$period->id})");
        $this->info("  Fuente: fact_portfolios (Saldos Por Cliente)");
        $this->info("  Producto: " . ($filterProd ?: 'todos') . " | Sucursal: " . ($filterBranch ?: 'todas'));
        $this->info("════════════════════════════════════════════════════════════");

        $this->line('');
        $this->info('── DEFINICIONES ──');
        $this->line('  Préstamo activo: tiene saldo/balance en el archivo de saldos');
        $this->line('  Vigente (al corriente): days_past_due = 0');
        $this->line('  Vencido: days_past_due > 0 (tiene mora)');

        $baseQ = DB::table('fact_portfolios as fp')
            ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
            ->whereIn('fp.period_id', $dataIds);
        if ($filterProd) {
            $baseQ->whereRaw("UPPER(COALESCE(fp.product_name,'')) LIKE ?", ["%{$filterProd}%"]);
        }
        if ($filterBranch) {
            $baseQ->whereRaw("UPPER(COALESCE(b.name,'')) LIKE ?", ["%{$filterBranch}%"]);
        }

        $tot = (clone $baseQ)->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN fp.days_past_due = 0 THEN 1 ELSE 0 END) as vigentes,
                SUM(CASE WHEN fp.days_past_due > 0 THEN 1 ELSE 0 END) as vencidos,
                SUM(fp.balance) as cartera_total,
                SUM(CASE WHEN fp.days_past_due = 0 THEN fp.balance ELSE 0 END) as cartera_vigente,
                SUM(CASE WHEN fp.days_past_due > 0 THEN fp.balance ELSE 0 END) as cartera_vencida,
                SUM(CASE WHEN fp.days_past_due > 0 THEN COALESCE(fp.past_due_balance,0) ELSE 0 END) as mora_total
            ')
            ->first();

        $cartera = (float)$tot->cartera_total;
        $moraPct = $cartera > 0 ? round((float)$tot->mora_total / $cartera * 100, 2) : 0;

        $this->line('');
        $this->info('── TOTALES ──');
        $this->line("  Total préstamos activos: " . number_format($tot->total));
        $this->line("    Vigentes (corriente):  " . number_format($tot->vigentes) .
                    "  (" . round($tot->vigentes / max($tot->total, 1) * 100, 1) . "%)");
        $this->line("    Vencidos (en mora):    " . number_format($tot->vencidos) .
                    "  (" . round($tot->vencidos / max($tot->total, 1) * 100, 1) . "%)");
        $this->line("  Cartera total:           $" . number_format($cartera, 2));
        $this->line("    Cartera vigente:       $" . number_format((float)$tot->cartera_vigente, 2));
        $this->line("    Cartera vencida:       $" . number_format((float)$tot->cartera_vencida, 2));
        $this->line("    Saldo vencido (mora):  $" . number_format((float)$tot->mora_total, 2) . " ({$moraPct}%)");

        // ── Por producto ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR PRODUCTO (top 15, ordenado por cartera) ──');

        $byProd = (clone $baseQ)
            ->select('fp.product_name',
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN fp.days_past_due=0 THEN 1 ELSE 0 END) as vigentes'),
                DB::raw('SUM(CASE WHEN fp.days_past_due>0 THEN 1 ELSE 0 END) as vencidos'),
                DB::raw('SUM(fp.balance) as cartera'),
                DB::raw('SUM(CASE WHEN fp.days_past_due>0 THEN COALESCE(fp.past_due_balance,0) ELSE 0 END) as mora'))
            ->groupBy('fp.product_name')
            ->orderByDesc('cartera')
            ->limit(15)
            ->get();

        $this->line(str_pad('Producto', 38) . str_pad('Total', 8) .
                    str_pad('Vigente', 10) . str_pad('Vencido', 10) .
                    str_pad('Cartera', 18) . str_pad('Mora', 17) . '% Mora/Cartera');
        $this->line(str_repeat('─', 105));
        foreach ($byProd as $p) {
            $pMora = (float)$p->cartera > 0 ? round((float)$p->mora / (float)$p->cartera * 100, 1) : 0;
            $this->line(
                str_pad(mb_substr($p->product_name ?? 'NULL', 0, 36), 38) .
                str_pad(number_format($p->total), 8) .
                str_pad(number_format($p->vigentes), 10) .
                str_pad(number_format($p->vencidos), 10) .
                str_pad('$' . number_format((float)$p->cartera, 0), 18) .
                str_pad('$' . number_format((float)$p->mora, 0), 17) .
                $pMora . '%'
            );
        }

        // ── Por sucursal ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR SUCURSAL (top 15) ──');

        $bySuc = (clone $baseQ)
            ->select(DB::raw('COALESCE(b.name,"Sin sucursal") as suc'),
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN fp.days_past_due=0 THEN 1 ELSE 0 END) as vigentes'),
                DB::raw('SUM(CASE WHEN fp.days_past_due>0 THEN 1 ELSE 0 END) as vencidos'),
                DB::raw('SUM(fp.balance) as cartera'),
                DB::raw('SUM(CASE WHEN fp.days_past_due>0 THEN COALESCE(fp.past_due_balance,0) ELSE 0 END) as mora'))
            ->groupBy('fp.branch_id', 'b.name')
            ->orderByDesc('cartera')
            ->limit(15)
            ->get();

        $this->line(str_pad('Sucursal', 28) . str_pad('Total', 8) .
                    str_pad('Vigente', 10) . str_pad('Vencido', 10) .
                    str_pad('Cartera', 18) . '% Mora');
        $this->line(str_repeat('─', 80));
        foreach ($bySuc as $s) {
            $pMora = (float)$s->cartera > 0 ? round((float)$s->mora / (float)$s->cartera * 100, 1) : 0;
            $this->line(
                str_pad(mb_substr($s->suc, 0, 26), 28) .
                str_pad(number_format($s->total), 8) .
                str_pad(number_format($s->vigentes), 10) .
                str_pad(number_format($s->vencidos), 10) .
                str_pad('$' . number_format((float)$s->cartera, 0), 18) .
                $pMora . '%'
            );
        }

        // ── Detalle (--detail) ────────────────────────────────────────────────
        if ($detail) {
            $this->line('');
            $this->info('════ MUESTRA DE CONTRATOS VENCIDOS (--detail) ════');

            $vencidos = (clone $baseQ)
                ->where('fp.days_past_due', '>', 0)
                ->select(
                    'fp.product_name', 'fp.balance', 'fp.past_due_balance',
                    'fp.days_past_due', 'fp.client_name', 'fp.promoter_name',
                    DB::raw('COALESCE(b.name,"Sin suc") as suc')
                )
                ->orderByDesc('fp.past_due_balance')
                ->limit(15)
                ->get();

            $this->line(str_pad('Producto', 18) . str_pad('Cliente', 25) . str_pad('Promotor', 18) .
                        str_pad('Sucursal', 18) . str_pad('DPD', 7) . str_pad('Balance', 14) . 'Vencido');
            $this->line(str_repeat('─', 105));
            foreach ($vencidos as $v) {
                $this->line(
                    str_pad(mb_substr($v->product_name ?? '?', 0, 16), 18) .
                    str_pad(mb_substr($v->client_name ?? '?', 0, 23), 25) .
                    str_pad(mb_substr($v->promoter_name ?? '?', 0, 16), 18) .
                    str_pad(mb_substr($v->suc, 0, 16), 18) .
                    str_pad($v->days_past_due, 7) .
                    str_pad('$' . number_format((float)$v->balance, 0), 14) .
                    '$' . number_format((float)$v->past_due_balance, 0)
                );
            }
        }

        return 0;
    }
}
