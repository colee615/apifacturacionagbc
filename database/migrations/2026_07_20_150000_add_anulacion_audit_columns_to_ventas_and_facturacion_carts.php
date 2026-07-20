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
                if (!Schema::hasColumn('ventas', 'anulada_at')) {
                    $table->timestamp('anulada_at')->nullable()->after('motivo');
                }
                if (!Schema::hasColumn('ventas', 'anulada_por_user_id')) {
                    $table->unsignedBigInteger('anulada_por_user_id')->nullable()->after('anulada_at');
                }
                if (!Schema::hasColumn('ventas', 'anulada_por_nombre')) {
                    $table->string('anulada_por_nombre', 120)->nullable()->after('anulada_por_user_id');
                }
                if (!Schema::hasColumn('ventas', 'anulada_por_email')) {
                    $table->string('anulada_por_email', 120)->nullable()->after('anulada_por_nombre');
                }
                if (!Schema::hasColumn('ventas', 'anulacion_motivo')) {
                    $table->string('anulacion_motivo', 500)->nullable()->after('anulada_por_email');
                }
                if (!Schema::hasColumn('ventas', 'anulacion_tipo')) {
                    $table->string('anulacion_tipo', 80)->nullable()->after('anulacion_motivo');
                }
                if (!Schema::hasColumn('ventas', 'anulacion_autorizada_por_user_id')) {
                    $table->unsignedBigInteger('anulacion_autorizada_por_user_id')->nullable()->after('anulacion_tipo');
                }
                if (!Schema::hasColumn('ventas', 'anulacion_autorizada_por_email')) {
                    $table->string('anulacion_autorizada_por_email', 120)->nullable()->after('anulacion_autorizada_por_user_id');
                }
            });
        }

        if (Schema::hasTable('facturacion_carts')) {
            Schema::table('facturacion_carts', function (Blueprint $table) {
                if (!Schema::hasColumn('facturacion_carts', 'anulada_at')) {
                    $table->timestamp('anulada_at')->nullable()->after('mensaje_emision');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulada_por_user_id')) {
                    $table->unsignedBigInteger('anulada_por_user_id')->nullable()->after('anulada_at');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulada_por_nombre')) {
                    $table->string('anulada_por_nombre', 120)->nullable()->after('anulada_por_user_id');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulada_por_email')) {
                    $table->string('anulada_por_email', 120)->nullable()->after('anulada_por_nombre');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulacion_motivo')) {
                    $table->string('anulacion_motivo', 500)->nullable()->after('anulada_por_email');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulacion_tipo')) {
                    $table->string('anulacion_tipo', 80)->nullable()->after('anulacion_motivo');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulacion_autorizada_por_user_id')) {
                    $table->unsignedBigInteger('anulacion_autorizada_por_user_id')->nullable()->after('anulacion_tipo');
                }
                if (!Schema::hasColumn('facturacion_carts', 'anulacion_autorizada_por_email')) {
                    $table->string('anulacion_autorizada_por_email', 120)->nullable()->after('anulacion_autorizada_por_user_id');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                foreach ([
                    'anulacion_autorizada_por_email',
                    'anulacion_autorizada_por_user_id',
                    'anulacion_tipo',
                    'anulacion_motivo',
                    'anulada_por_email',
                    'anulada_por_nombre',
                    'anulada_por_user_id',
                    'anulada_at',
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
                    'anulacion_autorizada_por_email',
                    'anulacion_autorizada_por_user_id',
                    'anulacion_tipo',
                    'anulacion_motivo',
                    'anulada_por_email',
                    'anulada_por_nombre',
                    'anulada_por_user_id',
                    'anulada_at',
                ] as $column) {
                    if (Schema::hasColumn('facturacion_carts', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
