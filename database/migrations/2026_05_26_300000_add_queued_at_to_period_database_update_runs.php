<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('period_database_update_runs', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable()->after('finished_at');
        });

        // Backfill: existing rows — use created_at as queued_at, null out started_at for queued ones
        DB::statement("UPDATE period_database_update_runs SET queued_at = created_at WHERE queued_at IS NULL");
    }

    public function down(): void
    {
        Schema::table('period_database_update_runs', function (Blueprint $table) {
            $table->dropColumn('queued_at');
        });
    }
};
