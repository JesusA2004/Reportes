<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_aliases', function (Blueprint $table) {
            $table->id();
            // Raw name as it appears in uploaded data / branches table
            $table->string('alias');
            // FK to branches.id — null if the alias is not yet linked to a DB branch row
            $table->unsignedBigInteger('branch_id')->nullable();
            // 'operativa', 'ruta', 'corporativo', 'sucursal_cerrada', 'fuera_de_alcance'
            $table->string('scope')->default('ruta');
            // Whether this alias should be included in the operational report
            $table->boolean('include_in_operational_report')->default(false);
            // Human-readable reason for the scope/exclusion decision
            $table->string('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('alias');
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_aliases');
    }
};
