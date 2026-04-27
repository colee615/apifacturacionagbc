<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ficha_postal_sucursal_saldos')) {
            Schema::create('ficha_postal_sucursal_saldos', function (Blueprint $table) {
                $table->id();
                $table->integer('codigo_sucursal')->default(0);
                $table->integer('punto_venta')->default(0);
                $table->string('sucursal_nombre', 255)->nullable();
                $table->integer('cantidad_disponible')->default(0);
                $table->decimal('monto_disponible', 12, 2)->default(0);
                $table->decimal('valor_unitario_referencia', 12, 2)->nullable();
                $table->timestamp('ultimo_abastecimiento_en')->nullable();
                $table->timestamp('ultima_transferencia_en')->nullable();
                $table->text('observacion')->nullable();
                $table->timestamps();

                $table->unique(['codigo_sucursal', 'punto_venta'], 'ficha_postal_sucursal_saldos_unique');
            });
        }

        if (!Schema::hasTable('ficha_postal_sucursal_movimientos')) {
            Schema::create('ficha_postal_sucursal_movimientos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('saldo_sucursal_id')->nullable();
                $table->integer('codigo_sucursal')->default(0);
                $table->integer('punto_venta')->default(0);
                $table->string('sucursal_nombre', 255)->nullable();
                $table->string('tipo_movimiento', 30);
                $table->integer('cantidad_delta')->default(0);
                $table->decimal('monto_delta', 12, 2)->default(0);
                $table->integer('cantidad_anterior')->default(0);
                $table->decimal('monto_anterior', 12, 2)->default(0);
                $table->integer('cantidad_actual')->default(0);
                $table->decimal('monto_actual', 12, 2)->default(0);
                $table->decimal('valor_unitario', 12, 2)->nullable();
                $table->string('observacion', 500)->nullable();
                $table->json('referencia')->nullable();
                $table->timestamps();

                $table->index(['codigo_sucursal', 'punto_venta', 'created_at'], 'ficha_postal_sucursal_movimientos_idx');
                $table->index(['tipo_movimiento'], 'ficha_postal_sucursal_movimientos_tipo_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ficha_postal_sucursal_movimientos');
        Schema::dropIfExists('ficha_postal_sucursal_saldos');
    }
};
