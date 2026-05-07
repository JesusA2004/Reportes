<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fact_portfolios', function (Blueprint $table) {
            $table->string('contract', 100)->nullable()->after('client_name');
            $table->string('promoter_name', 180)->nullable()->after('contract');
            $table->string('promoter_code', 80)->nullable()->after('promoter_name');
            $table->string('product_name', 180)->nullable()->after('promoter_code');
            $table->smallInteger('num_payments')->nullable()->after('product_name');
            $table->string('periodicity', 50)->nullable()->after('num_payments');
            $table->string('route_name', 180)->nullable()->after('periodicity');
            $table->decimal('capital_due', 14, 2)->nullable()->after('route_name');
        });

        // Add product_name and promoter_code to fact_placements if not yet added
        if (!Schema::hasColumn('fact_placements', 'product_name')) {
            Schema::table('fact_placements', function (Blueprint $table) {
                $table->string('product_name', 180)->nullable()->after('financial_product_id');
                $table->string('promoter_code', 80)->nullable()->after('promoter_name');
            });
        }

        // Add promoter_name and product_name to fact_recoveries if not yet added
        if (!Schema::hasColumn('fact_recoveries', 'promoter_name')) {
            Schema::table('fact_recoveries', function (Blueprint $table) {
                $table->string('promoter_name', 180)->nullable()->after('client_name');
                $table->string('product_name', 180)->nullable()->after('promoter_name');
            });
        }
    }

    public function down(): void
    {
        Schema::table('fact_portfolios', function (Blueprint $table) {
            $table->dropColumn([
                'contract', 'promoter_name', 'promoter_code',
                'product_name', 'num_payments', 'periodicity',
                'route_name', 'capital_due',
            ]);
        });

        if (Schema::hasColumn('fact_placements', 'product_name')) {
            Schema::table('fact_placements', function (Blueprint $table) {
                $table->dropColumn(['product_name', 'promoter_code']);
            });
        }

        if (Schema::hasColumn('fact_recoveries', 'promoter_name')) {
            Schema::table('fact_recoveries', function (Blueprint $table) {
                $table->dropColumn(['promoter_name', 'product_name']);
            });
        }
    }
};
