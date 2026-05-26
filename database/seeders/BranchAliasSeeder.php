<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds known non-operative branch aliases so the system understands
 * which sucursales to exclude from the operational report.
 *
 * Run: php artisan db:seed --class=BranchAliasSeeder
 */
class BranchAliasSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // ── Sucursal cerrada ────────────────────────────────────────────
            [
                'alias'                         => 'SAN JUAN DEL RÍO',
                'scope'                         => 'sucursal_cerrada',
                'include_in_operational_report' => false,
                'reason'                        => 'Sucursal cerrada. Excluida del reporte operativo a partir de mayo 2026.',
                'is_active'                     => true,
            ],
            // ── Corporativo ─────────────────────────────────────────────────
            [
                'alias'                         => 'CORPORATIVO',
                'scope'                         => 'corporativo',
                'include_in_operational_report' => false,
                'reason'                        => 'Centro de costos corporativo. No es una sucursal operativa.',
                'is_active'                     => true,
            ],
            // ── Fuera de alcance ────────────────────────────────────────────
            [
                'alias'                         => 'AGUASCALIENTES',
                'scope'                         => 'fuera_de_alcance',
                'include_in_operational_report' => false,
                'reason'                        => 'Sucursal fuera del alcance operativo del reporte mensual.',
                'is_active'                     => true,
            ],
            [
                'alias'                         => 'CHIHUAHUA',
                'scope'                         => 'fuera_de_alcance',
                'include_in_operational_report' => false,
                'reason'                        => 'Sucursal fuera del alcance operativo del reporte mensual.',
                'is_active'                     => true,
            ],
            [
                'alias'                         => 'DURANGO',
                'scope'                         => 'fuera_de_alcance',
                'include_in_operational_report' => false,
                'reason'                        => 'Sucursal fuera del alcance operativo del reporte mensual.',
                'is_active'                     => true,
            ],
            [
                'alias'                         => 'TULANCINGO',
                'scope'                         => 'fuera_de_alcance',
                'include_in_operational_report' => false,
                'reason'                        => 'Sucursal fuera del alcance operativo del reporte mensual.',
                'is_active'                     => true,
            ],
            [
                'alias'                         => 'PACHUCA',
                'scope'                         => 'fuera_de_alcance',
                'include_in_operational_report' => false,
                'reason'                        => 'Sucursal fuera del alcance operativo del reporte mensual.',
                'is_active'                     => true,
            ],
        ];

        foreach ($rows as $row) {
            DB::table('branch_aliases')->updateOrInsert(
                ['alias' => $row['alias']],
                array_merge($row, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }

        $this->command->info('BranchAliasSeeder: ' . count($rows) . ' aliases sembrados.');
    }
}
