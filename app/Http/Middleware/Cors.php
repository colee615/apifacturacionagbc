<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Cors
{
   private function isAllowedOrigin(string $origin): bool
   {
      $allowedOrigins = config('cors.allowed_origins', []);
      $originPatterns = config('cors.allowed_origins_patterns', []);

      if (in_array('*', $allowedOrigins, true)) {
         return true;
      }

      if (in_array($origin, $allowedOrigins, true)) {
         return true;
      }

      foreach ($originPatterns as $pattern) {
         if (@preg_match($pattern, $origin)) {
            return true;
         }
      }

      return false;
   }

   private function resolveAllowOrigin(Request $request): ?string
   {
      $origin = (string) $request->headers->get('Origin', '');
      if ($origin === '') {
         return null;
      }

      if (!$this->isAllowedOrigin($origin)) {
         return null;
      }

      $supportsCredentials = (bool) config('cors.supports_credentials', false);
      if (in_array('*', config('cors.allowed_origins', []), true) && !$supportsCredentials) {
         return '*';
      }

      return $origin;
   }

   private function addCorsHeaders(Request $request, $response)
   {
      $allowOrigin = $this->resolveAllowOrigin($request);
      $allowedMethods = implode(', ', config('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']));
      $allowedHeaders = implode(', ', config('cors.allowed_headers', ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'Application']));
      $exposedHeaders = implode(', ', config('cors.exposed_headers', []));
      $maxAge = (int) config('cors.max_age', 3600);

      if ($allowOrigin) {
         $response->headers->set('Access-Control-Allow-Origin', $allowOrigin);
         $response->headers->set('Vary', 'Origin');
      }

      $response->headers->set('Access-Control-Allow-Methods', $allowedMethods);
      $response->headers->set('Access-Control-Allow-Headers', $allowedHeaders);
      $response->headers->set('Access-Control-Max-Age', (string) $maxAge);

      if ($exposedHeaders !== '') {
         $response->headers->set('Access-Control-Expose-Headers', $exposedHeaders);
      }

      if ((bool) config('cors.supports_credentials', false)) {
         $response->headers->set('Access-Control-Allow-Credentials', 'true');
      }

      // Security response headers for API surfaces.
      $response->headers->set('X-Content-Type-Options', 'nosniff');
      $response->headers->set('X-Frame-Options', 'DENY');
      $response->headers->set('Referrer-Policy', 'no-referrer');

      return $response;
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
      $origin = (string) $request->headers->get('Origin', '');

      if ($origin !== '' && !$this->isAllowedOrigin($origin)) {
         return response()->json([
            'error' => 'Origen no permitido por la política CORS.'
         ], 403);
      }

      if ($request->getMethod() === 'OPTIONS') {
         return $this->addCorsHeaders($request, response()->json([], 204));
      }

      return $this->addCorsHeaders($request, $next($request));
   }
}
