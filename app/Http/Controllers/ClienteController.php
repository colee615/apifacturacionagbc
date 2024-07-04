<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Str;



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
         'correo' => 'nullable|email', // Permitir correo nulo o vacío
      ]);


      // Obtener el valor de correo o asignar un valor por defecto
      $correo = $request->input('correo') ?? 'correo-generico@example.com';

      // Obtener el último código de cliente existente
      $ultimoCodigoCliente = Cliente::where('estado', 1)->max('codigoCliente');

      // Extraer el número del último código de cliente
      $ultimoNumeroCliente = (int) Str::replace('CLIENT', '', $ultimoCodigoCliente);

      // Incrementar el número para el nuevo código de cliente
      $nuevoNumeroCliente = $ultimoNumeroCliente + 1;

      // Formatear el nuevo número de cliente con ceros a la izquierda
      $nuevoCodigoCliente = 'CLIENT' . str_pad($nuevoNumeroCliente, 2, '0', STR_PAD_LEFT);

      // Crear el cliente con el nuevo código
      $cliente = new Cliente();
      $cliente->razonSocial = $request->razonSocial;
      $cliente->documentoIdentidad = $request->documentoIdentidad;
      $cliente->complemento = $request->complemento;
      $cliente->tipoDocumentoIdentidad = $request->tipoDocumentoIdentidad;
      $cliente->correo = $correo; // Usar el correo del request o genér
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

      $cliente->razonSocial = $request->razonSocial;
      $cliente->documentoIdentidad = $request->documentoIdentidad;
      $cliente->complemento = $request->complemento;
      $cliente->tipoDocumentoIdentidad = $request->tipoDocumentoIdentidad;
      $cliente->correo = $request->correo;
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
