<?php

namespace App\Http\Controllers;

use App\Models\Cajero;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Model;
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
        if (Auth::guard('cajero')->attempt(['email' => $request->email, 'password' => $request->password])) {
            // Autenticación exitosa, recupera la información del cajero con la relación sucursale cargada
            $cajero = Cajero::with('sucursale')->find(Auth::guard('cajero')->id());
    
            // Verifica el estado de la cuenta
            if ($cajero->estado == 0) {
                // Cuenta no verificada
                return response()->json(['error' => 'Falta verificar su cuenta'], 401);
            } elseif ($cajero->estado == 2) {
                // Cuenta inhabilitada
                return response()->json(['error' => 'Cuenta inhabilitada'], 401);
            }
    
            // Devuelve un mensaje de éxito junto con los datos del cajero y su sucursal
            return response()->json(['message' => 'Inicio de sesión correcto', 'cajero' => $cajero]);
        }
    
        // Si la autenticación falla, devuelve un mensaje de error
        return response()->json(['error' => 'Credenciales incorrectas'], 401);
    }
    
    
}
