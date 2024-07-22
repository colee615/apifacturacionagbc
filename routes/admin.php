<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CajeroController;


Route::middleware(['jwt.auth'])->group(function () {
   Route::apiResource('/empresa', 'EmpresaController');

   Route::apiResource('/clientes', 'ClienteController');
   Route::apiResource('/servicios', 'ServicioController');
   Route::apiResource('/ventas', 'VentaController');
   Route::apiResource('/notificaciones', 'NotificacioneController');
   Route::get('venta/pdf/{codigoSeguimiento}', 'VentaController@getPdfUrl');
});
Route::post('login', 'CajeroController@login');
Route::post('verificar-codigo-confirmacion', 'CajeroController@verificarCodigoConfirmacion');
Route::post('request-password-reset', [CajeroController::class, 'requestPasswordReset']);
Route::post('reset-password/{token}', [CajeroController::class, 'resetPassword']);
Route::post('/cajeros/confirmar/{token}', [CajeroController::class, 'confirmar'])->name('cajeros.confirmar');

Route::get('test', 'VentaController@test');
Route::get('ventas/consultar/{codigoSeguimiento}', 'VentaController@consultarVenta');
Route::patch('ventas/anular/{cuf}', 'VentaController@anularFactura');
Route::apiResource('/sucursales', 'SucursaleController');
Route::apiResource('/cajeros', 'CajeroController');
