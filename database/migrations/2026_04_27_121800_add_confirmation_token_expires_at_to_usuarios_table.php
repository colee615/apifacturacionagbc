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
            if (!Schema::hasColumn('usuarios', 'confirmation_token_expires_at')) {
                $table->timestamp('confirmation_token_expires_at')->nullable()->after('confirmation_token');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('usuarios')) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table) {
            if (Schema::hasColumn('usuarios', 'confirmation_token_expires_at')) {
                $table->dropColumn('confirmation_token_expires_at');
            }
        });
    }
};
