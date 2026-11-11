<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cierre_diario_sucursales') || Schema::hasColumn('cierre_diario_sucursales', 'alcance')) {
            return;
        }

        Schema::table('cierre_diario_sucursales', function (Blueprint $table) {
            $table->string('alcance', 32)->default('sucursal')->after('punto_venta');
            $table->dropUnique('uniq_cierre_diario_sucursal_fecha');
            $table->unique(
                ['fecha_operacion', 'codigo_sucursal', 'punto_venta', 'alcance'],
                'uniq_cierre_diario_sucursal_fecha_scope'
            );
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('cierre_diario_sucursales') || !Schema::hasColumn('cierre_diario_sucursales', 'alcance')) {
            return;
        }

        Schema::table('cierre_diario_sucursales', function (Blueprint $table) {
            $table->dropUnique('uniq_cierre_diario_sucursal_fecha_scope');
            $table->dropColumn('alcance');
            $table->unique(
                ['fecha_operacion', 'codigo_sucursal', 'punto_venta'],
                'uniq_cierre_diario_sucursal_fecha'
            );
        });
    }
};
