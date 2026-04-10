<?php

namespace App\Console\Commands;

use App\Services\WeeklyPeriodGeneratorService;
use Illuminate\Console\Command;

class GenerateWeeklyPeriodsCommand extends Command {

    protected $signature = 'periods:generate-weekly {year : Año del periodo} {month? : Mes del periodo (1-12)}';

    protected $description = 'Genera periodos semanales para un mes con regla: día 1 al primer domingo, luego lunes-domingo.';

    public function handle(WeeklyPeriodGeneratorService $service): int {
        $year = (int) $this->argument('year');
        $monthArgument = $this->argument('month');

        if ($year < 2020 || $year > 2100) {
            $this->error('El año debe estar entre 2020 y 2100.');
            return self::INVALID;
        }

        $months = $monthArgument === null
            ? range(1, 12)
            : [(int) $monthArgument];

        foreach ($months as $month) {
            if ($month < 1 || $month > 12) {
                $this->error('El mes debe estar entre 1 y 12.');
                return self::INVALID;
            }

            $periods = $service->generateForMonth($year, $month);

            $this->line(sprintf('Mes %04d-%02d: %d periodos semanales generados/actualizados.', $year, $month, $periods->count()));
        }

        $this->info('Proceso finalizado.');

        return self::SUCCESS;
    }

}
