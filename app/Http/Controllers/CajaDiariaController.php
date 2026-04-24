<?php

namespace App\Http\Controllers;

use App\Models\CajaDiaria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CajaDiariaController extends Controller
{
    public function estado(Request $request)
    {
        [$usuarioId, $usuarioNombre, $usuarioEmail] = $this->resolveActor($request);
        $fecha = (string) ($request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
        ])['fecha'] ?? now()->toDateString());
        $autoClosed = $this->closePendingCajas($usuarioId, $fecha);

        $caja = CajaDiaria::query()
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_operacion', $fecha)
            ->first();

        return response()->json([
            'ok' => true,
            'usuario' => [
                'id' => $usuarioId,
                'nombre' => $usuarioNombre,
                'email' => $usuarioEmail,
            ],
            'fecha' => $fecha,
            'caja' => $caja ? $this->cajaPayload($caja) : null,
            'estado' => $caja?->estado ?? 'SIN_APERTURA',
            'mensaje' => $autoClosed > 0
                ? 'Se cerraron y arquearon ' . $autoClosed . ' caja(s) pendiente(s) de días anteriores.'
                : '',
        ]);
    }

    public function abrir(Request $request)
    {
        [$usuarioId, $usuarioNombre, $usuarioEmail] = $this->resolveActor($request);
        $validated = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
            'montoApertura' => ['nullable', 'numeric', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $fecha = (string) ($validated['fecha'] ?? now()->toDateString());
        $existente = CajaDiaria::query()
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_operacion', $fecha)
            ->first();

        if ($existente) {
            throw ValidationException::withMessages([
                'fecha' => ['Ya existe una caja para este usuario en la fecha seleccionada.'],
            ]);
        }

        $caja = CajaDiaria::query()->create([
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuarioNombre,
            'usuario_email' => $usuarioEmail,
            'codigo_sucursal' => (int) $validated['codigoSucursal'],
            'punto_venta' => (int) $validated['puntoVenta'],
            'fecha_operacion' => $fecha,
            'estado' => 'ABIERTA',
            'monto_apertura' => round((float) ($validated['montoApertura'] ?? 0), 2),
            'monto_ventas' => 0,
            'cantidad_ventas' => 0,
            'observacion_apertura' => isset($validated['observacion']) ? trim((string) $validated['observacion']) : null,
            'abierta_en' => now(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Caja abierta correctamente.',
            'caja' => $this->cajaPayload($caja),
        ], 201);
    }

    public function cerrar(Request $request)
    {
        [$usuarioId] = $this->resolveActor($request);
        $validated = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
            'montoCierreDeclarado' => ['required', 'numeric', 'min:0'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $fecha = (string) ($validated['fecha'] ?? now()->toDateString());
        $caja = CajaDiaria::query()
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_operacion', $fecha)
            ->first();

        if (!$caja) {
            throw ValidationException::withMessages([
                'fecha' => ['No existe una caja abierta para cerrar en la fecha seleccionada.'],
            ]);
        }

        if ($caja->estado === 'CERRADA') {
            throw ValidationException::withMessages([
                'fecha' => ['La caja del dia ya esta cerrada.'],
            ]);
        }

        $montoDeclarado = round((float) $validated['montoCierreDeclarado'], 2);
        $observacion = isset($validated['observacion']) ? trim((string) $validated['observacion']) : null;
        $caja = $this->closeCajaDiariaWithArqueo($caja, $montoDeclarado, $observacion);

        return response()->json([
            'ok' => true,
            'message' => 'Caja cerrada correctamente.',
            'caja' => $this->cajaPayload($caja),
        ]);
    }

    public function arqueos(Request $request)
    {
        [$usuarioId] = $this->resolveActor($request);
        $validated = $request->validate([
            'mes' => ['nullable', 'regex:/^\d{4}\-\d{2}$/'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
            'codigoSucursal' => ['nullable', 'integer', 'min:0'],
            'puntoVenta' => ['nullable', 'integer', 'min:0'],
        ]);

        $month = (string) ($validated['mes'] ?? now()->format('Y-m'));
        [$year, $monthNumber] = array_map('intval', explode('-', $month));
        $from = sprintf('%04d-%02d-01', $year, $monthNumber);
        $to = date('Y-m-t', strtotime($from));

        if (!Schema::hasTable('caja_arqueos')) {
            return response()->json([
                'ok' => true,
                'mes' => $month,
                'rango' => ['from' => $from, 'to' => $to],
                'resumen' => [
                    'dias' => 0,
                    'arqueos' => 0,
                    'cantidadVentas' => 0,
                    'montoTotal' => 0.0,
                    'montoDeclarado' => 0.0,
                    'diferencia' => 0.0,
                ],
                'dias' => [],
            ]);
        }

        $query = DB::table('caja_arqueos')
            ->where('usuario_id', (string) $usuarioId)
            ->whereBetween('fecha_operacion', [$from, $to]);

        if (array_key_exists('codigoSucursal', $validated)) {
            $query->where('codigo_sucursal', (int) $validated['codigoSucursal']);
        }
        if (array_key_exists('puntoVenta', $validated)) {
            $query->where('punto_venta', (int) $validated['puntoVenta']);
        }

        $rows = $query
            ->orderByDesc('fecha_operacion')
            ->orderByDesc('id')
            ->get();

        $groupedDays = $rows
            ->groupBy('fecha_operacion')
            ->map(function ($dayRows, $fecha) {
                $dayRows = collect($dayRows);
                return [
                    'fecha' => (string) $fecha,
                    'arqueos' => $dayRows->map(function ($row) {
                        return [
                            'id' => (int) $row->id,
                            'estado' => (string) ($row->estado ?? 'ARQUEADO'),
                            'codigoSucursal' => (int) $row->codigo_sucursal,
                            'puntoVenta' => (int) $row->punto_venta,
                            'cantidadVentas' => (int) $row->cantidad_ventas,
                            'montoTotal' => (float) $row->monto_total,
                            'montoDeclarado' => (float) $row->monto_cierre_declarado,
                            'diferencia' => (float) $row->diferencia,
                            'cerradoEn' => $this->formatDateTimeValue($row->cerrado_en ?? null),
                        ];
                    })->values(),
                    'cantidadVentas' => (int) $dayRows->sum('cantidad_ventas'),
                    'montoTotal' => round((float) $dayRows->sum('monto_total'), 2),
                    'montoDeclarado' => round((float) $dayRows->sum('monto_cierre_declarado'), 2),
                    'diferencia' => round((float) $dayRows->sum('diferencia'), 2),
                ];
            })
            ->values();

        return response()->json([
            'ok' => true,
            'mes' => $month,
            'rango' => ['from' => $from, 'to' => $to],
            'resumen' => [
                'dias' => $groupedDays->count(),
                'arqueos' => $rows->count(),
                'cantidadVentas' => (int) $rows->sum('cantidad_ventas'),
                'montoTotal' => round((float) $rows->sum('monto_total'), 2),
                'montoDeclarado' => round((float) $rows->sum('monto_cierre_declarado'), 2),
                'diferencia' => round((float) $rows->sum('diferencia'), 2),
            ],
            'dias' => $groupedDays,
        ]);
    }

    public function reporteDiario(Request $request)
    {
        $validated = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'codigoSucursal' => ['nullable', 'integer', 'min:0'],
            'puntoVenta' => ['nullable', 'integer', 'min:0'],
        ]);

        $fecha = (string) ($validated['fecha'] ?? now()->toDateString());
        $base = CajaDiaria::query()->whereDate('fecha_operacion', $fecha);

        if (array_key_exists('codigoSucursal', $validated)) {
            $base->where('codigo_sucursal', (int) $validated['codigoSucursal']);
        }
        if (array_key_exists('puntoVenta', $validated)) {
            $base->where('punto_venta', (int) $validated['puntoVenta']);
        }

        $cajas = (clone $base)->orderBy('codigo_sucursal')->orderBy('punto_venta')->orderBy('usuario_nombre')->get();

        $porCajero = $cajas->map(function (CajaDiaria $caja) {
            return [
                'usuarioId' => $caja->usuario_id,
                'usuarioNombre' => $caja->usuario_nombre,
                'codigoSucursal' => (int) $caja->codigo_sucursal,
                'puntoVenta' => (int) $caja->punto_venta,
                'estado' => $caja->estado,
                'montoApertura' => (float) $caja->monto_apertura,
                'montoVentas' => (float) $caja->monto_ventas,
                'montoCierreDeclarado' => (float) ($caja->monto_cierre_declarado ?? 0),
                'diferencia' => (float) ($caja->diferencia ?? 0),
                'cantidadVentas' => (int) $caja->cantidad_ventas,
                'abiertaEn' => optional($caja->abierta_en)->format('Y-m-d H:i:s'),
                'cerradaEn' => optional($caja->cerrada_en)->format('Y-m-d H:i:s'),
            ];
        })->values();

        $porSucursal = $cajas
            ->groupBy(fn (CajaDiaria $caja) => $caja->codigo_sucursal . '-' . $caja->punto_venta)
            ->map(function ($rows) {
                /** @var \Illuminate\Support\Collection $rows */
                $first = $rows->first();
                return [
                    'codigoSucursal' => (int) $first->codigo_sucursal,
                    'puntoVenta' => (int) $first->punto_venta,
                    'cajas' => $rows->count(),
                    'abiertas' => (int) $rows->where('estado', 'ABIERTA')->count(),
                    'cerradas' => (int) $rows->where('estado', 'CERRADA')->count(),
                    'montoApertura' => round((float) $rows->sum('monto_apertura'), 2),
                    'montoVentas' => round((float) $rows->sum('monto_ventas'), 2),
                    'montoCierreDeclarado' => round((float) $rows->sum(fn ($r) => (float) ($r->monto_cierre_declarado ?? 0)), 2),
                    'diferencia' => round((float) $rows->sum(fn ($r) => (float) ($r->diferencia ?? 0)), 2),
                    'cantidadVentas' => (int) $rows->sum('cantidad_ventas'),
                ];
            })
            ->values();

        return response()->json([
            'ok' => true,
            'fecha' => $fecha,
            'resumen' => [
                'cajas' => $cajas->count(),
                'abiertas' => (int) $cajas->where('estado', 'ABIERTA')->count(),
                'cerradas' => (int) $cajas->where('estado', 'CERRADA')->count(),
                'montoApertura' => round((float) $cajas->sum('monto_apertura'), 2),
                'montoVentas' => round((float) $cajas->sum('monto_ventas'), 2),
                'montoCierreDeclarado' => round((float) $cajas->sum(fn ($r) => (float) ($r->monto_cierre_declarado ?? 0)), 2),
                'diferencia' => round((float) $cajas->sum(fn ($r) => (float) ($r->diferencia ?? 0)), 2),
                'cantidadVentas' => (int) $cajas->sum('cantidad_ventas'),
            ],
            'porCajero' => $porCajero,
            'porSucursal' => $porSucursal,
        ]);
    }

    private function resolveActor(Request $request): array
    {
        $authUser = Auth::guard('api')->user() ?? $request->user();
        if ($authUser) {
            return [
                (string) $authUser->id,
                trim((string) ($authUser->name ?? $authUser->nombre ?? '')),
                strtolower(trim((string) ($authUser->email ?? ''))),
            ];
        }

        $usuarioId = trim((string) $request->input('origen_usuario_id', ''));
        if ($usuarioId === '') {
            throw ValidationException::withMessages([
                'origen_usuario_id' => ['Debe enviar el origen_usuario_id para operar caja sin sesion JWT.'],
            ]);
        }

        return [
            $usuarioId,
            trim((string) $request->input('origen_usuario_nombre', '')),
            strtolower(trim((string) $request->input('origen_usuario_email', ''))),
        ];
    }

    private function ventasDelDia(string $usuarioId, string $fecha, int $codigoSucursal, int $puntoVenta): array
    {
        $query = DB::table('ventas')
            ->where('estado', 1)
            ->whereDate('created_at', $fecha)
            ->where(function ($sub) use ($usuarioId) {
                $sub->where('origen_usuario_id', $usuarioId)
                    ->orWhereRaw('cast(origen_usuario_id as text) = ?', [$usuarioId]);
            })
            ->where('codigoSucursal', $codigoSucursal)
            ->where('puntoVenta', $puntoVenta);

        $row = $query
            ->selectRaw('count(*) as cantidad, coalesce(sum(total), 0) as total')
            ->first();

        return [
            (float) ($row->total ?? 0),
            (int) ($row->cantidad ?? 0),
        ];
    }

    private function ventasDelDiaRows(string $usuarioId, string $fecha, int $codigoSucursal, int $puntoVenta)
    {
        return DB::table('ventas')
            ->where('estado', 1)
            ->whereDate('created_at', $fecha)
            ->where(function ($sub) use ($usuarioId) {
                $sub->where('origen_usuario_id', $usuarioId)
                    ->orWhereRaw('cast(origen_usuario_id as text) = ?', [$usuarioId]);
            })
            ->where('codigoSucursal', $codigoSucursal)
            ->where('puntoVenta', $puntoVenta)
            ->orderBy('id')
            ->get();
    }

    private function registrarArqueo(CajaDiaria $caja, $ventas): void
    {
        if (!Schema::hasTable('caja_arqueos') || !Schema::hasTable('caja_arqueo_ventas')) {
            return;
        }

        $now = now();
        $arqueoPayload = [
            'caja_diaria_id' => (int) $caja->id,
            'usuario_id' => (string) $caja->usuario_id,
            'usuario_nombre' => (string) ($caja->usuario_nombre ?? ''),
            'usuario_email' => (string) ($caja->usuario_email ?? ''),
            'codigo_sucursal' => (int) $caja->codigo_sucursal,
            'punto_venta' => (int) $caja->punto_venta,
            'fecha_operacion' => (string) optional($caja->fecha_operacion)->format('Y-m-d'),
            'estado' => 'ARQUEADO',
            'cantidad_ventas' => (int) $caja->cantidad_ventas,
            'monto_total' => round((float) $caja->monto_ventas, 2),
            'monto_cierre_declarado' => round((float) ($caja->monto_cierre_declarado ?? 0), 2),
            'diferencia' => round((float) ($caja->diferencia ?? 0), 2),
            'cerrado_en' => $caja->cerrada_en ?? $now,
            'observacion' => (string) ($caja->observacion_cierre ?? ''),
            'updated_at' => $now,
        ];

        $existingArqueo = DB::table('caja_arqueos')
            ->where('caja_diaria_id', (int) $caja->id)
            ->first();

        if ($existingArqueo) {
            DB::table('caja_arqueos')
                ->where('id', (int) $existingArqueo->id)
                ->update($arqueoPayload);
            $arqueoId = (int) $existingArqueo->id;
        } else {
            $arqueoPayload['created_at'] = $now;
            $arqueoId = (int) DB::table('caja_arqueos')->insertGetId($arqueoPayload);
        }

        DB::table('caja_arqueo_ventas')->where('arqueo_id', $arqueoId)->delete();

        $ventaIds = collect($ventas)->pluck('id')->map(fn ($id) => (int) $id)->filter()->values()->all();
        $detalleRows = collect();
        if (!empty($ventaIds) && Schema::hasTable('detalle_ventas')) {
            $detalleRows = DB::table('detalle_ventas')
                ->whereIn('venta_id', $ventaIds)
                ->orderBy('id')
                ->get()
                ->groupBy('venta_id');
        }

        $rowsToInsert = collect($ventas)->map(function ($venta) use ($arqueoId, $detalleRows, $now) {
            $ventaId = (int) ($venta->id ?? 0);
            return [
                'arqueo_id' => $arqueoId,
                'venta_id' => $ventaId > 0 ? $ventaId : null,
                'codigo_orden' => (string) ($venta->codigoOrden ?? ''),
                'codigo_seguimiento' => (string) ($venta->codigoSeguimiento ?? ''),
                'estado_sufe' => (string) ($venta->estado_sufe ?? ''),
                'numero_factura' => (string) ($venta->numero_factura ?? ''),
                'total' => round((float) ($venta->total ?? 0), 2),
                'payload' => json_encode([
                    'venta' => (array) $venta,
                    'detalle' => collect($detalleRows->get($ventaId, []))->map(fn ($d) => (array) $d)->values()->all(),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->values()->all();

        if (!empty($rowsToInsert)) {
            DB::table('caja_arqueo_ventas')->insert($rowsToInsert);
        }

        if (!empty($ventaIds) && Schema::hasColumn('ventas', 'estado_caja')) {
            $updatePayload = [
                'estado_caja' => 'ARQUEADO',
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('ventas', 'arqueado_en')) {
                $updatePayload['arqueado_en'] = $now;
            }

            DB::table('ventas')
                ->whereIn('id', $ventaIds)
                ->update($updatePayload);
        }
    }

    private function closePendingCajas(string $usuarioId, string $fechaActual): int
    {
        $pendientes = CajaDiaria::query()
            ->where('usuario_id', $usuarioId)
            ->where('estado', 'ABIERTA')
            ->whereDate('fecha_operacion', '<', $fechaActual)
            ->orderBy('fecha_operacion')
            ->get();

        if ($pendientes->isEmpty()) {
            return 0;
        }

        $closed = 0;
        foreach ($pendientes as $cajaPendiente) {
            try {
                [$totalVentas] = $this->ventasDelDia(
                    (string) $cajaPendiente->usuario_id,
                    (string) $cajaPendiente->fecha_operacion,
                    (int) $cajaPendiente->codigo_sucursal,
                    (int) $cajaPendiente->punto_venta
                );

                $this->closeCajaDiariaWithArqueo(
                    $cajaPendiente,
                    round((float) $totalVentas, 2),
                    'Cierre automático por cambio de día.'
                );
                $closed++;
            } catch (\Throwable) {
                // Continúa con las demás cajas pendientes.
            }
        }

        return $closed;
    }

    private function closeCajaDiariaWithArqueo(CajaDiaria $caja, float $montoDeclarado, ?string $observacion = null): CajaDiaria
    {
        [$totalVentas, $cantidadVentas] = $this->ventasDelDia(
            (string) $caja->usuario_id,
            (string) $caja->fecha_operacion,
            (int) $caja->codigo_sucursal,
            (int) $caja->punto_venta
        );
        $diferencia = round($montoDeclarado - (float) $totalVentas, 2);

        DB::transaction(function () use ($caja, $montoDeclarado, $totalVentas, $cantidadVentas, $diferencia, $observacion) {
            $caja->update([
                'estado' => 'CERRADA',
                'monto_cierre_declarado' => round($montoDeclarado, 2),
                'monto_ventas' => round((float) $totalVentas, 2),
                'cantidad_ventas' => (int) $cantidadVentas,
                'diferencia' => $diferencia,
                'observacion_cierre' => $observacion,
                'cerrada_en' => now(),
            ]);

            $ventas = $this->ventasDelDiaRows(
                (string) $caja->usuario_id,
                (string) $caja->fecha_operacion,
                (int) $caja->codigo_sucursal,
                (int) $caja->punto_venta
            );

            $this->registrarArqueo($caja->fresh(), $ventas);
        });

        return $caja->fresh();
    }

    private function cajaPayload(CajaDiaria $caja): array
    {
        return [
            'id' => (int) $caja->id,
            'usuarioId' => $caja->usuario_id,
            'usuarioNombre' => $caja->usuario_nombre,
            'usuarioEmail' => $caja->usuario_email,
            'codigoSucursal' => (int) $caja->codigo_sucursal,
            'puntoVenta' => (int) $caja->punto_venta,
            'fechaOperacion' => optional($caja->fecha_operacion)->format('Y-m-d'),
            'estado' => $caja->estado,
            'montoApertura' => (float) $caja->monto_apertura,
            'montoVentas' => (float) $caja->monto_ventas,
            'montoCierreDeclarado' => (float) ($caja->monto_cierre_declarado ?? 0),
            'diferencia' => (float) ($caja->diferencia ?? 0),
            'cantidadVentas' => (int) $caja->cantidad_ventas,
            'observacionApertura' => $caja->observacion_apertura,
            'observacionCierre' => $caja->observacion_cierre,
            'abiertaEn' => optional($caja->abierta_en)->format('Y-m-d H:i:s'),
            'cerradaEn' => optional($caja->cerrada_en)->format('Y-m-d H:i:s'),
        ];
    }

    private function formatDateTimeValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($raw)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $raw;
        }
    }
}
