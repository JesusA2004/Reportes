<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Console\Command;

/**
 * Reconciliación detallada por sección.
 * Registrado como 6 comandos vía aliases en routes/console.php o CommandRegistrar.
 */
class ReconcileSectionCommand extends Command
{
    protected $signature   = 'reportes:reconcile {section} {period_id} {--target= : Valor target manual para comparar}';
    protected $description = 'Conciliación crudo vs procesado para una sección (gastos|nomina|ingresos|cartera|moras|fondeo).';

    public function handle(): int
    {
        $section  = strtolower($this->argument('section'));
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

        $this->info("══════ RECONCILE {$section} — {$period->label} ══════");
        $snap   = app(RadiographySnapshotBuilder::class)->build($period, $summary);
        $global = $snap['branch_radiography']['global'] ?? [];
        $branches = $snap['branch_radiography']['branches'] ?? [];
        $target = $this->option('target') !== null ? (float)$this->option('target') : null;

        match ($section) {
            'gastos'   => $this->reconcileGastos($snap, $global, $branches, $target),
            'nomina'   => $this->reconcileNomina($snap, $global, $branches, $target),
            'ingresos' => $this->reconcileIngresos($snap, $global, $branches, $target),
            'cartera'  => $this->reconcileCartera($snap, $global, $branches, $target),
            'moras'    => $this->reconcileMoras($snap, $global, $branches, $target),
            'fondeo'   => $this->reconcileFondeo($snap, $global, $target),
            default    => $this->error("Sección desconocida: {$section}. Usa: gastos|nomina|ingresos|cartera|moras|fondeo"),
        };

        return self::SUCCESS;
    }

    private function reconcileGastos(array $snap, array $global, array $branches, ?float $target): void
    {
        $this->line("\n<fg=cyan>GASTOS OPERATIVOS</>  (sección 3 del informe)");

        $gastGlobal  = (float)($global['gastos_operativos'] ?? 0);
        $gastDet     = (array)($global['gastos_detalle'] ?? []);
        $gastDetSum  = array_sum($gastDet);
        $gastBrSum   = array_sum(array_column($branches, 'gastos_operativos'));
        $unassigned  = $snap['branch_radiography']['unassigned'] ?? [];
        $gastUnassig = (float)($unassigned['gastos_operativos'] ?? 0);

        $this->line(sprintf('  GLOBAL gastos_operativos:    %s', number_format($gastGlobal, 2)));
        $this->line(sprintf('  Suma gastos_detalle:         %s  %s', number_format($gastDetSum, 2),
            abs($gastDetSum - $gastGlobal) < 1 ? '✓ CUADRADO' : '✗ DIFF=' . number_format(abs($gastDetSum - $gastGlobal), 2)));
        $this->line(sprintf('  Suma 13 branches:            %s', number_format($gastBrSum, 2)));
        $this->line(sprintf('  SIN ASIGNAR gastos:          %s', number_format($gastUnassig, 2)));
        $this->line(sprintf('  Branches + SIN ASIGNAR:      %s  %s',
            number_format($gastBrSum + $gastUnassig, 2),
            abs(($gastBrSum + $gastUnassig) - $gastGlobal) < 1 ? '✓ CUADRADO' : '✗ DIFF'));

        if ($target !== null) {
            $this->compareWithTarget($gastGlobal, $target, 'GASTOS OPERATIVOS');
        }

        $this->line("\n  Conceptos clasificados:");
        arsort($gastDet);
        foreach ($gastDet as $concept => $amt) {
            if ($amt > 0) {
                $this->line(sprintf('    %-38s  %s', $concept, number_format($amt, 2)));
            }
        }

        $this->line("\n<fg=cyan>REGLAS QUE APLICAN AL PROCESADO:</>");
        $this->line('  1. Excluir Fondeo/Excedentes (no son gasto operativo)');
        $this->line('  2. Excluir Norte (sucursal excluida del informe)');
        $this->line('  3. No duplicar Lendus-PDF + Lendus-Excel (usar solo Excel)');
        $this->line('  4. Gasolina/Celular van a Nómina, no a Gastos');
        $this->line('  5. "Emergentes" = todo lo no clasificado → investigar si >50%');
    }

