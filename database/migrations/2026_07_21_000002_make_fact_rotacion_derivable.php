<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * fact_rotacion pasa a poder llenarse sin archivo subido (derivado de NOI):
     * report_upload_id se vuelve nullable y se agrega 'source' para distinguir
     * filas derivadas ('derived_noi') de filas legacy de archivo ('file').
     * Usa SQL crudo para el ALTER de nullability en MySQL — evita depender de
     * doctrine/dbal. SQLite (usado por la suite de tests, phpunit.xml
     * DB_CONNECTION=sqlite) no soporta ALTER COLUMN ni DROP FOREIGN KEY, pero SÍ
     * crea toda columna sin ->notNullable() ya nullable por defecto — por eso
     * ahí basta con NO tocar la nulabilidad ni el FK, solo agregar 'source'.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('fact_rotacion', function (Blueprint $table) {
                $table->string('source', 30)->default('file')->after('plantilla_maxima');
            });
            return;
        }

        Schema::table('fact_rotacion', function (Blueprint $table) {
            $table->dropForeign(['report_upload_id']);
        });

        DB::statement('ALTER TABLE fact_rotacion MODIFY report_upload_id BIGINT UNSIGNED NULL');

        Schema::table('fact_rotacion', function (Blueprint $table) {
            $table->string('source', 30)->default('file')->after('plantilla_maxima');
            $table->foreign('report_upload_id')->references('id')->on('report_uploads')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('fact_rotacion', function (Blueprint $table) {
                $table->dropColumn('source');
            });
            return;
        }

        Schema::table('fact_rotacion', function (Blueprint $table) {
            $table->dropColumn('source');
            $table->dropForeign(['report_upload_id']);
        });

        DB::statement('ALTER TABLE fact_rotacion MODIFY report_upload_id BIGINT UNSIGNED NOT NULL');

        Schema::table('fact_rotacion', function (Blueprint $table) {
            $table->foreign('report_upload_id')->references('id')->on('report_uploads')->onDelete('cascade');
        });
    }
};
