<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturacion_tarifa_trazas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('venta_id');
            $table->unsignedBigInteger('cart_id');
            $table->unsignedBigInteger('cart_item_id');
            $table->string('origen_tipo', 120)->nullable();
            $table->unsignedBigInteger('origen_id')->nullable();
            $table->string('codigo_paquete', 120)->nullable();
            $table->string('estado_trazabilidad', 32);
            $table->unsignedBigInteger('tarifario_version_id')->nullable();
            $table->unsignedBigInteger('source_tarifario_id')->nullable();
            $table->string('version_codigo', 80)->nullable();
            $table->string('version_checksum', 64)->nullable();
            $table->json('tarifario_snapshot')->nullable();
            $table->decimal('importe_facturado', 12, 2)->default(0);
            $table->string('moneda', 3)->default('BOB');
            $table->timestamp('capturado_en');
            $table->timestamps();

            $table->unique(['venta_id', 'cart_item_id'], 'facturacion_tarifa_traza_sale_item_unique');
            $table->index(['estado_trazabilidad', 'capturado_en'], 'facturacion_tarifa_traza_status_date_idx');
            $table->index('codigo_paquete');
            $table->index(['tarifario_version_id', 'source_tarifario_id'], 'facturacion_tarifa_traza_version_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_tarifa_trazas');
    }
};
