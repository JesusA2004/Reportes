<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditCarteraCommand extends Command
{
    protected $signature = 'reportes:audit-cartera
                                {period_id}
                                {--by-branch : desglose por sucursal oficial: valor cartera + cartera vencida (5 cols) + mora}
                                {--detail : detalle contrato a contrato: saldo actual, 5 cols vencidas, días vencido, incluido/motivo}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría Cartera — valor cartera (saldo actual) + cartera vencida (5 cols) — misma fuente que Dashboard/Excel/PDF';

    private const REF_CARTERA = 39_205_971.67;
    private const REF_MORA    = 13_707_000.00;

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

        /** @var BranchRadiographyCalculator $calc */
        $calc     = app(BranchRadiographyCalculator::class);
        $resolver = app(BranchResolverService::class);

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA CARTERA — {$period->label} (ID {$period->id})");
        $this->info('  Valor cartera : SUM(balance), excluye AGS');
        $this->info('  Cartera vencida: SUM(capital_due + interes_atrasado + impuesto_atrasado');
        $this->info('                    + saldo_interes_moratorio + saldo_impuesto_interes_moratorio)');
        $this->info('                    donde days_past_due > 0, excluye AGS');
        $this->info('════════════════════════════════════════════════════════════════════════════');

        // ── Global KPIs ──────────────────────────────────────────────────────
        $global5   = $calc->computeCarteraGlobalSinFiltro($dataIds);
        $result    = $calc->buildBranches($period, $dataIds, []);
        $global    = $calc->sumGlobal($result['branches'], $result['unassigned']);

        $carteraOficial = (float) $global5['valor_cartera'];
        $vencidaOficial = (float) $global5['cartera_vencida'];
        $pctMora = $carteraOficial > 0 ? round($vencidaOficial / $carteraOficial * 100, 2) : 0;

        $this->line('');
        $this->info('── TOTALES GLOBALES ──');
        $diffC = $carteraOficial - self::REF_CARTERA;
        $diffM = $vencidaOficial - self::REF_MORA;
        $statusC = abs($diffC) < 1 ? '✓ EXACTO' : (abs($diffC) < 50_000 ? '≈ CERCANO' : '✗ DIFERENCIA');
        $statusM = abs($diffM) < 1 ? '✓ EXACTO' : (abs($diffM) < 200_000 ? '≈ CERCANO' : '✗ DIFERENCIA');
        $this->line("  Valor cartera:          \$" . number_format($carteraOficial, 2));
        $this->line("  Referencia:             \$" . number_format(self::REF_CARTERA, 2));
        $this->line("  Diferencia:             " . ($diffC >= 0 ? '+' : '') . "\$" . number_format($diffC, 2) . "  {$statusC}");
        $this->line('');
        $this->line("  Cartera vencida (5col): \$" . number_format($vencidaOficial, 2));
        $this->line("  Referencia mora:       ~\$" . number_format(self::REF_MORA, 2));
        $this->line("  Diferencia:             " . ($diffM >= 0 ? '+' : '') . "\$" . number_format($diffM, 2) . "  {$statusM}");
        $this->line("  % Mora/Cartera:         {$pctMora}%");

        // ── By Branch ────────────────────────────────────────────────────────
        if ($this->option('by-branch')) {
            $this->line('');
            $this->info('════ POR SUCURSAL OFICIAL ════');
            $hdr = str_pad('Sucursal', 26)
                . str_pad('Val. Cartera', 18)
                . str_pad('Cartera Venc.', 17)
                . str_pad('Mora 1-30', 14)
                . str_pad('Mora 31-60', 14)
                . str_pad('Mora 61-90', 14)
                . str_pad('Mora 91-120', 14)
                . str_pad('Mora 120+', 14)
                . '% Mora';
            $this->line($hdr);
            $this->line(str_repeat('─', 140));

            $totCar = $totVen = 0.0;
            $branches = collect($result['branches'])->sortByDesc('valor_cartera');

            foreach ($branches as $b) {
                $cartera = (float) $b['valor_cartera'];
                $vencida = (float) ($b['cartera_vencida'] ?? 0);
                $pct = $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0;
                $totCar += $cartera;
                $totVen += $vencida;

                $this->line(
                    str_pad(mb_substr($b['sucursal'], 0, 24), 26)
                    . str_pad('$' . number_format($cartera, 2), 18)
                    . str_pad('$' . number_format($vencida, 2), 17)
                    . str_pad('$' . number_format((float) $b['mora_0_30'], 2), 14)
                    . str_pad('$' . number_format((float) $b['mora_31_60'], 2), 14)
                    . str_pad('$' . number_format((float) $b['mora_61_90'], 2), 14)
                    . str_pad('$' . number_format((float) $b['mora_91_120'], 2), 14)
                    . str_pad('$' . number_format((float) $b['mora_120_plus'], 2), 14)
                    . $pct . '%'
                );
            }

            $this->line(str_repeat('─', 140));
            $totPct = $totCar > 0 ? round($totVen / $totCar * 100, 2) : 0;
            $this->info(
                str_pad('TOTAL SUCURSALES', 26)
                . str_pad('$' . number_format($totCar, 2), 18)
                . str_pad('$' . number_format($totVen, 2), 17)
                . str_repeat(' ', 56)
                . $totPct . '%'
            );
        }

        // ── Detail: per-contract rows ─────────────────────────────────────
        $detailRows = [];
        if ($this->option('detail') || $this->option('export')) {
            $detailRows = $this->loadDetailRows($dataIds, $resolver);

            if ($this->option('detail')) {
                $included = array_filter($detailRows, fn ($r) => $r['incluido']);
                $excluded = array_filter($detailRows, fn ($r) => !$r['incluido']);
                $this->line('');
                $this->info('════ DETALLE CONTRATO A CONTRATO ════');
                $this->line("  Total contratos: " . count($detailRows)
                    . "  Incluidos en mora: " . count($included)
                    . "  Excluidos: " . count($excluded));
                $this->line('');
                $this->line(
                    str_pad('Sucursal', 22)
                    . str_pad('Saldo actual', 15)
                    . str_pad('Cap atras.', 13)
                    . str_pad('Int atras.', 13)
                    . str_pad('Imp atras.', 13)
                    . str_pad('SI morat.', 13)
                    . str_pad('SII morat.', 13)
                    . str_pad('Vencida', 15)
                    . str_pad('DPD', 7)
                    . str_pad('Incluido', 10)
                    . 'Motivo'
                );
                $this->line(str_repeat('─', 155));

                $shown = 0;
                foreach ($detailRows as $row) {
                    if ($shown >= 100) {
                        $this->line('  ... (' . (count($detailRows) - 100) . ' contratos más — usa --export para ver todos)');
                        break;
                    }
                    $line = str_pad(mb_substr((string) $row['sucursal'], 0, 20), 22)
                        . str_pad('$' . number_format($row['balance'], 2), 15)
                        . str_pad('$' . number_format($row['capital_due'], 2), 13)
                        . str_pad('$' . number_format($row['interes_atrasado'], 2), 13)
                        . str_pad('$' . number_format($row['impuesto_atrasado'], 2), 13)
                        . str_pad('$' . number_format($row['saldo_interes_moratorio'], 2), 13)
                        . str_pad('$' . number_format($row['saldo_impuesto_interes_moratorio'], 2), 13)
                        . str_pad('$' . number_format($row['vencida'], 2), 15)
                        . str_pad((string) $row['days_past_due'], 7)
                        . str_pad($row['incluido'] ? 'Sí' : 'No', 10)
                        . ($row['motivo'] ?? '');
                    $row['incluido'] ? $this->line($line) : $this->warn($line);
                    $shown++;
                }
            }
        }

        if ($this->option('export')) {
            $this->exportCsv($period->id, $result['branches'], $detailRows);
        }

        return 0;
    }

    private function loadDetailRows(array $dataIds, BranchResolverService $resolver): array
    {
        // Build AGS branch_id set
        $agsIds = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            if ($real && strtoupper(trim($real)) === 'AGUASCALIENTES') {
                $agsIds[] = (int) $b->id;
            }
        }

        // Build operative branch map for name resolution
        $branchMap = [];
        foreach (DB::table('branches')->get() as $b) {
            $real = $resolver->resolveRealBranchFromRoute($b->name);
            $branchMap[(int) $b->id] = $real ?? $b->name;
        }

        $rows = DB::table('fact_portfolios as p')
            ->whereIn('p.period_id', $dataIds)
            ->selectRaw("
                p.id,
                p.branch_id,
                p.balance,
                p.days_past_due,
                COALESCE(p.capital_due, 0)                        AS capital_due,
                COALESCE(p.interes_atrasado, 0)                   AS interes_atrasado,
                COALESCE(p.impuesto_atrasado, 0)                  AS impuesto_atrasado,
                COALESCE(p.saldo_interes_moratorio, 0)            AS saldo_interes_moratorio,
                COALESCE(p.saldo_impuesto_interes_moratorio, 0)   AS saldo_impuesto_interes_moratorio
            ")
            ->orderBy('p.branch_id')
            ->orderByDesc('p.days_past_due')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $branchId = (int) $row->branch_id;
            $isAgs    = in_array($branchId, $agsIds, true);
            $dpd      = (int) $row->days_past_due;
            $vencida  = (float) $row->capital_due
                + (float) $row->interes_atrasado
                + (float) $row->impuesto_atrasado
                + (float) $row->saldo_interes_moratorio
                + (float) $row->saldo_impuesto_interes_moratorio;

            $incluido = !$isAgs && $dpd > 0;
            $motivo   = $isAgs ? 'AGS excluida' : ($dpd === 0 ? 'days_past_due = 0' : '');

            $result[] = [
                'id'                                  => $row->id,
                'sucursal'                            => $branchMap[$branchId] ?? 'SIN SUCURSAL',
                'balance'                             => (float) $row->balance,
                'capital_due'                         => (float) $row->capital_due,
                'interes_atrasado'                    => (float) $row->interes_atrasado,
                'impuesto_atrasado'                   => (float) $row->impuesto_atrasado,
                'saldo_interes_moratorio'             => (float) $row->saldo_interes_moratorio,
                'saldo_impuesto_interes_moratorio'    => (float) $row->saldo_impuesto_interes_moratorio,
                'vencida'                             => $vencida,
                'days_past_due'                       => $dpd,
                'incluido'                            => $incluido,
                'motivo'                              => $motivo,
            ];
        }

        return $result;
    }

    private function exportCsv(int $periodId, array $branches, array $detailRows): void
    {
        $dir = 'auditorias';
        $ts  = now()->format('Ymd_His');

        // Branch summary CSV
        $summaryFile = "cartera_resumen_periodo_{$periodId}_{$ts}.csv";
        $lines = [implode(',', ['Sucursal', 'Valor Cartera', 'Cartera Vencida (5col)',
            'Mora 1-30', 'Mora 31-60', 'Mora 61-90', 'Mora 91-120', 'Mora 120+', '% Mora'])];
        $totCar = $totVen = 0.0;
        foreach ($branches as $b) {
            $cartera = (float) $b['valor_cartera'];
            $vencida = (float) ($b['cartera_vencida'] ?? 0);
            $pct = $cartera > 0 ? round($vencida / $cartera * 100, 2) : 0;
            $totCar += $cartera;
            $totVen += $vencida;
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $b['sucursal']) . '"',
                number_format($cartera, 2, '.', ''),
                number_format($vencida, 2, '.', ''),
                number_format((float) $b['mora_0_30'], 2, '.', ''),
                number_format((float) $b['mora_31_60'], 2, '.', ''),
                number_format((float) $b['mora_61_90'], 2, '.', ''),
                number_format((float) $b['mora_91_120'], 2, '.', ''),
                number_format((float) $b['mora_120_plus'], 2, '.', ''),
                $pct,
            ]);
        }
        $totPct = $totCar > 0 ? round($totVen / $totCar * 100, 2) : 0;
        $lines[] = implode(',', ['TOTAL', number_format($totCar, 2, '.', ''), number_format($totVen, 2, '.', ''),
            '', '', '', '', '', $totPct]);
        Storage::disk('local')->put("{$dir}/{$summaryFile}", implode("\n", $lines));
        $this->info("  Resumen por sucursal: storage/app/{$dir}/{$summaryFile}");

        if (empty($detailRows)) {
            return;
        }

        // Detail CSV (all contracts)
        $detailFile = "cartera_detalle_periodo_{$periodId}_{$ts}.csv";
        $dlines = [implode(',', [
            'Sucursal', 'Saldo actual', 'Capital atrasado', 'Interés atrasado',
            'Impuesto atrasado', 'Saldo interés moratorio', 'Saldo impuesto int morat',
            'Cartera vencida calculada', 'Días vencido', 'Incluido', 'Motivo',
        ])];
        foreach ($detailRows as $r) {
            $dlines[] = implode(',', [
                '"' . str_replace('"', '""', (string) $r['sucursal']) . '"',
                number_format($r['balance'], 2, '.', ''),
                number_format($r['capital_due'], 2, '.', ''),
                number_format($r['interes_atrasado'], 2, '.', ''),
                number_format($r['impuesto_atrasado'], 2, '.', ''),
                number_format($r['saldo_interes_moratorio'], 2, '.', ''),
                number_format($r['saldo_impuesto_interes_moratorio'], 2, '.', ''),
                number_format($r['vencida'], 2, '.', ''),
                $r['days_past_due'],
                $r['incluido'] ? 'Sí' : 'No',
                '"' . str_replace('"', '""', (string) $r['motivo']) . '"',
            ]);
        }
        Storage::disk('local')->put("{$dir}/{$detailFile}", implode("\n", $dlines));
        $this->info("  Detalle contratos:    storage/app/{$dir}/{$detailFile}");
    }
}
