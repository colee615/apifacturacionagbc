<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            if (!Schema::hasColumn('facturacion_carts', 'origen_usuario_alias')) {
                $table->string('origen_usuario_alias', 80)->nullable()->after('origen_usuario_email');
            }
            if (!Schema::hasColumn('facturacion_carts', 'origen_usuario_carnet')) {
                $table->string('origen_usuario_carnet', 40)->nullable()->after('origen_usuario_alias');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('facturacion_carts')) {
            return;
        }

        Schema::table('facturacion_carts', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('facturacion_carts', 'origen_usuario_alias')) {
                $drop[] = 'origen_usuario_alias';
            }
            if (Schema::hasColumn('facturacion_carts', 'origen_usuario_carnet')) {
                $drop[] = 'origen_usuario_carnet';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};

