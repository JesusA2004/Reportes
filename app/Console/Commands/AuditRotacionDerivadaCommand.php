<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\RotacionDerivedFromNoiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditRotacionDerivadaCommand extends Command
{
    protected $signature = 'reportes:audit-rotacion-derivada
                                {period_id}
                                {--detail : listar empleados de alta/baja por sucursal}
                                {--rebuild : re-derivar antes de auditar}';

    protected $description = 'Auditoría Rotación: compara legacy validado (archivo, source=file) contra derivado de NOI (source=derived_noi). El legacy manda mientras exista.';

    public function handle(RotacionDerivedFromNoiService $service): int
    {
        $period = Period::find($this->argument('period_id'));
        if (!$period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");
            return self::FAILURE;
        }

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA ROTACIÓN — {$period->label} (ID {$period->id})");
        $this->info('  Fórmula: Índice = bajas / plantilla actual × 100');
        $this->info('════════════════════════════════════════════════════════════');

        if ($this->option('rebuild')) {
            $res = $service->deriveForPeriod($period);
            $this->line('  Derivado recalculado: ' . json_encode($res));
        }

        $legacyRows = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'file')->orderBy('sucursal_nombre')->get()->keyBy('sucursal_nombre');
        $derivedRows = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')->orderBy('sucursal_nombre')->get()->keyBy('sucursal_nombre');

        if ($legacyRows->isEmpty() && $derivedRows->isEmpty()) {
            $this->warn('  Sin datos en fact_rotacion para este periodo (ni legacy ni derivado). Ejecuta con --rebuild.');
            return self::SUCCESS;
        }

        $sucursales = $legacyRows->keys()->merge($derivedRows->keys())->unique()->sort()->values();

        $tableRows = [];
        foreach ($sucursales as $suc) {
            $l = $legacyRows->get($suc);
            $d = $derivedRows->get($suc);
            $diffAltas = ($d->altas ?? 0) - ($l->altas ?? 0);
            $diffBajas = ($d->bajas ?? 0) - ($l->bajas ?? 0);
            $diffPlant = (int) ($d->promedio_personal ?? 0) - (int) ($l->promedio_personal ?? 0);
            $accion = $l ? 'Usa LEGACY (validado)' : 'Usa DERIVADO (sin archivo)';

            $tableRows[] = [
                $suc,
                $l->altas ?? '—', $d->altas ?? '—',
                $l->bajas ?? '—', $d->bajas ?? '—',
                $l ? (int) $l->promedio_personal : '—', $d ? (int) $d->promedio_personal : '—',
                ($diffAltas || $diffBajas || $diffPlant) ? "altas:{$diffAltas} bajas:{$diffBajas} plant:{$diffPlant}" : 'OK',
                $accion,
            ];
        }

        $this->table(
            ['Sucursal', 'Altas val.', 'Altas der.', 'Bajas val.', 'Bajas der.', 'Plant. val.', 'Plant. der.', 'Diferencia', 'Acción aplicada'],
            $tableRows
        );

        $legacySum = ['altas' => (int) $legacyRows->sum('altas'), 'bajas' => (int) $legacyRows->sum('bajas'), 'plant' => (int) $legacyRows->sum('promedio_personal')];
        $derivedSum = ['altas' => (int) $derivedRows->sum('altas'), 'bajas' => (int) $derivedRows->sum('bajas'), 'plant' => (int) $derivedRows->sum('promedio_personal')];

        $usaLegacy = $legacyRows->isNotEmpty();
        $activo = $usaLegacy ? $legacySum : $derivedSum;
        $indiceGlobal = $activo['plant'] > 0 ? round($activo['bajas'] / $activo['plant'] * 100, 2) : 0.0;

        $this->line('');
        $this->info('════ RESULTADO QUE VE EL REPORTE (' . ($usaLegacy ? 'LEGACY validado' : 'DERIVADO NOI') . ') ════');
        $this->line("  Altas: {$activo['altas']}  |  Bajas: {$activo['bajas']}  |  Plantilla: {$activo['plant']}  |  Índice: {$indiceGlobal}%");

        if ($usaLegacy && $derivedRows->isNotEmpty()) {
            $matches = $legacySum === $derivedSum;
            $this->line('');
            $this->line('  Comparación LEGACY vs DERIVADO (informativa, el derivado NO sustituye mientras exista el archivo):');
            $this->line("    Legacy   -> Altas {$legacySum['altas']} · Bajas {$legacySum['bajas']} · Plantilla {$legacySum['plant']}");
            $this->line("    Derivado -> Altas {$derivedSum['altas']} · Bajas {$derivedSum['bajas']} · Plantilla {$derivedSum['plant']}");
            $this->line($matches ? '    Estado: COINCIDE ✓' : '    Estado: NO coincide — ver detalle con --detail (revisar sucursales listadas arriba)');
        }

        if (!$usaLegacy) {
            $this->line('');
            $this->warn('  No hay archivo legacy para este periodo — se está usando el valor DERIVADO como fuente del reporte.');
        }

        if ($this->option('detail')) {
            $branchRows = DB::table('period_employee_rosters')
                ->where('period_id', $period->id)
                ->where('is_branch_operativa', true)
                ->orderBy('branch_name')
                ->get();

            foreach ($branchRows->groupBy('branch_name') as $branchName => $group) {
                $altasG = $group->where('is_active_for_period', true)->where('movement_type', 'alta');
                $bajasG = $group->where('is_active_for_period', false)->where('movement_type', 'baja');
                if ($altasG->isEmpty() && $bajasG->isEmpty()) continue;

                $this->line('');
                $this->info("  {$branchName} (roster derivado de NOI)");
                foreach ($altasG as $a) $this->line("    + ALTA: {$a->nombre_normalizado}");
                foreach ($bajasG as $b) $this->line("    − BAJA: {$b->nombre_normalizado}");
            }
        }

        return self::SUCCESS;
    }
}
