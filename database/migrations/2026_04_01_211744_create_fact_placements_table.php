<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('fact_placements', function (Blueprint $table) {
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
            $table->foreignId('financial_product_id')
                ->nullable()
                ->constrained('financial_products')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('client_name', 180)->nullable();
            $table->string('normalized_client_name', 180)->nullable()->index();
            $table->string('promoter_name', 180)->nullable();
            $table->string('normalized_promoter_name', 180)->nullable()->index();
            $table->string('coordinator_name', 180)->nullable();
            $table->decimal('amount', 14, 2)->default(0);
            $table->date('operation_date')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->index(['period_id', 'branch_id']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('fact_placements');
    }

};
