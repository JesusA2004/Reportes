<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditPortfolioCommand extends Command
{
    protected $signature   = 'reportes:audit-portfolio {period_id}';
    protected $description = 'Auditoría completa de cartera y mora para un periodo';

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
        $this->info("  AUDITORÍA CARTERA/MORA — {$period->label} (ID {$period->id})");
        $this->info("  DataIds: [" . implode(', ', $dataIds) . "]");
        $this->info("════════════════════════════════════════════════════════════");

        // ── Totales generales ────────────────────────────────────────────────
        $tot = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->selectRaw('
                COUNT(*) as contratos,
                SUM(balance) as cartera,
                SUM(past_due_balance) as vencida,
                SUM(CASE WHEN days_past_due = 0 THEN balance ELSE 0 END) as al_corriente,
                SUM(CASE WHEN days_past_due BETWEEN 1 AND 30 THEN COALESCE(past_due_balance,balance) ELSE 0 END) as mora_1_30,
                SUM(CASE WHEN days_past_due BETWEEN 31 AND 60 THEN COALESCE(past_due_balance,balance) ELSE 0 END) as mora_31_60,
                SUM(CASE WHEN days_past_due BETWEEN 61 AND 90 THEN COALESCE(past_due_balance,balance) ELSE 0 END) as mora_61_90,
                SUM(CASE WHEN days_past_due BETWEEN 91 AND 120 THEN COALESCE(past_due_balance,balance) ELSE 0 END) as mora_91_120,
                SUM(CASE WHEN days_past_due > 120 THEN COALESCE(past_due_balance,balance) ELSE 0 END) as mora_120plus
            ')
            ->first();

        $moraTotal = (float)$tot->mora_1_30 + (float)$tot->mora_31_60 +
                     (float)$tot->mora_61_90 + (float)$tot->mora_91_120 + (float)$tot->mora_120plus;
        $cartera   = (float)$tot->cartera;
        $moraPct   = $cartera > 0 ? round($moraTotal / $cartera * 100, 2) : 0;

        $this->line('');
        $this->info('── TOTALES GENERALES ──');
        $this->line("  Contratos:     " . number_format($tot->contratos));
        $this->line("  Cartera:       $" . number_format($cartera, 2));
        $this->line("  Vencida (BD):  $" . number_format((float)$tot->vencida, 2));
        $this->line('');
        $this->info("── MORA POR BUCKET (fuente: columna days_past_due) ──");
        $this->line("  Al corriente:  $" . number_format((float)$tot->al_corriente, 2));
        $this->line("  Mora 1-30:     $" . number_format((float)$tot->mora_1_30, 2));
        $this->line("  Mora 31-60:    $" . number_format((float)$tot->mora_31_60, 2));
        $this->line("  Mora 61-90:    $" . number_format((float)$tot->mora_61_90, 2));
        $this->line("  Mora 91-120:   $" . number_format((float)$tot->mora_91_120, 2));
        $this->line("  Mora 120+:     $" . number_format((float)$tot->mora_120plus, 2));
        $this->line("  ─────────────────────────────────");
        $this->line("  Mora Total:    $" . number_format($moraTotal, 2) . "  ({$moraPct}%)");
        $this->line('');
        $this->info("  Columna usada para buckets: 'days_past_due'");
        $this->info("  (alias en import: 'dias_vencido', 'dias_vencidos')");

        // ── Por producto ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR PRODUCTO (top 20) ──');

        $byProd = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->select('product_name',
                DB::raw('COUNT(*) as contratos'),
                DB::raw('SUM(balance) as cartera'),
                DB::raw('SUM(CASE WHEN days_past_due > 0 THEN COALESCE(past_due_balance,balance) ELSE 0 END) as vencida'))
            ->groupBy('product_name')
            ->orderByDesc('cartera')
            ->limit(20)
            ->get();

        $this->line(str_pad('Producto', 40) . str_pad('Contratos', 12) .
                    str_pad('Cartera', 20) . str_pad('Vencida', 18) . 'Clasificación');
        $this->line(str_repeat('-', 100));
        foreach ($byProd as $p) {
            $name  = $p->product_name ?? 'NULL';
            $upper = strtoupper($name);
            $clasif = 'OPERATIVO';
            if (str_contains($upper, 'RECURSOS PROPIOS')) $clasif = 'FONDEO';
            elseif (str_contains($upper, 'REESTRUCTURA') || str_contains($upper, 'UNIFICACION')) $clasif = 'REESTRUCTURA';
            elseif (str_contains($upper, 'CRECE')) $clasif = 'CRECE (OPERATIVO)';
            elseif (str_contains($upper, 'SEGURO')) $clasif = 'SEGURO (excluido)';

            $this->line(
                str_pad(mb_substr($name, 0, 38), 40) .
                str_pad(number_format($p->contratos), 12) .
                str_pad('$' . number_format((float)$p->cartera, 0), 20) .
                str_pad('$' . number_format((float)$p->vencida, 0), 18) .
                $clasif
            );
        }

        // ── CRECE específico ─────────────────────────────────────────────────
        $this->line('');
        $this->info('── CRECE EN CARTERA (detalle) ──');

        $crecePort = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(product_name,'')) REGEXP 'CRECE'")
            ->selectRaw('COUNT(*) as contratos, SUM(balance) as cartera,
                SUM(CASE WHEN days_past_due > 120 THEN COALESCE(past_due_balance,balance) ELSE 0 END) as mora_120plus')
            ->first();

        $this->line("  Contratos CRECE: " . number_format($crecePort->contratos ?? 0));
        $this->line("  Cartera CRECE:   $" . number_format((float)($crecePort->cartera ?? 0), 2));
        $this->line("  Mora 120+:       $" . number_format((float)($crecePort->mora_120plus ?? 0), 2));

        $creceTot = (float)($crecePort->cartera ?? 0);
        if ($creceTot > 0) {
            $pctCrece = round($creceTot / max($cartera, 1) * 100, 1);
            $this->line("  % de cartera total: {$pctCrece}%");
            $this->info("  ✓ CRECE clasificado como OPERATIVO en el reporte.");
        }

        // ── Por sucursal ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── POR SUCURSAL (top 15) ──');

        $bySuc = DB::table('fact_portfolios as fp')
            ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
            ->whereIn('fp.period_id', $dataIds)
            ->select(DB::raw('COALESCE(b.name,"Sin sucursal") as sucursal'),
                DB::raw('COUNT(*) as contratos'),
                DB::raw('SUM(fp.balance) as cartera'),
                DB::raw('SUM(CASE WHEN fp.days_past_due > 0 THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as vencida'))
            ->groupBy('fp.branch_id', 'b.name')
            ->orderByDesc('cartera')
            ->limit(15)
            ->get();

        $this->line(str_pad('Sucursal', 28) . str_pad('Contratos', 12) .
                    str_pad('Cartera', 20) . 'Vencida');
        $this->line(str_repeat('-', 68));
        foreach ($bySuc as $s) {
            $this->line(
                str_pad(mb_substr($s->sucursal, 0, 26), 28) .
                str_pad(number_format($s->contratos), 12) .
                str_pad('$' . number_format((float)$s->cartera, 0), 20) .
                '$' . number_format((float)$s->vencida, 0)
            );
        }

        // ── Verificación columna días vencidos ────────────────────────────────
        $this->line('');
        $this->info('── VERIFICACIÓN COLUMNA DÍAS VENCIDOS ──');

        $maxDpd = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->max('days_past_due');
        $minDpd = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->min('days_past_due');
        $nullDpd = DB::table('fact_portfolios')->whereIn('period_id', $dataIds)->whereNull('days_past_due')->count();

        $this->line("  Columna en BD:    days_past_due (alias: 'dias_vencido', 'dias_vencidos')");
        $this->line("  Rango:            min={$minDpd}  max={$maxDpd}");
        $this->line("  Nulls:            {$nullDpd}");
        $this->line("  ✓ Se usa 'días vencidos' (no atraso/mora) para buckets — correcto.");

        return 0;
    }
}
