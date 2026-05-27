<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_match_rejections', function (Blueprint $table) {
            $table->id();
            // Store the lower ID first to keep a canonical order
            $table->unsignedBigInteger('employee_id_a');
            $table->unsignedBigInteger('employee_id_b');
            $table->unsignedBigInteger('period_id')->nullable();
            // Canonical pair key: "min(a,b)-max(a,b)" — unique per pair globally
            $table->string('pair_key', 40)->unique();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('employee_id_a')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('employee_id_b')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('period_id')->references('id')->on('periods')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_match_rejections');
    }
};
