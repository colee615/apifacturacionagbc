<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureHasPermission
{
   /**
    * Handle an incoming request.
    *
    * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
    */
   public function handle(Request $request, Closure $next, string $permission)
   {
      $user = Auth::guard('api')->user() ?? Auth::user();

      if (!$user || !method_exists($user, 'hasPermission')) {
         return response()->json(['error' => 'No autenticado'], 401);
      }

      $requestedPermissions = collect(explode(',', $permission))
         ->map(fn($item) => trim((string) $item))
         ->filter()
         ->values();

      $authorized = $requestedPermissions->isNotEmpty()
         ? $requestedPermissions->contains(fn($perm) => $user->hasPermission($perm))
         : $user->hasPermission($permission);

      if (!$authorized) {
         return response()->json([
            'error' => 'No autorizado',
            'required_permission' => $requestedPermissions->isNotEmpty()
               ? $requestedPermissions->all()
               : [$permission],
         ], 403);
      }

      return $next($request);
   }
}
