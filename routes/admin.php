<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\IntegrationTokenController;
use App\Http\Controllers\CajaDiariaController;
use App\Http\Controllers\RbacController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VentaController;

Route::post('login', 'UsuarioController@login');
Route::post('request-password-reset', [UsuarioController::class, 'requestPasswordReset']);
Route::post('reset-password/{token}', [UsuarioController::class, 'resetPassword']);

Route::middleware(['jwt.auth'])->group(function () {
   Route::get('me', [UsuarioController::class, 'me']);
   Route::get('/ventas', 'VentaController@index')->middleware('permission:ventas.read');
   Route::get('/ventas/reportes/kardex-usuarios', 'VentaController@kardexUsuarios')->middleware('permission:ventas.read');
   Route::get('/ventas/reportes/kardex-pdf', 'VentaController@reporteKardexPdf')->middleware('permission:ventas.read');
   Route::get('/ventas/reportes/resumen', 'VentaController@reporteVentas')->middleware('permission:ventas.read');
   Route::get('/ventas/operables', 'VentaController@operables')->middleware('permission:ventas.read');
   Route::post('/ventas/emitir-seleccion', 'VentaController@emitirVentasSeleccionadas')->middleware('permission:ventas.write');
   Route::post('/ventas/consultar-seleccion', 'VentaController@consultarVentasSeleccionadas')->middleware('permission:ventas.read');
   Route::post('/ventas/masiva', 'VentaController@emitirFacturasMasivas')->middleware('permission:ventas.write');
   Route::post('/ventas/contingencia-cafc', 'VentaController@emitirContingenciaCafc')->middleware('permission:ventas.write');
   Route::post('/ventas/contingencia-cafc-seleccion', 'VentaController@emitirContingenciaCafcSeleccionadas')->middleware('permission:ventas.write');
   Route::get('/ventas/consultar-paquete/{codigoSeguimientoPaquete}', 'VentaController@consultarPaquete')->middleware('permission:ventas.read');
   Route::get('/ventas/consultar/{codigoSeguimiento}', 'VentaController@consultarVenta')->middleware('permission:ventas.read');
   Route::patch('/ventas/anular/{cuf}', 'VentaController@anularFactura')->middleware('permission:ventas.write');
   Route::get('/ventas/{venta}', 'VentaController@show')->middleware('permission:ventas.read');

   Route::get('/caja/estado', [CajaDiariaController::class, 'estado'])->middleware('permission:ventas.read');
   Route::post('/caja/abrir', [CajaDiariaController::class, 'abrir'])->middleware('permission:ventas.write');
   Route::post('/caja/cerrar', [CajaDiariaController::class, 'cerrar'])->middleware('permission:ventas.write');
   Route::get('/caja/arqueos', [CajaDiariaController::class, 'arqueos'])->middleware('permission:ventas.read');
   Route::get('/caja/reporte-diario', [CajaDiariaController::class, 'reporteDiario'])->middleware('permission:ventas.read');
});

Route::middleware(['jwt.auth'])->group(function () {
   Route::get('/usuarios', 'UsuarioController@index')->middleware('permission:usuarios.manage');
   Route::get('/usuarios/{usuario}', 'UsuarioController@show')->whereNumber('usuario')->middleware('permission:usuarios.manage');
   Route::post('/usuarios', 'UsuarioController@store')->middleware('permission:usuarios.manage,usuarios.create');
   Route::put('/usuarios/{usuario}', 'UsuarioController@update')->whereNumber('usuario')->middleware('permission:usuarios.manage,usuarios.update');
   Route::patch('/usuarios/{usuario}', 'UsuarioController@update')->whereNumber('usuario')->middleware('permission:usuarios.manage,usuarios.update');
   Route::delete('/usuarios/{usuario}', 'UsuarioController@destroy')->whereNumber('usuario')->middleware('permission:usuarios.manage,usuarios.delete');
   Route::put('/usuarios/activar/{id}', 'UsuarioController@activar')->middleware('permission:usuarios.manage,usuarios.update');

});

Route::middleware(['jwt.auth', 'permission:dashboard.view'])->group(function () {
   Route::get('/notificaciones', 'NotificacioneController@index');
   Route::get('/notificaciones/{notificacione}', 'NotificacioneController@show');
});

Route::middleware(['jwt.auth', 'permission:rbac.manage'])->prefix('rbac')->group(function () {
   Route::get('/roles', [RbacController::class, 'roles']);
   Route::post('/roles', [RbacController::class, 'storeRole']);
   Route::put('/roles/{role}', [RbacController::class, 'updateRole']);
   Route::patch('/roles/{role}', [RbacController::class, 'updateRole']);
   Route::delete('/roles/{role}', [RbacController::class, 'deleteRole']);

   Route::get('/permissions', [RbacController::class, 'permissions']);
   Route::post('/permissions', [RbacController::class, 'storePermission']);
   Route::put('/permissions/{permission}', [RbacController::class, 'updatePermission']);
   Route::patch('/permissions/{permission}', [RbacController::class, 'updatePermission']);
   Route::delete('/permissions/{permission}', [RbacController::class, 'deletePermission']);

   Route::get('/views', [RbacController::class, 'views']);
   Route::post('/views', [RbacController::class, 'storeView']);
   Route::put('/views/{view}', [RbacController::class, 'updateView']);
   Route::patch('/views/{view}', [RbacController::class, 'updateView']);
   Route::delete('/views/{view}', [RbacController::class, 'deleteView']);

   Route::post('/roles/{role}/permissions', [RbacController::class, 'syncRolePermissions']);
   Route::post('/roles/{role}/views', [RbacController::class, 'syncRoleViews']);
   Route::post('/users/{usuario}/roles', [RbacController::class, 'syncUserRoles']);
   Route::get('/users/{usuario}/access', [RbacController::class, 'userAccess']);
});

Route::middleware(['integration.tokens.admin'])->prefix('integration-tokens')->group(function () {
   Route::get('/', [IntegrationTokenController::class, 'index']);
   Route::post('/', [IntegrationTokenController::class, 'store']);
   Route::put('/{integrationToken}', [IntegrationTokenController::class, 'update']);
   Route::patch('/{integrationToken}', [IntegrationTokenController::class, 'update']);
   Route::get('/{integrationToken}/reveal', [IntegrationTokenController::class, 'reveal']);
   Route::put('/{integrationToken}/activate', [IntegrationTokenController::class, 'activate']);
   Route::put('/{integrationToken}/deactivate', [IntegrationTokenController::class, 'deactivate']);
   Route::delete('/{integrationToken}', [IntegrationTokenController::class, 'destroy']);
});
