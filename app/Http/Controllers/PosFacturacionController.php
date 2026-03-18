<?php

namespace App\Http\Controllers;



use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class PosFacturacionController extends Controller
{
   public function emitirFactura(Request $request)
   {

      $ventaId = $request->venta_id; // Asegúrate de ajustar esto según cómo se maneje en tu front-end

      $url = rtrim(config('services.agetic.base_url', 'https://sefe.demo.agetic.gob.bo'), '/') . '/facturacion/emision/individual';
      $token = config('services.agetic.token');

      $response = Http::withHeaders([
         'Authorization' => 'Bearer ' . $token,
         'Content-Type' => 'application/json'
      ])->post($url, [
         'codigoOrden' => $ventaId,  //Código de orden de la solicitud,necesario para identificar deforma única la emisión de factura.
         'codigoSucursal' => $request->codigoSucursal, // codigo sucursal del usuario logueado
         'puntoVenta' => $request->puntoVenta,         // codigo sucursal del usuario logueado
         'documentoSector' => $request->documentoSector, // siempre debe ser 1 por ahora
         'municipio' => $request->municipio,            // municipio de la sucursal del usuario logueado
         'departamento' => $request->departamento,      // departamento de la sucursal del usuario logueado
         'telefono' => $request->telefono,              // telefono de la sucursal del usuario logueado
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

      return $response->json();
   }
}

