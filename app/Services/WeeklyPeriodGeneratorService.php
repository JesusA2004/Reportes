<?php

namespace App\Services;

use App\Models\Period;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WeeklyPeriodGeneratorService {

    /**
     * @return Collection<int, Period>
     */
    public function generateForMonth(int $year, int $month): Collection {
        $cursor = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $cursor->copy()->endOfMonth()->startOfDay();

        $sequence = 1;
        $periods = collect();

        while ($cursor->lte($monthEnd)) {
            $start = $cursor->copy();

            $end = $sequence === 1
                ? $start->copy()->endOfWeek(Carbon::SUNDAY)
                : $start->copy()->addDays(6);

            if ($end->gt($monthEnd)) {
                $end = $monthEnd->copy();
            }

            $name = sprintf('Semana %d - %s %d', $sequence, $start->translatedFormat('F'), $year);
            $code = sprintf('W-%04d-%02d-%02d', $year, $month, $sequence);

            $period = Period::query()->updateOrCreate(
                [
                    'type' => 'weekly',
                    'year' => $year,
                    'month' => $month,
                    'sequence' => $sequence,
                ],
                [
                    'name' => ucfirst($name),
                    'code' => $code,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'is_closed' => false,
                ],
            );

            $periods->push($period);

            $cursor = $end->copy()->addDay()->startOfDay();
            $sequence++;
        }

        return $periods;
    }

}