    private function reconcileNomina(array $snap, array $global, array $branches, ?float $target): void
    {
        $this->line("\n<fg=cyan>NÓMINA Y CAPITAL HUMANO</>  (sección 4 del informe)");

        $nomBase = (float)($global['nomina_total'] ?? 0) + (float)($global['comisiones'] ?? 0)
            + (float)($global['bonos'] ?? 0) + (float)($global['vacaciones'] ?? 0)
            + (float)($global['prima_vacacional'] ?? 0);
        $nomDet  = (array)($global['nomina_detalle'] ?? []);
        $nomDetSum = array_sum($nomDet);
        $nomFull = $nomBase + $nomDetSum;
        $nomBrSum = 0.0;
        foreach ($branches as $b) {
            $nomBrSum += (float)($b['nomina_total'] ?? 0) + (float)($b['comisiones'] ?? 0)
                + (float)($b['bonos'] ?? 0) + (float)($b['vacaciones'] ?? 0)
                + (float)($b['prima_vacacional'] ?? 0) + array_sum((array)($b['nomina_detalle'] ?? []));
        }
        $unassigned = $snap['branch_radiography']['unassigned'] ?? [];
        $nomUnassig = (float)($unassigned['nomina_total'] ?? 0) + (float)($unassigned['comisiones'] ?? 0)
            + (float)($unassigned['bonos'] ?? 0);

        $gest = $snap['sections']['employees_gestores'] ?? [];
        $gestNomTotal = array_sum(array_column($gest, 'pagos')) + array_sum(array_column($gest, 'bonos'));

        $this->line(sprintf('  Nómina (P001):               %s', number_format((float)($global['nomina_total'] ?? 0), 2)));
        $this->line(sprintf('  Comisiones (P002):           %s', number_format((float)($global['comisiones'] ?? 0), 2)));
        $this->line(sprintf('  Bonos (P1xx):                %s', number_format((float)($global['bonos'] ?? 0), 2)));
        $this->line(sprintf('  Vacaciones (P009):           %s', number_format((float)($global['vacaciones'] ?? 0), 2)));
        $this->line(sprintf('  Prima vacacional (P010):     %s', number_format((float)($global['prima_vacacional'] ?? 0), 2)));
        $this->line(sprintf('  Conceptos extra (nomina_det):%s', number_format($nomDetSum, 2)));
        $this->line(sprintf('  TOTAL NÓMINA COMPLETA:       %s', number_format($nomFull, 2)));
        $this->line(sprintf('  Suma 13 branches:            %s', number_format($nomBrSum, 2)));
        $this->line(sprintf('  SIN ASIGNAR nómina:          %s', number_format($nomUnassig, 2)));
        $this->line(sprintf('  Gestores pagos+bonos (sum):  %s', number_format($gestNomTotal, 2)));

        if ($target !== null) {
            $this->compareWithTarget($nomFull, $target, 'NÓMINA COMPLETA');
        }

        $this->line("\n  Detalle nomina_detalle:");
        arsort($nomDet);
        foreach ($nomDet as $label => $amt) {
            if ($amt > 0) {
                $this->line(sprintf('    %-38s  %s', $label, number_format($amt, 2)));
            }
        }

        $this->line("\n<fg=cyan>REGLAS QUE APLICAN AL PROCESADO:</>");
        $this->line('  1. NOMINANUEVA + NOMINAF + conceptos NOI = nómina total');
        $this->line('  2. Gasolina + Financiamiento Celular vienen de fact_expenses, van a nómina');
        $this->line('  3. Descuentos D094/D010/D113/D123/D004 reducen neto pero no afectan gasto total');
        $this->line('  4. Finiquito incluido en nómina (concepto de pago, no gasto)');
        $this->line('  5. IMSS + Infonavit son cargas patronales, van en nómina completa');
    }

