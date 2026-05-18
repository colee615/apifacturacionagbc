<?php

namespace App\Http\Controllers;

use App\Models\LoginLog;
use App\Models\Role;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

class UsuarioController extends Controller
{
   private const PASSWORD_RULES = [
      'required',
      'string',
      'min:10',
      'max:64',
      'regex:/[a-z]/',
      'regex:/[A-Z]/',
      'regex:/[0-9]/',
      'regex:/[^A-Za-z0-9]/',
   ];

   public function index()
   {
      return Usuario::with(['roles:id,name,slug'])->get();
   }

   public function store(Request $request)
   {
      $request->validate([
         'name' => 'required|string',
         'email' => 'required|string|email|unique:usuarios',
         'alias' => 'nullable|string|max:80|unique:usuarios,alias',
         'numero_carnet' => 'nullable|string|max:40',
         'password' => self::PASSWORD_RULES,
         'role_ids' => 'nullable|array',
         'role_ids.*' => 'integer|exists:roles,id',
      ]);

      $usuario = new Usuario();
      $usuario->name = $request->name;
      $usuario->email = $request->email;
      $usuario->alias = $request->filled('alias') ? strtolower(trim((string) $request->alias)) : null;
      $usuario->numero_carnet = $request->filled('numero_carnet') ? strtoupper(trim((string) $request->numero_carnet)) : null;
      $usuario->password = Hash::make($request->input('password'));
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
      $usuario->load(['roles:id,name,slug']);
      return $usuario;
   }

   public function show(Usuario $usuario)
   {
      $usuario->load(['roles:id,name,slug']);
      return $usuario;
   }

   public function update(Request $request, Usuario $usuario)
   {
      $request->validate([
         'name' => 'required|string',
         'email' => 'required|string|email|unique:usuarios,email,' . $usuario->id,
         'alias' => 'nullable|string|max:80|unique:usuarios,alias,' . $usuario->id,
         'numero_carnet' => 'nullable|string|max:40',
         'role_ids' => 'nullable|array',
         'role_ids.*' => 'integer|exists:roles,id',
      ]);

      $usuario->name = $request->name;
      $usuario->email = $request->email;
      $usuario->alias = $request->filled('alias') ? strtolower(trim((string) $request->alias)) : null;
      $usuario->numero_carnet = $request->filled('numero_carnet') ? strtoupper(trim((string) $request->numero_carnet)) : null;

      if (isset($request->password) && !empty($request->password)) {
         $request->validate([
            'password' => self::PASSWORD_RULES,
         ]);
         $usuario->password = Hash::make($request->password);
      }
      $usuario->save();
      if ($request->has('role_ids')) {
         $usuario->roles()->sync($request->input('role_ids', []));
      }
      $usuario->load(['roles:id,name,slug']);

      return $usuario;
   }

   public function login(Request $request)
   {
      $request->validate([
         'email' => 'required|email',
         'password' => 'required|string',
      ]);

      $email = strtolower(trim((string) $request->email));
      $usuario = Usuario::where('email', $email)->first();

      if (!$usuario || (int) $usuario->estado !== 1 || !Hash::check((string) $request->password, (string) $usuario->password)) {
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

      return response()->json([
         'usuario' => $usuario,
         'roles' => $usuario->roleSlugs(),
         'permissions' => $usuario->permissions(),
         'views' => $usuario->views(),
      ]);
   }

   public function logout()
   {
      try {
         $token = JWTAuth::getToken();
         if ($token) {
            JWTAuth::invalidate($token);
         }
      } catch (\Throwable $e) {
         Log::warning('Logout JWT invalidate warning', ['message' => $e->getMessage()]);
      }

      return response()->json([
         'message' => 'Sesion cerrada correctamente',
      ]);
   }

   public function requestPasswordReset(Request $request)
   {
      $request->validate([
         'email' => 'required|email',
      ]);

      $email = strtolower(trim((string) $request->email));
      $usuario = Usuario::where('email', $email)->where('estado', 1)->first();
      $plainTextToken = null;

      if ($usuario) {
         $plainTextToken = Str::random(64);
         $usuario->confirmation_token = hash('sha256', $plainTextToken);
         $usuario->confirmation_token_expires_at = now()->addMinutes((int) config('auth.passwords.usuarios.expire', 60));
         $usuario->save();

         try {
            Mail::raw(
               "Se solicito restablecer tu contraseña.\n\nToken: {$plainTextToken}\n\nEste token expirara en 60 minutos.",
               function ($message) use ($usuario) {
                  $message->to($usuario->email)
                     ->subject('Recuperacion de contraseña - AGBc');
               }
            );
         } catch (\Throwable $e) {
            Log::warning('No se pudo enviar correo de recuperacion', [
               'usuario_id' => $usuario->id,
               'email' => $usuario->email,
               'error' => $e->getMessage(),
            ]);
         }
      }

      $payload = [
         'message' => 'Si el correo existe en el sistema, se ha generado el proceso de recuperacion.',
      ];

      // Solo para desarrollo local controlado, evita exponer tokens en produccion.
      if (app()->environment('local') || (bool) config('app.debug')) {
         $payload['reset_token'] = $plainTextToken;
      }

      return response()->json($payload);
   }

   public function resetPassword(Request $request, $token)
   {
      $request->validate([
         'password' => self::PASSWORD_RULES,
      ]);

      $tokenHash = hash('sha256', (string) $token);
      $usuario = Usuario::where('confirmation_token', $tokenHash)
         ->where(function ($query) {
            $query->whereNull('confirmation_token_expires_at')
               ->orWhere('confirmation_token_expires_at', '>=', now());
         })
         ->first();

      if (!$usuario) {
         return response()->json(['error' => 'Token invalido o expirado'], 404);
      }

      $usuario->password = Hash::make($request->password);
      $usuario->confirmation_token = null;
      $usuario->confirmation_token_expires_at = null;
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
