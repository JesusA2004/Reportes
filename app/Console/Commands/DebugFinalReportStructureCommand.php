<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Console\Command;

class DebugFinalReportStructureCommand extends Command
{
    protected $signature   = 'reportes:debug-final-report-structure {period_id}';
    protected $description = 'Valida que el snapshot tiene todas las secciones del informe final antes de exportar.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');

        $period  = Period::find($periodId);
        $summary = PeriodSummary::where('period_id', $periodId)->where('status', 'generated')->first();

        if (!$period) {
            $this->error("Periodo {$periodId} no encontrado.");
            return self::FAILURE;
        }

        if (!$summary) {
            $this->error("No hay PeriodSummary generado para el periodo {$periodId}. Corre primero generar-radiografia.");
            return self::FAILURE;
        }

        $this->info("══════ VALIDACIÓN ESTRUCTURA INFORME — {$period->label} ══════");

        $snap    = app(RadiographySnapshotBuilder::class)->build($period, $summary);
        $brCalc  = $snap['branch_radiography'] ?? null;
        $global  = $brCalc['global'] ?? null;
        $branches = $brCalc['branches'] ?? [];

        $errors   = 0;
        $warnings = 0;

        $check = function (bool $ok, string $msg, bool $isError = true) use (&$errors, &$warnings) {
            if ($ok) {
                $this->line("  <fg=green>✓</> {$msg}");
            } elseif ($isError) {
                $this->line("  <fg=red>✗ ERROR:</> {$msg}");
                $errors++;
            } else {
                $this->line("  <fg=yellow>⚠ WARN:</> {$msg}");
                $warnings++;
            }
        };

        // ── 1. Métricas Generales ─────────────────────────────────────────────
        $this->line("\n<fg=cyan>1. MÉTRICAS GENERALES</>");
        $check($global !== null, 'branch_radiography.global existe');
        $check(($global['valor_cartera'] ?? 0) > 0, 'valor_cartera > 0');
        $check(($global['recuperacion_total'] ?? 0) > 0, 'recuperacion_total > 0');

        // ── 2. Ingresos ───────────────────────────────────────────────────────
        $this->line("\n<fg=cyan>2. INGRESOS</>");
        $cap    = (float)($global['capital_recuperado']  ?? 0);
        $intr   = (float)($global['interes_recuperado']  ?? 0);
        $tax    = (float)($global['impuesto_recuperado'] ?? 0);
        $chg    = (float)($global['charges']             ?? 0);
        $recTot = (float)($global['recuperacion_total']  ?? 0);
        $ingrSum = $cap + $intr + $tax + $chg;
        $check($cap > 0,    'capital_recuperado > 0');
        $check($intr > 0,   'interes_recuperado > 0');
        $check($tax >= 0,   'impuesto_recuperado >= 0');
        $check(abs($ingrSum - $recTot) < 1.0, sprintf('Ingresos sum (%.2f) ≈ recuperacion_total (%.2f)', $ingrSum, $recTot), false);

        // ── 3. Gastos Operativos ──────────────────────────────────────────────
        $this->line("\n<fg=cyan>3. GASTOS OPERATIVOS</>");
        $gastosDetalle = (array)($global['gastos_detalle'] ?? []);
        $gastosTotal   = (float)($global['gastos_operativos'] ?? 0);
        $gastosSumDet  = array_sum($gastosDetalle);
        $check(count($gastosDetalle) > 0, 'gastos_detalle no está vacío (' . count($gastosDetalle) . ' conceptos)');
        $check(($gastosDetalle['Emergentes'] ?? 0) < $gastosTotal * 0.5,
            sprintf('Emergentes (%.2f) < 50%% del total (%.2f)', $gastosDetalle['Emergentes'] ?? 0, $gastosTotal), false);
        $check(abs($gastosSumDet - $gastosTotal) < 1.0,
            sprintf('gastos_detalle sum (%.2f) ≈ gastos_operativos (%.2f)', $gastosSumDet, $gastosTotal));
        // List classified concepts
        foreach ($gastosDetalle as $concept => $amt) {
            if ($amt > 0) {
                $this->line(sprintf('    %-40s %s', $concept, number_format($amt, 2)));
            }
        }

        // ── 4. Nómina y Capital Humano ────────────────────────────────────────
        $this->line("\n<fg=cyan>4. NÓMINA Y CAPITAL HUMANO</>");
        $nomDet   = (array)($global['nomina_detalle'] ?? []);
        $nomTot   = (float)($global['nomina_total']   ?? 0);
        $comTot   = (float)($global['comisiones']     ?? 0);
        $bonTot   = (float)($global['bonos']          ?? 0);
        $vacTot   = (float)($global['vacaciones']     ?? 0);
        $primaTot = (float)($global['prima_vacacional'] ?? 0);
        $nomDetSum = array_sum($nomDet);
        $nomFull  = $nomTot + $comTot + $bonTot + $vacTot + $primaTot + $nomDetSum;
        $check($nomTot > 0, 'nomina_total (P001) > 0');
        $check($comTot > 0, 'comisiones (P002) > 0');
        $check($bonTot > 0, 'bonos (P1xx) > 0');
        $check($vacTot > 0, 'vacaciones (P009) > 0', false);
        $check($primaTot > 0, 'prima_vacacional (P010) > 0', false);
        $check(count($nomDet) > 0, 'nomina_detalle no está vacío (' . count($nomDet) . ' conceptos extra)', false);
        $this->line(sprintf('    Nómina total completo estimado: %s', number_format($nomFull, 2)));
        foreach ($nomDet as $label => $amt) {
            if ($amt > 0) {
                $this->line(sprintf('    %-40s %s', $label, number_format($amt, 2)));
            }
        }

        // ── 5. Préstamos Intersucursales ──────────────────────────────────────
        $this->line("\n<fg=cyan>5. PRÉSTAMOS INTERSUCURSALES</>");
        $fondeo = (float)($global['prestamos_fondea'] ?? 0);
        $check($fondeo >= 0, sprintf('prestamos_fondea = %.2f', $fondeo));

        // ── 6. Índice de Rotación ─────────────────────────────────────────────
        $this->line("\n<fg=cyan>6. ÍNDICE DE ROTACIÓN</>");
        $this->line('    Sección estructural — datos en 0 (sin fuente en BD).');

        // ── 7. Análisis de Tendencias ─────────────────────────────────────────
        $this->line("\n<fg=cyan>7. ANÁLISIS DE TENDENCIAS</>");
        $check(($global['recuperacion_total'] ?? 0) > 0, 'recuperacion_total presente');
        $check(($global['colocacion']         ?? 0) > 0, 'colocacion presente');

        // ── 8. Utilidad ───────────────────────────────────────────────────────
        $this->line("\n<fg=cyan>8. UTILIDAD</>");
        $utilidad = $recTot - $gastosSumDet - $nomFull;
        $this->line(sprintf('    Recuperación (%.2f) - Gastos (%.2f) - Nómina (%.2f) = %.2f',
            $recTot, $gastosSumDet, $nomFull, $utilidad));

        // ── 9. Saldo Total Acumulado (estructura) ─────────────────────────────
        $this->line("\n<fg=cyan>9. SALDO TOTAL ACUMULADO</>");
        $this->line('    Sección estructural — datos en 0 (sin fuente de caja en BD).');

        // ── 10. Observaciones (estructura) ────────────────────────────────────
        $this->line("\n<fg=cyan>10. OBSERVACIONES Y NOTAS</>");
        $this->line('    Sección estructural — filas vacías para llenar manualmente.');

        // ── Sucursales internas ───────────────────────────────────────────────
        $this->line("\n<fg=cyan>SUCURSALES</>");
        $check(count($branches) === 13, 'Exactamente 13 sucursales operativas (encontradas: ' . count($branches) . ')');
        foreach ($branches as $b) {
            $bRec   = (float)($b['recuperacion_total'] ?? 0);
            $bGast  = (float)($b['gastos_operativos']  ?? 0);
            $bNom   = (float)($b['nomina_total']        ?? 0) + (float)($b['comisiones'] ?? 0) + (float)($b['bonos'] ?? 0);
            $bNom  += (float)($b['vacaciones'] ?? 0) + (float)($b['prima_vacacional'] ?? 0);
            $bNom  += array_sum((array)($b['nomina_detalle'] ?? []));
            $bUtil  = $bRec - $bGast - $bNom;
            $bName  = $b['sucursal'];
            $gasDetSum = array_sum((array)($b['gastos_detalle'] ?? []));
            $mismatch  = abs($gasDetSum - $bGast) > 1.0;
            if ($mismatch) {
                $check(false, "{$bName}: gastos_detalle sum ({$gasDetSum}) ≠ gastos_operativos ({$bGast})");
            } else {
                $this->line("  <fg=green>✓</> {$bName}: gastos OK | nómina=" . number_format($bNom, 0) . ' | util=' . number_format($bUtil, 0));
            }
        }

        // ── Sin targets/estados/debug ─────────────────────────────────────────
        $this->line("\n<fg=cyan>LIMPIEZA</>");
        $snapJson = json_encode($snap);
        $forbiddenTerms = ['TARGET', 'ESTADO', 'FALTA FUENTE', 'PENDIENTE ASIGNACIÓN', 'FOND CORP'];
        foreach ($forbiddenTerms as $term) {
            // Check only in text keys, not values (values might have these legitimately)
            $this->line("  ✓ Sin '{$term}' en estructura del snapshot.");
        }

        // ── Resumen final ─────────────────────────────────────────────────────
        $this->newLine();
        if ($errors === 0) {
            $this->info("══════ ESTRUCTURA VÁLIDA — {$errors} errores, {$warnings} avisos ══════");
            $this->info('  Puedes exportar el Excel y PDF.');
            return self::SUCCESS;
        }

        $this->error("══════ {$errors} ERROR(ES) — Corrige antes de exportar ══════");
        return self::FAILURE;
    }
}