    private function reconcileIngresos(array $snap, array $global, array $branches, ?float $target): void
    {
        $this->line("\n<fg=cyan>INGRESOS / RECUPERACIÓN</>  (sección 2 del informe)");

        $recGlobal = (float)($global['recuperacion_total'] ?? 0);
        $cap  = (float)($global['capital_recuperado']  ?? 0);
        $int  = (float)($global['interes_recuperado']  ?? 0);
        $imp  = (float)($global['impuesto_recuperado'] ?? 0);
        $chg  = (float)($global['charges']             ?? 0);
        $car  = (float)($global['cargos_inicio']       ?? 0);
        $com  = (float)($global['comision_apertura']   ?? 0);
        $sum  = $cap + $int + $imp + $chg + $car + $com;
        $brSum = array_sum(array_column($branches, 'recuperacion_total'));

        $this->line(sprintf('  Capital recuperado:          %s', number_format($cap, 2)));
        $this->line(sprintf('  Interés recuperado:          %s', number_format($int, 2)));
        $this->line(sprintf('  Impuesto:                    %s', number_format($imp, 2)));
        $this->line(sprintf('  Multas/Cargos:               %s', number_format($chg, 2)));
        $this->line(sprintf('  Cargos de inicio:            %s', number_format($car, 2)));
        $this->line(sprintf('  Comisión apertura:           %s', number_format($com, 2)));
        $this->line(sprintf('  Suma componentes:            %s  %s', number_format($sum, 2),
            abs($sum - $recGlobal) < 1 ? '✓ CUADRADO' : '✗ DIFF=' . number_format(abs($sum - $recGlobal), 2)));
        $this->line(sprintf('  TOTAL RECUPERACIÓN GLOBAL:   %s', number_format($recGlobal, 2)));
        $this->line(sprintf('  Suma 13 branches:            %s  %s', number_format($brSum, 2),
            abs($brSum - $recGlobal) < 1 ? '✓' : 'DIFF=' . number_format(abs($brSum - $recGlobal), 2)));

        if ($target !== null) {
            $this->compareWithTarget($recGlobal, $target, 'RECUPERACIÓN');
        }

        $this->line("\n<fg=cyan>REGLAS QUE APLICAN AL PROCESADO:</>");
        $this->line('  1. Rutas sin mapeo de branch → pagos perdidos en el crudo');
        $this->line('  2. Periodos semanales: algunos pagos pueden estar en semanas no mapeadas');
        $this->line('  3. Prefijos de contrato: algunos contratos no tienen ruta/branch mapeada');
        $this->line('  4. Norte/Corporativo excluidos del global operativo');
        $this->line('  5. Reclasificación: pagos del padre que en crudo aparecen como ruta');
    }

    private function reconcileCartera(array $snap, array $global, array $branches, ?float $target): void
    {
        $this->line("\n<fg=cyan>VALOR DE CARTERA</>  (sección 1 del informe)");

        $cartGlobal = (float)($global['valor_cartera'] ?? 0);
        $cartBrSum  = array_sum(array_column($branches, 'valor_cartera'));
        $unassigned = $snap['branch_radiography']['unassigned'] ?? [];
        $cartUnassig = (float)($unassigned['valor_cartera'] ?? 0);

        $this->line(sprintf('  Cartera GLOBAL:              %s', number_format($cartGlobal, 2)));
        $this->line(sprintf('  Suma 13 branches:            %s', number_format($cartBrSum, 2)));
        $this->line(sprintf('  SIN ASIGNAR:                 %s', number_format($cartUnassig, 2)));
        $this->line(sprintf('  Branches + SIN ASIGNAR:      %s  %s',
            number_format($cartBrSum + $cartUnassig, 2),
            abs(($cartBrSum + $cartUnassig) - $cartGlobal) < 1 ? '✓ CUADRADO' : 'DIFF'));

        if ($target !== null) {
            $this->compareWithTarget($cartGlobal, $target, 'CARTERA');
        }

        $this->line("\n  Top sucursales por cartera:");
        $sorted = $branches;
        usort($sorted, fn ($a, $b) => (float)($b['valor_cartera'] ?? 0) <=> (float)($a['valor_cartera'] ?? 0));
        foreach (array_slice($sorted, 0, 5) as $b) {
            $this->line(sprintf('    %-20s  %s', $b['sucursal'], number_format((float)($b['valor_cartera'] ?? 0), 2)));
        }

        $this->line("\n<fg=cyan>REGLAS QUE APLICAN AL PROCESADO:</>");
        $this->line('  1. Excluir FALSO / estatus inactivo');
        $this->line('  2. Excluir productos no operativos (RECURSOS PROPIOS, etc.)');
        $this->line('  3. Excluir Norte / Aguascalientes / sucursales cerradas');
        $this->line('  4. Contratos reestructurados: usar saldo actual, no original');
        $this->line('  5. Fecha de corte: usar 30/04/2026, no fecha de archivo');
    }

