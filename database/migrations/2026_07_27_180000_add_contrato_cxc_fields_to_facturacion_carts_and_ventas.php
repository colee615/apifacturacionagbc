<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('facturacion_carts')) {
            Schema::table('facturacion_carts', function (Blueprint $table) {
                if (!Schema::hasColumn('facturacion_carts', 'canal_operativo')) {
                    $table->string('canal_operativo', 30)->default('normal');
                }
                if (!Schema::hasColumn('facturacion_carts', 'contabiliza_en_caja')) {
                    $table->boolean('contabiliza_en_caja')->default(true);
                }
                if (!Schema::hasColumn('facturacion_carts', 'es_cuenta_por_cobrar')) {
                    $table->boolean('es_cuenta_por_cobrar')->default(false);
                }
                if (!Schema::hasColumn('facturacion_carts', 'empresa_id')) {
                    $table->unsignedBigInteger('empresa_id')->nullable();
                }
                if (!Schema::hasColumn('facturacion_carts', 'empresa_codigo_cliente')) {
                    $table->string('empresa_codigo_cliente', 60)->nullable();
                }
                if (!Schema::hasColumn('facturacion_carts', 'empresa_nombre')) {
                    $table->string('empresa_nombre', 255)->nullable();
                }
                if (!Schema::hasColumn('facturacion_carts', 'empresa_sigla')) {
                    $table->string('empresa_sigla', 60)->nullable();
                }
            });
        }

        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                if (!Schema::hasColumn('ventas', 'canal_operativo')) {
                    $table->string('canal_operativo', 30)->default('normal');
                }
                if (!Schema::hasColumn('ventas', 'contabiliza_en_caja')) {
                    $table->boolean('contabiliza_en_caja')->default(true);
                }
                if (!Schema::hasColumn('ventas', 'es_cuenta_por_cobrar')) {
                    $table->boolean('es_cuenta_por_cobrar')->default(false);
                }
                if (!Schema::hasColumn('ventas', 'empresa_id')) {
                    $table->unsignedBigInteger('empresa_id')->nullable();
                }
                if (!Schema::hasColumn('ventas', 'empresa_codigo_cliente')) {
                    $table->string('empresa_codigo_cliente', 60)->nullable();
                }
                if (!Schema::hasColumn('ventas', 'empresa_nombre')) {
                    $table->string('empresa_nombre', 255)->nullable();
                }
                if (!Schema::hasColumn('ventas', 'empresa_sigla')) {
                    $table->string('empresa_sigla', 60)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                foreach ([
                    'empresa_sigla',
                    'empresa_nombre',
                    'empresa_codigo_cliente',
                    'empresa_id',
                    'es_cuenta_por_cobrar',
                    'contabiliza_en_caja',
                    'canal_operativo',
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
                    'empresa_sigla',
                    'empresa_nombre',
                    'empresa_codigo_cliente',
                    'empresa_id',
                    'es_cuenta_por_cobrar',
                    'contabiliza_en_caja',
                    'canal_operativo',
                ] as $column) {
                    if (Schema::hasColumn('facturacion_carts', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
