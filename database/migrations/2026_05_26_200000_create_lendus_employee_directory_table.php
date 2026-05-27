<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lendus_employee_directory', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('report_upload_id')->nullable()->index();
            $table->string('codigo', 30)->nullable()->index();
            $table->string('nombre', 200);
            $table->string('normalized_name', 200)->nullable()->index();
            $table->string('puesto', 100)->nullable();
            $table->string('area', 100)->nullable();
            $table->string('promotor', 200)->nullable();
            $table->string('distribuidor', 200)->nullable();
            $table->string('estatus', 50)->nullable()->index();
            $table->date('fecha_inicio')->nullable();
            $table->unsignedBigInteger('inferred_branch_id')->nullable()->index();
            $table->string('inferred_branch_name', 100)->nullable();
            $table->boolean('is_operational')->default(false)->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->foreign('report_upload_id')->references('id')->on('report_uploads')->onDelete('set null');
            $table->foreign('inferred_branch_id')->references('id')->on('branches')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lendus_employee_directory');
    }
};
