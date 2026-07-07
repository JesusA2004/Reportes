<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {

    public function up(): void {
        // SQLite (used by the test suite) has no MODIFY COLUMN syntax and is dynamically
        // typed anyway — the declared precision doesn't change how it stores numbers, so
        // this widening is a MySQL/MariaDB-only concern in production.
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE fact_noi_movements MODIFY amount DECIMAL(18,4) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE fact_noi_movements MODIFY quantity DECIMAL(18,4) NOT NULL DEFAULT 0');
    }

    public function down(): void {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE fact_noi_movements MODIFY amount DECIMAL(14,2) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE fact_noi_movements MODIFY quantity DECIMAL(14,2) NOT NULL DEFAULT 0');
    }

};
