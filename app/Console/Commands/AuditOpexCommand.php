<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditOpexCommand extends Command
{
    protected $signature = 'reportes:audit-opex
                                {period_id}
                                {--by-branch : desglose OPEX por sucursal oficial (ERP + Lendus)}
                                {--detail : detalle por concepto canónico dentro de cada sucursal}
                                {--export : exportar CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría OPEX — ERP + Lendus por sucursal y concepto — misma fuente que Dashboard/Excel/PDF';

    private const REF_ERP    = 448_549.95;
    private const REF_LENDUS = 557_561.29;
    private const REF_OPEX   = 1_006_111.24;

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
        $calc   = app(BranchRadiographyCalculator::class);
        $result = $calc->buildBranches($period, $dataIds, []);
        $global = $calc->sumGlobal($result['branches'], $result['unassigned']);

        $opexTotal  = (float) $global['gastos_operativos'];
        $erpTotal   = (float) $global['gastos_erp_total'];
        $lendusTotal= (float) $global['gastos_lendus_total'];

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA OPEX — {$period->label} (ID {$period->id})");
        $this->info('  ERP: filtro único por Sucursal (excluye Corporativo, Gasolina, Financiamiento Celular)');
        $this->info('  Lendus: excluye Nómina y Cap Humano, Pólizas, Fondeos, Excedentes');
        $this->info('════════════════════════════════════════════════════════════════════════════');

        $this->line('');
        $this->info('── TOTALES GLOBALES ──');
        $this->printRefLine('ERP', $erpTotal, self::REF_ERP);
        $this->printRefLine('Lendus', $lendusTotal, self::REF_LENDUS);
        $this->printRefLine('OPEX Total', $opexTotal, self::REF_OPEX);

        // ── By Branch ────────────────────────────────────────────────────────
        if ($this->option('by-branch')) {
            $this->line('');
            $this->info('════ OPEX POR SUCURSAL OFICIAL ════');
            $this->line(
                str_pad('Sucursal', 26)
                . str_pad('ERP', 17)
                . str_pad('Lendus', 17)
                . str_pad('OPEX Total', 17)
                . '% del global'
            );
            $this->line(str_repeat('─', 90));

            $totErp = $totLendus = $totOpex = 0.0;
            $branches = collect($result['branches'])->sortByDesc('gastos_operativos');

            foreach ($branches as $b) {
                $erp    = (float) $b['gastos_erp_total'];
                $lendus = (float) $b['gastos_lendus_total'];
                $opex   = (float) $b['gastos_operativos'];
                $pct    = $opexTotal > 0 ? round($opex / $opexTotal * 100, 2) : 0;
                $totErp    += $erp;
                $totLendus += $lendus;
                $totOpex   += $opex;

                $this->line(
                    str_pad(mb_substr($b['sucursal'], 0, 24), 26)
                    . str_pad('$' . number_format($erp, 2), 17)
                    . str_pad('$' . number_format($lendus, 2), 17)
                    . str_pad('$' . number_format($opex, 2), 17)
                    . $pct . '%'
                );
            }

            $this->line(str_repeat('─', 90));
            $this->info(
                str_pad('TOTAL SUCURSALES', 26)
                . str_pad('$' . number_format($totErp, 2), 17)
                . str_pad('$' . number_format($totLendus, 2), 17)
                . str_pad('$' . number_format($totOpex, 2), 17)
            );
        }

        // ── Detail: per-concept breakdown ─────────────────────────────────
        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE POR CONCEPTO ════');

            if ($this->option('by-branch')) {
                // Show concepts per branch
                foreach ($result['branches'] as $b) {
                    if (empty($b['gastos_detalle'])) {
                        continue;
                    }
                    $this->line('');
                    $this->info("  ── {$b['sucursal']} (ERP: \$" . number_format((float) $b['gastos_erp_total'], 2)
                        . "  Lendus: \$" . number_format((float) $b['gastos_lendus_total'], 2) . ") ──");
                    arsort($b['gastos_detalle']);
                    foreach ($b['gastos_detalle'] as $concept => $amount) {
                        $this->line("    " . str_pad(mb_substr((string) $concept, 0, 50), 54)
                            . '$' . number_format((float) $amount, 2));
                    }
                }
            } else {
                // Global concept breakdown
                $globalDetail = $global['gastos_detalle'] ?? [];
                arsort($globalDetail);
                $this->line(str_pad('Concepto', 55) . 'Monto');
                $this->line(str_repeat('─', 75));
                foreach ($globalDetail as $concept => $amount) {
                    $this->line(str_pad(mb_substr((string) $concept, 0, 53), 55) . '$' . number_format((float) $amount, 2));
                }
            }
        }

        if ($this->option('export')) {
            $this->exportCsv($period->id, $result['branches'], $global);
        }

        return 0;
    }

    private function printRefLine(string $label, float $value, float $ref): void
    {
        $diff   = $value - $ref;
        $sign   = $diff >= 0 ? '+' : '';
        $status = abs($diff) < 1 ? '✓ EXACTO' : (abs($diff) < 20_000 ? '≈ CERCANO' : '⚠ REVISAR');
        $this->line(
            "  " . str_pad($label, 14)
            . str_pad('$' . number_format($value, 2), 20)
            . "ref: \$" . number_format($ref, 2)
            . "  diff: {$sign}\$" . number_format(abs($diff), 2)
            . "  {$status}"
        );
    }

    private function exportCsv(int $periodId, array $branches, array $global): void
    {
        $dir = 'auditorias';
        $ts  = now()->format('Ymd_His');

        // Branch summary CSV
        $summaryFile = "opex_por_sucursal_periodo_{$periodId}_{$ts}.csv";
        $lines = [implode(',', ['Sucursal', 'ERP', 'Lendus', 'OPEX Total'])];
        foreach ($branches as $b) {
            $lines[] = implode(',', [
                '"' . str_replace('"', '""', $b['sucursal']) . '"',
                number_format((float) $b['gastos_erp_total'], 2, '.', ''),
                number_format((float) $b['gastos_lendus_total'], 2, '.', ''),
                number_format((float) $b['gastos_operativos'], 2, '.', ''),
            ]);
        }
        $lines[] = implode(',', [
            'GLOBAL',
            number_format((float) $global['gastos_erp_total'], 2, '.', ''),
            number_format((float) $global['gastos_lendus_total'], 2, '.', ''),
            number_format((float) $global['gastos_operativos'], 2, '.', ''),
        ]);
        Storage::disk('local')->put("{$dir}/{$summaryFile}", implode("\n", $lines));
        $this->info("  Resumen por sucursal: storage/app/{$dir}/{$summaryFile}");

        // Concept detail CSV
        $detailFile = "opex_conceptos_periodo_{$periodId}_{$ts}.csv";
        $branchNames = array_map(fn ($b) => $b['sucursal'], $branches);
        $dheaders = array_merge(['Concepto'], $branchNames, ['GLOBAL']);
        $dlines   = [implode(',', $dheaders)];

        // Collect all concepts
        $allConcepts = [];
        foreach ($branches as $b) {
            foreach (array_keys($b['gastos_detalle'] ?? []) as $c) {
                $allConcepts[$c] = true;
            }
        }

        foreach (array_keys($allConcepts) as $concept) {
            $row = ['"' . str_replace('"', '""', $concept) . '"'];
            foreach ($branches as $b) {
                $row[] = number_format((float) ($b['gastos_detalle'][$concept] ?? 0), 2, '.', '');
            }
            $row[] = number_format((float) ($global['gastos_detalle'][$concept] ?? 0), 2, '.', '');
            $dlines[] = implode(',', $row);
        }

        Storage::disk('local')->put("{$dir}/{$detailFile}", implode("\n", $dlines));
        $this->info("  Detalle por concepto: storage/app/{$dir}/{$detailFile}");
    }
}
