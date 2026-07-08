<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cajas_diarias', function (Blueprint $table) {
            $table->dropUnique('cajas_diarias_usuario_fecha_unique');
            $table->unique(
                ['usuario_id', 'fecha_operacion', 'codigo_sucursal', 'punto_venta'],
                'cajas_diarias_usuario_fecha_sucursal_punto_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('cajas_diarias', function (Blueprint $table) {
            $table->dropUnique('cajas_diarias_usuario_fecha_sucursal_punto_unique');
            $table->unique(['usuario_id', 'fecha_operacion'], 'cajas_diarias_usuario_fecha_unique');
        });
    }
};
