<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use App\Models\Notificacione;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB; // Asegúrate de importar esta línea

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


   public function store(Request $request)
   {
      // Iniciar una transacción para asegurar atomicidad
      DB::beginTransaction();

      try {
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
         $venta->formatoFactura = $request->formatoFactura;
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
            $detalleVenta->codigosEspeciales = $item['codigoEspecial']; // Guardar código especial
            $detalleVenta->informacionesAdicionales = $item['informacionAdicional']; // Guardar información adicional
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
            'codigoOrden' => $venta->codigoOrden, // Obtener el código de la venta recién creada
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

         // Emitir factura
         $response = $this->emitirFactura($facturaData);

         // Verificar respuesta de la emisión de factura
         if ($response['finalizado'] === false) {
            // Si la emisión de la factura no se completó, lanzar una excepción
            throw new \Exception('Error al emitir factura: ' . $response['mensaje']);
         }

         // Actualizar la venta con el código de seguimiento
         $venta->codigoSeguimiento = $response['datos']['codigoSeguimiento'];

         $venta->save();

         // Confirmar la transacción
         DB::commit();

         return response()->json([
            'message' => 'Venta guardada y factura emitida correctamente',
            'codigoSeguimiento' => $response['datos']['codigoSeguimiento']
         ]);
      } catch (\Exception $e) {
         // Revertir la transacción si ocurre cualquier error
         DB::rollBack();

         // Registrar el error en el log
         Log::error('Error al guardar venta o emitir factura:', ['message' => $e->getMessage()]);

         return response()->json([
            'message' => 'Error al guardar venta o emitir factura',
            'details' => $e->getMessage()
         ], 400);
      }
   }

   public function venta2(Request $request)
   {
      // Iniciar una transacción para asegurar atomicidad
      DB::beginTransaction();

      try {
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
            $detalleVenta->codigosEspeciales = $item['codigoEspecial']; // Guardar código especial
            $detalleVenta->informacionesAdicionales = $item['informacionAdicional']; // Guardar información adicional
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
            'codigoOrden' => $venta->codigoOrden, // Obtener el código de la venta recién creada
            'correo' => $request->input('correo', 'correo-generico@example.com'), // Usar 'input' para obtener el valor del request
            'telefono' => $request->telefono,
            'municipio' => $request->municipio,
            'metodoPago' => $request->metodoPago,
            'montoTotal' => $request->total,
            'puntoVenta' => $request->puntoVenta,
            'codigoSucursal' => $request->codigoSucursal,
            'departamento' => $request->departamento,
            'formatoFactura' => $request->formatoFactura,
            'documentoSector' => $request->documentoSector,
            'detalle' => $detalleVentaList
         ];

         // Registrar datos enviados en el log
         Log::info('Datos enviados para emitir factura:', $facturaData);
         $response = $this->emitirFactura2($facturaData);
         if ($response['finalizado'] === false) {
            // Si la emisión de la factura no se completó, eliminar la venta y retornar el error
            $venta->delete();
            return response()->json([
               'message' => 'Error al emitir factura',
               'details' => $response['mensaje'],
               'errores' => $response['datos']['errores']
            ], 400);
         }
         $venta->codigoSeguimiento = $response['datos']['codigoSeguimiento'];
         $venta->save();
         // Confirmar la transacción
         DB::commit();
         return response()->json([
            'message' => 'Venta guardada y factura emitida correctamente',
            'codigoSeguimiento' => $response['datos']['codigoSeguimiento']
         ]);
      } catch (\Exception $e) {
         // Revertir la transacción si ocurre cualquier error
         DB::rollBack();

         // Registrar el error en el log
         Log::error('Error al guardar venta o emitir factura:', ['message' => $e->getMessage()]);

         return response()->json([
            'message' => 'Error al guardar venta o emitir factura',
            'details' => $e->getMessage()
         ], 400);
      }
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

      // Solo agregar el campo 'anchoFactura' si el formato es 'rollo'
      if ($data['formatoFactura'] === 'rollo') {
         $requestData['anchoFactura'] = 90;
      }

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

      return $response;
   }
   public function emitirFactura2($data)
   {
      $url = "https://sefe.demo.agetic.gob.bo/facturacion/emision/individual";
      $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI3UzN2TFE3bkRuODNoeVlXVDZfcWoiLCJleHAiOjE3NDA4MDE1OTksIm5pdCI6IjM1NTcwMTAyNyIsImlzcyI6InlpampSdXRhU01DRUs5ZGRtYXFEbWNwSUpKcUxranhzIn0.gLLEwjLMHDmYaYtBKMHgQIRdwVVDSdeoikQrwPQNNuA';

      $requestData = [
         'codigoOrden' => $data['codigoOrden'],
         'correo' => $data['correo'],
         'telefono' => $data['telefono'],
         'municipio' => $data['municipio'],
         'metodoPago' => $data['metodoPago'],
         'montoTotal' => $data['montoTotal'],
         'puntoVenta' => $data['puntoVenta'],
         'codigoSucursal' => $data['codigoSucursal'],
         'departamento' => $data['departamento'],
         'formatoFactura' => $data['formatoFactura'],
         'documentoSector' => $data['documentoSector'],
         'detalle' => $data['detalle']
      ];

      // Solo agregar el campo 'anchoFactura' si el formato es 'rollo'
      if ($data['formatoFactura'] === 'rollo') {
         $requestData['anchoFactura'] = 75;
      }

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

      return $response;
   }
   public function getPdfUrl($codigoSeguimiento)
   {
      $notificacion = Notificacione::where('codigo_seguimiento', $codigoSeguimiento)->first();

      if ($notificacion) {
         $detalle = json_decode($notificacion->detalle, true);
         $urlPdf = $detalle['urlPdf'] ?? null;
         $cuf = $detalle['cuf'] ?? null;

         if ($urlPdf) {
            return response()->json([
               'pdf_url' => $urlPdf,
               'cuf' => $cuf
            ], 200, [
               'Content-Disposition' => 'inline; filename="factura.pdf"'
            ]);
         } elseif ($cuf) {
            return response()->json([
               'cuf' => $cuf,
               'message' => 'PDF URL not yet available'
            ], 200);
         } else {
            return response()->json(['error' => 'No PDF URL and no CUF found'], 404);
         }
      } else {
         return response()->json(['error' => 'Notification not found'], 404);
      }
   }



   public function consultarVenta($codigoSeguimiento)
   {
      $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI3UzN2TFE3bkRuODNoeVlXVDZfcWoiLCJleHAiOjE3NDA4MDE1OTksIm5pdCI6IjM1NTcwMTAyNyIsImlzcyI6InlpampSdXRhU01DRUs5ZGRtYXFEbWNwSUpKcUxranhzIn0.gLLEwjLMHDmYaYtBKMHgQIRdwVVDSdeoikQrwPQNNuA';
      $url = "https://sefe.demo.agetic.gob.bo/consulta/{$codigoSeguimiento}";

      Log::info("Código de Seguimiento: {$codigoSeguimiento}");
      Log::info("URL de Consulta: {$url}");

      try {
         $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
         ])->get($url);

         if ($response->successful()) {
            Log::info('Respuesta de la API:', $response->json());
            return response()->json($response->json(), 200);
         } else {
            Log::error("Error al consultar venta: " . $response->body());
            return response()->json([
               'error' => 'Error al consultar la venta',
               'details' => $response->body()
            ], $response->status());
         }
      } catch (\Exception $e) {
         Log::error("Excepción al consultar venta: " . $e->getMessage());
         return response()->json([
            'error' => 'Error al consultar la venta',
            'exception' => $e->getMessage()
         ], 500);
      }
   }
   public function anularFactura(Request $request, $cuf)
   {
      $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiI3UzN2TFE3bkRuODNoeVlXVDZfcWoiLCJleHAiOjE3NDA4MDE1OTksIm5pdCI6IjM1NTcwMTAyNyIsImlzcyI6InlpampSdXRhU01DRUs5ZGRtYXFEbWNwSUpKcUxranhzIn0.gLLEwjLMHDmYaYtBKMHgQIRdwVVDSdeoikQrwPQNNuA';
      $url = "https://sefe.demo.agetic.gob.bo/anulacion/{$cuf}";

      // Preparar datos para la solicitud de anulación
      $requestData = [
         'motivo' => $request->motivo,
         'tipoAnulacion' => $request->tipoAnulacion
      ];
      // Registrar los datos enviados en el log
      Log::info('Datos enviados para anulación de factura:', $requestData);

      try {
         // Enviar la solicitud PATCH
         $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json'
         ])->patch($url, $requestData);

         // Registrar la respuesta en el log
         Log::info('Respuesta de la API de anulación:', $response->json());

         if ($response->successful()) {
            return response()->json([
               'message' => 'Factura anulada correctamente',
               'response' => $response->json()
            ], 200);
         } else {
            return response()->json([
               'error' => 'Error al anular la factura',
               'details' => $response->json()
            ], $response->status());
         }
      } catch (\Exception $e) {
         Log::error('Excepción al anular factura: ' . $e->getMessage());
         return response()->json([
            'error' => 'Error al anular la factura',
            'exception' => $e->getMessage()
         ], 500);
      }
   }
   public function ventasDelDia($cajeroId)
   {
      // Obtener todas las ventas del día
      $ventas = Venta::where('cajero_id', $cajeroId)
         ->whereDate('created_at', Carbon::today())
         ->with(['detalleVentas', 'cliente', 'cajero'])
         ->orderBy('id') // Ordenar por id
         ->get();

      // Calcular el total excluyendo las ventas con estado 0
      $total = $ventas->where('estado', '!=', 0)->sum('total');

      $servicios = DetalleVenta::select('servicio_id', DB::raw('count(*) as total'))
         ->whereHas('venta', function ($query) use ($cajeroId) {
            $query->where('cajero_id', $cajeroId)
               ->whereDate('created_at', Carbon::today());
         })
         ->groupBy('servicio_id')
         ->orderBy('servicio_id') // Ordenar por id del servicio
         ->get();

      return response()->json([
         'ventas' => $ventas,
         'total' => $total,
         'servicios' => $servicios
      ]);
   }

   public function ventasPorRangoFechas($cajeroId, Request $request)
   {
      $fechaInicio = $request->input('fecha_inicio');
      $fechaFin = $request->input('fecha_fin');

      // Obtener todas las ventas en el rango de fechas
      $ventas = Venta::where('cajero_id', $cajeroId)
         ->whereBetween('created_at', [$fechaInicio, $fechaFin])
         ->with(['detalleVentas', 'cliente', 'cajero'])
         ->orderBy('id') // Ordenar por id
         ->get();

      // Calcular el total excluyendo las ventas con estado 0
      $total = $ventas->where('estado', '!=', 0)->sum('total');

      $servicios = DetalleVenta::select('servicio_id', DB::raw('count(*) as total'))
         ->whereHas('venta', function ($query) use ($cajeroId, $fechaInicio, $fechaFin) {
            $query->where('cajero_id', $cajeroId)
               ->whereBetween('created_at', [$fechaInicio, $fechaFin]);
         })
         ->groupBy('servicio_id')
         ->orderBy('servicio_id') // Ordenar por id del servicio
         ->get();

      return response()->json([
         'ventas' => $ventas,
         'total' => $total,
         'servicios' => $servicios
      ]);
   }

   public function ventasPorSucursalDia($codigoSucursal, Request $request)
   {
      $fecha = $request->input('fecha', Carbon::today()->toDateString());

      // Obtener las ventas por sucursal en el día específico
      $ventas = Venta::where('codigoSucursal', $codigoSucursal)
         ->whereDate('created_at', $fecha)
         ->with(['detalleVentas.servicio', 'cliente', 'cajero'])
         ->get();

      if ($ventas->isEmpty()) {
         return response()->json([
            'message' => 'No se encontraron ventas para la sucursal en el día especificado.'
         ], 404);
      }

      // Agrupar las ventas por cajero y calcular el total por cajero y el total general
      $ventasPorCajero = $ventas->groupBy('cajero_id')->map(function ($ventasCajero) {
         return [
            'total' => $ventasCajero->where('estado', '!=', 0)->sum('total'),
            'ventas' => $ventasCajero->map(function ($venta) {
               return $venta->load(['detalleVentas.servicio', 'cliente', 'cajero']);
            })
         ];
      });

      $totalGeneral = $ventas->where('estado', '!=', 0)->sum('total');

      return response()->json([
         'ventas_por_cajero' => $ventasPorCajero,
         'total_general' => $totalGeneral
      ]);
   }

   /**
    * Obtener las ventas por sucursal y cajero en un rango de fechas.
    *
    * @param  string  $codigoSucursal
    * @param  Request  $request
    * @return \Illuminate\Http\Response
    */
   public function ventasPorSucursalRangoFechas($codigoSucursal, Request $request)
   {
      $fechaInicio = $request->input('fecha_inicio', Carbon::today()->toDateString());
      $fechaFin = $request->input('fecha_fin', Carbon::today()->toDateString());

      // Validar que la fecha de inicio sea anterior o igual a la fecha de fin
      if (Carbon::parse($fechaInicio)->gt(Carbon::parse($fechaFin))) {
         return response()->json([
            'message' => 'La fecha de inicio no puede ser posterior a la fecha de fin.'
         ], 422);
      }

      // Obtener las ventas por sucursal y por cajero en el rango de fechas
      $ventas = Venta::where('codigoSucursal', $codigoSucursal)
         ->whereBetween('created_at', [$fechaInicio, $fechaFin])
         ->with(['detalleVentas.servicio', 'cliente', 'cajero'])
         ->get();

      if ($ventas->isEmpty()) {
         return response()->json([
            'message' => 'No se encontraron ventas para la sucursal en el rango de fechas especificado.'
         ], 404);
      }

      // Agrupar las ventas por cajero y calcular el total por cajero y el total general
      $ventasPorCajero = $ventas->groupBy('cajero_id')->map(function ($ventasCajero) {
         return [
            'total' => $ventasCajero->where('estado', '!=', 0)->sum('total'),
            'ventas' => $ventasCajero->map(function ($venta) {
               return $venta->load(['detalleVentas.servicio', 'cliente', 'cajero']);
            })
         ];
      });

      $totalGeneral = $ventas->where('estado', '!=', 0)->sum('total');

      return response()->json([
         'ventas_por_cajero' => $ventasPorCajero,
         'total_general' => $totalGeneral
      ]);
   }
}
