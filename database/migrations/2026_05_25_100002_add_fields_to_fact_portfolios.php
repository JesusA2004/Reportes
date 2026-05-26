<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_portfolios', function (Blueprint $table) {
            // C) Préstamo activo — "Capital" column, DISTINCT from "Saldo actual" (balance)
            if (!Schema::hasColumn('fact_portfolios', 'capital_activo')) {
                $table->decimal('capital_activo', 14, 2)->nullable()->after('balance');
            }
            // B) Individual mora components that were missing from the calculation
            if (!Schema::hasColumn('fact_portfolios', 'interes_atrasado')) {
                $table->decimal('interes_atrasado', 14, 2)->nullable()->after('capital_due');
            }
            if (!Schema::hasColumn('fact_portfolios', 'impuesto_atrasado')) {
                $table->decimal('impuesto_atrasado', 14, 2)->nullable()->after('interes_atrasado');
            }
            if (!Schema::hasColumn('fact_portfolios', 'saldo_interes_moratorio')) {
                $table->decimal('saldo_interes_moratorio', 14, 2)->nullable()->after('impuesto_atrasado');
            }
            if (!Schema::hasColumn('fact_portfolios', 'saldo_impuesto_interes_moratorio')) {
                $table->decimal('saldo_impuesto_interes_moratorio', 14, 2)->nullable()->after('saldo_interes_moratorio');
            }
            // Previously missing from mora calculation
            if (!Schema::hasColumn('fact_portfolios', 'cargos_calendario_atrasado')) {
                $table->decimal('cargos_calendario_atrasado', 14, 2)->nullable()->after('saldo_impuesto_interes_moratorio');
            }
            if (!Schema::hasColumn('fact_portfolios', 'impuesto_cargos_calendario_atrasado')) {
                $table->decimal('impuesto_cargos_calendario_atrasado', 14, 2)->nullable()->after('cargos_calendario_atrasado');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fact_portfolios', function (Blueprint $table) {
            $cols = [
                'capital_activo',
                'interes_atrasado',
                'impuesto_atrasado',
                'saldo_interes_moratorio',
                'saldo_impuesto_interes_moratorio',
                'cargos_calendario_atrasado',
                'impuesto_cargos_calendario_atrasado',
            ];
            foreach ($cols as $col) {
                if (Schema::hasColumn('fact_portfolios', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
