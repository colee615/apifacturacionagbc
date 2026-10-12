<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('facturacion_tarifa_trazas')) {
            return;
        }

        Schema::table('facturacion_tarifa_trazas', function (Blueprint $table): void {
            if (! Schema::hasColumn('facturacion_tarifa_trazas', 'manual_version_id')) {
                $table->unsignedBigInteger('manual_version_id')->nullable()->after('tarifario_snapshot');
            }
            if (! Schema::hasColumn('facturacion_tarifa_trazas', 'manual_version_codigo')) {
                $table->string('manual_version_codigo', 80)->nullable()->after('manual_version_id');
            }
            if (! Schema::hasColumn('facturacion_tarifa_trazas', 'manual_version_nombre')) {
                $table->string('manual_version_nombre', 180)->nullable()->after('manual_version_codigo');
            }
            if (! Schema::hasColumn('facturacion_tarifa_trazas', 'manual_version_snapshot')) {
                $table->json('manual_version_snapshot')->nullable()->after('manual_version_nombre');
            }
        });

        Schema::table('facturacion_tarifa_trazas', function (Blueprint $table): void {
            $table->index(['manual_version_id', 'capturado_en'], 'facturacion_tarifa_traza_manual_version_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('facturacion_tarifa_trazas')) {
            return;
        }

        Schema::table('facturacion_tarifa_trazas', function (Blueprint $table): void {
            $table->dropIndex('facturacion_tarifa_traza_manual_version_idx');
        });

        Schema::table('facturacion_tarifa_trazas', function (Blueprint $table): void {
            foreach (['manual_version_id', 'manual_version_codigo', 'manual_version_nombre', 'manual_version_snapshot'] as $column) {
                if (Schema::hasColumn('facturacion_tarifa_trazas', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
