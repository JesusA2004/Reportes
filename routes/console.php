<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Aliases for reconcile section commands: reconcile-gastos 5 == reconcile gastos 5
foreach (['gastos', 'nomina', 'ingresos', 'cartera', 'moras', 'fondeo'] as $section) {
    Artisan::command("reportes:reconcile-{$section} {period_id}", function (int $period_id) use ($section) {
        $this->call("reportes:reconcile", ['section' => $section, 'period_id' => $period_id]);
    })->purpose("Conciliación de {$section} para el periodo dado.");
}
