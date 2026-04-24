<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('usuarios')) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table) {
            if (!Schema::hasColumn('usuarios', 'alias')) {
                $table->string('alias', 80)->nullable()->after('name');
            }
            if (!Schema::hasColumn('usuarios', 'numero_carnet')) {
                $table->string('numero_carnet', 40)->nullable()->after('email');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('usuarios')) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $drop = [];
            if (Schema::hasColumn('usuarios', 'alias')) {
                $drop[] = 'alias';
            }
            if (Schema::hasColumn('usuarios', 'numero_carnet')) {
                $drop[] = 'numero_carnet';
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};

