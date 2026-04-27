<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FichaPostalStockService
{
    public function snapshotSucursal(array $context): array
    {
        if (!$this->isBranchEnabled()) {
            return $this->emptyBranchSnapshot($context);
        }

        $row = DB::table('ficha_postal_sucursal_saldos')
            ->where('codigo_sucursal', (int) $context['codigo_sucursal'])
            ->where('punto_venta', (int) $context['punto_venta'])
            ->first();

        if (!$row) {
            return $this->emptyBranchSnapshot($context);
        }

        return [
            'id' => (int) $row->id,
            'codigoSucursal' => (int) $row->codigo_sucursal,
            'puntoVenta' => (int) $row->punto_venta,
            'sucursalNombre' => (string) ($row->sucursal_nombre ?? ''),
            'cantidadDisponible' => (int) ($row->cantidad_disponible ?? 0),
            'montoDisponible' => round((float) ($row->monto_disponible ?? 0), 2),
            'valorUnitarioReferencia' => $row->valor_unitario_referencia !== null ? round((float) $row->valor_unitario_referencia, 2) : null,
            'ultimoAbastecimientoEn' => $this->formatDateTime($row->ultimo_abastecimiento_en ?? null),
            'ultimaTransferenciaEn' => $this->formatDateTime($row->ultima_transferencia_en ?? null),
            'observacion' => (string) ($row->observacion ?? ''),
        ];
    }

    public function inferConsumptionFromPayload(array $payload): array
    {
        $data = (array) data_get($payload, 'fichasPostales', []);
        $cantidad = max(0, (int) ($data['cantidad'] ?? 0));
        $monto = round((float) ($data['montoTotal'] ?? $data['monto'] ?? 0), 2);
        $valorUnitario = $this->normalizeNullablePositiveFloat($data['valorUnitario'] ?? null);

        if ($monto <= 0) {
            $monto = round((float) ($payload['montoTotal'] ?? 0), 2);
        }

        if ($cantidad <= 0 && $valorUnitario !== null && $monto > 0) {
            $estimado = $monto / $valorUnitario;
            if (abs($estimado - round($estimado)) < 0.00001) {
                $cantidad = (int) round($estimado);
            }
        }

        return [
            'cantidad' => $cantidad,
            'montoTotal' => max(0, $monto),
            'valorUnitario' => $valorUnitario,
            'detalle' => is_array($data['detalle'] ?? null) ? $data['detalle'] : [],
            'observacion' => trim((string) ($data['observacion'] ?? '')),
        ];
    }

    public function snapshot(array $context): array
    {
        if (!$this->isEnabled()) {
            return $this->emptySnapshot($context);
        }

        $row = DB::table('ficha_postal_saldos')
            ->where('usuario_id', (string) $context['usuario_id'])
            ->where('codigo_sucursal', (int) $context['codigo_sucursal'])
            ->where('punto_venta', (int) $context['punto_venta'])
            ->first();

        if (!$row) {
            return $this->emptySnapshot($context);
        }

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
            'ultimaAsignacionEn' => $this->formatDateTime($row->ultima_asignacion_en ?? null),
            'ultimoConsumoEn' => $this->formatDateTime($row->ultimo_consumo_en ?? null),
            'observacion' => (string) ($row->observacion ?? ''),
        ];
    }

    public function syncOpeningSaldo(array $context, int $cantidad, float $monto, ?float $valorUnitario = null, ?string $observacion = null, array $reference = []): array
    {
        $snapshot = $this->snapshot($context);
        $deltaCantidad = $cantidad - (int) ($snapshot['cantidadDisponible'] ?? 0);
        $deltaMonto = round($monto - (float) ($snapshot['montoDisponible'] ?? 0), 2);

        if ($deltaCantidad === 0 && $deltaMonto === 0.0) {
            return $snapshot;
        }

        return $this->applyMovement($context, 'AJUSTE_APERTURA', $deltaCantidad, $deltaMonto, [
            'valor_unitario' => $valorUnitario,
            'observacion' => $observacion ?: 'Ajuste de saldo al abrir caja.',
            'referencia' => $reference,
        ]);
    }

    public function syncClosingSaldo(array $context, int $cantidad, float $monto, ?float $valorUnitario = null, ?string $observacion = null, array $reference = []): array
    {
        $snapshot = $this->snapshot($context);
        $deltaCantidad = $cantidad - (int) ($snapshot['cantidadDisponible'] ?? 0);
        $deltaMonto = round($monto - (float) ($snapshot['montoDisponible'] ?? 0), 2);

        if ($deltaCantidad === 0 && $deltaMonto === 0.0) {
            return $snapshot;
        }

        return $this->applyMovement($context, 'AJUSTE_CIERRE', $deltaCantidad, $deltaMonto, [
            'valor_unitario' => $valorUnitario,
            'observacion' => $observacion ?: 'Ajuste de saldo al cerrar caja.',
            'referencia' => $reference,
        ]);
    }

    public function addStock(array $context, string $tipoMovimiento, int $cantidad, float $monto, ?float $valorUnitario = null, ?string $observacion = null, array $reference = []): array
    {
        return $this->applyMovement($context, $tipoMovimiento, max(0, $cantidad), max(0, round($monto, 2)), [
            'valor_unitario' => $valorUnitario,
            'observacion' => $observacion,
            'referencia' => $reference,
        ]);
    }

    public function abastecerSucursal(array $context, string $tipoMovimiento, int $cantidad, float $monto, ?float $valorUnitario = null, ?string $observacion = null, array $reference = []): array
    {
        return $this->applyBranchMovement($context, $tipoMovimiento, max(0, $cantidad), max(0, round($monto, 2)), [
            'valor_unitario' => $valorUnitario,
            'observacion' => $observacion,
            'referencia' => $reference,
        ]);
    }

    public function transferirSucursalACajero(array $branchContext, array $cashierContext, int $cantidad, float $monto, ?float $valorUnitario = null, ?string $observacion = null, array $reference = []): array
    {
        $cantidad = max(0, $cantidad);
        $monto = max(0, round($monto, 2));
        if ($cantidad <= 0 && $monto <= 0) {
            return [
                'sucursal' => $this->snapshotSucursal($branchContext),
                'cajero' => $this->snapshot($cashierContext),
            ];
        }

        $branchSnapshot = $this->snapshotSucursal($branchContext);
        if (($branchSnapshot['cantidadDisponible'] ?? 0) < $cantidad || (float) ($branchSnapshot['montoDisponible'] ?? 0) + 0.00001 < $monto) {
            throw new \RuntimeException('La sucursal no tiene suficientes fichas postales para transferir a la cajera.');
        }

        $referencia = array_merge($reference, [
            'transferencia_a_usuario_id' => (string) ($cashierContext['usuario_id'] ?? ''),
            'transferencia_a_usuario_nombre' => (string) ($cashierContext['usuario_nombre'] ?? ''),
        ]);

        $sucursal = $this->applyBranchMovement($branchContext, 'TRANSFERENCIA_SALIDA', -$cantidad, -$monto, [
            'valor_unitario' => $valorUnitario,
            'observacion' => $observacion ?: 'Transferencia de fichas desde sucursal a cajera.',
            'referencia' => $referencia,
        ]);

        $cajero = $this->applyMovement($cashierContext, 'TRANSFERENCIA_ENTRADA', $cantidad, $monto, [
            'valor_unitario' => $valorUnitario,
            'observacion' => $observacion ?: 'Transferencia de fichas recibida desde la sucursal.',
            'referencia' => array_merge($reference, [
                'transferencia_desde_sucursal' => [
                    'codigoSucursal' => (int) ($branchContext['codigo_sucursal'] ?? 0),
                    'puntoVenta' => (int) ($branchContext['punto_venta'] ?? 0),
                    'sucursalNombre' => (string) ($branchContext['sucursal_nombre'] ?? ''),
                ],
            ]),
        ]);

        return [
            'sucursal' => $sucursal,
            'cajero' => $cajero,
        ];
    }

    public function consume(array $context, array $consumo, array $reference = []): array
    {
        return $this->applyMovement(
            $context,
            'CONSUMO',
            -max(0, (int) ($consumo['cantidad'] ?? 0)),
            -max(0, round((float) ($consumo['montoTotal'] ?? 0), 2)),
            [
                'valor_unitario' => $this->normalizeNullablePositiveFloat($consumo['valorUnitario'] ?? null),
                'observacion' => trim((string) ($consumo['observacion'] ?? '')) ?: 'Consumo de fichas postales por venta.',
                'referencia' => array_merge($reference, [
                    'detalle' => is_array($consumo['detalle'] ?? null) ? $consumo['detalle'] : [],
                ]),
            ]
        );
    }

    public function movimientos(array $context, int $limit = 20): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        return DB::table('ficha_postal_movimientos')
            ->where('usuario_id', (string) $context['usuario_id'])
            ->where('codigo_sucursal', (int) $context['codigo_sucursal'])
            ->where('punto_venta', (int) $context['punto_venta'])
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'tipoMovimiento' => (string) $row->tipo_movimiento,
                    'cantidadDelta' => (int) ($row->cantidad_delta ?? 0),
                    'montoDelta' => round((float) ($row->monto_delta ?? 0), 2),
                    'cantidadActual' => (int) ($row->cantidad_actual ?? 0),
                    'montoActual' => round((float) ($row->monto_actual ?? 0), 2),
                    'valorUnitario' => $row->valor_unitario !== null ? round((float) $row->valor_unitario, 2) : null,
                    'observacion' => (string) ($row->observacion ?? ''),
                    'referencia' => $this->decodeJson($row->referencia ?? null),
                    'createdAt' => $this->formatDateTime($row->created_at ?? null),
                ];
            })
            ->values()
            ->all();
    }

    public function movimientosSucursal(array $context, int $limit = 20): array
    {
        if (!$this->isBranchEnabled()) {
            return [];
        }

        return DB::table('ficha_postal_sucursal_movimientos')
            ->where('codigo_sucursal', (int) $context['codigo_sucursal'])
            ->where('punto_venta', (int) $context['punto_venta'])
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get()
            ->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'tipoMovimiento' => (string) $row->tipo_movimiento,
                    'cantidadDelta' => (int) ($row->cantidad_delta ?? 0),
                    'montoDelta' => round((float) ($row->monto_delta ?? 0), 2),
                    'cantidadActual' => (int) ($row->cantidad_actual ?? 0),
                    'montoActual' => round((float) ($row->monto_actual ?? 0), 2),
                    'valorUnitario' => $row->valor_unitario !== null ? round((float) $row->valor_unitario, 2) : null,
                    'observacion' => (string) ($row->observacion ?? ''),
                    'referencia' => $this->decodeJson($row->referencia ?? null),
                    'createdAt' => $this->formatDateTime($row->created_at ?? null),
                ];
            })
            ->values()
            ->all();
    }

    public function isEnabled(): bool
    {
        return Schema::hasTable('ficha_postal_saldos') && Schema::hasTable('ficha_postal_movimientos');
    }

    public function isBranchEnabled(): bool
    {
        return Schema::hasTable('ficha_postal_sucursal_saldos') && Schema::hasTable('ficha_postal_sucursal_movimientos');
    }

    private function applyMovement(array $context, string $tipoMovimiento, int $cantidadDelta, float $montoDelta, array $options = []): array
    {
        if (!$this->isEnabled()) {
            return $this->emptySnapshot($context);
        }

        $saldo = $this->findOrCreateSaldo($context);
        $cantidadAnterior = (int) ($saldo->cantidad_disponible ?? 0);
        $montoAnterior = round((float) ($saldo->monto_disponible ?? 0), 2);
        $cantidadActual = $cantidadAnterior + $cantidadDelta;
        $montoActual = round($montoAnterior + $montoDelta, 2);
        $valorUnitario = $this->resolveValorUnitario(
            $options['valor_unitario'] ?? null,
            $cantidadDelta,
            $montoDelta,
            $saldo->valor_unitario_referencia ?? null
        );
        $now = now();

        DB::table('ficha_postal_saldos')
            ->where('id', (int) $saldo->id)
            ->update([
                'usuario_nombre' => (string) ($context['usuario_nombre'] ?? ''),
                'usuario_email' => (string) ($context['usuario_email'] ?? ''),
                'cantidad_disponible' => $cantidadActual,
                'monto_disponible' => $montoActual,
                'valor_unitario_referencia' => $valorUnitario,
                'ultima_asignacion_en' => $cantidadDelta > 0 || $montoDelta > 0 ? $now : $saldo->ultima_asignacion_en,
                'ultimo_consumo_en' => $cantidadDelta < 0 || $montoDelta < 0 ? $now : $saldo->ultimo_consumo_en,
                'observacion' => $options['observacion'] ?? ($saldo->observacion ?? null),
                'updated_at' => $now,
            ]);

        DB::table('ficha_postal_movimientos')->insert([
            'saldo_id' => (int) $saldo->id,
            'caja_diaria_id' => $options['caja_diaria_id'] ?? null,
            'venta_id' => $options['venta_id'] ?? null,
            'usuario_id' => (string) $context['usuario_id'],
            'usuario_nombre' => (string) ($context['usuario_nombre'] ?? ''),
            'usuario_email' => (string) ($context['usuario_email'] ?? ''),
            'codigo_sucursal' => (int) $context['codigo_sucursal'],
            'punto_venta' => (int) $context['punto_venta'],
            'tipo_movimiento' => $tipoMovimiento,
            'cantidad_delta' => $cantidadDelta,
            'monto_delta' => $montoDelta,
            'cantidad_anterior' => $cantidadAnterior,
            'monto_anterior' => $montoAnterior,
            'cantidad_actual' => $cantidadActual,
            'monto_actual' => $montoActual,
            'valor_unitario' => $valorUnitario,
            'observacion' => $options['observacion'] ?? null,
            'referencia' => json_encode($options['referencia'] ?? [], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->snapshot($context);
    }

    private function applyBranchMovement(array $context, string $tipoMovimiento, int $cantidadDelta, float $montoDelta, array $options = []): array
    {
        if (!$this->isBranchEnabled()) {
            return $this->emptyBranchSnapshot($context);
        }

        $saldo = $this->findOrCreateBranchSaldo($context);
        $cantidadAnterior = (int) ($saldo->cantidad_disponible ?? 0);
        $montoAnterior = round((float) ($saldo->monto_disponible ?? 0), 2);
        $cantidadActual = $cantidadAnterior + $cantidadDelta;
        $montoActual = round($montoAnterior + $montoDelta, 2);
        $valorUnitario = $this->resolveValorUnitario(
            $options['valor_unitario'] ?? null,
            $cantidadDelta,
            $montoDelta,
            $saldo->valor_unitario_referencia ?? null
        );
        $now = now();

        DB::table('ficha_postal_sucursal_saldos')
            ->where('id', (int) $saldo->id)
            ->update([
                'sucursal_nombre' => (string) ($context['sucursal_nombre'] ?? ''),
                'cantidad_disponible' => $cantidadActual,
                'monto_disponible' => $montoActual,
                'valor_unitario_referencia' => $valorUnitario,
                'ultimo_abastecimiento_en' => $cantidadDelta > 0 || $montoDelta > 0 ? $now : $saldo->ultimo_abastecimiento_en,
                'ultima_transferencia_en' => $cantidadDelta < 0 || $montoDelta < 0 ? $now : $saldo->ultima_transferencia_en,
                'observacion' => $options['observacion'] ?? ($saldo->observacion ?? null),
                'updated_at' => $now,
            ]);

        DB::table('ficha_postal_sucursal_movimientos')->insert([
            'saldo_sucursal_id' => (int) $saldo->id,
            'codigo_sucursal' => (int) $context['codigo_sucursal'],
            'punto_venta' => (int) $context['punto_venta'],
            'sucursal_nombre' => (string) ($context['sucursal_nombre'] ?? ''),
            'tipo_movimiento' => $tipoMovimiento,
            'cantidad_delta' => $cantidadDelta,
            'monto_delta' => $montoDelta,
            'cantidad_anterior' => $cantidadAnterior,
            'monto_anterior' => $montoAnterior,
            'cantidad_actual' => $cantidadActual,
            'monto_actual' => $montoActual,
            'valor_unitario' => $valorUnitario,
            'observacion' => $options['observacion'] ?? null,
            'referencia' => json_encode($options['referencia'] ?? [], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->snapshotSucursal($context);
    }

    private function findOrCreateSaldo(array $context): object
    {
        $saldo = DB::table('ficha_postal_saldos')
            ->where('usuario_id', (string) $context['usuario_id'])
            ->where('codigo_sucursal', (int) $context['codigo_sucursal'])
            ->where('punto_venta', (int) $context['punto_venta'])
            ->first();

        if ($saldo) {
            return $saldo;
        }

        $now = now();
        $id = DB::table('ficha_postal_saldos')->insertGetId([
            'usuario_id' => (string) $context['usuario_id'],
            'usuario_nombre' => (string) ($context['usuario_nombre'] ?? ''),
            'usuario_email' => (string) ($context['usuario_email'] ?? ''),
            'codigo_sucursal' => (int) $context['codigo_sucursal'],
            'punto_venta' => (int) $context['punto_venta'],
            'cantidad_disponible' => 0,
            'monto_disponible' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('ficha_postal_saldos')->where('id', $id)->first();
    }

    private function findOrCreateBranchSaldo(array $context): object
    {
        $saldo = DB::table('ficha_postal_sucursal_saldos')
            ->where('codigo_sucursal', (int) $context['codigo_sucursal'])
            ->where('punto_venta', (int) $context['punto_venta'])
            ->first();

        if ($saldo) {
            return $saldo;
        }

        $now = now();
        $id = DB::table('ficha_postal_sucursal_saldos')->insertGetId([
            'codigo_sucursal' => (int) $context['codigo_sucursal'],
            'punto_venta' => (int) $context['punto_venta'],
            'sucursal_nombre' => (string) ($context['sucursal_nombre'] ?? ''),
            'cantidad_disponible' => 0,
            'monto_disponible' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('ficha_postal_sucursal_saldos')->where('id', $id)->first();
    }

    private function resolveValorUnitario(mixed $provided, int $cantidadDelta, float $montoDelta, mixed $fallback): ?float
    {
        $direct = $this->normalizeNullablePositiveFloat($provided);
        if ($direct !== null) {
            return $direct;
        }

        if ($cantidadDelta !== 0 && abs($montoDelta) > 0) {
            return round(abs($montoDelta) / abs($cantidadDelta), 2);
        }

        $fallbackValue = $this->normalizeNullablePositiveFloat($fallback);
        return $fallbackValue;
    }

    private function normalizeNullablePositiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $number = round((float) $value, 2);
        return $number > 0 ? $number : null;
    }

    private function emptySnapshot(array $context): array
    {
        return [
            'id' => null,
            'usuarioId' => (string) ($context['usuario_id'] ?? ''),
            'usuarioNombre' => (string) ($context['usuario_nombre'] ?? ''),
            'usuarioEmail' => (string) ($context['usuario_email'] ?? ''),
            'codigoSucursal' => (int) ($context['codigo_sucursal'] ?? 0),
            'puntoVenta' => (int) ($context['punto_venta'] ?? 0),
            'cantidadDisponible' => 0,
            'montoDisponible' => 0.0,
            'valorUnitarioReferencia' => null,
            'ultimaAsignacionEn' => null,
            'ultimoConsumoEn' => null,
            'observacion' => '',
        ];
    }

    private function emptyBranchSnapshot(array $context): array
    {
        return [
            'id' => null,
            'codigoSucursal' => (int) ($context['codigo_sucursal'] ?? 0),
            'puntoVenta' => (int) ($context['punto_venta'] ?? 0),
            'sucursalNombre' => (string) ($context['sucursal_nombre'] ?? ''),
            'cantidadDisponible' => 0,
            'montoDisponible' => 0.0,
            'valorUnitarioReferencia' => null,
            'ultimoAbastecimientoEn' => null,
            'ultimaTransferenciaEn' => null,
            'observacion' => '',
        ];
    }

    private function formatDateTime(mixed $value): ?string
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

    private function decodeJson(mixed $value): array
    {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
