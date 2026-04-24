<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('caja_arqueos')) {
            Schema::create('caja_arqueos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('caja_diaria_id')->nullable();
                $table->string('usuario_id', 100);
                $table->string('usuario_nombre', 255)->nullable();
                $table->string('usuario_email', 120)->nullable();
                $table->integer('codigo_sucursal')->default(0);
                $table->integer('punto_venta')->default(0);
                $table->date('fecha_operacion');
                $table->string('estado', 30)->default('ARQUEADO');
                $table->integer('cantidad_ventas')->default(0);
                $table->decimal('monto_total', 12, 2)->default(0);
                $table->decimal('monto_cierre_declarado', 12, 2)->default(0);
                $table->decimal('diferencia', 12, 2)->default(0);
                $table->timestamp('cerrado_en')->nullable();
                $table->string('observacion', 500)->nullable();
                $table->timestamps();

                $table->unique(['caja_diaria_id'], 'caja_arqueos_caja_diaria_unique');
                $table->index(['usuario_id', 'fecha_operacion'], 'caja_arqueos_usuario_fecha_idx');
                $table->index(['codigo_sucursal', 'punto_venta', 'fecha_operacion'], 'caja_arqueos_sucursal_pv_fecha_idx');
            });
        }

        if (!Schema::hasTable('caja_arqueo_ventas')) {
            Schema::create('caja_arqueo_ventas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('arqueo_id');
                $table->unsignedBigInteger('venta_id')->nullable();
                $table->string('codigo_orden', 50)->nullable();
                $table->string('codigo_seguimiento', 50)->nullable();
                $table->string('estado_sufe', 50)->nullable();
                $table->string('numero_factura', 50)->nullable();
                $table->decimal('total', 12, 2)->default(0);
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->foreign('arqueo_id')
                    ->references('id')
                    ->on('caja_arqueos')
                    ->onDelete('cascade');

                $table->index(['arqueo_id'], 'caja_arqueo_ventas_arqueo_idx');
                $table->index(['venta_id'], 'caja_arqueo_ventas_venta_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('caja_arqueo_ventas');
        Schema::dropIfExists('caja_arqueos');
    }
};

