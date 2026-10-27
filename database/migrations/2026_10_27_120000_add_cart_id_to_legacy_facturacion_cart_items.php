<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facturacion_cart_items')
            || Schema::hasColumn('facturacion_cart_items', 'cart_id')
            || ! Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_cart_items', function (Blueprint $table): void {
            // Some installations already had this table, causing the original
            // create migration to skip it even when the cart foreign key was absent.
            // Keep legacy rows intact while allowing new cart items to be saved.
            $table->foreignId('cart_id')
                ->nullable()
                ->index()
                ->constrained('facturacion_carts')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // This is a schema repair for installations that may have skipped the
        // original table migration. Removing the repaired column could break them.
    }
};
