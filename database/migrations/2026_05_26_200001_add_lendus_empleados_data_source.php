<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('data_sources')->updateOrInsert(
            ['code' => 'lendus_empleados'],
            [
                'name'                    => 'Reporte de empleados Lendus',
                'description'             => 'Catálogo auxiliar de empleados exportado desde Lendus (hoja Employees). Solo para resolución de sucursal — no afecta nómina ni cartera.',
                'is_active'               => true,
                'is_required_for_bd'      => false,
                'is_required_for_report'  => false,
                'created_at'              => now(),
                'updated_at'              => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('data_sources')->where('code', 'lendus_empleados')->delete();
    }
};
