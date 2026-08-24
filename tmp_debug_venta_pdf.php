<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$rows = App\Models\Venta::query()
    ->whereIn('codigoOrden', ['VFC-0000003765', 'VFC-0000003762'])
    ->get(['id','codigoOrden','codigoSeguimiento','numero_factura','estado_sufe','cuf','url_pdf','contrato_pdf_path'])
    ->map(function ($row) {
        return [
            'id' => $row->id,
            'codigoOrden' => $row->codigoOrden,
            'codigoSeguimiento' => $row->codigoSeguimiento,
            'numero_factura' => $row->numero_factura,
            'estado_sufe' => $row->estado_sufe,
            'cuf' => $row->cuf,
            'url_pdf' => $row->url_pdf,
            'contrato_pdf_path' => $row->contrato_pdf_path,
        ];
    })
    ->values()
    ->all();
echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
