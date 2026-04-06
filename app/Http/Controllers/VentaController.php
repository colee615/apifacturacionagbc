<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use App\Models\Notificacione;
use App\Support\SufeSectorUnoValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class VentaController extends Controller
{
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

        return [
            'id' => $venta->id,
            'codigoOrden' => $venta->codigoOrden,
            'codigoSeguimiento' => $venta->codigoSeguimiento,
            'fecha' => optional($venta->created_at)->format('Y-m-d H:i:s'),
            'cliente' => [
                'id' => null,
                'razonSocial' => $venta->razonSocial,
                'documentoIdentidad' => $venta->documentoIdentidad,
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

    // =========================
    //  Listado
    // =========================
    public function index()
    {
        $ventas = Venta::where('estado', 1)->get();
        $list = [];
        foreach ($ventas as $venta) {
            $list[] = $this->show($venta);
        }
        return response()->json($list);
    }

    public function operables(Request $request)
    {
        $scope = $request->query('scope', 'actionable');

        $ventas = Venta::where('estado', 1)
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
    public function show(Venta $venta)
    {
        $venta->load('detalleVentas');
        $status = $this->protocolStatusForVenta($venta);
        $notification = $this->latestNotificationForVenta($venta);
        $detalleNotificacion = $notification ? json_decode((string) $notification->detalle, true) : [];

        $data = $venta->toArray();
        $data['fecha'] = $venta->created_at->format('Y-m-d');
        $data['cliente'] = [
            'razonSocial' => $venta->razonSocial,
            'documentoIdentidad' => $venta->documentoIdentidad,
            'codigoCliente' => $venta->codigoCliente,
        ];
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
    public function consultarVenta($codigoSeguimiento)
    {
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

    // =========================
    //  Anular factura
    // =========================
    public function anularFactura(Request $request, $cuf)
    {
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

}
