<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_recoveries', function (Blueprint $table) {
            // Operación — separate from Concepto (previously mixed in 'concept')
            if (!Schema::hasColumn('fact_recoveries', 'operation')) {
                $table->string('operation', 120)->nullable()->after('product_name');
            }
            // Concepto — strictly the Concepto column, not Operación
            if (!Schema::hasColumn('fact_recoveries', 'concept')) {
                $table->string('concept', 180)->nullable()->after('operation');
            }
            // Transacción (PAGO, DESCUENTO, etc.)
            if (!Schema::hasColumn('fact_recoveries', 'transaction')) {
                $table->string('transaction', 80)->nullable()->after('concept');
            }
            // Cargos vencimiento (separate from Cargos calendario)
            if (!Schema::hasColumn('fact_recoveries', 'charges_due')) {
                $table->decimal('charges_due', 14, 2)->default(0)->after('charges');
            }
            // Excedente
            if (!Schema::hasColumn('fact_recoveries', 'excedente')) {
                $table->decimal('excedente', 14, 2)->default(0)->after('charges_due');
            }
            // Días vencidos del pago
            if (!Schema::hasColumn('fact_recoveries', 'days_past_due')) {
                $table->smallInteger('days_past_due')->nullable()->after('excedente');
            }
            // Savehearts flag
            if (!Schema::hasColumn('fact_recoveries', 'is_savehearts')) {
                $table->boolean('is_savehearts')->default(false)->after('days_past_due')->index();
            }
            // 30% company share for CRECE Savehearts rows
            if (!Schema::hasColumn('fact_recoveries', 'savehearts_crece_share')) {
                $table->decimal('savehearts_crece_share', 14, 2)->default(0)->after('is_savehearts');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fact_recoveries', function (Blueprint $table) {
            $cols = ['operation', 'concept', 'transaction', 'charges_due', 'excedente', 'days_past_due', 'is_savehearts', 'savehearts_crece_share'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('fact_recoveries', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
