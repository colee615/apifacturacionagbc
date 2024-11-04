
<?php

use Illuminate\Support\Facades\Route;

Route::apiResource('perro', 'SucursaleController')->parameters(['perro' => 'sucursale']);
