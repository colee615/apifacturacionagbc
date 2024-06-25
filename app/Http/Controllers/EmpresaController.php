<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use Illuminate\Http\Request;

class EmpresaController extends Controller
{
   /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
   public function index()
   {
      return Empresa::where('estado', 1)->get();
   }

   /**
    * Store a newly created resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @return \Illuminate\Http\Response
    */
   public function store(Request $request)
   {
      $empresa = new Empresa();
      $empresa->nombre = $request->nombre;
      $empresa->save();
      return $empresa;
   }

   /**
    * Display the specified resource.
    *
    * @param  \App\Models\Empresa  $empresa
    * @return \Illuminate\Http\Response
    */
   public function show(Empresa $empresa)
   {
      //
   }

   /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \App\Models\Empresa  $empresa
    * @return \Illuminate\Http\Response
    */
   public function update(Request $request, Empresa $empresa)
   {
      //
   }

   /**
    * Remove the specified resource from storage.
    *
    * @param  \App\Models\Empresa  $empresa
    * @return \Illuminate\Http\Response
    */
   public function destroy(Empresa $empresa)
   {
      //
   }
}
