<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Usuario;
use App\Models\ViewAccess;
use Illuminate\Http\Request;

class RbacController extends Controller
{
   public function roles()
   {
      return Role::with(['permissions:id,slug,name', 'views:id,slug,name,route,is_active'])->orderBy('id')->get();
   }

   public function storeRole(Request $request)
   {
      $data = $request->validate([
         'name' => 'required|string|max:100',
         'slug' => 'required|string|max:100|unique:roles,slug',
         'description' => 'nullable|string|max:255',
         'is_system' => 'nullable|boolean',
      ]);

      $data['slug'] = strtolower($data['slug']);
      $role = Role::create($data);

      return response()->json($role, 201);
   }

   public function updateRole(Request $request, Role $role)
   {
      $data = $request->validate([
         'name' => 'sometimes|string|max:100',
         'slug' => 'sometimes|string|max:100|unique:roles,slug,' . $role->id,
         'description' => 'nullable|string|max:255',
         'is_system' => 'nullable|boolean',
      ]);

      if (isset($data['slug'])) {
         $data['slug'] = strtolower($data['slug']);
      }

      $role->update($data);
      return $role->fresh();
   }

   public function deleteRole(Role $role)
   {
      if ($role->is_system) {
         return response()->json(['error' => 'No se puede eliminar un rol del sistema'], 422);
      }

      $role->delete();
      return response()->json(['message' => 'Rol eliminado']);
   }

   public function permissions()
   {
      return Permission::orderBy('id')->get();
   }

   public function storePermission(Request $request)
   {
      $data = $request->validate([
         'name' => 'required|string|max:100',
         'slug' => 'required|string|max:100|unique:permissions,slug',
         'description' => 'nullable|string|max:255',
      ]);

      $data['slug'] = strtolower($data['slug']);
      return response()->json(Permission::create($data), 201);
   }

   public function updatePermission(Request $request, Permission $permission)
   {
      $data = $request->validate([
         'name' => 'sometimes|string|max:100',
         'slug' => 'sometimes|string|max:100|unique:permissions,slug,' . $permission->id,
         'description' => 'nullable|string|max:255',
      ]);

      if (isset($data['slug'])) {
         $data['slug'] = strtolower($data['slug']);
      }

      $permission->update($data);
      return $permission->fresh();
   }

   public function deletePermission(Permission $permission)
   {
      $permission->delete();
      return response()->json(['message' => 'Permiso eliminado']);
   }

   public function views()
   {
      return ViewAccess::orderBy('id')->get();
   }

   public function storeView(Request $request)
   {
      $data = $request->validate([
         'name' => 'required|string|max:100',
         'slug' => 'required|string|max:100|unique:views_access,slug',
         'route' => 'nullable|string|max:255',
         'description' => 'nullable|string|max:255',
         'is_active' => 'nullable|boolean',
      ]);

      $data['slug'] = strtolower($data['slug']);
      return response()->json(ViewAccess::create($data), 201);
   }

   public function updateView(Request $request, ViewAccess $view)
   {
      $data = $request->validate([
         'name' => 'sometimes|string|max:100',
         'slug' => 'sometimes|string|max:100|unique:views_access,slug,' . $view->id,
         'route' => 'nullable|string|max:255',
         'description' => 'nullable|string|max:255',
         'is_active' => 'nullable|boolean',
      ]);

      if (isset($data['slug'])) {
         $data['slug'] = strtolower($data['slug']);
      }

      $view->update($data);
      return $view->fresh();
   }

   public function deleteView(ViewAccess $view)
   {
      $view->delete();
      return response()->json(['message' => 'Vista eliminada']);
   }

   public function syncRolePermissions(Request $request, Role $role)
   {
      $data = $request->validate([
         'permission_ids' => 'array',
         'permission_ids.*' => 'integer|exists:permissions,id',
      ]);

      $role->permissions()->sync($data['permission_ids'] ?? []);
      return $role->load('permissions:id,name,slug');
   }

   public function syncRoleViews(Request $request, Role $role)
   {
      $data = $request->validate([
         'view_ids' => 'array',
         'view_ids.*' => 'integer|exists:views_access,id',
      ]);

      $role->views()->sync($data['view_ids'] ?? []);
      return $role->load('views:id,name,slug,route,is_active');
   }

   public function syncUserRoles(Request $request, Usuario $usuario)
   {
      $data = $request->validate([
         'role_ids' => 'required|array|min:1',
         'role_ids.*' => 'integer|exists:roles,id',
      ]);

      $usuario->roles()->sync($data['role_ids']);

      return response()->json([
         'usuario_id' => $usuario->id,
         'roles' => $usuario->roles()->get(['roles.id', 'roles.name', 'roles.slug']),
         'permissions' => $usuario->permissions(),
         'views' => $usuario->views(),
      ]);
   }

   public function userAccess(Usuario $usuario)
   {
      return response()->json([
         'usuario' => $usuario,
         'roles' => $usuario->roles()->get(['roles.id', 'roles.name', 'roles.slug']),
         'permissions' => $usuario->permissions(),
         'views' => $usuario->views(),
      ]);
   }
}
