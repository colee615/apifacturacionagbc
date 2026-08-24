<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$row = App\Models\Venta::query()
    ->whereNotNull('url_pdf')
    ->whereRaw("trim(coalesce(url_pdf, '')) <> ''")
    ->latest('id')
    ->first(['id','codigoOrden','url_pdf']);
echo json_encode($row ? $row->toArray() : null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
