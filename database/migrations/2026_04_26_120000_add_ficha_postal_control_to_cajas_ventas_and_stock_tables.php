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
                if (!Schema::hasColumn('ventas', 'cantidad_fichas_postales')) {
                    $table->integer('cantidad_fichas_postales')->default(0)->after('total');
                }
                if (!Schema::hasColumn('ventas', 'monto_fichas_postales')) {
                    $table->decimal('monto_fichas_postales', 12, 2)->default(0)->after('cantidad_fichas_postales');
                }
                if (!Schema::hasColumn('ventas', 'valor_unitario_ficha_postal')) {
                    $table->decimal('valor_unitario_ficha_postal', 12, 2)->nullable()->after('monto_fichas_postales');
                }
                if (!Schema::hasColumn('ventas', 'detalle_fichas_postales')) {
                    $table->json('detalle_fichas_postales')->nullable()->after('valor_unitario_ficha_postal');
                }
            });
        }

        if (Schema::hasTable('cajas_diarias')) {
            Schema::table('cajas_diarias', function (Blueprint $table) {
                if (!Schema::hasColumn('cajas_diarias', 'monto_cierre_esperado')) {
                    $table->decimal('monto_cierre_esperado', 12, 2)->default(0)->after('monto_cierre_declarado');
                }
                if (!Schema::hasColumn('cajas_diarias', 'cantidad_fichas_apertura')) {
                    $table->integer('cantidad_fichas_apertura')->default(0)->after('monto_cierre_esperado');
                }
                if (!Schema::hasColumn('cajas_diarias', 'monto_fichas_apertura')) {
                    $table->decimal('monto_fichas_apertura', 12, 2)->default(0)->after('cantidad_fichas_apertura');
                }
                if (!Schema::hasColumn('cajas_diarias', 'cantidad_fichas_ingresadas')) {
                    $table->integer('cantidad_fichas_ingresadas')->default(0)->after('monto_fichas_apertura');
                }
                if (!Schema::hasColumn('cajas_diarias', 'monto_fichas_ingresadas')) {
                    $table->decimal('monto_fichas_ingresadas', 12, 2)->default(0)->after('cantidad_fichas_ingresadas');
                }
                if (!Schema::hasColumn('cajas_diarias', 'cantidad_fichas_consumidas')) {
                    $table->integer('cantidad_fichas_consumidas')->default(0)->after('monto_fichas_ingresadas');
                }
                if (!Schema::hasColumn('cajas_diarias', 'monto_fichas_consumidas')) {
                    $table->decimal('monto_fichas_consumidas', 12, 2)->default(0)->after('cantidad_fichas_consumidas');
                }
                if (!Schema::hasColumn('cajas_diarias', 'cantidad_fichas_cierre_esperado')) {
                    $table->integer('cantidad_fichas_cierre_esperado')->default(0)->after('monto_fichas_consumidas');
                }
                if (!Schema::hasColumn('cajas_diarias', 'monto_fichas_cierre_esperado')) {
                    $table->decimal('monto_fichas_cierre_esperado', 12, 2)->default(0)->after('cantidad_fichas_cierre_esperado');
                }
                if (!Schema::hasColumn('cajas_diarias', 'cantidad_fichas_cierre_declarado')) {
                    $table->integer('cantidad_fichas_cierre_declarado')->nullable()->after('monto_fichas_cierre_esperado');
                }
                if (!Schema::hasColumn('cajas_diarias', 'monto_fichas_cierre_declarado')) {
                    $table->decimal('monto_fichas_cierre_declarado', 12, 2)->nullable()->after('cantidad_fichas_cierre_declarado');
                }
                if (!Schema::hasColumn('cajas_diarias', 'diferencia_efectivo')) {
                    $table->decimal('diferencia_efectivo', 12, 2)->nullable()->after('monto_fichas_cierre_declarado');
                }
                if (!Schema::hasColumn('cajas_diarias', 'diferencia_fichas')) {
                    $table->decimal('diferencia_fichas', 12, 2)->nullable()->after('diferencia_efectivo');
                }
                if (!Schema::hasColumn('cajas_diarias', 'diferencia_cantidad_fichas')) {
                    $table->integer('diferencia_cantidad_fichas')->nullable()->after('diferencia_fichas');
                }
            });
        }

        if (Schema::hasTable('caja_arqueos')) {
            Schema::table('caja_arqueos', function (Blueprint $table) {
                if (!Schema::hasColumn('caja_arqueos', 'monto_apertura')) {
                    $table->decimal('monto_apertura', 12, 2)->default(0)->after('monto_cierre_declarado');
                }
                if (!Schema::hasColumn('caja_arqueos', 'monto_cierre_esperado')) {
                    $table->decimal('monto_cierre_esperado', 12, 2)->default(0)->after('monto_apertura');
                }
                if (!Schema::hasColumn('caja_arqueos', 'cantidad_fichas_apertura')) {
                    $table->integer('cantidad_fichas_apertura')->default(0)->after('monto_cierre_esperado');
                }
                if (!Schema::hasColumn('caja_arqueos', 'monto_fichas_apertura')) {
                    $table->decimal('monto_fichas_apertura', 12, 2)->default(0)->after('cantidad_fichas_apertura');
                }
                if (!Schema::hasColumn('caja_arqueos', 'cantidad_fichas_ingresadas')) {
                    $table->integer('cantidad_fichas_ingresadas')->default(0)->after('monto_fichas_apertura');
                }
                if (!Schema::hasColumn('caja_arqueos', 'monto_fichas_ingresadas')) {
                    $table->decimal('monto_fichas_ingresadas', 12, 2)->default(0)->after('cantidad_fichas_ingresadas');
                }
                if (!Schema::hasColumn('caja_arqueos', 'cantidad_fichas_consumidas')) {
                    $table->integer('cantidad_fichas_consumidas')->default(0)->after('monto_fichas_ingresadas');
                }
                if (!Schema::hasColumn('caja_arqueos', 'monto_fichas_consumidas')) {
                    $table->decimal('monto_fichas_consumidas', 12, 2)->default(0)->after('cantidad_fichas_consumidas');
                }
                if (!Schema::hasColumn('caja_arqueos', 'cantidad_fichas_cierre_esperado')) {
                    $table->integer('cantidad_fichas_cierre_esperado')->default(0)->after('monto_fichas_consumidas');
                }
                if (!Schema::hasColumn('caja_arqueos', 'monto_fichas_cierre_esperado')) {
                    $table->decimal('monto_fichas_cierre_esperado', 12, 2)->default(0)->after('cantidad_fichas_cierre_esperado');
                }
                if (!Schema::hasColumn('caja_arqueos', 'cantidad_fichas_cierre_declarado')) {
                    $table->integer('cantidad_fichas_cierre_declarado')->default(0)->after('monto_fichas_cierre_esperado');
                }
                if (!Schema::hasColumn('caja_arqueos', 'monto_fichas_cierre_declarado')) {
                    $table->decimal('monto_fichas_cierre_declarado', 12, 2)->default(0)->after('cantidad_fichas_cierre_declarado');
                }
                if (!Schema::hasColumn('caja_arqueos', 'diferencia_efectivo')) {
                    $table->decimal('diferencia_efectivo', 12, 2)->default(0)->after('monto_fichas_cierre_declarado');
                }
                if (!Schema::hasColumn('caja_arqueos', 'diferencia_fichas')) {
                    $table->decimal('diferencia_fichas', 12, 2)->default(0)->after('diferencia_efectivo');
                }
                if (!Schema::hasColumn('caja_arqueos', 'diferencia_cantidad_fichas')) {
                    $table->integer('diferencia_cantidad_fichas')->default(0)->after('diferencia_fichas');
                }
            });
        }

        if (!Schema::hasTable('ficha_postal_saldos')) {
            Schema::create('ficha_postal_saldos', function (Blueprint $table) {
                $table->id();
                $table->string('usuario_id', 100);
                $table->string('usuario_nombre', 255)->nullable();
                $table->string('usuario_email', 120)->nullable();
                $table->integer('codigo_sucursal')->default(0);
                $table->integer('punto_venta')->default(0);
                $table->integer('cantidad_disponible')->default(0);
                $table->decimal('monto_disponible', 12, 2)->default(0);
                $table->decimal('valor_unitario_referencia', 12, 2)->nullable();
                $table->timestamp('ultima_asignacion_en')->nullable();
                $table->timestamp('ultimo_consumo_en')->nullable();
                $table->text('observacion')->nullable();
                $table->timestamps();

                $table->unique(['usuario_id', 'codigo_sucursal', 'punto_venta'], 'ficha_postal_saldos_usuario_sucursal_unique');
                $table->index(['codigo_sucursal', 'punto_venta'], 'ficha_postal_saldos_sucursal_pv_idx');
            });
        }

        if (!Schema::hasTable('ficha_postal_movimientos')) {
            Schema::create('ficha_postal_movimientos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('saldo_id')->nullable();
                $table->unsignedBigInteger('caja_diaria_id')->nullable();
                $table->unsignedBigInteger('venta_id')->nullable();
                $table->string('usuario_id', 100);
                $table->string('usuario_nombre', 255)->nullable();
                $table->string('usuario_email', 120)->nullable();
                $table->integer('codigo_sucursal')->default(0);
                $table->integer('punto_venta')->default(0);
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

                $table->index(['usuario_id', 'created_at'], 'ficha_postal_movimientos_usuario_fecha_idx');
                $table->index(['codigo_sucursal', 'punto_venta', 'created_at'], 'ficha_postal_movimientos_sucursal_pv_fecha_idx');
                $table->index(['tipo_movimiento'], 'ficha_postal_movimientos_tipo_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('ficha_postal_movimientos')) {
            Schema::dropIfExists('ficha_postal_movimientos');
        }

        if (Schema::hasTable('ficha_postal_saldos')) {
            Schema::dropIfExists('ficha_postal_saldos');
        }

        if (Schema::hasTable('caja_arqueos')) {
            Schema::table('caja_arqueos', function (Blueprint $table) {
                $columns = [
                    'monto_apertura',
                    'monto_cierre_esperado',
                    'cantidad_fichas_apertura',
                    'monto_fichas_apertura',
                    'cantidad_fichas_ingresadas',
                    'monto_fichas_ingresadas',
                    'cantidad_fichas_consumidas',
                    'monto_fichas_consumidas',
                    'cantidad_fichas_cierre_esperado',
                    'monto_fichas_cierre_esperado',
                    'cantidad_fichas_cierre_declarado',
                    'monto_fichas_cierre_declarado',
                    'diferencia_efectivo',
                    'diferencia_fichas',
                    'diferencia_cantidad_fichas',
                ];

                $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('caja_arqueos', $column)));
                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
        }

        if (Schema::hasTable('cajas_diarias')) {
            Schema::table('cajas_diarias', function (Blueprint $table) {
                $columns = [
                    'monto_cierre_esperado',
                    'cantidad_fichas_apertura',
                    'monto_fichas_apertura',
                    'cantidad_fichas_ingresadas',
                    'monto_fichas_ingresadas',
                    'cantidad_fichas_consumidas',
                    'monto_fichas_consumidas',
                    'cantidad_fichas_cierre_esperado',
                    'monto_fichas_cierre_esperado',
                    'cantidad_fichas_cierre_declarado',
                    'monto_fichas_cierre_declarado',
                    'diferencia_efectivo',
                    'diferencia_fichas',
                    'diferencia_cantidad_fichas',
                ];

                $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('cajas_diarias', $column)));
                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
        }

        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                $columns = [
                    'cantidad_fichas_postales',
                    'monto_fichas_postales',
                    'valor_unitario_ficha_postal',
                    'detalle_fichas_postales',
                ];

                $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('ventas', $column)));
                if ($existing !== []) {
                    $table->dropColumn($existing);
                }
            });
        }
    }
};
