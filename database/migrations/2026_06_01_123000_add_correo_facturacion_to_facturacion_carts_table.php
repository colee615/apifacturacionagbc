<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('facturacion_carts') || Schema::hasColumn('facturacion_carts', 'correo_facturacion')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            $table->string('correo_facturacion', 50)->nullable()->after('razon_social');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('facturacion_carts') || !Schema::hasColumn('facturacion_carts', 'correo_facturacion')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            $table->dropColumn('correo_facturacion');
        });
    }
};
