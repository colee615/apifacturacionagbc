<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        DB::statement('ALTER TABLE ventas ADD COLUMN IF NOT EXISTS origen_usuario_id VARCHAR(100) NULL');
        DB::statement('ALTER TABLE ventas ADD COLUMN IF NOT EXISTS origen_usuario_email VARCHAR(120) NULL');
        DB::statement('ALTER TABLE ventas ADD COLUMN IF NOT EXISTS origen_sucursal_id VARCHAR(100) NULL');
        DB::statement('ALTER TABLE ventas ADD COLUMN IF NOT EXISTS origen_sucursal_codigo VARCHAR(100) NULL');
        DB::statement('ALTER TABLE ventas ADD COLUMN IF NOT EXISTS origen_sucursal_nombre VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (!Schema::hasTable('ventas')) {
            return;
        }

        DB::statement('ALTER TABLE ventas DROP COLUMN IF EXISTS origen_sucursal_nombre');
        DB::statement('ALTER TABLE ventas DROP COLUMN IF EXISTS origen_sucursal_codigo');
        DB::statement('ALTER TABLE ventas DROP COLUMN IF EXISTS origen_sucursal_id');
        DB::statement('ALTER TABLE ventas DROP COLUMN IF EXISTS origen_usuario_email');
        DB::statement('ALTER TABLE ventas DROP COLUMN IF EXISTS origen_usuario_id');
    }
};
