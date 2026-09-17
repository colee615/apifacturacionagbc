<?php

namespace App\Http\Controllers;

use App\Models\Notificacione;
use App\Models\Venta;
use App\Support\SufeSectorUnoValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class NotificacioneController extends Controller
{
   public function __construct(
      private readonly SufeSectorUnoValidator $sufeValidator
   ) {
   }

   /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
   public function index()
   {
      return Notificacione::all();
   }

   /**
    * Store a newly created resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @return \Illuminate\Http\Response
    */
   public function store(Request $request)
   {
      //
   }

   /**
    * Display the specified resource.
    *
    * @param  \App\Models\Notificacione  $notificacione
    * @return \Illuminate\Http\Response
    */
   public function show(Notificacione $notificacione)
   {
      return $notificacione;
   }

   /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \App\Models\Notificacione  $notificacione
    * @return \Illuminate\Http\Response
    */
   public function update(Request $request, Notificacione $notificacione)
   {
      //
   }

   /**
    * Remove the specified resource from storage.
    *
    * @param  \App\Models\Notificacione  $notificacione
    * @return \Illuminate\Http\Response
    */
   public function destroy(Notificacione $notificacione)
   {
      //
   }

   private function resolveVentaEstadoSufe(array $validated): string
   {
      $estado = (string) ($validated['estado'] ?? '');
      $tipoEmision = (string) data_get($validated, 'detalle.tipoEmision', '');

      if ($tipoEmision === 'ANULACION' && $estado === 'EXITO') {
         return 'ANULADA';
      }

      if ($tipoEmision === 'ANULACION' && $estado === 'OBSERVADO') {
         return 'ANULACION_OBSERVADA';
      }

      if ($estado === 'EXITO') {
         return 'PROCESADA';
      }

      if ($estado === 'OBSERVADO') {
         return 'OBSERVADA';
      }

      if ($estado === 'CREADO' && $tipoEmision === 'CONTINGENCIA') {
         return 'CONTINGENCIA_CREADA';
      }

      if ($estado === 'CREADO') {
         return 'CREADA';
      }

      return 'RECEPCIONADA';
   }

   private function reverseVentaFromCajaIfNeeded(object $venta, ?string $estadoSufe = null): void
   {
      $status = strtoupper(trim((string) ($estadoSufe ?? $venta->estado_sufe ?? '')));
      if ($status !== 'ANULADA' || !DB::getSchemaBuilder()->hasTable('cajas_diarias')) {
         return;
      }

      if (Schema::hasColumn('ventas', 'estado_caja') && strtoupper(trim((string) ($venta->estado_caja ?? ''))) === 'ANULADA_DESCONTADA') {
         return;
      }

      $usuarioId = trim((string) ($venta->origen_usuario_id ?? ''));
      $metodoPago = (int) ($venta->metodoPago ?? 1);
      $origenVentaTipo = strtoupper(trim((string) ($venta->origen_venta_tipo ?? '')));
      $isOfficial = $origenVentaTipo === 'OFICIAL' || strtoupper(trim((string) ($venta->estado_sufe ?? ''))) === 'REGISTRADA_OFICIAL';
      $isQr = $metodoPago === 5;
      if ($usuarioId === '' || $isOfficial || $isQr) {
         return;
      }

      $fecha = $venta->created_at ? date('Y-m-d', strtotime((string) $venta->created_at)) : now()->toDateString();
      $codigoSucursal = (int) ($venta->codigoSucursal ?? 0);
      $puntoVenta = (int) ($venta->puntoVenta ?? 0);
      $montoTotal = round((float) ($venta->total ?? 0), 2);

      $updates = ['updated_at' => now()];
      if (Schema::hasColumn('cajas_diarias', 'monto_ventas')) {
         $updates['monto_ventas'] = DB::raw('greatest(coalesce(monto_ventas, 0) - ' . $montoTotal . ', 0)');
      }
      if (Schema::hasColumn('cajas_diarias', 'monto_cierre_esperado')) {
         $updates['monto_cierre_esperado'] = DB::raw('greatest(coalesce(monto_cierre_esperado, 0) - ' . $montoTotal . ', 0)');
      }
      if (Schema::hasColumn('cajas_diarias', 'cantidad_ventas')) {
         $updates['cantidad_ventas'] = DB::raw('greatest(coalesce(cantidad_ventas, 0) - 1, 0)');
      }

      DB::table('cajas_diarias')
         ->where('usuario_id', $usuarioId)
         ->whereDate('fecha_operacion', $fecha)
         ->where('codigo_sucursal', $codigoSucursal)
         ->where('punto_venta', $puntoVenta)
         ->update($updates);

      if (Schema::hasColumn('ventas', 'estado_caja')) {
         DB::table('ventas')
            ->where('id', $venta->id)
            ->update([
               'estado_caja' => 'ANULADA_DESCONTADA',
               'updated_at' => now(),
            ]);
      }
   }

   private function syncVentaFromNotification(string $codigoSeguimiento, array $validated): void
   {
      $observacion = $validated['observacion'] ?? data_get($validated, 'detalle.observacion');
      $tipoEmision = (string) data_get($validated, 'detalle.tipoEmision', '');
      $estadoSufe = $this->resolveVentaEstadoSufe($validated);
      $updates = [
         'estado_sufe' => $estadoSufe,
         'tipo_emision_sufe' => $tipoEmision,
         'observacion_sufe' => $observacion,
         'fecha_notificacion_sufe' => $validated['fecha'] ?? null,
         'updated_at' => now(),
      ];

      if ($tipoEmision !== 'ANULACION' || filled(data_get($validated, 'detalle.cuf'))) {
         $updates['cuf'] = data_get($validated, 'detalle.cuf');
      }

      if ($tipoEmision !== 'ANULACION') {
         $updates['url_pdf'] = data_get($validated, 'detalle.urlPdf');
         $updates['url_xml'] = data_get($validated, 'detalle.urlXml');
      }

      Venta::query()
         ->where('codigoSeguimiento', $codigoSeguimiento)
         ->update($updates);

      $venta = Venta::query()
         ->where('codigoSeguimiento', $codigoSeguimiento)
         ->first([
            'id',
            'origen_venta_id',
            'origen_venta_tipo',
            'codigoSeguimiento',
            'estado_sufe',
            'cuf',
            'origen_usuario_id',
            'metodoPago',
            'origen_venta_tipo',
            'codigoSucursal',
            'puntoVenta',
            'total',
            'created_at',
            'estado_caja',
         ]);

      if (!$venta) {
         return;
      }

      $origenVentaTipo = (string) ($venta->origen_venta_tipo ?? '');
      $cartId = (int) ($venta->origen_venta_id ?? 0);

      if (!in_array($origenVentaTipo, ['facturacion_cart', 'facturacion_cart_remote'], true) || $cartId <= 0) {
         return;
      }

      $estadoEmision = match ($estadoSufe) {
         'PROCESADA' => 'FACTURADA',
         'OBSERVADA' => 'RECHAZADA',
         'ANULADA', 'ANULACION_OBSERVADA' => 'ANULADA',
         'CONTINGENCIA_CREADA', 'CREADA', 'RECEPCIONADA' => 'PENDIENTE',
         default => strtoupper(trim($estadoSufe)) !== '' ? strtoupper(trim($estadoSufe)) : 'PENDIENTE',
      };

      $mensajeEmision = match ($estadoSufe) {
         'PROCESADA' => 'Factura emitida correctamente.',
         'OBSERVADA' => 'La factura fue observada por SEFE.',
         'ANULADA' => 'La factura fue anulada correctamente.',
         'ANULACION_OBSERVADA' => 'La solicitud de anulacion fue observada por SEFE.',
         'CONTINGENCIA_CREADA', 'CREADA', 'RECEPCIONADA' => 'La venta fue recibida y esta pendiente de confirmacion.',
         default => 'Estado de facturacion actualizado desde la notificacion de SEFE.',
      };

      DB::table('facturacion_carts')
         ->where('id', $cartId)
         ->update([
            'estado_emision' => $estadoEmision,
            'mensaje_emision' => $mensajeEmision,
            'codigo_seguimiento' => $venta->codigoSeguimiento,
            'codigo_seguimiento_fiscal' => $venta->codigoSeguimiento,
            'updated_at' => now(),
         ]);

      $this->reverseVentaFromCajaIfNeeded($venta, $estadoSufe);
   }

   public function procesarNotificacion(Request $request, $codigoSeguimiento)
   {
      $payload = array_merge($request->all(), [
         'codigoSeguimiento' => $request->input('codigoSeguimiento', $codigoSeguimiento),
      ]);

      $validated = $this->sufeValidator->validateNotification($payload, $codigoSeguimiento);

      $notificacion = new Notificacione([
         'finalizado' => $validated['finalizado'],
         'fuente' => $validated['fuente'],
         'estado' => $validated['estado'],
         'codigo_seguimiento' => $codigoSeguimiento,
         'fecha' => $validated['fecha'],
         'mensaje' => $validated['mensaje'],
         'detalle' => json_encode(array_merge(
            $validated['detalle'],
            isset($validated['observacion']) ? ['observacion' => $validated['observacion']] : []
         )),
     ]);
      $notificacion->save();
      $this->syncVentaFromNotification($codigoSeguimiento, $validated);

      return response()->json([
         'message' => 'Notificación recibida',
         'codigoSeguimiento' => $codigoSeguimiento,
      ], 200);
   }
}
