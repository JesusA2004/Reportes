<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('fact_portfolios', function (Blueprint $table) {
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
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('client_name', 180)->nullable();
            $table->string('normalized_client_name', 180)->nullable()->index();
            $table->decimal('balance', 14, 2)->default(0);
            $table->decimal('past_due_balance', 14, 2)->default(0);
            $table->integer('days_past_due')->default(0);
            $table->date('portfolio_date')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'branch_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('fact_portfolios');
    }

};
