<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                if (!Schema::hasColumn('ventas', 'anulacion_respaldo_path')) {
                    $table->string('anulacion_respaldo_path', 255)->nullable()->after('anulacion_autorizada_por_email');
                }
                if (!Schema::hasColumn('ventas', 'anulacion_respaldo_nombre')) {
                    $table->string('anulacion_respaldo_nombre', 255)->nullable()->after('anulacion_respaldo_path');
                }
                if (!Schema::hasColumn('ventas', 'anulacion_respaldo_mime')) {
                    $table->string('anulacion_respaldo_mime', 120)->nullable()->after('anulacion_respaldo_nombre');
                }
                if (!Schema::hasColumn('ventas', 'anulacion_respaldo_size')) {
                    $table->unsignedBigInteger('anulacion_respaldo_size')->nullable()->after('anulacion_respaldo_mime');
                }
            });
        }

        if (Schema::hasTable('facturacion_carts')) {
            Schema::table('facturacion_carts', function (Blueprint $table) {
                if (!Schema::hasColumn('facturacion_carts', 'anulacion_respaldo_path')) {
                    $table->string('anulacion_respaldo_path', 255)->nullable()->after('anulacion_autorizada_por_email');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulacion_respaldo_nombre')) {
                    $table->string('anulacion_respaldo_nombre', 255)->nullable()->after('anulacion_respaldo_path');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulacion_respaldo_mime')) {
                    $table->string('anulacion_respaldo_mime', 120)->nullable()->after('anulacion_respaldo_nombre');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulacion_respaldo_size')) {
                    $table->unsignedBigInteger('anulacion_respaldo_size')->nullable()->after('anulacion_respaldo_mime');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                foreach ([
                    'anulacion_respaldo_size',
                    'anulacion_respaldo_mime',
                    'anulacion_respaldo_nombre',
                    'anulacion_respaldo_path',
                ] as $column) {
                    if (Schema::hasColumn('ventas', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('facturacion_carts')) {
            Schema::table('facturacion_carts', function (Blueprint $table) {
                foreach ([
                    'anulacion_respaldo_size',
                    'anulacion_respaldo_mime',
                    'anulacion_respaldo_nombre',
                    'anulacion_respaldo_path',
                ] as $column) {
                    if (Schema::hasColumn('facturacion_carts', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
