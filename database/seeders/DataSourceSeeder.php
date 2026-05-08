<?php

namespace Database\Seeders;

use App\Models\DataSource;
use Illuminate\Database\Seeder;

class DataSourceSeeder extends Seeder {

    public function run(): void {
        $sources = [
            [
                'code'                => 'noi_nomina',
                'name'                => 'NOI Nómina',
                'description'         => 'Archivo general de nómina de NOI',
                'is_active'           => true,
                'is_required_for_bd'  => true,
                'is_required_for_report' => true,
            ],
            [
                'code'                => 'lendus_ministraciones',
                'name'                => 'Lendus Ministraciones',
                'description'         => 'Archivo de colocación / ministraciones',
                'is_active'           => true,
                'is_required_for_bd'  => false,
                'is_required_for_report' => true,
            ],
            [
                'code'                => 'lendus_ingresos_cobranza',
                'name'                => 'Lendus Ingresos Cobranza',
                'description'         => 'Archivo de ingresos y recuperación',
                'is_active'           => true,
                'is_required_for_bd'  => true,
                'is_required_for_report' => true,
            ],
            [
                'code'                => 'lendus_saldos_cliente',
                'name'                => 'Lendus Saldos por Cliente',
                'description'         => 'Archivo de cartera / saldos por cliente',
                'is_active'           => true,
                'is_required_for_bd'  => false,
                'is_required_for_report' => true,
            ],
            [
                'code'                => 'gastos',
                'name'                => 'Gastos (legado)',
                'description'         => 'Fuente genérica de gastos — reemplazada por Gastos Lendus y Gastos ERP',
                'is_active'           => false,
                'is_required_for_bd'  => false,
                'is_required_for_report' => false,
            ],
            [
                'code'                => 'gastos_lendus',
                'name'                => 'Gastos Lendus (PDF)',
                'description'         => 'Reporte de gastos operativos exportado desde Lendus en formato PDF',
                'is_active'           => true,
                'is_required_for_bd'  => false,
                'is_required_for_report' => true,
            ],
            [
                'code'                => 'gastos_erp',
                'name'                => 'Gastos ERP (Requisiciones)',
                'description'         => 'Reporte de requisiciones aprobadas exportado desde el ERP en formato Excel',
                'is_active'           => true,
                'is_required_for_bd'  => false,
                'is_required_for_report' => true,
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
