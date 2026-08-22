<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('cierre_diario_comprobantes')) {
            return;
        }

        Schema::table('cierre_diario_comprobantes', function (Blueprint $table) {
            if (!Schema::hasColumn('cierre_diario_comprobantes', 'nombre_banco')) {
                $table->string('nombre_banco', 160)->nullable()->after('banco');
            }
            if (!Schema::hasColumn('cierre_diario_comprobantes', 'usuario_banco')) {
                $table->string('usuario_banco', 120)->nullable()->after('nombre_banco');
            }
            if (!Schema::hasColumn('cierre_diario_comprobantes', 'agencia_banco')) {
                $table->string('agencia_banco', 180)->nullable()->after('usuario_banco');
            }
            if (!Schema::hasColumn('cierre_diario_comprobantes', 'transaccion_banco')) {
                $table->string('transaccion_banco', 180)->nullable()->after('agencia_banco');
            }
            if (!Schema::hasColumn('cierre_diario_comprobantes', 'fecha_comprobante')) {
                $table->string('fecha_comprobante', 40)->nullable()->after('transaccion_banco');
            }
            if (!Schema::hasColumn('cierre_diario_comprobantes', 'moneda_comprobante')) {
                $table->string('moneda_comprobante', 20)->nullable()->after('fecha_comprobante');
            }
            if (!Schema::hasColumn('cierre_diario_comprobantes', 'depositante')) {
                $table->string('depositante', 180)->nullable()->after('moneda_comprobante');
            }
            if (!Schema::hasColumn('cierre_diario_comprobantes', 'beneficiario')) {
                $table->string('beneficiario', 180)->nullable()->after('depositante');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('cierre_diario_comprobantes')) {
            return;
        }

        Schema::table('cierre_diario_comprobantes', function (Blueprint $table) {
            foreach ([
                'nombre_banco',
                'usuario_banco',
                'agencia_banco',
                'transaccion_banco',
                'fecha_comprobante',
                'moneda_comprobante',
                'depositante',
                'beneficiario',
            ] as $column) {
                if (Schema::hasColumn('cierre_diario_comprobantes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
