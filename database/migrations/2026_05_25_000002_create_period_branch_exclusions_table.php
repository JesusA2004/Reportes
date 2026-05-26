<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_branch_exclusions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            // Canonical sucursal name (e.g. 'SAN JUAN DEL RÍO', 'CORPORATIVO')
            $table->string('branch_name');
            // FK to branches.id — nullable when the exclusion is by name only
            $table->unsignedBigInteger('branch_id')->nullable();
            // 'sucursal_cerrada', 'corporativo', 'fuera_de_alcance', 'manual'
            $table->string('exclusion_type');
            $table->string('reason')->nullable();
            $table->boolean('exclude_from_operational_report')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['period_id', 'branch_name']);
            $table->foreign('period_id')->references('id')->on('periods')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_branch_exclusions');
    }
};
