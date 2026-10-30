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

// Receptor de pruebas: SEFE agrega /{codigoSeguimiento} a la URL configurada.
Route::get('/dev/notificacion', function () {
    return response()->json([
        'message' => 'Receptor de notificaciones SEFE de desarrollo. Utilice POST con el codigo de seguimiento.',
        'method' => 'POST',
        'example' => '/dev/notificacion/{codigoSeguimiento}',
    ], 405);
});
Route::post('/dev/notificacion/{codigoSeguimiento}', 'NotificacionDemoController@recibir');

// Alias plural para el apartado solicitado; no sustituye el listado principal.
Route::get('/dev/notificaciones', 'NotificacionDemoController@index')->middleware('factura.auth');
Route::post('/dev/notificaciones/{codigoSeguimiento}', 'NotificacionDemoController@recibir');
