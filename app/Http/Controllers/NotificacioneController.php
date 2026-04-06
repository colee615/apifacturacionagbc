<?php

namespace App\Http\Controllers;

use App\Models\Notificacione;
use App\Models\Venta;
use App\Support\SufeSectorUnoValidator;
use Illuminate\Http\Request;

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

   private function syncVentaFromNotification(string $codigoSeguimiento, array $validated): void
   {
      $observacion = $validated['observacion'] ?? data_get($validated, 'detalle.observacion');

      Venta::query()
         ->where('codigoSeguimiento', $codigoSeguimiento)
         ->update([
            'estado_sufe' => $this->resolveVentaEstadoSufe($validated),
            'tipo_emision_sufe' => data_get($validated, 'detalle.tipoEmision'),
            'cuf' => data_get($validated, 'detalle.cuf'),
            'url_pdf' => data_get($validated, 'detalle.urlPdf'),
            'url_xml' => data_get($validated, 'detalle.urlXml'),
            'observacion_sufe' => $observacion,
            'fecha_notificacion_sufe' => $validated['fecha'] ?? null,
            'updated_at' => now(),
         ]);
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
