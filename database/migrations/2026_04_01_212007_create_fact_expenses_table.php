<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('fact_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')
                ->constrained('periods')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('report_upload_id')
                ->nullable()
                ->constrained('report_uploads')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('employee_id')
                ->nullable()
                ->constrained('employees')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('category', 120)->nullable()->index();
            $table->string('concept', 180)->nullable()->index();
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->date('expense_date')->nullable()->index();
            $table->text('observations')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'branch_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('fact_expenses');
    }

};
