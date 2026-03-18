<?php

namespace App\Http\Controllers;

use App\Models\Notificacione;
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

      return response()->json([
         'message' => 'Notificación recibida',
         'codigoSeguimiento' => $codigoSeguimiento,
      ], 200);
   }
}
