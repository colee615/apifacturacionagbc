<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
   {
      if (!Schema::hasTable('usuarios')) {
         return;
      }

      $hasRole = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='usuarios' AND column_name='role'");
      if ($hasRole) {
         DB::statement('ALTER TABLE usuarios DROP COLUMN role');
      }
   }

   public function down(): void
   {
      if (!Schema::hasTable('usuarios')) {
         return;
      }

      $hasRole = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='usuarios' AND column_name='role'");
      if (!$hasRole) {
         DB::statement("ALTER TABLE usuarios ADD COLUMN role character varying(255) DEFAULT 'cajero'");
      }
   }
};
