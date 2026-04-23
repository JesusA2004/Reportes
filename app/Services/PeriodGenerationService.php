<?php

namespace App\Services;

use App\Models\Period;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PeriodGenerationService {

    public function generate(int $year, int $month, string $type): Collection
    {
        if ($type === 'weekly') {
            $weekly = $this->generateWeekly($year, $month);
            $derived = $this->syncDerivedPeriods($year);

            return $weekly->concat($derived)->values();
        }
        return $this->syncDerivedPeriods($year);
    }

    private function getWeeklySequenceStart(int $year, Carbon $start): int
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $firstSunday = $yearStart->copy()->endOfWeek(Carbon::SUNDAY);
        if ($start->betweenIncluded($yearStart, $firstSunday)) {
            return 1;
        }
        $firstMondayAfterOpeningWeek = $firstSunday->copy()->addDay()->startOfDay();
        return 2 + (int) floor(
            $firstMondayAfterOpeningWeek->diffInDays($start) / 7
        );
    }

    /**
     * Regla semanal:
     * - Enero arranca con una semana especial del día 1 al primer domingo.
     * - Después, todas las semanas son lunes a domingo.
     * - Si la última semana cruza de mes, se respeta completa.
     */
    private function generateWeekly(int $year, int $month): Collection
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();
        if ($month === 1) {
            $cursor = $monthStart->copy();
        } else {
            $cursor = $monthStart->dayOfWeek === Carbon::MONDAY
                ? $monthStart->copy()
                : $monthStart->copy()->next(Carbon::MONDAY);
        }
        if ($cursor->gt($monthEnd)) {
            return collect();
        }
        $sequence = $this->getWeeklySequenceStart($year, $cursor);
        $periods = collect();
        $keptIds = [];
        while ($cursor->lte($monthEnd)) {
            $start = $cursor->copy();
            $end = $start->copy()->endOfWeek(Carbon::SUNDAY);
            $periodMonth = (int) $start->month;
            $periodYear = (int) $start->year;
            $name = sprintf(
                'Semana %d - %s %d',
                $sequence,
                ucfirst($start->translatedFormat('F')),
                $periodYear
            );
            $code = sprintf('W-%04d-%02d', $year, $sequence);
            $period = Period::query()->updateOrCreate(
                [
                    'type' => 'weekly',
                    'year' => $periodYear,
                    'month' => $periodMonth,
                    'sequence' => $sequence,
                ],
                [
                    'name' => $name,
                    'code' => $code,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'is_closed' => false,
                ],
            );
            $periods->push($period);
            $keptIds[] = $period->id;
            $cursor = $end->copy()->addDay()->startOfDay();
            $sequence++;
        }
        Period::query()
            ->where('type', 'weekly')
            ->where('year', $year)
            ->where('month', $month)
            ->when(!empty($keptIds), fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
        return $periods->values();
    }

    private function syncDerivedPeriods(int $year): Collection {
        return collect()
            ->concat($this->syncBimonthlyPeriods($year))
            ->concat($this->syncQuarterlyPeriods($year))
            ->concat($this->syncSemiannualPeriods($year))
            ->concat($this->syncAnnualPeriods($year))
            ->values();
    }

    private function syncBimonthlyPeriods(int $year): Collection {
        $blocks = [
            1 => [1, 2],
            2 => [3, 4],
            3 => [5, 6],
            4 => [7, 8],
            5 => [9, 10],
            6 => [11, 12],
        ];
        $periods = collect();
        $keptIds = [];
        foreach ($blocks as $sequence => $months) {
            $period = $this->syncGroupedPeriod(
                type: 'bimonthly',
                year: $year,
                sequence: $sequence,
                months: $months,
                name: sprintf('Bimestre %d - %d', $sequence, $year),
                code: sprintf('BI-%04d-%02d', $year, $sequence),
            );
            if ($period) {
                $periods->push($period);
                $keptIds[] = $period->id;
            }
        }
        Period::query()
            ->where('type', 'bimonthly')
            ->where('year', $year)
            ->when(!empty($keptIds), fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
        return $periods->values();
    }

    private function syncQuarterlyPeriods(int $year): Collection {
        $blocks = [
            1 => [1, 2, 3],
            2 => [4, 5, 6],
            3 => [7, 8, 9],
            4 => [10, 11, 12],
        ];
        $periods = collect();
        $keptIds = [];
        foreach ($blocks as $sequence => $months) {
            $period = $this->syncGroupedPeriod(
                type: 'quarterly',
                year: $year,
                sequence: $sequence,
                months: $months,
                name: sprintf('Trimestre %d - %d', $sequence, $year),
                code: sprintf('TRI-%04d-%02d', $year, $sequence),
            );
            if ($period) {
                $periods->push($period);
                $keptIds[] = $period->id;
            }
        }
        Period::query()
            ->where('type', 'quarterly')
            ->where('year', $year)
            ->when(!empty($keptIds), fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
        return $periods->values();
    }

    private function syncSemiannualPeriods(int $year): Collection {
        $blocks = [
            1 => [1, 2, 3, 4, 5, 6],
            2 => [7, 8, 9, 10, 11, 12],
        ];
        $periods = collect();
        $keptIds = [];
        foreach ($blocks as $sequence => $months) {
            $period = $this->syncGroupedPeriod(
                type: 'semiannual',
                year: $year,
                sequence: $sequence,
                months: $months,
                name: sprintf('Semestre %d - %d', $sequence, $year),
                code: sprintf('SEM-%04d-%02d', $year, $sequence),
            );
            if ($period) {
                $periods->push($period);
                $keptIds[] = $period->id;
            }
        }
        Period::query()
            ->where('type', 'semiannual')
            ->where('year', $year)
            ->when(!empty($keptIds), fn ($query) => $query->whereNotIn('id', $keptIds))
            ->delete();
        return $periods->values();
    }

    private function syncAnnualPeriods(int $year): Collection {
        $months = range(1, 12);
        $period = $this->syncGroupedPeriod(
            type: 'annual',
            year: $year,
            sequence: 1,
            months: $months,
            name: sprintf('Anual %d', $year),
            code: sprintf('AN-%04d', $year),
        );
        if (!$period) {
            Period::query()
                ->where('type', 'annual')
                ->where('year', $year)
                ->delete();

            return collect();
        }
        return collect([$period]);
    }

    private function syncGroupedPeriod(
        string $type,
        int $year,
        int $sequence,
        array $months,
        string $name,
        string $code
    ): ?Period {
        $weeks = $this->getWeeklyPeriodsForMonths($year, $months);
        if (!$this->monthsAreReady($weeks, $months)) {
            return null;
        }
        $firstWeek = $weeks->sortBy('start_date')->first();
        $lastWeek = $weeks->sortByDesc('end_date')->first();
        if (!$firstWeek || !$lastWeek) {
            return null;
        }
        return Period::query()->updateOrCreate(
            [
                'type' => $type,
                'year' => $year,
                'month' => (int) min($months),
                'sequence' => $sequence,
            ],
            [
                'name' => $name,
                'code' => $code,
                'start_date' => $firstWeek->start_date->toDateString(),
                'end_date' => $lastWeek->end_date->toDateString(),
                'is_closed' => false,
            ],
        );
    }

    private function getWeeklyPeriodsForMonths(int $year, array $months): Collection
    {
        return Period::query()
            ->where('type', 'weekly')
            ->where('year', $year)
            ->whereIn('month', $months)
            ->orderBy('start_date')
            ->get();
    }

    private function monthsAreReady(Collection $weeks, array $months): bool
    {
        $availableMonths = $weeks
            ->pluck('month')
            ->map(fn ($month) => (int) $month)
            ->unique()
            ->values()
            ->all();
        foreach ($months as $month) {
            if (!in_array((int) $month, $availableMonths, true)) {
                return false;
            }
        }
        return true;
    }

}
