<?php

namespace App\Http\Controllers;

use App\Models\Notificacione;
use App\Models\Venta;
use App\Support\SufeSectorUnoValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class FacturaVentaApiController extends Controller
{
    private const DEBUG_RESPONSE_QUERY_VALUES = ['1', 'true', 'yes', 'on'];

    public function __construct(
        private readonly SufeSectorUnoValidator $sufeValidator
    ) {
    }

    private function ageticBaseUrl(): string
    {
        return rtrim(config('services.agetic.base_url', 'https://sefe.demo.agetic.gob.bo'), '/');
    }

    private function ageticPublicBaseUrl(): string
    {
        return rtrim(config('services.agetic.public_base_url', 'https://sefe.agetic.gob.bo'), '/');
    }

    private function ageticClient()
    {
        $token = config('services.agetic.token');
        $verify = filter_var(config('services.agetic.verify', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

        return Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->withOptions([
                'force_ip_resolve' => 'v4',
                'verify' => $verify,
                'http_version' => 1.1,
            ])
            ->connectTimeout(20)
            ->timeout(60)
            ->retry(3, 800, function ($exception) {
                return $exception instanceof ConnectionException;
            });
    }

    private function assertFacturaVentaSector(array $payload): void
    {
        if ((int) ($payload['documentoSector'] ?? 0) !== 1) {
            throw ValidationException::withMessages([
                'documentoSector' => ['Este endpoint solo admite factura de compra y venta (documentoSector 1).'],
            ]);
        }
    }

    private function assertCajaAbierta(array $payload): void
    {
        $usuarioId = trim((string) data_get($payload, 'origenUsuario.id', ''));
        if ($usuarioId === '') {
            throw ValidationException::withMessages([
                'origenUsuario.id' => ['Debe enviar el origenUsuario.id para validar apertura de caja.'],
            ]);
        }

        $fecha = now()->toDateString();
        $codigoSucursal = (int) ($payload['codigoSucursal'] ?? 0);
        $puntoVenta = (int) ($payload['puntoVenta'] ?? 0);

        $caja = DB::table('cajas_diarias')
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_operacion', $fecha)
            ->where('estado', 'ABIERTA')
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('punto_venta', $puntoVenta)
            ->first();

        if (!$caja) {
            throw ValidationException::withMessages([
                'caja' => ['La caja del dia no esta abierta para este usuario/sucursal. Abra caja antes de emitir.'],
            ]);
        }
    }

    private function addVentaToCaja(array $payload): void
    {
        $usuarioId = trim((string) data_get($payload, 'origenUsuario.id', ''));
        if ($usuarioId === '' || !DB::getSchemaBuilder()->hasTable('cajas_diarias')) {
            return;
        }

        $metodoPago = (int) ($payload['metodoPago'] ?? 1);
        $origenVentaTipo = strtoupper(trim((string) data_get($payload, 'origenVenta.tipo', '')));
        $isOfficial = $origenVentaTipo === 'OFICIAL';
        $isQr = $metodoPago === 5;
        if ($isOfficial || $isQr) {
            return;
        }

        $fecha = now()->toDateString();
        $codigoSucursal = (int) ($payload['codigoSucursal'] ?? 0);
        $puntoVenta = (int) ($payload['puntoVenta'] ?? 0);
        $montoTotal = round((float) ($payload['montoTotal'] ?? 0), 2);

        $updates = ['updated_at' => now()];

        if (Schema::hasColumn('cajas_diarias', 'monto_ventas')) {
            $updates['monto_ventas'] = DB::raw('coalesce(monto_ventas, 0) + ' . $montoTotal);
        }

        if (Schema::hasColumn('cajas_diarias', 'monto_cierre_esperado')) {
            $updates['monto_cierre_esperado'] = DB::raw('coalesce(monto_cierre_esperado, coalesce(monto_apertura, 0)) + ' . $montoTotal);
        }

        if (Schema::hasColumn('cajas_diarias', 'cantidad_ventas')) {
            $updates['cantidad_ventas'] = DB::raw('coalesce(cantidad_ventas, 0) + 1');
        }

        DB::table('cajas_diarias')
            ->where('usuario_id', $usuarioId)
            ->whereDate('fecha_operacion', $fecha)
            ->where('estado', 'ABIERTA')
            ->where('codigo_sucursal', $codigoSucursal)
            ->where('punto_venta', $puntoVenta)
            ->update($updates);
    }

    private function resolveCodigoOrden(array $payload): string
    {
        $codigoOrdenRecibido = trim((string) ($payload['codigoOrden'] ?? ''));
        $isOficial = strtoupper((string) ($payload['estado_sufe'] ?? '')) === 'REGISTRADA_OFICIAL'
            || strtoupper((string) data_get($payload, 'origenVenta.tipo', '')) === 'OFICIAL';
        $canalEmision = strtolower(trim((string) ($payload['canalEmision'] ?? $payload['canal_emision'] ?? 'factura_electronica')));
        if (!in_array($canalEmision, ['factura_electronica', 'qr'], true)) {
            $canalEmision = 'factura_electronica';
        }

        $nextCodigoOrden = $isOficial
            ? Venta::nextCodigoOrdenOficial()
            : ($canalEmision === 'qr' ? Venta::nextCodigoOrdenQr() : Venta::nextCodigoOrden());
        $codigoOrden = $codigoOrdenRecibido !== ''
            ? $codigoOrdenRecibido
            : $nextCodigoOrden;

        // Normaliza formatos tipo "venta-1", "VENTA-0001", etc. al formato canónico.
        if (preg_match('/^venta-(\d+)$/i', $codigoOrden, $matches)) {
            $codigoOrden = Venta::formatCodigoOrdenFromNumber((int) $matches[1]);
        }
        if (preg_match('/^qv-(\d+)$/i', $codigoOrden, $matches)) {
            $codigoOrden = Venta::formatCodigoOrdenFromNumberWithPrefix(
                (int) $matches[1],
                $canalEmision === 'qr' ? Venta::CODIGO_ORDEN_QR_PREFIX : Venta::CODIGO_ORDEN_PREFIX
            );
        }
        if (preg_match('/^(?:fvc|vfc)-(\d+)$/i', $codigoOrden, $matches)) {
            $codigoOrden = Venta::formatCodigoOrdenFromNumberWithPrefix((int) $matches[1], Venta::CODIGO_ORDEN_PREFIX);
        }
        if (preg_match('/^(?:fqc|vqc)-(\d+)$/i', $codigoOrden, $matches)) {
            $codigoOrden = Venta::formatCodigoOrdenFromNumberWithPrefix((int) $matches[1], Venta::CODIGO_ORDEN_QR_PREFIX);
        }
        if (preg_match('/^ofi-(\d+)$/i', $codigoOrden, $matches)) {
            $codigoOrden = Venta::formatCodigoOrdenFromNumberWithPrefix((int) $matches[1], Venta::CODIGO_ORDEN_OFICIAL_PREFIX);
        }

        $exists = DB::table('ventas')
            ->where('codigoOrden', $codigoOrden)
            ->exists();

        if ($exists) {
            $codigoOrden = $nextCodigoOrden;
        }

        return $codigoOrden;
    }

    private function resolveSucursalContext(array $payload): array
    {
        $codigoSucursal = (int) ($payload['codigoSucursal'] ?? 0);
        $puntoVenta = (int) ($payload['puntoVenta'] ?? 0);
        $sucursalId = trim((string) data_get($payload, 'origenSucursal.id', ''));
        $sucursalCodigo = trim((string) data_get($payload, 'origenSucursal.codigo', ''));
        $sucursalNombre = trim((string) data_get($payload, 'origenSucursal.nombre', ''));
        $municipio = trim((string) ($payload['municipio'] ?? ''));
        $departamento = trim((string) ($payload['departamento'] ?? ''));
        $telefono = $this->normalizeFacturaTelefono($payload['telefono'] ?? null);

        $sucursal = null;
        if (Schema::hasTable('sucursales')) {
            $sucursal = DB::table('sucursales')
                ->select('nombre', 'municipio', 'departamento', 'telefono', 'codigosucursal')
                ->where('codigosucursal', $codigoSucursal)
                ->first();
        }

        if ($sucursalCodigo === '') {
            $sucursalCodigo = (string) $codigoSucursal;
        }

        if ($sucursalId === '') {
            $sucursalId = (string) $puntoVenta;
        }

        if ($sucursalNombre === '') {
            $sucursalNombre = trim((string) ($sucursal->nombre ?? ''));
        }

        if ($sucursalNombre === '') {
            $sucursalNombre = 'Sucursal ' . $codigoSucursal . ' / PV ' . $puntoVenta;
        }

        if ($municipio === '') {
            $municipio = trim((string) ($sucursal->municipio ?? ''));
        }

        if ($departamento === '') {
            $departamento = trim((string) ($sucursal->departamento ?? ''));
        }

        if ($telefono === '2222222') {
            $telefono = $this->normalizeFacturaTelefono($sucursal->telefono ?? null);
        }

        if ($municipio === '') {
            $municipio = 'LA PAZ';
        }

        [$municipio, $departamento] = $this->normalizeFiscalLocation($municipio, $departamento);

        return [
            'id' => $sucursalId,
            'codigo' => $sucursalCodigo,
            'nombre' => $sucursalNombre,
            'municipio' => $municipio,
            'departamento' => $departamento,
            'telefono' => $telefono,
        ];
    }

    private function normalizeFacturaTelefono(mixed $raw): string
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return '2222222';
        }

        $primary = trim((string) preg_split('/[-\/]/', $value)[0]);
        $digits = preg_replace('/\D+/', '', $primary) ?? '';
        if ($digits === '') {
            $digits = preg_replace('/\D+/', '', $value) ?? '';
        }

        if (strlen($digits) > 8) {
            $digits = substr($digits, 0, 8);
        }

        if (strlen($digits) < 7) {
            return '2222222';
        }

        return $digits;
    }

    private function normalizeFiscalLocation(string $municipio, string $departamento): array
    {
        $municipio = mb_strtoupper(trim($municipio));
        $departamento = mb_strtoupper(trim($departamento));

        $aliases = [
            'SANTA CRUZ DE LA SIERRA' => 'SANTA CRUZ',
            'NUESTRA SENORA DE LA PAZ' => 'LA PAZ',
            'LA SANTISIMA TRINIDAD' => 'TRINIDAD',
        ];

        $municipio = $aliases[$municipio] ?? $municipio;
        $departamento = $aliases[$departamento] ?? $departamento;

        if ($departamento !== '' && strcasecmp($municipio, $departamento) !== 0) {
            $combinedLength = mb_strlen($municipio . '-' . $departamento);
            if ($combinedLength > 25) {
                $municipio = $this->shrinkLocationLabel($municipio, 25 - mb_strlen($departamento) - 1);
            }
        }

        return [$municipio, $departamento];
    }

    private function shrinkLocationLabel(string $value, int $limit): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
        if ($limit <= 0 || mb_strlen($value) <= $limit) {
            return $value;
        }

        $replacements = [
            ' DE LA SIERRA' => '',
            ' DE LA' => '',
            ' DEL' => '',
            ' DE' => '',
        ];

        $reduced = $value;
        foreach ($replacements as $search => $replace) {
            $candidate = str_replace($search, $replace, $reduced);
            if (mb_strlen($candidate) <= $limit) {
                return trim(preg_replace('/\s+/', ' ', $candidate) ?? $candidate);
            }
            $reduced = $candidate;
        }

        return trim(mb_substr($reduced, 0, $limit));
    }

    private function createVenta(
        array $payload,
        string $codigoOrden,
        string $codigoSeguimiento,
        string $estadoSufe = 'RECEPCIONADA',
        string $motivo = 'Integracion bolipost'
    ): array
    {
        $now = Date::now();
        $sucursal = $this->resolveSucursalContext($payload);

        $ventaId = DB::table('ventas')->insertGetId([
            'origen_sistema' => 'BOLIPOST',
            'origen_venta_id' => data_get($payload, 'origenVenta.id'),
            'origen_venta_tipo' => data_get($payload, 'origenVenta.tipo'),
            'origen_usuario_id' => data_get($payload, 'origenUsuario.id'),
            'origen_usuario_nombre' => data_get($payload, 'origenUsuario.nombre'),
            'origen_usuario_email' => data_get($payload, 'origenUsuario.email'),
            'origen_usuario_alias' => data_get($payload, 'origenUsuario.alias'),
            'origen_usuario_carnet' => data_get($payload, 'origenUsuario.carnet'),
            'origen_sucursal_id' => $sucursal['id'],
            'origen_sucursal_codigo' => $sucursal['codigo'],
            'origen_sucursal_nombre' => $sucursal['nombre'],
            'codigoSucursal' => (int) $payload['codigoSucursal'],
            'puntoVenta' => (int) $payload['puntoVenta'],
            'documentoSector' => (int) $payload['documentoSector'],
            'municipio' => $sucursal['municipio'],
            'departamento' => null,
            'telefono' => $sucursal['telefono'],
            'codigoCliente' => isset($payload['codigoCliente']) ? (string) $payload['codigoCliente'] : null,
            'razonSocial' => $payload['razonSocial'] ?? null,
            'documentoIdentidad' => $payload['documentoIdentidad'] ?? null,
            'tipoDocumentoIdentidad' => isset($payload['tipoDocumentoIdentidad']) ? (int) $payload['tipoDocumentoIdentidad'] : null,
            'complemento' => (int) $payload['tipoDocumentoIdentidad'] === 1
                ? ($payload['complemento'] ?? null)
                : null,
            'correo' => $payload['correo'] ?? null,
            'metodoPago' => isset($payload['metodoPago']) ? (int) $payload['metodoPago'] : null,
            'formatoFactura' => $payload['formatoFactura'] ?? null,
            'monto_descuento_adicional' => (float) ($payload['montoDescuentoAdicional'] ?? 0),
            'motivo' => $motivo,
            'total' => (float) $payload['montoTotal'],
            'codigoOrden' => $codigoOrden,
            'codigoSeguimiento' => $codigoSeguimiento,
            'estado_sufe' => $estadoSufe,
            'numero_factura' => null,
            'estado' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (Schema::hasColumn('ventas', 'peso_total')) {
            DB::table('ventas')
                ->where('id', $ventaId)
                ->update([
                    'peso_total' => round((float) ($payload['pesoTotal'] ?? 0), 3),
                ]);
        }

        return [
            'id' => (int) $ventaId,
            'codigoOrden' => $codigoOrden,
            'codigoSeguimiento' => $codigoSeguimiento,
        ];
    }

    private function createDetalleVentas(array $venta, array $payload): void
    {
        $now = Date::now();

        foreach ($payload['detalle'] as $detalle) {
            $insert = [
                'venta_id' => $venta['id'],
                'actividadEconomica' => $detalle['actividadEconomica'],
                'codigoSin' => $detalle['codigoSin'],
                'codigo' => $detalle['codigo'],
                'descripcion' => $detalle['descripcion'],
                'unidadMedida' => (int) $detalle['unidadMedida'],
                'precio' => (float) $detalle['precioUnitario'],
                'cantidad' => (float) $detalle['cantidad'],
                'estado' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('detalle_ventas', 'peso')) {
                $insert['peso'] = round((float) ($detalle['peso'] ?? 0), 3);
            }

            DB::table('detalle_ventas')->insert($insert);
        }
    }

    private function createOfficialTrackingCode(): string
    {
        return 'oficial-' . now()->format('YmdHisv') . '-' . bin2hex(random_bytes(4));
    }

    public function registrarOficial(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origenVenta.id' => ['required', 'string', 'max:100'],
            'origenVenta.tipo' => ['required', 'string', 'max:100'],
            'origenUsuario.id' => ['required', 'string', 'max:100'],
            'origenUsuario.nombre' => ['nullable', 'string', 'max:255'],
            'origenUsuario.email' => ['nullable', 'string', 'max:255'],
            'origenUsuario.alias' => ['nullable', 'string', 'max:80'],
            'origenUsuario.carnet' => ['nullable', 'string', 'max:40'],
            'origenSucursal.id' => ['required', 'string', 'max:100'],
            'origenSucursal.codigo' => ['required', 'string', 'max:100'],
            'origenSucursal.nombre' => ['nullable', 'string', 'max:255'],
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
            'documentoSector' => ['required', 'integer', 'min:0'],
            'municipio' => ['nullable', 'string', 'max:120'],
            'departamento' => ['nullable', 'string', 'max:120'],
            'telefono' => ['nullable', 'string', 'max:60'],
            'codigoCliente' => ['nullable', 'string', 'max:50'],
            'razonSocial' => ['nullable', 'string', 'max:255'],
            'documentoIdentidad' => ['nullable', 'string', 'max:80'],
            'tipoDocumentoIdentidad' => ['nullable', 'integer', 'min:1'],
            'complemento' => ['nullable', 'string', 'max:30'],
            'correo' => ['nullable', 'email', 'max:255'],
            'metodoPago' => ['nullable', 'integer', 'min:0'],
            'formatoFactura' => ['nullable', 'string', 'max:30'],
            'montoTotal' => ['required', 'numeric', 'min:0'],
            'pesoTotal' => ['nullable', 'numeric', 'min:0'],
            'detalle' => ['required', 'array', 'min:1'],
            'detalle.*.actividadEconomica' => ['nullable', 'string', 'max:20'],
            'detalle.*.codigoSin' => ['nullable', 'string', 'max:20'],
            'detalle.*.codigo' => ['nullable', 'string', 'max:120'],
            'detalle.*.descripcion' => ['nullable', 'string', 'max:255'],
            'detalle.*.unidadMedida' => ['nullable', 'integer', 'min:1'],
            'detalle.*.precioUnitario' => ['nullable', 'numeric', 'min:0'],
            'detalle.*.peso' => ['nullable', 'numeric', 'min:0'],
            'detalle.*.cantidad' => ['required', 'numeric', 'gt:0'],
        ]);

        $this->assertFacturaVentaSector($validated);
        $this->assertCajaAbierta($validated);

        $validated['estado_sufe'] = 'REGISTRADA_OFICIAL';
        $validated['origenVenta']['tipo'] = 'OFICIAL';
        $codigoOrden = $this->resolveCodigoOrden($validated);
        $codigoSeguimiento = $this->createOfficialTrackingCode();

        $venta = DB::transaction(function () use ($validated, $codigoOrden, $codigoSeguimiento) {
            $venta = $this->createVenta(
                $validated,
                $codigoOrden,
                $codigoSeguimiento,
                'REGISTRADA_OFICIAL',
                'Registro oficial sin facturacion electronica'
            );
            $this->createDetalleVentas($venta, $validated);
            $this->addVentaToCaja($validated);

            return $venta;
        });

        return response()->json([
            'ok' => true,
            'estado' => 'REGISTRADA_OFICIAL',
            'message' => 'Venta OFICIAL registrada correctamente sin pasar por carrito.',
            'venta' => $venta,
        ], 201);
    }

    private function sanitizePayloadForAgetic(array $payload): array
    {
        $clean = $payload;

        unset($clean['origenVenta'], $clean['origenUsuario'], $clean['origenSucursal']);
        unset($clean['fichasPostales']);

        if ((int) ($clean['tipoDocumentoIdentidad'] ?? 0) !== 1 || blank($clean['complemento'] ?? null)) {
            unset($clean['complemento']);
        }

        if (blank($clean['departamento'] ?? null)) {
            unset($clean['departamento']);
        }

        if ((float) ($clean['montoDescuentoAdicional'] ?? 0) <= 0) {
            unset($clean['montoDescuentoAdicional']);
        }

        if (isset($clean['detalle']) && is_array($clean['detalle'])) {
            $clean['detalle'] = collect($clean['detalle'])
                ->map(function ($line) {
                    if (!is_array($line)) {
                        return $line;
                    }

                    if ((float) ($line['montoDescuento'] ?? 0) <= 0) {
                        unset($line['montoDescuento']);
                    }

                    return $line;
                })
                ->values()
                ->all();
        }

        return $clean;
    }

    private function bridgeStatusLabel(?string $status): string
    {
        return match ($status) {
            'PROCESADA' => 'Procesada correctamente',
            'ANULADA' => 'Anulada',
            'ANULACION_SOLICITADA' => 'Anulación solicitada',
            'ANULACION_OBSERVADA' => 'Anulación observada',
            'OBSERVADA' => 'Observada',
            'CONTINGENCIA_CREADA' => 'En contingencia',
            'RECEPCIONADA' => 'Recepcionada por SEFE',
            default => 'Estado desconocido',
        };
    }

    private function bridgeStatusHelp(?string $status): string
    {
        return match ($status) {
            'PROCESADA' => 'La venta fue validada y procesada correctamente por SEFE.',
            'ANULADA' => 'La factura fue anulada correctamente por SEFE.',
            'ANULACION_SOLICITADA' => 'SEFE recepcionó la solicitud de anulación y se espera la notificación final.',
            'ANULACION_OBSERVADA' => 'SEFE observó la solicitud de anulación y la factura conserva su estado previo.',
            'OBSERVADA' => 'SEFE devolvió observaciones y la venta requiere revisión.',
            'CONTINGENCIA_CREADA' => 'SEFE recepcionó la venta, pero SIAT no estaba disponible y la dejó en contingencia.',
            'RECEPCIONADA' => 'SEFE recepcionó la venta y se espera la notificación final del proceso.',
            default => 'No existe suficiente información para determinar el estado final.',
        };
    }

    private function bridgeMessageForStatus(?string $status, ?string $default = null): string
    {
        return match ($status) {
            'PROCESADA' => 'La venta fue procesada correctamente por SEFE.',
            'ANULADA' => 'La factura fue anulada correctamente por SEFE.',
            'ANULACION_SOLICITADA' => 'La solicitud de anulación fue recepcionada por SEFE.',
            'ANULACION_OBSERVADA' => 'La solicitud de anulación fue observada por SEFE.',
            'OBSERVADA' => 'La venta fue recepcionada, pero SEFE la dejó observada.',
            'CONTINGENCIA_CREADA' => 'La venta fue recepcionada por SEFE y quedó en contingencia.',
            'RECEPCIONADA' => 'La venta fue recepcionada por SEFE y está pendiente de notificación final.',
            default => $default ?: 'La operación fue procesada por el puente.',
        };
    }

    private function cashierStatusFromBridgeStatus(?string $status): string
    {
        return match ($status) {
            'PROCESADA' => 'FACTURADA',
            'ANULADA' => 'ANULADA',
            'ANULACION_SOLICITADA' => 'PENDIENTE_ANULACION',
            'RECEPCIONADA', 'CONTINGENCIA_CREADA' => 'PENDIENTE',
            'OBSERVADA', 'RECHAZADA', 'ANULACION_OBSERVADA' => 'RECHAZADA',
            default => 'PENDIENTE',
        };
    }

    private function cashierFacturadaFromBridgeStatus(?string $status): bool
    {
        return $status === 'PROCESADA';
    }

    private function cashierReasonFromBridgeStatus(?string $status, ?string $observacion = null, ?string $fallbackMessage = null): ?string
    {
        return match ($status) {
            'PROCESADA' => null,
            'ANULADA' => null,
            'ANULACION_SOLICITADA' => 'La solicitud fue aceptada y se espera la notificación final de anulación.',
            'ANULACION_OBSERVADA' => $observacion ?: 'La anulación no pudo completarse porque SEFE devolvió observaciones.',
            'RECEPCIONADA' => 'La venta fue recibida y está esperando la confirmación final de facturación.',
            'CONTINGENCIA_CREADA' => 'La venta fue recibida, pero la facturación quedó en contingencia.',
            'OBSERVADA' => $observacion ?: 'La factura no pudo completarse porque SEFE devolvió observaciones.',
            'RECHAZADA' => $observacion ?: $fallbackMessage ?: 'La factura fue rechazada durante la validación.',
            default => $observacion ?: $fallbackMessage,
        };
    }

    private function cashierMessageFromBridgeStatus(?string $status): string
    {
        return match ($status) {
            'PROCESADA' => 'Factura emitida correctamente.',
            'ANULADA' => 'Factura anulada correctamente.',
            'ANULACION_SOLICITADA' => 'Anulación solicitada correctamente.',
            'RECEPCIONADA' => 'La venta fue recibida y está pendiente de confirmación.',
            'CONTINGENCIA_CREADA' => 'La venta quedó pendiente por contingencia.',
            'OBSERVADA', 'RECHAZADA' => 'No se pudo emitir la factura.',
            'ANULACION_OBSERVADA' => 'No se pudo anular la factura.',
            default => 'La venta está en proceso de validación.',
        };
    }

    private function sefePublicAssetUrl(?string $type, ?string $cuf = null, ?string $xmlFile = null): ?string
    {
        $baseUrl = $this->ageticPublicBaseUrl() . '/public';

        if ($type === 'pdf' && filled($cuf)) {
            return "{$baseUrl}/facturas_pdf/{$cuf}.pdf";
        }

        if ($type === 'xml') {
            if (filled($xmlFile)) {
                $xmlFile = ltrim((string) $xmlFile, '/');
                return "{$baseUrl}/facturas_xml/{$xmlFile}";
            }

            if (filled($cuf)) {
                return "{$baseUrl}/facturas_xml/{$cuf}.xml";
            }
        }

        return null;
    }

    private function normalizeSefePublicUrl(?string $url): ?string
    {
        $resolvedUrl = trim((string) $url);
        if ($resolvedUrl === '') {
            return null;
        }

        $path = (string) parse_url($resolvedUrl, PHP_URL_PATH);
        if ($path === '' || !str_starts_with($path, '/public/')) {
            return $resolvedUrl;
        }

        return $this->ageticPublicBaseUrl() . $path;
    }

    private function wantsVerboseResponse(Request $request): bool
    {
        $debug = strtolower((string) $request->query('debug', ''));

        if (in_array($debug, self::DEBUG_RESPONSE_QUERY_VALUES, true)) {
            return true;
        }

        return strtolower((string) $request->header('X-Bridge-Debug', '')) === 'true';
    }

    private function formatResponseForClient(Request $request, array $base, array $verbose = []): array
    {
        if (!$this->wantsVerboseResponse($request)) {
            return $base;
        }

        return array_merge($base, $verbose);
    }

    private function resolveBridgeStatus(string $currentStatus, ?Notificacione $notificacion = null, ?array $consulta = null): string
    {
        if ($notificacion) {
            $detalle = json_decode((string) $notificacion->detalle, true) ?: [];
            $tipoEmision = (string) data_get($detalle, 'tipoEmision');

            if ($tipoEmision === 'ANULACION') {
                return match ($notificacion->estado) {
                    'EXITO' => 'ANULADA',
                    'OBSERVADO' => 'ANULACION_OBSERVADA',
                    default => $currentStatus,
                };
            }

            return match ($notificacion->estado) {
                'EXITO' => 'PROCESADA',
                'OBSERVADO' => 'OBSERVADA',
                'CREADO' => $tipoEmision === 'CONTINGENCIA'
                    ? 'CONTINGENCIA_CREADA'
                    : $currentStatus,
                default => $currentStatus,
            };
        }

        $estadoConsulta = strtoupper((string) data_get($consulta, 'estado', ''));
        $tipoEvento = strtoupper((string) data_get($consulta, 'tipoEvento', ''));

        return match ($estadoConsulta) {
            'PROCESADO' => 'PROCESADA',
            'OBSERVADO' => 'OBSERVADA',
            'ANULADO' => 'ANULADA',
            'PENDIENTE' => $tipoEvento === 'CONTINGENCIA' ? 'CONTINGENCIA_CREADA' : $currentStatus,
            default => $currentStatus,
        };
    }

    private function shouldStopWaitingForCashier(string $status): bool
    {
        return in_array($status, ['PROCESADA', 'OBSERVADA', 'CONTINGENCIA_CREADA', 'ANULADA', 'ANULACION_OBSERVADA'], true);
    }

    private function safeConsultaFactura(string $codigoSeguimiento): ?array
    {
        try {
            $response = $this->ageticClient()->get($this->ageticBaseUrl() . "/consulta/{$codigoSeguimiento}");

            if (!$response->successful()) {
                return null;
            }

            $payload = $response->json();

            if (!is_array($payload)) {
                return null;
            }

            try {
                return $this->sufeValidator->validateConsultaFacturaResponse($payload);
            } catch (ValidationException) {
                return $payload;
            }
        } catch (\Throwable) {
            return null;
        }
    }

    private function waitForCashierOutcome(array $venta, ?int $seconds = null): array
    {
        $seconds = $seconds ?? (int) config('services.facturacion_api.emit_wait_seconds', 8);
        $deadline = microtime(true) + max(1, $seconds);
        $lastVenta = $this->ventaByCodigoSeguimiento($venta['codigoSeguimiento']);
        $lastNotification = $this->latestNotificationByCodigoSeguimiento($venta['codigoSeguimiento']);
        $lastConsulta = null;

        do {
            $lastVenta = $this->ventaByCodigoSeguimiento($venta['codigoSeguimiento']) ?: $lastVenta;
            $lastNotification = $this->latestNotificationByCodigoSeguimiento($venta['codigoSeguimiento']) ?: $lastNotification;
            $lastConsulta = $this->safeConsultaFactura($venta['codigoSeguimiento']) ?: $lastConsulta;

            $currentStatus = (string) ($lastVenta->estado_sufe ?? 'RECEPCIONADA');
            $resolvedStatus = $this->resolveBridgeStatus($currentStatus, $lastNotification, $lastConsulta);

            if ($lastVenta && $resolvedStatus !== $currentStatus) {
                DB::table('ventas')
                    ->where('id', $lastVenta->id)
                    ->update([
                        'estado_sufe' => $resolvedStatus,
                        'cuf' => data_get($lastConsulta, 'cuf', $lastVenta->cuf),
                        'numero_factura' => data_get($lastConsulta, 'nroFactura', $lastVenta->numero_factura ?? null),
                        'observacion_sufe' => data_get($lastConsulta, 'observacion', $lastVenta->observacion_sufe),
                        'updated_at' => now(),
                    ]);

                $lastVenta = $this->ventaByCodigoSeguimiento($venta['codigoSeguimiento']) ?: $lastVenta;
                if ($lastVenta) {
                    $this->syncFacturacionCartFromVenta($lastVenta, $resolvedStatus);
                }
            }

            if ($this->shouldStopWaitingForCashier($resolvedStatus)) {
                break;
            }

            usleep(800000);
        } while (microtime(true) < $deadline);

        return [
            'venta' => $lastVenta,
            'notificacion' => $lastNotification,
            'consulta' => $lastConsulta,
        ];
    }

    private function waitForAnulacionOutcome(\stdClass $venta, ?int $seconds = null): array
    {
        $codigoSeguimiento = trim((string) ($venta->codigoSeguimiento ?? ''));
        if ($codigoSeguimiento === '') {
            return [
                'venta' => $venta,
                'notificacion' => null,
                'consulta' => null,
            ];
        }

        $seconds = $seconds ?? 6;
        $deadline = microtime(true) + max(1, $seconds);
        $lastVenta = $this->ventaByCodigoSeguimiento($codigoSeguimiento) ?: $venta;
        $lastNotification = $this->latestNotificationByCodigoSeguimiento($codigoSeguimiento);
        $lastConsulta = null;

        do {
            $lastVenta = $this->ventaByCodigoSeguimiento($codigoSeguimiento) ?: $lastVenta;
            $lastNotification = $this->latestNotificationByCodigoSeguimiento($codigoSeguimiento) ?: $lastNotification;
            $lastConsulta = $this->safeConsultaFactura($codigoSeguimiento) ?: $lastConsulta;

            $currentStatus = (string) ($lastVenta->estado_sufe ?? 'ANULACION_SOLICITADA');
            $resolvedStatus = $this->resolveBridgeStatus($currentStatus, $lastNotification, $lastConsulta);

            if ($resolvedStatus !== $currentStatus) {
                DB::table('ventas')
                    ->where('id', $lastVenta->id)
                    ->update([
                        'estado_sufe' => $resolvedStatus,
                        'cuf' => data_get($lastConsulta, 'cuf', $lastVenta->cuf),
                        'numero_factura' => data_get($lastConsulta, 'nroFactura', $lastVenta->numero_factura ?? null),
                        'observacion_sufe' => data_get($lastConsulta, 'observacion', $lastVenta->observacion_sufe),
                        'updated_at' => now(),
                    ]);

                $lastVenta = $this->ventaByCodigoSeguimiento($codigoSeguimiento) ?: $lastVenta;
                $this->syncFacturacionCartFromVenta($lastVenta, $resolvedStatus);
            }

            if (in_array($resolvedStatus, ['ANULADA', 'ANULACION_OBSERVADA'], true)) {
                break;
            }

            usleep(800000);
        } while (microtime(true) < $deadline);

        return [
            'venta' => $lastVenta,
            'notificacion' => $lastNotification,
            'consulta' => $lastConsulta,
        ];
    }

    private function ventaByCodigoSeguimiento(string $codigoSeguimiento): ?\stdClass
    {
        return DB::table('ventas')
            ->where('codigoSeguimiento', $codigoSeguimiento)
            ->first();
    }

    private function ventaByCuf(string $cuf): ?\stdClass
    {
        return DB::table('ventas')
            ->where('cuf', $cuf)
            ->first();
    }

    private function syncFacturacionCartFromVenta(\stdClass $venta, ?string $bridgeStatus = null): void
    {
        $origenVentaTipo = (string) ($venta->origen_venta_tipo ?? '');
        $cartId = (int) ($venta->origen_venta_id ?? 0);

        if (!in_array($origenVentaTipo, ['facturacion_cart', 'facturacion_cart_remote'], true) || $cartId <= 0) {
            return;
        }

        $bridgeStatus = $bridgeStatus ?: (string) ($venta->estado_sufe ?? '');

        DB::table('facturacion_carts')
            ->where('id', $cartId)
            ->update([
                'estado_emision' => $this->cashierStatusFromBridgeStatus($bridgeStatus),
                'mensaje_emision' => $this->cashierMessageFromBridgeStatus($bridgeStatus),
                'updated_at' => now(),
            ]);
    }

    private function latestNotificationByCodigoSeguimiento(string $codigoSeguimiento): ?Notificacione
    {
        return Notificacione::query()
            ->where('codigo_seguimiento', $codigoSeguimiento)
            ->latest('id')
            ->first();
    }

    private function bridgeConsultPayloadFromVenta(\stdClass $venta, ?Notificacione $notificacion = null, ?array $consulta = null): array
    {
        $status = $this->resolveBridgeStatus((string) ($venta->estado_sufe ?: 'RECEPCIONADA'), $notificacion, $consulta);
        $detalleNotificacion = $notificacion ? (json_decode((string) $notificacion->detalle, true) ?: []) : [];
        $observacion = $venta->observacion_sufe ?: data_get($detalleNotificacion, 'observacion') ?: data_get($consulta, 'observacion');
        $cuf = $venta->cuf ?: data_get($detalleNotificacion, 'cuf') ?: data_get($consulta, 'cuf');
        $xmlFile = data_get($consulta, 'xml');
        $pdfUrl = $this->normalizeSefePublicUrl($venta->url_pdf ?: data_get($detalleNotificacion, 'urlPdf'));
        $xmlUrl = $this->normalizeSefePublicUrl($venta->url_xml ?: data_get($detalleNotificacion, 'urlXml'));

        if (!$pdfUrl && $status === 'PROCESADA') {
            $pdfUrl = $this->sefePublicAssetUrl('pdf', $cuf);
        }

        if (!$xmlUrl && $status === 'PROCESADA') {
            $xmlUrl = $this->sefePublicAssetUrl('xml', $cuf, $xmlFile);
        }

        $factura = [
            'cuf' => $cuf,
            'nroFactura' => ($venta->numero_factura ?? null) ?: data_get($detalleNotificacion, 'nroFactura') ?: data_get($consulta, 'nroFactura'),
            'pdfUrl' => $this->normalizeSefePublicUrl($pdfUrl),
            'xmlUrl' => $this->normalizeSefePublicUrl($xmlUrl),
        ];

        $base = [
            'ok' => true,
            'facturada' => $this->cashierFacturadaFromBridgeStatus($status),
            'estado' => $this->cashierStatusFromBridgeStatus($status),
            'mensaje' => $this->cashierMessageFromBridgeStatus($status),
            'razon' => $this->cashierReasonFromBridgeStatus($status, $observacion),
            'factura' => $factura,
        ];

        $verbose = [
            'estadoPuente' => $status,
            'estadoPuenteLabel' => $this->bridgeStatusLabel($status),
            'estadoSufe' => $notificacion?->estado,
            'tipoEmision' => $venta->tipo_emision_sufe ?: data_get($detalleNotificacion, 'tipoEmision'),
            'codigoOrden' => $venta->codigoOrden,
            'codigoSeguimiento' => $venta->codigoSeguimiento,
            'mensajeTecnico' => $this->bridgeMessageForStatus($status, $notificacion?->mensaje),
            'ayuda' => $this->bridgeStatusHelp($status),
            'venta' => [
                'id' => (int) $venta->id,
                'codigoCliente' => $venta->codigoCliente,
                'razonSocial' => $venta->razonSocial,
                'documentoIdentidad' => $venta->documentoIdentidad,
                'total' => (float) $venta->total,
            ],
            'observacion' => $observacion,
            'fechaNotificacion' => $venta->fecha_notificacion_sufe,
            'notificacion' => $notificacion ? [
                'estado' => $notificacion->estado,
                'mensaje' => $notificacion->mensaje,
                'fecha' => $notificacion->fecha,
                'fuente' => $notificacion->fuente,
            ] : null,
            'consultaSefe' => $consulta,
        ];

        return compact('base', 'verbose');
    }

    private function emitResponsePayload(array $validatedRequest, array $sefePayload, array $venta, bool $isFinal): array
    {
        $estadoPuente = $isFinal ? 'PROCESADA' : 'RECEPCIONADA';
        $cuf = data_get($sefePayload, 'datos.cuf');
        $factura = [
            'cuf' => $cuf,
            'nroFactura' => data_get($sefePayload, 'datos.nroFactura'),
            'pdfUrl' => $this->normalizeSefePublicUrl(data_get($sefePayload, 'datos.urlPdf') ?: ($estadoPuente === 'PROCESADA' ? $this->sefePublicAssetUrl('pdf', $cuf) : null)),
            'xmlUrl' => $this->normalizeSefePublicUrl(data_get($sefePayload, 'datos.urlXml') ?: ($estadoPuente === 'PROCESADA' ? $this->sefePublicAssetUrl('xml', $cuf) : null)),
        ];

        $base = [
            'ok' => true,
            'facturada' => $this->cashierFacturadaFromBridgeStatus($estadoPuente),
            'estado' => $this->cashierStatusFromBridgeStatus($estadoPuente),
            'mensaje' => $this->cashierMessageFromBridgeStatus($estadoPuente),
            'razon' => $this->cashierReasonFromBridgeStatus($estadoPuente, null, data_get($sefePayload, 'mensaje')),
            'factura' => $factura,
        ];

        $verbose = [
            'estadoPuente' => $estadoPuente,
            'estadoPuenteLabel' => $this->bridgeStatusLabel($estadoPuente),
            'estadoSufe' => $isFinal ? 'EXITO' : null,
            'tipoEmision' => $isFinal ? 'EMISION' : null,
            'codigoOrden' => $venta['codigoOrden'],
            'codigoSeguimiento' => $venta['codigoSeguimiento'],
            'mensajeTecnico' => $this->bridgeMessageForStatus($estadoPuente, data_get($sefePayload, 'mensaje')),
            'ayuda' => $this->bridgeStatusHelp($estadoPuente),
            'venta' => [
                'id' => $venta['id'],
                'codigoCliente' => (string) $validatedRequest['codigoCliente'],
                'razonSocial' => $validatedRequest['razonSocial'],
                'documentoIdentidad' => $validatedRequest['documentoIdentidad'],
                'montoTotal' => (float) $validatedRequest['montoTotal'],
            ],
            'sefe' => [
                'finalizado' => data_get($sefePayload, 'finalizado'),
                'mensaje' => data_get($sefePayload, 'mensaje'),
                'datos' => data_get($sefePayload, 'datos'),
            ],
        ];

        return compact('base', 'verbose');
    }

    private function rejectBridgePayload(?array $payload, string $fallbackMessage = 'La solicitud fue rechazada por SEFE.'): array
    {
        $mensaje = data_get($payload, 'mensaje', $fallbackMessage);
        $errors = data_get($payload, 'datos.errores', []);

        $base = [
            'ok' => false,
            'facturada' => false,
            'estado' => 'RECHAZADA',
            'mensaje' => 'No se pudo emitir la factura.',
            'razon' => $errors[0] ?? $mensaje,
            'factura' => [
                'cuf' => null,
                'nroFactura' => null,
                'pdfUrl' => null,
                'xmlUrl' => null,
            ],
        ];

        $verbose = [
            'estadoPuente' => 'RECHAZADA',
            'mensajeTecnico' => $mensaje,
            'errors' => $errors,
            'sefe' => $payload,
        ];

        return compact('base', 'verbose');
    }

    private function contingenciaBridgePayload(array $payload): array
    {
        $detalle = collect(data_get($payload, 'datos.detalle', []))->map(function ($item) {
            return [
                'codigoSeguimiento' => data_get($item, 'codigoSeguimiento'),
                'nroFactura' => data_get($item, 'nroFactura'),
                'documentoIdentidad' => data_get($item, 'documentoIdentidad'),
                'fechaEmision' => data_get($item, 'fechaEmision'),
            ];
        })->values()->all();

        $rechazados = collect(data_get($payload, 'datos.rechazados', []))->map(function ($item) {
            return [
                'nroFactura' => data_get($item, 'nroFactura'),
                'documentoIdentidad' => data_get($item, 'documentoIdentidad'),
                'fechaEmision' => data_get($item, 'fechaEmision'),
                'observacion' => data_get($item, 'observacion'),
            ];
        })->values()->all();

        $base = [
            'ok' => true,
            'estado' => empty($rechazados) ? 'REGULARIZADA' : 'REGULARIZADA_PARCIAL',
            'mensaje' => empty($rechazados)
                ? 'Facturas de contingencia enviadas correctamente.'
                : 'Las facturas de contingencia se enviaron con observaciones parciales.',
            'razon' => empty($rechazados) ? null : 'Algunas facturas fueron aceptadas y otras quedaron observadas.',
            'paquete' => [
                'codigoSeguimientoPaquete' => data_get($payload, 'datos.codigoSeguimientoPaquete'),
                'aceptadas' => count($detalle),
                'rechazadas' => count($rechazados),
            ],
            'facturas' => $detalle,
            'rechazados' => $rechazados,
        ];

        $verbose = [
            'mensajeTecnico' => data_get($payload, 'mensaje'),
            'sefe' => $payload,
        ];

        return compact('base', 'verbose');
    }

    private function validatedRejectedPayloadFromResponse(?\Illuminate\Http\Client\Response $response): ?array
    {
        if (!$response) {
            return null;
        }

        $payload = $response->json();

        if (!is_array($payload)) {
            return null;
        }

        try {
            return $this->sufeValidator->validateRejectedResponse($payload);
        } catch (ValidationException) {
            return null;
        }
    }

    private function shouldRetryWithNitException(array $requestPayload, ?array $rejectedPayload): bool
    {
        if (($requestPayload['codigoExcepcion'] ?? null) === 1) {
            return false;
        }

        if ((int) ($requestPayload['tipoDocumentoIdentidad'] ?? 0) !== 5) {
            return false;
        }

        $documento = trim((string) ($requestPayload['documentoIdentidad'] ?? ''));
        if ($documento === '') {
            return false;
        }

        $errors = collect((array) data_get($rejectedPayload, 'datos.errores', []))
            ->filter(fn ($value) => is_string($value))
            ->map(fn ($value) => mb_strtolower(trim($value)))
            ->values();

        if ($errors->isEmpty()) {
            return false;
        }

        $needle = mb_strtolower('El documentoIdentidad ' . $documento . ' no es un nit válido');

        return $errors->contains(function (string $error) use ($needle, $documento) {
            return str_contains($error, $needle)
                || str_contains($error, 'no es un nit valido')
                || str_contains($error, 'no es un nit válido')
                || (str_contains($error, mb_strtolower($documento)) && str_contains($error, 'nit'));
        });
    }

    private function resolveSuccessfulReception(array $payload): array
    {
        try {
            $validated = $this->sufeValidator->validateAcceptedIndividualResponse($payload);

            return [
                'validated' => $validated,
                'is_final' => false,
            ];
        } catch (ValidationException $acceptedException) {
            $validated = $this->sufeValidator->validateReceivedIndividualResponse($payload);

            Log::warning('FacturaVentaApi emitir successful response received without final acceptance', [
                'body' => $payload,
                'accepted_errors' => $acceptedException->errors(),
            ]);

            return [
                'validated' => $validated,
                'is_final' => false,
            ];
        }
    }

    /**
     * Consolida lineas repetidas de detalle para evitar rechazo SEFE por
     * codigos de actividad/producto duplicados.
     *
     * Se agrupa por actividadEconomica + codigoSin + codigo + unidadMedida +
     * precioUnitario (+ montoDescuento) y se acumula cantidad.
     */
    private function consolidateDetalleLines(array $detalles): array
    {
        $grouped = [];

        foreach ($detalles as $line) {
            if (!is_array($line)) {
                continue;
            }

            $actividad = trim((string) ($line['actividadEconomica'] ?? ''));
            $codigoSin = trim((string) ($line['codigoSin'] ?? ''));
            $codigo = trim((string) ($line['codigo'] ?? ''));
            $descripcion = trim((string) ($line['descripcion'] ?? ''));
            $unidadMedida = (int) ($line['unidadMedida'] ?? 0);
            $precioUnitario = round((float) ($line['precioUnitario'] ?? 0), 2);
            $montoDescuento = round((float) ($line['montoDescuento'] ?? 0), 2);
            $cantidad = (float) ($line['cantidad'] ?? 0);

            if ($actividad === '' || $codigoSin === '' || $codigo === '' || $unidadMedida <= 0 || $precioUnitario <= 0 || $cantidad <= 0) {
                continue;
            }

            $key = implode('|', [
                $actividad,
                $codigoSin,
                $codigo,
                (string) $unidadMedida,
                number_format($precioUnitario, 2, '.', ''),
                number_format($montoDescuento, 2, '.', ''),
            ]);

            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'actividadEconomica' => $actividad,
                    'codigoSin' => $codigoSin,
                    'codigo' => $codigo,
                    'descripcion' => $descripcion,
                    'precioUnitario' => $precioUnitario,
                    'montoDescuento' => $montoDescuento > 0 ? $montoDescuento : null,
                    'cantidad' => $cantidad,
                    'unidadMedida' => $unidadMedida,
                ];
                continue;
            }

            $grouped[$key]['cantidad'] = (float) $grouped[$key]['cantidad'] + $cantidad;
            if (($grouped[$key]['descripcion'] ?? '') === '' && $descripcion !== '') {
                $grouped[$key]['descripcion'] = $descripcion;
            }
        }

        return array_values($grouped);
    }

    public function emitir(Request $request)
    {
        $requestData = $request->all();
        $codigoOrdenRecibido = (string) ($requestData['codigoOrden'] ?? '');
        $codigoOrden = $codigoOrdenRecibido;

        Log::debug('FacturaVentaApi emitir started', [
            'codigoOrden_recibido' => $codigoOrdenRecibido,
            'ip' => $request->ip(),
            'payload_keys' => array_keys($requestData),
        ]);

        try {
            $validated = $this->sufeValidator->validateIndividualPayload($requestData);
            $detalleCountOriginal = count($validated['detalle'] ?? []);
            $validated['detalle'] = $this->consolidateDetalleLines((array) ($validated['detalle'] ?? []));
            if (empty($validated['detalle'])) {
                throw ValidationException::withMessages([
                    'detalle' => ['No se encontro detalle valido para emitir la factura.'],
                ]);
            }

            Log::debug('FacturaVentaApi emitir payload validated', [
                'codigoOrden_recibido' => $codigoOrdenRecibido,
                'detalle_count' => $detalleCountOriginal,
                'detalle_count_consolidado' => count($validated['detalle'] ?? []),
                'montoTotal' => $validated['montoTotal'] ?? null,
                'documentoIdentidad' => $validated['documentoIdentidad'] ?? null,
                'codigoCliente' => $validated['codigoCliente'] ?? null,
            ]);
            $this->assertFacturaVentaSector($validated);
            $this->assertCajaAbierta($validated);
            Log::debug('FacturaVentaApi emitir sector validated', [
                'codigoOrden_recibido' => $codigoOrdenRecibido,
                'documentoSector' => $validated['documentoSector'] ?? null,
            ]);

            Log::debug('FacturaVentaApi emitir snapshot prepared', [
                'codigoOrden_recibido' => $codigoOrdenRecibido,
                'codigoCliente' => $validated['codigoCliente'] ?? null,
                'razonSocial' => $validated['razonSocial'] ?? null,
                'documentoIdentidad' => $validated['documentoIdentidad'] ?? null,
                'origen_usuario_nombre' => data_get($validated, 'origenUsuario.nombre'),
            ]);
            $codigoOrden = $this->resolveCodigoOrden($validated);
            Log::debug('FacturaVentaApi emitir codigoOrden resolved', [
                'codigoOrden' => $codigoOrden,
                'codigoOrden_recibido' => $codigoOrdenRecibido,
            ]);
            $requestPayload = $this->sanitizePayloadForAgetic($validated);
            $requestPayload['codigoOrden'] = $codigoOrden;

            Log::debug('FacturaVentaApi emitir request', $requestPayload);

            $response = $this->ageticClient()->post(
                $this->ageticBaseUrl() . '/facturacion/emision/individual',
                $requestPayload
            );

            $payload = $response->json();

            if (
                !$response->successful()
                && $this->shouldRetryWithNitException($requestPayload, is_array($payload) ? $payload : null)
            ) {
                $requestPayload['codigoExcepcion'] = 1;

                Log::warning('FacturaVentaApi emitir retrying with codigoExcepcion=1 after NIT validation reject', [
                    'codigoOrden' => $codigoOrden,
                    'documentoIdentidad' => $requestPayload['documentoIdentidad'] ?? null,
                    'tipoDocumentoIdentidad' => $requestPayload['tipoDocumentoIdentidad'] ?? null,
                ]);

                $response = $this->ageticClient()->post(
                    $this->ageticBaseUrl() . '/facturacion/emision/individual',
                    $requestPayload
                );

                $payload = $response->json();
                if ($response->successful()) {
                    $validated['codigoExcepcion'] = 1;
                }
            }

            if ($response->successful()) {
                $reception = $this->resolveSuccessfulReception($payload ?? []);
                $codigoSeguimiento = (string) data_get($reception['validated'], 'datos.codigoSeguimiento');
                $venta = DB::transaction(function () use ($validated, $codigoOrden, $codigoSeguimiento) {
                    $venta = $this->createVenta($validated, $codigoOrden, $codigoSeguimiento);
                    $this->createDetalleVentas($venta, $validated);
                    $this->addVentaToCaja($validated);

                    return $venta;
                });

                Log::debug('FacturaVentaApi emitir response accepted', [
                    'status' => $response->status(),
                    'codigoOrden' => $venta['codigoOrden'],
                    'codigoSeguimiento' => $codigoSeguimiento,
                    'venta_id' => $venta['id'],
                    'is_final' => $reception['is_final'],
                    'body' => $payload,
                ]);

                Log::debug('FacturaVentaApi emitir returning immediately after accepted response', [
                    'codigoOrden' => $venta['codigoOrden'],
                    'codigoSeguimiento' => $codigoSeguimiento,
                    'status' => $response->status(),
                    'is_final' => $reception['is_final'],
                ]);

                $payload = $this->emitResponsePayload($validated, $payload ?? [], $venta, $reception['is_final']);

                return response()->json(
                    $this->formatResponseForClient($request, $payload['base'], $payload['verbose']),
                    $response->status()
                );
            }

            Log::warning('FacturaVentaApi emitir response rejected', [
                'status' => $response->status(),
                'codigoOrden' => $codigoOrden,
                'body' => $payload,
            ]);

            if (is_array($payload)) {
                try {
                    $this->sufeValidator->validateRejectedResponse($payload);
                } catch (ValidationException $validationException) {
                    Log::warning('La respuesta de rechazo de FacturaVentaApi no cumple el protocolo', [
                        'errores' => $validationException->errors(),
                        'body' => $payload,
                    ]);
                }
            }

            Log::warning('FacturaVentaApi emitir skipped persistence after rejected response', [
                'codigoOrden' => $codigoOrden,
            ]);

            $rejectedPayload = $this->rejectBridgePayload(is_array($payload) ? $payload : null);

            return response()->json(
                $this->formatResponseForClient($request, $rejectedPayload['base'], $rejectedPayload['verbose']),
                $response->status()
            );
        } catch (ValidationException $e) {
            Log::warning('FacturaVentaApi emitir validation exception', [
                'codigoOrden' => $codigoOrden,
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'ok' => false,
                'facturada' => false,
                'estado' => 'RECHAZADA',
                'mensaje' => 'No se pudo emitir la factura.',
                'razon' => 'La venta no cumple la validación del protocolo.',
                'factura' => [
                    'cuf' => null,
                    'nroFactura' => null,
                    'pdfUrl' => null,
                    'xmlUrl' => null,
                ],
                'estadoPuente' => 'RECHAZADA',
                'message' => 'La solicitud de factura de venta no cumple la validacion del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            $rejectedPayload = $this->validatedRejectedPayloadFromResponse($e->response);

            if ($rejectedPayload !== null) {
                if ($this->shouldRetryWithNitException($requestPayload ?? [], $rejectedPayload)) {
                    $retryPayload = $requestPayload;
                    $retryPayload['codigoExcepcion'] = 1;

                    Log::warning('FacturaVentaApi emitir retrying with codigoExcepcion=1 after RequestException NIT reject', [
                        'codigoOrden' => $codigoOrden,
                        'documentoIdentidad' => $retryPayload['documentoIdentidad'] ?? null,
                        'tipoDocumentoIdentidad' => $retryPayload['tipoDocumentoIdentidad'] ?? null,
                    ]);

                    $retryResponse = $this->ageticClient()->post(
                        $this->ageticBaseUrl() . '/facturacion/emision/individual',
                        $retryPayload
                    );
                    $retryBody = $retryResponse->json();

                    if ($retryResponse->successful()) {
                        $reception = $this->resolveSuccessfulReception($retryBody ?? []);
                        $codigoSeguimiento = (string) data_get($reception['validated'], 'datos.codigoSeguimiento');
                        $validated['codigoExcepcion'] = 1;
                        $venta = DB::transaction(function () use ($validated, $codigoOrden, $codigoSeguimiento) {
                            $venta = $this->createVenta($validated, $codigoOrden, $codigoSeguimiento);
                            $this->createDetalleVentas($venta, $validated);
                            $this->addVentaToCaja($validated);

                            return $venta;
                        });

                        $payload = $this->emitResponsePayload($validated, $retryBody ?? [], $venta, $reception['is_final']);

                        return response()->json(
                            $this->formatResponseForClient($request, $payload['base'], $payload['verbose']),
                            $retryResponse->status()
                        );
                    }

                    $retryRejectedPayload = $this->validatedRejectedPayloadFromResponse($retryResponse);
                    if ($retryRejectedPayload !== null) {
                        $rejectedPayload = $retryRejectedPayload;
                    }
                }

                Log::warning('FacturaVentaApi emitir request rejected by SEFE', [
                    'codigoOrden' => $codigoOrden,
                    'status' => $e->response?->status(),
                    'body' => $rejectedPayload,
                ]);

                $payload = $this->rejectBridgePayload($rejectedPayload);

                return response()->json(
                    $this->formatResponseForClient($request, $payload['base'], $payload['verbose']),
                    $e->response?->status() ?? 400
                );
            }

            Log::error('FacturaVentaApi emitir request exception', [
                'codigoOrden' => $codigoOrden,
                'status' => $e->response?->status(),
                'body' => $e->response?->json(),
                'msg' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'estadoPuente' => 'ERROR',
                'message' => 'Error al emitir la factura de venta.',
                'details' => $e->getMessage(),
                'sefe' => $e->response?->json(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            Log::error('FacturaVentaApi emitir connection exception', [
                'codigoOrden' => $codigoOrden,
                'msg' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'estadoPuente' => 'ERROR',
                'message' => 'No se pudo conectar con el servicio SEFE.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('FacturaVentaApi emitir unexpected error', [
                'codigoOrden' => $codigoOrden,
                'msg' => $e->getMessage(),
                'trace_line' => $e->getLine(),
                'trace_file' => $e->getFile(),
            ]);

            return response()->json([
                'ok' => false,
                'estadoPuente' => 'ERROR',
                'message' => 'Error inesperado al emitir la factura de venta.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function anular(Request $request, string $cuf)
    {
        try {
            $validated = $this->sufeValidator->validateAnulacionPayload($request->all());
            $venta = $this->ventaByCuf($cuf);

            if (!$venta) {
                return response()->json([
                    'ok' => false,
                    'estado' => 'RECHAZADA',
                    'mensaje' => 'No se pudo anular la factura.',
                    'razon' => 'No existe una venta local asociada al CUF indicado.',
                    'factura' => [
                        'cuf' => $cuf,
                        'nroFactura' => null,
                    ],
                    'estadoPuente' => 'NO_REGISTRADA_LOCALMENTE',
                ], 404);
            }

            $estadoActual = strtoupper((string) ($venta->estado_sufe ?? ''));

            if (!in_array($estadoActual, ['PROCESADA', 'ANULACION_OBSERVADA'], true)) {
                return response()->json([
                    'ok' => false,
                    'estado' => 'RECHAZADA',
                    'mensaje' => 'No se pudo anular la factura.',
                    'razon' => 'Solo se puede anular una factura procesada correctamente.',
                    'factura' => [
                        'cuf' => $cuf,
                        'nroFactura' => $venta->numero_factura ?? null,
                    ],
                    'estadoPuente' => $estadoActual ?: 'SIN_ESTADO',
                ], 409);
            }

            Log::info('FacturaVentaApi anular request', [
                'cuf' => $cuf,
                'codigoSeguimiento' => $venta->codigoSeguimiento,
                'payload' => $validated,
            ]);

            $response = $this->ageticClient()->patch(
                $this->ageticBaseUrl() . "/anulacion/{$cuf}",
                $validated
            );

            $payload = $response->json();

            if ($response->successful()) {
                $this->sufeValidator->validateAcceptedAnulacionResponse($payload ?? []);

                DB::table('ventas')
                    ->where('id', $venta->id)
                    ->update([
                        'estado_sufe' => 'ANULACION_SOLICITADA',
                        'tipo_emision_sufe' => 'ANULACION',
                        'observacion_sufe' => $validated['motivo'],
                        'updated_at' => now(),
                    ]);

                $ventaActualizada = $this->ventaByCuf($cuf) ?: $venta;
                $this->syncFacturacionCartFromVenta($ventaActualizada, 'ANULACION_SOLICITADA');
                $resultadoAnulacion = $this->waitForAnulacionOutcome($ventaActualizada);
                $ventaFinal = $resultadoAnulacion['venta'] ?? $ventaActualizada;
                $notificacionFinal = $resultadoAnulacion['notificacion'] ?? null;
                $consultaFinal = $resultadoAnulacion['consulta'] ?? null;
                $statusFinal = $this->resolveBridgeStatus(
                    (string) ($ventaFinal->estado_sufe ?? 'ANULACION_SOLICITADA'),
                    $notificacionFinal,
                    $consultaFinal
                );

                if (in_array($statusFinal, ['ANULADA', 'ANULACION_OBSERVADA'], true)) {
                    $bridgePayload = $this->bridgeConsultPayloadFromVenta($ventaFinal, $notificacionFinal, $consultaFinal);

                    return response()->json(
                        $this->formatResponseForClient($request, $bridgePayload['base'], $bridgePayload['verbose']),
                        200
                    );
                }

                $base = [
                    'ok' => true,
                    'estado' => 'PENDIENTE_ANULACION',
                    'mensaje' => 'Anulación solicitada correctamente.',
                    'razon' => 'La solicitud fue aceptada y se espera la notificación final de anulación.',
                    'factura' => [
                        'cuf' => data_get($payload, 'datos.cuf', $cuf),
                        'nroFactura' => $venta->numero_factura ?? null,
                    ],
                ];

                $verbose = [
                    'estadoPuente' => 'ANULACION_SOLICITADA',
                    'codigoOrden' => $venta->codigoOrden,
                    'codigoSeguimiento' => $venta->codigoSeguimiento,
                    'mensajeTecnico' => data_get($payload, 'mensaje'),
                    'sefe' => $payload,
                ];

                return response()->json(
                    $this->formatResponseForClient($request, $base, $verbose),
                    $response->status()
                );
            }

            $rejectedPayload = is_array($payload) ? $payload : null;

            if ($rejectedPayload !== null) {
                try {
                    $this->sufeValidator->validateRejectedResponse($rejectedPayload);
                } catch (ValidationException $validationException) {
                    Log::warning('La respuesta de rechazo de anulación no cumple el protocolo', [
                        'errores' => $validationException->errors(),
                        'body' => $rejectedPayload,
                    ]);
                }
            }

            return response()->json([
                'ok' => false,
                'estado' => 'RECHAZADA',
                'mensaje' => 'No se pudo anular la factura.',
                'razon' => data_get($rejectedPayload, 'datos.errores.0', data_get($rejectedPayload, 'mensaje', 'SEFE rechazó la solicitud de anulación.')),
                'factura' => [
                    'cuf' => $cuf,
                    'nroFactura' => $venta->numero_factura ?? null,
                ],
                'estadoPuente' => 'RECHAZADA',
                'sefe' => $rejectedPayload,
            ], $response->status());
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'estado' => 'RECHAZADA',
                'mensaje' => 'No se pudo anular la factura.',
                'razon' => 'La solicitud de anulación no cumple la validación del protocolo.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            $rejectedPayload = $this->validatedRejectedPayloadFromResponse($e->response);

            return response()->json([
                'ok' => false,
                'estado' => 'RECHAZADA',
                'mensaje' => 'No se pudo anular la factura.',
                'razon' => data_get($rejectedPayload, 'datos.errores.0', data_get($rejectedPayload, 'mensaje', 'SEFE devolvió un error al procesar la anulación.')),
                'estadoPuente' => 'ERROR',
                'sefe' => $e->response?->json(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'ok' => false,
                'estado' => 'ERROR',
                'mensaje' => 'No se pudo anular la factura.',
                'razon' => 'No se pudo conectar con SEFE.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('FacturaVentaApi anular unexpected error', [
                'cuf' => $cuf,
                'msg' => $e->getMessage(),
                'trace_line' => $e->getLine(),
                'trace_file' => $e->getFile(),
            ]);

            return response()->json([
                'ok' => false,
                'estado' => 'ERROR',
                'mensaje' => 'No se pudo anular la factura.',
                'razon' => 'Ocurrió un error inesperado al procesar la anulación.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function consultar(Request $request, string $codigoSeguimiento)
    {
        $tipo = $request->query('tipo');
        $url = $this->ageticBaseUrl() . "/consulta/{$codigoSeguimiento}";

        Log::debug('FacturaVentaApi consultar started', [
            'codigoSeguimiento' => $codigoSeguimiento,
            'tipo' => $tipo,
        ]);

        if (in_array($tipo, ['CO', 'CUF'], true)) {
            $url .= '?tipo=' . $tipo;
        }

        try {
            $response = $this->ageticClient()->get($url);
            $payload = $response->json();

            if ($response->successful()) {
                $venta = $this->ventaByCodigoSeguimiento($codigoSeguimiento);

                $validatedConsulta = null;

                if (is_array($payload)) {
                    try {
                        $validatedConsulta = $this->sufeValidator->validateConsultaFacturaResponse($payload);
                    } catch (ValidationException $validationException) {
                        Log::warning('FacturaVentaApi consultar response does not fully match protocolo', [
                            'codigoSeguimiento' => $codigoSeguimiento,
                            'body' => $payload,
                            'errors' => $validationException->errors(),
                        ]);
                    }
                }

                if ($venta) {
                    if (is_array($validatedConsulta)) {
                        $notificacion = $this->latestNotificationByCodigoSeguimiento($codigoSeguimiento);
                        $resolvedStatus = $this->resolveBridgeStatus(
                            (string) ($venta->estado_sufe ?? 'RECEPCIONADA'),
                            $notificacion,
                            $validatedConsulta
                        );

                        DB::table('ventas')
                            ->where('id', $venta->id)
                            ->update([
                                'estado_sufe' => $resolvedStatus,
                                'cuf' => data_get($validatedConsulta, 'cuf', $venta->cuf),
                                'numero_factura' => data_get($validatedConsulta, 'nroFactura', $venta->numero_factura ?? null),
                                'observacion_sufe' => data_get($validatedConsulta, 'observacion', $venta->observacion_sufe),
                                'updated_at' => now(),
                            ]);

                        $venta = $this->ventaByCodigoSeguimiento($codigoSeguimiento) ?: $venta;
                        $this->syncFacturacionCartFromVenta($venta, $resolvedStatus);
                    }

                    $notificacion = $this->latestNotificationByCodigoSeguimiento($codigoSeguimiento);
                    $bridgePayload = $this->bridgeConsultPayloadFromVenta(
                        $venta,
                        $notificacion,
                        $validatedConsulta ?? (is_array($payload) ? $payload : null)
                    );

                    return response()->json(
                        $this->formatResponseForClient($request, $bridgePayload['base'], $bridgePayload['verbose']),
                        200
                    );
                }

                if ($validatedConsulta === null) {
                    throw ValidationException::withMessages([
                        'consulta' => ['La respuesta de consulta de SEFE no cumple el protocolo esperado y no existe una venta local de respaldo.'],
                    ]);
                }

                Log::debug('FacturaVentaApi consultar response accepted', [
                    'codigoSeguimiento' => $codigoSeguimiento,
                    'status' => $response->status(),
                    'body' => $validatedConsulta,
                ]);

                return response()->json([
                    'ok' => true,
                    'facturada' => false,
                    'estado' => 'PENDIENTE',
                    'mensaje' => 'La venta fue recibida, pero no existe respaldo local suficiente para mostrar su estado final.',
                    'razon' => 'SEFE devolvió información, pero el puente no encontró la venta local asociada.',
                    'factura' => [
                        'cuf' => data_get($validatedConsulta, 'cuf'),
                        'nroFactura' => data_get($validatedConsulta, 'nroFactura'),
                        'pdfUrl' => null,
                        'xmlUrl' => null,
                    ],
                    'estadoPuente' => 'NO_REGISTRADA_LOCALMENTE',
                    'codigoSeguimiento' => $codigoSeguimiento,
                    'mensajeTecnico' => 'SEFE devolvió información, pero no existe una venta local asociada en el puente.',
                    'consultaSefe' => $validatedConsulta,
                ], 200);
            }

            Log::warning('FacturaVentaApi consultar response rejected', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'status' => $response->status(),
                'body' => $payload,
            ]);

            return response()->json([
                'ok' => false,
                'estadoPuente' => 'ERROR',
                'codigoSeguimiento' => $codigoSeguimiento,
                'message' => data_get($payload, 'message', 'Error al consultar la factura.'),
                'sefe' => $payload,
            ], $response->status());
        } catch (RequestException $e) {
            Log::error('FacturaVentaApi consultar request exception', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'status' => $e->response?->status(),
                'body' => $e->response?->json(),
                'msg' => $e->getMessage(),
            ]);
            return response()->json([
                'ok' => false,
                'estadoPuente' => 'ERROR',
                'codigoSeguimiento' => $codigoSeguimiento,
                'message' => 'Error al consultar la factura.',
                'details' => $e->getMessage(),
                'sefe' => $e->response?->json(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            Log::error('FacturaVentaApi consultar connection exception', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'msg' => $e->getMessage(),
            ]);
            return response()->json([
                'ok' => false,
                'estadoPuente' => 'ERROR',
                'codigoSeguimiento' => $codigoSeguimiento,
                'message' => 'No se pudo conectar con el servicio SEFE.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('FacturaVentaApi consultar unexpected error', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'msg' => $e->getMessage(),
                'trace_line' => $e->getLine(),
                'trace_file' => $e->getFile(),
            ]);

            return response()->json([
                'ok' => false,
                'estadoPuente' => 'ERROR',
                'codigoSeguimiento' => $codigoSeguimiento,
                'message' => 'Error inesperado al consultar la factura.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function contingenciaCafc(Request $request)
    {
        try {
            $validated = $this->sufeValidator->validateContingenciaCafcPayload($request->all());

            Log::info('FacturaVentaApi contingencia request', [
                'facturas_count' => count($validated['facturas'] ?? []),
                'documentoSector' => $validated['documentoSector'] ?? null,
                'codigoSucursal' => $validated['codigoSucursal'] ?? null,
                'puntoVenta' => $validated['puntoVenta'] ?? null,
            ]);

            $response = $this->ageticClient()->post(
                $this->ageticBaseUrl() . '/facturacion/contingencia',
                $validated
            );

            $payload = $response->json();

            if ($response->successful()) {
                $this->sufeValidator->validateAcceptedContingenciaCafcResponse($payload ?? []);
                $bridgePayload = $this->contingenciaBridgePayload($payload ?? []);

                return response()->json(
                    $this->formatResponseForClient($request, $bridgePayload['base'], $bridgePayload['verbose']),
                    $response->status()
                );
            }

            return response()->json(
                $this->formatResponseForClient($request, [
                    'ok' => false,
                    'estado' => 'RECHAZADA',
                    'mensaje' => 'No se pudo regularizar la contingencia.',
                    'razon' => data_get($payload, 'datos.errores.0', data_get($payload, 'mensaje', 'SEFE rechazó la regularización de contingencia.')),
                    'paquete' => [
                        'codigoSeguimientoPaquete' => data_get($payload, 'datos.codigoSeguimientoPaquete'),
                        'aceptadas' => count(data_get($payload, 'datos.detalle', [])),
                        'rechazadas' => count(data_get($payload, 'datos.rechazados', [])),
                    ],
                    'facturas' => [],
                    'rechazados' => data_get($payload, 'datos.rechazados', []),
                ], [
                    'mensajeTecnico' => data_get($payload, 'mensaje'),
                    'sefe' => $payload,
                ]),
                $response->status()
            );
        } catch (ValidationException $e) {
            return response()->json([
                'ok' => false,
                'estado' => 'RECHAZADA',
                'mensaje' => 'No se pudo regularizar la contingencia.',
                'razon' => 'La solicitud CAFC no cumple la validación del protocolo.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            return response()->json([
                'ok' => false,
                'estado' => 'ERROR',
                'mensaje' => 'No se pudo regularizar la contingencia.',
                'razon' => 'SEFE devolvió un error al procesar la regularización CAFC.',
                'sefe' => $e->response?->json(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'ok' => false,
                'estado' => 'ERROR',
                'mensaje' => 'No se pudo regularizar la contingencia.',
                'razon' => 'No se pudo conectar con SEFE.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('FacturaVentaApi contingencia unexpected error', [
                'msg' => $e->getMessage(),
                'trace_line' => $e->getLine(),
                'trace_file' => $e->getFile(),
            ]);

            return response()->json([
                'ok' => false,
                'estado' => 'ERROR',
                'mensaje' => 'No se pudo regularizar la contingencia.',
                'razon' => 'Ocurrió un error inesperado al procesar la regularización CAFC.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function pdf(string $codigoSeguimiento)
    {
        try {
            Log::info('FacturaVentaApi pdf started', [
                'codigoSeguimiento' => $codigoSeguimiento,
            ]);

            $notificacion = Notificacione::query()
                ->where('codigo_seguimiento', $codigoSeguimiento)
                ->latest('id')
                ->first();

            if (!$notificacion) {
                Log::warning('FacturaVentaApi pdf notification missing', [
                    'codigoSeguimiento' => $codigoSeguimiento,
                ]);
                return response()->json([
                    'message' => 'Aun no existe una notificacion asociada a la factura.',
                ], 404);
            }

            $detalle = json_decode((string) $notificacion->detalle, true) ?: [];
            $urlPdf = $detalle['urlPdf'] ?? null;
            $cuf = $detalle['cuf'] ?? null;

            if ($urlPdf) {
                Log::info('FacturaVentaApi pdf available', [
                    'codigoSeguimiento' => $codigoSeguimiento,
                    'notificacion_id' => $notificacion->id,
                    'cuf' => $cuf,
                    'pdf_url' => $urlPdf,
                ]);
                return response()->json([
                    'pdf_url' => $urlPdf,
                    'cuf' => $cuf,
                ]);
            }

            Log::warning('FacturaVentaApi pdf missing url', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'notificacion_id' => $notificacion->id,
                'cuf' => $cuf,
            ]);

            return response()->json([
                'message' => 'La factura aun no tiene PDF disponible.',
                'cuf' => $cuf,
            ], 404);
        } catch (\Throwable $e) {
            Log::error('FacturaVentaApi pdf unexpected error', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'msg' => $e->getMessage(),
                'trace_line' => $e->getLine(),
                'trace_file' => $e->getFile(),
            ]);

            return response()->json([
                'message' => 'Error inesperado al obtener el PDF de la factura.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
