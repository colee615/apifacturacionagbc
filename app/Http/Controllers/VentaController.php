<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use App\Models\Cliente;
use Illuminate\Http\Request;
use App\Models\Reporte;
use Dompdf\Dompdf;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\Printer;
use Illuminate\Support\Facades\Log;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

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
      Log::info('Datos recibidos:', $request->all());

      // Crear nueva venta
      $venta = new Venta();
      $venta->cliente_id = $request->cliente_id;
      $venta->cajero_id = $request->cajero_id;
      $venta->motivo = $request->motivo;
      $venta->total = $request->total;
      $venta->pago = $request->pago;
      $venta->cambio = $request->cambio;
      $venta->tipo = $request->tipo;
      $venta->monto_descuento_adicional = $request->monto_descuento_adicional; // Guardar el descuento adicional
      $venta->save();

      // Guardar detalles de la venta
      foreach ($request->carrito as $item) {
         $detalleVenta = new DetalleVenta();
         $detalleVenta->venta_id = $venta->id;
         $detalleVenta->servicio_id = $item['servicio_id'];
         $detalleVenta->cantidad = $item['cantidad'];
         $detalleVenta->precio = $item['precio'];
         $detalleVenta->save();
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
}
