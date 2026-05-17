<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugMoraRawCommand extends Command
{
    protected $signature   = 'reportes:debug-mora-raw {period_id : ID del periodo mensual} {--cutoff= : Fecha de corte DPD (YYYY-MM-DD, default=ultimo dia del periodo)}';
    protected $description = 'Mora detallada: buckets raw del archivo vs ajuste DPD a fecha de corte del período, por sucursal.';

    private const TARGETS = [
        '0-30'   => 1_057_765.00,
        '31-60'  =>   926_001.80,
        '61-90'  =>   913_414.71,
        '91-120' =>   816_584.91,
        '120+'   => 10_259_891.95,
    ];

    public function handle(): int
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
            [$period->id]
        )));

        // Determine cutoff date for DPD recalculation
        $cutoffStr = $this->option('cutoff');
        if (!$cutoffStr && $period->end_date ?? null) {
            $cutoffStr = $period->end_date;
        }
        if (!$cutoffStr) {
            // Try to infer from period label (Abril 2026 → 2026-04-30)
            $cutoffStr = '2026-04-30';
        }

        // Compute upload date from saldos_cliente upload
        $uploadDate = DB::table('report_uploads as ru')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('ru.period_id', $dataIds)
            ->where('ds.code', 'lendus_saldos_cliente')
            ->orderByDesc('ru.uploaded_at')
            ->value('uploaded_at');

        $uploadDay  = $uploadDate ? substr($uploadDate, 0, 10) : 'desconocida';
        $daysDelta  = $uploadDate
            ? (int) DB::selectOne("SELECT DATEDIFF(?,?) as d", [$uploadDay, $cutoffStr])->d
            : 0;

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG MORA RAW — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs        : [' . implode(', ', $dataIds) . ']');
        $this->line('  Fecha corte     : ' . $cutoffStr . ' (fin del período)');
        $this->line('  Fecha upload    : ' . $uploadDay);
        $this->line('  Días de desfase : ' . $daysDelta . ' (upload - cutoff)');
        $this->line('  DPD ajustado    = max(0, DPD_archivo - ' . $daysDelta . ')');
        $this->line('');

        // ── Operative branch IDs ───────────────────────────────────────────────
        $operativeNames = ['atlacomulco','atlixco','cordoba','cuernavaca','huamantla','ixtlahuaca','miacatlan','orizaba','san juan del rio','san juan del río','san luis potosi','tenango del valle','tlaxcala','tula'];
        $operativeIds = DB::table('branches')
            ->whereIn(DB::raw('LOWER(normalized_name)'), $operativeNames)
            ->pluck('id')
            ->all();

        // ── 1. Totales brutos ────────────────────────────────────────────────
        $this->info('── 1. Totales brutos en fact_portfolios (operativas) ──');
        $totals = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->selectRaw("
                COUNT(*) as contratos,
                SUM(balance) as balance,
                COUNT(CASE WHEN days_past_due > 0 THEN 1 END) as con_mora,
                SUM(CASE WHEN days_past_due > 0 THEN COALESCE(NULLIF(past_due_balance,0), balance) ELSE 0 END) as mora_total,
                COUNT(CASE WHEN days_past_due IS NULL THEN 1 END) as dpd_null
            ")
            ->first();

        $this->line(sprintf('  Contratos totales    : %d', $totals->contratos ?? 0));
        $this->line(sprintf('  Contratos con mora   : %d', $totals->con_mora ?? 0));
        $this->line(sprintf('  DPD nulos            : %d', $totals->dpd_null ?? 0));
        $this->line(sprintf('  Balance total        : $%s', number_format((float)($totals->balance ?? 0), 2)));
        $this->line(sprintf('  Mora total (raw)     : $%s', number_format((float)($totals->mora_total ?? 0), 2)));
        $this->line('');

        // ── 2. Buckets raw vs ajustado ───────────────────────────────────────
        $this->info('── 2. Buckets de mora: raw del archivo vs ajustado a fecha de corte ──');

        $rows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->where('days_past_due', '>', 0)
            ->selectRaw('days_past_due, SUM(COALESCE(NULLIF(past_due_balance,0), balance)) as mora')
            ->groupBy('days_past_due')
            ->get();

        $raw = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91-120' => 0.0, '120+' => 0.0];
        $adj = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '91-120' => 0.0, '120+' => 0.0];
        $nowCurrent = 0.0;

        foreach ($rows as $row) {
            $dpd  = (int) $row->days_past_due;
            $mora = (float) $row->mora;

            $b = match (true) { $dpd <= 30 => '0-30', $dpd <= 60 => '31-60', $dpd <= 90 => '61-90', $dpd <= 120 => '91-120', default => '120+' };
            $raw[$b] += $mora;

            $adjDpd = max(0, $dpd - $daysDelta);
            if ($adjDpd === 0) {
                $nowCurrent += $mora;
            } else {
                $ba = match (true) { $adjDpd <= 30 => '0-30', $adjDpd <= 60 => '31-60', $adjDpd <= 90 => '61-90', $adjDpd <= 120 => '91-120', default => '120+' };
                $adj[$ba] += $mora;
            }
        }

        $this->line(sprintf('  %-10s  %-14s  %-14s  %-14s  %-9s  %s',
            'Bucket', 'RAW (archivo)', 'AJUST (cutoff)', 'Target', 'Dif $', 'Dif %'));
        $this->line('  ' . str_repeat('─', 78));

        foreach ($raw as $b => $val) {
            $a    = $adj[$b];
            $tgt  = self::TARGETS[$b];
            $diff = $a - $tgt;
            $pct  = $tgt > 0 ? ($diff / $tgt) * 100 : 0;
            $color = abs($pct) < 2 ? 'green' : (abs($pct) < 10 ? 'yellow' : 'red');
            $this->line(sprintf("  %-10s  \$%-13s  \$%-13s  \$%-13s  <fg={$color}>%+10.2f  %+.1f%%</>",
                $b, number_format($val, 2), number_format($a, 2), number_format($tgt, 2), $diff, $pct));
        }

        if ($nowCurrent > 0) {
            $this->line(sprintf('  %-10s  %-14s  $%-13s  %-14s  (en corte eran al corriente)',
                'Al corriente', '(ver raw)', number_format($nowCurrent, 2), '—'));
        }
        $this->line('');

        // ── 3. DPD distribution ──────────────────────────────────────────────
        $this->info('── 3. Distribución DPD (grupos de 5 días) ──');
        $distRows = DB::table('fact_portfolios')
            ->whereIn('period_id', $dataIds)
            ->whereIn('branch_id', $operativeIds)
            ->where('days_past_due', '>', 0)
            ->where('days_past_due', '<=', 130)
            ->selectRaw('FLOOR(days_past_due/5)*5 as dpd_group, COUNT(*) as cnt, SUM(COALESCE(NULLIF(past_due_balance,0),balance)) as mora')
            ->groupByRaw('FLOOR(days_past_due/5)*5')
            ->orderBy('dpd_group')
            ->get();

        foreach ($distRows as $row) {
            $dpd  = (int) $row->dpd_group;
            $adj  = max(0, $dpd - $daysDelta);
            $this->line(sprintf('  DPD %3d–%3d (adj→%3d–%3d) : %4d contratos  $%s',
                $dpd, $dpd + 4, max(0, $adj), max(0, $adj + 4),
                $row->cnt, number_format((float)$row->mora, 2)));
        }
        $this->line('');

        // ── 4. Por sucursal (raw) ────────────────────────────────────────────
        $this->info('── 4. Mora por sucursal (raw del archivo) ──');
        $bySuc = DB::table('fact_portfolios as fp')
            ->join('branches as b', 'fp.branch_id', '=', 'b.id')
            ->whereIn('fp.period_id', $dataIds)
            ->whereIn('fp.branch_id', $operativeIds)
            ->where('fp.days_past_due', '>', 0)
            ->selectRaw("
                b.name as sucursal,
                SUM(CASE WHEN fp.days_past_due BETWEEN 1 AND 30 THEN COALESCE(NULLIF(fp.past_due_balance,0),fp.balance) ELSE 0 END) as m0_30,
                SUM(CASE WHEN fp.days_past_due BETWEEN 31 AND 60 THEN COALESCE(NULLIF(fp.past_due_balance,0),fp.balance) ELSE 0 END) as m31_60,
                SUM(CASE WHEN fp.days_past_due BETWEEN 61 AND 90 THEN COALESCE(NULLIF(fp.past_due_balance,0),fp.balance) ELSE 0 END) as m61_90,
                SUM(CASE WHEN fp.days_past_due BETWEEN 91 AND 120 THEN COALESCE(NULLIF(fp.past_due_balance,0),fp.balance) ELSE 0 END) as m91_120,
                SUM(CASE WHEN fp.days_past_due > 120 THEN COALESCE(NULLIF(fp.past_due_balance,0),fp.balance) ELSE 0 END) as m120plus
            ")
            ->groupBy('b.id', 'b.name')
            ->orderByDesc(DB::raw('SUM(COALESCE(NULLIF(fp.past_due_balance,0),fp.balance))'))
            ->get();

        $this->table(
            ['Sucursal', '0-30', '31-60', '61-90', '91-120', '120+'],
            $bySuc->map(fn ($r) => [
                mb_substr($r->sucursal, 0, 22),
                '$' . number_format((float)$r->m0_30,    2),
                '$' . number_format((float)$r->m31_60,   2),
                '$' . number_format((float)$r->m61_90,   2),
                '$' . number_format((float)$r->m91_120,  2),
                '$' . number_format((float)$r->m120plus, 2),
            ])->all()
        );
        $this->line('');

        // ── 5. Contratos en zona de desfase (DPD 1 – 2*delta) ───────────────
        if ($daysDelta > 0) {
            $upperBound = $daysDelta * 2;
            $this->info(sprintf('── 5. Contratos afectados por desfase (DPD 1–%d, i.e. puede cambiar de bucket) ──', $upperBound));
            $affectedRows = DB::table('fact_portfolios as fp')
                ->join('branches as b', 'fp.branch_id', '=', 'b.id')
                ->whereIn('fp.period_id', $dataIds)
                ->whereIn('fp.branch_id', $operativeIds)
                ->whereBetween('fp.days_past_due', [1, $upperBound])
                ->selectRaw("
                    b.name as sucursal,
                    fp.days_past_due as dpd_raw,
                    GREATEST(0, fp.days_past_due - ?) as dpd_adj,
                    fp.contract,
                    COALESCE(NULLIF(fp.past_due_balance,0), fp.balance) as mora
                ", [$daysDelta])
                ->orderByDesc('mora')
                ->limit(30)
                ->get();

            $this->table(
                ['Sucursal', 'Contrato', 'DPD raw', 'DPD adj', 'Bucket raw', 'Bucket adj', 'Mora'],
                $affectedRows->map(fn ($r) => [
                    mb_substr($r->sucursal, 0, 20),
                    $r->contract,
                    $r->dpd_raw,
                    $r->dpd_adj,
                    $this->bucket((int)$r->dpd_raw),
                    $this->bucket((int)$r->dpd_adj),
                    '$' . number_format((float)$r->mora, 2),
                ])->all()
            );
            $this->line('');
        }

        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('');

        return self::SUCCESS;
    }

    private function bucket(int $dpd): string
    {
        return match (true) {
            $dpd === 0  => 'corriente',
            $dpd <= 30  => '0-30',
            $dpd <= 60  => '31-60',
            $dpd <= 90  => '61-90',
            $dpd <= 120 => '91-120',
            default     => '120+',
        };
    }
}
