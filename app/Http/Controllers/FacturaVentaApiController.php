<?php

namespace App\Http\Controllers;

use App\Models\DetalleVenta;
use App\Models\Notificacione;
use App\Models\Venta;
use App\Support\SufeSectorUnoValidator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FacturaVentaApiController extends Controller
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

    private function createVenta(array $payload): Venta
    {
        $venta = new Venta();
        $venta->origen_sistema = 'BOLIPOST';
        $venta->origen_usuario_id = data_get($payload, 'origenUsuario.id');
        $venta->origen_usuario_nombre = data_get($payload, 'origenUsuario.nombre');
        $venta->origen_usuario_email = data_get($payload, 'origenUsuario.email');
        $venta->origen_sucursal_id = data_get($payload, 'origenSucursal.id');
        $venta->origen_sucursal_codigo = data_get($payload, 'origenSucursal.codigo');
        $venta->origen_sucursal_nombre = data_get($payload, 'origenSucursal.nombre');
        $venta->codigoSucursal = (int) $payload['codigoSucursal'];
        $venta->puntoVenta = (int) $payload['puntoVenta'];
        $venta->documentoSector = (int) $payload['documentoSector'];
        $venta->municipio = $payload['municipio'];
        $venta->departamento = $payload['departamento'] ?? null;
        $venta->telefono = $payload['telefono'];
        $venta->codigoCliente = $payload['codigoCliente'];
        $venta->razonSocial = $payload['razonSocial'];
        $venta->documentoIdentidad = $payload['documentoIdentidad'];
        $venta->tipoDocumentoIdentidad = (int) $payload['tipoDocumentoIdentidad'];
        $venta->complemento = (int) $payload['tipoDocumentoIdentidad'] === 1
            ? ($payload['complemento'] ?? null)
            : null;
        $venta->correo = $payload['correo'];
        $venta->metodoPago = (int) $payload['metodoPago'];
        $venta->formatoFactura = $payload['formatoFactura'];
        $venta->monto_descuento_adicional = (float) ($payload['montoDescuentoAdicional'] ?? 0);
        $venta->motivo = 'Integracion bolipost';
        $venta->total = (float) $payload['montoTotal'];
        $venta->codigoSeguimiento = 'pendiente-' . Str::uuid()->toString();
        $venta->estado = 1;
        $venta->save();

        return $venta;
    }

    private function createDetalleVentas(Venta $venta, array $payload): void
    {
        foreach ($payload['detalle'] as $detalle) {
            $detalleVenta = new DetalleVenta();
            $detalleVenta->venta_id = $venta->id;
            $detalleVenta->actividadEconomica = $detalle['actividadEconomica'];
            $detalleVenta->codigoSin = $detalle['codigoSin'];
            $detalleVenta->codigo = $detalle['codigo'];
            $detalleVenta->descripcion = $detalle['descripcion'];
            $detalleVenta->unidadMedida = (int) $detalle['unidadMedida'];
            $detalleVenta->precio = (float) $detalle['precioUnitario'];
            $detalleVenta->cantidad = (float) $detalle['cantidad'];
            $detalleVenta->estado = 1;
            $detalleVenta->save();
        }
    }

    private function sanitizePayloadForAgetic(array $payload): array
    {
        $clean = $payload;

        unset($clean['origenUsuario'], $clean['origenSucursal']);

        if ((int) ($clean['tipoDocumentoIdentidad'] ?? 0) !== 1 || blank($clean['complemento'] ?? null)) {
            unset($clean['complemento']);
        }

        if (blank($clean['departamento'] ?? null)) {
            unset($clean['departamento']);
        }

        if ((float) ($clean['montoDescuentoAdicional'] ?? 0) <= 0) {
            unset($clean['montoDescuentoAdicional']);
        }

        return $clean;
    }

    public function emitir(Request $request)
    {
        DB::beginTransaction();
        $requestData = $request->all();
        $codigoOrdenRecibido = (string) ($requestData['codigoOrden'] ?? '');
        $codigoOrden = $codigoOrdenRecibido;

        Log::info('FacturaVentaApi emitir started', [
            'codigoOrden_recibido' => $codigoOrdenRecibido,
            'ip' => $request->ip(),
            'payload_keys' => array_keys($requestData),
        ]);

        try {
            $validated = $this->sufeValidator->validateIndividualPayload($requestData);
            Log::info('FacturaVentaApi emitir payload validated', [
                'codigoOrden_recibido' => $codigoOrdenRecibido,
                'detalle_count' => count($validated['detalle'] ?? []),
                'montoTotal' => $validated['montoTotal'] ?? null,
                'documentoIdentidad' => $validated['documentoIdentidad'] ?? null,
                'codigoCliente' => $validated['codigoCliente'] ?? null,
            ]);
            $this->assertFacturaVentaSector($validated);
            Log::info('FacturaVentaApi emitir sector validated', [
                'codigoOrden_recibido' => $codigoOrdenRecibido,
                'documentoSector' => $validated['documentoSector'] ?? null,
            ]);

            Log::info('FacturaVentaApi emitir snapshot prepared', [
                'codigoOrden_recibido' => $codigoOrdenRecibido,
                'codigoCliente' => $validated['codigoCliente'] ?? null,
                'razonSocial' => $validated['razonSocial'] ?? null,
                'documentoIdentidad' => $validated['documentoIdentidad'] ?? null,
                'origen_usuario_id' => data_get($validated, 'origenUsuario.id'),
                'origen_usuario_nombre' => data_get($validated, 'origenUsuario.nombre'),
                'origen_sucursal_id' => data_get($validated, 'origenSucursal.id'),
                'origen_sucursal_nombre' => data_get($validated, 'origenSucursal.nombre'),
            ]);
            $venta = $this->createVenta($validated);
            $codigoOrden = (string) $venta->codigoOrden;
            Log::info('FacturaVentaApi emitir venta created', [
                'codigoOrden' => $codigoOrden,
                'codigoOrden_recibido' => $codigoOrdenRecibido,
                'venta_id' => $venta->id,
                'codigoSeguimiento_temporal' => $venta->codigoSeguimiento,
            ]);
            $this->createDetalleVentas($venta, $validated);
            Log::info('FacturaVentaApi emitir detalle created', [
                'codigoOrden' => $codigoOrden,
                'venta_id' => $venta->id,
                'detalle_count' => count($validated['detalle'] ?? []),
            ]);
            $requestPayload = $this->sanitizePayloadForAgetic($validated);
            $requestPayload['codigoOrden'] = $codigoOrden;

            Log::info('FacturaVentaApi emitir request', $requestPayload);

            $response = $this->ageticClient()->post(
                $this->ageticBaseUrl() . '/facturacion/emision/individual',
                $requestPayload
            );

            $payload = $response->json();

            if ($response->successful()) {
                $this->sufeValidator->validateAcceptedIndividualResponse($payload ?? []);
                $venta->codigoSeguimiento = data_get($payload, 'datos.codigoSeguimiento');
                $venta->save();
                Log::info('FacturaVentaApi emitir response accepted', [
                'status' => $response->status(),
                'codigoOrden' => $venta->codigoOrden,
                'codigoSeguimiento' => $venta->codigoSeguimiento,
                'venta_id' => $venta->id,
                'body' => $payload,
            ]);
                DB::commit();

                return response()->json($payload, $response->status());
            }

            Log::warning('FacturaVentaApi emitir response rejected', [
                'status' => $response->status(),
                'codigoOrden' => $venta->codigoOrden,
                'venta_id' => $venta->id,
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

            DB::rollBack();
            Log::warning('FacturaVentaApi emitir rolled back after rejected response', [
                'codigoOrden' => $codigoOrden,
                'venta_id' => $venta->id ?? null,
            ]);

            return response()->json($payload, $response->status());
        } catch (ValidationException $e) {
            DB::rollBack();
            Log::warning('FacturaVentaApi emitir validation exception', [
                'codigoOrden' => $codigoOrden,
                'errors' => $e->errors(),
            ]);

            return response()->json([
                'message' => 'La solicitud de factura de venta no cumple la validacion del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            DB::rollBack();
            Log::error('FacturaVentaApi emitir request exception', [
                'codigoOrden' => $codigoOrden,
                'status' => $e->response?->status(),
                'body' => $e->response?->json(),
                'msg' => $e->getMessage(),
            ]);

            return response()->json($e->response?->json() ?? [
                'message' => 'Error al emitir la factura de venta.',
                'details' => $e->getMessage(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            DB::rollBack();
            Log::error('FacturaVentaApi emitir connection exception', [
                'codigoOrden' => $codigoOrden,
                'msg' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'No se pudo conectar con el servicio SEFE.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('FacturaVentaApi emitir unexpected error', [
                'codigoOrden' => $codigoOrden,
                'msg' => $e->getMessage(),
                'trace_line' => $e->getLine(),
                'trace_file' => $e->getFile(),
            ]);

            return response()->json([
                'message' => 'Error inesperado al emitir la factura de venta.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function consultar(Request $request, string $codigoSeguimiento)
    {
        $tipo = $request->query('tipo');
        $url = $this->ageticBaseUrl() . "/consulta/{$codigoSeguimiento}";

        Log::info('FacturaVentaApi consultar started', [
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
                $this->sufeValidator->validateConsultaFacturaResponse($payload ?? []);
                Log::info('FacturaVentaApi consultar response accepted', [
                    'codigoSeguimiento' => $codigoSeguimiento,
                    'status' => $response->status(),
                    'body' => $payload,
                ]);

                return response()->json($payload, 200);
            }

            Log::warning('FacturaVentaApi consultar response rejected', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'status' => $response->status(),
                'body' => $payload,
            ]);

            return response()->json($payload ?? [
                'message' => 'Error al consultar la factura.',
            ], $response->status());
        } catch (RequestException $e) {
            Log::error('FacturaVentaApi consultar request exception', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'status' => $e->response?->status(),
                'body' => $e->response?->json(),
                'msg' => $e->getMessage(),
            ]);
            return response()->json($e->response?->json() ?? [
                'message' => 'Error al consultar la factura.',
                'details' => $e->getMessage(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            Log::error('FacturaVentaApi consultar connection exception', [
                'codigoSeguimiento' => $codigoSeguimiento,
                'msg' => $e->getMessage(),
            ]);
            return response()->json([
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
                'message' => 'Error inesperado al consultar la factura.',
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
