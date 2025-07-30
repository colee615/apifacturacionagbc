<?php

namespace App\Http\Controllers;



use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PosFacturacionController extends Controller
{
   public function emitirFactura(Request $request)
   {

      $ventaId = $request->venta_id; // Asegúrate de ajustar esto según cómo se maneje en tu front-end

      $url = "https://sefe.demo.agetic.gob.bo/facturacion/emision/individual";
      $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI3UzN2TFE3bkRuODNoeVlXVDZfcWoiLCJleHAiOjE3NDA4MDE1OTksIm5pdCI6IjM1NTcwMTAyNyIsImlzcyI6InlpampSdXRhU01DRUs5ZGRtYXFEbWNwSUpKcUxranhzIn0.gLLEwjLMHDmYaYtBKMHgQIRdwVVDSdeoikQrwPQNNuA';

      $response = Http::withHeaders([
         'Authorization' => 'Bearer ' . $token,
         'Content-Type' => 'application/json'
      ])->post($url, [
         'codigoOrden' => $ventaId,  //Código de orden de la solicitud,necesario para identificar deforma única la emisión de factura.
         'codigoSucursal' => $request->codigoSucursal, // codigo sucursal del cajero logueado
         'puntoVenta' => $request->puntoVenta,         // codigo sucursal del cajero logueado
         'documentoSector' => $request->documentoSector, // siempre debe ser 1 por ahora
         'municipio' => $request->municipio,            // municipio de la sucursal del cajero logueado
         'departamento' => $request->departamento,      // departamento de la sucursal del cajero logueado
         'telefono' => $request->telefono,              // telefono de la sucursal del cajero logueado
         'razonSocial' => $request->razonSocial,        // jalar el nombre del cliente (razon social)
         'documentoIdentidad' => $request->documentoIdentidad, //jalar el documentoIdentidad del cliente
         'tipoDocumentoIdentidad' => $request->tipoDocumentoIdentidad, //jalar el tipodocumentoidentidad del cliente
         'correo' => $request->correo,                               //jalar  el correo del cliente
         'codigoCliente' => $request->codigoCliente,                 //jalar el codigo cliente 
         'metodoPago' => $request->metodoPago,                       // menejar el valor 1
         'montoTotal' => $request->montoTotal,                 //monto total de la venta
         'montoDescuentoAdicional' => $request->montoDescuentoAdicional,  //monto descuento adicional de la venta
         'formatoFactura' => $request->formatoFactura,            //valor rollo
         'detalle' => $request->detalle                           //detalle venta

         // detallle[]. actividadEconomica = actividad economica del servicio      
         // detalle[].codigoSin = CODIGO SIN DEL SERVICIO
         // detalle[].codigo  = ID DEL SERVICIO
         // detalle[].descripcion = Descripcion del servicio
         // detalle[].precioUnitario = El precio del servicio
         // detalle[].cantidad= cantidad 
         // detalle[].unidadMedida = unidad de medida del servicio 

      ]);

      if ($data['formatoFactura'] === 'rollo') {
         $requestData['anchoFactura'] = 90;
      }

      if (!empty($data['complemento'])) {
         $requestData['complemento'] = $data['complemento'];
      }

      Log::info('Datos enviados para emitir factura:', $requestData);

      try {
         $response = Http::withOptions([
            'timeout' => 60, // Tiempo total en segundos
            'connect_timeout' => 10, // Tiempo de conexión
            'verify' => false, // Desactiva SSL solo para DEMO
            'force_ip_resolve' => 'v4' // Evita IPv6 si da problemas
         ])
            ->withHeaders([
               'Authorization' => 'Bearer ' . $token,
               'Content-Type' => 'application/json'
            ])
            ->retry(3, 2000, throw: false) // Reintenta hasta 3 veces si falla
            ->post($url, $requestData);

         if ($response->successful()) {
            Log::info('Respuesta de la API:', $response->json());
            return $response->json();
         } else {
            Log::error('Error HTTP AGETIC:', [
               'status' => $response->status(),
               'body' => $response->body()
            ]);
            return [
               'finalizado' => false,
               'mensaje' => 'Error HTTP al emitir factura',
               'datos' => [
                  'errores' => $response->body()
               ]
            ];
         }
      } catch (\Exception $e) {
         Log::error('Timeout o error de conexión al emitir factura:', ['message' => $e->getMessage()]);
         return [
            'finalizado' => false,
            'mensaje' => 'No hay conexión con AGETIC (timeout o error de red)',
            'datos' => [
               'errores' => $e->getMessage()
            ]
         ];
      }
   }
}