    private function reconcileMoras(array $snap, array $global, array $branches, ?float $target): void
    {
        $this->line("\n<fg=cyan>MORAS / CARTERA VENCIDA</>  (sección del informe)");

        $moraFields = ['mora_0_30' => '0-30', 'mora_31_60' => '31-60', 'mora_61_90' => '61-90',
            'mora_91_120' => '91-120', 'mora_120_plus' => '120+'];
        $moraGlobal = 0.0;
        $moraBrSum  = 0.0;
        foreach ($moraFields as $field => $label) {
            $g = (float)($global[$field] ?? 0);
            $b = array_sum(array_column($branches, $field));
            $moraGlobal += $g;
            $moraBrSum  += $b;
            $this->line(sprintf('  %-10s  GLOBAL=%s  Branches=%s  %s',
                $label, number_format($g, 2), number_format($b, 2),
                abs($g - $b) < 1 ? '✓' : 'DIFF=' . number_format(abs($g - $b), 2)));
        }
        $this->line(sprintf('  TOTAL         GLOBAL=%s  Branches=%s  %s',
            number_format($moraGlobal, 2), number_format($moraBrSum, 2),
            abs($moraGlobal - $moraBrSum) < 1 ? '✓ CUADRADO' : 'DIFF'));

        $cartGlobal = (float)($global['valor_cartera'] ?? 0);
        $moraPct    = $cartGlobal > 0 ? round($moraGlobal / $cartGlobal * 100, 2) : 0;
        $this->line(sprintf('  Mora %% (sobre cartera):     %s%%', $moraPct));

        if ($target !== null) {
            $this->compareWithTarget($moraGlobal, $target, 'MORA TOTAL');
        }

        $this->line("\n<fg=cyan>REGLAS QUE APLICAN AL PROCESADO:</>");
        $this->line('  1. Usar col. "Días Vencido" (no "Días Mora" ni "Días Atraso")');
        $this->line('  2. Fecha de corte: 30/04/2026 (no fecha de generación del archivo)');
        $this->line('  3. Monto: "Vencido" o "Capital Atrasado" (no Saldo Total)');
        $this->line('  4. Excluir contratos con estatus inactivo/castigado');
        $this->line('  5. Excluir Norte/Aguascalientes');
    }

    private function reconcileFondeo(array $snap, array $global, ?float $target): void
    {
        $this->line("\n<fg=cyan>FONDEO / PRÉSTAMOS INTERSUCURSALES</>  (sección 5 del informe)");

        $fondeoGlobal = (float)($global['prestamos_fondea'] ?? 0);
        $loans = $snap['sections']['interbranch_loans'] ?? [];
        $fondeoRaw = array_sum(array_column($loans, 'amount'));

        $this->line(sprintf('  Fondeo GLOBAL (filtrado):    %s', number_format($fondeoGlobal, 2)));
        $this->line(sprintf('  Suma interbranch_loans crudo: %s', number_format($fondeoRaw, 2)));
        $this->line(sprintf('  Diferencia:                  %s', number_format(abs($fondeoGlobal - $fondeoRaw), 2)));

        if (!empty($loans)) {
            $this->line("\n  Primeras filas de interbranch_loans:");
            foreach (array_slice($loans, 0, 8) as $l) {
                $this->line(sprintf('    %-20s → %-20s  %s  %s',
                    mb_substr($l['from_branch'] ?? '?', 0, 20),
                    mb_substr($l['to_branch'] ?? '?', 0, 20),
                    number_format((float)($l['amount'] ?? 0), 2),
                    $l['category'] ?? ''));
            }
        }

        if ($target !== null) {
            $this->compareWithTarget($fondeoGlobal, $target, 'FONDEO');
        }

        $this->line("\n<fg=cyan>REGLAS QUE APLICAN AL PROCESADO:</>");
        $this->line('  1. Excluir filas donde from_branch o to_branch = Norte/Corporativo');
        $this->line('  2. Excluir fondeo interno (sucursal a sí misma)');
        $this->line('  3. Verificar que no estén duplicadas Lendus-PDF + Lendus-Excel');
        $this->line('  4. Confirmar que category = "FONDEO A" o equivalente (no gastos mal clasificados)');
        $this->line('  5. Diferencia +$133K vs target manual: investigar filas extra por branch Norte');
    }

    private function compareWithTarget(float $raw, float $target, string $label): void
    {
        $diff   = $raw - $target;
        $pct    = $target > 0 ? abs($diff) / $target * 100 : 0;
        $status = match(true) {
            abs($diff) < 1                   => 'CUADRADO EXACTO',
            $pct < 0.1                       => 'CUADRADO CON REGLA',
            $pct < 1.5 && abs($diff) < 50000 => 'AJUSTE TRAZABLE',
            default                          => 'NO CUADRADO',
        };
        $color = match($status) {
            'CUADRADO EXACTO'    => 'green',
            'CUADRADO CON REGLA' => 'green',
            'AJUSTE TRAZABLE'    => 'yellow',
            default              => 'red',
        };
        $this->line(sprintf("\n  <fg=cyan>VS TARGET MANUAL:</> %s", $label));
        $this->line(sprintf('    Crudo calculado: %s', number_format($raw, 2)));
        $this->line(sprintf('    Target manual:   %s', number_format($target, 2)));
        $this->line(sprintf('    Diferencia:      %s (%s%%)', number_format($diff, 2), round($pct, 2)));
        $this->line("    Estado:          <fg={$color}>{$status}</>");
    }
}
