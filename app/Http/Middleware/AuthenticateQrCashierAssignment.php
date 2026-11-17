<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateQrCashierAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = trim((string) config('services.facturacion_api.qr_assignments_token'));
        $incomingToken = (string) $request->bearerToken();

        if ($expectedToken === '' || $incomingToken === '' || !hash_equals($expectedToken, $incomingToken)) {
            return response()->json(['ok' => false, 'message' => 'Acceso no autorizado.'], 401);
        }

        return $next($request);
    }
}
