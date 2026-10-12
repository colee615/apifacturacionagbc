<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('facturacion_cart_items')) {
            return;
        }

        Schema::table('facturacion_cart_items', function ($table) {
            $table->dropUnique('facturacion_cart_items_unique_source');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('facturacion_cart_items')) {
            return;
        }

        Schema::table('facturacion_cart_items', function ($table) {
            $table->unique(
                ['cart_id', 'origen_tipo', 'origen_id'],
                'facturacion_cart_items_unique_source'
            );
        });
    }
};
