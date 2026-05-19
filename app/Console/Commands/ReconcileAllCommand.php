<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Console\Command;

/**
 * Muestra estado de conciliación crudo vs procesado para cada sección.
 * Para ingresar targets manuales: agregar a reconciliation_targets.json o
 * ejecutar reconcile-gastos, reconcile-nomina, etc. con target.
 */
class ReconcileAllCommand extends Command
{
    protected $signature   = 'reportes:reconcile-all {period_id}';
    protected $description = 'Resumen de conciliación crudo vs procesado para todas las secciones del periodo.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);
        if (!$period) {
            $this->error("Periodo {$periodId} no encontrado.");
            return self::FAILURE;
        }

        $summary = PeriodSummary::where('period_id', $periodId)->where('status', 'generated')->first();
        if (!$summary) {
            $this->error("Sin radiografía generada para el periodo {$periodId}.");
            return self::FAILURE;
        }

        $this->info("══════ CONCILIACIÓN — {$period->label} ══════");
        $this->line('  Modo: CRUDO (snapshot calculado vs cross-validation interna)');
        $this->line('  Para targets manuales: ejecutar sub-comandos con --target=valor');
        $this->newLine();

        $snap    = app(RadiographySnapshotBuilder::class)->build($period, $summary);
        $global  = $snap['branch_radiography']['global'] ?? [];
        $branches = $snap['branch_radiography']['branches'] ?? [];
        $gest    = $snap['sections']['employees_gestores'] ?? [];

        $rows = [];

        // ── CARTERA ──────────────────────────────────────────────────────────
        $cartGlobal = (float)($global['valor_cartera'] ?? 0);
        $cartSum    = array_sum(array_column($branches, 'valor_cartera'));
        $cartDiff   = abs($cartGlobal - $cartSum);
        $rows[]     = $this->buildRow('CARTERA', 'Valor cartera GLOBAL', $cartGlobal, $cartSum,
            'Suma de 13 sucursales', $cartDiff < 1 ? 'CUADRADO EXACTO' : ($cartDiff < $cartGlobal * 0.01 ? 'CUADRADO CON REGLA' : 'NO CUADRADO'),
            'Norte excluido, SIN ASIGNAR no en branches');

        // ── RECUPERACIÓN ─────────────────────────────────────────────────────
        $recGlobal = (float)($global['recuperacion_total'] ?? 0);
        $recSum    = array_sum(array_column($branches, 'recuperacion_total'));
        $recGest   = array_sum(array_column($gest, 'recuperacion'));
        $recDiff   = abs($recGlobal - $recSum);
        $rows[]    = $this->buildRow('INGRESOS', 'Recuperación GLOBAL', $recGlobal, $recSum,
            'Suma de 13 sucursales', $recDiff < 1 ? 'CUADRADO EXACTO' : 'NO CUADRADO',
            'Rutas sin mapeo reducen total; Norte/Corporativo excluidos');

        // ── COLOCACIÓN ───────────────────────────────────────────────────────
        $colGlobal = (float)($global['colocacion'] ?? 0);
        $colSum    = array_sum(array_column($branches, 'colocacion'));
        $colDiff   = abs($colGlobal - $colSum);
        $rows[]    = $this->buildRow('COLOCACIÓN', 'Colocación GLOBAL', $colGlobal, $colSum,
            'Suma de 13 sucursales', $colDiff < 1 ? 'CUADRADO EXACTO' : ($colDiff < $colGlobal * 0.01 ? 'CUADRADO CON REGLA' : 'NO CUADRADO'),
            'Norte/Corporativo excluidos; Aguascalientes no opera');

        // ── MORA ─────────────────────────────────────────────────────────────
        $moraFields = ['mora_0_30','mora_31_60','mora_61_90','mora_91_120','mora_120_plus'];
        $moraGlobal = 0.0;
        $moraSum    = 0.0;
        foreach ($moraFields as $f) {
            $moraGlobal += (float)($global[$f] ?? 0);
            $moraSum    += array_sum(array_column($branches, $f));
        }
        $moraDiff = abs($moraGlobal - $moraSum);
        $rows[] = $this->buildRow('MORAS', 'Mora total GLOBAL', $moraGlobal, $moraSum,
            'Suma de 13 sucursales', $moraDiff < 1 ? 'CUADRADO EXACTO' : 'NO CUADRADO',
            'Fecha de corte y col. Días Vencido (no Días Mora) afectan buckets');

