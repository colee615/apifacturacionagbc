<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            if (!Schema::hasColumn('facturacion_carts', 'metodo_pago')) {
                $table->string('metodo_pago', 20)->default('efectivo')->after('canal_emision');
            }
            if (!Schema::hasColumn('facturacion_carts', 'estado_pago')) {
                $table->string('estado_pago', 20)->default('pendiente')->after('metodo_pago');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            if (Schema::hasColumn('facturacion_carts', 'estado_pago')) {
                $table->dropColumn('estado_pago');
            }
            if (Schema::hasColumn('facturacion_carts', 'metodo_pago')) {
                $table->dropColumn('metodo_pago');
            }
        });
    }
};
