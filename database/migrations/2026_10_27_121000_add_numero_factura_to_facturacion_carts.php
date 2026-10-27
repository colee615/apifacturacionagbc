<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facturacion_carts')
            || Schema::hasColumn('facturacion_carts', 'numero_factura')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table): void {
            $table->string('numero_factura', 80)->nullable();
        });
    }

    public function down(): void
    {
        // Keep this compatibility column in place to avoid breaking installations
        // that may already rely on it after the migration is deployed.
    }
};
