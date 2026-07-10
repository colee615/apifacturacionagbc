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
                if (!Schema::hasColumn('ventas', 'peso_total')) {
                    $table->decimal('peso_total', 12, 3)->default(0)->after('total');
                }
            });
        }

        if (Schema::hasTable('detalle_ventas')) {
            Schema::table('detalle_ventas', function (Blueprint $table) {
                if (!Schema::hasColumn('detalle_ventas', 'peso')) {
                    $table->decimal('peso', 12, 3)->default(0)->after('cantidad');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('detalle_ventas')) {
            Schema::table('detalle_ventas', function (Blueprint $table) {
                if (Schema::hasColumn('detalle_ventas', 'peso')) {
                    $table->dropColumn('peso');
                }
            });
        }

        if (Schema::hasTable('ventas')) {
            Schema::table('ventas', function (Blueprint $table) {
                if (Schema::hasColumn('ventas', 'peso_total')) {
                    $table->dropColumn('peso_total');
                }
            });
        }
    }
};
