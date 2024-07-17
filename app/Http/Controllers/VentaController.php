<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB; // Importar DB facade
class VentaController extends Controller
{
   /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
   public function index()
   {
      $ventas = Venta::where('estado', 1)->get();
      $list = [];
      foreach ($ventas as $venta) {
         $list[] = $this->show($venta);
      }
      return response()->json($list);
   }

   /**
    * Store a newly created resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @return \Illuminate\Http\Response
    */
   // Controlador de Laravel
   private function numeroATexto($numero)
   {

      $unidad = [
         '', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve', 'diez',
         'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve', 'veinte',
         'veintiuno', 'veintidós', 'veintitrés', 'veinticuatro', 'veinticinco', 'veintiséis', 'veintisiete', 'veintiocho', 'veintinueve'
      ];
      $decena = [
         '', 'diez', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'
      ];
      $centena = [
         '', 'cien', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'
      ];

      $mil = 'mil';
      $millones = 'millón';

      if ($numero == 0) {
         return 'cero';
      }

      if ($numero < 30) {
         return $unidad[$numero];
      }

      if ($numero < 100) {
         $decenas = (int)($numero / 10);
         $unidades = $numero % 10;
         if ($unidades) {
            return $decena[$decenas] . ' y ' . $unidad[$unidades];
         } else {
            return $decena[$decenas];
         }
      }

      if ($numero < 1000) {
         $centenas = (int)($numero / 100);
         $resto = $numero % 100;
         if ($resto) {
            if ($centenas == 1 && $resto < 30) {
               return 'ciento ' . $unidad[$resto];
            } else {
               return $centena[$centenas] . ' ' . $this->numeroATexto($resto);
            }
         } else {
            return $centena[$centenas];
         }
      }

      if ($numero < 1000000) {
         $miles = (int)($numero / 1000);
         $resto = $numero % 1000;
         if ($miles == 1) {
            if ($resto) {
               return $mil . ' ' . $this->numeroATexto($resto);
            } else {
               return $mil;
            }
         } else {
            if ($resto) {
               return $this->numeroATexto($miles) . ' ' . $mil . ' ' . $this->numeroATexto($resto);
            } else {
               return $this->numeroATexto($miles) . ' ' . $mil;
            }
         }
      }

      if ($numero < 1000000000) {
         $millonesCantidad = (int)($numero / 1000000);
         $resto = $numero % 1000000;
         if ($millonesCantidad == 1) {
            if ($resto) {
               return $millones . ' ' . $this->numeroATexto($resto);
            } else {
               return $millones;
            }
         } else {
            if ($resto) {
               return $this->numeroATexto($millonesCantidad) . ' ' . $millones . 'es ' . $this->numeroATexto($resto);
            } else {
               return $this->numeroATexto($millonesCantidad) . ' ' . $millones . 'es';
            }
         }
      }

      return 'Número fuera de rango';
   }

   public function store(Request $request)
   {
      // Crear nueva venta
      $venta = new Venta();
      // Asignar datos de la venta desde $request
      $venta->cliente_id = $request->cliente_id;
      $venta->cajero_id = $request->cajero_id;
      $venta->codigoSucursal = $request->codigoSucursal;
      $venta->puntoVenta = $request->puntoVenta;
      $venta->documentoSector = $request->documentoSector;
      $venta->municipio = $request->municipio;
      $venta->departamento = $request->departamento;
      $venta->telefono = $request->telefono;
      $venta->metodoPago = $request->metodoPago;
      $venta->formatoFactura  = $request->formatoFactura;
      $venta->monto_descuento_adicional = $request->monto_descuento_adicional;
      $venta->motivo = $request->motivo;
      $venta->total = $request->total;
      $venta->save();

      // Guardar detalles de la venta
      $detalleVentaList = [];
      foreach ($request->carrito as $item) {
         $detalleVenta = new DetalleVenta();
         $detalleVenta->venta_id = $venta->id;
         $detalleVenta->servicio_id = $item['servicio_id'];
         $detalleVenta->cantidad = $item['cantidad'];
         $detalleVenta->precio = $item['precio'];
         $detalleVenta->save();

         // Convertir precioUnitario a número
         $precioUnitario = floatval($item['precio']);

         // Preparar los detalles de venta para emitir factura
         $detalleVentaList[] = [
            'actividadEconomica' => $item['actividadEconomica'],
            'codigoSin' => $item['codigoSin'],
            'codigo' => $item['codigo'],
            'descripcion' => $item['descripcion'],
            'precioUnitario' => $precioUnitario,  // Corregir precioUnitario a número
            'cantidad' => $item['cantidad'],
            'unidadMedida' => $item['unidadMedida']
         ];
      }

      // Preparar datos para emitir factura
      $facturaData = [
         'codigoOrden' => (string) $venta->id, // Convertir a cadena
         'codigoSucursal' => $request->codigoSucursal,
         'puntoVenta' => $request->puntoVenta,
         'documentoSector' => $request->documentoSector,
         'municipio' => $request->municipio,
         'departamento' => $request->departamento,
         'telefono' => $request->telefono,
         'razonSocial' => $venta->cliente->razonSocial,
         'documentoIdentidad' => $venta->cliente->documentoIdentidad,
         'tipoDocumentoIdentidad' => $venta->cliente->tipoDocumentoIdentidad,
         'complemento' => $venta->cliente->complemento,
         'correo' => $venta->cliente->correo,
         'codigoCliente' => $venta->cliente->codigoCliente,
         'metodoPago' => $request->metodoPago,
         'montoTotal' => $request->total,
         'formatoFactura' => $request->formatoFactura,
         'detalle' => $detalleVentaList
      ];

      // Registrar datos enviados en el log
      Log::info('Datos enviados para emitir factura:', $facturaData);

      // Llamar a la función para emitir la factura
      $this->emitirFactura($facturaData);

      return response()->json(['message' => 'Venta guardada y factura emitida correctamente']);
   }





