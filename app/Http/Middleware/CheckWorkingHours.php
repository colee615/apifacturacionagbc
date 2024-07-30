<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CheckWorkingHours
{
   public function handle(Request $request, Closure $next)
   {
      $currentHour = now()->format('H');
      $startHour = 8; // Hora de inicio (8 AM)
      $endHour = 14; // Hora de fin (7 PM)

      // Verifica si el usuario tiene acceso especial o está dentro del horario permitido
      if (Auth::check() && (Auth::user()->special_access || ($currentHour >= $startHour && $currentHour < $endHour))) {
         return $next($request);
      }

      // Si está fuera del horario permitido, cierra la sesión
      if (Auth::check()) {
         Auth::logout();
      }

      return response()->json(['error' => 'Fuera del horario laboral permitido.'], 403);
   }
}
