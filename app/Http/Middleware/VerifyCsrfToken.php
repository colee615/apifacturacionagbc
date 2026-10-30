<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Callbacks servidor a servidor: SEFE no utiliza la sesion del navegador.
        'notificacion/*',
        'dev/notificacion/*',
        'dev/notificaciones/*',
    ];
}
