<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Http\Middleware\Authenticate as JwtAuthenticate;

class AuthenticateFacturaVenta
{
    public function __construct(
        private readonly JwtAuthenticate $jwtAuthenticate
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $incomingToken = $request->bearerToken();
        $integrationToken = (string) config('services.facturacion_api.integration_token');

        if ($incomingToken !== '' && $integrationToken !== '' && hash_equals($integrationToken, $incomingToken)) {
            return $next($request);
        }

        return $this->jwtAuthenticate->handle($request, $next);
    }
}
