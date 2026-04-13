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
            if (!Schema::hasColumn('ventas', 'origen_venta_id')) {
                $table->string('origen_venta_id')->nullable()->after('origen_sistema');
            }

            if (!Schema::hasColumn('ventas', 'origen_venta_tipo')) {
                $table->string('origen_venta_tipo')->nullable()->after('origen_venta_id');
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
                'origen_venta_id',
                'origen_venta_tipo',
            ], fn ($column) => Schema::hasColumn('ventas', $column)));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
