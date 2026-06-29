<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('data_sources')
            ->whereIn('code', ['imss', 'rotacion'])
            ->update([
                'is_required_for_report' => true,
                'is_required_for_bd'     => true,
                'updated_at'             => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('data_sources')
            ->whereIn('code', ['imss', 'rotacion'])
            ->update([
                'is_required_for_report' => false,
                'is_required_for_bd'     => false,
                'updated_at'             => now(),
            ]);
    }
};
