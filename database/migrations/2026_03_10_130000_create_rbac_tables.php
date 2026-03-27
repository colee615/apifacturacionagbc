<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   /**
    * Run the migrations.
    */
   public function up(): void
   {
      Schema::create('roles', function (Blueprint $table) {
         $table->id();
         $table->string('name');
         $table->string('slug')->unique();
         $table->string('description')->nullable();
         $table->boolean('is_system')->default(false);
         $table->timestamps();
      });

      Schema::create('permissions', function (Blueprint $table) {
         $table->id();
         $table->string('name');
         $table->string('slug')->unique();
         $table->string('description')->nullable();
         $table->timestamps();
      });

      Schema::create('views_access', function (Blueprint $table) {
         $table->id();
         $table->string('name');
         $table->string('slug')->unique();
         $table->string('route')->nullable();
         $table->string('description')->nullable();
         $table->boolean('is_active')->default(true);
         $table->timestamps();
      });

      Schema::create('role_permission', function (Blueprint $table) {
         $table->id();
         $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
         $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
         $table->timestamps();
         $table->unique(['role_id', 'permission_id']);
      });

      Schema::create('role_view', function (Blueprint $table) {
         $table->id();
         $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
         $table->foreignId('view_access_id')->constrained('views_access')->cascadeOnDelete();
         $table->timestamps();
         $table->unique(['role_id', 'view_access_id']);
      });

      Schema::create('usuario_role', function (Blueprint $table) {
         $table->id();
         $table->foreignId('usuario_id')->constrained('usuarios')->cascadeOnDelete();
         $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
         $table->timestamps();
         $table->unique(['usuario_id', 'role_id']);
      });

      $now = now();

      DB::table('roles')->insert([
         ['name' => 'Administrador', 'slug' => 'admin', 'description' => 'Acceso total', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
         ['name' => 'Usuario', 'slug' => 'usuario', 'description' => 'Operacion de caja y ventas', 'is_system' => true, 'created_at' => $now, 'updated_at' => $now],
      ]);

      $permissions = [
         ['name' => 'Ver dashboard', 'slug' => 'dashboard.view'],
         ['name' => 'Gestionar sucursales', 'slug' => 'sucursales.manage'],
         ['name' => 'Gestionar usuarios', 'slug' => 'usuarios.manage'],
         ['name' => 'Leer ventas', 'slug' => 'ventas.read'],
         ['name' => 'Crear/editar ventas', 'slug' => 'ventas.write'],
         ['name' => 'Anular ventas', 'slug' => 'ventas.void'],
         ['name' => 'Ver logs', 'slug' => 'logs.view'],
         ['name' => 'Administrar RBAC', 'slug' => 'rbac.manage'],
      ];

      foreach ($permissions as $permission) {
         DB::table('permissions')->insert([
            'name' => $permission['name'],
            'slug' => $permission['slug'],
            'created_at' => $now,
            'updated_at' => $now,
         ]);
      }

      $views = [
         ['name' => 'Dashboard', 'slug' => 'dashboard', 'route' => '/dashboard'],
         ['name' => 'Sucursales', 'slug' => 'sucursales', 'route' => '/sucursales'],
         ['name' => 'Usuarios', 'slug' => 'usuarios', 'route' => '/usuarios'],
         ['name' => 'Ventas', 'slug' => 'ventas', 'route' => '/ventas'],
         ['name' => 'Reportes', 'slug' => 'reportes', 'route' => '/reportes'],
         ['name' => 'Configuracion', 'slug' => 'configuracion', 'route' => '/configuracion'],
         ['name' => 'Seguridad', 'slug' => 'seguridad', 'route' => '/seguridad'],
      ];

      foreach ($views as $view) {
         DB::table('views_access')->insert([
            'name' => $view['name'],
            'slug' => $view['slug'],
            'route' => $view['route'],
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
         ]);
      }

      $roleIds = DB::table('roles')->pluck('id', 'slug');
      $permissionIds = DB::table('permissions')->pluck('id', 'slug');
      $viewIds = DB::table('views_access')->pluck('id', 'slug');

      $adminPerms = array_keys($permissionIds->toArray());
      $usuarioPerms = ['dashboard.view', 'ventas.read', 'ventas.write'];

      foreach ($adminPerms as $slug) {
         DB::table('role_permission')->insert([
            'role_id' => $roleIds['admin'],
            'permission_id' => $permissionIds[$slug],
            'created_at' => $now,
            'updated_at' => $now,
         ]);
      }

      foreach ($usuarioPerms as $slug) {
         DB::table('role_permission')->insert([
            'role_id' => $roleIds['usuario'],
            'permission_id' => $permissionIds[$slug],
            'created_at' => $now,
            'updated_at' => $now,
         ]);
      }

      foreach (array_keys($viewIds->toArray()) as $viewSlug) {
         DB::table('role_view')->insert([
            'role_id' => $roleIds['admin'],
            'view_access_id' => $viewIds[$viewSlug],
            'created_at' => $now,
            'updated_at' => $now,
         ]);
      }

      foreach (['dashboard', 'ventas', 'reportes'] as $viewSlug) {
         DB::table('role_view')->insert([
            'role_id' => $roleIds['usuario'],
            'view_access_id' => $viewIds[$viewSlug],
            'created_at' => $now,
            'updated_at' => $now,
         ]);
      }

      $usuarios = DB::table('usuarios')->select('id')->get();
      foreach ($usuarios as $usuario) {
         $roleSlug = 'usuario';
         DB::table('usuario_role')->insert([
            'usuario_id' => $usuario->id,
            'role_id' => $roleIds[$roleSlug],
            'created_at' => $now,
            'updated_at' => $now,
         ]);
      }
   }

   /**
    * Reverse the migrations.
    */
   public function down(): void
   {
      Schema::dropIfExists('usuario_role');
      Schema::dropIfExists('role_view');
      Schema::dropIfExists('role_permission');
      Schema::dropIfExists('views_access');
      Schema::dropIfExists('permissions');
      Schema::dropIfExists('roles');
   }
};
