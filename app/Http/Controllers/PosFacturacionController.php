<?php

namespace App\Http\Controllers;



use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PosFacturacionController extends Controller
{
    public function emitirFactura(Request $request)
    {
        $url   = 'https://sefe.demo.agetic.gob.bo/facturacion/emision/individual';
        $token = 'eyJhbGciOiJIUzI1NiIsInR…NNuA';   // ⚠️  muévelo a .env o config/services.php

        // 1. Opciones Guzzle/cURL
        $guzzleOpts = [
            'verify' => false,                 // desactiva validación del certificado
            'timeout' => 60,                   // aumenta o desactiva con 0 (no recomendado)
            'curl' => [
                CURLOPT_SSLVERSION   => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_IPRESOLVE    => CURL_IPRESOLVE_V4,
            ],
        ];

        // 2. Encabezados y payload
        $payload = [
            'codigoOrden'              => $request->venta_id,
            'codigoSucursal'           => $request->codigoSucursal,
            'puntoVenta'               => $request->puntoVenta,
            'documentoSector'          => $request->documentoSector,
            'municipio'                => $request->municipio,
            'departamento'             => $request->departamento,
            'telefono'                 => $request->telefono,
            'razonSocial'              => $request->razonSocial,
            'documentoIdentidad'       => $request->documentoIdentidad,
            'tipoDocumentoIdentidad'   => $request->tipoDocumentoIdentidad,
            'correo'                   => $request->correo,
            'codigoCliente'            => $request->codigoCliente,
            'metodoPago'               => $request->metodoPago,
            'montoTotal'               => $request->montoTotal,
            'montoDescuentoAdicional'  => $request->montoDescuentoAdicional,
            'formatoFactura'           => $request->formatoFactura,
            'detalle'                  => $request->detalle,
        ];

        // 3. Petición
        $response = Http::withOptions($guzzleOpts)
            ->withToken($token)               // o ->withHeaders(['Authorization' => "Bearer $token"])
            ->acceptJson()
            ->post($url, $payload);

        return $response->json();             // devuelve la respuesta al front-end
    }
}
