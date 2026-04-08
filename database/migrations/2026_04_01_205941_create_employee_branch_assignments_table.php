<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('employee_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')
                ->constrained('periods')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('employee_id')
                ->constrained('employees')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('source_type', 40)->nullable()->index();
            $table->string('source_reference', 180)->nullable();
            $table->string('match_type', 40)->nullable()->index();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->boolean('was_manual_reviewed')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['period_id', 'employee_id'], 'eba_period_employee_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('employee_branch_assignments');
    }

};
