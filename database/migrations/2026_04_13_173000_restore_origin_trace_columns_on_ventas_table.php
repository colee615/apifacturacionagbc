<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            if (!Schema::hasColumn('ventas', 'origen_usuario_id')) {
                $table->string('origen_usuario_id')->nullable()->after('origen_venta_tipo');
            }

            if (!Schema::hasColumn('ventas', 'origen_usuario_email')) {
                $table->string('origen_usuario_email')->nullable()->after('origen_usuario_nombre');
            }

            if (!Schema::hasColumn('ventas', 'origen_sucursal_id')) {
                $table->string('origen_sucursal_id')->nullable()->after('origen_usuario_email');
            }

            if (!Schema::hasColumn('ventas', 'origen_sucursal_codigo')) {
                $table->string('origen_sucursal_codigo')->nullable()->after('origen_sucursal_id');
            }

            if (!Schema::hasColumn('ventas', 'origen_sucursal_nombre')) {
                $table->string('origen_sucursal_nombre')->nullable()->after('origen_sucursal_codigo');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            $columns = array_values(array_filter([
                'origen_usuario_id',
                'origen_usuario_email',
                'origen_sucursal_id',
                'origen_sucursal_codigo',
                'origen_sucursal_nombre',
            ], fn ($column) => Schema::hasColumn('ventas', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
