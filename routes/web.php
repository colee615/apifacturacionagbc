<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
   return view('welcome');
});

// GET informativo para evitar 404 cuando alguien lo abre en el navegador
Route::get('/notificacion', function () {
    return response()->json([
        'message' => 'Endpoint solo acepta POST',
        'method'  => 'POST',
        'example' => '/notificacion/{codigoSeguimiento}',
    ], 405); // Method Not Allowed
});

// Tu endpoint real (POST)
Route::post('/notificacion/{codigoSeguimiento}', 'NotificacioneController@procesarNotificacion');