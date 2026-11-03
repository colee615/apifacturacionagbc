<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('detalle_ventas')) {
            Schema::table('detalle_ventas', function (Blueprint $table) {
                $table->index('codigo', 'detalle_ventas_codigo_idx');
            });
        }

        if (Schema::hasTable('notificaciones')) {
            Schema::table('notificaciones', function (Blueprint $table) {
                $table->index('codigo_seguimiento', 'notificaciones_codigo_seguimiento_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('detalle_ventas')) {
            Schema::table('detalle_ventas', function (Blueprint $table) {
                $table->dropIndex('detalle_ventas_codigo_idx');
            });
        }

        if (Schema::hasTable('notificaciones')) {
            Schema::table('notificaciones', function (Blueprint $table) {
                $table->dropIndex('notificaciones_codigo_seguimiento_idx');
            });
        }
    }
};
