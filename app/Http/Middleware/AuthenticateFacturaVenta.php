<?php

namespace App\Http\Middleware;

use App\Models\IntegrationToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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
        $incomingToken = (string) ($request->bearerToken() ?? '');
        $integrationToken = (string) config('services.facturacion_api.integration_token');

        if ($incomingToken !== '' && $this->matchesManagedToken($incomingToken)) {
            return $next($request);
        }

        if ($incomingToken !== '' && $integrationToken !== '' && hash_equals($integrationToken, $incomingToken)) {
            return $next($request);
        }

        return $this->jwtAuthenticate->handle($request, $next);
    }

    private function matchesManagedToken(string $incomingToken): bool
    {
        if (!Schema::hasTable('integration_tokens')) {
            return false;
        }

        $tokenHash = hash('sha256', $incomingToken);

        /** @var IntegrationToken|null $token */
        $token = IntegrationToken::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if (!$token || !$token->isActive()) {
            return false;
        }

        $token->markAsUsed();

        return true;
    }
}
