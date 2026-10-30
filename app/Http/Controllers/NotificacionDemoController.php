<?php

namespace App\Http\Controllers;

use App\Support\SufeSectorUnoValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NotificacionDemoController extends Controller
{
    public function recibir(Request $request, string $codigoSeguimiento, SufeSectorUnoValidator $validator)
    {
        $payload = $request->all();
        $payload['codigoSeguimiento'] ??= $codigoSeguimiento;
        $validated = $validator->validateNotification($payload, $codigoSeguimiento);

        // Este receptor nunca sincroniza ventas, carritos ni cajas de produccion.
        DB::table('notificaciones_demo')->insert([
            'codigo_seguimiento' => $codigoSeguimiento,
            'estado' => $validated['estado'],
            'fuente' => $validated['fuente'],
            'payload' => json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Notificacion demo recibida; facturacion principal sin modificaciones.',
            'codigoSeguimiento' => $codigoSeguimiento,
            'environment' => 'demo',
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'codigoSeguimiento' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return DB::table('notificaciones_demo')
            ->when($data['codigoSeguimiento'] ?? null, fn ($query, $code) => $query->where('codigo_seguimiento', $code))
            ->orderByDesc('id')->paginate(25);
    }
}
