<?php

namespace App\Services;

use App\Models\Period;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PeriodGenerationService {

    // ── Punto de entrada principal ────────────────────────────────────

    public function generate(int $year, int $month, string $type, array $weekIds = []): Collection
    {
        if ($type === 'weekly') {
            $weekly  = $this->generateWeekly($year, $month);
            $derived = $this->syncDerivedPeriods($year);
            return $weekly->concat($derived)->values();
        }

        if ($type === 'monthly') {
            $monthly = $this->generateMonthly($year, $month, $weekIds);
            $derived = $this->syncDerivedPeriods($year);
            return collect([$monthly])->concat($derived)->values();
        }

        return $this->syncDerivedPeriods($year);
    }

    // ── Periodos semanales (base) ─────────────────────────────────────

    private function getWeeklySequenceStart(int $year, Carbon $start): int
    {
        $yearStart   = Carbon::create($year, 1, 1)->startOfDay();
        $firstSunday = $yearStart->copy()->endOfWeek(Carbon::SUNDAY);

        if ($start->betweenIncluded($yearStart, $firstSunday)) {
            return 1;
        }

        $firstMondayAfterOpeningWeek = $firstSunday->copy()->addDay()->startOfDay();
        return 2 + (int) floor($firstMondayAfterOpeningWeek->diffInDays($start) / 7);
    }

    private function generateWeekly(int $year, int $month): Collection
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd   = $monthStart->copy()->endOfMonth()->startOfDay();

        $cursor = $month === 1
            ? $monthStart->copy()
            : ($monthStart->dayOfWeek === Carbon::MONDAY
                ? $monthStart->copy()
                : $monthStart->copy()->next(Carbon::MONDAY));

        if ($cursor->gt($monthEnd)) {
            return collect();
        }

        $sequence = $this->getWeeklySequenceStart($year, $cursor);
        $periods  = collect();
        $keptIds  = [];

        while ($cursor->lte($monthEnd)) {
            $start       = $cursor->copy();
            $end         = $start->copy()->endOfWeek(Carbon::SUNDAY);
            $periodMonth = (int) $start->month;
            $periodYear  = (int) $start->year;

            $name = sprintf(
                'Semana %d - %s %d',
                $sequence,
                ucfirst($start->translatedFormat('F')),
                $periodYear
            );
            $code = sprintf('W-%04d-%02d', $year, $sequence);

            $period = Period::query()->updateOrCreate(
                [
                    'type'     => 'weekly',
                    'year'     => $periodYear,
                    'month'    => $periodMonth,
                    'sequence' => $sequence,
                ],
                [
                    'name'       => $name,
                    'code'       => $code,
                    'start_date' => $start->toDateString(),
                    'end_date'   => $end->toDateString(),
                    'is_closed'  => false,
                ],
            );

            $periods->push($period);
            $keptIds[] = $period->id;
            $cursor    = $end->copy()->addDay()->startOfDay();
            $sequence++;
        }

        Period::query()
            ->where('type', 'weekly')
            ->where('year', $year)
            ->where('month', $month)
            ->when(!empty($keptIds), fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();

        return $periods->values();
    }

    // ── Periodos mensuales (operativos) ───────────────────────────────
    // Crea un periodo mensual explícito que agrupa las semanas de ese mes.
    // Si ya existen semanas para ese mes las incluye todas como componentes.

    public function generateMonthly(int $year, int $month, array $weekIds = []): Period
    {
        if (!empty($weekIds)) {
            $weeks = Period::query()
                ->whereIn('id', $weekIds)
                ->orderBy('start_date')
                ->get();
        } else {
            $weeks = $this->getBasePeriodsForMonth($year, $month);
        }

        if ($weeks->isEmpty()) {
            throw new \RuntimeException(
                "No hay semanas generadas para {$year}-{$month}. Genera primero los periodos semanales del mes."
            );
        }

        $monthNames = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo',    4 => 'Abril',
            5 => 'Mayo',  6 => 'Junio',   7 => 'Julio',    8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
        ];
        $monthName    = $monthNames[$month];
        $componentIds = $weeks->sortBy('start_date')->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $firstWeek    = $weeks->sortBy('start_date')->first();
        $lastWeek     = $weeks->sortByDesc('end_date')->first();

        return Period::query()->updateOrCreate(
            [
                'type'     => 'monthly',
                'year'     => $year,
                'month'    => $month,
                'sequence' => 1,
            ],
            [
                'name'                 => "{$monthName} {$year}",
                'code'                 => sprintf('M-%04d-%02d', $year, $month),
                'start_date'           => $firstWeek->start_date->toDateString(),
                'end_date'             => $lastWeek->end_date->toDateString(),
                'component_period_ids' => $componentIds,
                'is_closed'            => false,
            ]
        );
    }

    // ── Periodos derivados automáticos ────────────────────────────────

    public function syncDerivedPeriods(int $year): Collection
    {
        return collect()
            ->concat($this->syncBimonthlyPeriods($year))
            ->concat($this->syncQuarterlyPeriods($year))
            ->concat($this->syncSemiannualPeriods($year))
            ->concat($this->syncAnnualPeriods($year))
            ->values();
    }

    private function syncBimonthlyPeriods(int $year): Collection
    {
        $blocks = [
            1 => [1, 2], 2 => [3, 4],  3 => [5, 6],
            4 => [7, 8], 5 => [9, 10], 6 => [11, 12],
        ];
        return $this->syncGroupedBlocks('bimonthly', $year, $blocks, 'Bimestre', 'BI');
    }

    private function syncQuarterlyPeriods(int $year): Collection
    {
        $blocks = [
            1 => [1, 2, 3], 2 => [4, 5, 6],
            3 => [7, 8, 9], 4 => [10, 11, 12],
        ];
        return $this->syncGroupedBlocks('quarterly', $year, $blocks, 'Trimestre', 'TRI');
    }

    private function syncSemiannualPeriods(int $year): Collection
    {
        $blocks = [
            1 => [1, 2, 3, 4, 5, 6],
            2 => [7, 8, 9, 10, 11, 12],
        ];
        return $this->syncGroupedBlocks('semiannual', $year, $blocks, 'Semestre', 'SEM');
    }

    private function syncAnnualPeriods(int $year): Collection
    {
        $blocks  = [1 => range(1, 12)];
        $periods = $this->syncGroupedBlocks('annual', $year, $blocks, 'Anual', 'AN', includeYearInCode: true);

        if ($periods->isEmpty()) {
            Period::query()->where('type', 'annual')->where('year', $year)->delete();
        }

        return $periods;
    }

    private function syncGroupedBlocks(
        string $type,
        int    $year,
        array  $blocks,
        string $label,
        string $codePrefix,
        bool   $includeYearInCode = false,
    ): Collection {
        $periods = collect();
        $keptIds = [];

        foreach ($blocks as $sequence => $months) {
            $periodName = $type === 'annual'
                ? "{$label} {$year}"
                : sprintf('%s %d - %d', $label, $sequence, $year);

            $code = $includeYearInCode
                ? sprintf('%s-%04d', $codePrefix, $year)
                : sprintf('%s-%04d-%02d', $codePrefix, $year, $sequence);

            $period = $this->syncGroupedPeriod(
                type:     $type,
                year:     $year,
                sequence: $sequence,
                months:   $months,
                name:     $periodName,
                code:     $code,
            );

            if ($period) {
                $periods->push($period);
                $keptIds[] = $period->id;
            }
        }

        Period::query()
            ->where('type', $type)
            ->where('year', $year)
            ->when(!empty($keptIds), fn ($q) => $q->whereNotIn('id', $keptIds))
            ->delete();

        return $periods->values();
    }

    // ── Resolución de un bloque compuesto ────────────────────────────

    private function syncGroupedPeriod(
        string $type,
        int    $year,
        int    $sequence,
        array  $months,
        string $name,
        string $code,
    ): ?Period {
        // Preferir mensuales si existen para TODOS los meses del bloque
        $monthlyPeriods = $this->getMonthlyPeriodsForMonths($year, $months);

        if ($monthlyPeriods->count() === count($months)) {
            $first        = $monthlyPeriods->sortBy('start_date')->first();
            $last         = $monthlyPeriods->sortByDesc('end_date')->first();
            $componentIds = $monthlyPeriods->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        } else {
            // Fallback: semanas directamente
            $weeks = $this->getWeeklyPeriodsForMonths($year, $months);

            if (!$this->monthsAreReady($weeks, $months)) {
                return null;
            }

            $first        = $weeks->sortBy('start_date')->first();
            $last         = $weeks->sortByDesc('end_date')->first();
            $componentIds = $weeks->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        }

        if (!$first || !$last) {
            return null;
        }

        return Period::query()->updateOrCreate(
            [
                'type'     => $type,
                'year'     => $year,
                'month'    => (int) min($months),
                'sequence' => $sequence,
            ],
            [
                'name'                 => $name,
                'code'                 => $code,
                'start_date'           => $first->start_date->toDateString(),
                'end_date'             => $last->end_date->toDateString(),
                'component_period_ids' => $componentIds,
                'is_closed'            => false,
            ],
        );
    }

    // ── Helpers de consulta ───────────────────────────────────────────

    public function getBasePeriodsForMonth(int $year, int $month): Collection
    {
        return Period::query()
            ->where('year', $year)
            ->where('month', $month)
            ->where(function ($q) {
                $q->whereIn('type', ['weekly', 'base', 'week'])
                  ->orWhereNull('component_period_ids');
            })
            ->whereNotIn('type', ['monthly', 'bimonthly', 'quarterly', 'semiannual', 'annual'])
            ->orderBy('start_date')
            ->get();
    }

    private function getWeeklyPeriodsForMonths(int $year, array $months): Collection
    {
        // Use getBasePeriodsForMonth for each month and merge results
        $results = collect();
        foreach ($months as $month) {
            $results = $results->concat($this->getBasePeriodsForMonth($year, (int) $month));
        }
        return $results->unique('id')->sortBy('start_date')->values();
    }

    private function getMonthlyPeriodsForMonths(int $year, array $months): Collection
    {
        return Period::query()
            ->where('type', 'monthly')
            ->where('year', $year)
            ->whereIn('month', $months)
            ->orderBy('start_date')
            ->get();
    }

    private function monthsAreReady(Collection $weeks, array $months): bool
    {
        $available = $weeks->pluck('month')->map(fn ($m) => (int) $m)->unique()->values()->all();
        foreach ($months as $month) {
            if (!in_array((int) $month, $available, true)) {
                return false;
            }
        }
        return true;
    }

}
