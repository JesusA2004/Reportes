<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AuditRecuperacionCommand extends Command
{
    protected $signature = 'reportes:audit-recuperacion
                                {period_id}
                                {--detail : Muestra el detalle por contrato/concepto}
                                {--export : Exporta CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría de Recuperación: valida que global == componentes == sucursales == productos, incluyendo Seguro CRECE 30%/70%.';

    public function handle(BranchRadiographyCalculator $calc): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA RECUPERACIÓN — {$period->label} (ID {$periodId})");
        $this->info('  dataIds: ' . implode(', ', $dataIds));
        $this->info('════════════════════════════════════════════════════════════');

        ['branches' => $branches, 'unassigned' => $unassigned] = $calc->buildBranches($period, $dataIds);
        $global    = $calc->sumGlobal($branches, $unassigned);
        $byProduct = $calc->buildRecoveryByProduct($dataIds);

        // ── 1. Totales de control ────────────────────────────────────────────
        $this->line('');
        $this->info('════ 1. TOTALES DE CONTROL ════');

        $globalTotal = round((float) $global['recuperacion_total'], 2);
        $componentSum = round(
            (float) $global['capital_recuperado']
            + (float) $global['interes_recuperado']
            + (float) $global['impuesto_recuperado']
            + (float) $global['charges']
            + (float) $global['cargos_adicionales']
            + (float) $global['excedente_recuperado']
            + (float) $global['cargos_inicio']
            + (float) $global['comision_apertura']
            + (float) $global['seguro_crece_reconocido']
            + (float) $global['otros_recuperacion'],
            2
        );
        $branchSum = round(array_sum(array_map(fn ($b) => (float) $b['recuperacion_total'], $branches)), 2);
        $productSum = round((float) ($byProduct['total'] ?? 0), 2);

        $diffComponents = round($globalTotal - $componentSum, 2);
        $diffBranches   = round($globalTotal - $branchSum, 2);
        $diffProducts   = round($globalTotal - $productSum, 2);

        $this->line(sprintf('  Global:       $%s', number_format($globalTotal, 2)));
        $this->line(sprintf('  Componentes:  $%s  (diff $%s)', number_format($componentSum, 2), number_format($diffComponents, 2)));
        $this->line(sprintf('  Sucursales:   $%s  (diff $%s)', number_format($branchSum, 2), number_format($diffBranches, 2)));
        $this->line(sprintf('  Productos:    $%s  (diff $%s)', number_format($productSum, 2), number_format($diffProducts, 2)));

        $seguroCreceBruto = (float) $global['seguro_crece_bruto'];
        $seguroCrece30    = (float) $global['seguro_crece_reconocido'];
        $seguroCrece70    = max(0.0, $seguroCreceBruto - $seguroCrece30);

        $this->line('');
        $this->line(sprintf('  Seguro CRECE bruto:          $%s', number_format($seguroCreceBruto, 2)));
        $this->line(sprintf('  Seguro CRECE reconocido 30%%: $%s', number_format($seguroCrece30, 2)));
        $this->line(sprintf('  Seguro CRECE canalizado 70%%: $%s', number_format($seguroCrece70, 2)));
        $this->line(sprintf('  Seguro Savehearts (excluido): $%s', number_format((float) $global['seguro_savehearts_bruto'], 2)));
        $this->line(sprintf('  Seguro Comadres/Grupal (excluido): $%s', number_format((float) $global['seguro_comadres_bruto'], 2)));
        $this->line(sprintf('  Condonación excluida:        $%s', number_format((float) $global['condonacion_excluida'], 2)));

        $tolerance = 0.01;
        $ok = abs($diffComponents) <= $tolerance && abs($diffBranches) <= $tolerance && abs($diffProducts) <= $tolerance;

        // ── 2. Desglose por componente ────────────────────────────────────────
        $this->line('');
        $this->info('════ 2. DESGLOSE POR COMPONENTE ════');
        $components = [
            'Capital recuperado'             => $global['capital_recuperado'],
            'Intereses'                      => $global['interes_recuperado'],
            'Impuestos'                      => $global['impuesto_recuperado'],
            'Moratorios / Multas'            => $global['charges'],
            'Cargos adicionales'             => $global['cargos_adicionales'],
            'Cargos al inicio'               => $global['cargos_inicio'],
            'Comisión por apertura'          => $global['comision_apertura'],
            'Excedentes recuperados'         => $global['excedente_recuperado'],
            'Seguro CRECE reconocido (30%)'  => $global['seguro_crece_reconocido'],
        ];
        foreach ($global['otros_detalle'] ?? [] as $label => $amt) {
            $components[(string) $label] = $amt;
        }
        if (abs((float) $global['otros_recuperacion'] - array_sum($global['otros_detalle'] ?? [])) > $tolerance) {
            $components['Otros (no clasificado)'] = round((float) $global['otros_recuperacion'] - array_sum($global['otros_detalle'] ?? []), 2);
        }
        foreach ($components as $label => $amt) {
            if ((float) $amt == 0.0) continue;
            $this->line(sprintf('  %-40s $%s', $label, number_format((float) $amt, 2)));
        }

        // ── 3. Por sucursal ───────────────────────────────────────────────────
        $this->line('');
        $this->info('════ 3. RECUPERACIÓN POR SUCURSAL ════');
        foreach ($branches as $b) {
            if ((float) $b['recuperacion_total'] == 0.0) continue;
            $this->line(sprintf(
                '  %-25s Total: $%-15s CRECE 30%%: $%-12s Excedentes: $%s',
                $b['sucursal'],
                number_format((float) $b['recuperacion_total'], 2),
                number_format((float) $b['seguro_crece_reconocido'], 2),
                number_format((float) $b['excedente_recuperado'], 2)
            ));
        }

        // ── 4. Por producto ───────────────────────────────────────────────────
        $this->line('');
        $this->info('════ 4. RECUPERACIÓN POR PRODUCTO ════');
        foreach ($byProduct['rows'] as $p) {
            $creceTag = (float) $p['seguro_crece_reconocido'] > 0 ? ' [CRECE 30%: $' . number_format((float) $p['seguro_crece_reconocido'], 2) . ']' : '';
            $this->line(sprintf('  %-30s $%s%s', $p['product'], number_format((float) $p['total'], 2), $creceTag));
        }

        // ── 5. Detalle por contrato (opcional) ────────────────────────────────
        $detailRows = null;
        if ($this->option('detail') || $this->option('export')) {
            $detailRows = $this->buildDetailRows($dataIds);
        }
        if ($this->option('detail') && $detailRows !== null) {
            $this->line('');
            $this->info('════ 5. DETALLE POR CONTRATO (primeras 50 filas) ════');
            $this->table(
                ['Contrato', 'Sucursal', 'Producto', 'Transacción', 'Concepto', 'Total', 'CRECE 30%', 'Incluido', 'Motivo'],
                collect($detailRows)->take(50)->map(fn ($r) => [
                    $r['contrato'], $r['sucursal'], $r['producto'], $r['transaccion'], $r['concepto'],
                    number_format($r['total'], 2), number_format($r['crece_30'], 2), $r['incluido'] ? 'Sí' : 'No', $r['motivo'],
                ])->all()
            );
        }

        if ($this->option('export') && $detailRows !== null) {
            $this->exportCsv($periodId, $detailRows, $globalTotal, $componentSum, $branchSum, $productSum, $diffComponents, $diffBranches, $diffProducts, $seguroCreceBruto, $seguroCrece30, $seguroCrece70);
        }

        $this->line('');
        $this->info($ok ? '  ✓ Recuperación cuadrada: global == componentes == sucursales == productos.' : '  ✗ DESCUADRE detectado — revisar diferencias arriba.');
        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Fila por fila de fact_recoveries con la MISMA clasificación (incluido/motivo)
     * que usa BranchRadiographyCalculator::recoveryExclusionSql() — computada en PHP
     * para reportar el motivo exacto por transacción individual.
     */
    private function buildDetailRows(array $dataIds): array
    {
        $rows = DB::table('fact_recoveries as fr')
            ->leftJoin('branches as b', 'fr.branch_id', '=', 'b.id')
            ->whereIn('fr.period_id', $dataIds)
            ->select(
                'fr.contract', 'b.name as branch_name', 'fr.product_name', 'fr.transaction',
                'fr.concept', 'fr.operation', 'fr.total_amount', 'fr.is_savehearts', 'fr.savehearts_crece_share'
            )
            ->orderBy('fr.contract')
            ->limit(20000)
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $isPagoOrDescuento = in_array($r->transaction, ['PAGO', 'DESCUENTO'], true);
            $isSavehearts = (bool) $r->is_savehearts;
            $crece30 = 0.0;
            $incluido = false;
            $montoIncluido = 0.0;
            $motivo = '';

            if (!$isPagoOrDescuento) {
                $motivo = "Excluido: transacción '{$r->transaction}' no es PAGO/DESCUENTO";
            } elseif ($isSavehearts && (float) $r->savehearts_crece_share > 0) {
                $crece30 = (float) $r->savehearts_crece_share;
                $incluido = true;
                $montoIncluido = $crece30;
                $motivo = 'Incluido: 30% reconocido de Seguro CRECE';
            } elseif ($isSavehearts) {
                $motivo = 'Excluido: seguro no-CRECE (Savehearts/Comadres) — 100% canalizado';
            } else {
                $incluido = true;
                $montoIncluido = (float) $r->total_amount;
                $motivo = 'Incluido: componente real de recuperación';
            }

            $out[] = [
                'contrato'    => $r->contract,
                'sucursal'    => $r->branch_name ?? 'Sin sucursal',
                'producto'    => $r->product_name ?? 'Sin producto',
                'transaccion' => $r->transaction,
                'concepto'    => $r->concept ?? $r->operation,
                'total'       => $montoIncluido,
                'crece_30'    => $crece30,
                'incluido'    => $incluido,
                'motivo'      => $motivo,
            ];
        }

        return $out;
    }

    private function exportCsv(
        int $periodId,
        array $detailRows,
        float $globalTotal,
        float $componentSum,
        float $branchSum,
        float $productSum,
        float $diffComponents,
        float $diffBranches,
        float $diffProducts,
        float $creceBruto,
        float $crece30,
        float $crece70,
    ): void {
        $dir  = 'auditorias';
        $file = "recuperacion_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";
        $csv  = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';

        $lines = [implode(',', ['Contrato', 'Sucursal', 'Producto', 'Transacción', 'Concepto', 'Total', 'Seguro CRECE 30%', 'Incluido', 'Motivo'])];
        foreach ($detailRows as $r) {
            $lines[] = implode(',', [
                $csv((string) $r['contrato']), $csv($r['sucursal']), $csv($r['producto']), $csv($r['transaccion']),
                $csv((string) $r['concepto']), number_format($r['total'], 2, '.', ''), number_format($r['crece_30'], 2, '.', ''),
                $r['incluido'] ? 'Si' : 'No', $csv($r['motivo']),
            ]);
        }

        $lines[] = '';
        $lines[] = implode(',', ['RESUMEN', 'Monto']);
        $lines[] = implode(',', ['Total global', number_format($globalTotal, 2, '.', '')]);
        $lines[] = implode(',', ['Total por componentes', number_format($componentSum, 2, '.', '')]);
        $lines[] = implode(',', ['Total por sucursales', number_format($branchSum, 2, '.', '')]);
        $lines[] = implode(',', ['Total por productos', number_format($productSum, 2, '.', '')]);
        $lines[] = implode(',', ['Diferencia componentes', number_format($diffComponents, 2, '.', '')]);
        $lines[] = implode(',', ['Diferencia sucursales', number_format($diffBranches, 2, '.', '')]);
        $lines[] = implode(',', ['Diferencia productos', number_format($diffProducts, 2, '.', '')]);
        $lines[] = implode(',', ['Seguro CRECE bruto', number_format($creceBruto, 2, '.', '')]);
        $lines[] = implode(',', ['Seguro CRECE reconocido 30%', number_format($crece30, 2, '.', '')]);
        $lines[] = implode(',', ['Seguro CRECE canalizado 70%', number_format($crece70, 2, '.', '')]);

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
