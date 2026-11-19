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
        // 1. Products table
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'product_type')) {
                $table->string('product_type')->default('general')->after('name');
            }
            if (!Schema::hasColumn('products', 'generic_name')) {
                $table->string('generic_name')->nullable()->after('product_type');
            }
            if (!Schema::hasColumn('products', 'company')) {
                $table->string('company')->nullable()->after('generic_name');
            }
            if (!Schema::hasColumn('products', 'strength')) {
                $table->string('strength')->nullable()->after('company');
            }
            if (!Schema::hasColumn('products', 'dosage_form')) {
                $table->string('dosage_form')->nullable()->after('strength');
            }
            if (!Schema::hasColumn('products', 'has_expire_date')) {
                $table->tinyInteger('has_expire_date')->default(0)->after('has_serial');
            }
        });

        // 2. Purchase items table
        Schema::table('purchase_items', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_items', 'expire_date')) {
                $table->date('expire_date')->nullable()->after('date');
            }
        });

        // 3. Invoice items table
        Schema::table('invoice_items', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_items', 'expire_date')) {
                $table->date('expire_date')->nullable()->after('date');
            }
        });

        // 4. Return items table (Sale returns)
        Schema::table('return_items', function (Blueprint $table) {
            if (!Schema::hasColumn('return_items', 'expire_date')) {
                $table->date('expire_date')->nullable()->after('date');
            }
        });

        // 5. Return purchase items table (Purchase returns)
        Schema::table('return_purchase_items', function (Blueprint $table) {
            if (!Schema::hasColumn('return_purchase_items', 'expire_date')) {
                $table->date('expire_date')->nullable()->after('date');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $cols = ['product_type', 'generic_name', 'company', 'strength', 'dosage_form', 'has_expire_date'];
            foreach ($cols as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_items', 'expire_date')) {
                $table->dropColumn('expire_date');
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (Schema::hasColumn('invoice_items', 'expire_date')) {
                $table->dropColumn('expire_date');
            }
        });

        Schema::table('return_items', function (Blueprint $table) {
            if (Schema::hasColumn('return_items', 'expire_date')) {
                $table->dropColumn('expire_date');
            }
        });

        Schema::table('return_purchase_items', function (Blueprint $table) {
            if (Schema::hasColumn('return_purchase_items', 'expire_date')) {
                $table->dropColumn('expire_date');
            }
        });
    }
};
