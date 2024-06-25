<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
   /**
    * Get the path the user should be redirected to when they are not authenticated.
    *
    * @param  \Illuminate\Http\Request  $request
    * @return string|null
    */
   protected function redirectTo($request)
   {
      if (!$request->expectsJson()) {
         abort(401, 'Unauthenticated'); // Devuelve un error 401 en lugar de redirigir a la ruta login
      }
   }
}
