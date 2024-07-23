<?php

namespace App\Http\Controllers;

use App\Models\Servicio;
use Illuminate\Http\Request;

class ServicioController extends Controller
{
   /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
   public function index()
   {
      return Servicio::all();
   }

   /**
    * Store a newly created resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @return \Illuminate\Http\Response
    */
   public function store(Request $request)
   {
      $request->validate([
         'nombre' => 'required',
         'codigo' => 'required',
         'descripcion' => 'required',
         'precioUnitario' => 'required|numeric|min:0',
         'actividadEconomica' => 'required',
         'unidadMedida' => 'required',
         'codigoSin' => 'required',
         'tipo' => 'required',
      ]);
      $servicio = new Servicio();
      $servicio->nombre = $request->nombre;
      $servicio->codigo = $request->codigo;
      $servicio->descripcion = $request->descripcion;
      $servicio->precioUnitario = $request->precioUnitario;

      $servicio->actividadEconomica = $request->actividadEconomica;
      $servicio->unidadMedida = $request->unidadMedida;
      $servicio->codigoSin = $request->codigoSin;
      $servicio->tipo = $request->tipo;

      $servicio->save();
   }

   /**
    * Display the specified resource.
    *
    * @param  \App\Models\Servicio  $servicio
    * @return \Illuminate\Http\Response
    */
   public function show(Servicio $servicio)
   {
      return $servicio;
   }

   /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \App\Models\Servicio  $servicio
    * @return \Illuminate\Http\Response
    */
   public function update(Request $request, Servicio $servicio)
   {
      $request->validate([
         'nombre' => 'required',
         'codigo' => 'required',
         'descripcion' => 'required',
         'precioUnitario' => 'required|numeric|min:0',
         'actividadEconomica' => 'required',
         'unidadMedida' => 'required',
         'codigoSin' => 'required',
         'tipo' => 'required',
      ]);
      $servicio->nombre = $request->nombre;
      $servicio->codigo = $request->codigo;
      $servicio->descripcion = $request->descripcion;
      $servicio->precioUnitario = $request->precioUnitario;
      $servicio->actividadEconomica = $request->actividadEconomica;
      $servicio->unidadMedida = $request->unidadMedida;
      $servicio->codigoSin = $request->codigoSin;
      $servicio->tipo = $request->tipo;
      $servicio->save();
   }

   /**
    * Remove the specified resource from storage.
    *
    * @param  \App\Models\Servicio  $servicio
    * @return \Illuminate\Http\Response
    */
   public function destroy(Servicio $servicio)
   {
      //
   }
}
