<?php

namespace App\Services;

use App\Models\Period;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PeriodGenerationService
{
    /**
     * @return Collection<int, Period>
     */
    public function generate(int $year, int $month, string $type): Collection
    {
        return match ($type) {
            'weekly' => $this->generateWeekly($year, $month),
            'bimonthly' => $this->generateBimonthly($year, $month),
            'quarterly' => $this->generateQuarterly($year, $month),
            'semiannual' => $this->generateSemiannual($year, $month),
            'annual' => $this->generateAnnual($year),
            default => throw new \InvalidArgumentException("Tipo de periodo no soportado: {$type}"),
        };
    }

    private function getWeeklySequenceStart(int $year, Carbon $start): int
    {
        $yearStart = Carbon::create($year, 1, 1)->startOfDay();
        $firstSunday = $yearStart->copy()->endOfWeek(Carbon::SUNDAY);

        // La semana 1 especial de inicio de año
        if ($start->betweenIncluded($yearStart, $firstSunday)) {
            return 1;
        }

        $firstMondayAfterOpeningWeek = $firstSunday->copy()->addDay()->startOfDay();

        return 2 + (int) floor(
            $firstMondayAfterOpeningWeek->diffInDays($start) / 7
        );
    }

    /**
     * Semana 1: día 1 al primer domingo. Luego lunes-domingo.
     *
     * @return Collection<int, Period>
     */
    private function generateWeekly(int $year, int $month): Collection
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        // Enero: excepción, empieza en el día 1
        // Resto de meses: empieza en el primer lunes del mes
        if ($month === 1) {
            $cursor = $monthStart->copy();
        } else {
            $cursor = $monthStart->copy()->next(Carbon::MONDAY);

            // Si el día 1 ya es lunes, next() se va al siguiente lunes, así que corregimos
            if ($monthStart->dayOfWeek === Carbon::MONDAY) {
                $cursor = $monthStart->copy();
            }
        }

        // Si por alguna razón el primer lunes ya cae fuera del mes, no hay periodos
        if ($cursor->gt($monthEnd)) {
            return collect();
        }

        $sequence = $this->getWeeklySequenceStart($year, $cursor);
        $periods = collect();

        while ($cursor->lte($monthEnd)) {
            $start = $cursor->copy();

            $end = ($year === (int) $start->year && $month === 1 && $sequence === 1)
                ? $start->copy()->endOfWeek(Carbon::SUNDAY)
                : $start->copy()->endOfWeek(Carbon::SUNDAY);

            $monthName = ucfirst($start->translatedFormat('F'));
            $code = sprintf('W-%04d-%02d-%02d', $year, $month, $sequence);
            $name = sprintf('Semana %d - %s %d', $sequence, $monthName, $start->year);

            $period = Period::query()->updateOrCreate(
                [
                    'type' => 'weekly',
                    'year' => $year,
                    'month' => $month,
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

            $cursor = $end->copy()->addDay()->startOfDay();
            $sequence++;
        }

        return $periods;
    }

    /**
     * Quincenal dentro del mes base:
     * 1) 1-15
     * 2) 16-fin de mes
     *
     * @return Collection<int, Period>
     */
    private function generateBimonthly(int $year, int $month): Collection
    {
        $periods = collect();
        $firstStart = Carbon::create($year, $month, 1)->startOfDay();
        $firstEnd = Carbon::create($year, $month, 15)->startOfDay();
        $secondStart = Carbon::create($year, $month, 16)->startOfDay();
        $secondEnd = Carbon::create($year, $month, 1)->endOfMonth()->startOfDay();
        $ranges = [
            1 => [$firstStart, $firstEnd],
            2 => [$secondStart, $secondEnd],
        ];
        foreach ($ranges as $sequence => [$start, $end]) {
            $name = sprintf('Quincena %d - %s %d', $sequence, ucfirst($start->translatedFormat('F')), $year);
            $code = sprintf('B-%04d-%02d-%02d', $year, $month, $sequence);
            $period = Period::query()->updateOrCreate(
                [
                    'type' => 'bimonthly',
                    'year' => $year,
                    'month' => $month,
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
        }
        return $periods;
    }

    /**
     * Trimestre calendario según el mes base seleccionado.
     *
     * @return Collection<int, Period>
     */
    private function generateQuarterly(int $year, int $month): Collection {
        $quarter = (int) ceil($month / 3);
        $startMonth = (($quarter - 1) * 3) + 1;
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addMonths(2)->endOfMonth()->startOfDay();
        $period = Period::query()->updateOrCreate(
            [
                'type' => 'quarterly',
                'year' => $year,
                'month' => $startMonth,
                'sequence' => 1,
            ],
            [
                'name' => sprintf('Trimestre %d - %d', $quarter, $year),
                'code' => sprintf('Q-%04d-%02d', $year, $quarter),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'is_closed' => false,
            ],
        );

        return collect([$period]);
    }

    /**
     * Semestre calendario según el mes base seleccionado.
     *
     * @return Collection<int, Period>
     */
    private function generateSemiannual(int $year, int $month): Collection
    {
        $semester = $month <= 6 ? 1 : 2;
        $startMonth = $semester === 1 ? 1 : 7;
        $start = Carbon::create($year, $startMonth, 1)->startOfDay();
        $end = $start->copy()->addMonths(5)->endOfMonth()->startOfDay();
        $period = Period::query()->updateOrCreate(
            [
                'type' => 'semiannual',
                'year' => $year,
                'month' => $startMonth,
                'sequence' => 1,
            ],
            [
                'name' => sprintf('Semestre %d - %d', $semester, $year),
                'code' => sprintf('S-%04d-%02d', $year, $semester),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'is_closed' => false,
            ],
        );
        return collect([$period]);
    }

    /**
     * Año calendario completo.
     *
     * @return Collection<int, Period>
     */
    private function generateAnnual(int $year): Collection {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->startOfDay();
        $period = Period::query()->updateOrCreate(
            [
                'type' => 'annual',
                'year' => $year,
                'month' => 1,
                'sequence' => 1,
            ],
            [
                'name' => sprintf('Anual %d', $year),
                'code' => sprintf('A-%04d', $year),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'is_closed' => false,
            ],
        );
        return collect([$period]);
    }

}
