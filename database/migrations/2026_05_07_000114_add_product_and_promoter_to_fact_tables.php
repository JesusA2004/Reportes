<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add product_name to placements (financial_products table is empty; store raw name)
        Schema::table('fact_placements', function (Blueprint $table) {
            $table->string('product_name', 180)->nullable()->after('financial_product_id');
            $table->string('promoter_code', 80)->nullable()->after('promoter_name');
        });

        // Add promoter_name and product_name to recoveries
        Schema::table('fact_recoveries', function (Blueprint $table) {
            $table->string('promoter_name', 180)->nullable()->after('client_name');
            $table->string('product_name', 180)->nullable()->after('promoter_name');
        });
    }

    public function down(): void
    {
        Schema::table('fact_placements', function (Blueprint $table) {
            $table->dropColumn(['product_name', 'promoter_code']);
        });

        Schema::table('fact_recoveries', function (Blueprint $table) {
            $table->dropColumn(['promoter_name', 'product_name']);
        });
    }
};
