<?php

namespace App\Http\Controllers;

use App\Models\Cajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\ConfirmationMail;


class CajeroController extends Controller
{
   /**
    * Display a listing of the resource.
    *
    * @return \Illuminate\Http\Response
    */
   public function index()
   {
      return Cajero::with(['sucursale'])->where('estado', 1)->get();
   }

   /**
    * Store a newly created resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @return \Illuminate\Http\Response
    */
   public function store(Request $request)
   {
      $cajero = new Cajero();
      $cajero->name = $request->name;
      $cajero->email = $request->email;
      $cajero->password = Hash::make($request->input('password'));

      // Generar un token de confirmación y asignarlo al usuario
      $cajero->confirmation_token = Str::random(40);

      $cajero->sucursale_id = $request->sucursale_id;
      $cajero->save();



      Mail::to($cajero->email)->send(new ConfirmationMail($cajero));
      return $cajero;
   }

   /**
    * Display the specified resource.
    *
    * @param  \App\Models\Cajero  $cajero
    * @return \Illuminate\Http\Response
    */
   public function show(Cajero $cajero)
   {
      $cajero->cajero = $cajero->cajero;
      return $cajero;
   }

   /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \App\Models\Cajero  $cajero
    * @return \Illuminate\Http\Response
    */
   public function update(Request $request, Cajero $cajero)
   {
      $cajero->name = $request->name;
      $cajero->email = $request->email;
      if (isset($request->password)) {
         if (!empty($request->password)) {
            $cajero->password = Hash::make($request->password);
         }
      }
      $cajero->sucursale_id = $request->sucursale_id;
      $cajero->save();
      return $cajero;
   }

   /**
    * Remove the specified resource from storage.
    *
    * @param  \App\Models\Cajero  $cajero
    * @return \Illuminate\Http\Response
    */
   public function destroy(Cajero $cajero)
   {
      $cajero->estado = 0;
      $cajero->save();
      return $cajero;
   }

   public function login(Request $request)
   {
      // Verificar si el email existe en la base de datos
      $cajero = Cajero::where('email', $request->email)->first();

      // Si no existe, devolver un mensaje de error específico
      if (!$cajero) {
         return response()->json(['error' => 'El correo electronico que ingreso no esta registrado en el sistema']);
      }

      // Intentar autenticar al cajero con las credenciales proporcionadas
      if (Auth::guard('cajero')->attempt(['email' => $request->email, 'password' => $request->password])) {
         // Autenticación exitosa, recupera la información del cajero con la relación sucursale cargada
         $cajero = Cajero::with('sucursale')->find(Auth::guard('cajero')->id());

         // Verificar el estado de la cuenta
         if ($cajero->estado == 0) {
            // Cuenta no verificada
            return response()->json(['error' => 'Falta verificar su cuenta']);
         } elseif ($cajero->estado == 2) {
            // Cuenta inhabilitada
            return response()->json(['error' => 'Cuenta inhabilitada']);
         }

         // Devuelve un mensaje de éxito junto con los datos del cajero y su sucursal
         return response()->json(['message' => 'Inicio de sesión correcto', 'cajero' => $cajero]);
      }

      // Si la autenticación falla, devuelve un mensaje de error
      return response()->json(['error' => 'Credenciales incorrectas']);
   }
   public function confirmar($token)
   {
      $cajero = Cajero::where('confirmation_token', $token)->first();

      if (!$cajero) {
         return redirect('/')->with('error', 'Invalid token');
      }

      $cajero->estado = 1; // O el campo que uses para el estado del cajero
      $cajero->confirmation_token = null; // Elimina el token de confirmación
      $cajero->save();

      return redirect('http://localhost:3000/auth/register');
   }
}
