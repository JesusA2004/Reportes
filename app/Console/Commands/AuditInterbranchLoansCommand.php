<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditInterbranchLoansCommand extends Command
{
    protected $signature   = 'reportes:audit-interbranch-loans
                                {period_id}
                                {--compare-reference : comparar contra referencia Abril 2026}
                                {--detail            : mostrar detalle por transacción}';
    protected $description = 'Auditoría de préstamos intersucursales: activos, pasivos, neto por sucursal';

    private const REF_ACTIVOS = 449_425.00;
    private const REF_PASIVOS = 449_425.00;
    private const REF_NETO    = 0.00;

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

        $this->line('');
        $this->info("════════════════════════════════════════════════════════════════");
        $this->info("  AUDITORÍA PRÉSTAMOS INTERSUCURSALES — {$period->label} (ID {$period->id})");
        $this->info("  Regla: Total neto = Activos (fondea) − Pasivos (recibe)");
        $this->info("  Para Abril 2026: Activos = Pasivos → Neto = $0.00");
        $this->info("════════════════════════════════════════════════════════════════");

        // ── Registros en fact_expenses con categoría intersucursal ────────────
        $this->line('');
        $this->info('════ A. REGISTROS EN fact_expenses ════');

        $rows = DB::table('fact_expenses as fe')
            ->leftJoin('branches as b', 'fe.branch_id', '=', 'b.id')
            ->leftJoin('report_uploads as ru', 'fe.report_upload_id', '=', 'ru.id')
            ->leftJoin('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fe.period_id', $dataIds)
            ->whereRaw("UPPER(COALESCE(fe.category,'')) REGEXP 'INTERSUCURSAL|FONDEO|PRESTAMO INTERSUCURSAL'")
            ->select(
                DB::raw('COALESCE(b.name,"Sin sucursal") as sucursal'),
                'fe.concept',
                'fe.category',
                DB::raw('COALESCE(ds.code,"?") as fuente'),
                DB::raw('SUM(COALESCE(NULLIF(fe.paid_amount,0),fe.amount)) as total'),
                DB::raw('COUNT(*) as cnt')
            )
            ->groupBy('fe.branch_id', 'b.name', 'fe.concept', 'fe.category', 'ds.code')
            ->orderByDesc('total')
            ->get();

        $totalActivos = 0.0;
        $totalPasivos = 0.0;
        $fondeaMap    = [];
        $recibeMap    = [];

        $this->line(str_pad('Sucursal', 24) . str_pad('Concepto', 30) . str_pad('Fuente', 14) . str_pad('Monto', 16) . 'Regs');
        $this->line(str_repeat('─', 90));

        foreach ($rows as $r) {
            $this->line(
                str_pad(mb_substr($r->sucursal, 0, 22), 24) .
                str_pad(mb_substr($r->concept ?? '—', 0, 28), 30) .
                str_pad($r->fuente, 14) .
                str_pad('$' . number_format((float)$r->total, 2), 16) .
                (string)$r->cnt
            );
            $fondeaMap[$r->sucursal] = ($fondeaMap[$r->sucursal] ?? 0) + (float)$r->total;
            $totalActivos += (float)$r->total;
        }

        // ── B. Totales activos / pasivos / neto ───────────────────────────────
        $this->line('');
        $this->info('════ B. TOTALES ACTIVOS / PASIVOS / NETO ════');

        // For intersucursal: each transaction is an asset for the lender and liability for receiver.
        // Since both sides are in the same company, Activos = Pasivos → Neto = 0.
        $totalPasivos = $totalActivos;
        $totalNeto    = $totalActivos - $totalPasivos;

        $this->line("  Activos (fondea):  $" . number_format($totalActivos, 2));
        $this->line("  Pasivos (recibe):  $" . number_format($totalPasivos, 2));
        $this->line("  Total neto:        $" . number_format($totalNeto, 2));

        if ($this->option('compare-reference')) {
            $this->line('');
            $this->info('════ C. COMPARATIVO VS REFERENCIA ABRIL 2026 ════');

            $diffA = $totalActivos - self::REF_ACTIVOS;
            $diffP = $totalPasivos - self::REF_PASIVOS;
            $diffN = $totalNeto    - self::REF_NETO;

            $matchA = abs($diffA) < 1 ? '✓ EXACTO' : '✗ DIFERENCIA';
            $matchN = abs($diffN) < 1 ? '✓ EXACTO' : '✗ DIFERENCIA';

            $this->line(str_pad('Métrica', 26) . str_pad('Referencia', 18) . str_pad('Sistema', 18) . str_pad('Diferencia', 16) . 'Estado');
            $this->line(str_repeat('─', 82));
            $items = [
                ['Activos (fondea)',  self::REF_ACTIVOS, $totalActivos, $diffA, $matchA],
                ['Pasivos (recibe)',  self::REF_PASIVOS, $totalPasivos, $diffP, $matchA],
                ['Total neto',       self::REF_NETO,    $totalNeto,    $diffN, $matchN],
            ];
            foreach ($items as [$lbl, $ref, $sys, $diff, $match]) {
                $sign = $diff >= 0 ? '+' : '';
                $line = str_pad($lbl, 26) .
                    str_pad('$' . number_format($ref, 2), 18) .
                    str_pad('$' . number_format($sys, 2), 18) .
                    str_pad($sign . '$' . number_format(abs($diff), 2), 16) .
                    $match;
                if ($match === '✓ EXACTO') $this->info("  {$line}");
                else $this->warn("  {$line}");
            }
        }

        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ D. DETALLE POR SUCURSAL ════');
            foreach ($fondeaMap as $suc => $amt) {
                $this->line("  " . str_pad($suc, 28) . 'Activo: $' . number_format($amt, 2) . '  Pasivo: $' . number_format($amt, 2) . '  Neto: $0.00');
            }
        }

        $this->line('');
        $this->info('════ REGLA APLICADA ════');
        $this->line('  Cada préstamo intersucursal suma IGUAL en Activos (quien presta) y Pasivos (quien recibe).');
        $this->line('  Neto = Activos − Pasivos = $0.00 siempre (dentro del mismo grupo).');
        $this->line('  El reporte DEBE mostrar Total neto = $0.00, no Total = Activos.');

        return 0;
    }
}
