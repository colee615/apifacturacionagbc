<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Usuario extends Authenticatable implements JWTSubject
{
   use HasApiTokens, HasFactory, Notifiable;

   private static ?bool $rbacTablesReady = null;

   protected $table = 'usuarios';

   protected $fillable = [
      'name',
      'email',
      'password',
      'estado',
   ];

   protected $hidden = [
      'password',
      'confirmation_token'
   ];

   protected $casts = [];

   public function roles()
   {
      return $this->belongsToMany(Role::class, 'usuario_role')->withTimestamps();
   }

   public function roleSlugs(): array
   {
      if (!$this->rbacTablesReady()) {
         return ['usuario'];
      }

      $roles = $this->roles()
         ->pluck('slug')
         ->map(fn($slug) => strtolower((string) $slug))
         ->values();

      if ($roles->isEmpty()) {
         return ['usuario'];
      }

      return $roles->all();
   }

   public function permissions(): array
   {
      if (!$this->rbacTablesReady()) {
         return config('permissions.roles.usuario', []);
      }

      $roles = $this->roles()
         ->with('permissions:id,slug')
         ->get();

      if ($roles->isEmpty()) {
         return config('permissions.roles.usuario', []);
      }

      $permissionSlugs = $roles
         ->pluck('permissions')
         ->flatten()
         ->pluck('slug')
         ->map(fn($slug) => strtolower((string) $slug))
         ->unique()
         ->values();

      // If the user has roles but no permissions are assigned, return an empty list.
      return $permissionSlugs->all();
   }

   public function hasPermission(string $permission): bool
   {
      return in_array($permission, $this->permissions(), true);
   }

   public function hasRole(string $role): bool
   {
      return in_array(strtolower($role), $this->roleSlugs(), true);
   }

   public function views(): array
   {
      if (!$this->rbacTablesReady()) {
         return [];
      }

      $viewSlugs = $this->roles()
         ->with('views:id,slug,is_active')
         ->get()
         ->pluck('views')
         ->flatten()
         ->where('is_active', true)
         ->pluck('slug')
         ->map(fn($slug) => strtolower((string) $slug))
         ->unique()
         ->values();

      return $viewSlugs->all();
   }

   public function syncRolesBySlugs(array $roleSlugs): void
   {
      $slugs = collect($roleSlugs)
         ->map(fn($slug) => strtolower((string) $slug))
         ->map(fn($slug) => $slug === 'administrador' ? 'admin' : $slug)
         ->filter()
         ->unique()
         ->values();

      if ($slugs->isEmpty()) {
         $slugs = collect(['usuario']);
      }

      if (!$this->rbacTablesReady()) {
         return;
      }

      $roleIds = Role::whereIn('slug', $slugs)->pluck('id')->all();
      $this->roles()->sync($roleIds);
   }

   private function rbacTablesReady(): bool
   {
      if (self::$rbacTablesReady !== null) {
         return self::$rbacTablesReady;
      }

      self::$rbacTablesReady = Schema::hasTable('roles')
         && Schema::hasTable('permissions')
         && Schema::hasTable('role_permission')
         && Schema::hasTable('views_access')
         && Schema::hasTable('role_view')
         && Schema::hasTable('usuario_role');

      return self::$rbacTablesReady;
   }

   public function getJWTIdentifier()
   {
      return $this->getKey();
   }

   public function getJWTCustomClaims()
   {
      return [
         'roles' => $this->roleSlugs(),
         'permissions' => $this->permissions(),
         'views' => $this->views(),
      ];
   }
}
