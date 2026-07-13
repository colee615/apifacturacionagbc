<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\VentaController;
use Illuminate\Http\Request;

function ms($start) { return round((microtime(true)-$start)*1000); }

$controller = app(VentaController::class);
$request = Request::create('/admin/ventas', 'GET', ['codigoSucursal' => 0, 'puntoVenta' => 0]);
$start = microtime(true);
$response = $controller->index($request);
$data = $response->getData(true);
echo 'count=' . count($data) . ' total_ms=' . ms($start) . PHP_EOL;
