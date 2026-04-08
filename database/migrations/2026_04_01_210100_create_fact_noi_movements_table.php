<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('fact_noi_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')
                ->constrained('periods')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('report_upload_id')
                ->nullable()
                ->constrained('report_uploads')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('concept', 160)->nullable()->index();
            $table->string('concept_type', 60)->nullable()->index();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('quantity', 14, 2)->default(0);
            $table->string('payroll_type', 80)->nullable();
            $table->date('movement_date')->nullable()->index();
            $table->string('raw_row_hash', 100)->nullable()->unique();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'employee_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('fact_noi_movements');
    }

};
