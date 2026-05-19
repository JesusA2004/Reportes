<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Radiography\RadiographySnapshotBuilder;
use Illuminate\Console\Command;

class DebugUiOptionsCommand extends Command
{
    protected $signature   = 'reportes:debug-ui-options {period_id}';
    protected $description = 'Muestra exactamente qué branches, employees y periods se envían al frontend para el periodo dado.';

    private const OPERATIVE_BRANCH_NAMES = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN JUAN DEL RÍO',
        'SAN LUIS POTOSI', 'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
    ];

    public function handle(): int
    {
        $periodId = (int) $this->argument('period_id');
        $period   = Period::find($periodId);
        if (!$period) {
            $this->error("Periodo {$periodId} no encontrado.");
            return self::FAILURE;
        }

        $this->info("══════ DEBUG UI OPTIONS — {$period->label} ══════");

        $summary = PeriodSummary::where('period_id', $periodId)->where('status', 'generated')->first();
        $snapshot = null;
        if ($summary) {
            $snapshot = app(RadiographySnapshotBuilder::class)->build($period, $summary);
            $this->line('<fg=green>✓</> Snapshot encontrado (summary ID=' . $summary->id . ')');
        } else {
            $this->line('<fg=yellow>⚠</> Sin snapshot generado para este periodo.');
        }

        // ── BRANCHES ─────────────────────────────────────────────────────────
        $this->line("\n<fg=cyan>BRANCHES enviados al frontend</>");
        $branches = Branch::query()
            ->whereIn('name', self::OPERATIVE_BRANCH_NAMES)
            ->orderBy('name')
            ->get(['id', 'name']);

        $this->line('  Encontradas: ' . $branches->count() . ' de 13');
        if ($branches->count() !== 13) {
            $this->line('  <fg=red>ERROR: Se esperaban 13, se encontraron ' . $branches->count() . '</>');
            $missing = array_diff(self::OPERATIVE_BRANCH_NAMES, $branches->pluck('name')->toArray());
            foreach ($missing as $m) {
                $this->line('    <fg=red>✗ Falta en DB:</> ' . $m);
            }
        }
        $this->table(['ID', 'Nombre'], $branches->map(fn ($b) => [$b->id, $b->name])->toArray());

        // ── EMPLOYEES ────────────────────────────────────────────────────────
        $this->line("\n<fg=cyan>GESTORES enviados al frontend</>");
        if ($snapshot) {
            $gestores  = $snapshot['sections']['employees_gestores'] ?? [];
            $this->line('  employees_gestores en snapshot: ' . count($gestores));

            $empNames  = array_unique(array_column($gestores, 'name'));
            $allEmpIds = Employee::orderBy('full_name')->get(['id', 'full_name'])
                ->keyBy(fn ($e) => strtoupper(trim($e->full_name)));

            $matched = 0;
            $unmatched = [];
            foreach ($empNames as $name) {
                $key = strtoupper(trim($name));
                if ($allEmpIds->has($key)) {
                    $matched++;
                } else {
                    $unmatched[] = $name;
                }
            }
            $this->line('  Con match en DB employees: ' . $matched . ' de ' . count($empNames));
            if (!empty($unmatched)) {
                $this->line('  <fg=yellow>Sin match (primeros 10):</>');
                foreach (array_slice($unmatched, 0, 10) as $u) {
                    $this->line('    - ' . $u);
                }
            }
        } else {
            $total = Employee::count();
            $this->line('  Sin snapshot — fallback: todos los employees (' . $total . ')');
        }

        // ── ALL PERIODS ──────────────────────────────────────────────────────
        $this->line("\n<fg=cyan>PERIODOS enviados al frontend (allPeriods)</>");
        $periodsWithSnap = PeriodSummary::where('status', 'generated')->pluck('period_id')->flip();
        $allPeriods = Period::orderByDesc('year')->orderByDesc('month')->orderByDesc('sequence')
            ->get(['id', 'name', 'code', 'type', 'year', 'month']);

        $byType = $allPeriods->groupBy('type');
        foreach ($byType as $type => $ps) {
            $this->line("  {$type}: " . $ps->count() . " periodos, con snapshot: " .
                $ps->filter(fn ($p) => $periodsWithSnap->has($p->id))->count());
        }

        $this->line('  Total: ' . $allPeriods->count() . ' periodos');

        // ── COMPARATIVO FILTER ────────────────────────────────────────────────
        $this->line("\n<fg=cyan>FILTRO COMPARATIVO</>");
        $types = ['monthly' => 'Mes vs Mes', 'bimestral' => 'Bimestre', 'quarterly' => 'Trimestre', 'weekly' => 'Semana'];
        foreach ($types as $type => $label) {
            $count = $allPeriods->where('type', $type)->count();
            $withSnap = $allPeriods->where('type', $type)->filter(fn ($p) => $periodsWithSnap->has($p->id))->count();
            $this->line("  {$label}: {$count} periodos, {$withSnap} con radiografía (habilitados para comparativo)");
        }

        $this->newLine();
        $this->info('══════ FIN DEBUG UI OPTIONS ══════');
        return self::SUCCESS;
    }
}
