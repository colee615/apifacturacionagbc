<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cierre_diario_sucursales')) {
            Schema::create('cierre_diario_sucursales', function (Blueprint $table) {
                $table->id();
                $table->date('fecha_operacion');
                $table->unsignedInteger('codigo_sucursal');
                $table->unsignedInteger('punto_venta')->default(0);
                $table->string('sucursal_nombre', 180)->nullable();
                $table->decimal('total_efectivo_sistema', 14, 2)->default(0);
                $table->decimal('total_qr_sistema', 14, 2)->default(0);
                $table->decimal('total_general_sistema', 14, 2)->default(0);
                $table->decimal('total_comprobantes', 14, 2)->default(0);
                $table->decimal('diferencia', 14, 2)->default(0);
                $table->string('estado', 40)->default('sin_comprobante');
                $table->string('observacion_general', 500)->nullable();
                $table->timestamps();

                $table->unique(['fecha_operacion', 'codigo_sucursal', 'punto_venta'], 'uniq_cierre_diario_sucursal_fecha');
                $table->index(['codigo_sucursal', 'punto_venta'], 'idx_cierre_diario_sucursal_branch');
            });
        }

        if (!Schema::hasTable('cierre_diario_comprobantes')) {
            Schema::create('cierre_diario_comprobantes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cierre_diario_sucursal_id')
                    ->constrained('cierre_diario_sucursales')
                    ->cascadeOnDelete();
                $table->date('fecha_deposito');
                $table->decimal('monto_depositado', 14, 2);
                $table->string('banco', 120)->nullable();
                $table->string('referencia', 120)->nullable();
                $table->string('observacion', 500)->nullable();
                $table->string('archivo_path', 255);
                $table->string('archivo_nombre', 255);
                $table->string('archivo_mime', 120)->nullable();
                $table->unsignedBigInteger('archivo_size')->nullable();
                $table->unsignedBigInteger('subido_por_user_id')->nullable();
                $table->string('subido_por_nombre', 160)->nullable();
                $table->string('subido_por_email', 160)->nullable();
                $table->unsignedBigInteger('validado_por_user_id')->nullable();
                $table->string('validado_por_nombre', 160)->nullable();
                $table->string('validado_por_email', 160)->nullable();
                $table->timestamp('validado_at')->nullable();
                $table->timestamps();

                $table->index(['fecha_deposito'], 'idx_cierre_diario_comprobantes_fecha');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cierre_diario_comprobantes');
        Schema::dropIfExists('cierre_diario_sucursales');
    }
};
