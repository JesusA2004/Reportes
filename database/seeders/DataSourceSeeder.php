<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class DataSourceSeeder extends Seeder {

    public function run(): void {
        $sources = [
            [
                'code' => 'noi_nomina',
                'name' => 'NOI Nómina',
                'description' => 'Archivo general de nómina de NOI',
                'is_active' => true,
            ],
            [
                'code' => 'lendus_ministraciones',
                'name' => 'Lendus Ministraciones',
                'description' => 'Archivo de colocación / ministraciones',
                'is_active' => true,
            ],
            [
                'code' => 'lendus_ingresos_cobranza',
                'name' => 'Lendus Ingresos Cobranza',
                'description' => 'Archivo de ingresos y recuperación',
                'is_active' => true,
            ],
            [
                'code' => 'lendus_saldos_cliente',
                'name' => 'Lendus Saldos por Cliente',
                'description' => 'Archivo de cartera / saldos por cliente',
                'is_active' => true,
            ],
            [
                'code' => 'gastos',
                'name' => 'Gastos',
                'description' => 'Archivo de gastos operativos',
                'is_active' => true,
            ],
            [
                'code' => 'radiografia',
                'name' => 'Radiografía Final',
                'description' => 'Archivo final de referencia',
                'is_active' => true,
            ],
            [
                'code' => 'macro_analisis',
                'name' => 'Macro Análisis',
                'description' => 'Plantilla macro/intermedia actual',
                'is_active' => true,
            ],
        ];

        foreach ($sources as $source) {
            DataSource::updateOrCreate(
                ['code' => $source['code']],
                $source
            );
        }
    }

}
