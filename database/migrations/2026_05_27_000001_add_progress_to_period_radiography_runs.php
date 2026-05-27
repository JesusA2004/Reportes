<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('period_radiography_runs', function (Blueprint $table) {
            $table->json('metadata')->nullable()->after('log');
            $table->text('error_message')->nullable()->after('metadata');
            $table->timestamp('queued_at')->nullable()->after('finished_at');
            $table->timestamp('cancelled_at')->nullable()->after('queued_at');
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete()->after('cancelled_at');
            $table->string('output_excel_path')->nullable()->after('cancelled_by');
            $table->string('output_pdf_path')->nullable()->after('output_excel_path');
        });
    }

    public function down(): void
    {
        Schema::table('period_radiography_runs', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn([
                'metadata', 'error_message', 'queued_at', 'cancelled_at',
                'cancelled_by', 'output_excel_path', 'output_pdf_path',
            ]);
        });
    }
};
