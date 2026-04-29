<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('period_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->json('source_upload_ids')->nullable();
            $table->string('status', 30)->default('generated');
            $table->json('global_metrics')->nullable();
            $table->json('warnings')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invalidated_at')->nullable();
            $table->foreignId('invalidated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invalidated_reason')->nullable();
            $table->timestamps();
            $table->unique('period_id');
        });

        Schema::create('period_branch_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_summary_id')->constrained('period_summaries')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->json('metrics');
            $table->timestamps();
        });

        Schema::create('period_corporate_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_summary_id')->constrained('period_summaries')->cascadeOnDelete();
            $table->json('metrics');
            $table->timestamps();
        });

        Schema::create('period_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_summary_id')->constrained('period_summaries')->cascadeOnDelete();
            $table->string('type', 60);
            $table->string('severity', 20)->default('warning');
            $table->string('message');
            $table->json('context')->nullable();
            $table->timestamps();
        });

        Schema::create('period_radiography_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('periods')->cascadeOnDelete();
            $table->foreignId('period_summary_id')->nullable()->constrained('period_summaries')->nullOnDelete();
            $table->string('status', 30)->default('running');
            $table->text('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('period_radiography_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_summary_id')->constrained('period_summaries')->cascadeOnDelete();
            $table->string('export_path');
            $table->string('template_version')->nullable();
            $table->timestamp('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('period_radiography_exports');
        Schema::dropIfExists('period_radiography_runs');
        Schema::dropIfExists('period_incidents');
        Schema::dropIfExists('period_corporate_summaries');
        Schema::dropIfExists('period_branch_summaries');
        Schema::dropIfExists('period_summaries');
    }
};
