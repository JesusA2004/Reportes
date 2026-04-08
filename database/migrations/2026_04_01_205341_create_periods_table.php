<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 180);
            $table->string('code', 80)->unique();
            // weekly, bimonthly, quarterly, semiannual, annual
            $table->string('type', 30)->index();
            $table->unsignedSmallInteger('year')->index();
            // Para weekly sirve como "mes contenedor"
            // Para otros tipos puede ir null
            $table->unsignedTinyInteger('month')->nullable()->index();
            // Semana 1, Semana 2, Trimestre 1, etc.
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
            $table->index(['type', 'year']);
            $table->index(['type', 'year', 'month']);
            $table->unique(['type', 'year', 'month', 'sequence'], 'periods_type_year_month_sequence_unique');
        });
    }

    public function down(): void {
        Schema::dropIfExists('periods');
    }

};
