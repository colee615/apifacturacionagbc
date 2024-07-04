<?php

namespace App\Http\Controllers;

use App\Models\Cajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use App\Mail\ConfirmationMail;
use App\Mail\ResetMail;
use App\Mail\CodigoConfirmationMail;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Jenssegers\Agent\Agent;
use App\Models\LoginLog;

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
      // Validar los campos
      $request->validate([
         'name' => 'required|string',
         'email' => 'required|string|email|unique:cajeros',
         'sucursale_id' => 'required|integer|exists:sucursales,id',
         'password' => 'required',
      ]);
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
      // Validar los campos
      $request->validate([
         'name' => 'required|string',
         'email' => 'required|string|email|unique:cajeros,email,' . $cajero->id,
         'password' => 'nullable|string|confirmed',
         'sucursale_id' => 'required|integer|exists:sucursales,id',
      ]);

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
      $cajero = Cajero::where('email', $request->email)->first();

      if (!$cajero) {
         return response()->json(['error' => 'El correo electrónico que ingresó no está registrado en el sistema'], 400);
      }

      if (!Auth::guard('cajero')->attempt(['email' => $request->email, 'password' => $request->password])) {
         return response()->json(['error' => 'Credenciales incorrectas'], 400);
      }

      $codigoConfirmacion = rand(100000, 999999);
      $cajero->codigo_confirmacion = $codigoConfirmacion;
      $cajero->save();

      Mail::to($cajero->email)->send(new CodigoConfirmationMail($codigoConfirmacion));

      $agent = new Agent();
      $log = new LoginLog();
      $log->cajero_id = $cajero->id;
      $log->ip_address = $request->ip();
      $log->user_agent = $agent->platform() . ' - ' . $agent->browser();
      $log->login_time = now();
      $log->save();

      return response()->json(['message' => 'Código de confirmación enviado a su correo electrónico']);
   }
   public function verificarCodigoConfirmacion(Request $request)
   {
      $cajero = Cajero::where('email', $request->email)
         ->where('codigo_confirmacion', $request->codigo_confirmacion)
         ->first();

      if (!$cajero) {
         return response()->json(['error' => 'Código de confirmación incorrecto'], 400);
      }
      if (Auth::guard('cajero')->attempt(['email' => $request->email, 'password' => $request->password])) {
         $cajero = Cajero::with('sucursale')->find(Auth::guard('cajero')->id());
         if ($cajero->estado == 0) {
            return response()->json(['error' => 'Falta verificar su cuenta'], 400);
         } elseif ($cajero->estado == 2) {
            return response()->json(['error' => 'Cuenta inhabilitada'], 400);
         }
         try {
            if (!$token = JWTAuth::fromUser($cajero)) {
               return response()->json(['error' => 'No se pudo crear el token'], 500);
            }
         } catch (JWTException $e) {
            return response()->json(['error' => 'No se pudo crear el token'], 500);
         }
         return response()->json(['message' => 'Inicio de sesión correcto', 'token' => $token, 'cajero' => $cajero]);
      }
      return response()->json(['error' => 'Credenciales incorrectas'], 400);
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




   public function requestPasswordReset(Request $request)
   {
      $cajero = Cajero::where('email', $request->email)->first();

      if (!$cajero) {
         return response()->json(['error' => 'El correo electrónico no está registrado en el sistema'], 400);
      }

      // Generar un token de restablecimiento de contraseña
      $cajero->confirmation_token = Str::random(40);
      $cajero->save();

      // Enviar el correo de restablecimiento de contraseña
      Mail::to($cajero->email)->send(new ResetMail($cajero));

      return response()->json(['message' => 'Se ha enviado un correo para restablecer su contraseña']);
   }

   public function resetPassword(Request $request, $token)
   {
      $cajero = Cajero::where('confirmation_token', $token)->first();

      if (!$cajero) {
         return response()->json(['error' => 'Token de restablecimiento inválido'], 400);
      }



      // Actualizar la contraseña
      $cajero->password = Hash::make($request->password);
      $cajero->confirmation_token = null; // Eliminar el token de restablecimiento
      $cajero->save();

      return response()->json(['message' => 'Su contraseña ha sido restablecida exitosamente']);
   }
}
