<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roster canónico de empleados activos por periodo, construido desde
     * NOI Nómina + NOI Nómina Fiscal (nunca desde Lendus). Fuente única para
     * derivar Rotación de Personal e IMSS patronal. Incluye también filas
     * "baja" (empleados activos el periodo anterior que ya no aparecen) para
     * que la auditoría de movimientos viva en una sola tabla.
     */
    public function up(): void
    {
        Schema::create('period_employee_rosters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('employee_id');
            $table->string('employee_key', 191);
            $table->string('nombre_normalizado', 191);
            $table->string('nombre_original', 191)->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('branch_name', 120)->nullable();
            $table->boolean('is_branch_operativa')->default(false);
            $table->string('source', 20)->default('noi_nomina'); // noi_nomina | noi_nomina_fiscal | ambos
            $table->boolean('appears_in_nomina_normal')->default(false);
            $table->boolean('appears_in_nomina_fiscal')->default(false);
            $table->boolean('is_active_for_period')->default(true);
            $table->string('movement_type', 20)->nullable(); // alta | baja | activo | sin_cambio
            $table->timestamps();

            $table->unique(['period_id', 'employee_id']);
            $table->index('period_id');
            $table->index('branch_id');
            $table->foreign('period_id')->references('id')->on('periods')->onDelete('cascade');
            $table->foreign('employee_id')->references('id')->on('employees')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_employee_rosters');
    }
};
