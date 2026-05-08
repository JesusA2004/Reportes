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
            // weekly | monthly | bimonthly | quarterly | semiannual | annual
            // weekly = periodo base, recibe archivos
            // monthly = periodo operativo, compuesto de semanas elegidas por el usuario
            // bimonthly/quarterly/semiannual/annual = automáticos, compuestos de meses
            $table->string('type', 30)->index();
            $table->unsignedSmallInteger('year')->index();
            $table->unsignedTinyInteger('month')->nullable()->index();
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->date('start_date')->index();
            $table->date('end_date')->index();
            // IDs de periodos que componen este periodo (semanas para mensuales, meses para bimestres/etc.)
            $table->json('component_period_ids')->nullable();
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
