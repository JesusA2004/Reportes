<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deactivate the old generic gastos source
        DB::table('data_sources')
            ->where('code', 'gastos')
            ->update(['is_active' => false]);

        // Add Gastos Lendus (PDF)
        DB::table('data_sources')->updateOrInsert(
            ['code' => 'gastos_lendus'],
            [
                'name'        => 'Gastos Lendus (PDF)',
                'description' => 'Reporte de gastos operativos exportado desde Lendus en formato PDF',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );

        // Add Gastos ERP (Excel)
        DB::table('data_sources')->updateOrInsert(
            ['code' => 'gastos_erp'],
            [
                'name'        => 'Gastos ERP (Requisiciones)',
                'description' => 'Reporte de requisiciones aprobadas exportado desde el ERP en formato Excel',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]
        );
    }

    public function down(): void
    {
        DB::table('data_sources')->where('code', 'gastos_lendus')->delete();
        DB::table('data_sources')->where('code', 'gastos_erp')->delete();
        DB::table('data_sources')->where('code', 'gastos')->update(['is_active' => true]);
    }
};
