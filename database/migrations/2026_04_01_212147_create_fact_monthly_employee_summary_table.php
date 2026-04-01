<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('fact_monthly_employee_summary', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total_payments', 14, 2)->default(0);
            $table->decimal('total_bonuses', 14, 2)->default(0);
            $table->decimal('total_discounts', 14, 2)->default(0);
            $table->decimal('total_expenses', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->boolean('has_useful_movement')->default(false);
            $table->boolean('included_in_report')->default(true);
            $table->string('exclusion_reason')->nullable();
            $table->timestamps();
            $table->unique(['period_id', 'employee_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('fact_monthly_employee_summary');
    }

};
