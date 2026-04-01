<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('employee_branch_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type')->default('lendus'); // lendus, manual
            $table->string('source_reference')->nullable();   // archivo o fila origen
            $table->string('match_type')->default('exact');   // exact, normalized, manual, unmatched
            $table->decimal('confidence', 5, 2)->default(100.00);
            $table->boolean('was_manual_reviewed')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['period_id', 'employee_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('employee_branch_assignments');
    }

};
