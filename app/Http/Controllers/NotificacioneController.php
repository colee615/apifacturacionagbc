<?php

namespace App\Http\Controllers;

use App\Models\Notificacione;
use Illuminate\Http\Request;

class NotificacioneController extends Controller
{
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
      $validated = $request->validate([
         'finalizado' => 'required|boolean',
         'fuente' => 'required|string',
         'estado' => 'required|string',
         'fecha' => 'required|string',
         'mensaje' => 'required|string',
         'detalle' => 'nullable|array'
     ]);
     // Crear y guardar la notificación en la base de datos
     $notificacion = new Notificacione([
         'finalizado' => $validated['finalizado'],
         'fuente' => $validated['fuente'],
         'estado' => $validated['estado'],
         'codigo_seguimiento' => $codigoSeguimiento,
         'fecha' => $validated['fecha'],
         'mensaje' => $validated['mensaje'],
         'detalle' => json_encode($validated['detalle']),
     ]);
     $notificacion->save();
     // Devolver una respuesta para marcar la notificación como confirmada
     return response()->json(['message' => 'Notificación recibida'], 200);
   }
}
