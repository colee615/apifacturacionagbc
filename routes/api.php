<?php

use App\Http\Controllers\FacturaVentaApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::middleware('factura.auth')->prefix('factura-venta')->group(function () {
    Route::post('/emitir', [FacturaVentaApiController::class, 'emitir']);
    Route::get('/consultar/{codigoSeguimiento}', [FacturaVentaApiController::class, 'consultar']);
    Route::get('/pdf/{codigoSeguimiento}', [FacturaVentaApiController::class, 'pdf']);
});

Route::middleware('factura.auth')->prefix('facturacion')->group(function () {
    Route::post('/emision/individual', [FacturaVentaApiController::class, 'emitir']);
});
