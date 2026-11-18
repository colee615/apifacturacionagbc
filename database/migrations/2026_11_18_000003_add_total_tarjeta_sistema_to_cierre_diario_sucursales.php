<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cierre_diario_sucursales')
            || Schema::hasColumn('cierre_diario_sucursales', 'total_tarjeta_sistema')) {
            return;
        }

        Schema::table('cierre_diario_sucursales', function (Blueprint $table): void {
            $table->decimal('total_tarjeta_sistema', 14, 2)
                ->default(0)
                ->after('total_qr_sistema');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cierre_diario_sucursales')
            || ! Schema::hasColumn('cierre_diario_sucursales', 'total_tarjeta_sistema')) {
            return;
        }

        Schema::table('cierre_diario_sucursales', function (Blueprint $table): void {
            $table->dropColumn('total_tarjeta_sistema');
        });
    }
};
