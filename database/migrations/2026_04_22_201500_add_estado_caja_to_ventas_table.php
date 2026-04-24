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
            if (!Schema::hasColumn('ventas', 'estado_caja')) {
                $table->string('estado_caja', 30)->nullable()->after('estado');
            }
            if (!Schema::hasColumn('ventas', 'arqueado_en')) {
                $table->timestamp('arqueado_en')->nullable()->after('estado_caja');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            if (Schema::hasColumn('ventas', 'arqueado_en')) {
                $table->dropColumn('arqueado_en');
            }
            if (Schema::hasColumn('ventas', 'estado_caja')) {
                $table->dropColumn('estado_caja');
            }
        });
    }
};

