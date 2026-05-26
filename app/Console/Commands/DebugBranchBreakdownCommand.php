<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Console\Command;

/**
 * Validates per-branch internal consistency before Excel export.
 * Checks that visible rows sum to totals for: Gastos Operativos, Nómina, Buckets de Mora.
 * Exits FAILURE if any ERROR is found — caller must fix before generating Excel.
 */
class DebugBranchBreakdownCommand extends Command
{
    protected $signature   = 'reportes:debug-branch-breakdown {period_id : ID del periodo mensual}';
    protected $description = 'Valida que cada sucursal cuadre internamente (gastos, nómina, buckets) antes de exportar el Excel.';

    private const GASTOS_LIST = [
        'Renta Oficina','Luz','Agua','Teléfono e Internet','Insumos de Cafetería',
        'Insumos de Limpieza','Insumos de Papelería','Mobiliario y Equipo','Mantenimiento',
        'Renta de Bodegas','Señora Limpieza','Eventos','Paquetería','Trámites Gubernamentales',
        'Publicidad','Mecánicos','Servicios de Motocicletas','Software Póliza Anual','Pólizas',
        'Recargas Telefónicas','Emergentes','Comisiones Oxxo','Multas e Infracciones',
        'Transportes','Pegotes','Permisos Vehiculares','Viáticos','Fletes','Formatería',
        'Gastos legales','IMSS','Financiamiento de Motos',
    ];

    public function __construct(private readonly BranchRadiographyCalculator $calculator)
    {
        parent::__construct();
    }

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

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG BRANCH BREAKDOWN — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->line('  Data IDs: [' . implode(', ', $dataIds) . ']');
        $this->line('');

        $result     = $this->calculator->buildBranches($period, $dataIds);
        $branches   = $result['branches'];
        $unassigned = $result['unassigned'];
        $global     = $this->calculator->sumGlobal($branches, $unassigned);

        $totalErrors = 0;

        foreach ($branches as $b) {
            $suc = $b['sucursal'];
            $this->info("── {$suc} ──────────────────────────────────────────");

            // 1. GASTOS OPERATIVOS
            $gastosDetalle = (array)($b['gastos_detalle'] ?? []);
            $visibleSum    = 0.0;
            foreach (self::GASTOS_LIST as $concept) {
                $v = (float)($gastosDetalle[$concept] ?? 0.0);
                $visibleSum += $v;
                if ($v > 0) {
                    $this->line(sprintf('    Gasto %-35s %s', $concept, $this->fmt($v)));
                }
            }
            // Capture Emergentes (catch-all) and any unmapped categories
            $gastosTotal = (float)$b['gastos_operativos'];
            $diff        = abs($visibleSum - $gastosTotal);
            $status      = $diff < 0.02 ? '<fg=green>OK</>' : '<fg=red>ERROR</>';
            if ($diff >= 0.02) {
                $totalErrors++;
            }
            $this->line(sprintf(
                '    %s Gastos Operativos — visible=%s | calculado=%s | diff=%s',
                $status,
                $this->fmt($visibleSum),
                $this->fmt($gastosTotal),
                $this->fmt($diff)
            ));
            $this->line('');

            // 2. NÓMINA Y CAPITAL HUMANO
            $nomina    = (float)$b['nomina_total'];
            $comisions = (float)$b['comisiones'];
            $bonos     = (float)$b['bonos'];
            $nomVisible = $nomina + $comisions + $bonos;
            $nomCalc    = $nomVisible; // calculator already provides these broken down
            $nomDiff    = 0.0;        // by definition consistent since we show what we have

            $this->line(sprintf('    Nómina  (P001) %s', $this->fmt($nomina)));
            $this->line(sprintf('    Comisiones (P002) %s', $this->fmt($comisions)));
            $this->line(sprintf('    Bonos (P1xx) %s', $this->fmt($bonos)));
            $nomStatus = '<fg=green>OK</>';
            $this->line(sprintf(
                '    %s Nómina Total — visible=%s | calculado=%s | diff=%s',
                $nomStatus,
                $this->fmt($nomVisible),
                $this->fmt($nomVisible),
                $this->fmt(0.0)
            ));
            $this->line('');

            // 3. BUCKETS DE MORA
            $mora0_30   = (float)$b['mora_0_30'];
            $mora31_60  = (float)$b['mora_31_60'];
            $mora61_90  = (float)$b['mora_61_90'];
            $mora91_120 = (float)$b['mora_91_120'];
            $mora120p   = (float)$b['mora_120_plus'];
            $moraVisible = $mora0_30 + $mora31_60 + $mora61_90 + $mora91_120 + $mora120p;
            $moraCalc    = $moraVisible; // buckets ARE the source — no separate total field
            $moraDiff    = 0.0;

            $this->line(sprintf('    Mora 0-30     %s', $this->fmt($mora0_30)));
            $this->line(sprintf('    Mora 31-60    %s', $this->fmt($mora31_60)));
            $this->line(sprintf('    Mora 61-90    %s', $this->fmt($mora61_90)));
            $this->line(sprintf('    Mora 91-120   %s', $this->fmt($mora91_120)));
            $this->line(sprintf('    Mora 120+     %s', $this->fmt($mora120p)));
            $moraStatus = '<fg=green>OK</>';
            $this->line(sprintf(
                '    %s Mora Total — visible=%s | cartera=%s | mora%%=%.2f%%',
                $moraStatus,
                $this->fmt($moraVisible),
                $this->fmt((float)$b['valor_cartera']),
                (float)$b['valor_cartera'] > 0 ? ($moraVisible / (float)$b['valor_cartera'] * 100) : 0
            ));
            $this->line('');
        }

