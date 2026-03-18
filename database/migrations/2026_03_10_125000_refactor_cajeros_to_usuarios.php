<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   public function up(): void
   {
      if (Schema::hasTable('cajeros') && !Schema::hasTable('usuarios')) {
         DB::statement('ALTER TABLE cajeros RENAME TO usuarios');
      }

      if (Schema::hasTable('ventas')) {
         $hasCajeroId = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='ventas' AND column_name='cajero_id'");
         if ($hasCajeroId) {
            DB::statement('ALTER TABLE ventas RENAME COLUMN cajero_id TO usuario_id');
         }
      }

      if (Schema::hasTable('login_logs')) {
         $hasCajeroId = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='login_logs' AND column_name='cajero_id'");
         if ($hasCajeroId) {
            DB::statement('ALTER TABLE login_logs RENAME COLUMN cajero_id TO usuario_id');
         }
      }

      if (Schema::hasTable('special_access_logs')) {
         $hasCajeroId = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='special_access_logs' AND column_name='cajero_id'");
         if ($hasCajeroId) {
            DB::statement('ALTER TABLE special_access_logs RENAME COLUMN cajero_id TO usuario_id');
         }
      }

      if (Schema::hasTable('cajero_role') && !Schema::hasTable('usuario_role')) {
         DB::statement('ALTER TABLE cajero_role RENAME TO usuario_role');
      }

      if (Schema::hasTable('usuario_role')) {
         $hasCajeroId = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='usuario_role' AND column_name='cajero_id'");
         if ($hasCajeroId) {
            DB::statement('ALTER TABLE usuario_role RENAME COLUMN cajero_id TO usuario_id');
         }
      }

      if (Schema::hasTable('permissions')) {
         DB::table('permissions')->where('slug', 'cajeros.manage')->update([
            'slug' => 'usuarios.manage',
            'name' => 'Gestionar usuarios',
         ]);
      }

      if (Schema::hasTable('views_access')) {
         DB::table('views_access')->where('slug', 'cajeros')->update([
            'slug' => 'usuarios',
            'name' => 'Usuarios',
            'route' => '/usuarios',
         ]);
      }
   }

   public function down(): void
   {
      if (Schema::hasTable('usuario_role')) {
         $hasUsuarioId = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='usuario_role' AND column_name='usuario_id'");
         if ($hasUsuarioId) {
            DB::statement('ALTER TABLE usuario_role RENAME COLUMN usuario_id TO cajero_id');
         }
      }

      if (Schema::hasTable('usuario_role') && !Schema::hasTable('cajero_role')) {
         DB::statement('ALTER TABLE usuario_role RENAME TO cajero_role');
      }

      if (Schema::hasTable('special_access_logs')) {
         $hasUsuarioId = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='special_access_logs' AND column_name='usuario_id'");
         if ($hasUsuarioId) {
            DB::statement('ALTER TABLE special_access_logs RENAME COLUMN usuario_id TO cajero_id');
         }
      }

      if (Schema::hasTable('login_logs')) {
         $hasUsuarioId = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='login_logs' AND column_name='usuario_id'");
         if ($hasUsuarioId) {
            DB::statement('ALTER TABLE login_logs RENAME COLUMN usuario_id TO cajero_id');
         }
      }

      if (Schema::hasTable('ventas')) {
         $hasUsuarioId = DB::selectOne("SELECT 1 FROM information_schema.columns WHERE table_schema='public' AND table_name='ventas' AND column_name='usuario_id'");
         if ($hasUsuarioId) {
            DB::statement('ALTER TABLE ventas RENAME COLUMN usuario_id TO cajero_id');
         }
      }

      if (Schema::hasTable('usuarios') && !Schema::hasTable('cajeros')) {
         DB::statement('ALTER TABLE usuarios RENAME TO cajeros');
      }

      if (Schema::hasTable('permissions')) {
         DB::table('permissions')->where('slug', 'usuarios.manage')->update([
            'slug' => 'cajeros.manage',
            'name' => 'Gestionar cajeros',
         ]);
      }

      if (Schema::hasTable('views_access')) {
         DB::table('views_access')->where('slug', 'usuarios')->update([
            'slug' => 'cajeros',
            'name' => 'Cajeros',
            'route' => '/cajeros',
         ]);
      }
   }
};
