<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void {
        Schema::table('fact_noi_movements', function (Blueprint $table) {
            $table->dropUnique('fact_noi_movements_raw_row_hash_unique');
            $table->string('source_row_key', 64)->nullable()->after('raw_row_hash');
        });

        Schema::table('fact_noi_movements', function (Blueprint $table) {
            $table->unique(['report_upload_id', 'source_row_key'], 'fact_noi_movements_upload_row_unique');
        });
    }

    public function down(): void {
        Schema::table('fact_noi_movements', function (Blueprint $table) {
            $table->dropUnique('fact_noi_movements_upload_row_unique');
            $table->dropColumn('source_row_key');
        });

        Schema::table('fact_noi_movements', function (Blueprint $table) {
            $table->unique('raw_row_hash', 'fact_noi_movements_raw_row_hash_unique');
        });
    }

};