   /**
    * Display the specified resource.
    *
    * @param  \App\Models\Venta  $venta
    * @return \Illuminate\Http\Response
    */

   public function show(Venta $venta)
   {

      $venta->cajero;
      $venta->cliente;
      $venta->detalleVentas->load('servicio'); // Cargar los datos del servicio para cada detalle de venta
      $venta->fecha = $venta->created_at->format('Y-m-d');
      return $venta;
   }



   /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \App\Models\Venta  $venta
    * @return \Illuminate\Http\Response
    */
   public function update(Request $request, Venta $venta)
   {
      $venta->total = $request->total;
      $venta->pago = $request->pago;
      $venta->cambio = $request->cambio;
      $venta->tipo = $request->tipo;
      $venta->cliente_id = $request->cliente_id;
      $venta->motivo = $request->motivo;
      $venta->estado = $request->estado;
      $venta->save();

      return response()->json($venta);
   }

   /**
    * Remove the specified resource from storage.
    *
    * @param  \App\Models\Venta  $venta
    * @return \Illuminate\Http\Response
    */
   public function destroy(Venta $venta)
   {
      $venta->estado = 0;
      $venta->save();
      return response()->json(['message' => 'Venta eliminada correctamente']);
   }

   public function emitirFactura($data)
   {
      $url = "https://sefe.demo.agetic.gob.bo/facturacion/emision/individual";
      $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI3UzN2TFE3bkRuODNoeVlXVDZfcWoiLCJleHAiOjE3NDA4MDE1OTksIm5pdCI6IjM1NTcwMTAyNyIsImlzcyI6InlpampSdXRhU01DRUs5ZGRtYXFEbWNwSUpKcUxranhzIn0.gLLEwjLMHDmYaYtBKMHgQIRdwVVDSdeoikQrwPQNNuA';

      // Construir el arreglo de datos para la solicitud
      $requestData = [
         'codigoOrden' => $data['codigoOrden'],
         'codigoSucursal' => $data['codigoSucursal'],
         'puntoVenta' => $data['puntoVenta'],
         'documentoSector' => $data['documentoSector'],
         'municipio' => $data['municipio'],
         'departamento' => $data['departamento'],
         'telefono' => $data['telefono'],
         'razonSocial' => $data['razonSocial'],
         'documentoIdentidad' => $data['documentoIdentidad'],
         'tipoDocumentoIdentidad' => $data['tipoDocumentoIdentidad'],
         'correo' => $data['correo'],
         'codigoCliente' => $data['codigoCliente'],
         'metodoPago' => $data['metodoPago'],
         'montoTotal' => $data['montoTotal'],
         'formatoFactura' => $data['formatoFactura'],
         'detalle' => $data['detalle']
      ];

      // Solo agregar el campo 'complemento' si tiene un valor
      if (!empty($data['complemento'])) {
         $requestData['complemento'] = $data['complemento'];
      }

      // Registrar los datos enviados en el log
      Log::info('Datos enviados para emitir factura:', $requestData);

      // Enviar la solicitud POST
      $response = Http::withHeaders([
         'Authorization' => 'Bearer ' . $token,
         'Content-Type' => 'application/json'
      ])->post($url, $requestData);

      // Registrar la respuesta en el log
      Log::info('Respuesta de la API:', $response->json());
      $responseData = $response->json();

      if ($responseData['finalizado']) {
         $codigoSeguimiento = $responseData['datos']['codigoSeguimiento'];

         // Registrar el código de seguimiento
         Log::info('Código de seguimiento recibido:', ['codigoSeguimiento' => $codigoSeguimiento]);

         try {
            // Buscar el registro en la base de datos
            $notificacion = DB::table('notificaciones')->where('codigo_seguimiento', $codigoSeguimiento)->first();

            if ($notificacion) {
               // Decodificar el campo 'detalle' que está en formato JSON
               $detalle = json_decode($notificacion->detalle, true);

               // Registrar el detalle
               Log::info('Detalle de la notificación:', $detalle);

               // Abrir el URL del PDF
               return redirect($detalle['urlPdf']);
            } else {
               Log::error('No se encontró ninguna notificación con el código de seguimiento proporcionado.');
               return response()->json(['error' => 'No se encontró ninguna notificación con el código de seguimiento proporcionado.'], 404);
            }
         } catch (\Exception $e) {
            Log::error('Error en la consulta a la base de datos: ' . $e->getMessage());
            return response()->json(['error' => 'Error en la consulta a la base de datos'], 500);
         }
      }

      return $response;
   }
}
