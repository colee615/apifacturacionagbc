<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\VentaController;
use App\Models\Venta;
use Illuminate\Http\Request;

function ms($start) { return round((microtime(true)-$start)*1000); }

$controller = app(VentaController::class);
$request = Request::create('/admin/ventas', 'GET');

foreach ([[0,0],[1,0],[2,0]] as [$codigo,$punto]) {
    $ventas = Venta::query()->where('estado', 1)->where('codigoSucursal', $codigo)->where('puntoVenta', $punto)->get();
    $start = microtime(true);
    foreach ($ventas as $venta) {
        $controller->show($request, $venta);
    }
    echo 'branch=' . $codigo . '-' . $punto . ' ventas=' . $ventas->count() . ' show_map_ms=' . ms($start) . PHP_EOL;
}
