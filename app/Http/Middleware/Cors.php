<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Cors
{
   private function addCorsHeaders($response)
   {
      return $response
         ->header('Access-Control-Allow-Origin', '*')
         ->header('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
         ->header('Access-Control-Allow-Headers', 'Content-Type, Accept, Authorization, X-Requested-With, Application')
         ->header('Access-Control-Max-Age', '86400');
   }

   /**
    * Handle an incoming request.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \Closure(\Illuminate\Http\Request): (\Illuminate\Http\Response|\Illuminate\Http\RedirectResponse)  $next
    * @return \Illuminate\Http\Response|\Illuminate\Http\RedirectResponse
    */
   public function handle(Request $request, Closure $next)
   {
      if ($request->getMethod() === 'OPTIONS') {
         return $this->addCorsHeaders(response()->json([], 204));
      }

      return $this->addCorsHeaders($next($request));
   }
}
