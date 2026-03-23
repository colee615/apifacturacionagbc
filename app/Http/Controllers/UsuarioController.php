<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class UsuarioController extends Controller
{
   public function index()
   {
      return Usuario::with(['sucursale', 'roles:id,name,slug'])->get();
   }

   public function store(Request $request)
   {
      $request->validate([
         'name' => 'required|string',
         'email' => 'required|string|email|unique:usuarios',
         'sucursale_id' => 'required|integer|exists:sucursales,id',
         'password' => 'required',
         'role_ids' => 'nullable|array',
         'role_ids.*' => 'integer|exists:roles,id',
      ]);

      $usuario = new Usuario();
      $usuario->name = $request->name;
      $usuario->email = $request->email;
      $usuario->password = Hash::make($request->input('password'));
      $usuario->sucursale_id = $request->sucursale_id;
      $usuario->save();
      $roleIds = $request->input('role_ids');
      if (is_array($roleIds) && !empty($roleIds)) {
         $usuario->roles()->sync($roleIds);
      } else {
         $defaultRoleId = Role::where('slug', 'usuario')->value('id');
         if ($defaultRoleId) {
            $usuario->roles()->sync([$defaultRoleId]);
         }
      }
      $usuario->load(['sucursale', 'roles:id,name,slug']);
      return $usuario;
   }

   public function show(Usuario $usuario)
   {
      $usuario->load(['sucursale', 'roles:id,name,slug']);
      return $usuario;
   }

   public function update(Request $request, Usuario $usuario)
   {
      $request->validate([
         'name' => 'required|string',
         'email' => 'required|string|email|unique:usuarios,email,' . $usuario->id,
         'sucursale_id' => 'required|integer|exists:sucursales,id',
         'role_ids' => 'nullable|array',
         'role_ids.*' => 'integer|exists:roles,id',
      ]);

      $usuario->name = $request->name;
      $usuario->email = $request->email;

      if (isset($request->password) && !empty($request->password)) {
         $usuario->password = Hash::make($request->password);
      }
      $usuario->sucursale_id = $request->sucursale_id;
      $usuario->save();
      if ($request->has('role_ids')) {
         $usuario->roles()->sync($request->input('role_ids', []));
      }
      $usuario->load(['sucursale', 'roles:id,name,slug']);

      return $usuario;
   }

   public function login(Request $request)
   {
      $request->validate([
         'email' => 'required|email',
    'password' => 'required',
      ]);

      $usuario = Usuario::where('email', $request->email)->first();

      if (!$usuario) {
         return response()->json(['error' => 'El correo electronico no esta registrado'], 400);
      }

      if (!Hash::check($request->password, $usuario->password)) {
         return response()->json(['error' => 'Credenciales incorrectas'], 401);
      }


      try {
         $token = JWTAuth::fromUser($usuario);
      } catch (JWTException $e) {
         return response()->json(['error' => 'No se pudo generar el token de autenticacion'], 500);
      }

      $agent = new Agent();
      $log = new LoginLog();
      $log->usuario_id = $usuario->id;
      $log->ip_address = $request->ip();
      $log->user_agent = $agent->platform() . ' - ' . $agent->browser();
      $log->login_time = now();
      $log->save();

      $usuario->load('sucursale');

      return response()->json([
         'message' => 'Inicio de sesion exitoso',
         'token' => $token,
         'token_type' => 'bearer',
         'usuario' => $usuario,
         'roles' => $usuario->roleSlugs(),
         'permissions' => $usuario->permissions(),
         'views' => $usuario->views(),
      ]);
   }

   public function me(Request $request)
   {
      $usuario = Auth::guard('api')->user() ?? $request->user();

      if (!$usuario) {
         return response()->json(['error' => 'No autenticado'], 401);
      }

      $usuario->load('sucursale');

      return response()->json([
         'usuario' => $usuario,
         'roles' => $usuario->roleSlugs(),
         'permissions' => $usuario->permissions(),
         'views' => $usuario->views(),
      ]);
   }

   public function requestPasswordReset(Request $request)
   {
      $request->validate([
         'email' => 'required|email',
      ]);

      $usuario = Usuario::where('email', $request->email)->first();
      if (!$usuario) {
         return response()->json(['error' => 'Usuario no encontrado'], 404);
      }

      $usuario->confirmation_token = Str::random(40);
      $usuario->save();

      return response()->json([
         'message' => 'Token de recuperacion generado correctamente',
         'token' => $usuario->confirmation_token,
      ]);
   }

   public function resetPassword(Request $request, $token)
   {
      $request->validate([
         'password' => 'required|string|min:6',
      ]);

      $usuario = Usuario::where('confirmation_token', $token)->first();
      if (!$usuario) {
         return response()->json(['error' => 'Token invalido'], 404);
      }

      $usuario->password = Hash::make($request->password);
      $usuario->confirmation_token = null;
      $usuario->save();

      return response()->json(['message' => 'Contrasena actualizada correctamente']);
   }

   public function destroy(Usuario $usuario)
   {
      $usuario->estado = 2;
      $usuario->save();
      return $usuario;
   }

   public function activar(Request $request, $id)
   {
      $usuario = Usuario::find($id);

      if (!$usuario) {
         return response()->json(['error' => 'Usuario no encontrado'], 404);
      }

      $usuario->estado = 1;
      $usuario->save();

      return response()->json(['message' => 'Usuario activado exitosamente', 'usuario' => $usuario]);
   }

}
