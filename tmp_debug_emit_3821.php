<?php

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$cart = DB::table('facturacion_carts')->where('id', 3821)->first([
    'id',
    'codigo_orden',
    'estado',
    'estado_pago',
    'estado_emision',
    'qr_transaction_id',
    'metodo_pago',
    'canal_emision',
    'mensaje_emision',
]);

echo "CART BEFORE\n";
echo json_encode($cart, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

try {
    $request = Request::create('/api/factura-venta/cart/emitir', 'POST', [
        'origen_usuario_id' => '60',
        'cart_id' => 3821,
        'canal_emision' => 'factura_electronica',
        'codigo_orden_mode' => 'new',
        'reuse_cart_billing_data' => true,
        'preserve_paid_qr_payment' => true,
    ]);
    $request->headers->set('Accept', 'application/json');

    $response = app(App\Http\Controllers\FacturacionCartIntegrationController::class)->emitir($request);

    echo "HTTP STATUS\n";
    echo $response->getStatusCode() . "\n\n";

    echo "BODY\n";
    echo $response->getContent() . "\n\n";
} catch (Throwable $e) {
    echo "EXCEPTION\n";
    echo get_class($e) . "\n";
    echo $e->getMessage() . "\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n\n";
}

$after = DB::table('facturacion_carts')->where('id', 3821)->first([
    'id',
    'codigo_orden',
    'estado',
    'estado_pago',
    'estado_emision',
    'qr_transaction_id',
    'metodo_pago',
    'canal_emision',
    'mensaje_emision',
    'updated_at',
]);

echo "CART AFTER\n";
echo json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
