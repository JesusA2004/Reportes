<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodIncident;
use App\Models\PeriodSummary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DebugIncidentsCommand extends Command
{
    protected $signature   = 'reportes:debug-incidents {period_id : ID del periodo}';
    protected $description = 'Lista todas las incidencias de un periodo con tipo, severidad, mensaje y contexto.';

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);

        if (!$period) {
            $this->error("Periodo #{$periodId} no encontrado.");
            return self::FAILURE;
        }

        $summary = PeriodSummary::query()
            ->where('period_id', $periodId)
            ->with('incidents')
            ->first();

        $this->line('');
        $this->info('══════════════════════════════════════════════════════════════════════');
        $this->info("  DEBUG INCIDENTS — {$period->label} (ID #{$periodId})");
        $this->info('══════════════════════════════════════════════════════════════════════');

        if (!$summary) {
            $this->warn('  No hay PeriodSummary para este periodo. Ejecuta la carga de BD primero.');
            $this->line('');
            return self::SUCCESS;
        }

        $all       = $summary->incidents ?? collect();
        $critical  = $all->filter(fn ($i) => $i->severity === 'high');
        $warnings  = $all->filter(fn ($i) => $i->severity === 'warning');
        $resolved  = $all->filter(fn ($i) => $i->severity === 'resolved');

        $this->line(sprintf(
            '  Total: %d  |  Críticas: %d  |  Advertencias: %d  |  Resueltas: %d',
            $all->count(), $critical->count(), $warnings->count(), $resolved->count()
        ));
        $this->line('');

        if ($all->isEmpty()) {
            $this->info('  <fg=green>Sin incidencias registradas.</>');
            $this->line('');
            return self::SUCCESS;
        }

        // Group by type prefix
        $groups = $all->groupBy(fn ($i) => match (true) {
            str_starts_with($i->type, 'db_update.pending_location') => 'pending_locations',
            str_starts_with($i->type, 'db_update.')                 => 'db_update',
            in_array($i->type, ['mora_alta'])                        => 'mora_alta',
            default                                                  => 'report',
        });

        foreach ([
            'db_update'       => '── Incidencias de carga de BD ──────────────────────────────────────',
            'pending_locations' => '── Ubicaciones pendientes ────────────────────────────────────────────',
            'mora_alta'       => '── Mora alta (alerta financiera, no bloquea) ─────────────────────────',
            'report'          => '── Incidencias de radiografía ───────────────────────────────────────',
        ] as $group => $label) {
            $items = $groups[$group] ?? collect();
            if ($items->isEmpty()) continue;

            $this->info($label);

            foreach ($items as $incident) {
                $severityColor = match ($incident->severity) {
                    'high'     => 'red',
                    'warning'  => 'yellow',
                    'resolved' => 'green',
                    default    => 'white',
                };
                $severityLabel = match ($incident->severity) {
                    'high'     => '[CRÍTICA]',
                    'warning'  => '[ADVERTENCIA]',
                    'resolved' => '[RESUELTA]',
                    default    => "[{$incident->severity}]",
                };

                $this->line(sprintf(
                    '  <fg=%s>%s</> %s',
                    $severityColor,
                    $severityLabel,
                    $incident->type
                ));
                $this->line("    {$incident->message}");

                if (!empty($incident->context)) {
                    $filtered = array_filter($incident->context, fn ($v) => !is_array($v));
                    if (!empty($filtered)) {
                        $ctx = implode(' | ', array_map(
                            fn ($k, $v) => "{$k}: {$v}",
                            array_keys($filtered),
                            array_values($filtered)
                        ));
                        $this->line("    <fg=gray>ctx: {$ctx}</>");
                    }
                }
                $this->line('');
            }
        }

        // Separate: personas sin sucursal (live check)
        $this->info('── Personas sin sucursal (verificación en vivo) ─────────────────────');

        $allPeriods = Period::all();
        $weeklyIds  = $period->resolveBaseWeeklyIds($allPeriods);
        $dataIds    = array_values(array_unique(array_merge(
            empty($weeklyIds) ? [] : $weeklyIds,
            [$periodId]
        )));

        $sinSucursal = DB::table('fact_noi_movements as n')
            ->leftJoin('employee_branch_assignments as eba', function ($j) use ($period, $dataIds) {
                $j->on('eba.employee_id', '=', 'n.employee_id')
                  ->whereIn('eba.period_id', array_merge([$period->id], $dataIds));
            })
            ->leftJoin('employees as emp', 'n.employee_id', '=', 'emp.id')
            ->join('report_uploads as ru', 'n.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('n.period_id', $dataIds)
            ->whereNotNull('n.employee_id')
            ->whereNull('eba.branch_id')
            ->selectRaw('
                n.employee_id,
                COALESCE(emp.full_name, CONCAT("Emp #", n.employee_id)) AS nombre,
                ds.code AS fuente,
                COUNT(DISTINCT n.id) AS movimientos,
                SUM(n.amount) AS monto
            ')
            ->groupBy('n.employee_id', 'emp.full_name', 'ds.id', 'ds.code')
            ->orderByDesc('monto')
            ->get();

        if ($sinSucursal->isEmpty()) {
            $this->line('  <fg=green>✓ Todas las personas tienen sucursal asignada.</>');
        } else {
            $totalPersonas   = $sinSucursal->pluck('employee_id')->unique()->count();
            $totalMovs       = $sinSucursal->sum('movimientos');
            $totalMonto      = $sinSucursal->sum('monto');
            $bySource        = $sinSucursal->groupBy('fuente')->map(fn ($g) => [
                'personas' => $g->pluck('employee_id')->unique()->count(),
                'movs'     => $g->sum('movimientos'),
                'monto'    => $g->sum('monto'),
            ]);

            $this->warn("  {$totalPersonas} persona(s) única(s) sin sucursal:");
            $this->line("  Movimientos totales: {$totalMovs}  |  Monto total: $" . number_format((float) $totalMonto, 2));
            $this->line('  Desglose por fuente:');
            foreach ($bySource as $fuente => $vals) {
                $this->line(sprintf("    %-28s  personas: %d  movs: %d  monto: $%s",
                    $fuente, $vals['personas'], $vals['movs'], number_format((float) $vals['monto'], 2)));
            }

            $incidentSinSucursal = $all->first(fn ($i) => $i->type === 'empleados_sin_sucursal');
            $severity = $incidentSinSucursal?->severity ?? 'N/A';
            $reason   = abs($totalMonto) > 0 ? 'monto relacionado > 0' : 'sin impacto monetario';
            $this->line("  Severity registrada: <fg=" . ($severity === 'high' ? 'red' : 'yellow') . ">{$severity}</>  (razón: {$reason})");
            $this->line('');

            $this->table(
                ['Persona / gestor', 'Fuente', 'Movimientos', 'Monto'],
                $sinSucursal->map(fn ($r) => [
                    mb_substr((string) $r->nombre, 0, 40),
                    $r->fuente,
                    $r->movimientos,
                    '$' . number_format((float) $r->monto, 0),
                ])->all()
            );
        }

        // Pending locations summary
        $this->line('');
        $this->info('── Resumen ejecutivo ─────────────────────────────────────────────────');
        $critCount    = $critical->count();
        $pendingCount = $all->filter(fn ($i) => $i->type === 'db_update.pending_location' && $i->severity !== 'resolved')->count();

        if ($critCount > 0) {
            $this->warn("  {$critCount} incidencia(s) crítica(s) — bloquean la generación.");
        } else {
            $this->line('  <fg=green>Sin incidencias críticas activas.</>');
        }
        if ($pendingCount > 0) {
            $this->warn("  {$pendingCount} ubicación(es) pendiente(s) — resolver desde Etapa 3.");
        }
        $this->line('');

        return $critCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}
