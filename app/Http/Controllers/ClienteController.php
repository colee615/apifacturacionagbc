<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
   /**
    * Display a listing of the resource.
    */
   public function index()
   {
      return Cliente::where('estado', 1)->get();
   }

   /**
    * Store a newly created resource in storage.
    */
   public function store(Request $request)
   {
      $request->validate([
         'razonSocial' => 'required|string',
         'documentoIdentidad' => 'required|string',
         'complemento' => 'nullable|string',
         'tipoDocumentoIdentidad' => 'required',
         'correo' => 'nullable|email',
      ]);

      $correo = trim((string) $request->input('correo', ''));
      if ($correo === '') {
         $correo = 'correo-generico@example.com';
      }

      $ultimo = Cliente::selectRaw('MAX(CAST(SUBSTRING("codigoCliente", 7) AS INTEGER)) as max')
         ->first()
         ->max ?? 0;

      $nuevoCodigoCliente = 'CLIENT' . str_pad(($ultimo + 1), 2, '0', STR_PAD_LEFT);

      $cliente = new Cliente();
      $cliente->razonSocial = trim((string) $request->razonSocial);
      $cliente->documentoIdentidad = trim((string) $request->documentoIdentidad);
      $cliente->complemento = $request->filled('complemento') ? trim((string) $request->complemento) : null;
      $cliente->tipoDocumentoIdentidad = $request->tipoDocumentoIdentidad;
      $cliente->correo = $correo;
      $cliente->codigoCliente = $nuevoCodigoCliente;
      $cliente->save();

      return $cliente;
   }

   /**
    * Display the specified resource.
    */
   public function show(Cliente $cliente)
   {
      return $cliente;
   }

   /**
    * Update the specified resource in storage.
    */
   public function update(Request $request, Cliente $cliente)
   {
      $request->validate([
         'razonSocial' => 'required|string',
         'documentoIdentidad' => 'required|string',
         'complemento' => 'nullable|string',
         'tipoDocumentoIdentidad' => 'required',
         'correo' => 'nullable|email',
      ]);

      $cliente->razonSocial = trim((string) $request->razonSocial);
      $cliente->documentoIdentidad = trim((string) $request->documentoIdentidad);
      $cliente->complemento = $request->filled('complemento') ? trim((string) $request->complemento) : null;
      $cliente->tipoDocumentoIdentidad = $request->tipoDocumentoIdentidad;
      $cliente->correo = $request->filled('correo') ? trim((string) $request->correo) : 'correo-generico@example.com';
      $cliente->save();

      return $cliente;
   }

   /**
    * Remove the specified resource from storage.
    */
   public function destroy(Cliente $cliente)
   {
      $cliente->estado = 0;
      $cliente->save();

      return $cliente;
   }
}