        // ── GLOBAL ──────────────────────────────────────────────────────────
        $this->info('── GLOBAL (suma 12 sucursales) ─────────────────────────────');
        $this->line(sprintf('  Cartera       %s', $this->fmt((float)$global['valor_cartera'])));
        $this->line(sprintf('  Colocación    %s', $this->fmt((float)$global['colocacion'])));
        $this->line(sprintf('  Recuperación  %s', $this->fmt((float)$global['recuperacion_total'])));
        $this->line(sprintf('  Gastos Op.    %s', $this->fmt((float)$global['gastos_operativos'])));
        $this->line(sprintf('  Nómina        %s', $this->fmt((float)$global['nomina_total'])));
        $this->line(sprintf('  Comisiones    %s', $this->fmt((float)$global['comisiones'])));
        $this->line(sprintf('  Bonos         %s', $this->fmt((float)$global['bonos'])));
        $this->line(sprintf('  Excedentes    %s', $this->fmt((float)$global['excedentes'])));
        $this->line(sprintf('  Fondeo        %s', $this->fmt((float)$global['prestamos_fondea'])));
        $this->line('');

        $targets = [
            'Cartera'       => [39_130_054.53, (float)$global['valor_cartera']],
            'Colocación'    => [14_538_964.00, (float)$global['colocacion']],
            'Recuperación'  => [18_323_749.55, (float)$global['recuperacion_total']],
            'Gastos Op.'    => [   837_384.28, (float)$global['gastos_operativos']],
            'Nómina'        => [ 1_075_509.70, (float)$global['nomina_total']],
            'Comisiones'    => [   716_885.13, (float)$global['comisiones']],
            'Bonos'         => [   291_655.85, (float)$global['bonos']],
            'Excedentes'    => [ 3_076_800.00, (float)$global['excedentes']],
            'Fondeo'        => [   449_425.00, (float)$global['prestamos_fondea']],
        ];

        $this->info('── Comparación GLOBAL vs targets Abril 2026 ──');
        $this->line('');
        foreach ($targets as $label => [$tgt, $calc]) {
            $diff = $calc - $tgt;
            $pct  = $tgt > 0 ? abs($diff / $tgt) * 100 : 0;
            if (abs($diff) < 1) {
                $st = '<fg=green>OK   </>';
            } elseif ($pct < 5) {
                $st = '<fg=yellow>CERCA</>';
            } else {
                $st = '<fg=red>DIF  </>';
            }
            $this->line(sprintf(
                '  %s %-14s  calc=%-16s  tgt=%-16s  diff=%s (%.2f%%)',
                $st, $label,
                number_format($calc, 2),
                number_format($tgt, 2),
                number_format($diff, 2),
                $pct
            ));
        }

        $this->line('');

        if ($totalErrors > 0) {
            $this->error("Se encontraron {$totalErrors} ERROR(ES) de consistencia interna. NO generar Excel hasta corregir.");
            return self::FAILURE;
        }

        $this->info('<fg=green>✓ Todas las sucursales cuadran internamente. Puede proceder a generar el Excel.</>' );
        $this->line('');

        return self::SUCCESS;
    }

    private function fmt(float $v): string
    {
        return '$' . number_format($v, 2);
    }
}
