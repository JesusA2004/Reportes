<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Da identidad real a los reportes generados: hasta ahora ni period_radiography_runs
 * ni period_radiography_exports guardaban report_type/scope/comparison_period_id, así
 * que un comparativo mes vs mes generado sobre un periodo que ya tenía un reporte
 * simple no se podía distinguir del simple — ambos vivían como "el reporte de este
 * periodo". Con estas columnas, cada run/export queda identificado por
 * (period_id, report_type, scope, comparison_period_id, branch_id, employee_id) y
 * simple/comparativo pueden coexistir como registros y archivos separados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_radiography_runs', function (Blueprint $table) {
            $table->string('report_type', 30)->default('simple')->after('period_summary_id');
            $table->string('scope', 20)->default('general')->after('report_type');
            $table->unsignedBigInteger('branch_id')->nullable()->after('scope');
            $table->unsignedBigInteger('employee_id')->nullable()->after('branch_id');
            $table->unsignedBigInteger('comparison_period_id')->nullable()->after('employee_id');

            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
            $table->foreign('employee_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('comparison_period_id')->references('id')->on('periods')->nullOnDelete();

            $table->index(
                ['period_id', 'report_type', 'scope', 'comparison_period_id', 'branch_id', 'employee_id'],
                'radiography_runs_identity_idx'
            );
        });

        Schema::table('period_radiography_exports', function (Blueprint $table) {
            $table->unsignedBigInteger('run_id')->nullable()->after('period_summary_id');
            $table->foreign('run_id')->references('id')->on('period_radiography_runs')->cascadeOnDelete();
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::table('period_radiography_exports', function (Blueprint $table) {
            $table->dropForeign(['run_id']);
            $table->dropColumn('run_id');
        });

        Schema::table('period_radiography_runs', function (Blueprint $table) {
            $table->dropIndex('radiography_runs_identity_idx');
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['comparison_period_id']);
            $table->dropColumn(['report_type', 'scope', 'branch_id', 'employee_id', 'comparison_period_id']);
        });
    }
};
