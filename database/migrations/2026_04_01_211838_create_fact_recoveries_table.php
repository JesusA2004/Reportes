<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('fact_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_upload_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->string('contract')->nullable()->index();
            $table->string('client_name')->nullable();
            $table->string('normalized_client_name')->nullable()->index();
            $table->decimal('capital', 14, 2)->default(0);
            $table->decimal('interest', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('charges', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->date('payment_date')->nullable();

            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('fact_recoveries');
    }

};
