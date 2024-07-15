<?php

namespace App\Http\Controllers;

use App\Models\Sucursale;
use Illuminate\Http\Request;

class SucursaleController extends Controller
{
   /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
   public function index()
   {
      return Sucursale::where('estado', 1)->get();
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
         'municipio' => 'required',
         'departamento' => 'required',
         'codigosucursal' => 'required',
         'direcccion' => 'required',
         'telefono' => 'required',
      ]);

      $sucursale = new Sucursale();
      $sucursale->nombre = $request->nombre;
      $sucursale->municipio = $request->municipio;
      $sucursale->departamento = $request->departamento;
      $sucursale->codigosucursal = $request->codigosucursal;
      $sucursale->direcccion = $request->direcccion;
      $sucursale->telefono = $request->telefono;
      $sucursale->save();

      return response()->json($sucursale, 201);
   }

   /**
    * Display the specified resource.
    *
    * @param  \App\Models\Sucursale  $sucursale
    * @return \Illuminate\Http\Response
    */
   public function show(Sucursale $sucursale)
   {
      return $sucursale;
   }

   /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \App\Models\Sucursale  $sucursale
    * @return \Illuminate\Http\Response
    */
   public function update(Request $request, Sucursale $sucursale)
   {
      $request->validate([
         'nombre' => 'required',
         'municipio' => 'required',
         'departamento' => 'required',
         'codigosucursal' => 'required',
         'direcccion' => 'required',
         'telefono' => 'required',
      ]);
      $sucursale->nombre = $request->nombre;
      $sucursale->municipio = $request->municipio;
      $sucursale->departamento = $request->departamento;
      $sucursale->codigosucursal = $request->codigosucursal;
      $sucursale->direcccion = $request->direcccion;
      $sucursale->telefono = $request->telefono;
      $sucursale->save();
      return $sucursale;
   }

   /**
    * Remove the specified resource from storage.
    *
    * @param  \App\Models\Sucursale  $sucursale
    * @return \Illuminate\Http\Response
    */
   public function destroy(Sucursale $sucursale)
   {
      $sucursale->estado = 0;
      $sucursale->save();
      return $sucursale;
   }
}
