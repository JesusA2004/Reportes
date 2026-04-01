<?php

namespace Database\Seeders;

use App\Models\Period;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class PeriodSeeder extends Seeder {

    public function run(): void {
        $periods = [
            ['year' => 2026, 'month' => 1],
            ['year' => 2026, 'month' => 2],
            ['year' => 2026, 'month' => 3],
        ];
        foreach ($periods as $item) {
            $start = Carbon::create($item['year'], $item['month'], 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();
            Period::updateOrCreate(
                ['code' => $start->format('Y-m')],
                [
                    'year' => $item['year'],
                    'month' => $item['month'],
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'is_closed' => false,
                ]
            );
        }
    }

}
