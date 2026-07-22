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
use Illuminate\Support\Collection;
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
    private static ?bool $hasCartOrigenUsuarioEmailColumn = null;
    private static ?bool $hasCartOrigenUsuarioAliasColumn = null;
    private static ?bool $hasCartOrigenUsuarioCarnetColumn = null;
    private static ?bool $hasCartOrigenSucursalCodigoColumn = null;
    private static ?bool $hasCartOrigenSucursalIdColumn = null;
    private static ?bool $hasFacturacionCartItemsTable = null;

    public function __construct(
        private readonly SufeSectorUnoValidator $sufeValidator
    ) {
    }

    private function reportLogContext(Request $request, array $extra = []): array
    {
        return array_merge([
            'route' => $request->path(),
            'method' => $request->method(),
            'query' => $request->query(),
            'user_id' => optional(Auth::guard('api')->user() ?? $request->user())->id,
        ], $extra);
    }

    private function reportStartedAt(): float
    {
        return microtime(true);
    }

    private function reportElapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
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
            // Reintenta solo si es problema de conexiÃƒÆ’Ã‚Â³n (timeouts, etc.)
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

    private function latestNotificationsMapFromSeguimientos(array $seguimientos): array
    {
        $seguimientos = array_values(array_unique(array_filter(array_map(
            fn ($value) => trim((string) $value),
            $seguimientos
        ))));

        if ($seguimientos === []) {
            return [];
        }

        $map = [];
        $notificaciones = Notificacione::query()
            ->whereIn('codigo_seguimiento', $seguimientos)
            ->orderByDesc('id')
            ->get();

        foreach ($notificaciones as $notificacion) {
            $codigoSeguimiento = trim((string) $notificacion->codigo_seguimiento);
            if ($codigoSeguimiento === '' || array_key_exists($codigoSeguimiento, $map)) {
                continue;
            }

            $map[$codigoSeguimiento] = $notificacion;
        }

        return $map;
    }

    private function protocolStatusFromVentaNotification(Venta $venta, ?Notificacione $notification): array
    {
        $detalle = $notification ? json_decode((string) $notification->detalle, true) : [];
        $estadoSufe = strtoupper((string) ($venta->estado_sufe ?? ''));

        if (blank($venta->codigoSeguimiento) || Str::startsWith((string) $venta->codigoSeguimiento, 'pendiente-')) {
            return [
                'key' => 'PENDIENTE',
                'label' => 'Pendiente de envÃƒÂ­o',
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
            if ($estadoSufe === 'ANULADA') {
                return [
                    'key' => 'ANULADA',
                    'label' => 'Anulada',
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

            if ($estadoSufe === 'ANULACION_SOLICITADA') {
                return [
                    'key' => 'ANULACION_SOLICITADA',
                    'label' => 'Anulacion solicitada',
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

    private function protocolStatusForVenta(Venta $venta): array
    {
        return $this->protocolStatusFromVentaNotification($venta, $this->latestNotificationForVenta($venta));
    }

    private function buildAnulacionAuditData($currentUser, array $guard, array $requestData): array
    {
        $allowedBy = (string) ($guard['allowed_by'] ?? 'NONE');
        $authorizedUserId = null;
        $authorizedEmail = null;

        if ($allowedBy === 'SUPERVISOR_UNLOCK') {
            $authorizedUserId = data_get($guard, 'user_unlock.authorized_by_user_id');
            $authorizedEmail = data_get($guard, 'user_unlock.authorized_by_email');
        } elseif ($allowedBy === 'GLOBAL_SWITCH') {
            $authorizedUserId = data_get($guard, 'global.enabled_by_user_id');
            $authorizedEmail = data_get($guard, 'global.enabled_by_email');
        } elseif ($allowedBy === 'ROL_SUPERIOR' && $currentUser) {
            $authorizedUserId = $currentUser->id ?? null;
            $authorizedEmail = $currentUser->email ?? null;
        }

        return [
            'anulada_at' => now(),
            'anulada_por_user_id' => $currentUser->id ?? null,
            'anulada_por_nombre' => trim((string) data_get($currentUser, 'nombre', data_get($currentUser, 'name', data_get($currentUser, 'email', '')))) ?: null,
            'anulada_por_email' => trim((string) ($currentUser->email ?? '')) ?: null,
            'anulacion_motivo' => trim((string) ($requestData['motivo'] ?? '')) ?: null,
            'anulacion_tipo' => isset($requestData['tipoAnulacion']) ? ('TIPO ' . (string) $requestData['tipoAnulacion']) : null,
            'anulacion_autorizada_por_user_id' => $authorizedUserId,
            'anulacion_autorizada_por_email' => $authorizedEmail,
        ];
    }

    private function storeAnulacionRespaldo(Request $request, string $cuf): array
    {
        if (!$request->hasFile('respaldo')) {
            return [];
        }

        $validated = $request->validate([
            'respaldo' => ['file', 'max:5120', 'mimes:jpg,jpeg,png,pdf,webp,doc,docx'],
        ]);

        $file = $validated['respaldo'];
        $folder = public_path('uploads/anulaciones/' . now()->format('Y/m'));

        if (!is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin'));
        $safeCuf = preg_replace('/[^A-Za-z0-9\-]/', '', $cuf) ?: 'sin-cuf';
        $filename = 'anulacion-' . $safeCuf . '-' . now()->format('YmdHis') . '-' . Str::random(8) . '.' . $extension;

        $file->move($folder, $filename);

        return [
            'anulacion_respaldo_path' => 'uploads/anulaciones/' . now()->format('Y/m') . '/' . $filename,
            'anulacion_respaldo_nombre' => $file->getClientOriginalName(),
            'anulacion_respaldo_mime' => $file->getClientMimeType(),
            'anulacion_respaldo_size' => (int) $file->getSize(),
        ];
    }

    private function anulacionRespaldoUrl(?string $path): ?string
    {
        $cleanPath = trim((string) $path);
        if ($cleanPath === '') {
            return null;
        }

        return url(str_replace('\\', '/', ltrim($cleanPath, '/')));
    }

    private function anulacionPayloadForVenta(Venta $venta): array
    {
        return [
            'anuladaAt' => $venta->anulada_at,
            'anuladaPorUserId' => $venta->anulada_por_user_id,
            'anuladaPorNombre' => $venta->anulada_por_nombre,
            'anuladaPorEmail' => $venta->anulada_por_email,
            'motivo' => $venta->anulacion_motivo,
            'tipo' => $venta->anulacion_tipo,
            'autorizadaPorUserId' => $venta->anulacion_autorizada_por_user_id,
            'autorizadaPorEmail' => $venta->anulacion_autorizada_por_email,
            'respaldoPath' => $venta->anulacion_respaldo_path,
            'respaldoNombre' => $venta->anulacion_respaldo_nombre,
            'respaldoMime' => $venta->anulacion_respaldo_mime,
            'respaldoSize' => $venta->anulacion_respaldo_size,
            'respaldoUrl' => $this->anulacionRespaldoUrl($venta->anulacion_respaldo_path),
            'numeroFactura' => $venta->numero_factura,
            'codigoOrden' => $venta->codigoOrden,
            'cuf' => $venta->cuf,
        ];
    }

    private function persistAnulacionAuditForVenta(?Venta $venta, array $auditData, ?string $message = null): void
    {
        if (!$venta) {
            return;
        }

        $estadoSufe = strtoupper(trim((string) ($venta->estado_sufe ?? '')));
        $ventaUpdates = array_filter([
            'motivo' => $auditData['anulacion_motivo'] ?? null,
            'updated_at' => now(),
        ] + $auditData, fn ($value, $key) => $value !== null || $key === 'updated_at', ARRAY_FILTER_USE_BOTH);

        if ($message !== null && trim($message) !== '') {
            $ventaUpdates['observacion_sufe'] = trim($message);
        }

        DB::table('ventas')
            ->where('id', (int) $venta->id)
            ->update($ventaUpdates);

        if (
            !Schema::hasTable('facturacion_carts')
            || !in_array((string) ($venta->origen_venta_tipo ?? ''), ['facturacion_cart', 'facturacion_cart_remote'], true)
            || (int) ($venta->origen_venta_id ?? 0) <= 0
        ) {
            return;
        }

        $cartUpdates = array_filter([
            'updated_at' => now(),
        ] + $auditData, fn ($value, $key) => $value !== null || $key === 'updated_at', ARRAY_FILTER_USE_BOTH);

        if ($message !== null && trim($message) !== '') {
            $cartUpdates['mensaje_emision'] = trim($message);
        }

        if ($estadoSufe === 'ANULADA') {
            $cartUpdates['estado'] = 'descartado';
            $cartUpdates['estado_emision'] = 'ANULADA';
        } elseif (in_array($estadoSufe, ['ANULACION_SOLICITADA', 'ANULACION_OBSERVADA'], true)) {
            $cartUpdates['estado_emision'] = $estadoSufe;
        }

        DB::table('facturacion_carts')
            ->where('id', (int) $venta->origen_venta_id)
            ->update($cartUpdates);
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
                'venta_ids' => ['Una o mÃƒÂ¡s ventas no existen o no estÃƒÂ¡n disponibles.'],
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
        $canViewGlobalReports = $this->isAnulacionSupervisor($usuario);

        if ($canViewGlobalReports) {
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

    private function applySettledVentaFilters($query)
    {
        return $query->where(function ($scope) {
            $scope->whereRaw("upper(coalesce(estado_sufe, '')) in ('PROCESADA', 'REGISTRADA_OFICIAL')")
                ->orWhereNotNull('cuf');
        });
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

    private function hasCartOrigenUsuarioEmailColumn(): bool
    {
        if (self::$hasCartOrigenUsuarioEmailColumn === null) {
            self::$hasCartOrigenUsuarioEmailColumn = Schema::hasColumn('facturacion_carts', 'origen_usuario_email');
        }

        return self::$hasCartOrigenUsuarioEmailColumn;
    }

    private function hasCartOrigenUsuarioAliasColumn(): bool
    {
        if (self::$hasCartOrigenUsuarioAliasColumn === null) {
            self::$hasCartOrigenUsuarioAliasColumn = Schema::hasColumn('facturacion_carts', 'origen_usuario_alias');
        }

        return self::$hasCartOrigenUsuarioAliasColumn;
    }

    private function hasCartOrigenUsuarioCarnetColumn(): bool
    {
        if (self::$hasCartOrigenUsuarioCarnetColumn === null) {
            self::$hasCartOrigenUsuarioCarnetColumn = Schema::hasColumn('facturacion_carts', 'origen_usuario_carnet');
        }

        return self::$hasCartOrigenUsuarioCarnetColumn;
    }

    private function hasCartOrigenSucursalCodigoColumn(): bool
    {
        if (self::$hasCartOrigenSucursalCodigoColumn === null) {
            self::$hasCartOrigenSucursalCodigoColumn = Schema::hasColumn('facturacion_carts', 'origen_sucursal_codigo');
        }

        return self::$hasCartOrigenSucursalCodigoColumn;
    }

    private function hasCartOrigenSucursalIdColumn(): bool
    {
        if (self::$hasCartOrigenSucursalIdColumn === null) {
            self::$hasCartOrigenSucursalIdColumn = Schema::hasColumn('facturacion_carts', 'origen_sucursal_id');
        }

        return self::$hasCartOrigenSucursalIdColumn;
    }

    private function hasFacturacionCartItemsTable(): bool
    {
        if (self::$hasFacturacionCartItemsTable === null) {
            self::$hasFacturacionCartItemsTable = Schema::hasTable('facturacion_cart_items');
        }

        return self::$hasFacturacionCartItemsTable;
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
                return in_array((string) ($row->origen_venta_tipo ?? ''), ['facturacion_cart', 'facturacion_cart_remote'], true)
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

    private function normalizeNotificationAssetUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        return preg_replace('#(?<!:)//+#', '/', $url);
    }

    private function bridgeCartMetaMapFromVentasRows($ventasRows): array
    {
        $cartIds = collect($ventasRows)
            ->filter(function ($row) {
                return in_array((string) ($row->origen_venta_tipo ?? ''), ['facturacion_cart', 'facturacion_cart_remote'], true)
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

        return DB::table('facturacion_carts')
            ->whereIn('id', $cartIds)
            ->get([
                'id',
                'canal_emision',
                'metodo_pago',
                'estado_pago',
                'estado_emision',
                'qr_transaction_id',
            ])
            ->keyBy(fn ($row) => (int) $row->id)
            ->toArray();
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
            $bridgeCartMetaMap = $this->bridgeCartMetaMapFromVentasRows($detalleRows);
            $itemsCountMaps = $this->itemsCountMapsFromRows($detalleRows);
            $detalleMaps = $this->detalleMapsFromRows($detalleRows);

            $detalle = $detalleRows->map(function (Venta $venta) use ($numeroFacturaMap, $numeroFacturaBridgeMap, $bridgeCartMetaMap, $itemsCountMaps, $detalleMaps) {
                    $codigoSeguimiento = trim((string) $venta->codigoSeguimiento);
                    $origenVentaId = (int) ($venta->origen_venta_id ?? 0);
                    $bridgeCart = $bridgeCartMetaMap[$origenVentaId] ?? null;
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
                        'canal_emision' => $bridgeCart->canal_emision ?? null,
                        'metodo_pago' => $bridgeCart->metodo_pago ?? null,
                        'estado_pago' => $bridgeCart->estado_pago ?? null,
                        'estado_emision' => $bridgeCart->estado_emision ?? null,
                        'qr_transaction_id' => $bridgeCart->qr_transaction_id ?? null,
                        'itemsCount' => $itemsCount,
                        'detalle' => $items->values()->all(),
                        'estadoSufe' => $venta->estado_sufe,
                        'cuf' => $venta->cuf,
                    ];
                })
                ->reject(fn ($item) => ($item['type'] ?? '') === 'qr_anulado' && !empty($item['reviewedAt']))
                ->values();
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
        $cartRows = Schema::hasTable('facturacion_carts')
            ? $this->buildFacturacionCartReportQuery($filters)
                ->orderByDesc('emitido_en')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limite)
                ->get()
            : collect();

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

        $ventasRows = $ventasQuery
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
                'origen_sucursal_id',
                'origen_sucursal_nombre',
                'codigoSucursal',
                'puntoVenta',
                'razonSocial',
                'documentoIdentidad',
                'total',
                'estado_sufe',
            ])));

        $numeroFacturaMap = $this->numeroFacturaMapFromSeguimientos($ventasRows->pluck('codigoSeguimiento')->all());
        $numeroFacturaBridgeMap = $this->numeroFacturaMapFromBridgeCartRows($ventasRows);
        $bridgeCartMetaMap = $this->bridgeCartMetaMapFromVentasRows($ventasRows);
        $detalleMaps = $this->detalleMapsFromRows($ventasRows);

        $rows = $this->buildPdfRowsFromVentas($ventasRows, $detalleMaps['detalle'] ?? [], $numeroFacturaMap, $numeroFacturaBridgeMap, $bridgeCartMetaMap)
            ->concat($this->buildPdfRowsFromFacturacionCarts($cartRows))
            ->sortByDesc(fn ($row) => (int) data_get($row, 'fecha_sort', 0))
            ->values();

        $totals = [
            'parcial' => round((float) $rows->sum('importe_parcial'), 2),
            'general' => round((float) $rows->sum('importe_general'), 2),
        ];

        $authUser = Auth::guard('api')->user() ?? $request->user();
        $firstRow = $ventasRows->first() ?: $cartRows->first();
        $usuario = (object) [
            'name' => trim((string) data_get($authUser, 'nombre', data_get($authUser, 'name', 'Sin responsable'))),
            'sucursal' => (object) [
                'nombre' => trim((string) data_get($firstRow, 'origen_sucursal_nombre', data_get($authUser, 'sucursal.nombre', ''))),
                'descripcion' => trim((string) data_get($authUser, 'sucursal.descripcion', '')),
                'municipio' => trim((string) data_get($authUser, 'sucursal.municipio', '')),
                'puntoVenta' => trim((string) data_get($firstRow, 'puntoVenta', data_get($authUser, 'sucursal.puntoVenta', ''))),
            ],
        ];

        $filtersView = [
            'estado' => 'emitido',
            'estado_emision' => (string) ($filters['estado_sufe'] ?? 'all'),
            'from' => $filters['fechaInicio'] ?? null,
            'to' => $filters['fechaFin'] ?? null,
            'q' => trim((string) ($filters['q'] ?? '')),
        ];

        $scope = empty($filters['origen_usuario_id'])
            && empty($filters['origen_usuario_email'])
            && empty($filters['origen_usuario_alias'])
            && empty($filters['origen_usuario_carnet'])
            ? 'branch'
            : 'own';

        $html = view('facturacion.mis-ventas-kardex-pdf', [
            'user' => $usuario,
            'filters' => $filtersView,
            'carts' => $cartRows,
            'rows' => $rows->values(),
            'totals' => $totals,
            'generatedAt' => now(),
            'scope' => $scope,
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

    private function buildPdfRowsFromVentas(Collection $ventasRows, array $detalleVentasMap, array $numeroFacturaMap, array $numeroFacturaBridgeMap, array $bridgeCartMetaMap = []): Collection
    {
        return $ventasRows->flatMap(function (Venta $venta) use ($detalleVentasMap, $numeroFacturaMap, $numeroFacturaBridgeMap, $bridgeCartMetaMap) {
            $ventaId = (int) $venta->id;
            $origenVentaId = (int) ($venta->origen_venta_id ?? 0);
            $bridgeCart = $bridgeCartMetaMap[$origenVentaId] ?? null;
            $codigoSeguimiento = trim((string) ($venta->codigoSeguimiento ?? ''));
            $numeroFactura = trim((string) (
                $venta->numero_factura
                ?: ($numeroFacturaMap[$codigoSeguimiento] ?? ($numeroFacturaBridgeMap[$origenVentaId] ?? ''))
            ));
            $detalleVentas = collect($detalleVentasMap[$ventaId] ?? []);
            $items = $detalleVentas->map(function ($item) {
                $cantidad = max(1, (int) ($item->cantidad ?? 1));
                $precio = round((float) ($item->precio ?? 0), 2);
                $descripcion = trim((string) ($item->descripcion ?? 'SIN SERVICIO'));

                return (object) [
                    'codigo' => trim((string) ($item->codigo ?? '')),
                    'descripcion' => $descripcion,
                    'titulo' => $descripcion,
                    'nombre_servicio' => $descripcion,
                    'cantidad' => $cantidad,
                    'precio' => $precio,
                    'monto_base' => $precio,
                    'monto_extras' => 0.0,
                    'total_linea' => round($cantidad * $precio, 2),
                    'resumen_origen' => [],
                ];
            })->values();

            $tipoEnvio = $items
                ->map(fn ($item) => trim((string) data_get($item, 'nombre_servicio', data_get($item, 'titulo', ''))))
                ->filter()
                ->unique()
                ->implode(' / ');
            $detalleItems = $items
                ->map(fn ($item) => trim((string) data_get($item, 'titulo', data_get($item, 'nombre_servicio', ''))))
                ->filter()
                ->unique()
                ->implode(' / ');
            $codigoOrden = trim((string) ($venta->codigoOrden ?? '')) ?: '-';
            $codigosPaquete = $this->extractPdfPackageCodesFromItems($items);
            $cantidadTotal = max(1, (int) $items->sum(fn ($item) => (int) data_get($item, 'cantidad', 1)));
            $packageItemsCount = $codigosPaquete->count();
            $serviceItemsCount = max(0, $cantidadTotal - $packageItemsCount);
            $detalleResumen = collect([
                $packageItemsCount > 0 ? $packageItemsCount . ' paquete' . ($packageItemsCount === 1 ? '' : 's') : null,
                $serviceItemsCount > 0 ? $serviceItemsCount . ' servicio' . ($serviceItemsCount === 1 ? '' : 's') : null,
            ])->filter()->implode(' + ');
            $canalEmision = strtolower(trim((string) ($bridgeCart->canal_emision ?? 'factura_electronica')));
            $metodoPago = strtolower(trim((string) ($bridgeCart->metodo_pago ?? 'efectivo')));
            $estadoPago = strtolower(trim((string) ($bridgeCart->estado_pago ?? 'pagado')));
            $estadoEmision = strtoupper(trim((string) ($bridgeCart->estado_emision ?? 'FACTURADA')));
            $sectionKey = $this->resolvePdfSectionKey([
                'codigo_orden' => $venta->codigoOrden,
                'canal_emision' => $canalEmision,
                'metodo_pago' => $metodoPago,
                'estado_pago' => $estadoPago,
                'estado_emision' => $estadoEmision,
                'qr_transaction_id' => $bridgeCart->qr_transaction_id ?? null,
                'estado_sufe' => $venta->estado_sufe,
                'es_oficial' => strtoupper(trim((string) ($venta->estado_sufe ?? ''))) === 'REGISTRADA_OFICIAL',
                'razon_social' => $venta->razonSocial,
            ]);

            return [[
                'origen_usuario_id' => trim((string) ($venta->origen_usuario_id ?? '')),
                'origen_usuario_nombre' => trim((string) ($venta->origen_usuario_nombre ?? '')),
                'origen_usuario_email' => trim((string) ($venta->origen_usuario_email ?? '')),
                'fecha' => optional($venta->created_at)->format('d/m/Y') ?: '-',
                'fecha_hora' => optional($venta->created_at)->format('d/m/Y H:i') ?: '-',
                'fecha_sort' => optional($venta->created_at)->timestamp ?: 0,
                'cliente' => trim((string) ($venta->razonSocial ?? '')) ?: 'Sin cliente',
                'tipo_envio' => $tipoEnvio !== '' ? $tipoEnvio : 'SIN DETALLE REAL',
                'detalle_items' => $detalleItems !== '' ? $detalleItems : 'Sin detalle real',
                'detalle_resumen' => $detalleResumen,
                'codigo_item' => $codigoOrden,
                'codigo_paquetes' => $codigosPaquete,
                'codigo_referencia' => $codigosPaquete->isNotEmpty()
                    ? $codigoOrden . "\nPaquetes: " . $codigosPaquete->implode(', ')
                    : $codigoOrden,
                'peso' => Schema::hasColumn('ventas', 'peso_total')
                    ? round((float) ($venta->peso_total ?? 0), 3)
                    : 0.0,
                'cantidad' => $cantidadTotal,
                'canal_emision' => $canalEmision,
                'metodo_pago' => $metodoPago,
                'estado_pago' => $estadoPago,
                'estado_emision' => $estadoEmision,
                'section_key' => $sectionKey,
                'emision_label' => match ($sectionKey) {
                    'qr_facturado' => 'QR pagado + facturado',
                    'qr_pagado_pendiente_factura' => 'QR pagado',
                    'qr_cancelado' => 'QR cancelado',
                    'qr_pendiente' => 'QR pendiente',
                    'oficial' => 'Envio oficial',
                    default => 'Factura electronica',
                },
                'cobro_label' => match ($sectionKey) {
                    'qr_facturado', 'qr_pagado_pendiente_factura' => 'QR / NO CAJA',
                    'qr_cancelado' => 'QR CANCELADO',
                    'qr_pendiente' => 'QR PENDIENTE',
                    'oficial' => 'CAJA / OFICIAL',
                    default => 'CAJA',
                },
                'cobro_detalle' => match ($sectionKey) {
                    'qr_facturado' => 'Cobro QR transformado a factura electronica. No suma a caja.',
                    'qr_pagado_pendiente_factura' => 'Cobro QR confirmado. No suma a caja mientras no sea efectivo.',
                    'qr_cancelado' => 'Intento QR no concretado.',
                    'qr_pendiente' => 'Pendiente de pago QR.',
                    default => 'Cobro registrado en caja.',
                },
                'contabiliza_en_caja' => !in_array($sectionKey, ['qr_facturado', 'qr_pagado_pendiente_factura', 'qr_pendiente', 'qr_cancelado'], true)
                    && $estadoEmision !== 'ANULADA',
                'cobrada' => true,
                'numero_factura' => $numeroFactura !== '' ? $numeroFactura : '-',
                'importe_parcial' => round((float) ($venta->total ?? 0), 2),
                'importe_general' => round((float) ($venta->total ?? 0), 2),
            ]];
        })->values();
    }

    private function buildPdfRowsFromFacturacionCarts(Collection $cartRows): Collection
    {
        if ($cartRows->isEmpty()) {
            return collect();
        }

        $cartIds = $cartRows->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
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

        return $cartRows->map(function ($cart) use ($cartItemsMap) {
            $cart = is_array($cart) ? (object) $cart : $cart;
            $items = collect($cartItemsMap[(int) $cart->id] ?? [])
                ->map(function ($item) {
                    $item = is_array($item) ? (object) $item : $item;
                    $resumen = json_decode((string) ($item->resumen_origen ?? ''), true);
                    $item->resumen_origen = is_array($resumen) ? $resumen : [];
                    return $item;
                })
                ->values();

            $numeroFactura = trim((string) $this->facturacionCartNumeroFactura((string) ($cart->respuesta_emision ?? '')));
            $fechaBase = $cart->emitido_en ?: $cart->created_at;
            $canalEmision = strtolower(trim((string) ($cart->canal_emision ?? 'factura_electronica')));
            $metodoPago = strtolower(trim((string) ($cart->metodo_pago ?? ($canalEmision === 'qr' ? 'qr' : 'efectivo'))));
            $sectionKey = $this->resolvePdfSectionKey([
                'codigo_orden' => $cart->codigo_orden,
                'canal_emision' => $canalEmision,
                'metodo_pago' => $metodoPago,
                'estado_pago' => $cart->estado_pago,
                'estado_emision' => $cart->estado_emision,
                'qr_transaction_id' => $cart->qr_transaction_id,
            ]);
            $contabilizaEnCaja = !in_array($sectionKey, ['qr_facturado', 'qr_pagado_pendiente_factura', 'qr_pendiente', 'qr_cancelado'], true)
                && strtoupper(trim((string) ($cart->estado_emision ?? 'NO_APLICA'))) !== 'ANULADA';
            $emisionLabel = match ($sectionKey) {
                'qr_facturado' => 'QR pagado + facturado',
                'qr_pagado_pendiente_factura' => 'QR pagado',
                'qr_cancelado' => 'QR cancelado',
                'qr_pendiente' => 'QR pendiente',
                default => $this->labelCanalEmision($canalEmision),
            };
            $cobroLabel = match ($sectionKey) {
                'qr_facturado' => 'QR / NO CAJA',
                'qr_pagado_pendiente_factura' => 'QR / NO CAJA',
                'qr_cancelado' => 'QR CANCELADO',
                'qr_pendiente' => 'QR PENDIENTE',
                default => 'CAJA',
            };
            $cobroDetalle = match ($sectionKey) {
                'qr_facturado' => 'Cobro QR transformado a factura electronica. No suma a caja.',
                'qr_pagado_pendiente_factura' => 'Cobro QR confirmado. No suma a caja mientras no sea efectivo.',
                'qr_cancelado' => 'Intento QR no concretado.',
                'qr_pendiente' => 'Pendiente de pago QR.',
                default => 'Cobro registrado en caja.',
            };
            $tipoEnvio = $items
                ->map(fn ($item) => trim((string) data_get($item, 'nombre_servicio', data_get($item, 'titulo', ''))))
                ->filter()
                ->unique()
                ->implode(' / ');
            $detalleItems = $items
                ->map(fn ($item) => trim((string) data_get($item, 'titulo', data_get($item, 'nombre_servicio', ''))))
                ->filter()
                ->unique()
                ->implode(' / ');
            $codigoOrden = trim((string) ($cart->codigo_orden ?? ($cart->codigo_seguimiento ?? ''))) ?: '-';
            $codigosPaquete = $this->extractPdfPackageCodesFromItems($items);
            $pesoTotal = (float) $items->sum(fn ($item) => (float) data_get($item, 'resumen_origen.peso', 0));
            $cantidadTotal = max(1, (int) ($cart->cantidad_items ?? $items->sum(fn ($item) => (int) data_get($item, 'cantidad', 1)) ?: $items->count()));
            $packageItemsCount = $codigosPaquete->count();
            $serviceItemsCount = max(0, $cantidadTotal - $packageItemsCount);
            $detalleResumen = collect([
                $packageItemsCount > 0 ? $packageItemsCount . ' paquete' . ($packageItemsCount === 1 ? '' : 's') : null,
                $serviceItemsCount > 0 ? $serviceItemsCount . ' servicio' . ($serviceItemsCount === 1 ? '' : 's') : null,
            ])->filter()->implode(' + ');
            $clienteLabel = trim((string) ($cart->razon_social ?? ''));
            if ($clienteLabel === '') {
                $clienteLabel = 'Sin cliente';
            }

            return [
                'origen_usuario_id' => trim((string) ($cart->origen_usuario_id ?? '')),
                'origen_usuario_nombre' => trim((string) ($cart->origen_usuario_nombre ?? '')),
                'origen_usuario_email' => trim((string) ($cart->origen_usuario_email ?? '')),
                'fecha' => $fechaBase ? date('d/m/Y', strtotime((string) $fechaBase)) : '-',
                'fecha_hora' => $fechaBase ? date('d/m/Y H:i', strtotime((string) $fechaBase)) : '-',
                'fecha_sort' => $fechaBase ? strtotime((string) $fechaBase) : 0,
                'cliente' => $clienteLabel,
                'tipo_envio' => $tipoEnvio !== '' ? $tipoEnvio : 'SIN DETALLE REAL',
                'detalle_items' => $detalleItems !== '' ? $detalleItems : 'Sin detalle real',
                'detalle_resumen' => $detalleResumen,
                'codigo_item' => $codigoOrden,
                'codigo_paquetes' => $codigosPaquete,
                'codigo_referencia' => $codigosPaquete->isNotEmpty()
                    ? $codigoOrden . "\nPaquetes: " . $codigosPaquete->implode(', ')
                    : $codigoOrden,
                'peso' => $pesoTotal,
                'cantidad' => $cantidadTotal,
                'canal_emision' => $canalEmision,
                'metodo_pago' => $metodoPago,
                'estado_pago' => strtolower(trim((string) ($cart->estado_pago ?? 'pendiente'))),
                'estado_emision' => strtoupper(trim((string) ($cart->estado_emision ?? 'NO_APLICA'))),
                'section_key' => $sectionKey,
                'emision_label' => $emisionLabel,
                'cobro_label' => $cobroLabel,
                'cobro_detalle' => $cobroDetalle,
                'contabiliza_en_caja' => $contabilizaEnCaja,
                'cobrada' => in_array($sectionKey, ['factura_electronica', 'qr_facturado', 'qr_pagado_pendiente_factura', 'oficial'], true),
                'numero_factura' => $numeroFactura !== '' ? $numeroFactura : '-',
                'importe_parcial' => round((float) ($cart->total ?? 0), 2),
                'importe_general' => round((float) ($cart->total ?? 0), 2),
            ];
        })->values();
    }

    private function extractPdfPackageCodesFromItems(Collection $items): Collection
    {
        return $items
            ->flatMap(function ($item) {
                return [
                    trim((string) data_get($item, 'codigo', '')),
                    trim((string) data_get($item, 'codigo_item', '')),
                    trim((string) data_get($item, 'codigo_paquete', '')),
                    trim((string) data_get($item, 'resumen_origen.codigo', '')),
                    trim((string) data_get($item, 'resumen_origen.codigo_item', '')),
                    trim((string) data_get($item, 'resumen_origen.codigo_paquete', '')),
                ];
            })
            ->filter()
            ->unique()
            ->values();
    }

    private function isQrPaymentRow(object|array $row): bool
    {
        $codigoOrden = strtoupper(trim((string) data_get($row, 'codigo_orden', data_get($row, 'codigoOrden', ''))));

        return strtolower(trim((string) data_get($row, 'metodo_pago', ''))) === 'qr'
            || trim((string) data_get($row, 'qr_transaction_id', '')) !== ''
            || strtolower(trim((string) data_get($row, 'canal_emision', ''))) === 'qr'
            || $this->hasQrOrderCodePrefix($codigoOrden);
    }

    private function hasQrOrderCodePrefix(string $codigoOrden): bool
    {
        $codigoOrden = strtoupper(trim($codigoOrden));

        return str_starts_with($codigoOrden, 'VQ-')
            || str_starts_with($codigoOrden, 'VQC-');
    }

    private function labelCanalEmision(string $canalEmision): string
    {
        return match (strtolower(trim($canalEmision))) {
            'qr' => 'QR',
            'oficial' => 'Envio oficial',
            default => 'Factura electronica',
        };
    }

    private function resolvePdfSectionKey(object|array $row): string
    {
        if ($this->isQrPaymentRow($row)) {
            $estadoPago = strtolower(trim((string) data_get($row, 'estado_pago', 'pendiente')));
            $estadoEmision = strtoupper(trim((string) data_get($row, 'estado_emision', 'NO_APLICA')));

            if (in_array($estadoPago, ['cancelado', 'fallido'], true)) {
                return 'qr_cancelado';
            }

            if ($estadoPago !== 'pagado') {
                return 'qr_pendiente';
            }

            if ($estadoEmision === 'FACTURADA') {
                return 'qr_facturado';
            }

            return 'qr_pagado_pendiente_factura';
        }

        return $this->resolveNonQrPdfSectionKey($row);
    }

    private function resolveNonQrPdfSectionKey(object|array $row): string
    {
        $codigoOrden = strtoupper(trim((string) data_get($row, 'codigo_orden', data_get($row, 'codigoOrden', ''))));
        $razonSocial = strtoupper(trim((string) data_get($row, 'razon_social', data_get($row, 'razonSocial', data_get($row, 'cliente.razonSocial', '')))));
        $estadoSufe = strtoupper(trim((string) data_get($row, 'estado_sufe', data_get($row, 'estadoSufe', data_get($row, 'respuesta_emision.estadoSufe', '')))));
        $canalEmision = strtolower(trim((string) data_get($row, 'canal_emision', 'factura_electronica')));
        $esOficial = (bool) data_get($row, 'es_oficial', false)
            || str_starts_with($codigoOrden, 'OFI-')
            || $razonSocial === 'ENVIO OFICIAL'
            || $estadoSufe === 'REGISTRADA_OFICIAL'
            || $canalEmision === 'oficial';

        return $esOficial ? 'oficial' : 'factura_electronica';
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
        $bridgeCartMetaMap = $this->bridgeCartMetaMapFromVentasRows($ventasRows);
        $itemsCountMaps = $this->itemsCountMapsFromRows($ventasRows);

        $ventas = $ventasRows->map(function (Venta $venta) use ($numeroFacturaMap, $numeroFacturaBridgeMap, $bridgeCartMetaMap, $itemsCountMaps) {
                $codigoSeguimiento = trim((string) $venta->codigoSeguimiento);
                $origenVentaId = (int) ($venta->origen_venta_id ?? 0);
                $bridgeCart = $bridgeCartMetaMap[$origenVentaId] ?? null;
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
                    'canal_emision' => $bridgeCart->canal_emision ?? null,
                    'metodo_pago' => $bridgeCart->metodo_pago ?? null,
                    'estado_pago' => $bridgeCart->estado_pago ?? null,
                    'estado_emision' => $bridgeCart->estado_emision ?? null,
                    'qr_transaction_id' => $bridgeCart->qr_transaction_id ?? null,
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
        $startedAt = $this->reportStartedAt();
        Log::info('ventas.reporteSucursales.start', $this->reportLogContext($request));

        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        Log::info('ventas.reporteSucursales.filters', $this->reportLogContext($request, [
            'filters' => $filters,
            'codigoSucursal_is_zero' => isset($filters['codigoSucursal']) && (int) $filters['codigoSucursal'] === 0,
            'puntoVenta_is_zero' => isset($filters['puntoVenta']) && (int) $filters['puntoVenta'] === 0,
        ]));
        $baseQuery = $this->buildVentaReportQuery($filters);
        $settledBaseQuery = $this->applySettledVentaFilters(clone $baseQuery);
        $limite = (int) ($filters['limite'] ?? 200);
        $reviewedDiscardedLinkedVentaExpr = Schema::hasTable('facturacion_carts')
            ? "exists (
                select 1
                from facturacion_carts as fc_review
                where cast(fc_review.id as varchar) = cast(ventas.origen_venta_id as varchar)
                    and lower(coalesce(fc_review.estado, '')) = 'descartado'
                    and upper(coalesce(fc_review.estado_emision, 'NO_APLICA')) = 'RECHAZADA'
                    and fc_review.incidencia_revisada_at is not null
            )"
            : 'false';
        $sucursalCodigoExpr = $this->hasOrigenSucursalCodigoColumn()
            ? "coalesce(nullif(origen_sucursal_codigo, ''), cast(coalesce(\"codigoSucursal\", 0) as varchar))"
            : "cast(coalesce(\"codigoSucursal\", 0) as varchar)";
        $puntoVentaExpr = "coalesce(nullif(origen_sucursal_id, ''), cast(coalesce(\"puntoVenta\", 0) as varchar))";
        $sucursalIdExpr = "concat({$sucursalCodigoExpr}, '-', {$puntoVentaExpr})";
        $sucursalNombreExpr = "coalesce(nullif(origen_sucursal_nombre, ''), concat('Sucursal ', \"codigoSucursal\", ' / PV ', \"puntoVenta\"), 'Sin sucursal')";

        $resumen = (clone $settledBaseQuery)
            ->selectRaw("
                sum(case
                    when upper(coalesce(estado_sufe, '')) in ('PROCESADA', 'REGISTRADA_OFICIAL')
                    then 1 else 0
                end) as cantidad_ventas,
                coalesce(sum(case
                    when upper(coalesce(estado_sufe, '')) in ('PROCESADA', 'REGISTRADA_OFICIAL')
                    then total else 0
                end), 0) as total_vendido,
                coalesce(sum(case
                    when upper(coalesce(estado_sufe, '')) = 'PROCESADA'
                        and (
                            upper(coalesce(\"codigoOrden\", '')) like 'VQ-%'
                            or upper(coalesce(\"codigoOrden\", '')) like 'VQC-%'
                        )
                    then total else 0
                end), 0) as total_qr_facturado,
                coalesce(sum(case
                    when upper(coalesce(estado_sufe, '')) = 'PROCESADA'
                        and not (
                            upper(coalesce(\"codigoOrden\", '')) like 'VQ-%'
                            or upper(coalesce(\"codigoOrden\", '')) like 'VQC-%'
                        )
                    then total else 0
                end), 0) as total_efectivo_facturado,
                count(distinct coalesce(origen_usuario_id, origen_usuario_email, origen_usuario_alias, origen_usuario_nombre, 'SIN-USUARIO')) as cajeros_unicos,
                sum(case when upper(coalesce(estado_sufe, '')) = 'PROCESADA' then 1 else 0 end) as facturadas,
                sum(case
                    when upper(coalesce(estado_sufe, '')) = 'PROCESADA'
                        and (
                            upper(coalesce(\"codigoOrden\", '')) like 'VQ-%'
                            or upper(coalesce(\"codigoOrden\", '')) like 'VQC-%'
                        )
                    then 1 else 0
                end) as qr_facturadas,
                sum(case
                    when upper(coalesce(estado_sufe, '')) = 'PROCESADA'
                        and not (
                            upper(coalesce(\"codigoOrden\", '')) like 'VQ-%'
                            or upper(coalesce(\"codigoOrden\", '')) like 'VQC-%'
                        )
                    then 1 else 0
                end) as electronicas_facturadas,
                sum(case when upper(coalesce(estado_sufe, '')) = 'REGISTRADA_OFICIAL' then 1 else 0 end) as oficiales,
                sum(case when coalesce(cuf, '') <> '' and upper(coalesce(estado_sufe, '')) not in ('PROCESADA', 'REGISTRADA_OFICIAL') and not ({$reviewedDiscardedLinkedVentaExpr}) then 1 else 0 end) as con_cuf_otro_estado,
                sum(case when upper(coalesce(estado_sufe, '')) in ('ANULADA', 'ANULADO') and not ({$reviewedDiscardedLinkedVentaExpr}) then 1 else 0 end) as facturas_anuladas,
                coalesce(sum(case
                    when upper(coalesce(estado_sufe, '')) in ('ANULADA', 'ANULADO') and not ({$reviewedDiscardedLinkedVentaExpr})
                    then total else 0
                end), 0) as total_facturas_anuladas,
                sum(case when upper(coalesce(estado_sufe, '')) = 'OBSERVADA' and not ({$reviewedDiscardedLinkedVentaExpr}) then 1 else 0 end) as observadas,
                sum(case when upper(coalesce(estado_sufe, '')) in ('RECEPCIONADA', 'CONTINGENCIA_CREADA') then 1 else 0 end) as pendientes
            ")
            ->first();

        $porSucursal = (clone $settledBaseQuery)
            ->selectRaw("
                {$sucursalIdExpr} as sucursal_id,
                {$sucursalNombreExpr} as sucursal_nombre,
                {$sucursalCodigoExpr} as codigo_sucursal,
                {$puntoVentaExpr} as punto_venta,
                coalesce(max(nullif(departamento, '')), '') as departamento,
                sum(case
                    when upper(coalesce(estado_sufe, '')) in ('PROCESADA', 'REGISTRADA_OFICIAL')
                    then 1 else 0
                end) as cantidad_ventas,
                coalesce(sum(case
                    when upper(coalesce(estado_sufe, '')) in ('PROCESADA', 'REGISTRADA_OFICIAL')
                    then total else 0
                end), 0) as total_vendido,
                coalesce(sum(case
                    when upper(coalesce(estado_sufe, '')) = 'PROCESADA'
                        and (
                            upper(coalesce(\"codigoOrden\", '')) like 'VQ-%'
                            or upper(coalesce(\"codigoOrden\", '')) like 'VQC-%'
                        )
                    then total else 0
                end), 0) as total_qr_facturado,
                coalesce(sum(case
                    when upper(coalesce(estado_sufe, '')) = 'PROCESADA'
                        and not (
                            upper(coalesce(\"codigoOrden\", '')) like 'VQ-%'
                            or upper(coalesce(\"codigoOrden\", '')) like 'VQC-%'
                        )
                    then total else 0
                end), 0) as total_efectivo_facturado,
                count(distinct coalesce(origen_usuario_id, origen_usuario_email, origen_usuario_alias, origen_usuario_nombre, 'SIN-USUARIO')) as cajeros_unicos,
                sum(case when upper(coalesce(estado_sufe, '')) = 'PROCESADA' then 1 else 0 end) as facturadas,
                sum(case
                    when upper(coalesce(estado_sufe, '')) = 'PROCESADA'
                        and (
                            upper(coalesce(\"codigoOrden\", '')) like 'VQ-%'
                            or upper(coalesce(\"codigoOrden\", '')) like 'VQC-%'
                        )
                    then 1 else 0
                end) as qr_facturadas,
                sum(case
                    when upper(coalesce(estado_sufe, '')) = 'PROCESADA'
                        and not (
                            upper(coalesce(\"codigoOrden\", '')) like 'VQ-%'
                            or upper(coalesce(\"codigoOrden\", '')) like 'VQC-%'
                        )
                    then 1 else 0
                end) as electronicas_facturadas,
                sum(case when upper(coalesce(estado_sufe, '')) = 'REGISTRADA_OFICIAL' then 1 else 0 end) as oficiales,
                sum(case when coalesce(cuf, '') <> '' and upper(coalesce(estado_sufe, '')) not in ('PROCESADA', 'REGISTRADA_OFICIAL') and not ({$reviewedDiscardedLinkedVentaExpr}) then 1 else 0 end) as con_cuf_otro_estado,
                sum(case when upper(coalesce(estado_sufe, '')) in ('ANULADA', 'ANULADO') and not ({$reviewedDiscardedLinkedVentaExpr}) then 1 else 0 end) as facturas_anuladas,
                coalesce(sum(case
                    when upper(coalesce(estado_sufe, '')) in ('ANULADA', 'ANULADO') and not ({$reviewedDiscardedLinkedVentaExpr})
                    then total else 0
                end), 0) as total_facturas_anuladas,
                sum(case when upper(coalesce(estado_sufe, '')) = 'OBSERVADA' and not ({$reviewedDiscardedLinkedVentaExpr}) then 1 else 0 end) as observadas,
                sum(case when upper(coalesce(estado_sufe, '')) in ('RECEPCIONADA', 'CONTINGENCIA_CREADA') then 1 else 0 end) as pendientes,
                min(created_at) as primera_venta,
                max(created_at) as ultima_venta
            ")
            ->groupByRaw("
                {$sucursalIdExpr},
                {$sucursalNombreExpr},
                {$sucursalCodigoExpr},
                {$puntoVentaExpr}
            ")
            ->orderByDesc('total_vendido')
            ->orderBy('sucursal_nombre')
            ->get();
        Log::info('ventas.reporteSucursales.porSucursal.ready', $this->reportLogContext($request, [
            'elapsed_ms' => $this->reportElapsedMs($startedAt),
            'sucursales_count' => $porSucursal->count(),
            'first_sucursal' => $porSucursal->first(),
        ]));

        $qrSucursalMetrics = collect();
        if (Schema::hasTable('facturacion_carts')) {
            $cartSucursalCodigoExpr = $this->hasCartOrigenSucursalCodigoColumn()
                ? "coalesce(nullif(origen_sucursal_codigo, ''), '0')"
                : "'0'";
            $cartPuntoVentaExpr = $this->hasCartOrigenSucursalIdColumn()
                ? "coalesce(nullif(origen_sucursal_id, ''), '0')"
                : "'0'";
            $cartSucursalIdExpr = "concat({$cartSucursalCodigoExpr}, '-', {$cartPuntoVentaExpr})";
            $cartIsQrExpr = "(
                lower(coalesce(metodo_pago, '')) = 'qr'
                or lower(coalesce(canal_emision, '')) = 'qr'
                or upper(coalesce(codigo_orden, '')) like 'VQ-%'
                or upper(coalesce(codigo_orden, '')) like 'VQC-%'
            )";

            $qrSucursalMetrics = $this->buildFacturacionCartReportQuery($filters)
                ->selectRaw("
                    {$cartSucursalIdExpr} as sucursal_id,
                    sum(case
                        when {$cartIsQrExpr}
                            and lower(coalesce(estado_pago, 'pendiente')) = 'pagado'
                            and upper(coalesce(estado_emision, 'NO_APLICA')) = 'FACTURADA'
                        then 1 else 0
                    end) as qr_facturado,
                    coalesce(sum(case
                        when {$cartIsQrExpr}
                            and lower(coalesce(estado_pago, 'pendiente')) = 'pagado'
                            and upper(coalesce(estado_emision, 'NO_APLICA')) = 'FACTURADA'
                        then total else 0
                    end), 0) as total_qr_facturado_cart,
                    sum(case
                        when {$cartIsQrExpr}
                            and lower(coalesce(estado_pago, 'pendiente')) = 'pagado'
                            and upper(coalesce(estado_emision, 'NO_APLICA')) <> 'FACTURADA'
                        then 1 else 0
                    end) as qr_pagado_pendiente_factura,
                    coalesce(sum(case
                        when {$cartIsQrExpr}
                            and lower(coalesce(estado_pago, 'pendiente')) = 'pagado'
                            and upper(coalesce(estado_emision, 'NO_APLICA')) <> 'FACTURADA'
                        then total else 0
                    end), 0) as total_qr_pagado_pendiente_factura,
                    sum(case
                        when {$cartIsQrExpr}
                            and lower(coalesce(estado_pago, 'pendiente')) in ('cancelado', 'fallido')
                            and incidencia_revisada_at is null
                        then 1 else 0
                    end) as qr_cancelado,
                    coalesce(sum(case
                        when {$cartIsQrExpr}
                            and lower(coalesce(estado_pago, 'pendiente')) in ('cancelado', 'fallido')
                            and incidencia_revisada_at is null
                        then total else 0
                    end), 0) as total_qr_cancelado,
                    sum(case
                        when {$cartIsQrExpr}
                            and lower(coalesce(estado_pago, 'pendiente')) not in ('pagado', 'cancelado', 'fallido')
                        then 1 else 0
                    end) as qr_pendiente,
                    coalesce(sum(case
                        when {$cartIsQrExpr}
                            and lower(coalesce(estado_pago, 'pendiente')) not in ('pagado', 'cancelado', 'fallido')
                        then total else 0
                    end), 0) as total_qr_pendiente
                    ,
                    sum(case
                        when lower(coalesce(estado, '')) = 'descartado'
                            and upper(coalesce(estado_emision, 'NO_APLICA')) = 'RECHAZADA'
                            and incidencia_revisada_at is null
                        then 1 else 0
                    end) as cart_rechazado_descartado,
                    coalesce(sum(case
                        when lower(coalesce(estado, '')) = 'descartado'
                            and upper(coalesce(estado_emision, 'NO_APLICA')) = 'RECHAZADA'
                            and incidencia_revisada_at is null
                        then total else 0
                    end), 0) as total_cart_rechazado_descartado
                ")
                ->groupByRaw($cartSucursalIdExpr)
                ->get()
                ->keyBy('sucursal_id');
        }

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
        Log::info('ventas.reporteSucursales.detalle.ready', $this->reportLogContext($request, [
            'elapsed_ms' => $this->reportElapsedMs($startedAt),
            'detalle_count' => $detalleRows->count(),
        ]));

        return response()->json([
            'filters' => $filters,
            'resumen' => [
                'cantidadVentas' => (int) ($resumen->cantidad_ventas ?? 0),
                'totalVendido' => (float) ($resumen->total_vendido ?? 0),
                'totalQrFacturado' => (float) ($resumen->total_qr_facturado ?? 0),
                'totalEfectivoFacturado' => (float) ($resumen->total_efectivo_facturado ?? 0),
                'cajerosUnicos' => (int) ($resumen->cajeros_unicos ?? 0),
                'facturadas' => (int) ($resumen->facturadas ?? 0),
                'qrFacturadas' => (int) ($resumen->qr_facturadas ?? 0),
                'electronicasFacturadas' => (int) ($resumen->electronicas_facturadas ?? 0),
                'oficiales' => (int) ($resumen->oficiales ?? 0),
                'facturasAnuladas' => (int) ($resumen->facturas_anuladas ?? 0),
                'totalFacturasAnuladas' => (float) ($resumen->total_facturas_anuladas ?? 0),
                'conCufOtroEstado' => (int) ($resumen->con_cuf_otro_estado ?? 0) + (int) $qrSucursalMetrics->sum(fn ($row) => (int) ($row->cart_rechazado_descartado ?? 0)),
                'observadas' => (int) ($resumen->observadas ?? 0),
                'pendientes' => (int) ($resumen->pendientes ?? 0),
                'qrPagadoPendienteFactura' => (int) $qrSucursalMetrics->sum(fn ($row) => (int) ($row->qr_pagado_pendiente_factura ?? 0)),
                'qrCancelado' => (int) $qrSucursalMetrics->sum(fn ($row) => (int) ($row->qr_cancelado ?? 0)),
                'qrPendiente' => (int) $qrSucursalMetrics->sum(fn ($row) => (int) ($row->qr_pendiente ?? 0)),
                'cartRechazadoDescartado' => (int) $qrSucursalMetrics->sum(fn ($row) => (int) ($row->cart_rechazado_descartado ?? 0)),
                'totalQrPagadoPendienteFactura' => (float) $qrSucursalMetrics->sum(fn ($row) => (float) ($row->total_qr_pagado_pendiente_factura ?? 0)),
                'totalQrCancelado' => (float) $qrSucursalMetrics->sum(fn ($row) => (float) ($row->total_qr_cancelado ?? 0)),
                'totalQrPendiente' => (float) $qrSucursalMetrics->sum(fn ($row) => (float) ($row->total_qr_pendiente ?? 0)),
                'totalCartRechazadoDescartado' => (float) $qrSucursalMetrics->sum(fn ($row) => (float) ($row->total_cart_rechazado_descartado ?? 0)),
            ],
            'sucursales' => $porSucursal->map(function ($row) use ($qrSucursalMetrics) {
                $qrMetrics = $qrSucursalMetrics->get($row->sucursal_id);

                return [
                    'id' => $row->sucursal_id,
                    'nombre' => $row->sucursal_nombre,
                    'codigoSucursal' => trim((string) $row->codigo_sucursal),
                    'puntoVenta' => trim((string) $row->punto_venta),
                    'kardexDisponible' => trim((string) $row->codigo_sucursal) !== '',
                    'departamento' => trim((string) ($row->departamento ?? '')) !== ''
                        ? $row->departamento
                        : $row->sucursal_nombre,
                    'sucursalNombre' => $row->sucursal_nombre,
                    'cantidadVentas' => (int) $row->cantidad_ventas,
                    'totalVendido' => (float) $row->total_vendido,
                    'totalQrFacturado' => (float) $row->total_qr_facturado,
                    'totalEfectivoFacturado' => (float) $row->total_efectivo_facturado,
                    'cajerosUnicos' => (int) $row->cajeros_unicos,
                    'facturadas' => (int) $row->facturadas,
                    'qrFacturadas' => (int) $row->qr_facturadas,
                    'electronicasFacturadas' => (int) $row->electronicas_facturadas,
                    'oficiales' => (int) $row->oficiales,
                    'facturasAnuladas' => (int) $row->facturas_anuladas,
                    'totalFacturasAnuladas' => (float) $row->total_facturas_anuladas,
                    'conCufOtroEstado' => (int) $row->con_cuf_otro_estado + (int) ($qrMetrics->cart_rechazado_descartado ?? 0),
                    'observadas' => (int) $row->observadas,
                    'pendientes' => (int) $row->pendientes,
                    'qrPagadoPendienteFactura' => (int) ($qrMetrics->qr_pagado_pendiente_factura ?? 0),
                    'qrCancelado' => (int) ($qrMetrics->qr_cancelado ?? 0),
                    'qrPendiente' => (int) ($qrMetrics->qr_pendiente ?? 0),
                    'cartRechazadoDescartado' => (int) ($qrMetrics->cart_rechazado_descartado ?? 0),
                    'totalQrPagadoPendienteFactura' => (float) ($qrMetrics->total_qr_pagado_pendiente_factura ?? 0),
                    'totalQrCancelado' => (float) ($qrMetrics->total_qr_cancelado ?? 0),
                    'totalQrPendiente' => (float) ($qrMetrics->total_qr_pendiente ?? 0),
                    'totalCartRechazadoDescartado' => (float) ($qrMetrics->total_cart_rechazado_descartado ?? 0),
                    'primeraVenta' => $row->primera_venta,
                    'ultimaVenta' => $row->ultima_venta,
                ];
            })->values(),
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
        $startedAt = $this->reportStartedAt();
        Log::info('ventas.reporteSucursalesUsuarios.start', $this->reportLogContext($request));

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
        Log::info('ventas.reporteSucursalesUsuarios.filters', $this->reportLogContext($request, [
            'filters' => $filters,
            'codigoSucursal_is_zero' => $filters['codigoSucursal'] === 0,
            'puntoVenta_is_zero' => $filters['puntoVenta'] === 0,
        ]));

        $usuarios = $this->applySettledVentaFilters(clone $this->buildVentaReportQuery($filters))
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
        Log::info('ventas.reporteSucursalesUsuarios.ready', $this->reportLogContext($request, [
            'elapsed_ms' => $this->reportElapsedMs($startedAt),
            'usuarios_count' => $usuarios->count(),
            'first_usuario' => $usuarios->first(),
        ]));

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

    public function reporteSucursalesIncidencias(Request $request)
    {
        $startedAt = $this->reportStartedAt();
        Log::info('ventas.reporteSucursalesIncidencias.start', $this->reportLogContext($request));

        $request->validate([
            'codigoSucursal' => ['required', 'integer', 'min:0'],
            'puntoVenta' => ['required', 'integer', 'min:0'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $filters = $this->requestIdentityFilters($request);
        $filters['codigoSucursal'] = (int) $request->query('codigoSucursal');
        $filters['puntoVenta'] = (int) $request->query('puntoVenta');
        $filters['q'] = trim((string) $request->query('q', '')) ?: null;

        Log::info('ventas.reporteSucursalesIncidencias.filters', $this->reportLogContext($request, [
            'filters' => $filters,
            'codigoSucursal_is_zero' => $filters['codigoSucursal'] === 0,
            'puntoVenta_is_zero' => $filters['puntoVenta'] === 0,
        ]));

        $ventaIncidencias = $this->buildVentaReportQuery($filters)
            ->when(Schema::hasTable('facturacion_carts'), function ($query) {
                $query->whereNotExists(function ($reviewedDiscarded) {
                    $reviewedDiscarded->select(DB::raw('1'))
                        ->from('facturacion_carts as fc_review')
                        ->whereRaw("cast(fc_review.id as varchar) = cast(ventas.origen_venta_id as varchar)")
                        ->whereRaw("lower(coalesce(fc_review.estado, '')) = 'descartado'")
                        ->whereRaw("upper(coalesce(fc_review.estado_emision, 'NO_APLICA')) = 'RECHAZADA'")
                        ->whereNotNull('fc_review.incidencia_revisada_at');
                });
            })
            ->where(function ($scope) {
                $scope->whereRaw("upper(coalesce(estado_sufe, '')) = 'OBSERVADA'")
                    ->orWhereRaw("upper(coalesce(estado_sufe, '')) in ('RECEPCIONADA', 'CONTINGENCIA_CREADA')")
                    ->orWhere(function ($inner) {
                        $inner->whereRaw("coalesce(cuf, '') <> ''")
                            ->whereRaw("upper(coalesce(estado_sufe, '')) not in ('PROCESADA', 'REGISTRADA_OFICIAL', 'OBSERVADA', 'RECEPCIONADA', 'CONTINGENCIA_CREADA')");
                    });
            })
            ->latest('created_at')
            ->get([
                'id',
                'codigoOrden',
                'codigoSeguimiento',
                'razonSocial',
                'total',
                'created_at',
                'estado_sufe',
                'origen_usuario_nombre',
                'origen_usuario_alias',
                'origen_usuario_email',
            ])
            ->map(function ($venta) {
                $estado = strtoupper(trim((string) ($venta->estado_sufe ?? '')));
                $type = match ($estado) {
                    'OBSERVADA' => 'observada',
                    'RECEPCIONADA', 'CONTINGENCIA_CREADA' => 'pendiente',
                    'ANULADA', 'ANULADO' => 'factura_anulada',
                    default => 'otro_estado',
                };

                return [
                    'key' => 'venta-' . $venta->id,
                    'type' => $type,
                    'title' => match ($type) {
                        'observada' => 'Factura observada',
                        'pendiente' => 'Factura pendiente',
                        'factura_anulada' => 'Factura anulada',
                        default => 'Factura con otro estado',
                    },
                    'status' => $estado !== '' ? $estado : 'SIN_ESTADO',
                    'code' => trim((string) ($venta->codigoOrden ?? '')) ?: ('#' . $venta->id),
                    'tracking' => trim((string) ($venta->codigoSeguimiento ?? '')),
                    'customer' => trim((string) ($venta->razonSocial ?? '')) ?: 'Sin cliente',
                    'amount' => (float) ($venta->total ?? 0),
                    'createdAt' => $venta->created_at,
                    'user' => trim((string) ($venta->origen_usuario_nombre ?? $venta->origen_usuario_alias ?? $venta->origen_usuario_email ?? '')) ?: 'Sin usuario',
                    'message' => match ($type) {
                        'observada' => 'La factura fue observada y requiere revisiÃƒÂ³n.',
                        'pendiente' => 'La factura sigue pendiente de procesamiento o confirmaciÃƒÂ³n.',
                        'factura_anulada' => 'La factura fue anulada y no debe contarse como venta vÃƒÂ¡lida.',
                        default => 'La factura tiene un estado fiscal distinto al esperado y requiere revisiÃƒÂ³n.',
                    },
                ];
            });

        $qrIncidencias = collect();
        $cartRejectedIncidencias = collect();
        if (Schema::hasTable('facturacion_carts')) {
            $qrIncidencias = $this->buildFacturacionCartReportQuery($filters)
                ->where(function ($scope) {
                    $scope->whereRaw("lower(coalesce(metodo_pago, '')) = 'qr'")
                        ->orWhereRaw("lower(coalesce(canal_emision, '')) = 'qr'")
                        ->orWhereRaw("upper(coalesce(codigo_orden, '')) like 'VQ-%'")
                        ->orWhereRaw("upper(coalesce(codigo_orden, '')) like 'VQC-%'");
                })
                ->where(function ($scope) {
                    $scope->where(function ($paidNoFactura) {
                        $paidNoFactura->whereRaw("lower(coalesce(estado_pago, 'pendiente')) = 'pagado'")
                            ->whereRaw("upper(coalesce(estado_emision, 'NO_APLICA')) <> 'FACTURADA'");
                    })->orWhere(function ($cancelled) {
                        $cancelled->whereRaw("lower(coalesce(estado_pago, 'pendiente')) in ('cancelado', 'fallido')");
                    })->orWhere(function ($pending) {
                        $pending->whereRaw("lower(coalesce(estado_pago, 'pendiente')) not in ('pagado', 'cancelado', 'fallido')");
                    });
                })
                ->latest('created_at')
                ->get([
                    'id',
                    'codigo_orden',
                    'codigo_seguimiento',
                    'razon_social',
                    'total',
                    'created_at',
                    'estado_pago',
                    'estado_emision',
                    'incidencia_revisada_at',
                    'incidencia_revisada_por',
                    'incidencia_revision_nota',
                    'origen_usuario_nombre',
                    'origen_usuario_alias',
                    'origen_usuario_email',
                ])
                ->map(function ($cart) {
                    $estadoPago = strtolower(trim((string) ($cart->estado_pago ?? 'pendiente')));
                    $estadoEmision = strtoupper(trim((string) ($cart->estado_emision ?? 'NO_APLICA')));
                    $type = 'qr_pendiente';

                    if ($estadoPago === 'pagado' && $estadoEmision !== 'FACTURADA') {
                        $type = 'qr_pagado_sin_factura';
                    } elseif (in_array($estadoPago, ['cancelado', 'fallido'], true)) {
                        $type = 'qr_anulado';
                    }

                    return [
                        'key' => 'cart-' . $cart->id,
                        'type' => $type,
                        'title' => match ($type) {
                            'qr_pagado_sin_factura' => 'QR pagado sin factura',
                            'qr_anulado' => 'QR anulado',
                            default => 'QR pendiente',
                        },
                        'status' => strtoupper($estadoPago !== '' ? $estadoPago : 'PENDIENTE'),
                        'code' => trim((string) ($cart->codigo_orden ?? '')) ?: ('QR-' . $cart->id),
                        'tracking' => trim((string) ($cart->codigo_seguimiento ?? '')),
                        'customer' => trim((string) ($cart->razon_social ?? '')) ?: 'Sin cliente',
                        'amount' => (float) ($cart->total ?? 0),
                        'createdAt' => $cart->created_at,
                        'user' => trim((string) ($cart->origen_usuario_nombre ?? $cart->origen_usuario_alias ?? $cart->origen_usuario_email ?? '')) ?: 'Sin usuario',
                        'reviewedAt' => $cart->incidencia_revisada_at,
                        'reviewedBy' => $cart->incidencia_revisada_por,
                        'reviewNote' => $cart->incidencia_revision_nota,
                        'message' => match ($type) {
                            'qr_pagado_sin_factura' => 'El cobro QR fue confirmado, pero la factura aÃƒÂºn no fue emitida.',
                            'qr_anulado' => 'El intento de cobro QR fue cancelado o fallÃƒÂ³.',
                            default => 'El cobro QR sigue pendiente de pago.',
                        },
                    ];
                });

            $cartRejectedIncidencias = $this->buildFacturacionCartReportQuery($filters)
                ->whereRaw("lower(coalesce(estado, '')) = 'descartado'")
                ->whereRaw("upper(coalesce(estado_emision, 'NO_APLICA')) = 'RECHAZADA'")
                ->whereNull('incidencia_revisada_at')
                ->latest('created_at')
                ->get([
                    'id',
                    'codigo_orden',
                    'codigo_seguimiento',
                    'razon_social',
                    'total',
                    'created_at',
                    'estado',
                    'estado_emision',
                    'incidencia_revisada_at',
                    'incidencia_revisada_por',
                    'incidencia_revision_nota',
                    'origen_usuario_nombre',
                    'origen_usuario_alias',
                    'origen_usuario_email',
                ])
                ->map(function ($cart) {
                    return [
                        'key' => 'cart-rejected-' . $cart->id,
                        'type' => 'factura_descartada',
                        'title' => 'Factura rechazada descartada',
                        'status' => 'DESCARTADA',
                        'code' => trim((string) ($cart->codigo_orden ?? '')) ?: ('FC-' . $cart->id),
                        'tracking' => trim((string) ($cart->codigo_seguimiento ?? '')),
                        'customer' => trim((string) ($cart->razon_social ?? '')) ?: 'Sin cliente',
                        'amount' => (float) ($cart->total ?? 0),
                        'createdAt' => $cart->created_at,
                        'user' => trim((string) ($cart->origen_usuario_nombre ?? $cart->origen_usuario_alias ?? $cart->origen_usuario_email ?? '')) ?: 'Sin usuario',
                        'reviewedAt' => $cart->incidencia_revisada_at,
                        'reviewedBy' => $cart->incidencia_revisada_por,
                        'reviewNote' => $cart->incidencia_revision_nota,
                        'message' => 'La factura fue rechazada y la venta se descarto localmente; requiere revision manual.',
                    ];
                });
        }

        $incidencias = $ventaIncidencias
            ->concat($qrIncidencias)
            ->concat($cartRejectedIncidencias)
            ->sortByDesc(fn ($item) => (string) ($item['createdAt'] ?? ''))
            ->values();

        Log::info('ventas.reporteSucursalesIncidencias.ready', $this->reportLogContext($request, [
            'elapsed_ms' => $this->reportElapsedMs($startedAt),
            'incidencias_count' => $incidencias->count(),
            'first_incidencia' => $incidencias->first(),
        ]));

        return response()->json([
            'filters' => $filters,
            'sucursal' => [
                'codigoSucursal' => (int) $filters['codigoSucursal'],
                'puntoVenta' => (int) $filters['puntoVenta'],
            ],
            'resumen' => [
                'incidencias' => $incidencias->count(),
            ],
            'incidencias' => $incidencias->values(),
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
            $fieldExists = $field === 'origen_sucursal_id'
                ? $this->hasCartOrigenSucursalIdColumn()
                : Schema::hasColumn('facturacion_carts', $field);
            if (!empty($filters[$field]) && $fieldExists) {
                $query->where($field, (string) $filters[$field]);
            }
        }

        if (!empty($filters['origen_usuario_email']) && $this->hasCartOrigenUsuarioEmailColumn()) {
            $query->whereRaw('lower(coalesce(origen_usuario_email, ?)) = ?', ['', strtolower((string) $filters['origen_usuario_email'])]);
        }

        if (!empty($filters['origen_usuario_alias']) && $this->hasCartOrigenUsuarioAliasColumn()) {
            $query->whereRaw('lower(coalesce(origen_usuario_alias, ?)) = ?', ['', strtolower((string) $filters['origen_usuario_alias'])]);
        }

        if (!empty($filters['origen_usuario_carnet']) && $this->hasCartOrigenUsuarioCarnetColumn()) {
            $query->whereRaw("upper(replace(coalesce(origen_usuario_carnet, ''), ' ', '')) = ?", [(string) $filters['origen_usuario_carnet']]);
        }

        if (array_key_exists('codigoSucursal', $filters) && $filters['codigoSucursal'] !== null && $this->hasCartOrigenSucursalCodigoColumn()) {
            $query->where('origen_sucursal_codigo', (string) $filters['codigoSucursal']);
        }

        if (array_key_exists('puntoVenta', $filters) && $filters['puntoVenta'] !== null && $this->hasCartOrigenSucursalIdColumn()) {
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

                if ($this->hasCartOrigenUsuarioEmailColumn()) {
                    $search->orWhere('origen_usuario_email', 'like', $term);
                }
                if ($this->hasCartOrigenUsuarioAliasColumn()) {
                    $search->orWhere('origen_usuario_alias', 'like', $term);
                }
                if ($this->hasCartOrigenUsuarioCarnetColumn()) {
                    $search->orWhere('origen_usuario_carnet', 'like', $term);
                }
            });
        }

        return $query;
    }

    private function facturacionCartFiscalBackfillMap($cartRows): array
    {
        $cartIds = collect($cartRows)
            ->pluck('id')
            ->map(fn ($value) => trim((string) $value))
            ->filter(fn ($value) => $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($cartIds === []) {
            return [];
        }

        return Venta::query()
            ->whereIn(DB::raw('cast(origen_venta_id as varchar)'), $cartIds)
            ->whereIn('origen_venta_tipo', ['facturacion_cart', 'facturacion_cart_remote'])
            ->orderByDesc('id')
            ->get(['id', 'origen_venta_id', 'codigoSeguimiento', 'numero_factura', 'cuf', 'url_pdf', 'url_xml'])
            ->groupBy(fn ($venta) => trim((string) ($venta->origen_venta_id ?? '')))
            ->map(fn ($rows) => $rows->first())
            ->toArray();
    }

    private function facturacionCartNotificationBackfillMap(array $seguimientos): array
    {
        return $this->latestNotificationsMapFromSeguimientos($seguimientos);
    }

    private function facturacionCartStatusPayload(object $cart, ?object $linkedVenta = null): array
    {
        $canal = strtolower(trim((string) ($cart->canal_emision ?? '')));
        $estado = strtolower(trim((string) ($cart->estado ?? '')));
        $estadoPago = strtolower(trim((string) ($cart->estado_pago ?? 'pendiente')));
        $estadoEmision = strtoupper(trim((string) ($cart->estado_emision ?? '')));
        $canConsult = $this->canConsultFacturacionCart($cart);
        $respuestaEmision = json_decode((string) ($cart->respuesta_emision ?? ''), true);
        if (!is_array($respuestaEmision)) {
            $respuestaEmision = [];
        }

        $cuf = trim((string) (
            data_get($respuestaEmision, 'cuf')
            ?: data_get($respuestaEmision, 'factura.cuf')
            ?: data_get($respuestaEmision, 'datos.cuf')
            ?: data_get($respuestaEmision, 'detalle.cuf')
            ?: ($linkedVenta->cuf ?? '')
            ?: ''
        ));
        $canAnnul = $canal !== 'qr' && $estadoEmision === 'FACTURADA' && $cuf !== '';

        if ($estado === 'descartado') {
            return ['key' => 'DESCARTADA', 'label' => 'Descartada', 'can_annul' => false, 'can_cancel' => false, 'can_consult' => false, 'cuf' => $cuf !== '' ? $cuf : null];
        }

        if ($canal === 'qr') {
            if ($estadoPago === 'pagado' || $estado === 'emitido') {
                return ['key' => 'QR_PAGADO', 'label' => 'Pagado QR', 'can_annul' => false, 'can_cancel' => false, 'can_consult' => $canConsult, 'cuf' => $cuf !== '' ? $cuf : null];
            }
            if ($estadoPago === 'cancelado') {
                return ['key' => 'QR_ANULADO', 'label' => 'QR anulado', 'can_annul' => false, 'can_cancel' => false, 'can_consult' => $canConsult, 'cuf' => $cuf !== '' ? $cuf : null];
            }
            return ['key' => 'QR_PENDIENTE', 'label' => 'QR pendiente', 'can_annul' => false, 'can_cancel' => true, 'can_consult' => $canConsult, 'cuf' => $cuf !== '' ? $cuf : null];
        }

        return match ($estadoEmision) {
            'FACTURADA' => ['key' => 'FACTURADA', 'label' => 'Facturada', 'can_annul' => $canAnnul, 'can_cancel' => false, 'can_consult' => false, 'cuf' => $cuf !== '' ? $cuf : null],
            'PENDIENTE' => ['key' => 'PENDIENTE', 'label' => 'Pendiente', 'can_annul' => false, 'can_cancel' => false, 'can_consult' => $canConsult, 'cuf' => $cuf !== '' ? $cuf : null],
            'RECHAZADA' => ['key' => 'RECHAZADA', 'label' => 'Rechazada', 'can_annul' => false, 'can_cancel' => false, 'can_consult' => $canConsult, 'cuf' => $cuf !== '' ? $cuf : null],
            'ERROR' => ['key' => 'ERROR', 'label' => 'Error', 'can_annul' => false, 'can_cancel' => false, 'can_consult' => $canConsult, 'cuf' => $cuf !== '' ? $cuf : null],
            'NO_APLICA' => ['key' => 'NO_APLICA', 'label' => 'No aplica', 'can_annul' => false, 'can_cancel' => false, 'can_consult' => $canConsult, 'cuf' => $cuf !== '' ? $cuf : null],
            default => ['key' => strtoupper($estado !== '' ? $estado : 'SIN_ESTADO'), 'label' => ucfirst($estado !== '' ? $estado : 'Sin estado'), 'can_annul' => false, 'can_cancel' => false, 'can_consult' => $canConsult, 'cuf' => $cuf !== '' ? $cuf : null],
        };
    }

    private function canConsultFacturacionCart(object $cart): bool
    {
        $estadoEmision = strtoupper(trim((string) ($cart->estado_emision ?? '')));
        $estadoPago = strtolower(trim((string) ($cart->estado_pago ?? 'pendiente')));
        $codigoSeguimiento = trim((string) (($cart->codigo_seguimiento_fiscal ?? null) ?: ($cart->codigo_seguimiento ?? '')));
        $transactionId = trim((string) ($cart->qr_transaction_id ?? ''));

        if ($codigoSeguimiento !== '' && in_array($estadoEmision, ['PENDIENTE', 'ERROR', 'RECHAZADA'], true)) {
            return true;
        }

        return $transactionId !== ''
            && in_array($estadoPago, ['pendiente', 'pagado', 'cancelado'], true)
            && in_array($estadoEmision, ['', 'NO_APLICA', 'PENDIENTE', 'ERROR', 'RECHAZADA'], true);
    }

    private function facturacionCartNumeroFactura(?string $respuestaEmision): ?string
    {
        return $this->extractNumeroFacturaFromDetalle($respuestaEmision);
    }

    private function facturacionCartItemsMapFromRows($cartRows): array
    {
        $cartIds = collect($cartRows)
            ->pluck('id')
            ->map(fn ($value) => (int) $value)
            ->filter(fn ($value) => $value > 0)
            ->unique()
            ->values()
            ->all();

        if ($cartIds === [] || !$this->hasFacturacionCartItemsTable()) {
            return [];
        }

        return DB::table('facturacion_cart_items')
            ->whereIn('cart_id', $cartIds)
            ->orderBy('id')
            ->get()
            ->groupBy('cart_id')
            ->map(function ($items) {
                return collect($items)->map(function ($item) {
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
                })->values()->all();
            })
            ->toArray();
    }

    private function mapFacturacionCartToVentaPayload(
        object $cart,
        array $preloadedItems = [],
        object|array|null $linkedVenta = null,
        ?Notificacione $notification = null
    ): array
    {
        $respuestaEmision = json_decode((string) ($cart->respuesta_emision ?? ''), true);
        if (!is_array($respuestaEmision)) {
            $respuestaEmision = [];
        }
        if (is_array($linkedVenta)) {
            $linkedVenta = (object) $linkedVenta;
        }
        $detalleNotificacion = $notification ? json_decode((string) $notification->detalle, true) : [];
        if (!is_array($detalleNotificacion)) {
            $detalleNotificacion = [];
        }

        $items = collect($preloadedItems);

        $status = $this->facturacionCartStatusPayload($cart, $linkedVenta);
        $fecha = $cart->emitido_en ?: $cart->created_at;
        $numeroFactura = $this->facturacionCartNumeroFactura((string) ($cart->respuesta_emision ?? ''))
            ?: ($detalleNotificacion['nroFactura'] ?? null)
            ?: ($linkedVenta->numero_factura ?? null);
        $codigoSeguimiento = (string) (($cart->codigo_seguimiento_fiscal ?? null) ?: ($cart->codigo_seguimiento ?? ''));
        if ($codigoSeguimiento === '') {
            $codigoSeguimiento = (string) ($linkedVenta->codigoSeguimiento ?? '');
        }
        if (!data_get($respuestaEmision, 'factura.cuf') && !data_get($respuestaEmision, 'cuf') && !blank($linkedVenta->cuf ?? null)) {
            data_set($respuestaEmision, 'factura.cuf', $linkedVenta->cuf);
        }
        if (!data_get($respuestaEmision, 'factura.nroFactura') && !blank($linkedVenta->numero_factura ?? null)) {
            data_set($respuestaEmision, 'factura.nroFactura', $linkedVenta->numero_factura);
        }
        if (!data_get($respuestaEmision, 'factura.pdfUrl') && !empty($detalleNotificacion['urlPdf'])) {
            data_set($respuestaEmision, 'factura.pdfUrl', $this->normalizeNotificationAssetUrl((string) $detalleNotificacion['urlPdf']));
        }
        if (!data_get($respuestaEmision, 'factura.xmlUrl') && !empty($detalleNotificacion['urlXml'])) {
            data_set($respuestaEmision, 'factura.xmlUrl', $this->normalizeNotificationAssetUrl((string) $detalleNotificacion['urlXml']));
        }

        $linkedVentaId = (int) ($linkedVenta->id ?? 0);
        if ($linkedVentaId > 0) {
            $ventaUpdates = [];
            $backfillNumeroFactura = trim((string) ($numeroFactura ?? ''));
            $backfillCuf = trim((string) (
                data_get($respuestaEmision, 'factura.cuf')
                ?: data_get($respuestaEmision, 'cuf')
                ?: ''
            ));
            $backfillPdfUrl = trim((string) (
                data_get($respuestaEmision, 'factura.pdfUrl')
                ?: data_get($respuestaEmision, 'pdfUrl')
                ?: ''
            ));
            $backfillXmlUrl = trim((string) (
                data_get($respuestaEmision, 'factura.xmlUrl')
                ?: data_get($respuestaEmision, 'xmlUrl')
                ?: ''
            ));

            if ($backfillNumeroFactura !== '' && blank($linkedVenta->numero_factura ?? null)) {
                $ventaUpdates['numero_factura'] = $backfillNumeroFactura;
            }
            if ($backfillCuf !== '' && blank($linkedVenta->cuf ?? null)) {
                $ventaUpdates['cuf'] = $backfillCuf;
            }
            if ($backfillPdfUrl !== '' && blank($linkedVenta->url_pdf ?? null)) {
                $ventaUpdates['url_pdf'] = $backfillPdfUrl;
            }
            if ($backfillXmlUrl !== '' && blank($linkedVenta->url_xml ?? null)) {
                $ventaUpdates['url_xml'] = $backfillXmlUrl;
            }

            if ($ventaUpdates !== []) {
                $ventaUpdates['updated_at'] = now();
                DB::table('ventas')->where('id', $linkedVentaId)->update($ventaUpdates);
            }
        }

        return [
            'id' => 'cart-' . (int) $cart->id,
            'cartId' => (int) $cart->id,
            'fecha' => $fecha ? date('Y-m-d H:i:s', strtotime((string) $fecha)) : null,
            'codigoOrden' => $this->normalizeFacturacionCartCodigoOrden($cart),
            'codigoSeguimiento' => $codigoSeguimiento,
            'numeroFactura' => $numeroFactura,
            'origenVentaId' => (int) $cart->id,
            'origenVentaTipo' => 'facturacion_cart',
            'canal_emision' => (string) ($cart->canal_emision ?? ''),
            'metodo_pago' => (string) ($cart->metodo_pago ?? ''),
            'estado_pago' => (string) ($cart->estado_pago ?? ''),
            'estado_emision' => (string) ($cart->estado_emision ?? ''),
            'mensaje_emision' => (string) ($cart->mensaje_emision ?? ''),
            'incidencia_revisada_at' => $cart->incidencia_revisada_at,
            'incidencia_revisada_por' => $cart->incidencia_revisada_por,
            'incidencia_revision_nota' => $cart->incidencia_revision_nota,
            'anulacion' => [
                'anuladaAt' => $cart->anulada_at ?? null,
                'anuladaPorUserId' => $cart->anulada_por_user_id ?? null,
                'anuladaPorNombre' => $cart->anulada_por_nombre ?? null,
                'anuladaPorEmail' => $cart->anulada_por_email ?? null,
                'motivo' => $cart->anulacion_motivo ?? null,
                'tipo' => $cart->anulacion_tipo ?? null,
                'autorizadaPorUserId' => $cart->anulacion_autorizada_por_user_id ?? null,
                'autorizadaPorEmail' => $cart->anulacion_autorizada_por_email ?? null,
                'numeroFactura' => $numeroFactura,
                'codigoOrden' => $this->normalizeFacturacionCartCodigoOrden($cart),
                'cuf' => data_get($respuestaEmision, 'factura.cuf') ?: data_get($respuestaEmision, 'cuf') ?: ($linkedVenta->cuf ?? null),
            ],
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
                'email' => $this->hasCartOrigenUsuarioEmailColumn() ? $cart->origen_usuario_email : null,
                'alias' => $this->hasCartOrigenUsuarioAliasColumn() ? $cart->origen_usuario_alias : null,
                'carnet' => $this->hasCartOrigenUsuarioCarnetColumn() ? $cart->origen_usuario_carnet : null,
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
        $startedAt = $this->reportStartedAt();
        Log::info('ventas.index.start', $this->reportLogContext($request));

        $filters = $this->resolveIdentityFilters($request, $this->validateVentaReportFilters($request));
        Log::info('ventas.index.filters', $this->reportLogContext($request, [
            'filters' => $filters,
            'codigoSucursal_is_zero' => isset($filters['codigoSucursal']) && (int) $filters['codigoSucursal'] === 0,
            'puntoVenta_is_zero' => isset($filters['puntoVenta']) && (int) $filters['puntoVenta'] === 0,
        ]));
        $cartRows = collect();

        if (Schema::hasTable('facturacion_carts')) {
            $cartRows = $this->buildFacturacionCartReportQuery($filters)
                ->orderByDesc('emitido_en')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
        }
        Log::info('ventas.index.carts.ready', $this->reportLogContext($request, [
            'elapsed_ms' => $this->reportElapsedMs($startedAt),
            'cart_rows_count' => $cartRows->count(),
        ]));

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

        $ventas = $ventasQuery
            ->latest('created_at')
            ->get(array_values(array_filter([
                'id',
                'created_at',
                'codigoOrden',
                'codigoSeguimiento',
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
                'tipo_emision_sufe',
                'cuf',
                'url_pdf',
                'url_xml',
                'observacion_sufe',
                'fecha_notificacion_sufe',
                'departamento',
            ])));
        Log::info('ventas.index.ventas.ready', $this->reportLogContext($request, [
            'elapsed_ms' => $this->reportElapsedMs($startedAt),
            'ventas_count' => $ventas->count(),
        ]));

        $detalleMaps = $this->detalleMapsFromRows($ventas);
        $itemsCountMaps = $this->itemsCountMapsFromRows($ventas);
        $notificationsMap = $this->latestNotificationsMapFromSeguimientos($ventas->pluck('codigoSeguimiento')->all());

        $list = $ventas->map(function (Venta $venta) use ($detalleMaps, $itemsCountMaps, $notificationsMap) {
            $ventaId = (int) $venta->id;
            $cartId = (int) ($venta->origen_venta_id ?? 0);
            $codigoSeguimiento = trim((string) ($venta->codigoSeguimiento ?? ''));
            $notification = $codigoSeguimiento !== '' ? ($notificationsMap[$codigoSeguimiento] ?? null) : null;
            $status = $this->protocolStatusFromVentaNotification($venta, $notification);
            $detalleNotificacion = $notification ? json_decode((string) $notification->detalle, true) : [];
            $detalle = $detalleMaps['detalle'][$ventaId] ?? [];

            if ($detalle === [] && $cartId > 0) {
                $detalle = $detalleMaps['cart'][$cartId] ?? [];
            }

            $itemsCount = (int) ($itemsCountMaps['detalle'][$ventaId] ?? 0);
            if ($itemsCount === 0 && $cartId > 0) {
                $itemsCount = (int) ($itemsCountMaps['cart'][$cartId] ?? 0);
            }

            return [
                'id' => $venta->id,
                'fecha' => optional($venta->created_at)->format('Y-m-d H:i:s'),
                'codigoOrden' => $venta->codigoOrden,
                'codigoSeguimiento' => $venta->codigoSeguimiento,
                'origenVentaId' => $venta->origen_venta_id,
                'origenVentaTipo' => $venta->origen_venta_tipo,
                'cliente' => [
                    'razonSocial' => $venta->razonSocial,
                    'documentoIdentidad' => $venta->documentoIdentidad,
                    'codigoCliente' => $venta->codigoCliente,
                ],
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
                    'departamento' => $venta->departamento,
                ],
                'detalle' => $detalle,
                'itemsCount' => $itemsCount,
                'cantidad' => max(1, $itemsCount ?: count($detalle)),
                'total' => (float) $venta->total,
                'estadoSufe' => $venta->estado_sufe,
                'cuf' => $venta->cuf,
                'status' => $status,
                'seguimiento' => [
                    'codigoSeguimiento' => $venta->codigoSeguimiento,
                    'estadoSufe' => $venta->estado_sufe,
                    'tipoEmision' => $status['tipoEmision'] ?? $venta->tipo_emision_sufe,
                    'cuf' => $status['cuf'] ?? $venta->cuf,
                    'urlPdf' => $venta->url_pdf,
                    'urlXml' => $venta->url_xml,
                    'observacion' => $venta->observacion_sufe,
                    'fechaNotificacion' => $venta->fecha_notificacion_sufe,
                    'notificacionEstado' => $notification?->estado,
                    'notificacionMensaje' => $notification?->mensaje,
                    'detalle' => is_array($detalleNotificacion) ? $detalleNotificacion : [],
                ],
                'anulacion' => $this->anulacionPayloadForVenta($venta),
            ];
        })->values();
        Log::info('ventas.index.show-map.ready', $this->reportLogContext($request, [
            'elapsed_ms' => $this->reportElapsedMs($startedAt),
            'mapped_ventas_count' => $list->count(),
        ]));

        $cartItemsMap = $this->facturacionCartItemsMapFromRows($cartRows);
        $cartFiscalBackfillMap = $this->facturacionCartFiscalBackfillMap($cartRows);
        $cartNotificationBackfillMap = $this->facturacionCartNotificationBackfillMap(
            $cartRows->map(fn ($cart) => (string) (($cart->codigo_seguimiento_fiscal ?? null) ?: ($cart->codigo_seguimiento ?? '')))
                ->filter()
                ->values()
                ->all()
        );
        $cartPayloads = $cartRows
            ->map(fn ($cart) => $this->mapFacturacionCartToVentaPayload(
                $cart,
                $cartItemsMap[(int) $cart->id] ?? [],
                $cartFiscalBackfillMap[(int) $cart->id] ?? null,
                $cartNotificationBackfillMap[(string) (($cart->codigo_seguimiento_fiscal ?? null) ?: ($cart->codigo_seguimiento ?? ''))] ?? null
            ))
            ->values();

        $merged = $list
            ->concat($cartPayloads)
            ->sortByDesc(function ($row) {
                return strtotime((string) ($row['fecha'] ?? '1970-01-01 00:00:00')) ?: 0;
            })
            ->values();
        Log::info('ventas.index.ready', $this->reportLogContext($request, [
            'elapsed_ms' => $this->reportElapsedMs($startedAt),
            'merged_count' => $merged->count(),
        ]));

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
        $data['anulacion'] = $this->anulacionPayloadForVenta($venta);

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
                        Log::warning('La respuesta de rechazo de emisiÃƒÂ³n individual no cumple el protocolo', [
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
                'message' => 'La solicitud de emisiÃƒÂ³n individual no cumple la validaciÃƒÂ³n del protocolo SEFE.',
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
                'message' => 'La solicitud de documento de ajuste no cumple la validaciÃƒÂ³n del protocolo SEFE.',
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
                'message' => 'La solicitud de emisiÃƒÂ³n masiva no cumple la validaciÃƒÂ³n del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            return response()->json($e->response?->json() ?? [
                'message' => 'Error al emitir facturas masivas.',
                'details' => $e->getMessage(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'No se pudo conectar con el servicio SEFE para emisiÃƒÂ³n masiva.',
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
                    'venta_ids' => ["La venta {$venta->id} no estÃƒÂ¡ disponible para reenvÃƒÂ­o automÃƒÂ¡tico."],
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
            'message' => 'Ventas enviadas en emisiÃƒÂ³n masiva correctamente.',
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
                'message' => 'La solicitud de contingencia CAFC no cumple la validaciÃƒÂ³n del protocolo SEFE.',
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
                    'venta_ids' => ["La venta {$venta->id} no estÃƒÂ¡ disponible para contingencia CAFC."],
                ]);
            }
            if (!isset($validated['nro_facturas'][$venta->id]) || (int) $validated['nro_facturas'][$venta->id] <= 0) {
                throw ValidationException::withMessages([
                    'nro_facturas' => ["Debe proporcionar un nroFactura manual vÃƒÂ¡lido para la venta {$venta->id}."],
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
    //  Consultar emisiÃƒÆ’Ã‚Â³n
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
        Log::info("CÃƒÆ’Ã‚Â³digo de Seguimiento: {$codigoSeguimiento}");
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
            Log::error("ExcepciÃƒÆ’Ã‚Â³n al consultar venta: " . $e->getMessage());
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
                'message' => 'Error al consultar homologaciÃƒÂ³n de productos.',
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
                'tipoParametro' => ['El tipoParametro solicitado no estÃƒÂ¡ soportado por el protocolo SEFE.'],
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
                'message' => 'Error al consultar las paramÃƒÂ©tricas.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function consultarPaquete($codigoSeguimientoPaquete)
    {
        $url = $this->ageticBaseUrl() . "/consulta/paquete/{$codigoSeguimientoPaquete}";
        Log::info("CÃƒÂ³digo de Seguimiento Paquete: {$codigoSeguimientoPaquete}");
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
            Log::error("ExcepciÃƒÂ³n al consultar paquete: " . $e->getMessage());
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
        $guard = $this->buildAnulacionGuardStatus($user);
        Log::info('VentaController anulacionGuardStatus', [
            'user_id' => $user->id ?? null,
            'user_email' => $user->email ?? null,
            'guard' => $guard,
        ]);

        return response()->json([
            'ok' => true,
            'guard' => $guard,
        ]);
    }

    public function autorizarAnulacion(Request $request)
    {
        $currentUser = Auth::guard('api')->user() ?? $request->user();
        Log::info('VentaController autorizarAnulacion start', [
            'current_user_id' => $currentUser->id ?? null,
            'current_user_email' => $currentUser->email ?? null,
            'payload' => $request->except(['supervisor_password']),
        ]);
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
        Log::info('VentaController autorizarAnulacion supervisor lookup', [
            'current_user_id' => $currentUser->id ?? null,
            'supervisor_found' => (bool) $supervisor,
            'supervisor_id' => $supervisor->id ?? null,
            'supervisor_email' => $supervisor->email ?? null,
            'supervisor_roles' => $supervisor && method_exists($supervisor, 'roleSlugs') ? $supervisor->roleSlugs() : [],
            'supervisor_permissions' => $supervisor && method_exists($supervisor, 'permissions') ? $supervisor->permissions() : [],
        ]);

        if (!$supervisor || !Hash::check((string) $validated['supervisor_password'], (string) $supervisor->password)) {
            Log::warning('VentaController autorizarAnulacion invalid supervisor credentials', [
                'current_user_id' => $currentUser->id ?? null,
                'supervisor_email' => $validated['supervisor_email'] ?? null,
                'supervisor_found' => (bool) $supervisor,
            ]);
            return response()->json([
                'message' => 'Credenciales de supervisor invÃƒÂ¡lidas.',
                'code' => 'ANULACION_SUPERVISOR_INVALIDO',
            ], 422);
        }

        if (!$this->isAnulacionSupervisor($supervisor)) {
            Log::warning('VentaController autorizarAnulacion supervisor without permission', [
                'current_user_id' => $currentUser->id ?? null,
                'supervisor_id' => $supervisor->id ?? null,
                'supervisor_email' => $supervisor->email ?? null,
                'supervisor_roles' => method_exists($supervisor, 'roleSlugs') ? $supervisor->roleSlugs() : [],
                'supervisor_permissions' => method_exists($supervisor, 'permissions') ? $supervisor->permissions() : [],
            ]);
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
        Log::info('VentaController autorizarAnulacion success', [
            'current_user_id' => $currentUser->id ?? null,
            'current_user_email' => $currentUser->email ?? null,
            'supervisor_id' => $supervisor->id ?? null,
            'supervisor_email' => $supervisor->email ?? null,
            'expires_at' => $expiresAt->toIso8601String(),
            'guard' => $this->buildAnulacionGuardStatus($currentUser),
        ]);

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
        Log::info('VentaController anularFactura start', [
            'cuf' => (string) $cuf,
            'user_id' => $currentUser->id ?? null,
            'user_email' => $currentUser->email ?? null,
            'guard' => $guard,
            'payload_raw' => $request->all(),
        ]);

        if (($guard['allowed'] ?? false) !== true) {
            Log::warning('VentaController anularFactura blocked by guard', [
                'cuf' => (string) $cuf,
                'user_id' => $currentUser->id ?? null,
                'guard' => $guard,
            ]);
            return response()->json([
                'message' => 'Anulacion bloqueada. Requiere autorizacion de rol superior o habilitacion global de administrador.',
                'code' => 'ANULACION_REQUIERE_AUTORIZACION',
                'guard' => $guard,
            ], 423);
        }

        $requestData = $this->sufeValidator->validateAnulacionPayload($request->all());
        $respaldoData = $this->storeAnulacionRespaldo($request, (string) $cuf);
        $auditData = array_merge(
            $this->buildAnulacionAuditData($currentUser, $guard, $requestData),
            $respaldoData
        );
        Log::info('VentaController anularFactura delegating to FacturaVentaApiController', [
            'cuf' => (string) $cuf,
            'payload' => $requestData,
            'has_respaldo' => !empty($respaldoData),
            'respaldo_nombre' => $respaldoData['anulacion_respaldo_nombre'] ?? null,
            'guard' => $guard,
        ]);

        try {
            $delegatedResponse = app(FacturaVentaApiController::class)->anular($request, (string) $cuf);
            $statusCode = method_exists($delegatedResponse, 'getStatusCode') ? $delegatedResponse->getStatusCode() : 200;
            $body = method_exists($delegatedResponse, 'getData')
                ? $delegatedResponse->getData(true)
                : [];

            if ($statusCode < 400 && (bool) data_get($body, 'ok', true) === true) {
                $venta = Venta::query()
                    ->where('cuf', (string) $cuf)
                    ->orderByDesc('id')
                    ->first();

                $this->persistAnulacionAuditForVenta(
                    $venta,
                    $auditData,
                    (string) data_get($body, 'mensaje', ($requestData['motivo'] ?? 'Factura anulada.'))
                );

                $body['audit'] = [
                    'cuf' => (string) $cuf,
                    'motivo' => $requestData['motivo'] ?? null,
                    'tipoAnulacion' => $requestData['tipoAnulacion'] ?? null,
                    'anuladaPor' => $auditData['anulada_por_nombre'] ?? $auditData['anulada_por_email'] ?? null,
                    'anuladaAt' => ($auditData['anulada_at'] ?? null)?->toIso8601String(),
                    'autorizadaPor' => $auditData['anulacion_autorizada_por_email'] ?? null,
                    'respaldoNombre' => $auditData['anulacion_respaldo_nombre'] ?? null,
                    'respaldoMime' => $auditData['anulacion_respaldo_mime'] ?? null,
                    'respaldoSize' => $auditData['anulacion_respaldo_size'] ?? null,
                    'respaldoUrl' => $this->anulacionRespaldoUrl($auditData['anulacion_respaldo_path'] ?? null),
                    'numeroFactura' => $venta->numero_factura ?? null,
                    'codigoOrden' => $venta->codigoOrden ?? null,
                    'estadoFinal' => $venta->estado_sufe ?? null,
                ];

                Log::info('VentaController anularFactura success with audit', [
                    'cuf' => (string) $cuf,
                    'status_code' => $statusCode,
                    'venta_id' => $venta->id ?? null,
                    'estado_final' => $venta->estado_sufe ?? null,
                    'audit' => $body['audit'],
                ]);
            } else {
                Log::warning('VentaController anularFactura non-success response', [
                    'cuf' => (string) $cuf,
                    'status_code' => $statusCode,
                    'body' => $body,
                ]);
            }

            return response()->json($body, $statusCode);
        } catch (\Throwable $delegationError) {
            Log::error('VentaController delegated anulacion failed', [
                'cuf' => (string) $cuf,
                'user_id' => $currentUser->id ?? null,
                'message' => $delegationError->getMessage(),
                'trace' => $delegationError->getTraceAsString(),
            ]);
            throw $delegationError;
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
            Log::info('VentaController isAnulacionSupervisor evaluated', [
                'user_id' => null,
                'user_email' => null,
                'roles' => [],
                'permissions' => [],
                'has_higher_role' => false,
                'has_higher_permission' => false,
                'result' => false,
            ]);
            return false;
        }

        $roles = method_exists($user, 'roleSlugs') ? $user->roleSlugs() : [];
        $permissions = method_exists($user, 'permissions') ? $user->permissions() : [];
        $hasHigherRole = method_exists($user, 'hasRole') && ($user->hasRole('admin') || $user->hasRole('administrador') || $user->hasRole('supervisor'));
        $hasHigherPermission = method_exists($user, 'hasPermission') && (
            $user->hasPermission('rbac.manage')
            || $user->hasPermission('usuarios.manage')
            || $user->hasPermission('ventas.manage')
        );
        $result = $hasHigherRole || $hasHigherPermission;

        Log::info('VentaController isAnulacionSupervisor evaluated', [
            'user_id' => $user->id ?? null,
            'user_email' => $user->email ?? null,
            'roles' => $roles,
            'permissions' => $permissions,
            'has_higher_role' => $hasHigherRole,
            'has_higher_permission' => $hasHigherPermission,
            'result' => $result,
        ]);

        return $result;
    }

}


