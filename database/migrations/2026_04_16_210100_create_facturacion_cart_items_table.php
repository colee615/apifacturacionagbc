<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facturacion_cart_items')) {
            return;
        }

        Schema::create('facturacion_cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('facturacion_carts')->cascadeOnDelete();
            $table->string('origen_tipo', 120);
            $table->unsignedBigInteger('origen_id');
            $table->string('codigo')->nullable()->index();
            $table->string('titulo');
            $table->string('nombre_servicio')->nullable();
            $table->string('nombre_destinatario')->nullable();
            $table->json('servicios_extra')->nullable();
            $table->json('resumen_origen')->nullable();
            $table->unsignedInteger('cantidad')->default(1);
            $table->decimal('monto_base', 12, 2)->default(0);
            $table->decimal('monto_extras', 12, 2)->default(0);
            $table->decimal('total_linea', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['cart_id', 'origen_tipo', 'origen_id'], 'facturacion_cart_items_unique_source');
            $table->index(['origen_tipo', 'origen_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_cart_items');
    }
};
