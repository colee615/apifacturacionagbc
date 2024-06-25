<?php

namespace App\Http\Controllers;



use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PosFacturacionController extends Controller
{
   public function emitirFactura(Request $request)
   {
      $url = "https://url-base-servicio-sufe/facturacion/emision/individual";
      $token = 'tu_token_bearer_aquí';

      $response = Http::withHeaders([
         'Authorization' => 'Bearer ' . $token,
         'Content-Type' => 'application/json'
      ])->post($url, [
         'codigoOrden' => $request->codigoOrden,
         'codigoSucursal' => $request->codigoSucursal,
         'puntoVenta' => $request->puntoVenta,
         'documentoSector' => $request->documentoSector,
         'municipio' => $request->municipio,
         'departamento' => $request->departamento,
         'telefono' => $request->telefono,
         'razonSocial' => $request->razonSocial,
         'documentoIdentidad' => $request->documentoIdentidad,
         'complemento' => $request->complemento,
         'tipoDocumentoIdentidad' => $request->tipoDocumentoIdentidad,
         'correo' => $request->correo,
         'codigoCliente' => $request->codigoCliente,
         'metodoPago' => $request->metodoPago,
         'numeroTarjeta' => $request->numeroTarjeta,
         'codigoMoneda' => $request->codigoMoneda,
         'tipoCambio' => $request->tipoCambio,
         'montoTotal' => $request->montoTotal,
         'montoGiftcard' => $request->montoGiftcard,
         'montoDescuentoAdicional' => $request->montoDescuentoAdicional,
         'formatoFactura' => $request->formatoFactura,
         'codigoExcepcion' => $request->codigoExcepcion,
         'detalle' => $request->detalle
      ]);

      return $response->json();
   }
}
