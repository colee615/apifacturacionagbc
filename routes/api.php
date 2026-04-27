<?php

use App\Http\Controllers\FacturaVentaApiController;
use App\Http\Controllers\FacturacionCartIntegrationController;
use App\Http\Controllers\CajaDiariaController;
use App\Http\Controllers\VentaController;
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
    Route::patch('/anular/{cuf}', [FacturaVentaApiController::class, 'anular']);
    Route::get('/consultar/{codigoSeguimiento}', [FacturaVentaApiController::class, 'consultar']);
    Route::get('/pdf/{codigoSeguimiento}', [FacturaVentaApiController::class, 'pdf']);
    Route::get('/cart/context', [FacturacionCartIntegrationController::class, 'context']);
    Route::put('/cart/billing', [FacturacionCartIntegrationController::class, 'updateBilling']);
    Route::post('/cart/items/upsert', [FacturacionCartIntegrationController::class, 'upsertItem']);
    Route::put('/cart/items/{itemId}', [FacturacionCartIntegrationController::class, 'updateItem']);
    Route::delete('/cart/items/{itemId}', [FacturacionCartIntegrationController::class, 'removeItem']);
    Route::post('/cart/clear', [FacturacionCartIntegrationController::class, 'clear']);
    Route::post('/cart/emitir', [FacturacionCartIntegrationController::class, 'emitir']);
    Route::post('/cart/consultar', [FacturacionCartIntegrationController::class, 'consultar']);
    Route::get('/cart/ventas', [FacturacionCartIntegrationController::class, 'ventas']);
    Route::get('/cart/ventas/pdf', [FacturacionCartIntegrationController::class, 'ventasPdf']);
    Route::get('/cart/ventas/{cartId}', [FacturacionCartIntegrationController::class, 'show']);
    Route::get('/ventas/reportes/kardex-usuarios', [VentaController::class, 'kardexUsuarios']);
    Route::get('/ventas/consultar/{codigoSeguimiento}', [VentaController::class, 'consultarVenta']);
    Route::get('/ventas/{venta}', [VentaController::class, 'show']);
    Route::get('/caja/estado', [CajaDiariaController::class, 'estado']);
    Route::post('/caja/abrir', [CajaDiariaController::class, 'abrir']);
    Route::post('/caja/cerrar', [CajaDiariaController::class, 'cerrar']);
    Route::get('/caja/fichas/stock', [CajaDiariaController::class, 'fichasStock']);
    Route::get('/caja/fichas/sucursal/stock', [CajaDiariaController::class, 'sucursalStock']);
    Route::get('/caja/fichas/cajeros/saldos', [CajaDiariaController::class, 'fichasCajerosSaldos']);
    Route::post('/caja/fichas/sucursal/abastecer', [CajaDiariaController::class, 'abastecerSucursal']);
    Route::post('/caja/fichas/asignar', [CajaDiariaController::class, 'asignarFichas']);
    Route::get('/caja/arqueos', [CajaDiariaController::class, 'arqueos']);
    Route::get('/caja/reporte-diario', [CajaDiariaController::class, 'reporteDiario']);
});

Route::middleware('factura.auth')->prefix('facturacion')->group(function () {
    Route::post('/emision/individual', [FacturaVentaApiController::class, 'emitir']);
    Route::patch('/anulacion/{cuf}', [FacturaVentaApiController::class, 'anular']);
    Route::post('/contingencia', [FacturaVentaApiController::class, 'contingenciaCafc']);
});
