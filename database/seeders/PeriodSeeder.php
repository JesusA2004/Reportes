<?php

namespace Database\Seeders;

use App\Services\WeeklyPeriodGeneratorService;
use Illuminate\Database\Seeder;

class PeriodSeeder extends Seeder {

    public function run(): void {
        $generator = app(WeeklyPeriodGeneratorService::class);

        $generator->generateForMonth(2026, 1);
        $generator->generateForMonth(2026, 2);
        $generator->generateForMonth(2026, 3);
    }

}
