<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CajeroController;
use App\Http\Controllers\Reportes\ReporteCajeroController;
/*
|--------------------------------------------------------------------------
| Admin Routes
|--------------------------------------------------------------------------
|
| Here is where you can register admin routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "admin" middleware group. Now create something great!
|
*/

Route::middleware(['jwt.auth'])->group(function () {
   Route::apiResource('/empresa', 'EmpresaController');

   Route::apiResource('/clientes', 'ClienteController');
   Route::apiResource('/servicios', 'ServicioController');
   Route::apiResource('/ventas', 'VentaController');
   Route::apiResource('/notificaciones', 'NotificacioneController');
});
Route::post('login', 'CajeroController@login');
Route::post('verificar-codigo-confirmacion', 'CajeroController@verificarCodigoConfirmacion');
Route::post('request-password-reset', [CajeroController::class, 'requestPasswordReset']);
Route::post('reset-password/{token}', [CajeroController::class, 'resetPassword']);
Route::post('/cajeros/confirmar/{token}', [CajeroController::class, 'confirmar'])->name('cajeros.confirmar');
Route::apiResource('/sucursales', 'SucursaleController');
Route::apiResource('/cajeros', 'CajeroController');