        // ── GASTOS ───────────────────────────────────────────────────────────
        $gastGlobal = (float)($global['gastos_operativos'] ?? 0);
        $gastSum    = array_sum(array_column($branches, 'gastos_operativos'));
        $gastDiff   = abs($gastGlobal - $gastSum);
        $rows[] = $this->buildRow('GASTOS', 'Gastos GLOBAL', $gastGlobal, $gastSum,
            'Suma de 13 sucursales', $gastDiff < 1 ? 'CUADRADO EXACTO' : 'NO CUADRADO',
            'SIN ASIGNAR no incluido en branches; Fondeo/Excedentes excluidos');

        // ── NÓMINA ───────────────────────────────────────────────────────────
        $nomGlobal = (float)($global['nomina_total'] ?? 0) + (float)($global['comisiones'] ?? 0)
            + (float)($global['bonos'] ?? 0) + (float)($global['vacaciones'] ?? 0)
            + (float)($global['prima_vacacional'] ?? 0) + array_sum((array)($global['nomina_detalle'] ?? []));
        $nomGest = array_sum(array_column($gest, 'pagos')) + array_sum(array_column($gest, 'bonos'));
        $nomDiff = abs($nomGlobal - $nomGest);
        $rows[] = $this->buildRow('NÓMINA', 'Nómina GLOBAL (completa)', $nomGlobal, $nomGest,
            'Suma pagos+bonos gestores', $nomDiff < $nomGlobal * 0.05 ? 'CUADRADO CON REGLA' : 'NO CUADRADO',
            'Gestores = nómina NOI; GLOBAL incluye IMSS/Infonavit/finiquito/gasolina extras');

        // ── FONDEO ───────────────────────────────────────────────────────────
        $fondeoGlobal = (float)($global['prestamos_fondea'] ?? 0);
        $loans = $snap['sections']['interbranch_loans'] ?? [];
        $fondeoSum = array_sum(array_column($loans, 'amount'));
        $fondeoDiff = abs($fondeoGlobal - $fondeoSum);
        $rows[] = $this->buildRow('FONDEO', 'Préstamos intersucursales', $fondeoGlobal, $fondeoSum,
            'Suma filas interbranch_loans', $fondeoDiff < 1 ? 'CUADRADO EXACTO' : 'NO CUADRADO',
            'Norte/Corporativo incluidos en crudo; necesita regla de exclusión');

        // Display table
        $this->table(
            ['Sección', 'Métrica', 'Crudo calculado', 'Cross-check', 'Diff', 'Estado', 'Explicación'],
            array_map(fn ($r) => [
                $r['section'], $r['metric'],
                number_format($r['raw'], 2),
                number_format($r['cross'], 2),
                number_format(abs($r['raw'] - $r['cross']), 2),
                $r['status'],
                mb_substr($r['explanation'], 0, 40),
            ], $rows)
        );

        $this->newLine();
        $this->line('<fg=cyan>LEYENDA DE ESTADOS</>');
        $this->line('  CUADRADO EXACTO    — diferencia < $1');
        $this->line('  CUADRADO CON REGLA — diferencia < 1% (Norte/SIN ASIGNAR excluidos)');
        $this->line('  AJUSTE TRAZABLE    — hay ajuste registrado con razón documentada');
        $this->line('  NO CUADRADO        — diferencia > 1%, investigar');

        $this->newLine();
        $this->line('<fg=cyan>COMANDOS DETALLADOS</>');
        foreach (['gastos','nomina','ingresos','cartera','moras','fondeo'] as $cmd) {
            $this->line("  php artisan reportes:reconcile-{$cmd} {$periodId}");
        }

        $this->newLine();
        $this->line('<fg=cyan>PLAN MODO RECONCILED (pendiente de aprobación)</>');
        $this->line('  Tabla: reconciliation_adjustments');
        $this->line('  Cols:  metric, period_id, scope, source_file, source_sheet, source_row,');
        $this->line('         raw_value, target_value, adjustment_value, reason, approved_by_user, created_at');
        $this->line('  Para activar: php artisan migrate (crear tabla) + ingresar ajustes vía web/CLI');

        return self::SUCCESS;
    }

    private function buildRow(
        string $section, string $metric, float $raw, float $cross,
        string $crossLabel, string $status, string $explanation
    ): array {
        return compact('section', 'metric', 'raw', 'cross', 'crossLabel', 'status', 'explanation');
    }
}
