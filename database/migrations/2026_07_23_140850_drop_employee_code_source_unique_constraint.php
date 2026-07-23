<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * "Clave del trabajador" (employee_code) NO es un identificador estable entre
     * periodos/archivos NOI — el mismo número identifica personas distintas en meses
     * distintos (confirmado 2026-07-23: clave 36 = RICARDO ROCHA MORA en un periodo,
     * JOSE JUAN MUÑOZ VAZQUEZ en otro; clave 7 = DIEGO MARTINEZ ROMERO en Mayo,
     * ERNESTO LOPEZ LOPEZ en Junio fiscal). Con la restricción UNIQUE(employee_code,
     * source_system), cada reimport de un periodo con un código reutilizado
     * SOBRESCRIBÍA retroactivamente el nombre de un Employee ya vinculado a movimientos
     * de un periodo anterior. La identidad ahora se resuelve por nombre_normalizado
     * (ver NoiNominaImportService::resolveEmployee()); employee_code queda como dato
     * informativo, no como llave de identidad.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique('employees_code_source_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unique(['employee_code', 'source_system'], 'employees_code_source_unique');
        });
    }
};
