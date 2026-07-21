<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * IMSS y Rotación ya no se suben manualmente — se derivan automáticamente
     * desde NOI Nómina / NOI Nómina Fiscal. Dejan de ser requeridas para
     * "Actualizar BD" ni para "Generar Radiografía"; siguen activas por si
     * algún periodo histórico aún depende de un archivo ya cargado.
     */
    public function up(): void
    {
        DB::table('data_sources')
            ->whereIn('code', ['imss', 'rotacion'])
            ->update([
                'is_required_for_bd'     => false,
                'is_required_for_report' => false,
                'updated_at'             => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('data_sources')
            ->whereIn('code', ['imss', 'rotacion'])
            ->update([
                'is_required_for_bd'     => true,
                'is_required_for_report' => true,
                'updated_at'             => now(),
            ]);
    }
};
