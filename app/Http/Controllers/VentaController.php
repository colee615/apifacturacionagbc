<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use App\Models\Notificacione;
use App\Support\SufeSectorUnoValidator;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class VentaController extends Controller
{
    private static ?bool $hasOrigenUsuarioAliasColumn = null;
    private static ?bool $hasOrigenUsuarioCarnetColumn = null;
    private static ?bool $hasOrigenUsuarioEmailColumn = null;
    private static ?bool $hasOrigenSucursalCodigoColumn = null;

    public function __construct(
        private readonly SufeSectorUnoValidator $sufeValidator
    ) {
    }

  
    private function ageticBaseUrl(): string
    {
        return rtrim(config('services.agetic.base_url', 'https://sefe.demo.agetic.gob.bo'), '/');
    }

    private function ageticClient()
    {
        $token = config('services.agetic.token');

        return Http::withHeaders([
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ])
            ->withOptions([
                'force_ip_resolve' => 'v4',
            ])
            ->connectTimeout(20)
            ->timeout(60)
            // Reintenta solo si es problema de conexiÃ³n (timeouts, etc.)
            ->retry(3, 800, function ($exception) {
                return $exception instanceof ConnectionException;
            });
    }

    // =========================
    //  codigoOrden desde ID
    // =========================
    private function codigoOrdenFromId(int $id): string
    {
        return Venta::formatCodigoOrdenFromNumber($id);
    }

    private function loadVentaRelations(Venta $venta): Venta
    {
        return $venta->loadMissing(['detalleVentas']);
    }

    private function latestNotificationForVenta(Venta $venta): ?Notificacione
    {
        if (blank($venta->codigoSeguimiento) || Str::startsWith($venta->codigoSeguimiento, 'pendiente-')) {
            return null;
        }

        return Notificacione::where('codigo_seguimiento', $venta->codigoSeguimiento)
            ->latest('id')
            ->first();
    }

    private function protocolStatusForVenta(Venta $venta): array
    {
        $notification = $this->latestNotificationForVenta($venta);
        $detalle = $notification ? json_decode($notification->detalle, true) : [];
        $estadoSufe = strtoupper((string) ($venta->estado_sufe ?? ''));

        if (blank($venta->codigoSeguimiento) || Str::startsWith((string) $venta->codigoSeguimiento, 'pendiente-')) {
            return [
                'key' => 'PENDIENTE',
                'label' => 'Pendiente de envío',
                'can_emit' => true,
                'can_massive' => true,
                'can_cafc' => true,
                'can_consult' => false,
                'can_annul' => false,
                'notification_state' => null,
                'tipoEmision' => null,
                'cuf' => null,
            ];
        }

        if (!$notification) {
            if ($estadoSufe === 'REGISTRADA_OFICIAL') {
                return [
                    'key' => 'REGISTRADA_OFICIAL',
                    'label' => 'Registrada sin facturacion',
                    'can_emit' => false,
                    'can_massive' => false,
                    'can_cafc' => false,
                    'can_consult' => false,
                    'can_annul' => false,
                    'notification_state' => null,
                    'tipoEmision' => null,
                    'cuf' => null,
                ];
            }

            if ($estadoSufe === 'PROCESADA' || !blank($venta->cuf)) {
                return [
                    'key' => 'PROCESADO',
                    'label' => 'Facturada',
                    'can_emit' => false,
                    'can_massive' => false,
                    'can_cafc' => false,
                    'can_consult' => true,
                    'can_annul' => !blank($venta->cuf),
                    'notification_state' => null,
                    'tipoEmision' => $venta->tipo_emision_sufe,
                    'cuf' => $venta->cuf,
                ];
            }

            if ($estadoSufe === 'OBSERVADA') {
                return [
                    'key' => 'OBSERVADO',
                    'label' => 'Observado',
                    'can_emit' => true,
                    'can_massive' => true,
                    'can_cafc' => true,
                    'can_consult' => true,
                    'can_annul' => false,
                    'notification_state' => null,
                    'tipoEmision' => $venta->tipo_emision_sufe,
                    'cuf' => $venta->cuf,
                ];
            }

            if ($estadoSufe === 'CONTINGENCIA_CREADA') {
                return [
                    'key' => 'CONTINGENCIA_CREADA',
                    'label' => 'Pendiente por contingencia',
                    'can_emit' => false,
                    'can_massive' => false,
                    'can_cafc' => false,
                    'can_consult' => true,
                    'can_annul' => false,
                    'notification_state' => null,
                    'tipoEmision' => $venta->tipo_emision_sufe,
                    'cuf' => $venta->cuf,
                ];
            }

            if ($estadoSufe === 'RECEPCIONADA' || $estadoSufe === '') {
                return [
                    'key' => 'RECEPCIONADA',
                    'label' => 'En proceso',
                    'can_emit' => false,
                    'can_massive' => false,
                    'can_cafc' => false,
                    'can_consult' => true,
                    'can_annul' => false,
                    'notification_state' => null,
                    'tipoEmision' => $venta->tipo_emision_sufe,
                    'cuf' => $venta->cuf,
                ];
            }

            return [
                'key' => 'PENDIENTE_CONFIRMACION',
                'label' => 'En proceso',
                'can_emit' => false,
                'can_massive' => false,
                'can_cafc' => false,
                'can_consult' => true,
                'can_annul' => false,
                'notification_state' => null,
                'tipoEmision' => $venta->tipo_emision_sufe,
                'cuf' => $venta->cuf,
            ];
        }

        $estado = $notification->estado;
        $tipoEmision = data_get($detalle, 'tipoEmision');
        $cuf = data_get($detalle, 'cuf');

        if ($estado === 'EXITO') {
            return [
                'key' => 'PROCESADO',
                'label' => 'Facturada',
                'can_emit' => false,
                'can_massive' => false,
                'can_cafc' => false,
                'can_consult' => true,
                'can_annul' => !blank($cuf),
                'notification_state' => $estado,
                'tipoEmision' => $tipoEmision,
                'cuf' => $cuf,
            ];
        }

        if ($estado === 'OBSERVADO') {
            return [
                'key' => 'OBSERVADO',
                'label' => 'Observada',
                'can_emit' => true,
                'can_massive' => true,
                'can_cafc' => true,
                'can_consult' => true,
                'can_annul' => false,
                'notification_state' => $estado,
                'tipoEmision' => $tipoEmision,
                'cuf' => $cuf,
            ];
        }

        if ($estado === 'CREADO') {
            return [
                'key' => 'CONTINGENCIA_CREADA',
                'label' => 'Pendiente por contingencia',
                'can_emit' => false,
                'can_massive' => false,
                'can_cafc' => false,
                'can_consult' => true,
                'can_annul' => false,
                'notification_state' => $estado,
                'tipoEmision' => $tipoEmision,
                'cuf' => $cuf,
            ];
        }

        return [
            'key' => 'DESCONOCIDO',
            'label' => 'Estado desconocido',
            'can_emit' => false,
            'can_massive' => false,
            'can_cafc' => false,
            'can_consult' => true,
            'can_annul' => false,
            'notification_state' => $estado,
            'tipoEmision' => $tipoEmision,
            'cuf' => $cuf,
        ];
    }

    private function syncVentaFromConsulta(string $codigoSeguimiento, array $payload): void
    {
        $estadoConsulta = strtoupper((string) ($payload['estado'] ?? ''));

        $estadoSufe = match ($estadoConsulta) {
            'PROCESADO' => 'PROCESADA',
            'OBSERVADO' => 'OBSERVADA',
            'ANULADO' => 'ANULADA',
            'PENDIENTE' => 'RECEPCIONADA',
            default => null,
        };

        $update = array_filter([
            'estado_sufe' => $estadoSufe,
            'cuf' => $payload['cuf'] ?? null,
            'observacion_sufe' => $payload['observacion'] ?? null,
            'updated_at' => now(),
        ], function ($value, $key) {
            if ($key === 'updated_at') {
                return true;
            }

            return $value !== null;
        }, ARRAY_FILTER_USE_BOTH);

        if (!empty($update)) {
            Venta::query()
                ->where('codigoSeguimiento', $codigoSeguimiento)
                ->update($update);
        }
    }

    private function detalleVentaPayload(DetalleVenta $detalleVenta): array
    {
        return [
            'actividadEconomica' => $detalleVenta->actividadEconomica,
            'codigoSin' => $detalleVenta->codigoSin,
            'codigo' => $detalleVenta->codigo,
            'descripcion' => $detalleVenta->descripcion,
            'precioUnitario' => (float) $detalleVenta->precio,
            'cantidad' => (float) $detalleVenta->cantidad,
            'unidadMedida' => (int) $detalleVenta->unidadMedida,
        ];
    }

    private function normalizeFacturacionCartCodigoOrden(object $cart): string
    {
        $codigoOrden = trim((string) ($cart->codigo_orden ?? ''));
        if ($codigoOrden === '') {
            return '';
        }

        $canalEmision = strtolower(trim((string) ($cart->canal_emision ?? 'factura_electronica')));
        if (!in_array($canalEmision, ['factura_electronica', 'qr'], true)) {
            $canalEmision = strtolower(trim((string) ($cart->metodo_pago ?? ''))) === 'qr' ? 'qr' : 'factura_electronica';
        }

        if (preg_match('/^(?:qv|fvc|vfc)-(\d+)$/i', $codigoOrden, $matches)) {
            return Venta::formatCodigoOrdenFromNumberWithPrefix(
                (int) $matches[1],
                $canalEmision === 'qr' ? Venta::CODIGO_ORDEN_QR_PREFIX : Venta::CODIGO_ORDEN_PREFIX
            );
        }

        if (preg_match('/^(?:fqc|vqc)-(\d+)$/i', $codigoOrden, $matches)) {
            return Venta::formatCodigoOrdenFromNumberWithPrefix((int) $matches[1], Venta::CODIGO_ORDEN_QR_PREFIX);
        }

        return $codigoOrden;
    }

    private function individualPayloadFromVenta(Venta $venta, ?string $codigoOrden = null): array
    {
        $venta = $this->loadVentaRelations($venta);

        return [
            'codigoOrden' => $codigoOrden ?: $venta->codigoOrden,
            'codigoSucursal' => (int) $venta->codigoSucursal,
            'puntoVenta' => (int) $venta->puntoVenta,
            'documentoSector' => (int) $venta->documentoSector,
            'municipio' => $venta->municipio,
            'departamento' => $venta->departamento,
            'telefono' => $venta->telefono,
            'razonSocial' => $venta->razonSocial,
            'documentoIdentidad' => $venta->documentoIdentidad,
            'tipoDocumentoIdentidad' => (int) $venta->tipoDocumentoIdentidad,
            'complemento' => $venta->complemento,
            'correo' => $venta->correo,
            'codigoCliente' => $venta->codigoCliente,
            'metodoPago' => (int) $venta->metodoPago,
            'montoTotal' => (float) $venta->total,
            'montoDescuentoAdicional' => (float) $venta->monto_descuento_adicional,
            'formatoFactura' => $venta->formatoFactura,
            'detalle' => $venta->detalleVentas->map(fn ($detalleVenta) => $this->detalleVentaPayload($detalleVenta))->values()->all(),
        ];
    }

    private function massiveItemFromVenta(Venta $venta, ?string $codigoOrden = null): array
    {
        $payload = $this->individualPayloadFromVenta($venta, $codigoOrden);
        $payload['fechaEmision'] = optional($venta->created_at)->format('Y-m-d H:i:s');
        unset($payload['codigoSucursal'], $payload['puntoVenta'], $payload['documentoSector']);

        return $payload;
    }

    private function cafcItemFromVenta(Venta $venta, int $nroFactura, ?string $codigoOrden = null): array
    {
        $payload = $this->individualPayloadFromVenta($venta, $codigoOrden);
        $payload['nroFactura'] = $nroFactura;
        $payload['fechaEmision'] = optional($venta->created_at)->format('Y-m-d');
        unset($payload['codigoSucursal'], $payload['puntoVenta'], $payload['documentoSector']);

        return $payload;
    }

    private function ventasFromIds(array $ventaIds)
    {
        $ventas = Venta::whereIn('id', $ventaIds)
            ->where('estado', 1)
            ->with(['detalleVentas'])
            ->get()
            ->keyBy('id');

        if ($ventas->count() !== count(array_unique($ventaIds))) {
            throw ValidationException::withMessages([
                'venta_ids' => ['Una o más ventas no existen o no están disponibles.'],
            ]);
        }

        return collect($ventaIds)->map(fn ($id) => $ventas[(int) $id])->values();
    }

    private function assertSameOperationalContext($ventas): void
    {
        $first = $ventas->first();
        foreach ($ventas as $venta) {
            if (
                (int) $venta->codigoSucursal !== (int) $first->codigoSucursal ||
                (int) $venta->puntoVenta !== (int) $first->puntoVenta ||
                (int) $venta->documentoSector !== (int) $first->documentoSector
            ) {
                throw ValidationException::withMessages([
                    'venta_ids' => ['Todas las ventas seleccionadas deben pertenecer a la misma sucursal, punto de venta y documento sector.'],
                ]);
            }
        }
    }

    private function canOperateVenta(Venta $venta): bool
    {
        $status = $this->protocolStatusForVenta($venta);
        return $status['can_emit'] || $status['can_massive'] || $status['can_cafc'];
    }

    private function operationRow(Venta $venta): array
    {
        $venta = $this->loadVentaRelations($venta);
        $status = $this->protocolStatusForVenta($venta);
        $isOficial = strtoupper((string) ($venta->estado_sufe ?? '')) === 'REGISTRADA_OFICIAL';

        return [
            'id' => $venta->id,
            'codigoOrden' => $venta->codigoOrden,
            'codigoSeguimiento' => $venta->codigoSeguimiento,
            'fecha' => optional($venta->created_at)->format('Y-m-d H:i:s'),
            'cliente' => [
                'id' => null,
                'razonSocial' => $venta->razonSocial,
                'documentoIdentidad' => $isOficial ? null : $venta->documentoIdentidad,
                'codigoCliente' => $venta->codigoCliente,
            ],
            'total' => (float) $venta->total,
            'codigoSucursal' => (int) $venta->codigoSucursal,
            'puntoVenta' => (int) $venta->puntoVenta,
            'documentoSector' => (int) $venta->documentoSector,
            'status' => $status,
            'detalle' => $venta->detalleVentas->map(function ($detalleVenta) {
                return [
                    'codigo' => $detalleVenta->codigo,
                    'descripcion' => $detalleVenta->descripcion,
                    'cantidad' => (float) $detalleVenta->cantidad,
                    'precio' => (float) $detalleVenta->precio,
                ];
            })->values()->all(),
        ];
    }

    private function validateVentaReportFilters(Request $request): array
    {
        return $request->validate([
            'fechaInicio' => ['nullable', 'date_format:Y-m-d'],
            'fechaFin' => ['nullable', 'date_format:Y-m-d'],
            'origen_usuario_id' => ['nullable', 'string', 'max:100'],
            'origen_usuario_email' => ['nullable', 'string', 'max:120'],
            'origen_usuario_alias' => ['nullable', 'string', 'max:80'],
            'origen_usuario_carnet' => ['nullable', 'string', 'max:40'],
            'origen_sucursal_id' => ['nullable', 'string', 'max:100'],
            'origen_venta_id' => ['nullable', 'string', 'max:100'],
            'origen_venta_tipo' => ['nullable', 'string', 'max:100'],
            'codigoSucursal' => ['nullable', 'integer', 'min:0'],
            'puntoVenta' => ['nullable', 'integer', 'min:0'],
            'estado_sufe' => ['nullable', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
    }

    private function normalizeCarnet(?string $value): ?string
    {
        $clean = strtoupper(trim((string) $value));
        if ($clean === '') {
            return null;
        }

        return preg_replace('/\s+/', '', $clean) ?: null;
    }

    private function resolveIdentityFilters(Request $request, array $filters): array
    {
        $usuario = Auth::guard('api')->user() ?? $request->user();
        $isAdmin = $usuario && method_exists($usuario, 'hasRole') && $usuario->hasRole('admin');

        if ($isAdmin) {
            $filters['origen_usuario_email'] = strtolower(trim((string) ($filters['origen_usuario_email'] ?? ''))) ?: null;
            $filters['origen_usuario_alias'] = strtolower(trim((string) ($filters['origen_usuario_alias'] ?? ''))) ?: null;
            $filters['origen_usuario_carnet'] = $this->normalizeCarnet($filters['origen_usuario_carnet'] ?? null);
            return $filters;
        }

        if (!$usuario) {
            return $filters;
        }

        $filters['origen_usuario_email'] = strtolower(trim((string) ($usuario->email ?? ''))) ?: null;
        $filters['origen_usuario_alias'] = strtolower(trim((string) ($usuario->alias ?? ''))) ?: null;
        $filters['origen_usuario_carnet'] = $this->normalizeCarnet((string) ($usuario->numero_carnet ?? ''));

        return $filters;
    }

    private function buildVentaReportQuery(array $filters)
    {
        $query = Venta::query();

        return $this->applyVentaFilters($query, $filters);
    }

    private function applyVentaFilters($query, array $filters)
    {
        $query = $query->where('estado', 1);

        if (Schema::hasTable('facturacion_carts')) {
            $query->where(function ($scope) {
                $scope->whereNotIn('origen_venta_tipo', ['facturacion_cart', 'facturacion_cart_remote'])
                    ->orWhereNull('origen_venta_tipo')
                    ->orWhereNotExists(function ($draftCart) {
                        $draftCart->select(DB::raw('1'))
                            ->from('facturacion_carts as fc')
                            ->whereRaw("cast(fc.id as varchar) = cast(ventas.origen_venta_id as varchar)")
                            ->whereRaw("lower(coalesce(fc.estado, '')) = 'borrador'");
                    });
            });
        }

        if (!empty($filters['fechaInicio'])) {
            $query->whereDate('created_at', '>=', $filters['fechaInicio']);
        }

        if (!empty($filters['fechaFin'])) {
            $query->whereDate('created_at', '<=', $filters['fechaFin']);
        }

        foreach (['origen_usuario_id', 'origen_sucursal_id', 'origen_venta_id', 'origen_venta_tipo'] as $field) {
            if (!empty($filters[$field])) {
                $query->where($field, (string) $filters[$field]);
            }
        }

        if (!empty($filters['origen_usuario_email']) && $this->hasOrigenUsuarioEmailColumn()) {
            $query->whereRaw('lower(coalesce(origen_usuario_email, ?)) = ?', ['', strtolower((string) $filters['origen_usuario_email'])]);
        }

        if (!empty($filters['origen_usuario_alias']) && $this->hasOrigenUsuarioAliasColumn()) {
            $query->whereRaw('lower(coalesce(origen_usuario_alias, ?)) = ?', ['', strtolower((string) $filters['origen_usuario_alias'])]);
        }

        if (!empty($filters['origen_usuario_carnet']) && $this->hasOrigenUsuarioCarnetColumn()) {
            $query->whereRaw("upper(replace(coalesce(origen_usuario_carnet, ''), ' ', '')) = ?", [(string) $filters['origen_usuario_carnet']]);
        }

        if (array_key_exists('codigoSucursal', $filters) && $filters['codigoSucursal'] !== null) {
            $query->where('codigoSucursal', (int) $filters['codigoSucursal']);
        }

        if (array_key_exists('puntoVenta', $filters) && $filters['puntoVenta'] !== null) {
            $query->where('puntoVenta', (int) $filters['puntoVenta']);
        }

        if (!empty($filters['estado_sufe'])) {
            $query->whereRaw('upper(coalesce(estado_sufe, ?)) = ?', ['', strtoupper((string) $filters['estado_sufe'])]);
        }

        if (!empty($filters['q'])) {
            $term = '%' . trim((string) $filters['q']) . '%';
            $query->where(function ($search) use ($term) {
                $search->where('codigoOrden', 'like', $term)
                    ->orWhere('codigoSeguimiento', 'like', $term)
                    ->orWhere('razonSocial', 'like', $term)
                    ->orWhere('documentoIdentidad', 'like', $term)
                    ->orWhere('codigoCliente', 'like', $term)
                    ->orWhere('origen_usuario_id', 'like', $term)
                    ->orWhere('origen_usuario_nombre', 'like', $term)
                    ->orWhere('origen_sucursal_nombre', 'like', $term);

                if ($this->hasOrigenUsuarioEmailColumn()) {
                    $search->orWhere('origen_usuario_email', 'like', $term);
                }
                if ($this->hasOrigenUsuarioAliasColumn()) {
                    $search->orWhere('origen_usuario_alias', 'like', $term);
                }
                if ($this->hasOrigenUsuarioCarnetColumn()) {
                    $search->orWhere('origen_usuario_carnet', 'like', $term);
                }
            });
        }

        return $query;
    }

    private function requestIdentityFilters(Request $request): array
    {
        return $this->resolveIdentityFilters($request, [
            'origen_usuario_id' => trim((string) $request->query('origen_usuario_id', '')) ?: null,
            'origen_usuario_email' => trim((string) $request->query('origen_usuario_email', '')) ?: null,
            'origen_usuario_alias' => trim((string) $request->query('origen_usuario_alias', '')) ?: null,
            'origen_usuario_carnet' => trim((string) $request->query('origen_usuario_carnet', '')) ?: null,
            'origen_sucursal_id' => trim((string) $request->query('origen_sucursal_id', '')) ?: null,
            'codigoSucursal' => $request->query('codigoSucursal'),
            'puntoVenta' => $request->query('puntoVenta'),
        ]);
    }

    private function hasOrigenUsuarioAliasColumn(): bool
    {
        if (self::$hasOrigenUsuarioAliasColumn === null) {
            self::$hasOrigenUsuarioAliasColumn = Schema::hasColumn('ventas', 'origen_usuario_alias');
        }

        return self::$hasOrigenUsuarioAliasColumn;
    }

    private function hasOrigenUsuarioCarnetColumn(): bool
    {
        if (self::$hasOrigenUsuarioCarnetColumn === null) {
            self::$hasOrigenUsuarioCarnetColumn = Schema::hasColumn('ventas', 'origen_usuario_carnet');
        }

        return self::$hasOrigenUsuarioCarnetColumn;
    }

    private function hasOrigenUsuarioEmailColumn(): bool
    {
        if (self::$hasOrigenUsuarioEmailColumn === null) {
            self::$hasOrigenUsuarioEmailColumn = Schema::hasColumn('ventas', 'origen_usuario_email');
        }

        return self::$hasOrigenUsuarioEmailColumn;
    }

    private function hasOrigenSucursalCodigoColumn(): bool
    {
        if (self::$hasOrigenSucursalCodigoColumn === null) {
            self::$hasOrigenSucursalCodigoColumn = Schema::hasColumn('ventas', 'origen_sucursal_codigo');
        }

        return self::$hasOrigenSucursalCodigoColumn;
    }

    private function extractNumeroFacturaFromDetalle(?string $detalle): ?string
    {
        if (!$detalle) {
            return null;
        }

        $decoded = json_decode($detalle, true);
        if (!is_array($decoded)) {
            return null;
        }

        $candidates = [
            data_get($decoded, 'nroFactura'),
            data_get($decoded, 'numeroFactura'),
            data_get($decoded, 'factura.nroFactura'),
            data_get($decoded, 'factura.numeroFactura'),
            data_get($decoded, 'consultaSefe.nroFactura'),
            data_get($decoded, 'consultaSefe.numeroFactura'),
            data_get($decoded, 'datos.nroFactura'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function numeroFacturaMapFromSeguimientos(array $seguimientos): array
    {
        $seguimientos = array_values(array_unique(array_filter(array_map(
            fn ($v) => trim((string) $v),
            $seguimientos
        ))));

        if ($seguimientos === []) {
            return [];
        }

        $map = [];
        $notificaciones = Notificacione::query()
            ->whereIn('codigo_seguimiento', $seguimientos)
            ->orderByDesc('id')
            ->get(['codigo_seguimiento', 'detalle']);

        foreach ($notificaciones as $notificacion) {
            $codigoSeguimiento = trim((string) $notificacion->codigo_seguimiento);
            if ($codigoSeguimiento === '' || array_key_exists($codigoSeguimiento, $map)) {
                continue;
            }

            $numeroFactura = $this->extractNumeroFacturaFromDetalle((string) $notificacion->detalle);
            if ($numeroFactura !== null) {
                $map[$codigoSeguimiento] = $numeroFactura;
            }
        }

        return $map;
    }

    private function numeroFacturaMapFromBridgeCartRows($ventasRows): array
    {
        $cartIds = collect($ventasRows)
            ->filter(function ($row) {
                return (string) ($row->origen_venta_tipo ?? '') === 'facturacion_cart_remote'
                    && trim((string) ($row->origen_venta_id ?? '')) !== '';
            })
            ->pluck('origen_venta_id')
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values()
            ->all();

        if ($cartIds === []) {
            return [];
        }

        $map = [];
        $rows = DB::table('facturacion_carts')
            ->whereIn('id', $cartIds)
            ->get(['id', 'respuesta_emision']);

        foreach ($rows as $row) {
            $numeroFactura = $this->extractNumeroFacturaFromDetalle((string) ($row->respuesta_emision ?? ''));
            if ($numeroFactura !== null) {
                $map[(int) $row->id] = $numeroFactura;
            }
        }

        return $map;
    }

    private function itemsCountMapsFromRows($ventasRows): array
    {
        $ventaIds = collect($ventasRows)
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values()
            ->all();

        $cartIds = collect($ventasRows)
            ->filter(function ($row) {
                return in_array((string) ($row->origen_venta_tipo ?? ''), ['facturacion_cart', 'facturacion_cart_remote'], true)
                    && (int) ($row->origen_venta_id ?? 0) > 0;
            })
            ->pluck('origen_venta_id')
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values()
            ->all();

        $detalleCounts = [];
        if ($ventaIds !== []) {
            $detalleCounts = DB::table('detalle_ventas')
                ->selectRaw('venta_id, count(*) as cantidad')
                ->whereIn('venta_id', $ventaIds)
                ->groupBy('venta_id')
                ->pluck('cantidad', 'venta_id')
                ->map(fn ($count) => (int) $count)
                ->toArray();
        }

        $cartCounts = [];
        if ($cartIds !== [] && DB::table('facturacion_cart_items')->exists()) {
            $cartCounts = DB::table('facturacion_cart_items')
                ->selectRaw('cart_id, count(*) as cantidad')
                ->whereIn('cart_id', $cartIds)
                ->groupBy('cart_id')
                ->pluck('cantidad', 'cart_id')
                ->map(fn ($count) => (int) $count)
                ->toArray();
        }

        return [
            'detalle' => $detalleCounts,
            'cart' => $cartCounts,
        ];
    }

    private function detalleMapsFromRows($ventasRows): array
    {
        $ventaIds = collect($ventasRows)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $detalleVentasMap = [];
        if ($ventaIds !== []) {
            $detalleVentasMap = DB::table('detalle_ventas')
                ->whereIn('venta_id', $ventaIds)
                ->orderBy('id')
                ->get(['venta_id', 'id', 'codigo', 'descripcion', 'cantidad', 'precio'])
                ->groupBy('venta_id')
                ->map(function ($items) {
                    return collect($items)->map(function ($item) {
                        $cantidad = (float) ($item->cantidad ?? 1);
                        $base = (float) ($item->precio ?? 0);

                        return [
                            'id' => (int) ($item->id ?? 0),
                            'codigo' => (string) ($item->codigo ?? ''),
                            'descripcion' => (string) ($item->descripcion ?? 'Sin detalle'),
                            'titulo' => (string) ($item->descripcion ?? 'Sin detalle'),
                            'nombre_servicio' => (string) ($item->descripcion ?? 'Sin detalle'),
                            'nombre_destinatario' => null,
                            'origen_tipo' => 'detalle_venta',
                            'resumen_origen' => [],
                            'cantidad' => $cantidad,
                            'precio' => $base,
                            'monto_base' => $base,
                            'monto_extras' => 0.0,
                            'total_linea' => round($cantidad * $base, 2),
                        ];
                    })->values()->all();
                })
                ->toArray();
        }

        $cartIds = collect($ventasRows)
            ->filter(fn ($venta) => in_array((string) ($venta->origen_venta_tipo ?? ''), ['facturacion_cart', 'facturacion_cart_remote'], true))
            ->pluck('origen_venta_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $cartItemsMap = [];
        if ($cartIds !== [] && Schema::hasTable('facturacion_cart_items')) {
            $cartItemsMap = DB::table('facturacion_cart_items')
                ->whereIn('cart_id', $cartIds)
                ->orderBy('id')
                ->get([
                    'cart_id',
                    'id',
                    'codigo',
                    'titulo',
                    'nombre_servicio',
                    'nombre_destinatario',
                    'origen_tipo',
                    'resumen_origen',
                    'cantidad',
                    'monto_base',
                    'monto_extras',
                    'total_linea',
                ])
                ->groupBy('cart_id')
                ->map(function ($items) {
                    return collect($items)->map(function ($item) {
                        $resumen = json_decode((string) ($item->resumen_origen ?? ''), true);
                        if (!is_array($resumen)) {
                            $resumen = [];
                        }

                        $cantidad = (float) ($item->cantidad ?? 1);
                        $base = (float) ($item->monto_base ?? 0);
                        $extras = (float) ($item->monto_extras ?? 0);
                        $totalLinea = (float) ($item->total_linea ?? round(($base + $extras) * max(1, $cantidad), 2));
                        $titulo = trim((string) ($item->titulo ?? ''));
                        $servicio = trim((string) ($item->nombre_servicio ?? ''));

                        return [
                            'id' => (int) ($item->id ?? 0),
                            'codigo' => (string) (($item->codigo ?? '') !== '' ? $item->codigo : ('ITEM-' . (int) $item->id)),
                            'descripcion' => (string) ($titulo !== '' ? $titulo : ($servicio !== '' ? $servicio : 'Sin detalle')),
                            'titulo' => (string) ($titulo !== '' ? $titulo : ($servicio !== '' ? $servicio : 'Sin detalle')),
                            'nombre_servicio' => (string) ($servicio !== '' ? $servicio : $titulo),
                            'nombre_destinatario' => (string) ($item->nombre_destinatario ?? ''),
                            'origen_tipo' => (string) ($item->origen_tipo ?? ''),
                            'resumen_origen' => $resumen,
                            'cantidad' => $cantidad,
                            'precio' => $base,
                            'monto_base' => $base,
                            'monto_extras' => $extras,
                            'total_linea' => $totalLinea,
                        ];
                    })->values()->all();
                })
                ->toArray();
        }

        return [
            'detalle' => $detalleVentasMap,
            'cart' => $cartItemsMap,
        ];
    }

    public function kardexUsuarios(Request $request)
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $baseQuery = $this->buildVentaReportQuery($filters);

        $rows = (clone $baseQuery)
            ->selectRaw("
                coalesce(origen_usuario_id, 'SIN-USUARIO') as origen_usuario_id,
                coalesce(origen_usuario_nombre, 'SIN USUARIO') as origen_usuario_nombre,
                count(*) as cantidad_ventas,
                sum(total) as total_vendido,
                min(created_at) as primera_venta,
                max(created_at) as ultima_venta,
                sum(case when upper(coalesce(estado_sufe, '')) = 'PROCESADA' then 1 else 0 end) as facturadas,
                sum(case when upper(coalesce(estado_sufe, '')) = 'OBSERVADA' then 1 else 0 end) as observadas,
                sum(case when upper(coalesce(estado_sufe, '')) in ('RECEPCIONADA', 'CONTINGENCIA_CREADA') then 1 else 0 end) as pendientes
            ")
            ->groupByRaw("coalesce(origen_usuario_id, 'SIN-USUARIO'), coalesce(origen_usuario_nombre, 'SIN USUARIO')")
            ->orderByDesc('total_vendido')
            ->orderBy('origen_usuario_nombre')
            ->get();

        $detalle = collect();
        if (
            !empty($filters['origen_usuario_id'])
            || !empty($filters['origen_usuario_email'])
            || !empty($filters['origen_usuario_alias'])
            || !empty($filters['origen_usuario_carnet'])
        ) {
            $detalleColumns = [
                'id',
                'created_at',
                'codigoOrden',
                'codigoSeguimiento',
                'numero_factura',
                'origen_venta_id',
                'origen_venta_tipo',
                'codigoSucursal',
                'puntoVenta',
                'razonSocial',
                'documentoIdentidad',
                'codigoCliente',
                'total',
                'estado_sufe',
                'cuf',
            ];
            if ($this->hasOrigenUsuarioEmailColumn()) {
                $detalleColumns[] = 'origen_usuario_email';
            }
            if ($this->hasOrigenUsuarioAliasColumn()) {
                $detalleColumns[] = 'origen_usuario_alias';
            }
            if ($this->hasOrigenUsuarioCarnetColumn()) {
                $detalleColumns[] = 'origen_usuario_carnet';
            }

            $detalleRows = (clone $baseQuery)
                ->latest('created_at')
                ->limit((int) ($filters['limite'] ?? 200))
                ->get($detalleColumns);
            $numeroFacturaMap = $this->numeroFacturaMapFromSeguimientos($detalleRows->pluck('codigoSeguimiento')->all());
            $numeroFacturaBridgeMap = $this->numeroFacturaMapFromBridgeCartRows($detalleRows);
            $itemsCountMaps = $this->itemsCountMapsFromRows($detalleRows);
            $detalleMaps = $this->detalleMapsFromRows($detalleRows);

            $detalle = $detalleRows->map(function (Venta $venta) use ($numeroFacturaMap, $numeroFacturaBridgeMap, $itemsCountMaps, $detalleMaps) {
                    $codigoSeguimiento = trim((string) $venta->codigoSeguimiento);
                    $origenVentaId = (int) ($venta->origen_venta_id ?? 0);
                    $ventaId = (int) $venta->id;
                    $itemsCount = (int) ($itemsCountMaps['detalle'][$ventaId] ?? 0);
                    if ($itemsCount === 0 && $origenVentaId > 0) {
                        $itemsCount = (int) ($itemsCountMaps['cart'][$origenVentaId] ?? 0);
                    }
                    $cartItems = collect($detalleMaps['cart'][$origenVentaId] ?? []);
                    $detalleItems = collect($detalleMaps['detalle'][$ventaId] ?? []);
                    $items = $cartItems->isNotEmpty() ? $cartItems : $detalleItems;
                    if ($itemsCount === 0) {
                        $itemsCount = $items->count();
                    }
                    return [
                        'id' => $venta->id,
                        'fecha' => optional($venta->created_at)->format('Y-m-d H:i:s'),
                        'codigoOrden' => $venta->codigoOrden,
                        'codigoSeguimiento' => $venta->codigoSeguimiento,
                        'numeroFactura' => ($venta->numero_factura ?? null) ?: ($numeroFacturaMap[$codigoSeguimiento] ?? ($numeroFacturaBridgeMap[$origenVentaId] ?? null)),
                        'origenVentaId' => $venta->origen_venta_id,
                        'origenVentaTipo' => $venta->origen_venta_tipo,
                        'origenUsuarioEmail' => $venta->origen_usuario_email,
                        'origenUsuarioAlias' => $venta->origen_usuario_alias,
                        'origenUsuarioCarnet' => $venta->origen_usuario_carnet,
                        'codigoSucursal' => (int) $venta->codigoSucursal,
                        'puntoVenta' => (int) $venta->puntoVenta,
                        'razonSocial' => $venta->razonSocial,
                        'documentoIdentidad' => strtoupper((string) ($venta->estado_sufe ?? '')) === 'REGISTRADA_OFICIAL' ? null : $venta->documentoIdentidad,
                        'codigoCliente' => $venta->codigoCliente,
                        'total' => (float) $venta->total,
                        'itemsCount' => $itemsCount,
                        'detalle' => $items->values()->all(),
                        'estadoSufe' => $venta->estado_sufe,
                        'cuf' => $venta->cuf,
                    ];
                });
        }

        return response()->json([
            'filters' => $filters,
            'resumen' => [
                'usuarios' => $rows->count(),
                'ventas' => (int) $rows->sum('cantidad_ventas'),
                'totalVendido' => (float) $rows->sum(fn ($row) => (float) $row->total_vendido),
                'facturadas' => (int) $rows->sum('facturadas'),
                'observadas' => (int) $rows->sum('observadas'),
                'pendientes' => (int) $rows->sum('pendientes'),
            ],
            'usuarios' => $rows->map(function ($row) {
                return [
                    'usuarioId' => $row->origen_usuario_id,
                    'usuarioNombre' => $row->origen_usuario_nombre,
                    'cantidadVentas' => (int) $row->cantidad_ventas,
                    'totalVendido' => (float) $row->total_vendido,
                    'facturadas' => (int) $row->facturadas,
                    'observadas' => (int) $row->observadas,
                    'pendientes' => (int) $row->pendientes,
                    'primeraVenta' => $row->primera_venta,
                    'ultimaVenta' => $row->ultima_venta,
                ];
            })->values(),
            'detalle' => $detalle->values(),
        ]);
    }

    public function reporteKardexPdf(Request $request): HttpResponse
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $limite = (int) ($filters['limite'] ?? 500);

        $ventasRows = (clone $this->buildVentaReportQuery($filters))
            ->latest('created_at')
            ->limit($limite)
            ->get([
                'id',
                'created_at',
                'codigoOrden',
                'codigoSeguimiento',
                'numero_factura',
                'origen_venta_id',
                'origen_venta_tipo',
                'codigoSucursal',
                'puntoVenta',
                'total',
            ]);

        $numeroFacturaMap = $this->numeroFacturaMapFromSeguimientos($ventasRows->pluck('codigoSeguimiento')->all());
        $numeroFacturaBridgeMap = $this->numeroFacturaMapFromBridgeCartRows($ventasRows);

        $ventaIds = $ventasRows->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $detalleVentasMap = [];
        if ($ventaIds !== []) {
            $detalleVentasMap = DB::table('detalle_ventas')
                ->whereIn('venta_id', $ventaIds)
                ->orderBy('id')
                ->get(['venta_id', 'id', 'codigo', 'descripcion', 'cantidad', 'precio'])
                ->groupBy('venta_id')
                ->toArray();
        }

        $cartIds = $ventasRows
            ->filter(fn ($venta) => in_array((string) ($venta->origen_venta_tipo ?? ''), ['facturacion_cart', 'facturacion_cart_remote'], true))
            ->pluck('origen_venta_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $cartItemsMap = [];
        if ($cartIds !== [] && Schema::hasTable('facturacion_cart_items')) {
            $cartItemsMap = DB::table('facturacion_cart_items')
                ->whereIn('cart_id', $cartIds)
                ->orderBy('id')
                ->get([
                    'cart_id',
                    'id',
                    'codigo',
                    'titulo',
                    'nombre_servicio',
                    'nombre_destinatario',
                    'resumen_origen',
                    'cantidad',
                    'monto_base',
                    'monto_extras',
                    'total_linea',
                ])
                ->groupBy('cart_id')
                ->toArray();
        }

        $rows = collect();
        foreach ($ventasRows as $venta) {
            $ventaId = (int) $venta->id;
            $origenVentaId = (int) ($venta->origen_venta_id ?? 0);
            $codigoSeguimiento = trim((string) ($venta->codigoSeguimiento ?? ''));
            $numeroFactura = trim((string) (
                $venta->numero_factura
                ?: ($numeroFacturaMap[$codigoSeguimiento] ?? ($numeroFacturaBridgeMap[$origenVentaId] ?? ''))
            ));
            $fecha = optional($venta->created_at)->format('d/m/Y') ?: '-';

            $cartItems = collect($cartItemsMap[$origenVentaId] ?? []);
            if ($cartItems->isNotEmpty()) {
                foreach ($cartItems as $item) {
                    $resumen = json_decode((string) ($item->resumen_origen ?? ''), true);
                    if (!is_array($resumen)) {
                        $resumen = [];
                    }

                    $codigoItem = trim((string) (($resumen['codigo'] ?? null) ?: ($item->codigo ?? '')));
                    $codigoItem = $codigoItem !== '' ? $codigoItem : ('ITEM-' . (int) $item->id);

                    $tipoEnvio = trim((string) ($item->nombre_servicio ?? ''));
                    if ($tipoEnvio === '') {
                        $tipoEnvio = trim((string) ($item->titulo ?? 'SIN SERVICIO'));
                    }

                    $rows->push([
                        'fecha' => $fecha,
                        'origen' => trim((string) ($resumen['ciudad'] ?? $resumen['origen'] ?? '-')) ?: '-',
                        'tipo_envio' => $tipoEnvio !== '' ? $tipoEnvio : 'SIN SERVICIO',
                        'codigo_item' => $codigoItem,
                        'peso' => round((float) ($resumen['peso'] ?? 0), 3),
                        'cantidad' => max(1, (int) ($item->cantidad ?? 1)),
                        'numero_factura' => $numeroFactura !== '' ? $numeroFactura : '-',
                        'importe_parcial' => round((float) ($item->monto_base ?? 0), 2),
                        'importe_general' => round((float) ($item->total_linea ?? 0), 2),
                    ]);
                }

                continue;
            }

            $detalleVentas = collect($detalleVentasMap[$ventaId] ?? []);
            foreach ($detalleVentas as $item) {
                $cantidad = max(1, (int) ($item->cantidad ?? 1));
                $precio = round((float) ($item->precio ?? 0), 2);
                $descripcion = trim((string) ($item->descripcion ?? 'SIN SERVICIO'));
                $codigoServicio = trim((string) ($item->codigo ?? ''));
                $codigoItem = $codigoServicio;
                if ($codigoItem === '' || preg_match('/^SRV[\-0-9A-Z_]*$/i', $codigoItem)) {
                    if (preg_match('/\b(EN[0-9A-Z]+)\b/i', $descripcion, $matchPaquete)) {
                        $codigoItem = strtoupper((string) $matchPaquete[1]);
                    } else {
                        $codigoItem = '-';
                    }
                }

                $rows->push([
                    'fecha' => $fecha,
                    'origen' => '-',
                    'tipo_envio' => $descripcion !== '' ? $descripcion : 'SIN SERVICIO',
                    'codigo_item' => $codigoItem,
                    'peso' => 0.0,
                    'cantidad' => $cantidad,
                    'numero_factura' => $numeroFactura !== '' ? $numeroFactura : '-',
                    'importe_parcial' => $precio,
                    'importe_general' => round($cantidad * $precio, 2),
                ]);
            }
        }

        $totals = [
            'parcial' => round((float) $rows->sum('importe_parcial'), 2),
            'general' => round((float) $rows->sum('importe_general'), 2),
        ];

        $authUser = Auth::guard('api')->user() ?? $request->user();
        $usuario = (object) [
            'name' => trim((string) data_get($authUser, 'nombre', data_get($authUser, 'name', 'Sin responsable'))),
            'sucursal' => (object) [
                'nombre' => trim((string) data_get($authUser, 'sucursal.nombre', '')),
                'descripcion' => trim((string) data_get($authUser, 'sucursal.descripcion', '')),
                'municipio' => trim((string) data_get($authUser, 'sucursal.municipio', '')),
                'puntoVenta' => trim((string) data_get($authUser, 'sucursal.puntoVenta', '')),
            ],
        ];

        $filtersView = [
            'estado' => 'emitido',
            'estado_emision' => (string) ($filters['estado_sufe'] ?? 'all'),
            'from' => $filters['fechaInicio'] ?? null,
            'to' => $filters['fechaFin'] ?? null,
            'q' => trim((string) ($filters['q'] ?? '')),
        ];

        $dummyCarts = $rows->isEmpty()
            ? collect()
            : collect([(object) ['items' => $rows->map(fn ($row) => (object) [
                'titulo' => (string) ($row['tipo_envio'] ?? ''),
                'nombre_servicio' => (string) ($row['tipo_envio'] ?? ''),
            ])->values()]]);

        $html = view('facturacion.mis-ventas-kardex-pdf', [
            'user' => $usuario,
            'filters' => $filtersView,
            'carts' => $dummyCarts,
            'rows' => $rows->values(),
            'totals' => $totals,
            'generatedAt' => now(),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'kardex-facturacion-' . now()->format('Ymd-His') . '.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function reporteVentas(Request $request)
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $baseQuery = $this->buildVentaReportQuery($filters);
        $limite = (int) ($filters['limite'] ?? 100);

        $resumen = (clone $baseQuery)
            ->selectRaw("
                count(*) as cantidad_ventas,
                coalesce(sum(total), 0) as total_vendido,
                coalesce(avg(total), 0) as ticket_promedio,
                sum(case when upper(coalesce(estado_sufe, '')) = 'PROCESADA' then 1 else 0 end) as facturadas,
                sum(case when upper(coalesce(estado_sufe, '')) = 'OBSERVADA' then 1 else 0 end) as observadas,
                sum(case when upper(coalesce(estado_sufe, '')) in ('RECEPCIONADA', 'CONTINGENCIA_CREADA') then 1 else 0 end) as pendientes
            ")
            ->first();

        $porEstado = (clone $baseQuery)
            ->selectRaw("
                coalesce(nullif(upper(estado_sufe), ''), 'SIN_ESTADO') as estado,
                count(*) as cantidad,
                coalesce(sum(total), 0) as total
            ")
            ->groupByRaw("coalesce(nullif(upper(estado_sufe), ''), 'SIN_ESTADO')")
            ->orderByDesc('cantidad')
            ->get();

        $porSucursal = (clone $baseQuery)
            ->select('codigoSucursal', 'puntoVenta')
            ->selectRaw("
                count(*) as cantidad,
                coalesce(sum(total), 0) as total
            ")
            ->groupBy('codigoSucursal', 'puntoVenta')
            ->orderByDesc('total')
            ->get();

        $ventasRows = (clone $baseQuery)
            ->latest('created_at')
            ->limit($limite)
            ->get(array_values(array_filter([
                'id',
                'created_at',
                'codigoOrden',
                'codigoSeguimiento',
                'numero_factura',
                'origen_venta_id',
                'origen_venta_tipo',
                'origen_usuario_id',
                'origen_usuario_nombre',
                $this->hasOrigenUsuarioEmailColumn() ? 'origen_usuario_email' : null,
                $this->hasOrigenUsuarioAliasColumn() ? 'origen_usuario_alias' : null,
                $this->hasOrigenUsuarioCarnetColumn() ? 'origen_usuario_carnet' : null,
                'origen_sucursal_id',
                'origen_sucursal_nombre',
                'codigoSucursal',
                'puntoVenta',
                'razonSocial',
                'documentoIdentidad',
                'codigoCliente',
                'total',
                'estado_sufe',
                'cuf',
            ])));
        $numeroFacturaMap = $this->numeroFacturaMapFromSeguimientos($ventasRows->pluck('codigoSeguimiento')->all());
        $numeroFacturaBridgeMap = $this->numeroFacturaMapFromBridgeCartRows($ventasRows);
        $itemsCountMaps = $this->itemsCountMapsFromRows($ventasRows);

        $ventas = $ventasRows->map(function (Venta $venta) use ($numeroFacturaMap, $numeroFacturaBridgeMap, $itemsCountMaps) {
                $codigoSeguimiento = trim((string) $venta->codigoSeguimiento);
                $origenVentaId = (int) ($venta->origen_venta_id ?? 0);
                $ventaId = (int) $venta->id;
                $itemsCount = (int) ($itemsCountMaps['detalle'][$ventaId] ?? 0);
                if ($itemsCount === 0 && $origenVentaId > 0) {
                    $itemsCount = (int) ($itemsCountMaps['cart'][$origenVentaId] ?? 0);
                }
                return [
                    'id' => $venta->id,
                    'fecha' => optional($venta->created_at)->format('Y-m-d H:i:s'),
                    'codigoOrden' => $venta->codigoOrden,
                    'codigoSeguimiento' => $venta->codigoSeguimiento,
                    'numeroFactura' => ($venta->numero_factura ?? null) ?: ($numeroFacturaMap[$codigoSeguimiento] ?? ($numeroFacturaBridgeMap[$origenVentaId] ?? null)),
                    'origenVentaId' => $venta->origen_venta_id,
                    'origenVentaTipo' => $venta->origen_venta_tipo,
                    'usuario' => [
                        'id' => $venta->origen_usuario_id,
                        'nombre' => $venta->origen_usuario_nombre,
                        'email' => $venta->origen_usuario_email,
                        'alias' => $venta->origen_usuario_alias,
                        'carnet' => $venta->origen_usuario_carnet,
                    ],
                    'sucursal' => [
                        'id' => $venta->origen_sucursal_id,
                        'nombre' => $venta->origen_sucursal_nombre,
                        'codigoSucursal' => (int) $venta->codigoSucursal,
                        'puntoVenta' => (int) $venta->puntoVenta,
                    ],
                    'cliente' => [
                        'razonSocial' => $venta->razonSocial,
                        'documentoIdentidad' => strtoupper((string) ($venta->estado_sufe ?? '')) === 'REGISTRADA_OFICIAL' ? null : $venta->documentoIdentidad,
                        'codigoCliente' => $venta->codigoCliente,
                    ],
                    'itemsCount' => $itemsCount,
                    'total' => (float) $venta->total,
                    'estadoSufe' => $venta->estado_sufe,
                    'cuf' => $venta->cuf,
                ];
            });

        return response()->json([
            'filters' => $filters,
            'resumen' => [
                'cantidadVentas' => (int) ($resumen->cantidad_ventas ?? 0),
                'totalVendido' => (float) ($resumen->total_vendido ?? 0),
                'ticketPromedio' => (float) ($resumen->ticket_promedio ?? 0),
                'facturadas' => (int) ($resumen->facturadas ?? 0),
                'observadas' => (int) ($resumen->observadas ?? 0),
                'pendientes' => (int) ($resumen->pendientes ?? 0),
            ],
            'porEstado' => $porEstado->map(fn ($row) => [
                'estado' => $row->estado,
                'cantidad' => (int) $row->cantidad,
                'total' => (float) $row->total,
            ])->values(),
            'porSucursal' => $porSucursal->map(fn ($row) => [
                'codigoSucursal' => (int) $row->codigoSucursal,
                'puntoVenta' => (int) $row->puntoVenta,
                'cantidad' => (int) $row->cantidad,
                'total' => (float) $row->total,
            ])->values(),
            'ventas' => $ventas->values(),
        ]);
    }

    public function reporteSucursales(Request $request)
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $baseQuery = $this->buildVentaReportQuery($filters);
        $limite = (int) ($filters['limite'] ?? 200);
        $sucursalCodigoExpr = $this->hasOrigenSucursalCodigoColumn()
            ? "coalesce(nullif(origen_sucursal_codigo, ''), cast(coalesce(\"codigoSucursal\", 0) as varchar))"
            : "cast(coalesce(\"codigoSucursal\", 0) as varchar)";

        $resumen = (clone $baseQuery)
            ->selectRaw("
                count(*) as cantidad_ventas,
                coalesce(sum(total), 0) as total_vendido,
                count(distinct coalesce(origen_usuario_id, origen_usuario_email, origen_usuario_alias, origen_usuario_nombre, 'SIN-USUARIO')) as cajeros_unicos,
                sum(case when upper(coalesce(estado_sufe, '')) = 'PROCESADA' then 1 else 0 end) as facturadas,
                sum(case when upper(coalesce(estado_sufe, '')) = 'OBSERVADA' then 1 else 0 end) as observadas,
                sum(case when upper(coalesce(estado_sufe, '')) in ('RECEPCIONADA', 'CONTINGENCIA_CREADA') then 1 else 0 end) as pendientes
            ")
            ->first();

        $porSucursal = (clone $baseQuery)
            ->selectRaw("
                concat(
                    {$sucursalCodigoExpr},
                    '-',
                    coalesce(nullif(origen_sucursal_id, ''), cast(coalesce(\"puntoVenta\", 0) as varchar))
                ) as sucursal_id,
                coalesce(nullif(origen_sucursal_nombre, ''), concat('Sucursal ', \"codigoSucursal\", ' / PV ', \"puntoVenta\"), 'Sin sucursal') as sucursal_nombre,
                {$sucursalCodigoExpr} as codigo_sucursal,
                coalesce(nullif(origen_sucursal_id, ''), cast(coalesce(\"puntoVenta\", 0) as varchar)) as punto_venta,
                coalesce(max(nullif(departamento, '')), '') as departamento,
                count(*) as cantidad_ventas,
                coalesce(sum(total), 0) as total_vendido,
                count(distinct coalesce(origen_usuario_id, origen_usuario_email, origen_usuario_alias, origen_usuario_nombre, 'SIN-USUARIO')) as cajeros_unicos,
                sum(case when upper(coalesce(estado_sufe, '')) = 'PROCESADA' then 1 else 0 end) as facturadas,
                sum(case when upper(coalesce(estado_sufe, '')) = 'OBSERVADA' then 1 else 0 end) as observadas,
                sum(case when upper(coalesce(estado_sufe, '')) in ('RECEPCIONADA', 'CONTINGENCIA_CREADA') then 1 else 0 end) as pendientes,
                min(created_at) as primera_venta,
                max(created_at) as ultima_venta
            ")
            ->groupByRaw("
                concat(
                    {$sucursalCodigoExpr},
                    '-',
                    coalesce(nullif(origen_sucursal_id, ''), cast(coalesce(\"puntoVenta\", 0) as varchar))
                ),
                coalesce(nullif(origen_sucursal_nombre, ''), concat('Sucursal ', \"codigoSucursal\", ' / PV ', \"puntoVenta\"), 'Sin sucursal'),
                {$sucursalCodigoExpr},
                coalesce(nullif(origen_sucursal_id, ''), cast(coalesce(\"puntoVenta\", 0) as varchar))
            ")
            ->orderByDesc('total_vendido')
            ->orderBy('sucursal_nombre')
            ->get();

        $detalleRows = (clone $baseQuery)
            ->latest('created_at')
            ->limit($limite)
            ->get([
                'id',
                'created_at',
                'codigoOrden',
                'codigoSeguimiento',
                'origen_usuario_id',
                'origen_usuario_nombre',
                'origen_sucursal_id',
                'origen_sucursal_nombre',
                'codigoSucursal',
                'puntoVenta',
                'razonSocial',
                'documentoIdentidad',
                'codigoCliente',
                'total',
                'estado_sufe',
            ]);

        return response()->json([
            'filters' => $filters,
            'resumen' => [
                'cantidadVentas' => (int) ($resumen->cantidad_ventas ?? 0),
                'totalVendido' => (float) ($resumen->total_vendido ?? 0),
                'cajerosUnicos' => (int) ($resumen->cajeros_unicos ?? 0),
                'facturadas' => (int) ($resumen->facturadas ?? 0),
                'observadas' => (int) ($resumen->observadas ?? 0),
                'pendientes' => (int) ($resumen->pendientes ?? 0),
            ],
            'sucursales' => $porSucursal->map(fn ($row) => [
                'id' => $row->sucursal_id,
                'nombre' => $row->sucursal_nombre,
                'codigoSucursal' => trim((string) $row->codigo_sucursal),
                'puntoVenta' => trim((string) $row->punto_venta),
                'departamento' => $row->departamento,
                'cantidadVentas' => (int) $row->cantidad_ventas,
                'totalVendido' => (float) $row->total_vendido,
                'cajerosUnicos' => (int) $row->cajeros_unicos,
                'facturadas' => (int) $row->facturadas,
                'observadas' => (int) $row->observadas,
                'pendientes' => (int) $row->pendientes,
                'primeraVenta' => $row->primera_venta,
                'ultimaVenta' => $row->ultima_venta,
            ])->values(),
            'detalle' => $detalleRows->map(fn (Venta $venta) => [
                'id' => $venta->id,
                'fecha' => optional($venta->created_at)->format('Y-m-d H:i:s'),
                'codigoOrden' => $venta->codigoOrden,
                'codigoSeguimiento' => $venta->codigoSeguimiento,
                'usuario' => [
                    'id' => $venta->origen_usuario_id,
                    'nombre' => $venta->origen_usuario_nombre,
                ],
                'sucursal' => [
                    'id' => $venta->origen_sucursal_id,
                    'nombre' => $venta->origen_sucursal_nombre,
                    'codigoSucursal' => (int) $venta->codigoSucursal,
                    'puntoVenta' => (int) $venta->puntoVenta,
                ],
                'cliente' => [
                    'razonSocial' => $venta->razonSocial,
                    'documentoIdentidad' => $venta->documentoIdentidad,
                    'codigoCliente' => $venta->codigoCliente,
                ],
                'total' => (float) $venta->total,
                'estadoSufe' => $venta->estado_sufe,
            ])->values(),
        ]);
    }

    public function reporteSucursalesUsuarios(Request $request)
    {
        $request->validate([
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
            'q' => ['nullable', 'string', 'max:100'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $filters = $this->requestIdentityFilters($request);
        $filters['codigoSucursal'] = (int) $request->query('codigoSucursal');
        $filters['puntoVenta'] = (int) $request->query('puntoVenta');
        $filters['q'] = trim((string) $request->query('q', '')) ?: null;

        $usuarios = (clone $this->buildVentaReportQuery($filters))
            ->selectRaw(
                '
                    coalesce(nullif(origen_usuario_id, \'\'), \'SIN-USUARIO\') as usuario_id,
                    max(coalesce(nullif(origen_usuario_nombre, \'\'), \'Sin usuario\')) as usuario_nombre,
                    ' . ($this->hasOrigenUsuarioEmailColumn() ? "max(nullif(origen_usuario_email, '')) as usuario_email," : "null as usuario_email,") . '
                    ' . ($this->hasOrigenUsuarioAliasColumn() ? "max(nullif(origen_usuario_alias, '')) as usuario_alias," : "null as usuario_alias,") . '
                    ' . ($this->hasOrigenUsuarioCarnetColumn() ? "max(nullif(origen_usuario_carnet, '')) as usuario_carnet," : "null as usuario_carnet,") . '
                    max(coalesce(nullif(origen_sucursal_nombre, \'\'), concat(\'Sucursal \', "codigoSucursal", \' / PV \', "puntoVenta"), \'Sin sucursal\')) as sucursal_nombre,
                    count(*) as cantidad_ventas,
                    coalesce(sum(total), 0) as total_vendido,
                    min(created_at) as primera_venta,
                    max(created_at) as ultima_venta
                '
            )
            ->groupByRaw(
                'coalesce(nullif(origen_usuario_id, \'\'), \'SIN-USUARIO\'), "codigoSucursal", "puntoVenta"'
            )
            ->orderByDesc('cantidad_ventas')
            ->orderBy('usuario_nombre')
            ->get();

        return response()->json([
            'filters' => $filters,
            'sucursal' => [
                'codigoSucursal' => (int) $filters['codigoSucursal'],
                'puntoVenta' => (int) $filters['puntoVenta'],
            ],
            'resumen' => [
                'usuarios' => $usuarios->count(),
                'ventas' => (int) $usuarios->sum('cantidad_ventas'),
                'totalVendido' => (float) $usuarios->sum(fn ($row) => (float) $row->total_vendido),
            ],
            'usuarios' => $usuarios->map(function ($row) {
                return [
                    'usuarioId' => $row->usuario_id,
                    'usuarioNombre' => $row->usuario_nombre,
                    'usuarioEmail' => $row->usuario_email,
                    'usuarioAlias' => $row->usuario_alias,
                    'usuarioCarnet' => $row->usuario_carnet,
                    'sucursalNombre' => $row->sucursal_nombre,
                    'cantidadVentas' => (int) $row->cantidad_ventas,
                    'totalVendido' => (float) $row->total_vendido,
                    'primeraVenta' => $row->primera_venta,
                    'ultimaVenta' => $row->ultima_venta,
                ];
            })->values(),
        ]);
    }

    private function buildFacturacionCartReportQuery(array $filters)
    {
        $query = DB::table('facturacion_carts')
            ->whereRaw("lower(coalesce(estado, '')) <> 'borrador'");

        if (!empty($filters['fechaInicio'])) {
            $query->whereDate('created_at', '>=', $filters['fechaInicio']);
        }

        if (!empty($filters['fechaFin'])) {
            $query->whereDate('created_at', '<=', $filters['fechaFin']);
        }

        foreach (['origen_usuario_id', 'origen_sucursal_id'] as $field) {
            if (!empty($filters[$field]) && Schema::hasColumn('facturacion_carts', $field)) {
                $query->where($field, (string) $filters[$field]);
            }
        }

        if (!empty($filters['origen_usuario_email']) && Schema::hasColumn('facturacion_carts', 'origen_usuario_email')) {
            $query->whereRaw('lower(coalesce(origen_usuario_email, ?)) = ?', ['', strtolower((string) $filters['origen_usuario_email'])]);
        }

        if (!empty($filters['origen_usuario_alias']) && Schema::hasColumn('facturacion_carts', 'origen_usuario_alias')) {
            $query->whereRaw('lower(coalesce(origen_usuario_alias, ?)) = ?', ['', strtolower((string) $filters['origen_usuario_alias'])]);
        }

        if (!empty($filters['origen_usuario_carnet']) && Schema::hasColumn('facturacion_carts', 'origen_usuario_carnet')) {
            $query->whereRaw("upper(replace(coalesce(origen_usuario_carnet, ''), ' ', '')) = ?", [(string) $filters['origen_usuario_carnet']]);
        }

        if (array_key_exists('codigoSucursal', $filters) && $filters['codigoSucursal'] !== null && Schema::hasColumn('facturacion_carts', 'origen_sucursal_codigo')) {
            $query->where('origen_sucursal_codigo', (string) $filters['codigoSucursal']);
        }

        if (array_key_exists('puntoVenta', $filters) && $filters['puntoVenta'] !== null && Schema::hasColumn('facturacion_carts', 'origen_sucursal_id')) {
            $query->where('origen_sucursal_id', (string) $filters['puntoVenta']);
        }

        if (!empty($filters['q'])) {
            $term = '%' . trim((string) $filters['q']) . '%';
            $query->where(function ($search) use ($term) {
                $search->where('codigo_orden', 'like', $term)
                    ->orWhere('codigo_seguimiento', 'like', $term)
                    ->orWhere('codigo_seguimiento_fiscal', 'like', $term)
                    ->orWhere('qr_transaction_id', 'like', $term)
                    ->orWhere('razon_social', 'like', $term)
                    ->orWhere('numero_documento', 'like', $term)
                    ->orWhere('mensaje_emision', 'like', $term)
                    ->orWhere('origen_usuario_id', 'like', $term)
                    ->orWhere('origen_usuario_nombre', 'like', $term)
                    ->orWhere('origen_sucursal_nombre', 'like', $term);

                if (Schema::hasColumn('facturacion_carts', 'origen_usuario_email')) {
                    $search->orWhere('origen_usuario_email', 'like', $term);
                }
                if (Schema::hasColumn('facturacion_carts', 'origen_usuario_alias')) {
                    $search->orWhere('origen_usuario_alias', 'like', $term);
                }
                if (Schema::hasColumn('facturacion_carts', 'origen_usuario_carnet')) {
                    $search->orWhere('origen_usuario_carnet', 'like', $term);
                }
            });
        }

        return $query;
    }

    private function facturacionCartStatusPayload(object $cart): array
    {
        $canal = strtolower(trim((string) ($cart->canal_emision ?? '')));
        $estado = strtolower(trim((string) ($cart->estado ?? '')));
        $estadoPago = strtolower(trim((string) ($cart->estado_pago ?? 'pendiente')));
        $estadoEmision = strtoupper(trim((string) ($cart->estado_emision ?? '')));
        $respuestaEmision = json_decode((string) ($cart->respuesta_emision ?? ''), true);
        if (!is_array($respuestaEmision)) {
            $respuestaEmision = [];
        }

        $cuf = trim((string) (
            data_get($respuestaEmision, 'cuf')
            ?: data_get($respuestaEmision, 'factura.cuf')
            ?: data_get($respuestaEmision, 'datos.cuf')
            ?: data_get($respuestaEmision, 'detalle.cuf')
            ?: ''
        ));
        $canAnnul = $canal !== 'qr' && $estadoEmision === 'FACTURADA' && $cuf !== '';

        if ($canal === 'qr') {
            if ($estadoPago === 'pagado' || $estado === 'emitido') {
                return ['key' => 'QR_PAGADO', 'label' => 'Pagado QR', 'can_annul' => false, 'cuf' => $cuf !== '' ? $cuf : null];
            }
            if ($estadoPago === 'cancelado') {
                return ['key' => 'QR_RECHAZADO', 'label' => 'QR rechazado', 'can_annul' => false, 'cuf' => $cuf !== '' ? $cuf : null];
            }
            return ['key' => 'QR_PENDIENTE', 'label' => 'QR pendiente', 'can_annul' => false, 'cuf' => $cuf !== '' ? $cuf : null];
        }

        return match ($estadoEmision) {
            'FACTURADA' => ['key' => 'FACTURADA', 'label' => 'Facturada', 'can_annul' => $canAnnul, 'cuf' => $cuf !== '' ? $cuf : null],
            'PENDIENTE' => ['key' => 'PENDIENTE', 'label' => 'Pendiente', 'can_annul' => false, 'cuf' => $cuf !== '' ? $cuf : null],
            'RECHAZADA' => ['key' => 'RECHAZADA', 'label' => 'Rechazada', 'can_annul' => false, 'cuf' => $cuf !== '' ? $cuf : null],
            'ERROR' => ['key' => 'ERROR', 'label' => 'Error', 'can_annul' => false, 'cuf' => $cuf !== '' ? $cuf : null],
            'NO_APLICA' => ['key' => 'NO_APLICA', 'label' => 'No aplica', 'can_annul' => false, 'cuf' => $cuf !== '' ? $cuf : null],
            default => ['key' => strtoupper($estado !== '' ? $estado : 'SIN_ESTADO'), 'label' => ucfirst($estado !== '' ? $estado : 'Sin estado'), 'can_annul' => false, 'cuf' => $cuf !== '' ? $cuf : null],
        };
    }

    private function facturacionCartNumeroFactura(?string $respuestaEmision): ?string
    {
        return $this->extractNumeroFacturaFromDetalle($respuestaEmision);
    }

    private function mapFacturacionCartToVentaPayload(object $cart): array
    {
        $respuestaEmision = json_decode((string) ($cart->respuesta_emision ?? ''), true);
        if (!is_array($respuestaEmision)) {
            $respuestaEmision = [];
        }

        $items = collect();
        if (Schema::hasTable('facturacion_cart_items')) {
            $items = DB::table('facturacion_cart_items')
                ->where('cart_id', (int) $cart->id)
                ->orderBy('id')
                ->get()
                ->map(function ($item) {
                    $cantidad = (float) ($item->cantidad ?? 1);
                    $base = (float) ($item->monto_base ?? 0);
                    $extras = (float) ($item->monto_extras ?? 0);
                    $totalLinea = (float) ($item->total_linea ?? round(($base + $extras) * max(1, $cantidad), 2));

                    return [
                        'codigo' => (string) (($item->codigo ?? '') !== '' ? $item->codigo : ('ITEM-' . $item->id)),
                        'descripcion' => (string) (($item->titulo ?? '') !== '' ? $item->titulo : (($item->nombre_servicio ?? '') !== '' ? $item->nombre_servicio : 'Sin detalle')),
                        'cantidad' => $cantidad,
                        'precio' => $base,
                        'monto_base' => $base,
                        'monto_extras' => $extras,
                        'total_linea' => $totalLinea,
                        'titulo' => $item->nombre_servicio,
                        'subtitulo' => $item->nombre_destinatario,
                        'origen_tipo' => (string) ($item->origen_tipo ?? ''),
                    ];
                })
                ->values();
        }

        $status = $this->facturacionCartStatusPayload($cart);
        $fecha = $cart->emitido_en ?: $cart->created_at;

        return [
            'id' => 'cart-' . (int) $cart->id,
            'cartId' => (int) $cart->id,
            'fecha' => $fecha ? date('Y-m-d H:i:s', strtotime((string) $fecha)) : null,
            'codigoOrden' => $this->normalizeFacturacionCartCodigoOrden($cart),
            'codigoSeguimiento' => (string) (($cart->codigo_seguimiento_fiscal ?? null) ?: ($cart->codigo_seguimiento ?? '')),
            'numeroFactura' => $this->facturacionCartNumeroFactura((string) ($cart->respuesta_emision ?? '')),
            'origenVentaId' => (int) $cart->id,
            'origenVentaTipo' => 'facturacion_cart',
            'canal_emision' => (string) ($cart->canal_emision ?? ''),
            'metodo_pago' => (string) ($cart->metodo_pago ?? ''),
            'estado_pago' => (string) ($cart->estado_pago ?? ''),
            'estado_emision' => (string) ($cart->estado_emision ?? ''),
            'mensaje_emision' => (string) ($cart->mensaje_emision ?? ''),
            'modalidad_facturacion' => (string) ($cart->modalidad_facturacion ?? ''),
            'qr_transaction_id' => $cart->qr_transaction_id,
            'respuesta_emision' => $respuestaEmision,
            'cliente' => [
                'razonSocial' => $cart->razon_social,
                'documentoIdentidad' => $cart->numero_documento,
                'codigoCliente' => null,
            ],
            'usuario' => [
                'id' => $cart->origen_usuario_id,
                'nombre' => $cart->origen_usuario_nombre,
                'email' => Schema::hasColumn('facturacion_carts', 'origen_usuario_email') ? $cart->origen_usuario_email : null,
                'alias' => Schema::hasColumn('facturacion_carts', 'origen_usuario_alias') ? $cart->origen_usuario_alias : null,
                'carnet' => Schema::hasColumn('facturacion_carts', 'origen_usuario_carnet') ? $cart->origen_usuario_carnet : null,
            ],
            'sucursal' => [
                'id' => $cart->origen_sucursal_id,
                'nombre' => $cart->origen_sucursal_nombre,
                'codigoSucursal' => is_numeric($cart->origen_sucursal_codigo) ? (int) $cart->origen_sucursal_codigo : $cart->origen_sucursal_codigo,
                'puntoVenta' => is_numeric($cart->origen_sucursal_id) ? (int) $cart->origen_sucursal_id : $cart->origen_sucursal_id,
                'departamento' => null,
            ],
            'detalle' => $items->all(),
            'itemsCount' => (int) ($cart->cantidad_items ?? $items->count()),
            'cantidad' => max(1, (int) ($cart->cantidad_items ?? $items->count() ?: 1)),
            'total' => (float) ($cart->total ?? 0),
            'status' => $status,
        ];
    }

    // =========================
    //  Listado
    // =========================
    public function index(Request $request)
    {
        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        $cartRows = collect();

        if (Schema::hasTable('facturacion_carts')) {
            $cartRows = $this->buildFacturacionCartReportQuery($filters)
                ->orderByDesc('emitido_en')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
        }

        $cartIds = $cartRows
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values()
            ->all();

        $ventasQuery = $this->applyVentaFilters(Venta::query(), $filters);
        if ($cartIds !== []) {
            $ventasQuery->where(function ($query) use ($cartIds) {
                $query->whereNotIn('origen_venta_tipo', ['facturacion_cart', 'facturacion_cart_remote'])
                    ->orWhereNull('origen_venta_tipo')
                    ->orWhereNotIn('origen_venta_id', $cartIds);
            });
        }

        $ventas = $ventasQuery->get();

        $list = $ventas->map(function ($venta) use ($request) {
            $response = $this->show($request, $venta);
            return json_decode((string) $response->getContent(), true);
        })->values();

        $cartPayloads = $cartRows
            ->map(fn ($cart) => $this->mapFacturacionCartToVentaPayload($cart))
            ->values();

        $merged = $list
            ->concat($cartPayloads)
            ->sortByDesc(function ($row) {
                return strtotime((string) ($row['fecha'] ?? '1970-01-01 00:00:00')) ?: 0;
            })
            ->values();

        return response()->json($merged);
    }

    public function operables(Request $request)
    {
        $scope = $request->query('scope', 'actionable');
        $filters = $this->requestIdentityFilters($request);

        $ventas = $this->applyVentaFilters(Venta::query(), $filters)
            ->with(['detalleVentas'])
            ->latest('id')
            ->get()
            ->map(fn ($venta) => $this->operationRow($venta));

        if ($scope === 'all') {
            return response()->json($ventas->values());
        }

        return response()->json(
            $ventas->filter(function ($venta) {
                return in_array($venta['status']['key'], ['PENDIENTE', 'OBSERVADO'], true);
            })->values()
        );
    }

    // =========================
    //  Mostrar
    // =========================
    public function show(Request $request, Venta $venta)
    {
        $filters = $this->requestIdentityFilters($request);
        $venta = $this->applyVentaFilters(
            Venta::query()->whereKey($venta->id),
            $filters
        )->firstOrFail();

        $venta->load('detalleVentas');
        $status = $this->protocolStatusForVenta($venta);
        $notification = $this->latestNotificationForVenta($venta);
        $detalleNotificacion = $notification ? json_decode((string) $notification->detalle, true) : [];

        $detalle = $venta->detalleVentas->map(function ($detalleVenta) {
            $cantidad = (float) $detalleVenta->cantidad;
            $base = (float) $detalleVenta->precio;
            return [
                'codigo' => $detalleVenta->codigo,
                'descripcion' => $detalleVenta->descripcion,
                'cantidad' => $cantidad,
                'precio' => $base,
                'monto_base' => $base,
                'monto_extras' => 0.0,
                'total_linea' => round($cantidad * $base, 2),
                'titulo' => $detalleVenta->descripcion,
                'subtitulo' => null,
            ];
        })->values()->all();

        if (in_array((string) ($venta->origen_venta_tipo ?? ''), ['facturacion_cart', 'facturacion_cart_remote'], true)) {
            $cartId = (int) ($venta->origen_venta_id ?? 0);
            if ($cartId > 0 && DB::table('facturacion_cart_items')->exists()) {
                $detalleCart = DB::table('facturacion_cart_items')
                    ->where('cart_id', $cartId)
                    ->orderBy('id')
                    ->get()
                    ->map(function ($item) {
                        $cantidad = (float) ($item->cantidad ?? 1);
                        $base = (float) ($item->monto_base ?? 0);
                        $extras = (float) ($item->monto_extras ?? 0);
                        $totalLinea = (float) ($item->total_linea ?? round(($base + $extras) * max(1, $cantidad), 2));
                        $titulo = trim((string) ($item->titulo ?? ''));
                        $servicio = trim((string) ($item->nombre_servicio ?? ''));
                        $destinatario = trim((string) ($item->nombre_destinatario ?? ''));
                        return [
                            'codigo' => (string) ($item->codigo ?: ('ITEM-' . $item->id)),
                            // Priorizamos "titulo" para que UI muestre "Admision EMS"
                            // y dejamos el servicio como linea secundaria.
                            'descripcion' => (string) ($titulo !== '' ? $titulo : ($servicio !== '' ? $servicio : 'Sin detalle')),
                            'cantidad' => $cantidad,
                            'precio' => $base,
                            'monto_base' => $base,
                            'monto_extras' => $extras,
                            'total_linea' => $totalLinea,
                            'titulo' => $servicio,
                            'subtitulo' => $destinatario,
                            'origen_tipo' => (string) ($item->origen_tipo ?? ''),
                        ];
                    })
                    ->values()
                    ->all();

                if (!empty($detalleCart)) {
                    $detalle = $detalleCart;
                }
            }
        }

        $data = $venta->toArray();
        $data['fecha'] = $venta->created_at->format('Y-m-d');
        $data['cliente'] = [
            'razonSocial' => $venta->razonSocial,
            'documentoIdentidad' => $venta->documentoIdentidad,
            'codigoCliente' => $venta->codigoCliente,
        ];
        $data['usuario'] = [
            'id' => $venta->origen_usuario_id,
            'nombre' => $venta->origen_usuario_nombre,
            'email' => $venta->origen_usuario_email,
            'alias' => $venta->origen_usuario_alias,
        ];
        $data['sucursal'] = [
            'id' => $venta->origen_sucursal_id,
            'nombre' => $venta->origen_sucursal_nombre,
            'codigoSucursal' => (int) $venta->codigoSucursal,
            'puntoVenta' => (int) $venta->puntoVenta,
            'departamento' => $venta->departamento,
        ];
        $data['detalle'] = $detalle;
        $data['status'] = $status;
        $data['seguimiento'] = [
            'codigoSeguimiento' => $venta->codigoSeguimiento,
            'estadoSufe' => $venta->estado_sufe,
            'tipoEmision' => $venta->tipo_emision_sufe,
            'cuf' => $venta->cuf,
            'urlPdf' => $venta->url_pdf,
            'urlXml' => $venta->url_xml,
            'observacion' => $venta->observacion_sufe,
            'fechaNotificacion' => $venta->fecha_notificacion_sufe,
            'notificacionEstado' => $notification?->estado,
            'notificacionMensaje' => $notification?->mensaje,
            'detalle' => $detalleNotificacion,
        ];

        return response()->json($data);
    }

    // =========================
    //  HTTP: emitir
    // =========================
    public function emitirFactura(array $data): array
    {
        $url = $this->ageticBaseUrl() . '/facturacion/emision/individual';

        $requestData = [
            'codigoOrden'            => $data['codigoOrden'],
            'codigoSucursal'         => $data['codigoSucursal'],
            'puntoVenta'             => $data['puntoVenta'],
            'documentoSector'        => $data['documentoSector'],
            'municipio'              => $data['municipio'],
            'departamento'           => $data['departamento'],
            'telefono'               => $data['telefono'],
            'razonSocial'            => $data['razonSocial'],
            'documentoIdentidad'     => $data['documentoIdentidad'],
            'tipoDocumentoIdentidad' => $data['tipoDocumentoIdentidad'],
            'correo'                 => $data['correo'],
            'codigoCliente'          => $data['codigoCliente'],
            'metodoPago'             => $data['metodoPago'],
            'montoTotal'             => $data['montoTotal'],
            'formatoFactura'         => $data['formatoFactura'],
            'detalle'                => $data['detalle'],
        ];

        if (($data['formatoFactura'] ?? null) === 'rollo') {
            $requestData['anchoFactura'] = 90;
        }
        if (!empty($data['complemento'])) {
            $requestData['complemento'] = $data['complemento'];
        }

        Log::info('AGETIC emitirFactura request', $requestData);

        try {
            $resp = $this->ageticClient()->post($url, $requestData);
            $resp->throw();

            $json = $resp->json();
            Log::info('AGETIC emitirFactura response', $json ?? []);

            return [
                'ok'     => true,
                'status' => $resp->status(),
                'body'   => $json,
                'error'  => null,
            ];
        } catch (RequestException $e) {
            $resp = $e->response;
            return [
                'ok'     => false,
                'status' => optional($resp)->status() ?? 0,
                'body'   => optional($resp)->json(),
                'error'  => $e->getMessage(),
            ];
        } catch (ConnectionException $e) {
            Log::error('AGETIC emitirFactura connection error', ['msg' => $e->getMessage()]);
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => null,
                'error'  => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('AGETIC emitirFactura unexpected error', ['msg' => $e->getMessage()]);
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => null,
                'error'  => $e->getMessage(),
            ];
        }
    }

    public function emitirFacturaIndividual(Request $request)
    {
        try {
            $validated = $this->sufeValidator->validateIndividualPayload($request->all());

            Log::info('AGETIC emitirFacturaIndividual direct request', $validated);

            $result = $this->emitirFactura($validated);

            if (!($result['ok'] ?? false)) {
                $body = $result['body'] ?? null;

                if (is_array($body)) {
                    try {
                        $this->sufeValidator->validateRejectedResponse($body);
                    } catch (ValidationException $validationException) {
                        Log::warning('La respuesta de rechazo de emisión individual no cumple el protocolo', [
                            'errores' => $validationException->errors(),
                            'body' => $body,
                        ]);
                    }
                }

                return response()->json($body ?? [
                    'message' => 'No se pudo emitir la factura individual.',
                    'details' => $result['error'] ?? null,
                ], ($result['status'] ?? 400) ?: 400);
            }

            $payload = $result['body'] ?? [];
            $this->sufeValidator->validateAcceptedIndividualResponse($payload);

            return response()->json($payload, $result['status'] ?? 202);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'La solicitud de emisión individual no cumple la validación del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('AGETIC emitirFacturaIndividual unexpected error', ['msg' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error inesperado al emitir factura individual.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function emitirDocumentoAjuste(Request $request, string $cufFactura)
    {
        try {
            $validated = $this->sufeValidator->validateDocumentoAjustePayload($request->all());

            $url = $this->ageticBaseUrl() . '/documentoAjuste/' . $cufFactura;
            Log::info('AGETIC emitirDocumentoAjuste request', [
                'cufFactura' => $cufFactura,
                'payload' => $validated,
            ]);

            $response = $this->ageticClient()->post($url, $validated);
            $payload = $response->json();

            Log::info('AGETIC emitirDocumentoAjuste response', $payload ?? []);

            if ($response->successful()) {
                $this->sufeValidator->validateAcceptedIndividualResponse($payload ?? []);
            } elseif (is_array($payload)) {
                try {
                    $this->sufeValidator->validateRejectedResponse($payload);
                } catch (ValidationException $validationException) {
                    Log::warning('La respuesta de rechazo de documento de ajuste no cumple el protocolo', [
                        'errores' => $validationException->errors(),
                        'body' => $payload,
                    ]);
                }
            }

            return response()->json($payload, $response->status());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'La solicitud de documento de ajuste no cumple la validación del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            return response()->json($e->response?->json() ?? [
                'message' => 'Error al emitir el documento de ajuste.',
                'details' => $e->getMessage(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'No se pudo conectar con el servicio SEFE para documento de ajuste.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('AGETIC emitirDocumentoAjuste unexpected error', ['msg' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error inesperado al emitir documento de ajuste.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function emitirFacturasMasivas(Request $request)
    {
        try {
            $validated = $this->sufeValidator->validateMassivePayload($request->all());

            $url = $this->ageticBaseUrl() . '/facturacion/emision/masiva';
            Log::info('AGETIC emitirFacturasMasivas request', $validated);

            $response = $this->ageticClient()->post($url, $validated);
            $payload = $response->json();

            Log::info('AGETIC emitirFacturasMasivas response', $payload ?? []);

            if ($response->successful()) {
                $this->sufeValidator->validateAcceptedMassiveResponse($payload ?? []);
            }

            return response()->json($payload, $response->status());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'La solicitud de emisión masiva no cumple la validación del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            return response()->json($e->response?->json() ?? [
                'message' => 'Error al emitir facturas masivas.',
                'details' => $e->getMessage(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'No se pudo conectar con el servicio SEFE para emisión masiva.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('AGETIC emitirFacturasMasivas unexpected error', ['msg' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error inesperado al emitir facturas masivas.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function emitirVentasSeleccionadas(Request $request)
    {
        $validated = $request->validate([
            'venta_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'venta_ids.*' => ['required', 'integer', 'min:1'],
            'modo' => ['nullable', 'string', 'in:auto,individual,masiva'],
        ]);

        $modo = $validated['modo'] ?? 'auto';
        $ventas = $this->ventasFromIds($validated['venta_ids']);

        foreach ($ventas as $venta) {
            if (!$this->canOperateVenta($venta)) {
                throw ValidationException::withMessages([
                    'venta_ids' => ["La venta {$venta->id} no está disponible para reenvío automático."],
                ]);
            }
        }

        if ($modo === 'individual' || ($modo === 'auto' && $ventas->count() === 1)) {
            $results = [];

            foreach ($ventas as $venta) {
                $payload = $this->individualPayloadFromVenta($venta, $this->codigoOrdenFromId($venta->id) . '-R' . now()->format('His'));
                $this->sufeValidator->validateIndividualPayload($payload);
                $result = $this->emitirFactura($payload);

                if (!($result['ok'] ?? false)) {
                    return response()->json([
                        'message' => 'No se pudo emitir una de las ventas seleccionadas.',
                        'venta_id' => $venta->id,
                        'details' => $result['body'] ?? $result['error'] ?? null,
                    ], ($result['status'] ?? 400) ?: 400);
                }

                $body = $result['body'] ?? [];
                $this->sufeValidator->validateAcceptedIndividualResponse($body);

                $venta->codigoSeguimiento = data_get($body, 'datos.codigoSeguimiento');
                $venta->save();

                $results[] = [
                    'venta_id' => $venta->id,
                    'codigoSeguimiento' => $venta->codigoSeguimiento,
                ];
            }

            return response()->json([
                'message' => 'Ventas emitidas individualmente correctamente.',
                'modo' => 'individual',
                'detalle' => $results,
            ]);
        }

        $this->assertSameOperationalContext($ventas);

        $payload = [
            'codigoSucursal' => (int) $ventas->first()->codigoSucursal,
            'puntoVenta' => (int) $ventas->first()->puntoVenta,
            'documentoSector' => (int) $ventas->first()->documentoSector,
            'facturas' => $ventas->map(function ($venta) {
                return $this->massiveItemFromVenta($venta, $this->codigoOrdenFromId($venta->id) . '-M' . now()->format('His'));
            })->values()->all(),
        ];

        $this->sufeValidator->validateMassivePayload($payload);

        $url = $this->ageticBaseUrl() . '/facturacion/emision/masiva';
        $response = $this->ageticClient()->post($url, $payload);
        $body = $response->json();

        if (!$response->successful()) {
            return response()->json([
                'message' => 'No se pudo emitir el lote masivo.',
                'details' => $body,
            ], $response->status());
        }

        $this->sufeValidator->validateAcceptedMassiveResponse($body ?? []);

        foreach (data_get($body, 'datos.detalle', []) as $accepted) {
            $position = (int) $accepted['posicion'];
            if ($ventas->has($position)) {
                $venta = $ventas[$position];
                $venta->codigoSeguimiento = $accepted['codigoSeguimiento'];
                $venta->save();
            }
        }

        return response()->json([
            'message' => 'Ventas enviadas en emisión masiva correctamente.',
            'modo' => 'masiva',
            'response' => $body,
        ]);
    }

    public function emitirContingenciaCafc(Request $request)
    {
        try {
            $validated = $this->sufeValidator->validateContingenciaCafcPayload($request->all());

            $url = $this->ageticBaseUrl() . '/facturacion/contingencia';
            Log::info('AGETIC emitirContingenciaCafc request', $validated);

            $response = $this->ageticClient()->post($url, $validated);
            $payload = $response->json();

            Log::info('AGETIC emitirContingenciaCafc response', $payload ?? []);

            if ($response->successful()) {
                $this->sufeValidator->validateAcceptedMassiveResponse($payload ?? []);
            }

            return response()->json($payload, $response->status());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'La solicitud de contingencia CAFC no cumple la validación del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            return response()->json($e->response?->json() ?? [
                'message' => 'Error al procesar la contingencia CAFC.',
                'details' => $e->getMessage(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'No se pudo conectar con el servicio SEFE para contingencia CAFC.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('AGETIC emitirContingenciaCafc unexpected error', ['msg' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error inesperado al procesar contingencia CAFC.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function emitirContingenciaCafcSeleccionadas(Request $request)
    {
        $validated = $request->validate([
            'venta_ids' => ['required', 'array', 'min:1', 'max:500'],
            'venta_ids.*' => ['required', 'integer', 'min:1'],
            'cafc' => ['required', 'string'],
            'fechaInicio' => ['required', 'date_format:Y-m-d H:i:s'],
            'fechaFin' => ['required', 'date_format:Y-m-d H:i:s'],
            'nro_facturas' => ['required', 'array'],
        ]);

        $ventas = $this->ventasFromIds($validated['venta_ids']);
        $this->assertSameOperationalContext($ventas);

        foreach ($ventas as $venta) {
            if (!$this->canOperateVenta($venta)) {
                throw ValidationException::withMessages([
                    'venta_ids' => ["La venta {$venta->id} no está disponible para contingencia CAFC."],
                ]);
            }
            if (!isset($validated['nro_facturas'][$venta->id]) || (int) $validated['nro_facturas'][$venta->id] <= 0) {
                throw ValidationException::withMessages([
                    'nro_facturas' => ["Debe proporcionar un nroFactura manual válido para la venta {$venta->id}."],
                ]);
            }
        }

        $payload = [
            'cafc' => $validated['cafc'],
            'fechaInicio' => $validated['fechaInicio'],
            'fechaFin' => $validated['fechaFin'],
            'documentoSector' => (int) $ventas->first()->documentoSector,
            'puntoVenta' => (int) $ventas->first()->puntoVenta,
            'codigoSucursal' => (int) $ventas->first()->codigoSucursal,
            'facturas' => $ventas->map(function ($venta) use ($validated) {
                $manualNumber = (int) $validated['nro_facturas'][$venta->id];
                return $this->cafcItemFromVenta($venta, $manualNumber, $this->codigoOrdenFromId($venta->id) . '-C' . now()->format('His'));
            })->values()->all(),
        ];

        $this->sufeValidator->validateContingenciaCafcPayload($payload);

        $url = $this->ageticBaseUrl() . '/facturacion/contingencia';
        $response = $this->ageticClient()->post($url, $payload);
        $body = $response->json();

        if (!$response->successful()) {
            return response()->json([
                'message' => 'No se pudo procesar la contingencia CAFC para las ventas seleccionadas.',
                'details' => $body,
            ], $response->status());
        }

        $this->sufeValidator->validateAcceptedMassiveResponse($body ?? []);

        foreach (data_get($body, 'datos.detalle', []) as $accepted) {
            $nroFactura = (int) ($accepted['nroFactura'] ?? 0);
            $venta = $ventas->first(function ($candidate) use ($validated, $nroFactura) {
                return (int) ($validated['nro_facturas'][$candidate->id] ?? 0) === $nroFactura;
            });

            if ($venta) {
                $venta->codigoSeguimiento = $accepted['codigoSeguimiento'];
                $venta->save();
            }
        }

        return response()->json([
            'message' => 'Contingencia CAFC enviada correctamente.',
            'response' => $body,
        ]);
    }

   

    // =========================
    //  PDF desde Notificacione
    // =========================
    public function getPdfUrl($codigoSeguimiento)
    {
        $notificacion = Notificacione::where('codigo_seguimiento', $codigoSeguimiento)->first();

        if ($notificacion) {
            $detalle = json_decode($notificacion->detalle, true);
            $urlPdf = $detalle['urlPdf'] ?? null;
            $cuf    = $detalle['cuf'] ?? null;

            if ($urlPdf) {
                return response()->json([
                    'pdf_url' => $urlPdf,
                    'cuf'     => $cuf,
                ], 200, [
                    'Content-Disposition' => 'inline; filename="factura.pdf"',
                ]);
            } elseif ($cuf) {
                return response()->json([
                    'cuf'     => $cuf,
                    'message' => 'PDF URL not yet available',
                ], 200);
            } else {
                return response()->json(['error' => 'No PDF URL and no CUF found'], 404);
            }
        } else {
            return response()->json(['error' => 'Notification not found'], 404);
        }
    }

    // =========================
    //  Consultar emisiÃ³n
    // =========================
    public function consultarVenta(Request $request, $codigoSeguimiento)
    {
        $filters = $this->requestIdentityFilters($request);
        $ventaVisible = $this->applyVentaFilters(
            Venta::query()->where('codigoSeguimiento', $codigoSeguimiento),
            $filters
        )->exists();

        if (!$ventaVisible) {
            return response()->json([
                'error' => 'Venta no encontrada',
            ], 404);
        }

        $tipo = request()->query('tipo');
        $url = $this->ageticBaseUrl() . "/consulta/{$codigoSeguimiento}";

        if (in_array($tipo, ['CO', 'CUF'], true)) {
            $url .= '?tipo=' . $tipo;
        }
        Log::info("CÃ³digo de Seguimiento: {$codigoSeguimiento}");
        Log::info("URL de Consulta: {$url}");

        try {
            $response = $this->ageticClient()->get($url);

            if ($response->successful()) {
                $payload = $response->json();
                $this->sufeValidator->validateConsultaFacturaResponse($payload);
                $this->syncVentaFromConsulta($codigoSeguimiento, $payload);

                Log::info('Respuesta de la API:', $payload);
                return response()->json($payload, 200);
            } else {
                Log::error("Error al consultar venta: " . $response->body());
                return response()->json([
                    'error'   => 'Error al consultar la venta',
                    'details' => $response->body(),
                ], $response->status());
            }
        } catch (\Throwable $e) {
            Log::error("ExcepciÃ³n al consultar venta: " . $e->getMessage());
            return response()->json([
                'error'     => 'Error al consultar la venta',
                'exception' => $e->getMessage(),
            ], 500);
        }
    }

    public function homologarProductos(Request $request)
    {
        $validated = $request->validate([
            'pagina' => ['nullable', 'integer', 'min:1'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $url = $this->ageticBaseUrl() . '/validacion/productos';

        try {
            $response = $this->ageticClient()->get($url, array_filter([
                'pagina' => $validated['pagina'] ?? null,
                'limite' => $validated['limite'] ?? null,
            ], fn ($value) => $value !== null));

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('AGETIC homologarProductos error', ['msg' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al consultar homologación de productos.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function listarParametricas(Request $request, string $tipoParametro)
    {
        $allowed = [
            'tipoDocumentoIdentidad',
            'tipoDocumentoSector',
            'tipoEmision',
            'tipoFactura',
            'tipoHabitacion',
            'tipoMetodoPago',
            'tipoMoneda',
            'tipoPuntoVenta',
            'unidadMedida',
        ];

        if (!in_array($tipoParametro, $allowed, true)) {
            throw ValidationException::withMessages([
                'tipoParametro' => ['El tipoParametro solicitado no está soportado por el protocolo SEFE.'],
            ]);
        }

        $validated = $request->validate([
            'pagina' => ['nullable', 'integer', 'min:1'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $url = $this->ageticBaseUrl() . '/validacion/parametricas/' . $tipoParametro;

        try {
            $response = $this->ageticClient()->get($url, array_filter([
                'pagina' => $validated['pagina'] ?? null,
                'limite' => $validated['limite'] ?? null,
            ], fn ($value) => $value !== null));

            return response()->json($response->json(), $response->status());
        } catch (\Throwable $e) {
            Log::error('AGETIC listarParametricas error', [
                'tipoParametro' => $tipoParametro,
                'msg' => $e->getMessage(),
            ]);
            return response()->json([
                'message' => 'Error al consultar las paramétricas.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function consultarPaquete($codigoSeguimientoPaquete)
    {
        $url = $this->ageticBaseUrl() . "/consulta/paquete/{$codigoSeguimientoPaquete}";
        Log::info("Código de Seguimiento Paquete: {$codigoSeguimientoPaquete}");
        Log::info("URL de Consulta Paquete: {$url}");

        try {
            $response = $this->ageticClient()->get($url);

            if ($response->successful()) {
                $payload = $response->json();
                $this->sufeValidator->validateConsultaPaqueteResponse($payload);

                Log::info('Respuesta de la API de paquete:', $payload);
                return response()->json($payload, 200);
            }

            Log::error("Error al consultar paquete: " . $response->body());
            return response()->json([
                'error'   => 'Error al consultar el paquete',
                'details' => $response->body(),
            ], $response->status());
        } catch (\Throwable $e) {
            Log::error("Excepción al consultar paquete: " . $e->getMessage());
            return response()->json([
                'error'     => 'Error al consultar el paquete',
                'exception' => $e->getMessage(),
            ], 500);
        }
    }

    public function consultarVentasSeleccionadas(Request $request)
    {
        $validated = $request->validate([
            'venta_ids' => ['required', 'array', 'min:1', 'max:100'],
            'venta_ids.*' => ['required', 'integer', 'min:1'],
        ]);

        $ventas = $this->ventasFromIds($validated['venta_ids']);
        $results = [];

        foreach ($ventas as $venta) {
            if (blank($venta->codigoSeguimiento) || Str::startsWith($venta->codigoSeguimiento, 'pendiente-')) {
                $results[] = [
                    'venta_id' => $venta->id,
                    'codigoSeguimiento' => $venta->codigoSeguimiento,
                    'status' => 'SIN_ENVIO',
                    'response' => null,
                ];
                continue;
            }

            $url = $this->ageticBaseUrl() . "/consulta/{$venta->codigoSeguimiento}";
            $response = $this->ageticClient()->get($url);
            $body = $response->json();

            if ($response->successful() && is_array($body)) {
                $this->sufeValidator->validateConsultaFacturaResponse($body);
            }

            $results[] = [
                'venta_id' => $venta->id,
                'codigoSeguimiento' => $venta->codigoSeguimiento,
                'status' => $response->successful() ? 'CONSULTADO' : 'ERROR',
                'response' => $body,
            ];
        }

        return response()->json([
            'message' => 'Consulta de ventas completada.',
            'detalle' => $results,
        ]);
    }

    public function anulacionGuardStatus(Request $request)
    {
        $user = Auth::guard('api')->user() ?? $request->user();

        return response()->json([
            'ok' => true,
            'guard' => $this->buildAnulacionGuardStatus($user),
        ]);
    }

    public function autorizarAnulacion(Request $request)
    {
        $currentUser = Auth::guard('api')->user() ?? $request->user();
        if (!$currentUser) {
            return response()->json([
                'message' => 'No se pudo identificar al usuario autenticado.',
            ], 401);
        }

        $validated = $request->validate([
            'supervisor_email' => ['required', 'email', 'max:120'],
            'supervisor_password' => ['required', 'string', 'max:255'],
            'duracion_minutos' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        $supervisor = \App\Models\Usuario::query()
            ->whereRaw('lower(email) = ?', [strtolower((string) $validated['supervisor_email'])])
            ->where('estado', 1)
            ->first();

        if (!$supervisor || !Hash::check((string) $validated['supervisor_password'], (string) $supervisor->password)) {
            return response()->json([
                'message' => 'Credenciales de supervisor inválidas.',
                'code' => 'ANULACION_SUPERVISOR_INVALIDO',
            ], 422);
        }

        if (!$this->isAnulacionSupervisor($supervisor)) {
            return response()->json([
                'message' => 'El usuario supervisor no tiene permisos para autorizar anulaciones.',
                'code' => 'ANULACION_SUPERVISOR_SIN_PERMISO',
            ], 403);
        }

        $duracion = (int) ($validated['duracion_minutos'] ?? 15);
        $expiresAt = now()->addMinutes($duracion);

        Cache::put($this->anulacionUserUnlockCacheKey((int) $currentUser->id), [
            'authorized_by_user_id' => (int) $supervisor->id,
            'authorized_by_email' => (string) $supervisor->email,
            'authorized_for_user_id' => (int) $currentUser->id,
            'expires_at' => $expiresAt->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ], $expiresAt);

        return response()->json([
            'ok' => true,
            'message' => 'Autorizacion temporal de anulacion concedida.',
            'guard' => $this->buildAnulacionGuardStatus($currentUser),
        ]);
    }

    public function revocarAutorizacionAnulacion(Request $request)
    {
        $user = Auth::guard('api')->user() ?? $request->user();
        if (!$user) {
            return response()->json([
                'message' => 'No se pudo identificar al usuario autenticado.',
            ], 401);
        }

        Cache::forget($this->anulacionUserUnlockCacheKey((int) $user->id));

        return response()->json([
            'ok' => true,
            'message' => 'Autorizacion temporal revocada.',
            'guard' => $this->buildAnulacionGuardStatus($user),
        ]);
    }

    public function toggleAnulacionGuard(Request $request)
    {
        $user = Auth::guard('api')->user() ?? $request->user();
        if (!$user) {
            return response()->json([
                'message' => 'No se pudo identificar al usuario autenticado.',
            ], 401);
        }

        if (!$this->isAnulacionSupervisor($user)) {
            return response()->json([
                'message' => 'Solo un rol superior puede habilitar o deshabilitar anulaciones globales.',
                'code' => 'ANULACION_TOGGLE_SIN_PERMISO',
            ], 403);
        }

        $validated = $request->validate([
            'habilitado' => ['required', 'boolean'],
            'duracion_minutos' => ['nullable', 'integer', 'min:1', 'max:480'],
            'motivo' => ['nullable', 'string', 'max:255'],
        ]);

        if (!(bool) $validated['habilitado']) {
            Cache::forget($this->anulacionGlobalToggleCacheKey());

            return response()->json([
                'ok' => true,
                'message' => 'Anulacion global deshabilitada.',
                'guard' => $this->buildAnulacionGuardStatus($user),
            ]);
        }

        $duracion = (int) ($validated['duracion_minutos'] ?? 30);
        $expiresAt = now()->addMinutes($duracion);

        Cache::put($this->anulacionGlobalToggleCacheKey(), [
            'enabled' => true,
            'enabled_by_user_id' => (int) $user->id,
            'enabled_by_email' => (string) ($user->email ?? ''),
            'motivo' => trim((string) ($validated['motivo'] ?? '')),
            'expires_at' => $expiresAt->toIso8601String(),
            'created_at' => now()->toIso8601String(),
        ], $expiresAt);

        return response()->json([
            'ok' => true,
            'message' => 'Anulacion global habilitada temporalmente.',
            'guard' => $this->buildAnulacionGuardStatus($user),
        ]);
    }

    // =========================
    //  Anular factura
    // =========================
    public function anularFactura(Request $request, $cuf)
    {
        $currentUser = Auth::guard('api')->user() ?? $request->user();
        $guard = $this->buildAnulacionGuardStatus($currentUser);
        if (($guard['allowed'] ?? false) !== true) {
            return response()->json([
                'message' => 'Anulacion bloqueada. Requiere autorizacion de rol superior o habilitacion global de administrador.',
                'code' => 'ANULACION_REQUIERE_AUTORIZACION',
                'guard' => $guard,
            ], 423);
        }

        $url = $this->ageticBaseUrl() . "/anulacion/{$cuf}";
        $requestData = $this->sufeValidator->validateAnulacionPayload($request->all());
        Log::info('Datos enviados para anulaciÃ³n de factura:', $requestData);

        try {
            $response = $this->ageticClient()->patch($url, $requestData);

            $payload = $response->json();
            Log::info('Respuesta de la API de anulaciÃ³n:', $payload);

            if ($response->successful()) {
                $this->sufeValidator->validateAcceptedAnulacionResponse($payload);

                return response()->json([
                    'message'  => 'Factura anulada correctamente',
                    'response' => $payload,
                ], 200);
            } else {
                if (is_array($payload)) {
                    try {
                        $this->sufeValidator->validateRejectedResponse($payload);
                    } catch (ValidationException $validationException) {
                        Log::warning('La respuesta de rechazo de anulación no cumple el protocolo', [
                            'errores' => $validationException->errors(),
                            'body' => $payload,
                        ]);
                    }
                }

                return response()->json([
                    'error'   => 'Error al anular la factura',
                    'details' => $payload,
                ], $response->status());
            }
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'La solicitud de anulación no cumple la validación del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            $response = $e->response;
            $payload = $response?->json();

            if (is_array($payload)) {
                try {
                    $this->sufeValidator->validateRejectedResponse($payload);
                } catch (ValidationException $validationException) {
                    Log::warning('La respuesta de rechazo de anulación no cumple el protocolo', [
                        'errores' => $validationException->errors(),
                        'body' => $payload,
                    ]);
                }
            }

            Log::warning('Anulación rechazada por SEFE', [
                'status' => $response?->status(),
                'body' => $payload,
            ]);

            return response()->json([
                'message' => data_get($payload, 'mensaje', 'No se pudo anular la factura.'),
                'details' => $payload,
            ], $response?->status() ?: 400);
        } catch (\Throwable $e) {
            Log::error('ExcepciÃ³n al anular factura: ' . $e->getMessage());
            return response()->json([
                'error'     => 'Error al anular la factura',
                'exception' => $e->getMessage(),
            ], 500);
        }
    }

    private function buildAnulacionGuardStatus($user): array
    {
        $isSupervisor = $this->isAnulacionSupervisor($user);
        $global = $this->globalAnulacionToggleData();
        $globalEnabled = is_array($global) && ($global['enabled'] ?? false) === true;

        $unlock = null;
        $unlockEnabled = false;
        if ($user && isset($user->id)) {
            $unlock = Cache::get($this->anulacionUserUnlockCacheKey((int) $user->id));
            $unlockEnabled = is_array($unlock);
        }

        return [
            'requires_authorization' => true,
            'allowed' => $isSupervisor || $globalEnabled || $unlockEnabled,
            'allowed_by' => $isSupervisor
                ? 'ROL_SUPERIOR'
                : ($globalEnabled ? 'GLOBAL_SWITCH' : ($unlockEnabled ? 'SUPERVISOR_UNLOCK' : 'NONE')),
            'is_supervisor' => $isSupervisor,
            'global_enabled' => $globalEnabled,
            'global' => $globalEnabled ? $global : null,
            'user_unlock_enabled' => $unlockEnabled,
            'user_unlock' => $unlockEnabled ? $unlock : null,
        ];
    }

    private function globalAnulacionToggleData(): ?array
    {
        $data = Cache::get($this->anulacionGlobalToggleCacheKey());
        return is_array($data) ? $data : null;
    }

    private function anulacionGlobalToggleCacheKey(): string
    {
        return 'ventas:anulacion:global-toggle';
    }

    private function anulacionUserUnlockCacheKey(int $userId): string
    {
        return "ventas:anulacion:user-unlock:{$userId}";
    }

    private function isAnulacionSupervisor($user): bool
    {
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'hasRole') && ($user->hasRole('admin') || $user->hasRole('administrador') || $user->hasRole('supervisor'))) {
            return true;
        }

        return method_exists($user, 'hasPermission') && (
            $user->hasPermission('rbac.manage')
            || $user->hasPermission('usuarios.manage')
            || $user->hasPermission('ventas.manage')
        );
    }

}
