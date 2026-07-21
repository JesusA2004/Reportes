<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * IMSS derivado desde NOI Nómina Fiscal: colaboradores únicos por sucursal
     * × $3,500. Reemplaza la carga manual del archivo de cuota patronal.
     */
    public function up(): void
    {
        Schema::create('fact_imss', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('period_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('branch_name', 120)->nullable();
            $table->unsignedInteger('collaborators_count')->default(0);
            $table->decimal('monthly_fee_per_employee', 10, 2)->default(3500);
            $table->decimal('amount', 14, 2)->default(0);
            $table->boolean('included_in_report')->default(true);
            $table->string('excluded_reason', 255)->nullable();
            $table->string('source', 30)->default('derived_noi_fiscal');
            $table->timestamps();

            $table->index('period_id');
            $table->index('branch_id');
            $table->foreign('period_id')->references('id')->on('periods')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_imss');
    }
};
