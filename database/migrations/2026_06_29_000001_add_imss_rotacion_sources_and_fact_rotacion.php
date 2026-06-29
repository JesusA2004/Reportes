<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Data sources for IMSS and Rotación files
        DB::table('data_sources')->updateOrInsert(
            ['code' => 'imss'],
            [
                'name'      => 'IMSS — Cuota patronal por sucursal',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('data_sources')->updateOrInsert(
            ['code' => 'rotacion'],
            [
                'name'      => 'Índice de Rotación de Personal',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        // Table for rotation index data (per month, per branch)
        if (!Schema::hasTable('fact_rotacion')) {
            Schema::create('fact_rotacion', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('period_id');
                $table->unsignedBigInteger('report_upload_id');
                $table->string('sucursal_nombre', 120)->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->string('mes', 20)->nullable();           // 'ABRIL', 'MAYO', etc.
                $table->integer('bajas')->default(0);
                $table->decimal('promedio_personal', 10, 4)->default(0);
                $table->decimal('indice_rotacion', 10, 4)->default(0);
                $table->string('hoja_fuente', 100)->nullable();  // Nombre de la hoja usada
                $table->json('raw_payload')->nullable();
                $table->timestamps();

                $table->index('period_id');
                $table->index('report_upload_id');
                $table->index('branch_id');
                $table->foreign('period_id')->references('id')->on('periods')->onDelete('cascade');
                $table->foreign('report_upload_id')->references('id')->on('report_uploads')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fact_rotacion');
        DB::table('data_sources')->where('code', 'imss')->delete();
        DB::table('data_sources')->where('code', 'rotacion')->delete();
    }
};
