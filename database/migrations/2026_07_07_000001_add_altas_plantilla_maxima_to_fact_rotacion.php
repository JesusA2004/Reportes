<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_rotacion', function (Blueprint $table) {
            if (!Schema::hasColumn('fact_rotacion', 'altas')) {
                $table->integer('altas')->default(0)->after('sucursal_nombre');
            }
            if (!Schema::hasColumn('fact_rotacion', 'plantilla_maxima')) {
                $table->decimal('plantilla_maxima', 10, 4)->nullable()->after('promedio_personal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fact_rotacion', function (Blueprint $table) {
            if (Schema::hasColumn('fact_rotacion', 'altas')) {
                $table->dropColumn('altas');
            }
            if (Schema::hasColumn('fact_rotacion', 'plantilla_maxima')) {
                $table->dropColumn('plantilla_maxima');
            }
        });
    }
};
