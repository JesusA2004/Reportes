<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('period_database_update_runs', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('period_database_update_runs', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
