<?php

namespace App\Console\Commands;

use App\Models\Period;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría de cierre de Rotación de Personal — formato completo para revisión
 * manual: resumen general, resumen por sucursal, listas nominales de altas y
 * bajas, cambios de sucursal (no cuentan como alta/baja) y, opcionalmente,
 * comparación contra un control manual externo.
 *
 * Fuente de datos: fact_rotacion (source=derived_noi) y period_employee_rosters
 * — las mismas tablas que ya alimentan Preview.vue/Excel/PDF. Este comando NO
 * recalcula nada, solo presenta lo que ya existe en BD de forma auditable.
 */
class AuditRotacionCierreCommand extends Command
{
    protected $signature = 'reportes:audit-rotacion-cierre
                                {period_id : ID del periodo mensual actual (ej. Junio 2026)}
                                {--control= : ruta a CSV opcional con columnas sucursal,altas,bajas,plantilla para comparar contra un control manual}';

    protected $description = 'Auditoría completa de Rotación de Personal: resumen general, por sucursal, listas nominales de altas/bajas, cambios de sucursal y comparación opcional contra control manual.';

    public function handle(): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return self::FAILURE;
        }

        $prevPeriod = $period->previousMonthly(Period::all());
        if (!$prevPeriod) {
            $this->error("No se encontró periodo mensual anterior a {$period->label}.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA DE CIERRE — ROTACIÓN DE PERSONAL");
        $this->info("  {$prevPeriod->label} → {$period->label}");
        $this->info('════════════════════════════════════════════════════════════════');

        $this->sectionA($period, $prevPeriod);
        $this->sectionB($period, $prevPeriod);
        $this->sectionC($period);
        $this->sectionD($period);
        $this->sectionE($period, $prevPeriod);

        if ($controlPath = $this->option('control')) {
            $this->sectionF($period, $controlPath);
        }

        return self::SUCCESS;
    }

    /** A) Resumen general */
    private function sectionA(Period $period, Period $prevPeriod): void
    {
        $curr = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')
            ->selectRaw('SUM(altas) as altas, SUM(bajas) as bajas, SUM(promedio_personal) as plantilla')->first();
        $prev = DB::table('fact_rotacion')->where('period_id', $prevPeriod->id)->where('source', 'derived_noi')
            ->selectRaw('SUM(promedio_personal) as plantilla')->first();

        $altas     = (int) ($curr->altas ?? 0);
        $bajas     = (int) ($curr->bajas ?? 0);
        $plantilla = (int) ($curr->plantilla ?? 0);
        $indice    = $plantilla > 0 ? round($bajas / $plantilla * 100, 2) : 0.0;

        $this->line('');
        $this->info("════ A) RESUMEN GENERAL — {$prevPeriod->label} vs {$period->label} ════");
        $this->table(
            ["Plantilla {$prevPeriod->label}", "Plantilla {$period->label}", 'Altas', 'Bajas', 'Índice'],
            [[(int) ($prev->plantilla ?? 0), $plantilla, $altas, $bajas, "{$indice}%"]]
        );
    }

    /** B) Resumen por sucursal */
    private function sectionB(Period $period, Period $prevPeriod): void
    {
        $currRows = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')
            ->get()->keyBy('sucursal_nombre');
        $prevRows = DB::table('fact_rotacion')->where('period_id', $prevPeriod->id)->where('source', 'derived_noi')
            ->get()->keyBy('sucursal_nombre');

        $sucursales = $currRows->keys()->merge($prevRows->keys())->unique()->sort()->values();

        $rows = [];
        foreach ($sucursales as $suc) {
            $c = $currRows->get($suc);
            $p = $prevRows->get($suc);
            $plantillaPrev = (int) ($p->promedio_personal ?? 0);
            $plantillaCurr = (int) ($c->promedio_personal ?? 0);
            $rows[] = [
                $suc,
                $plantillaPrev,
                $plantillaCurr,
                (int) ($c->altas ?? 0),
                (int) ($c->bajas ?? 0),
                $c ? number_format((float) $c->indice_rotacion, 2) . '%' : '—',
                ($plantillaCurr - $plantillaPrev >= 0 ? '+' : '') . ($plantillaCurr - $plantillaPrev),
            ];
        }

        $this->line('');
        $this->info('════ B) RESUMEN POR SUCURSAL ════');
        $this->table(
            ['Sucursal', "Plantilla {$prevPeriod->label}", "Plantilla {$period->label}", 'Altas', 'Bajas', 'Índice', 'Variación plantilla'],
            $rows
        );
    }

    /** C) Lista nominal de altas */
    private function sectionC(Period $period): void
    {
        $altas = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('is_branch_operativa', true)
            ->where('is_active_for_period', true)
            ->where('movement_type', 'alta')
            ->orderBy('branch_name')
            ->get(['branch_name', 'nombre_normalizado', 'nombre_original', 'employee_id']);

        $this->line('');
        $this->info("════ C) LISTA NOMINAL DE ALTAS ({$altas->count()}) ════");
        if ($altas->isEmpty()) {
            $this->line('  Sin altas en el periodo.');
            return;
        }
        $this->table(
            ['Sucursal', 'Nombre', 'Clave periodo actual', 'Aparece mes anterior', 'Aparece mes actual'],
            $altas->map(fn ($a) => [$a->branch_name, $a->nombre_original, $a->employee_id, 'No', 'Sí'])->all()
        );
    }

    /** D) Lista nominal de bajas */
    private function sectionD(Period $period): void
    {
        $bajas = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('is_branch_operativa', true)
            ->where('is_active_for_period', false)
            ->where('movement_type', 'baja')
            ->orderBy('branch_name')
            ->get(['branch_name', 'nombre_normalizado', 'nombre_original', 'employee_id']);

        $this->line('');
        $this->info("════ D) LISTA NOMINAL DE BAJAS ({$bajas->count()}) ════");
        if ($bajas->isEmpty()) {
            $this->line('  Sin bajas en el periodo.');
            return;
        }
        $this->table(
            ['Sucursal anterior', 'Nombre', 'Clave periodo anterior', 'Aparece mes anterior', 'Aparece mes actual'],
            $bajas->map(fn ($b) => [$b->branch_name, $b->nombre_original, $b->employee_id, 'Sí', 'No'])->all()
        );
    }

    /** E) Cambios de sucursal — misma identidad, activo en ambos periodos, branch_id distinto */
    private function sectionE(Period $period, Period $prevPeriod): void
    {
        $curr = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('is_active_for_period', true)
            ->get(['nombre_normalizado', 'nombre_original', 'branch_id', 'branch_name'])
            ->keyBy('nombre_normalizado');

        $prev = DB::table('period_employee_rosters')
            ->where('period_id', $prevPeriod->id)
            ->where('is_active_for_period', true)
            ->get(['nombre_normalizado', 'branch_id', 'branch_name'])
            ->keyBy('nombre_normalizado');

        $cambios = [];
        foreach ($curr as $norm => $c) {
            $p = $prev->get($norm);
            if (!$p) continue; // es alta, no cambio de sucursal
            if ((int) $p->branch_id !== (int) $c->branch_id) {
                $cambios[] = [$c->nombre_original, $p->branch_name, $c->branch_name, 'No cuenta como alta/baja'];
            }
        }

        $this->line('');
        $this->info('════ E) CAMBIOS DE SUCURSAL (' . count($cambios) . ') ════');
        if (empty($cambios)) {
            $this->line('  Sin cambios de sucursal en el periodo.');
            return;
        }
        $this->table(['Nombre', 'Sucursal anterior', 'Sucursal actual', 'Nota'], $cambios);
    }

    /** F) Auditoría contra control manual externo (opcional, --control=ruta.csv) */
    private function sectionF(Period $period, string $controlPath): void
    {
        $this->line('');
        $this->info('════ F) AUDITORÍA VS CONTROL MANUAL ════');

        if (!is_file($controlPath) || !is_readable($controlPath)) {
            $this->warn("  No se pudo leer el archivo de control: {$controlPath}");
            return;
        }

        $handle = fopen($controlPath, 'r');
        if (!$handle) {
            $this->warn("  No se pudo abrir el archivo de control: {$controlPath}");
            return;
        }

        $header  = fgetcsv($handle);
        $control = [];
        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, $row);
            if (!$data || empty($data['sucursal'])) continue;
            $control[mb_strtoupper(trim($data['sucursal']))] = [
                'altas'     => (int) ($data['altas'] ?? 0),
                'bajas'     => (int) ($data['bajas'] ?? 0),
                'plantilla' => (int) ($data['plantilla'] ?? 0),
            ];
        }
        fclose($handle);

        if (empty($control)) {
            $this->warn('  El archivo de control no contiene filas válidas (columnas esperadas: sucursal,altas,bajas,plantilla).');
            return;
        }

        $noiRows = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')
            ->get()->keyBy(fn ($r) => mb_strtoupper(trim($r->sucursal_nombre)));

        $altasNominales = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)->where('is_active_for_period', true)->where('movement_type', 'alta')
            ->get()->groupBy(fn ($r) => mb_strtoupper(trim($r->branch_name ?? '')));
        $bajasNominales = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)->where('is_active_for_period', false)->where('movement_type', 'baja')
            ->get()->groupBy(fn ($r) => mb_strtoupper(trim($r->branch_name ?? '')));

        $sucursales = collect(array_keys($control))->merge($noiRows->keys())->unique()->sort()->values();
        $rows = [];
        foreach ($sucursales as $suc) {
            $ctrl = $control[$suc] ?? ['altas' => 0, 'bajas' => 0, 'plantilla' => 0];
            $noi  = $noiRows->get($suc);
            $noiAltas     = (int) ($noi->altas ?? 0);
            $noiBajas     = (int) ($noi->bajas ?? 0);
            $noiPlantilla = (int) ($noi->promedio_personal ?? 0);

            $diffAltas     = $noiAltas - $ctrl['altas'];
            $diffBajas     = $noiBajas - $ctrl['bajas'];
            $diffPlantilla = $noiPlantilla - $ctrl['plantilla'];

            $explica = collect()
                ->merge($altasNominales->get($suc, collect())->pluck('nombre_original')->map(fn ($n) => "+{$n}"))
                ->merge($bajasNominales->get($suc, collect())->pluck('nombre_original')->map(fn ($n) => "-{$n}"))
                ->implode(', ');

            $rows[] = [
                $suc,
                $ctrl['altas'], $noiAltas, $diffAltas,
                $ctrl['bajas'], $noiBajas, $diffBajas,
                $ctrl['plantilla'], $noiPlantilla, $diffPlantilla,
                ($diffAltas || $diffBajas || $diffPlantilla) ? $explica : '—',
            ];
        }

        $this->table(
            ['Sucursal', 'Ctrl altas', 'NOI altas', 'Dif.', 'Ctrl bajas', 'NOI bajas', 'Dif.', 'Ctrl plantilla', 'NOI plantilla', 'Dif.', 'Empleados que explican la diferencia'],
            $rows
        );
    }
}
