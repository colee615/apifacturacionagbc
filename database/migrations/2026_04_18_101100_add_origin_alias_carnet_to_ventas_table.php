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
            if (!Schema::hasColumn('ventas', 'origen_usuario_alias')) {
                $table->string('origen_usuario_alias', 80)->nullable()->after('origen_usuario_email');
            }
            if (!Schema::hasColumn('ventas', 'origen_usuario_carnet')) {
                $table->string('origen_usuario_carnet', 40)->nullable()->after('origen_usuario_alias');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        Schema::table('ventas', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('ventas', 'origen_usuario_alias')) {
                $drop[] = 'origen_usuario_alias';
            }
            if (Schema::hasColumn('ventas', 'origen_usuario_carnet')) {
                $drop[] = 'origen_usuario_carnet';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};

