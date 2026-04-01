<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 50)->nullable()->index();
            $table->string('full_name', 150);
            $table->string('normalized_name', 150)->index();
            $table->string('first_name', 100)->nullable();
            $table->string('paternal_last_name', 100)->nullable();
            $table->string('maternal_last_name', 100)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source_system', 30)->default('noi');
            $table->timestamps();

            $table->unique(['employee_code', 'source_system']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('employees');
    }

};
