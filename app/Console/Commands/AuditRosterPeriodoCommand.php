<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\PeriodEmployeeRosterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditRosterPeriodoCommand extends Command
{
    protected $signature = 'reportes:audit-roster-periodo
                                {period_id}
                                {--detail : listar empleado por empleado}
                                {--rebuild : reconstruir el roster antes de auditar}';

    protected $description = 'Auditoría del roster de empleados (NOI normal + fiscal): altas, bajas, sin sucursal, duplicados.';

    public function handle(PeriodEmployeeRosterService $rosterService): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA ROSTER DE EMPLEADOS — {$period->label} (ID {$period->id})");
        $this->info('  Fuente: NOI Nómina + NOI Nómina Fiscal (NUNCA Lendus)');
        $this->info('════════════════════════════════════════════════════════════');

        if ($this->option('rebuild')) {
            $stats = $rosterService->buildForPeriod($period);
            $this->line('  Roster reconstruido: ' . json_encode($stats));
        }

        $rows = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->orderByDesc('is_active_for_period')
            ->orderBy('branch_name')
            ->orderBy('nombre_normalizado')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn('  Sin roster construido para este periodo. Ejecuta con --rebuild.');
            return self::SUCCESS;
        }

        $active   = $rows->where('is_active_for_period', true);
        $bajas    = $rows->where('is_active_for_period', false);
        $altas    = $active->where('movement_type', 'alta');
        $activos  = $active->where('movement_type', 'activo');
        $sinSuc   = $active->whereNull('branch_id');
        $soloNormal = $active->where('appears_in_nomina_normal', true)->where('appears_in_nomina_fiscal', false);
        $soloFiscal = $active->where('appears_in_nomina_normal', false)->where('appears_in_nomina_fiscal', true);
        $ambos      = $active->where('appears_in_nomina_normal', true)->where('appears_in_nomina_fiscal', true);

        $this->line('');
        $this->info('════ RESUMEN ════');
        $this->table(['Métrica', 'Valor'], [
            ['empleados_actuales_noi (activos este periodo)', $active->count()],
            ['altas_detectadas', $altas->count()],
            ['bajas_detectadas', $bajas->count()],
            ['empleados_sin_sucursal', $sinSuc->count()],
            ['empleados_aparecen_solo_en_normal', $soloNormal->count()],
            ['empleados_aparecen_solo_en_fiscal', $soloFiscal->count()],
            ['empleados_en_ambos_noi', $ambos->count()],
        ]);

        if ($this->option('detail')) {
            $this->line('');
            $this->info('════ ALTAS (nuevo este periodo) ════');
            $this->table(['Empleado', 'Sucursal', 'Fuente'], $altas->map(fn ($r) => [
                $r->nombre_normalizado, $r->branch_name ?? '— sin sucursal —', $r->source,
            ])->values()->all());

            $this->line('');
            $this->info('════ BAJAS (activo periodo anterior, ausente ahora) ════');
            $this->table(['Empleado', 'Última sucursal'], $bajas->map(fn ($r) => [
                $r->nombre_normalizado, $r->branch_name ?? '— sin sucursal —',
            ])->values()->all());

            if ($sinSuc->isNotEmpty()) {
                $this->line('');
                $this->info('════ SIN SUCURSAL (excluidos de Rotación/IMSS por sucursal) ════');
                $this->table(['Empleado', 'Fuente'], $sinSuc->map(fn ($r) => [
                    $r->nombre_normalizado, $r->source,
                ])->values()->all());
            }
        }

        $prevPeriodId = $active->first()->period_id ?? null;
        $prevPeriod   = $period->previousMonthly(Period::all());
        if ($prevPeriod) {
            $prevCount = DB::table('period_employee_rosters')
                ->where('period_id', $prevPeriod->id)
                ->where('is_active_for_period', true)
                ->count();
            $this->line('');
            $this->line("  Periodo anterior: {$prevPeriod->label} (ID {$prevPeriod->id}) — empleados_periodo_anterior_noi: {$prevCount}");
            if ($prevCount === 0) {
                $this->warn('  ⚠ El periodo anterior no tiene roster (probablemente no se subió/reconstruyó NOI para ese mes).');
                $this->warn('  ⚠ Esto infla altas y anula bajas — no es un error de cálculo, es falta de dato base.');
            }
        } else {
            $this->warn('  ⚠ No hay periodo mensual anterior — no se puede calcular altas/bajas reales.');
        }

        return self::SUCCESS;
    }
}
