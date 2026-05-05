<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('period_radiography_exports', function (Blueprint $table) {
            $table->string('file_type', 20)->default('excel')->after('export_path');
            $table->json('metadata')->nullable()->after('template_version');
        });
    }

    public function down(): void
    {
        Schema::table('period_radiography_exports', function (Blueprint $table) {
            $table->dropColumn(['file_type', 'metadata']);
        });
    }
};
