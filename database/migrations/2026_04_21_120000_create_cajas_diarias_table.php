<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cajas_diarias')) {
            return;
        }

        Schema::create('cajas_diarias', function (Blueprint $table) {
            $table->id();
            $table->string('usuario_id', 100);
            $table->string('usuario_nombre', 255)->nullable();
            $table->string('usuario_email', 120)->nullable();
            $table->integer('codigo_sucursal')->default(0);
            $table->integer('punto_venta')->default(0);
            $table->date('fecha_operacion');
            $table->enum('estado', ['ABIERTA', 'CERRADA'])->default('ABIERTA');
            $table->decimal('monto_apertura', 12, 2)->default(0);
            $table->decimal('monto_cierre_declarado', 12, 2)->nullable();
            $table->decimal('monto_ventas', 12, 2)->default(0);
            $table->integer('cantidad_ventas')->default(0);
            $table->decimal('diferencia', 12, 2)->nullable();
            $table->text('observacion_apertura')->nullable();
            $table->text('observacion_cierre')->nullable();
            $table->timestamp('abierta_en')->nullable();
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();

            $table->unique(['usuario_id', 'fecha_operacion'], 'cajas_diarias_usuario_fecha_unique');
            $table->index(['fecha_operacion', 'estado'], 'cajas_diarias_fecha_estado_idx');
            $table->index(['codigo_sucursal', 'punto_venta'], 'cajas_diarias_sucursal_pv_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cajas_diarias');
    }
};

