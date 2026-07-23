<?php

namespace App\Http\Controllers;

use App\Models\CajaDiaria;
use App\Support\FichaPostalStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class CajaDiariaController extends Controller
{
    public function __construct(
        private readonly FichaPostalStockService $fichaPostalStockService
    ) {
    }

    public function estado(Request $request)
    {
        [$usuarioId, $usuarioNombre, $usuarioEmail] = $this->resolveActor($request);
        $validated = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
            'codigoSucursal' => ['nullable', 'integer', 'min:0'],
            'puntoVenta' => ['nullable', 'integer', 'min:0'],
        ]);
        $fecha = (string) ($validated['fecha'] ?? now()->toDateString());
        $autoClosed = $this->closePendingCajas($usuarioId, $fecha);

        $cajaQuery = CajaDiaria::query()
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_operacion', $fecha);

        if (array_key_exists('codigoSucursal', $validated) && $validated['codigoSucursal'] !== null) {
            $cajaQuery->where('codigo_sucursal', (int) $validated['codigoSucursal']);
        }

        if (array_key_exists('puntoVenta', $validated) && $validated['puntoVenta'] !== null) {
            $cajaQuery->where('punto_venta', (int) $validated['puntoVenta']);
        }

        $caja = $cajaQuery->first();
        if ($caja) {
            $caja = $this->syncCajaTotals($caja);
        }

        $stock = $this->fichaPostalStockService->snapshot([
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuarioNombre,
            'usuario_email' => $usuarioEmail,
            'codigo_sucursal' => (int) ($caja->codigo_sucursal ?? ($validated['codigoSucursal'] ?? 0)),
            'punto_venta' => (int) ($caja->punto_venta ?? ($validated['puntoVenta'] ?? 0)),
        ]);
        $stockSucursal = $this->fichaPostalStockService->snapshotSucursal([
            'codigo_sucursal' => (int) ($caja->codigo_sucursal ?? ($validated['codigoSucursal'] ?? 0)),
            'punto_venta' => (int) ($caja->punto_venta ?? ($validated['puntoVenta'] ?? 0)),
            'sucursal_nombre' => '',
        ]);

        return response()->json([
            'ok' => true,
            'usuario' => [
                'id' => $usuarioId,
                'nombre' => $usuarioNombre,
                'email' => $usuarioEmail,
            ],
            'fecha' => $fecha,
            'caja' => $caja ? $this->cajaPayload($caja) : null,
            'stockFichas' => $stock,
            'stockFichasSucursal' => $stockSucursal,
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
            'cantidadFichasApertura' => ['nullable', 'integer', 'min:0'],
            'montoFichasApertura' => ['nullable', 'numeric', 'min:0'],
            'valorUnitarioFicha' => ['nullable', 'numeric', 'gt:0'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);
        $fecha = (string) ($validated['fecha'] ?? now()->toDateString());
        $existente = CajaDiaria::query()
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_operacion', $fecha)
            ->where('codigo_sucursal', (int) $validated['codigoSucursal'])
            ->where('punto_venta', (int) $validated['puntoVenta'])
            ->first();

        $context = [
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuarioNombre,
            'usuario_email' => $usuarioEmail,
            'codigo_sucursal' => (int) $validated['codigoSucursal'],
            'punto_venta' => (int) $validated['puntoVenta'],
        ];
        $stockActual = $this->fichaPostalStockService->snapshot($context);
        [$cantidadFichasApertura, $montoFichasApertura] = $this->resolveOpeningFichas($validated, $stockActual);
        $valorUnitarioFicha = $this->resolveValorUnitarioFicha(
            $validated['valorUnitarioFicha'] ?? null,
            $cantidadFichasApertura,
            $montoFichasApertura,
            $stockActual['valorUnitarioReferencia'] ?? null
        );

        $montoApertura = round((float) ($validated['montoApertura'] ?? 0), 2);

        if ($existente && $existente->estado === 'ABIERTA') {
            return response()->json([
                'ok' => true,
                'message' => 'La caja ya se encontraba abierta para esta sucursal.',
                'caja' => $this->cajaPayload($existente),
                'stockFichas' => $stockActual,
            ]);
        }

        if ($existente && $existente->estado === 'CERRADA') {
            $stock = $this->fichaPostalStockService->syncOpeningSaldo(
                $context,
                $cantidadFichasApertura,
                $montoFichasApertura,
                $valorUnitarioFicha,
                isset($validated['observacion']) ? trim((string) $validated['observacion']) : null,
                ['fecha' => $fecha, 'reapertura' => true]
            );
            $existente->update($this->filterCajaDiariaColumns([
                'usuario_nombre' => $usuarioNombre,
                'usuario_email' => $usuarioEmail,
                'estado' => 'ABIERTA',
                'monto_apertura' => $montoApertura,
                'monto_cierre_declarado' => null,
                'monto_cierre_esperado' => $montoApertura,
                'monto_ventas' => 0,
                'cantidad_ventas' => 0,
                'cantidad_fichas_apertura' => (int) ($stock['cantidadDisponible'] ?? $cantidadFichasApertura),
                'monto_fichas_apertura' => round((float) ($stock['montoDisponible'] ?? $montoFichasApertura), 2),
                'cantidad_fichas_cierre_esperado' => (int) ($stock['cantidadDisponible'] ?? $cantidadFichasApertura),
                'monto_fichas_cierre_esperado' => round((float) ($stock['montoDisponible'] ?? $montoFichasApertura), 2),
                'cantidad_fichas_cierre_declarado' => null,
                'monto_fichas_cierre_declarado' => null,
                'diferencia_efectivo' => 0,
                'diferencia_fichas' => 0,
                'diferencia_cantidad_fichas' => 0,
                'diferencia' => 0,
                'observacion_apertura' => isset($validated['observacion']) ? trim((string) $validated['observacion']) : $existente->observacion_apertura,
                'observacion_cierre' => null,
                'abierta_en' => now(),
                'cerrada_en' => null,
            ]));

            return response()->json([
                'ok' => true,
                'message' => 'Caja reabierta correctamente.',
                'caja' => $this->cajaPayload($existente->fresh()),
                'stockFichas' => $stock,
            ], 200);
        }

        $stock = $this->fichaPostalStockService->syncOpeningSaldo(
            $context,
            $cantidadFichasApertura,
            $montoFichasApertura,
            $valorUnitarioFicha,
            isset($validated['observacion']) ? trim((string) $validated['observacion']) : null,
            ['fecha' => $fecha]
        );

        $caja = CajaDiaria::query()->create($this->filterCajaDiariaColumns([
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuarioNombre,
            'usuario_email' => $usuarioEmail,
            'codigo_sucursal' => (int) $validated['codigoSucursal'],
            'punto_venta' => (int) $validated['puntoVenta'],
            'fecha_operacion' => $fecha,
            'estado' => 'ABIERTA',
            'monto_apertura' => $montoApertura,
            'monto_cierre_esperado' => $montoApertura,
            'monto_ventas' => 0,
            'cantidad_ventas' => 0,
            'cantidad_fichas_apertura' => (int) ($stock['cantidadDisponible'] ?? $cantidadFichasApertura),
            'monto_fichas_apertura' => round((float) ($stock['montoDisponible'] ?? $montoFichasApertura), 2),
            'cantidad_fichas_ingresadas' => 0,
            'monto_fichas_ingresadas' => 0,
            'cantidad_fichas_consumidas' => 0,
            'monto_fichas_consumidas' => 0,
            'cantidad_fichas_cierre_esperado' => (int) ($stock['cantidadDisponible'] ?? $cantidadFichasApertura),
            'monto_fichas_cierre_esperado' => round((float) ($stock['montoDisponible'] ?? $montoFichasApertura), 2),
            'observacion_apertura' => isset($validated['observacion']) ? trim((string) $validated['observacion']) : null,
            'abierta_en' => now(),
        ]));

        return response()->json([
            'ok' => true,
            'message' => 'Caja abierta correctamente.',
            'caja' => $this->cajaPayload($caja),
            'stockFichas' => $stock,
        ], 201);
    }

    private function filterCajaDiariaColumns(array $payload): array
    {
        static $columns = null;

        if ($columns === null) {
            $columns = array_flip(Schema::getColumnListing('cajas_diarias'));
        }

        return array_filter(
            $payload,
            static fn (string $column): bool => isset($columns[$column]),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function cerrar(Request $request)
    {
        [$usuarioId, $usuarioNombre, $usuarioEmail] = $this->resolveActor($request);
        $validated = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
            'codigoSucursal' => ['nullable', 'integer', 'min:0'],
            'puntoVenta' => ['nullable', 'integer', 'min:0'],
            'montoCierreDeclarado' => ['nullable', 'numeric', 'min:0'],
            'monto_cierre_declarado' => ['nullable', 'numeric', 'min:0'],
            'cantidadFichasCierreDeclarado' => ['nullable', 'integer', 'min:0'],
            'montoFichasCierreDeclarado' => ['nullable', 'numeric', 'min:0'],
            'valorUnitarioFicha' => ['nullable', 'numeric', 'gt:0'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $montoDeclaradoRaw = $validated['montoCierreDeclarado'] ?? $validated['monto_cierre_declarado'] ?? null;
        if ($montoDeclaradoRaw === null || $montoDeclaradoRaw === '') {
            throw ValidationException::withMessages([
                'monto_cierre_declarado' => ['The monto cierre declarado field is required.'],
            ]);
        }

        $fecha = (string) ($validated['fecha'] ?? now()->toDateString());
        $cajaQuery = CajaDiaria::query()
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_operacion', $fecha);

        if (array_key_exists('codigoSucursal', $validated) && $validated['codigoSucursal'] !== null) {
            $cajaQuery->where('codigo_sucursal', (int) $validated['codigoSucursal']);
        }

        if (array_key_exists('puntoVenta', $validated) && $validated['puntoVenta'] !== null) {
            $cajaQuery->where('punto_venta', (int) $validated['puntoVenta']);
        }

        $caja = $cajaQuery->first();

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

        $montoDeclarado = round((float) $montoDeclaradoRaw, 2);
        $observacion = isset($validated['observacion']) ? trim((string) $validated['observacion']) : null;
        $caja = $this->closeCajaDiariaWithArqueo(
            $caja,
            $montoDeclarado,
            $observacion,
            array_key_exists('cantidadFichasCierreDeclarado', $validated) ? (int) $validated['cantidadFichasCierreDeclarado'] : null,
            array_key_exists('montoFichasCierreDeclarado', $validated) ? round((float) $validated['montoFichasCierreDeclarado'], 2) : null,
            isset($validated['valorUnitarioFicha']) ? round((float) $validated['valorUnitarioFicha'], 2) : null
        );
        $stock = $this->fichaPostalStockService->snapshot([
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuarioNombre,
            'usuario_email' => $usuarioEmail,
            'codigo_sucursal' => (int) $caja->codigo_sucursal,
            'punto_venta' => (int) $caja->punto_venta,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Caja cerrada correctamente.',
            'caja' => $this->cajaPayload($caja),
            'stockFichas' => $stock,
        ]);
    }

    public function fichasStock(Request $request)
    {
        [$usuarioId, $usuarioNombre, $usuarioEmail] = $this->resolveActor($request);
        $validated = $request->validate([
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
            'codigoSucursal' => ['nullable', 'integer', 'min:0'],
            'puntoVenta' => ['nullable', 'integer', 'min:0'],
            'sucursalNombre' => ['nullable', 'string', 'max:255'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $context = [
            'usuario_id' => $usuarioId,
            'usuario_nombre' => $usuarioNombre,
            'usuario_email' => $usuarioEmail,
            'codigo_sucursal' => (int) ($validated['codigoSucursal'] ?? 0),
            'punto_venta' => (int) ($validated['puntoVenta'] ?? 0),
        ];

        return response()->json([
            'ok' => true,
            'stock' => $this->fichaPostalStockService->snapshot($context),
            'movimientos' => $this->fichaPostalStockService->movimientos($context, (int) ($validated['limite'] ?? 20)),
            'stockSucursal' => $this->fichaPostalStockService->snapshotSucursal([
                'codigo_sucursal' => (int) ($validated['codigoSucursal'] ?? 0),
                'punto_venta' => (int) ($validated['puntoVenta'] ?? 0),
                'sucursal_nombre' => trim((string) ($validated['sucursalNombre'] ?? '')),
            ]),
            'movimientosSucursal' => $this->fichaPostalStockService->movimientosSucursal([
                'codigo_sucursal' => (int) ($validated['codigoSucursal'] ?? 0),
                'punto_venta' => (int) ($validated['puntoVenta'] ?? 0),
                'sucursal_nombre' => trim((string) ($validated['sucursalNombre'] ?? '')),
            ], (int) ($validated['limite'] ?? 20)),
        ]);
    }

    public function sucursalStock(Request $request)
    {
        $validated = $request->validate([
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
            'sucursalNombre' => ['nullable', 'string', 'max:255'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $context = [
            'codigo_sucursal' => (int) $validated['codigoSucursal'],
            'punto_venta' => (int) $validated['puntoVenta'],
            'sucursal_nombre' => trim((string) ($validated['sucursalNombre'] ?? '')),
        ];

        return response()->json([
            'ok' => true,
            'stockSucursal' => $this->fichaPostalStockService->snapshotSucursal($context),
            'movimientosSucursal' => $this->fichaPostalStockService->movimientosSucursal($context, (int) ($validated['limite'] ?? 20)),
        ]);
    }

    public function fichasCajerosSaldos(Request $request)
    {
        $validated = $request->validate([
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
        ]);

        if (!Schema::hasTable('ficha_postal_saldos')) {
            return response()->json([
                'ok' => true,
                'resumen' => [
                    'cajeras' => 0,
                    'cantidadDisponible' => 0,
                    'montoDisponible' => 0.0,
                ],
                'cajeras' => [],
            ]);
        }

        $rows = DB::table('ficha_postal_saldos')
            ->where('codigo_sucursal', (int) $validated['codigoSucursal'])
            ->where('punto_venta', (int) $validated['puntoVenta'])
            ->orderByDesc('monto_disponible')
            ->orderBy('usuario_nombre')
            ->get();

        $cajeras = $rows->map(function ($row) {
            return [
                'id' => (int) $row->id,
                'usuarioId' => (string) $row->usuario_id,
                'usuarioNombre' => (string) ($row->usuario_nombre ?? ''),
                'usuarioEmail' => (string) ($row->usuario_email ?? ''),
                'codigoSucursal' => (int) $row->codigo_sucursal,
                'puntoVenta' => (int) $row->punto_venta,
                'cantidadDisponible' => (int) ($row->cantidad_disponible ?? 0),
                'montoDisponible' => round((float) ($row->monto_disponible ?? 0), 2),
                'valorUnitarioReferencia' => $row->valor_unitario_referencia !== null ? round((float) $row->valor_unitario_referencia, 2) : null,
                'ultimaAsignacionEn' => $this->formatDateTimeValue($row->ultima_asignacion_en ?? null),
                'ultimoConsumoEn' => $this->formatDateTimeValue($row->ultimo_consumo_en ?? null),
                'observacion' => (string) ($row->observacion ?? ''),
            ];
        })->values();

        return response()->json([
            'ok' => true,
            'resumen' => [
                'cajeras' => $cajeras->count(),
                'cantidadDisponible' => (int) $rows->sum('cantidad_disponible'),
                'montoDisponible' => round((float) $rows->sum('monto_disponible'), 2),
            ],
            'cajeras' => $cajeras,
        ]);
    }

    public function abastecerSucursal(Request $request)
    {
        $validated = $request->validate([
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
            'sucursalNombre' => ['nullable', 'string', 'max:255'],
            'tipoMovimiento' => ['nullable', 'in:ABASTECIMIENTO_SUCURSAL,AJUSTE_SUCURSAL'],
            'cantidadFichas' => ['nullable', 'integer', 'min:0'],
            'montoFichas' => ['nullable', 'numeric', 'min:0'],
            'valorUnitarioFicha' => ['nullable', 'numeric', 'gt:0'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $cantidad = (int) ($validated['cantidadFichas'] ?? 0);
        $monto = round((float) ($validated['montoFichas'] ?? 0), 2);
        if ($cantidad <= 0 && $monto <= 0) {
            throw ValidationException::withMessages([
                'cantidadFichas' => ['Debe enviar cantidadFichas o montoFichas para abastecer la sucursal.'],
            ]);
        }

        $context = [
            'codigo_sucursal' => (int) $validated['codigoSucursal'],
            'punto_venta' => (int) $validated['puntoVenta'],
            'sucursal_nombre' => trim((string) ($validated['sucursalNombre'] ?? '')),
        ];

        $stockSucursal = $this->fichaPostalStockService->abastecerSucursal(
            $context,
            (string) ($validated['tipoMovimiento'] ?? 'ABASTECIMIENTO_SUCURSAL'),
            $cantidad,
            $monto,
            isset($validated['valorUnitarioFicha']) ? round((float) $validated['valorUnitarioFicha'], 2) : null,
            isset($validated['observacion']) ? trim((string) $validated['observacion']) : null,
            ['origen' => 'sucursal']
        );

        return response()->json([
            'ok' => true,
            'message' => 'Stock de fichas postales de sucursal actualizado correctamente.',
            'stockSucursal' => $stockSucursal,
        ]);
    }

    public function asignarFichas(Request $request)
    {
        [$actorId, $actorNombre, $actorEmail] = $this->resolveActor($request);
        $validated = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
            'destinoUsuarioId' => ['nullable', 'string', 'max:100'],
            'destinoUsuarioNombre' => ['nullable', 'string', 'max:255'],
            'destinoUsuarioEmail' => ['nullable', 'string', 'max:120'],
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
            'sucursalNombre' => ['nullable', 'string', 'max:255'],
            'tipoMovimiento' => ['nullable', 'in:ASIGNACION,REPOSICION'],
            'cantidadFichas' => ['nullable', 'integer', 'min:0'],
            'montoFichas' => ['nullable', 'numeric', 'min:0'],
            'valorUnitarioFicha' => ['nullable', 'numeric', 'gt:0'],
            'afectarStockSucursal' => ['nullable', 'boolean'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $cantidad = (int) ($validated['cantidadFichas'] ?? 0);
        $monto = round((float) ($validated['montoFichas'] ?? 0), 2);
        if ($cantidad <= 0 && $monto <= 0) {
            throw ValidationException::withMessages([
                'cantidadFichas' => ['Debe enviar cantidadFichas o montoFichas para asignar fichas postales.'],
            ]);
        }

        $targetUsuarioId = trim((string) ($validated['destinoUsuarioId'] ?? $actorId));
        $targetUsuarioNombre = trim((string) ($validated['destinoUsuarioNombre'] ?? $actorNombre));
        $targetUsuarioEmail = strtolower(trim((string) ($validated['destinoUsuarioEmail'] ?? $actorEmail)));

        $context = [
            'usuario_id' => $targetUsuarioId,
            'usuario_nombre' => $targetUsuarioNombre,
            'usuario_email' => $targetUsuarioEmail,
            'codigo_sucursal' => (int) $validated['codigoSucursal'],
            'punto_venta' => (int) $validated['puntoVenta'],
        ];
        $tipoMovimiento = (string) ($validated['tipoMovimiento'] ?? 'REPOSICION');
        $valorUnitario = isset($validated['valorUnitarioFicha']) ? round((float) $validated['valorUnitarioFicha'], 2) : null;
        $afectarStockSucursal = array_key_exists('afectarStockSucursal', $validated)
            ? (bool) $validated['afectarStockSucursal']
            : true;

        if ($afectarStockSucursal) {
            $transferencia = $this->fichaPostalStockService->transferirSucursalACajero(
                [
                    'codigo_sucursal' => (int) $validated['codigoSucursal'],
                    'punto_venta' => (int) $validated['puntoVenta'],
                    'sucursal_nombre' => trim((string) ($validated['sucursalNombre'] ?? '')),
                ],
                $context,
                $cantidad,
                $monto,
                $valorUnitario,
                isset($validated['observacion']) ? trim((string) $validated['observacion']) : null,
                [
                    'fecha' => (string) ($validated['fecha'] ?? now()->toDateString()),
                    'asignado_por' => [
                        'id' => $actorId,
                        'nombre' => $actorNombre,
                        'email' => $actorEmail,
                    ],
                ]
            );
            $stock = $transferencia['cajero'];
            $stockSucursal = $transferencia['sucursal'];
        } else {
            $stock = $this->fichaPostalStockService->addStock(
                $context,
                $tipoMovimiento,
                $cantidad,
                $monto,
                $valorUnitario,
                isset($validated['observacion']) ? trim((string) $validated['observacion']) : null,
                [
                    'fecha' => (string) ($validated['fecha'] ?? now()->toDateString()),
                    'asignado_por' => [
                        'id' => $actorId,
                        'nombre' => $actorNombre,
                        'email' => $actorEmail,
                    ],
                ]
            );
            $stockSucursal = $this->fichaPostalStockService->snapshotSucursal([
                'codigo_sucursal' => (int) $validated['codigoSucursal'],
                'punto_venta' => (int) $validated['puntoVenta'],
                'sucursal_nombre' => trim((string) ($validated['sucursalNombre'] ?? '')),
            ]);
        }

        $fecha = (string) ($validated['fecha'] ?? now()->toDateString());
        $caja = CajaDiaria::query()
            ->where('usuario_id', $targetUsuarioId)
            ->whereDate('fecha_operacion', $fecha)
            ->where('codigo_sucursal', (int) $validated['codigoSucursal'])
            ->where('punto_venta', (int) $validated['puntoVenta'])
            ->where('estado', 'ABIERTA')
            ->first();

        if ($caja && Schema::hasColumn('cajas_diarias', 'cantidad_fichas_ingresadas')) {
            $caja->update([
                'cantidad_fichas_ingresadas' => (int) ($caja->cantidad_fichas_ingresadas ?? 0) + $cantidad,
                'monto_fichas_ingresadas' => round((float) ($caja->monto_fichas_ingresadas ?? 0) + $monto, 2),
                'cantidad_fichas_cierre_esperado' => (int) ($caja->cantidad_fichas_cierre_esperado ?? 0) + $cantidad,
                'monto_fichas_cierre_esperado' => round((float) ($caja->monto_fichas_cierre_esperado ?? 0) + $monto, 2),
            ]);
            $caja = $caja->fresh();
        }

        return response()->json([
            'ok' => true,
            'message' => $tipoMovimiento === 'ASIGNACION'
                ? 'Fichas postales asignadas correctamente.'
                : 'Reposición de fichas postales registrada correctamente.',
            'stock' => $stock,
            'stockSucursal' => $stockSucursal,
            'destino' => [
                'usuarioId' => $targetUsuarioId,
                'usuarioNombre' => $targetUsuarioNombre,
                'usuarioEmail' => $targetUsuarioEmail,
            ],
            'caja' => $caja ? $this->cajaPayload($caja) : null,
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
                            'montoApertura' => (float) ($row->monto_apertura ?? 0),
                            'montoTotal' => (float) $row->monto_total,
                            'montoCierreEsperado' => (float) ($row->monto_cierre_esperado ?? 0),
                            'montoDeclarado' => (float) $row->monto_cierre_declarado,
                            'cantidadFichasApertura' => (int) ($row->cantidad_fichas_apertura ?? 0),
                            'montoFichasApertura' => (float) ($row->monto_fichas_apertura ?? 0),
                            'cantidadFichasIngresadas' => (int) ($row->cantidad_fichas_ingresadas ?? 0),
                            'montoFichasIngresadas' => (float) ($row->monto_fichas_ingresadas ?? 0),
                            'cantidadFichasConsumidas' => (int) ($row->cantidad_fichas_consumidas ?? 0),
                            'montoFichasConsumidas' => (float) ($row->monto_fichas_consumidas ?? 0),
                            'cantidadFichasCierreEsperado' => (int) ($row->cantidad_fichas_cierre_esperado ?? 0),
                            'montoFichasCierreEsperado' => (float) ($row->monto_fichas_cierre_esperado ?? 0),
                            'cantidadFichasCierreDeclarado' => (int) ($row->cantidad_fichas_cierre_declarado ?? 0),
                            'montoFichasCierreDeclarado' => (float) ($row->monto_fichas_cierre_declarado ?? 0),
                            'diferenciaEfectivo' => (float) ($row->diferencia_efectivo ?? 0),
                            'diferenciaFichas' => (float) ($row->diferencia_fichas ?? 0),
                            'diferenciaCantidadFichas' => (int) ($row->diferencia_cantidad_fichas ?? 0),
                            'diferencia' => (float) $row->diferencia,
                            'cerradoEn' => $this->formatDateTimeValue($row->cerrado_en ?? null),
                        ];
                    })->values(),
                    'cantidadVentas' => (int) $dayRows->sum('cantidad_ventas'),
                    'montoApertura' => round((float) $dayRows->sum('monto_apertura'), 2),
                    'montoTotal' => round((float) $dayRows->sum('monto_total'), 2),
                    'montoCierreEsperado' => round((float) $dayRows->sum('monto_cierre_esperado'), 2),
                    'montoDeclarado' => round((float) $dayRows->sum('monto_cierre_declarado'), 2),
                    'cantidadFichasConsumidas' => (int) $dayRows->sum('cantidad_fichas_consumidas'),
                    'montoFichasConsumidas' => round((float) $dayRows->sum('monto_fichas_consumidas'), 2),
                    'cantidadFichasCierreEsperado' => (int) $dayRows->sum('cantidad_fichas_cierre_esperado'),
                    'montoFichasCierreEsperado' => round((float) $dayRows->sum('monto_fichas_cierre_esperado'), 2),
                    'cantidadFichasCierreDeclarado' => (int) $dayRows->sum('cantidad_fichas_cierre_declarado'),
                    'montoFichasCierreDeclarado' => round((float) $dayRows->sum('monto_fichas_cierre_declarado'), 2),
                    'diferenciaEfectivo' => round((float) $dayRows->sum('diferencia_efectivo'), 2),
                    'diferenciaFichas' => round((float) $dayRows->sum('diferencia_fichas'), 2),
                    'diferenciaCantidadFichas' => (int) $dayRows->sum('diferencia_cantidad_fichas'),
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
                'montoApertura' => round((float) $rows->sum('monto_apertura'), 2),
                'montoTotal' => round((float) $rows->sum('monto_total'), 2),
                'montoCierreEsperado' => round((float) $rows->sum('monto_cierre_esperado'), 2),
                'montoDeclarado' => round((float) $rows->sum('monto_cierre_declarado'), 2),
                'cantidadFichasConsumidas' => (int) $rows->sum('cantidad_fichas_consumidas'),
                'montoFichasConsumidas' => round((float) $rows->sum('monto_fichas_consumidas'), 2),
                'cantidadFichasCierreEsperado' => (int) $rows->sum('cantidad_fichas_cierre_esperado'),
                'montoFichasCierreEsperado' => round((float) $rows->sum('monto_fichas_cierre_esperado'), 2),
                'cantidadFichasCierreDeclarado' => (int) $rows->sum('cantidad_fichas_cierre_declarado'),
                'montoFichasCierreDeclarado' => round((float) $rows->sum('monto_fichas_cierre_declarado'), 2),
                'diferenciaEfectivo' => round((float) $rows->sum('diferencia_efectivo'), 2),
                'diferenciaFichas' => round((float) $rows->sum('diferencia_fichas'), 2),
                'diferenciaCantidadFichas' => (int) $rows->sum('diferencia_cantidad_fichas'),
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
                    'montoCierreEsperado' => (float) ($caja->monto_cierre_esperado ?? 0),
                    'montoCierreDeclarado' => (float) ($caja->monto_cierre_declarado ?? 0),
                    'cantidadFichasApertura' => (int) ($caja->cantidad_fichas_apertura ?? 0),
                    'montoFichasApertura' => (float) ($caja->monto_fichas_apertura ?? 0),
                    'cantidadFichasIngresadas' => (int) ($caja->cantidad_fichas_ingresadas ?? 0),
                    'montoFichasIngresadas' => (float) ($caja->monto_fichas_ingresadas ?? 0),
                    'cantidadFichasConsumidas' => (int) ($caja->cantidad_fichas_consumidas ?? 0),
                    'montoFichasConsumidas' => (float) ($caja->monto_fichas_consumidas ?? 0),
                    'cantidadFichasCierreEsperado' => (int) ($caja->cantidad_fichas_cierre_esperado ?? 0),
                    'montoFichasCierreEsperado' => (float) ($caja->monto_fichas_cierre_esperado ?? 0),
                    'cantidadFichasCierreDeclarado' => (int) ($caja->cantidad_fichas_cierre_declarado ?? 0),
                    'montoFichasCierreDeclarado' => (float) ($caja->monto_fichas_cierre_declarado ?? 0),
                    'diferenciaEfectivo' => (float) ($caja->diferencia_efectivo ?? 0),
                    'diferenciaFichas' => (float) ($caja->diferencia_fichas ?? 0),
                    'diferenciaCantidadFichas' => (int) ($caja->diferencia_cantidad_fichas ?? 0),
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
                    'montoCierreEsperado' => round((float) $rows->sum(fn ($r) => (float) ($r->monto_cierre_esperado ?? 0)), 2),
                    'montoCierreDeclarado' => round((float) $rows->sum(fn ($r) => (float) ($r->monto_cierre_declarado ?? 0)), 2),
                    'cantidadFichasConsumidas' => (int) $rows->sum(fn ($r) => (int) ($r->cantidad_fichas_consumidas ?? 0)),
                    'montoFichasConsumidas' => round((float) $rows->sum(fn ($r) => (float) ($r->monto_fichas_consumidas ?? 0)), 2),
                    'cantidadFichasCierreEsperado' => (int) $rows->sum(fn ($r) => (int) ($r->cantidad_fichas_cierre_esperado ?? 0)),
                    'montoFichasCierreEsperado' => round((float) $rows->sum(fn ($r) => (float) ($r->monto_fichas_cierre_esperado ?? 0)), 2),
                    'cantidadFichasCierreDeclarado' => (int) $rows->sum(fn ($r) => (int) ($r->cantidad_fichas_cierre_declarado ?? 0)),
                    'montoFichasCierreDeclarado' => round((float) $rows->sum(fn ($r) => (float) ($r->monto_fichas_cierre_declarado ?? 0)), 2),
                    'diferenciaEfectivo' => round((float) $rows->sum(fn ($r) => (float) ($r->diferencia_efectivo ?? 0)), 2),
                    'diferenciaFichas' => round((float) $rows->sum(fn ($r) => (float) ($r->diferencia_fichas ?? 0)), 2),
                    'diferenciaCantidadFichas' => (int) $rows->sum(fn ($r) => (int) ($r->diferencia_cantidad_fichas ?? 0)),
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
                'montoCierreEsperado' => round((float) $cajas->sum(fn ($r) => (float) ($r->monto_cierre_esperado ?? 0)), 2),
                'montoCierreDeclarado' => round((float) $cajas->sum(fn ($r) => (float) ($r->monto_cierre_declarado ?? 0)), 2),
                'cantidadFichasConsumidas' => (int) $cajas->sum(fn ($r) => (int) ($r->cantidad_fichas_consumidas ?? 0)),
                'montoFichasConsumidas' => round((float) $cajas->sum(fn ($r) => (float) ($r->monto_fichas_consumidas ?? 0)), 2),
                'cantidadFichasCierreEsperado' => (int) $cajas->sum(fn ($r) => (int) ($r->cantidad_fichas_cierre_esperado ?? 0)),
                'montoFichasCierreEsperado' => round((float) $cajas->sum(fn ($r) => (float) ($r->monto_fichas_cierre_esperado ?? 0)), 2),
                'cantidadFichasCierreDeclarado' => (int) $cajas->sum(fn ($r) => (int) ($r->cantidad_fichas_cierre_declarado ?? 0)),
                'montoFichasCierreDeclarado' => round((float) $cajas->sum(fn ($r) => (float) ($r->monto_fichas_cierre_declarado ?? 0)), 2),
                'diferenciaEfectivo' => round((float) $cajas->sum(fn ($r) => (float) ($r->diferencia_efectivo ?? 0)), 2),
                'diferenciaFichas' => round((float) $cajas->sum(fn ($r) => (float) ($r->diferencia_fichas ?? 0)), 2),
                'diferenciaCantidadFichas' => (int) $cajas->sum(fn ($r) => (int) ($r->diferencia_cantidad_fichas ?? 0)),
                'diferencia' => round((float) $cajas->sum(fn ($r) => (float) ($r->diferencia ?? 0)), 2),
                'cantidadVentas' => (int) $cajas->sum('cantidad_ventas'),
            ],
            'porCajero' => $porCajero,
            'porSucursal' => $porSucursal,
        ]);
    }

    public function conciliaciones(Request $request)
    {
        $validated = $request->validate([
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $fecha = (string) ($validated['fecha'] ?? now()->toDateString());

        if (!Schema::hasTable('cierre_diario_sucursales')) {
            return response()->json([
                'ok' => true,
                'fecha' => $fecha,
                'conciliaciones' => [],
            ]);
        }

        $rows = DB::table('cierre_diario_sucursales')
            ->whereDate('fecha_operacion', $fecha)
            ->orderBy('codigo_sucursal')
            ->orderBy('punto_venta')
            ->get();

        return response()->json([
            'ok' => true,
            'fecha' => $fecha,
            'conciliaciones' => $rows->map(fn ($row) => $this->conciliacionPayload($row))->values(),
        ]);
    }

    public function conciliacionDetalle(Request $request)
    {
        $validated = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['nullable', 'integer', 'min:0'],
        ]);

        $fecha = (string) $validated['fecha'];
        $codigoSucursal = (int) $validated['codigoSucursal'];
        $puntoVenta = (int) ($validated['puntoVenta'] ?? 0);

        $row = $this->findOrCreateConciliacionRow($fecha, $codigoSucursal, $puntoVenta, []);
        $receipts = $this->conciliacionReceipts($row->id);

        return response()->json([
            'ok' => true,
            'fecha' => $fecha,
            'conciliacion' => $this->conciliacionPayload($row),
            'comprobantes' => $receipts,
        ]);
    }

    public function storeConciliacionComprobante(Request $request)
    {
        [$actorId, $actorNombre, $actorEmail] = $this->resolveActor($request);

        $validated = $request->validate([
            'fecha' => ['required', 'date_format:Y-m-d'],
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['nullable', 'integer', 'min:0'],
            'sucursalNombre' => ['nullable', 'string', 'max:180'],
            'totalEfectivoSistema' => ['nullable', 'numeric', 'min:0'],
            'totalQrSistema' => ['nullable', 'numeric', 'min:0'],
            'totalGeneralSistema' => ['nullable', 'numeric', 'min:0'],
            'montoDepositado' => ['required', 'numeric', 'min:0.01'],
            'banco' => ['nullable', 'string', 'max:120'],
            'referencia' => ['nullable', 'string', 'max:120'],
            'observacion' => ['nullable', 'string', 'max:500'],
            'archivo' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,webp'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_nombre' => ['nullable', 'string', 'max:255'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
        ]);

        $fecha = (string) $validated['fecha'];
        $codigoSucursal = (int) $validated['codigoSucursal'];
        $puntoVenta = (int) ($validated['puntoVenta'] ?? 0);

        $row = $this->findOrCreateConciliacionRow($fecha, $codigoSucursal, $puntoVenta, [
            'sucursal_nombre' => trim((string) ($validated['sucursalNombre'] ?? '')) ?: null,
            'total_efectivo_sistema' => round((float) ($validated['totalEfectivoSistema'] ?? 0), 2),
            'total_qr_sistema' => round((float) ($validated['totalQrSistema'] ?? 0), 2),
            'total_general_sistema' => round((float) ($validated['totalGeneralSistema'] ?? 0), 2),
        ]);

        $file = $validated['archivo'];
        $folderRelative = 'uploads/conciliaciones/' . now()->format('Y/m');
        $folder = public_path($folderRelative);
        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin'));
        $filename = 'comp-' . $fecha . '-s' . str_pad((string) $codigoSucursal, 3, '0', STR_PAD_LEFT) . '-pv' . $puntoVenta . '-' . now()->format('His') . '-' . Str::random(8) . '.' . $extension;
        $file->move($folder, $filename);

        DB::table('cierre_diario_comprobantes')->insert([
            'cierre_diario_sucursal_id' => (int) $row->id,
            'fecha_deposito' => $fecha,
            'monto_depositado' => round((float) $validated['montoDepositado'], 2),
            'banco' => trim((string) ($validated['banco'] ?? '')) ?: null,
            'referencia' => trim((string) ($validated['referencia'] ?? '')) ?: null,
            'observacion' => trim((string) ($validated['observacion'] ?? '')) ?: null,
            'archivo_path' => $folderRelative . '/' . $filename,
            'archivo_nombre' => $file->getClientOriginalName(),
            'archivo_mime' => $file->getClientMimeType(),
            'archivo_size' => (int) $file->getSize(),
            'subido_por_user_id' => is_numeric($actorId) ? (int) $actorId : null,
            'subido_por_nombre' => $actorNombre ?: null,
            'subido_por_email' => $actorEmail ?: null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = $this->refreshConciliacionRow((int) $row->id);

        return response()->json([
            'ok' => true,
            'message' => 'Comprobante cargado correctamente.',
            'conciliacion' => $this->conciliacionPayload($row),
            'comprobantes' => $this->conciliacionReceipts((int) $row->id),
        ]);
    }

    public function deleteConciliacionComprobante(Request $request, int $comprobanteId)
    {
        if (!Schema::hasTable('cierre_diario_comprobantes')) {
            return response()->json([
                'ok' => false,
                'message' => 'No existe la estructura de conciliaciones.',
            ], 404);
        }

        $row = DB::table('cierre_diario_comprobantes')->where('id', $comprobanteId)->first();
        if (!$row) {
            return response()->json([
                'ok' => false,
                'message' => 'No se encontró el comprobante solicitado.',
            ], 404);
        }

        $fullPath = public_path((string) $row->archivo_path);
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }

        DB::table('cierre_diario_comprobantes')->where('id', $comprobanteId)->delete();
        $conciliacion = $this->refreshConciliacionRow((int) $row->cierre_diario_sucursal_id);

        return response()->json([
            'ok' => true,
            'message' => 'Comprobante eliminado correctamente.',
            'conciliacion' => $this->conciliacionPayload($conciliacion),
            'comprobantes' => $this->conciliacionReceipts((int) $conciliacion->id),
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
            ->where('puntoVenta', $puntoVenta)
            ->whereRaw("upper(coalesce(estado_sufe, '')) <> 'ANULADA'")
            ->whereRaw("upper(coalesce(estado_sufe, '')) <> 'REGISTRADA_OFICIAL'")
            ->where(function ($sub) {
                $sub->whereNull('metodoPago')
                    ->orWhere('metodoPago', '<>', 5);
            });

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
            ->whereRaw("upper(coalesce(estado_sufe, '')) <> 'ANULADA'")
            ->whereRaw("upper(coalesce(estado_sufe, '')) <> 'REGISTRADA_OFICIAL'")
            ->where(function ($sub) {
                $sub->whereNull('metodoPago')
                    ->orWhere('metodoPago', '<>', 5);
            })
            ->orderBy('id')
            ->get();
    }

    private function findOrCreateConciliacionRow(string $fecha, int $codigoSucursal, int $puntoVenta, array $snapshot): object
    {
        if (!Schema::hasTable('cierre_diario_sucursales')) {
            throw ValidationException::withMessages([
                'conciliacion' => ['No existe la tabla de conciliación diaria.'],
            ]);
        }

        $existing = DB::table('cierre_diario_sucursales')
            ->whereDate('fecha_operacion', $fecha)
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('punto_venta', $puntoVenta)
            ->first();

        if ($existing) {
            $updates = array_filter([
                'sucursal_nombre' => $snapshot['sucursal_nombre'] ?? null,
                'total_efectivo_sistema' => $snapshot['total_efectivo_sistema'] ?? null,
                'total_qr_sistema' => $snapshot['total_qr_sistema'] ?? null,
                'total_general_sistema' => $snapshot['total_general_sistema'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            if ($updates !== []) {
                $updates['updated_at'] = now();
                DB::table('cierre_diario_sucursales')->where('id', (int) $existing->id)->update($updates);
            }

            return $this->refreshConciliacionRow((int) $existing->id);
        }

        $id = DB::table('cierre_diario_sucursales')->insertGetId([
            'fecha_operacion' => $fecha,
            'codigo_sucursal' => $codigoSucursal,
            'punto_venta' => $puntoVenta,
            'sucursal_nombre' => $snapshot['sucursal_nombre'] ?? null,
            'total_efectivo_sistema' => round((float) ($snapshot['total_efectivo_sistema'] ?? 0), 2),
            'total_qr_sistema' => round((float) ($snapshot['total_qr_sistema'] ?? 0), 2),
            'total_general_sistema' => round((float) ($snapshot['total_general_sistema'] ?? 0), 2),
            'total_comprobantes' => 0,
            'diferencia' => 0,
            'estado' => 'sin_comprobante',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->refreshConciliacionRow((int) $id);
    }

    private function refreshConciliacionRow(int $rowId): object
    {
        $row = DB::table('cierre_diario_sucursales')->where('id', $rowId)->first();
        if (!$row) {
            throw ValidationException::withMessages([
                'conciliacion' => ['No se encontró la fila de conciliación diaria.'],
            ]);
        }

        $totalComprobantes = Schema::hasTable('cierre_diario_comprobantes')
            ? round((float) DB::table('cierre_diario_comprobantes')->where('cierre_diario_sucursal_id', $rowId)->sum('monto_depositado'), 2)
            : 0;
        $expected = round((float) ($row->total_efectivo_sistema ?? 0), 2);
        $difference = round($totalComprobantes - $expected, 2);
        $status = $this->resolveConciliacionEstado($expected, $totalComprobantes);

        DB::table('cierre_diario_sucursales')->where('id', $rowId)->update([
            'total_comprobantes' => $totalComprobantes,
            'diferencia' => $difference,
            'estado' => $status,
            'updated_at' => now(),
        ]);

        return DB::table('cierre_diario_sucursales')->where('id', $rowId)->first();
    }

    private function resolveConciliacionEstado(float $expected, float $receipts): string
    {
        if ($receipts <= 0) {
            return 'sin_comprobante';
        }

        if (abs(round($receipts - $expected, 2)) < 0.01) {
            return 'conciliado';
        }

        if ($receipts < $expected) {
            return 'parcial';
        }

        return 'con_diferencia';
    }

    private function conciliacionPayload(object $row): array
    {
        $receiptCount = Schema::hasTable('cierre_diario_comprobantes')
            ? (int) DB::table('cierre_diario_comprobantes')
                ->where('cierre_diario_sucursal_id', (int) $row->id)
                ->count()
            : 0;

        return [
            'id' => (int) $row->id,
            'fecha' => (string) $row->fecha_operacion,
            'codigoSucursal' => (int) $row->codigo_sucursal,
            'puntoVenta' => (int) $row->punto_venta,
            'sucursalNombre' => $row->sucursal_nombre,
            'totalEfectivoSistema' => round((float) ($row->total_efectivo_sistema ?? 0), 2),
            'totalQrSistema' => round((float) ($row->total_qr_sistema ?? 0), 2),
            'totalGeneralSistema' => round((float) ($row->total_general_sistema ?? 0), 2),
            'totalComprobantes' => round((float) ($row->total_comprobantes ?? 0), 2),
            'diferencia' => round((float) ($row->diferencia ?? 0), 2),
            'estado' => (string) ($row->estado ?? 'sin_comprobante'),
            'receiptCount' => $receiptCount,
            'observacionGeneral' => $row->observacion_general,
            'createdAt' => $this->formatDateTimeValue($row->created_at ?? null),
            'updatedAt' => $this->formatDateTimeValue($row->updated_at ?? null),
        ];
    }

    private function conciliacionReceipts(int $rowId): array
    {
        if (!Schema::hasTable('cierre_diario_comprobantes')) {
            return [];
        }

        return DB::table('cierre_diario_comprobantes')
            ->where('cierre_diario_sucursal_id', $rowId)
            ->orderByDesc('fecha_deposito')
            ->orderByDesc('id')
            ->get()
            ->map(function ($receipt) {
                return [
                    'id' => (int) $receipt->id,
                    'fechaDeposito' => (string) $receipt->fecha_deposito,
                    'montoDepositado' => round((float) ($receipt->monto_depositado ?? 0), 2),
                    'banco' => $receipt->banco,
                    'referencia' => $receipt->referencia,
                    'observacion' => $receipt->observacion,
                    'archivoNombre' => $receipt->archivo_nombre,
                    'archivoMime' => $receipt->archivo_mime,
                    'archivoSize' => (int) ($receipt->archivo_size ?? 0),
                    'archivoUrl' => url(str_replace('\\', '/', ltrim((string) $receipt->archivo_path, '/'))),
                    'subidoPorUserId' => $receipt->subido_por_user_id,
                    'subidoPorNombre' => $receipt->subido_por_nombre,
                    'subidoPorEmail' => $receipt->subido_por_email,
                    'validadoPorUserId' => $receipt->validado_por_user_id,
                    'validadoPorNombre' => $receipt->validado_por_nombre,
                    'validadoPorEmail' => $receipt->validado_por_email,
                    'validadoAt' => $this->formatDateTimeValue($receipt->validado_at ?? null),
                    'createdAt' => $this->formatDateTimeValue($receipt->created_at ?? null),
                ];
            })
            ->values()
            ->all();
    }

    private function syncCajaTotals(CajaDiaria $caja): CajaDiaria
    {
        [$totalVentas, $cantidadVentas] = $this->ventasDelDia(
            (string) $caja->usuario_id,
            (string) $caja->fecha_operacion,
            (int) $caja->codigo_sucursal,
            (int) $caja->punto_venta
        );

        $montoVentas = round((float) $totalVentas, 2);
        $cantidad = (int) $cantidadVentas;
        $montoCierreEsperado = round((float) ($caja->monto_apertura ?? 0) + $montoVentas, 2);

        $needsUpdate = round((float) ($caja->monto_ventas ?? 0), 2) !== $montoVentas
            || (int) ($caja->cantidad_ventas ?? 0) !== $cantidad
            || round((float) ($caja->monto_cierre_esperado ?? 0), 2) !== $montoCierreEsperado;

        if (!$needsUpdate) {
            return $caja;
        }

        $caja->update([
            'monto_ventas' => $montoVentas,
            'cantidad_ventas' => $cantidad,
            'monto_cierre_esperado' => $montoCierreEsperado,
        ]);

        return $caja->fresh();
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
            'monto_apertura' => round((float) ($caja->monto_apertura ?? 0), 2),
            'monto_cierre_declarado' => round((float) ($caja->monto_cierre_declarado ?? 0), 2),
            'monto_cierre_esperado' => round((float) ($caja->monto_cierre_esperado ?? 0), 2),
            'cantidad_fichas_apertura' => (int) ($caja->cantidad_fichas_apertura ?? 0),
            'monto_fichas_apertura' => round((float) ($caja->monto_fichas_apertura ?? 0), 2),
            'cantidad_fichas_ingresadas' => (int) ($caja->cantidad_fichas_ingresadas ?? 0),
            'monto_fichas_ingresadas' => round((float) ($caja->monto_fichas_ingresadas ?? 0), 2),
            'cantidad_fichas_consumidas' => (int) ($caja->cantidad_fichas_consumidas ?? 0),
            'monto_fichas_consumidas' => round((float) ($caja->monto_fichas_consumidas ?? 0), 2),
            'cantidad_fichas_cierre_esperado' => (int) ($caja->cantidad_fichas_cierre_esperado ?? 0),
            'monto_fichas_cierre_esperado' => round((float) ($caja->monto_fichas_cierre_esperado ?? 0), 2),
            'cantidad_fichas_cierre_declarado' => (int) ($caja->cantidad_fichas_cierre_declarado ?? 0),
            'monto_fichas_cierre_declarado' => round((float) ($caja->monto_fichas_cierre_declarado ?? 0), 2),
            'diferencia_efectivo' => round((float) ($caja->diferencia_efectivo ?? 0), 2),
            'diferencia_fichas' => round((float) ($caja->diferencia_fichas ?? 0), 2),
            'diferencia_cantidad_fichas' => (int) ($caja->diferencia_cantidad_fichas ?? 0),
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

                $montoEsperado = round((float) ($cajaPendiente->monto_apertura ?? 0) + (float) $totalVentas, 2);
                $this->closeCajaDiariaWithArqueo(
                    $cajaPendiente,
                    $montoEsperado,
                    'Cierre automático por cambio de día.'
                );
                $closed++;
            } catch (\Throwable) {
                // Continúa con las demás cajas pendientes.
            }
        }

        return $closed;
    }

    private function closeCajaDiariaWithArqueo(
        CajaDiaria $caja,
        float $montoDeclarado,
        ?string $observacion = null,
        ?int $cantidadFichasDeclarada = null,
        ?float $montoFichasDeclarado = null,
        ?float $valorUnitarioFicha = null
    ): CajaDiaria
    {
        [$totalVentas, $cantidadVentas] = $this->ventasDelDia(
            (string) $caja->usuario_id,
            (string) $caja->fecha_operacion,
            (int) $caja->codigo_sucursal,
            (int) $caja->punto_venta
        );
        $montoEsperado = round((float) ($caja->monto_apertura ?? 0) + (float) $totalVentas, 2);
        $cantidadEsperada = (int) (($caja->cantidad_fichas_apertura ?? 0) + ($caja->cantidad_fichas_ingresadas ?? 0) - ($caja->cantidad_fichas_consumidas ?? 0));
        $montoFichasEsperado = round((float) (($caja->monto_fichas_apertura ?? 0) + ($caja->monto_fichas_ingresadas ?? 0) - ($caja->monto_fichas_consumidas ?? 0)), 2);
        $cantidadDeclarada = $cantidadFichasDeclarada ?? $cantidadEsperada;
        $montoFichasDeclarado = $montoFichasDeclarado ?? $montoFichasEsperado;
        $diferenciaEfectivo = round($montoDeclarado - $montoEsperado, 2);
        $diferenciaFichas = round($montoFichasDeclarado - $montoFichasEsperado, 2);
        $diferenciaCantidadFichas = $cantidadDeclarada - $cantidadEsperada;
        $diferenciaTotal = round($diferenciaEfectivo + $diferenciaFichas, 2);
        $context = [
            'usuario_id' => (string) $caja->usuario_id,
            'usuario_nombre' => (string) ($caja->usuario_nombre ?? ''),
            'usuario_email' => (string) ($caja->usuario_email ?? ''),
            'codigo_sucursal' => (int) $caja->codigo_sucursal,
            'punto_venta' => (int) $caja->punto_venta,
        ];

        DB::transaction(function () use (
            $caja,
            $montoDeclarado,
            $totalVentas,
            $cantidadVentas,
            $montoEsperado,
            $cantidadEsperada,
            $montoFichasEsperado,
            $cantidadDeclarada,
            $montoFichasDeclarado,
            $diferenciaEfectivo,
            $diferenciaFichas,
            $diferenciaCantidadFichas,
            $diferenciaTotal,
            $observacion,
            $context,
            $valorUnitarioFicha
        ) {
            $caja->update([
                'estado' => 'CERRADA',
                'monto_cierre_declarado' => round($montoDeclarado, 2),
                'monto_cierre_esperado' => round($montoEsperado, 2),
                'monto_ventas' => round((float) $totalVentas, 2),
                'cantidad_ventas' => (int) $cantidadVentas,
                'cantidad_fichas_cierre_esperado' => $cantidadEsperada,
                'monto_fichas_cierre_esperado' => round($montoFichasEsperado, 2),
                'cantidad_fichas_cierre_declarado' => $cantidadDeclarada,
                'monto_fichas_cierre_declarado' => round($montoFichasDeclarado, 2),
                'diferencia_efectivo' => $diferenciaEfectivo,
                'diferencia_fichas' => $diferenciaFichas,
                'diferencia_cantidad_fichas' => $diferenciaCantidadFichas,
                'diferencia' => $diferenciaTotal,
                'observacion_cierre' => $observacion,
                'cerrada_en' => now(),
            ]);

            $this->fichaPostalStockService->syncClosingSaldo(
                $context,
                $cantidadDeclarada,
                $montoFichasDeclarado,
                $valorUnitarioFicha,
                $observacion,
                ['caja_diaria_id' => (int) $caja->id, 'fecha' => (string) $caja->fecha_operacion]
            );

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

    private function resolveOpeningFichas(array $validated, array $stockActual): array
    {
        $cantidad = array_key_exists('cantidadFichasApertura', $validated)
            ? (int) ($validated['cantidadFichasApertura'] ?? 0)
            : (int) ($stockActual['cantidadDisponible'] ?? 0);

        $monto = array_key_exists('montoFichasApertura', $validated)
            ? round((float) ($validated['montoFichasApertura'] ?? 0), 2)
            : round((float) ($stockActual['montoDisponible'] ?? 0), 2);

        $valorUnitario = isset($validated['valorUnitarioFicha']) ? round((float) $validated['valorUnitarioFicha'], 2) : null;
        if ($cantidad > 0 && $monto <= 0 && $valorUnitario !== null && $valorUnitario > 0) {
            $monto = round($cantidad * $valorUnitario, 2);
        }

        if ($monto > 0 && $cantidad <= 0 && $valorUnitario !== null && $valorUnitario > 0) {
            $estimado = $monto / $valorUnitario;
            if (abs($estimado - round($estimado)) < 0.00001) {
                $cantidad = (int) round($estimado);
            }
        }

        return [max(0, $cantidad), max(0, $monto)];
    }

    private function resolveValorUnitarioFicha(mixed $provided, int $cantidad, float $monto, mixed $fallback = null): ?float
    {
        if ($provided !== null && $provided !== '' && is_numeric($provided) && (float) $provided > 0) {
            return round((float) $provided, 2);
        }

        if ($cantidad > 0 && $monto > 0) {
            return round($monto / $cantidad, 2);
        }

        if ($fallback !== null && $fallback !== '' && is_numeric($fallback) && (float) $fallback > 0) {
            return round((float) $fallback, 2);
        }

        return null;
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
            'montoCierreEsperado' => (float) ($caja->monto_cierre_esperado ?? 0),
            'montoCierreDeclarado' => (float) ($caja->monto_cierre_declarado ?? 0),
            'cantidadFichasApertura' => (int) ($caja->cantidad_fichas_apertura ?? 0),
            'montoFichasApertura' => (float) ($caja->monto_fichas_apertura ?? 0),
            'cantidadFichasIngresadas' => (int) ($caja->cantidad_fichas_ingresadas ?? 0),
            'montoFichasIngresadas' => (float) ($caja->monto_fichas_ingresadas ?? 0),
            'cantidadFichasConsumidas' => (int) ($caja->cantidad_fichas_consumidas ?? 0),
            'montoFichasConsumidas' => (float) ($caja->monto_fichas_consumidas ?? 0),
            'cantidadFichasCierreEsperado' => (int) ($caja->cantidad_fichas_cierre_esperado ?? 0),
            'montoFichasCierreEsperado' => (float) ($caja->monto_fichas_cierre_esperado ?? 0),
            'cantidadFichasCierreDeclarado' => (int) ($caja->cantidad_fichas_cierre_declarado ?? 0),
            'montoFichasCierreDeclarado' => (float) ($caja->monto_fichas_cierre_declarado ?? 0),
            'diferenciaEfectivo' => (float) ($caja->diferencia_efectivo ?? 0),
            'diferenciaFichas' => (float) ($caja->diferencia_fichas ?? 0),
            'diferenciaCantidadFichas' => (int) ($caja->diferencia_cantidad_fichas ?? 0),
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
