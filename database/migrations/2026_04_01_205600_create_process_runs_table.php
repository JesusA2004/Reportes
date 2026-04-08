<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('process_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')
                ->nullable()
                ->constrained('periods')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('report_upload_id')
                ->nullable()
                ->constrained('report_uploads')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->string('process_type', 50)->index();
            $table->string('status', 30)->default('pending')->index();
            $table->unsignedInteger('rows_read')->default(0);
            $table->unsignedInteger('rows_inserted')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);
            $table->unsignedInteger('rows_with_errors')->default(0);
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('process_runs');
    }

};
