<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class AuditGastosPeriodoCommand extends Command
{
    protected $signature = 'reportes:audit-gastos-periodo
                                {period_id}
                                {--detail : Muestra el detalle por sucursal}
                                {--export : Exporta CSV a storage/app/auditorias/}';

    protected $description = 'Auditoría de OPEX (gastos_operativos) — fuente única BranchRadiographyCalculator, concepto por concepto.';

    /**
     * Target validado por el usuario contra los archivos reales de Junio 2026 (period 21),
     * regla vigente 2026-07: ERP completo a OPEX (excl. Corporativo y Pólizas/seguros) +
     * Lendus OPEX vía PDF (excl. Nómina/IMSS/Deducciones/Fondeo/Excedentes/Pólizas/gastos de
     * empleados, que se mueven a Nómina y Capital Humano). Pólizas queda en $0.00 a propósito
     * (seguros excluidos de OPEX en ambas fuentes). Sirve de referencia de regresión para ese
     * periodo específico — en periodos distintos una diferencia contra esta tabla no implica
     * error, solo hay que revisar por qué cambió.
     */
    private const TARGETS_JUNIO_2026 = [
        'Renta Oficina' => 226055.12, 'Luz' => 24754.00, 'Agua' => 1012.00,
        'Teléfono e Internet' => 12405.00, 'Insumos de Cafetería' => 25436.30,
        'Insumos de Papelería' => 842.07,
        'Mobiliario y Equipo' => 6000.00, 'Mantenimiento' => 3166.80,
        'Señora Limpieza' => 42400.00, 'Eventos' => 0.00,
        'Trámites Gubernamentales' => 0.00, 'Publicidad' => 0.00,
        'Mecánicos' => 0.00, 'Servicios de Motocicletas' => 12253.73,
        'Software Póliza Anual' => 0.00, 'Pólizas' => 0.00,
        'Recargas Telefónicas' => 20200.00, 'Emergentes' => 296419.65,
        'Comisiones Oxxo' => 699.00, 'Multas e Infracciones' => 300.00,
        'Transportes' => 4432.47, 'Pegotes' => 3360.00, 'Permisos Vehiculares' => 0.00,
        'Viáticos' => 20500.00, 'Fletes' => 0.00, 'Formatería' => 18715.92,
        'Gasolina' => 165400.00, 'Sin clasificar: FLYERS Y VINILES' => 15585.27,
    ];

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

        $branchResult = $calc->buildBranches($period, $dataIds);
        $branches     = $branchResult['branches'];
        $unassigned   = $branchResult['unassigned'];
        $global       = $calc->sumGlobal($branches, $unassigned);

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA OPEX — {$period->label} (ID {$periodId})");
        $this->info('  Fuente única: gastos_lendus (PDF, branch_id 100% resuelto) + gastos_erp completo (excl. Corporativo/Pólizas).');
        $this->info('════════════════════════════════════════════════════════════');

        $current = (array) $global['gastos_detalle'];

        $this->line('');
        $this->info('════ CONCEPTO POR CONCEPTO ════');
        $this->table(
            ['Concepto', 'Target', 'Calculado', 'Diferencia', 'Estado'],
            collect(self::TARGETS_JUNIO_2026)->map(function ($target, $label) use ($current) {
                $actual = (float) ($current[$label] ?? 0.0);
                $diff   = $actual - $target;
                return [
                    $label,
                    number_format($target, 2),
                    number_format($actual, 2),
                    number_format($diff, 2),
                    abs($diff) < 0.01 ? '✅ Cuadra' : '❌ No cuadra',
                ];
            })->values()->all()
        );

        $extra = array_diff_key($current, self::TARGETS_JUNIO_2026);
        $extra = array_filter($extra, fn ($v) => (float) $v != 0.0);
        if (!empty($extra)) {
            $this->line('');
            $this->warn('  Conceptos calculados que NO están en la tabla target (dinero real sin bucket manual):');
            foreach ($extra as $label => $amt) {
                $this->line(sprintf('    %-45s $%s', $label, number_format($amt, 2)));
            }
        }

        $totalTarget  = array_sum(self::TARGETS_JUNIO_2026);
        $totalActual  = (float) $global['gastos_operativos'];
        $this->line('');
        $this->info(sprintf('  TOTAL TARGET:    $%s', number_format($totalTarget, 2)));
        $this->info(sprintf('  TOTAL CALCULADO: $%s', number_format($totalActual, 2)));
        $this->info(sprintf('  DIFERENCIA:      $%s', number_format($totalActual - $totalTarget, 2)));

        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ DETALLE POR SUCURSAL ════');
            foreach ($branches as $b) {
                if ((float) $b['gastos_operativos'] == 0.0) continue;
                $this->line(sprintf('  %-20s $%s', $b['sucursal'], number_format($b['gastos_operativos'], 2)));
            }
        }

        if ($this->option('export')) {
            $this->exportCsv($periodId, $current, $extra);
        }

        $this->line('');
        $ok = abs($totalActual - $totalTarget) < 0.01;
        $this->info($ok ? '  ✓ OPEX coincide con el target.' : '  ✗ OPEX NO coincide con el target — revisar arriba.');
        $this->line('');

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function exportCsv(int $periodId, array $current, array $extra): void
    {
        $dir  = 'auditorias';
        $file = "gastos_periodo_{$periodId}_" . now()->format('Ymd_His') . '.csv';
        $path = "{$dir}/{$file}";
        $csv  = static fn (string $v): string => '"' . str_replace('"', '""', $v) . '"';

        $lines = [implode(',', ['Concepto', 'Target', 'Calculado', 'Diferencia', 'Estado'])];
        foreach (self::TARGETS_JUNIO_2026 as $label => $target) {
            $actual = (float) ($current[$label] ?? 0.0);
            $diff   = $actual - $target;
            $lines[] = implode(',', [
                $csv($label), number_format($target, 2, '.', ''), number_format($actual, 2, '.', ''),
                number_format($diff, 2, '.', ''), abs($diff) < 0.01 ? 'Cuadra' : 'No cuadra',
            ]);
        }
        foreach ($extra as $label => $amt) {
            $lines[] = implode(',', [$csv($label . ' (fuera de target)'), '0.00', number_format((float) $amt, 2, '.', ''), number_format((float) $amt, 2, '.', ''), 'No cuadra']);
        }

        Storage::disk('local')->makeDirectory($dir);
        Storage::disk('local')->put($path, implode("\n", $lines));
        $this->info("  CSV exportado: storage/app/{$path}");
    }
}
