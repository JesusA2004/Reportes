<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Console\Command;

class DebugGestorReportCommand extends Command
{
    protected $signature   = 'reportes:debug-gestor-report {period_id} {--gestor= : Nombre parcial del gestor para filtrar}';
    protected $description = 'Muestra datos disponibles por gestor desde el snapshot: recuperación, colocación, cartera, mora, nómina.';

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

        $this->info("══════ DEBUG GESTOR REPORT — {$period->label} ══════");

        $snap    = app(RadiographySnapshotBuilder::class)->build($period, $summary);
        $gestores = $snap['sections']['employees_gestores'] ?? [];

        // Filter by partial name if provided
        $filterName = $this->option('gestor');
        if ($filterName) {
            $filterNorm = strtoupper(trim($filterName));
            $gestores = array_filter($gestores, fn ($g) =>
                str_contains(strtoupper(trim($g['name'] ?? '')), $filterNorm)
            );
        }

        $this->line("\n<fg=cyan>FUENTES DE DATOS POR GESTOR</>");
        $this->line("  Fuente principal: snapshot.sections.employees_gestores");
        $this->line("  Campos disponibles: name, code, branch, route, pagos, bonos, descuentos, neto, gastos, colocacion, operaciones, recuperacion, cartera, vencida, mora");
        $this->line("  Gestores en snapshot: " . count($snap['sections']['employees_gestores'] ?? []));
        if ($filterName) {
            $this->line("  Filtro aplicado: '{$filterName}' → " . count($gestores) . " resultados");
        }

        $this->newLine();

        // Summary stats
        $totalRec  = array_sum(array_column($gestores, 'recuperacion'));
        $totalCol  = array_sum(array_column($gestores, 'colocacion'));
        $totalCart = array_sum(array_column($gestores, 'cartera'));
        $totalVenc = array_sum(array_column($gestores, 'vencida'));
        $totalPagos = array_sum(array_column($gestores, 'pagos'));

        $this->line('<fg=cyan>TOTALES AGREGADOS (suma de gestores)</>');
        $this->line(sprintf('  Recuperación: %s', number_format($totalRec, 2)));
        $this->line(sprintf('  Colocación:   %s', number_format($totalCol, 2)));
        $this->line(sprintf('  Cartera:      %s', number_format($totalCart, 2)));
        $this->line(sprintf('  Vencida:      %s', number_format($totalVenc, 2)));
        $this->line(sprintf('  Pagos nómina: %s', number_format($totalPagos, 2)));

        $brGlobal = $snap['branch_radiography']['global'] ?? [];
        $this->newLine();
        $this->line('<fg=cyan>VS GLOBAL branch_radiography</>');
        $this->line(sprintf('  Recuperación global: %s', number_format((float)($brGlobal['recuperacion_total'] ?? 0), 2)));
        $this->line(sprintf('  Colocación global:   %s', number_format((float)($brGlobal['colocacion'] ?? 0), 2)));
        $this->line(sprintf('  Cartera global:      %s', number_format((float)($brGlobal['valor_cartera'] ?? 0), 2)));
        $this->line(sprintf('  Nómina global (P001):  %s', number_format((float)($brGlobal['nomina_total'] ?? 0), 2)));

        // Table per gestor
        $this->newLine();
        $showAll = count($gestores) <= 30 || $filterName;
        if (!$showAll) {
            $this->line('<fg=yellow>Mostrando top 30 por recuperación. Usa --gestor="nombre" para filtrar.</>');
        }

        usort($gestores, fn ($a, $b) => (float)($b['recuperacion'] ?? 0) <=> (float)($a['recuperacion'] ?? 0));
        $rows = $showAll ? $gestores : array_slice($gestores, 0, 30);

        $tableData = [];
        foreach ($rows as $g) {
            $cart = (float)($g['cartera'] ?? 0);
            $venc = (float)($g['vencida'] ?? 0);
            $moraPct = $cart > 0 ? round($venc / $cart * 100, 2) : 0;
            $tableData[] = [
                mb_substr($g['name'] ?? '', 0, 28),
                mb_substr($g['branch'] ?? '', 0, 12),
                mb_substr($g['route'] ?? '', 0, 10),
                number_format((float)($g['recuperacion'] ?? 0), 0, '.', ','),
                number_format((float)($g['colocacion'] ?? 0), 0, '.', ','),
                number_format($cart, 0, '.', ','),
                number_format($venc, 0, '.', ','),
                $moraPct . '%',
                number_format((float)($g['pagos'] ?? 0), 0, '.', ','),
                (int)($g['operaciones'] ?? 0),
            ];
        }

        $this->table(
            ['Gestor', 'Sucursal', 'Ruta', 'Recuperación', 'Colocación', 'Cartera', 'Vencida', 'Mora%', 'Nómina', 'Ops'],
            $tableData
        );

        // Branches breakdown
        $this->newLine();
        $this->line('<fg=cyan>GESTORES POR SUCURSAL</>');
        $byBranch = [];
        foreach ($gestores as $g) {
            $br = $g['branch'] ?? 'Sin sucursal';
            $byBranch[$br] = ($byBranch[$br] ?? 0) + 1;
        }
        arsort($byBranch);
        foreach ($byBranch as $br => $cnt) {
            $this->line("  {$br}: {$cnt} gestores");
        }

        // Field presence check
        $this->newLine();
        $this->line('<fg=cyan>CAMPOS DISPONIBLES POR GESTOR (fuente: employees_gestores)</>');
        $fields = ['recuperacion' => 'Recuperación (Ingresos Cobranza)', 'colocacion' => 'Colocación (Ministraciones)',
            'cartera' => 'Cartera (Saldos Cliente)', 'vencida' => 'Vencida (Saldos Cliente)',
            'pagos' => 'Pagos (NOI)', 'bonos' => 'Bonos (NOI)', 'gastos' => 'Gastos asignados'];
        foreach ($fields as $field => $desc) {
            $nonZero = count(array_filter($gestores, fn ($g) => (float)($g[$field] ?? 0) > 0));
            $total   = count($gestores);
            $status  = $nonZero > 0 ? '<fg=green>✓</>' : '<fg=yellow>0</>';
            $this->line("  {$status} {$desc}: {$nonZero}/{$total} gestores con datos > 0");
        }

        $this->newLine();
        $this->info('══════ FIN DEBUG GESTOR REPORT ══════');
        return self::SUCCESS;
    }
}
