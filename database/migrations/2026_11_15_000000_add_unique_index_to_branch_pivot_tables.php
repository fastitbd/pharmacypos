<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->unique(['branch_id', 'product_id'], 'branch_product_unique');
        });

        Schema::table('branch_categories', function (Blueprint $table) {
            $table->unique(['branch_id', 'category_id'], 'branch_category_unique');
        });

        Schema::table('branch_brands', function (Blueprint $table) {
            $table->unique(['branch_id', 'brand_id'], 'branch_brand_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->dropUnique('branch_product_unique');
        });

        Schema::table('branch_categories', function (Blueprint $table) {
            $table->dropUnique('branch_category_unique');
        });

        Schema::table('branch_brands', function (Blueprint $table) {
            $table->dropUnique('branch_brand_unique');
        });
    }
};
