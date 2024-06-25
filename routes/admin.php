<?php

use Illuminate\Support\Facades\Route;


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

Route::apiResource('/empresa', 'EmpresaController');
Route::apiResource('/sucursales', 'SucursaleController');
Route::apiResource('/cajeros', 'CajeroController');
Route::apiResource('/clientes', 'ClienteController');
Route::apiResource('/servicios', 'ServicioController');
Route::apiResource('/ventas', 'VentaController');
Route::apiResource('/notificaciones', 'NotificacioneController');


Route::post('login', 'CajeroController@login');


Route::post('/notificacion/{codigoSeguimiento}', 'NotificacioneController@procesarNotificacion');
Route::post('/emitir-factura', 'PosFacturacionController@emitirFactura');



Route::get('test','PrinterController@test');