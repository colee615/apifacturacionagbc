<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RbacController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\VentaController;

Route::post('login', 'UsuarioController@login');
Route::post('request-password-reset', [UsuarioController::class, 'requestPasswordReset']);
Route::post('reset-password/{token}', [UsuarioController::class, 'resetPassword']);

Route::middleware(['jwt.auth'])->group(function () {
   Route::get('me', [UsuarioController::class, 'me']);

   Route::get('/clientes', 'ClienteController@index')->middleware('permission:clientes.read');
   Route::get('/clientes/{cliente}', 'ClienteController@show')->middleware('permission:clientes.read');
   Route::post('/clientes', 'ClienteController@store')->middleware('permission:clientes.write,clientes.create,clientes.manage');
   Route::put('/clientes/{cliente}', 'ClienteController@update')->middleware('permission:clientes.write,clientes.update,clientes.manage');
   Route::patch('/clientes/{cliente}', 'ClienteController@update')->middleware('permission:clientes.write,clientes.update,clientes.manage');
   Route::delete('/clientes/{cliente}', 'ClienteController@destroy')->middleware('permission:clientes.write,clientes.delete,clientes.manage');

   Route::get('/ventas', 'VentaController@index')->middleware('permission:ventas.read');
   Route::get('/ventas/{venta}', 'VentaController@show')->middleware('permission:ventas.read');
   Route::post('/ventas', 'VentaController@store')->middleware('permission:ventas.write');
   Route::put('/ventas/{venta}', 'VentaController@update')->middleware('permission:ventas.write');
   Route::patch('/ventas/{venta}', 'VentaController@update')->middleware('permission:ventas.write');
   Route::delete('/ventas/{venta}', 'VentaController@destroy')->middleware('permission:ventas.write');

   Route::get('/ventas/dia/{usuarioId}', [VentaController::class, 'ventasDelDia'])->middleware('permission:ventas.read');
   Route::get('/ventas/mes/{usuarioId}', [VentaController::class, 'ventasDelMes'])->middleware('permission:ventas.read');
   Route::post('/ventas/fecha/{usuarioId}', [VentaController::class, 'ventasPorFecha'])->middleware('permission:ventas.read');
   Route::get('venta/pdf/{codigoSeguimiento}', 'VentaController@getPdfUrl')->middleware('permission:ventas.read');
   Route::get('ventas/consultar/{codigoSeguimiento}', 'VentaController@consultarVenta')->middleware('permission:ventas.read');
   Route::patch('ventas/anular/{cuf}', 'VentaController@anularFactura')->middleware('permission:ventas.void');
   Route::post('venta2', 'VentaController@venta2')->middleware('permission:ventas.write');
});

Route::middleware(['jwt.auth', 'permission:empresa.manage'])->group(function () {
   Route::apiResource('/empresa', 'EmpresaController');
});

Route::middleware(['jwt.auth'])->group(function () {
   Route::get('/sucursales', 'SucursaleController@index')->middleware('permission:sucursales.manage');
   Route::get('/sucursales/{sucursale}', 'SucursaleController@show')->middleware('permission:sucursales.manage');
   Route::post('/sucursales', 'SucursaleController@store')->middleware('permission:sucursales.manage,sucursales.create');
   Route::put('/sucursales/{sucursale}', 'SucursaleController@update')->middleware('permission:sucursales.manage,sucursales.update');
   Route::patch('/sucursales/{sucursale}', 'SucursaleController@update')->middleware('permission:sucursales.manage,sucursales.update');
   Route::delete('/sucursales/{sucursale}', 'SucursaleController@destroy')->middleware('permission:sucursales.manage,sucursales.delete');

   Route::get('/usuarios', 'UsuarioController@index')->middleware('permission:usuarios.manage');
   Route::get('/usuarios/{usuario}', 'UsuarioController@show')->whereNumber('usuario')->middleware('permission:usuarios.manage');
   Route::post('/usuarios', 'UsuarioController@store')->middleware('permission:usuarios.manage,usuarios.create');
   Route::put('/usuarios/{usuario}', 'UsuarioController@update')->whereNumber('usuario')->middleware('permission:usuarios.manage,usuarios.update');
   Route::patch('/usuarios/{usuario}', 'UsuarioController@update')->whereNumber('usuario')->middleware('permission:usuarios.manage,usuarios.update');
   Route::delete('/usuarios/{usuario}', 'UsuarioController@destroy')->whereNumber('usuario')->middleware('permission:usuarios.manage,usuarios.delete');
   Route::put('/usuarios/activar/{id}', 'UsuarioController@activar')->middleware('permission:usuarios.manage,usuarios.update');

   Route::get('/servicios', 'ServicioController@index')->middleware('permission:servicios.manage');
   Route::get('/servicios/{servicio}', 'ServicioController@show')->middleware('permission:servicios.manage');
   Route::post('/servicios', 'ServicioController@store')->middleware('permission:servicios.manage,servicios.create');
   Route::put('/servicios/{servicio}', 'ServicioController@update')->middleware('permission:servicios.manage,servicios.update');
   Route::patch('/servicios/{servicio}', 'ServicioController@update')->middleware('permission:servicios.manage,servicios.update');
   Route::delete('/servicios/{servicio}', 'ServicioController@destroy')->middleware('permission:servicios.manage,servicios.delete');
});

Route::middleware(['jwt.auth', 'permission:dashboard.view'])->group(function () {
   Route::apiResource('/notificaciones', 'NotificacioneController');
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
