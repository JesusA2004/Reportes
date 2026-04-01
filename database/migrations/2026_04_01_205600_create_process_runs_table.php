<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('process_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('report_upload_id')->nullable()->constrained()->nullOnDelete();
            $table->string('process_type'); // import, match, consolidate, export
            $table->string('status')->default('pending'); // pending, running, success, failed
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
