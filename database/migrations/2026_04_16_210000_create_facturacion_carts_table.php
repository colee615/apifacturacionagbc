<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::create('facturacion_carts', function (Blueprint $table) {
            $table->id();
            $table->string('origen_usuario_id', 60)->index();
            $table->string('origen_usuario_nombre')->nullable();
            $table->string('origen_usuario_email')->nullable();
            $table->string('origen_sucursal_id', 60)->nullable();
            $table->string('origen_sucursal_codigo', 60)->nullable();
            $table->string('origen_sucursal_nombre')->nullable();
            $table->string('estado', 20)->default('borrador')->index();
            $table->string('modalidad_facturacion', 20)->default('con_datos');
            $table->string('canal_emision', 20)->default('qr');
            $table->string('tipo_documento', 20)->nullable();
            $table->string('numero_documento', 80)->nullable();
            $table->string('complemento_documento', 30)->nullable();
            $table->string('razon_social')->nullable();
            $table->string('codigo_orden', 80)->nullable()->index();
            $table->string('codigo_seguimiento', 80)->nullable()->index();
            $table->string('estado_emision', 30)->nullable()->index();
            $table->text('mensaje_emision')->nullable();
            $table->json('respuesta_emision')->nullable();
            $table->unsignedInteger('cantidad_items')->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_extras', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamp('abierto_en')->nullable();
            $table->timestamp('cerrado_en')->nullable();
            $table->timestamp('emitido_en')->nullable();
            $table->timestamps();

            $table->index(['origen_usuario_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturacion_carts');
    }
};
