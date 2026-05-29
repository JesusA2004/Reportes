<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditMoraCommand extends Command
{
    protected $signature   = 'reportes:audit-mora
                                {period_id}
                                {--product= : filter by product keyword}
                                {--branch= : filter by branch name keyword}
                                {--detail : show per-bucket detail with sample contracts}';
    protected $description = 'Auditoría de mora — buckets, capital/interés/impuesto, % cartera, muestras por bucket';

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
        $this->info("  AUDITORÍA MORA — {$period->label} (ID {$period->id})");
        $this->info("  Columna: days_past_due | Buckets: 0 / 1-30 / 31-60 / 61-90 / 91-120 / 120+");
        $this->info("  Filtro producto: " . ($filterProd ?: 'todos') . " | sucursal: " . ($filterBranch ?: 'todas'));
        $this->info("════════════════════════════════════════════════════════════");

        $q = DB::table('fact_portfolios as fp')
            ->leftJoin('branches as b', 'fp.branch_id', '=', 'b.id')
            ->whereIn('fp.period_id', $dataIds);

        if ($filterProd !== '') {
            $q->whereRaw("UPPER(COALESCE(fp.product_name,'')) LIKE ?", ["%{$filterProd}%"]);
        }
        if ($filterBranch !== '') {
            $q->whereRaw("UPPER(COALESCE(b.name,'')) LIKE ?", ["%{$filterBranch}%"]);
        }

        // ── Tabla de buckets descriptiva ──────────────────────────────────────
        $this->line('');
        $this->info('════ TABLA DE MORA POR BUCKET ════');

        $tot = (clone $q)->selectRaw('
            COUNT(*) as contratos,
            SUM(fp.balance) as cartera,
            SUM(fp.past_due_balance) as vencida,
            SUM(fp.interes_atrasado) as interes_atrasado,
            SUM(fp.impuesto_atrasado) as impuesto_atrasado,
            SUM(fp.cargos_calendario_atrasado) as cargos_atrasados,
            SUM(CASE WHEN fp.days_past_due = 0 THEN fp.balance ELSE 0 END) as al_corriente_bal,
            SUM(CASE WHEN fp.days_past_due = 0 THEN 1 ELSE 0 END) as al_corriente_cnt,
            SUM(CASE WHEN fp.days_past_due BETWEEN 1  AND 30  THEN 1 ELSE 0 END) as m1_30_cnt,
            SUM(CASE WHEN fp.days_past_due BETWEEN 31 AND 60  THEN 1 ELSE 0 END) as m31_60_cnt,
            SUM(CASE WHEN fp.days_past_due BETWEEN 61 AND 90  THEN 1 ELSE 0 END) as m61_90_cnt,
            SUM(CASE WHEN fp.days_past_due BETWEEN 91 AND 120 THEN 1 ELSE 0 END) as m91_120_cnt,
            SUM(CASE WHEN fp.days_past_due > 120 THEN 1 ELSE 0 END) as m120p_cnt,
            SUM(CASE WHEN fp.days_past_due BETWEEN 1  AND 30  THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as m1_30,
            SUM(CASE WHEN fp.days_past_due BETWEEN 31 AND 60  THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as m31_60,
            SUM(CASE WHEN fp.days_past_due BETWEEN 61 AND 90  THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as m61_90,
            SUM(CASE WHEN fp.days_past_due BETWEEN 91 AND 120 THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as m91_120,
            SUM(CASE WHEN fp.days_past_due > 120 THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as m120plus
        ')->first();

        $cartera   = (float)($tot->cartera ?? 0);
        $buckets = [
            ['Al corriente',  (int)($tot->al_corriente_cnt ?? 0), (float)($tot->al_corriente_bal ?? 0), 0.0, false],
            ['Mora 1-30',     (int)($tot->m1_30_cnt ?? 0),        (float)($tot->m1_30 ?? 0),            0.0, true],
            ['Mora 31-60',    (int)($tot->m31_60_cnt ?? 0),       (float)($tot->m31_60 ?? 0),           0.0, true],
            ['Mora 61-90',    (int)($tot->m61_90_cnt ?? 0),       (float)($tot->m61_90 ?? 0),           0.0, true],
            ['Mora 91-120',   (int)($tot->m91_120_cnt ?? 0),      (float)($tot->m91_120 ?? 0),          0.0, true],
            ['Mora 120+',     (int)($tot->m120p_cnt ?? 0),        (float)($tot->m120plus ?? 0),         0.0, true],
        ];

        $moraTotal = (float)($tot->m1_30 ?? 0) + (float)($tot->m31_60 ?? 0) + (float)($tot->m61_90 ?? 0) +
                     (float)($tot->m91_120 ?? 0) + (float)($tot->m120plus ?? 0);
        $moraPct   = $cartera > 0 ? round($moraTotal / $cartera * 100, 2) : 0;

        // Header
        $this->line(str_pad('Bucket', 16) . str_pad('Contratos', 12) . str_pad('Vencido', 20) .
                    str_pad('% Cartera', 12) . str_pad('% Mora', 10) . 'Nota');
        $this->line(str_repeat('─', 85));

        foreach ($buckets as [$label, $cnt, $vencido, $dummy, $isMora]) {
            $pctCartera = $cartera > 0 ? round($vencido / $cartera * 100, 2) : 0;
            $pctMora    = $moraTotal > 0 && $isMora ? round($vencido / $moraTotal * 100, 2) : 0;
            $nota       = $isMora && $pctCartera > 5 ? '⚠ Alta' : '';
            $this->line(
                str_pad($label, 16) .
                str_pad(number_format($cnt), 12) .
                str_pad('$' . number_format($vencido, 0), 20) .
                str_pad($pctCartera . '%', 12) .
                str_pad(($isMora ? $pctMora . '%' : '—'), 10) .
                $nota
            );
        }

        $this->line(str_repeat('─', 85));
        $this->line(
            str_pad('TOTAL CARTERA', 16) .
            str_pad(number_format((int)($tot->contratos ?? 0)), 12) .
            str_pad('$' . number_format($cartera, 0), 20)
        );
        $this->line(
            str_pad('MORA TOTAL', 16) .
            str_pad('', 12) .
            str_pad('$' . number_format($moraTotal, 0), 20) .
            str_pad($moraPct . '%', 12) .
            '← % mora global'
        );

        // ── Componentes de mora ───────────────────────────────────────────────
        $this->line('');
        $this->info('── COMPONENTES DE CARTERA VENCIDA ──');
        $interesAtrasado  = (float)($tot->interes_atrasado ?? 0);
        $impuestoAtrasado = (float)($tot->impuesto_atrasado ?? 0);
        $cargosAtrasados  = (float)($tot->cargos_atrasados ?? 0);
        $capitalVencido   = (float)($tot->vencida ?? 0);

        $this->line(str_pad('Componente', 30) . str_pad('Tabla', 20) . str_pad('Columna', 25) . 'Monto');
        $this->line(str_repeat('─', 85));
        $this->line(str_pad('Capital vencido',        30) . str_pad('fact_portfolios', 20) . str_pad('past_due_balance', 25) . '$' . number_format($capitalVencido, 2));
        $this->line(str_pad('Interés atrasado',       30) . str_pad('fact_portfolios', 20) . str_pad('interes_atrasado', 25) . '$' . number_format($interesAtrasado, 2));
        $this->line(str_pad('Impuesto atrasado',      30) . str_pad('fact_portfolios', 20) . str_pad('impuesto_atrasado', 25) . '$' . number_format($impuestoAtrasado, 2));
        $this->line(str_pad('Cargos atrasados',       30) . str_pad('fact_portfolios', 20) . str_pad('cargos_calendario_atrasado', 25) . '$' . number_format($cargosAtrasados, 2));

        // ── Por producto ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── MORA POR PRODUCTO (top 15) ──');

        $byProd = (clone $q)
            ->select('fp.product_name', DB::raw('COUNT(*) as c'),
                DB::raw('SUM(fp.balance) as cartera'),
                DB::raw('SUM(CASE WHEN fp.days_past_due > 0 THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as mora'),
                DB::raw('SUM(CASE WHEN fp.days_past_due > 120 THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as m120p'))
            ->groupBy('fp.product_name')
            ->orderByDesc('mora')
            ->limit(15)
            ->get();

        $this->line(str_pad('Producto', 35) . str_pad('Cttos', 8) .
                    str_pad('Cartera', 18) . str_pad('Mora', 18) . '% Mora / Cartera');
        $this->line(str_repeat('─', 88));
        foreach ($byProd as $p) {
            $pMora = (float)$p->cartera > 0 ? round((float)$p->mora / (float)$p->cartera * 100, 1) : 0;
            $this->line(
                str_pad(mb_substr($p->product_name ?? 'NULL', 0, 33), 35) .
                str_pad(number_format($p->c), 8) .
                str_pad('$' . number_format((float)$p->cartera, 0), 18) .
                str_pad('$' . number_format((float)$p->mora, 0), 18) .
                $pMora . '%'
            );
        }

        // ── Por sucursal ─────────────────────────────────────────────────────
        $this->line('');
        $this->info('── MORA POR SUCURSAL (top 15) ──');

        $bySuc = (clone $q)
            ->select(DB::raw('COALESCE(b.name,"Sin sucursal") as suc'),
                DB::raw('COUNT(*) as c'),
                DB::raw('SUM(fp.balance) as cartera'),
                DB::raw('SUM(CASE WHEN fp.days_past_due > 0 THEN COALESCE(fp.past_due_balance,fp.balance) ELSE 0 END) as mora'))
            ->groupBy('fp.branch_id', 'b.name')
            ->orderByDesc('mora')
            ->limit(15)
            ->get();

        $this->line(str_pad('Sucursal', 28) . str_pad('Cttos', 8) .
                    str_pad('Cartera', 18) . str_pad('Mora', 18) . '% Mora');
        $this->line(str_repeat('─', 80));
        foreach ($bySuc as $s) {
            $pMora = (float)$s->cartera > 0 ? round((float)$s->mora / (float)$s->cartera * 100, 1) : 0;
            $this->line(
                str_pad(mb_substr($s->suc, 0, 26), 28) .
                str_pad(number_format($s->c), 8) .
                str_pad('$' . number_format((float)$s->cartera, 0), 18) .
                str_pad('$' . number_format((float)$s->mora, 0), 18) .
                $pMora . '%'
            );
        }

        // ── Detalle por bucket (--detail) ─────────────────────────────────────
        if ($detail) {
            $this->line('');
            $this->info('════ DETALLE POR BUCKET (muestras) ════');

            $bucketDefs = [
                ['Al corriente', 'fp.days_past_due = 0'],
                ['Mora 1-30',    'fp.days_past_due BETWEEN 1 AND 30'],
                ['Mora 31-60',   'fp.days_past_due BETWEEN 31 AND 60'],
                ['Mora 61-90',   'fp.days_past_due BETWEEN 61 AND 90'],
                ['Mora 91-120',  'fp.days_past_due BETWEEN 91 AND 120'],
                ['Mora 120+',    'fp.days_past_due > 120'],
            ];

            foreach ($bucketDefs as [$label, $cond]) {
                $samples = (clone $q)
                    ->whereRaw($cond)
                    ->select(
                        'fp.product_name', 'fp.balance', 'fp.past_due_balance',
                        'fp.days_past_due', 'fp.client_name',
                        DB::raw('COALESCE(b.name,"Sin suc") as suc')
                    )
                    ->orderByDesc('fp.past_due_balance')
                    ->limit(5)
                    ->get();

                $cnt  = (clone $q)->whereRaw($cond)->count();
                $vmor = (clone $q)->whereRaw($cond)->sum('fp.past_due_balance');
                $this->line('');
                $this->line("  ── {$label} ({$cnt} contratos, vencido $" . number_format((float)$vmor, 2) . ") ──");
                foreach ($samples as $s) {
                    $this->line(sprintf(
                        "    %s | dpd=%d | saldo=$%s | vencido=$%s | suc=%s",
                        mb_substr($s->product_name ?? '?', 0, 18),
                        $s->days_past_due,
                        number_format((float)$s->balance, 0),
                        number_format((float)$s->past_due_balance, 0),
                        mb_substr($s->suc, 0, 20)
                    ));
                }
            }
        }

        // ── Verificar que no existan buckets 121-180 / 180+ en la UI ─────────
        $this->line('');
        $this->info('── VERIFICACIÓN DE BUCKETS ──');
        $m121_180 = (clone $q)->whereBetween('fp.days_past_due', [121, 180])->count();
        $m180plus = (clone $q)->where('fp.days_past_due', '>', 180)->count();
        if ($m121_180 > 0 || $m180plus > 0) {
            $this->warn("  DATOS: 121-180={$m121_180} contratos, 180+={$m180plus} contratos");
            $this->warn("  Estos DEBEN agruparse en '120+' en la UI y PDF — no mostrarse separados.");
        } else {
            $this->info("  ✓ No hay contratos con dpd > 120 que necesiten verificación especial.");
        }
        $this->line("  Buckets válidos en UI: Al corriente | Mora 1-30 | 31-60 | 61-90 | 91-120 | 120+");
        $this->line("  Buckets PROHIBIDOS: 121-180, 180+ (deben estar dentro de 120+)");

        return 0;
    }
}
