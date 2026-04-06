<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('usuarios') || !Schema::hasColumn('usuarios', 'sucursale_id')) {
            return;
        }

        DB::statement("
            DO $$
            DECLARE constraint_name text;
            BEGIN
                SELECT con.conname
                INTO constraint_name
                FROM pg_constraint con
                JOIN pg_attribute att
                  ON att.attrelid = con.conrelid
                 AND att.attnum = ANY (con.conkey)
                WHERE con.conrelid = 'usuarios'::regclass
                  AND att.attname = 'sucursale_id'
                  AND con.contype = 'f'
                LIMIT 1;

                IF constraint_name IS NOT NULL THEN
                    EXECUTE format('ALTER TABLE usuarios DROP CONSTRAINT %I', constraint_name);
                END IF;
            END $$;
        ");

        Schema::table('usuarios', function (Blueprint $table) {
            $table->dropColumn('sucursale_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('usuarios') || Schema::hasColumn('usuarios', 'sucursale_id') || !Schema::hasTable('sucursales')) {
            return;
        }

        Schema::table('usuarios', function (Blueprint $table) {
            $table->foreignId('sucursale_id')->nullable()->constrained('sucursales')->after('confirmation_token');
        });
    }
};
