<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facturacion_cart_emisiones')) {
            return;
        }

        Schema::create('facturacion_cart_emisiones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('cart_id');
            $table->unsignedBigInteger('venta_id')->nullable();
            $table->string('canal_emision', 40)->default('factura_electronica');
            $table->string('codigo_orden', 80)->nullable();
            $table->string('codigo_seguimiento', 80)->nullable();
            $table->string('codigo_seguimiento_fiscal', 80)->nullable();
            $table->string('numero_factura', 40)->nullable();
            $table->string('cuf', 120)->nullable();
            $table->string('estado', 40)->default('PENDIENTE');
            $table->json('respuesta')->nullable();
            $table->timestamps();

            $table->index(['cart_id', 'estado'], 'fact_cart_emisiones_cart_estado_idx');
            $table->index('codigo_seguimiento', 'fact_cart_emisiones_seguimiento_idx');
            $table->index('cuf', 'fact_cart_emisiones_cuf_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_cart_emisiones');
    }
};
