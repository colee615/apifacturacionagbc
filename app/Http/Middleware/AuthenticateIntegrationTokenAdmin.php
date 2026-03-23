<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Http\Middleware\Authenticate as JwtAuthenticate;

class AuthenticateIntegrationTokenAdmin
{
    public function __construct(
        private readonly JwtAuthenticate $jwtAuthenticate
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isTrustedLocalRequest($request)) {
            return $next($request);
        }

        $response = $this->jwtAuthenticate->handle($request, function ($request) use ($next) {
            $user = auth('api')->user() ?? auth()->user();

            if (!$user || !method_exists($user, 'hasPermission') || !$user->hasPermission('rbac.manage')) {
                return response()->json([
                    'error' => 'No autorizado',
                    'required_permission' => ['rbac.manage'],
                ], 403);
            }

            return $next($request);
        });

        return $response;
    }

    private function isTrustedLocalRequest(Request $request): bool
    {
        if (!app()->environment('local')) {
            return false;
        }

        $trustedIps = collect(['127.0.0.1', '::1'])
            ->merge(gethostbynamel(gethostname()) ?: [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return in_array($request->ip(), $trustedIps, true);
    }
}
