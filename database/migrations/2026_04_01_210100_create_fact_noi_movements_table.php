<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('fact_noi_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_upload_id')->nullable()->constrained()->nullOnDelete();
            $table->string('concept');
            $table->string('concept_type')->nullable(); // pago, bono, incidencia, descuento, etc.
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('quantity', 14, 2)->default(0);
            $table->string('payroll_type')->nullable();
            $table->date('movement_date')->nullable();
            $table->string('raw_row_hash')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('fact_noi_movements');
    }

};
