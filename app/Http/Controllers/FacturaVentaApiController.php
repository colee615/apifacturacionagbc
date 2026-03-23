<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\DetalleVenta;
use App\Models\Notificacione;
use App\Models\Servicio;
use App\Models\Usuario;
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

    private function integrationUsuarioId(): int
    {
        $configuredId = (int) config('services.facturacion_api.integration_usuario_id', 0);

        if ($configuredId > 0 && Usuario::whereKey($configuredId)->exists()) {
            return $configuredId;
        }

        $fallbackId = Usuario::query()
            ->where('estado', 1)
            ->orderBy('id')
            ->value('id');

        if ($fallbackId) {
            return (int) $fallbackId;
        }

        throw ValidationException::withMessages([
            'usuario_id' => ['No existe un usuario activo configurado para registrar ventas de integracion.'],
        ]);
    }

    private function syncCliente(array $payload): Cliente
    {
        $cliente = Cliente::query()->firstOrNew([
            'codigoCliente' => $payload['codigoCliente'],
        ]);

        $cliente->razonSocial = $payload['razonSocial'];
        $cliente->documentoIdentidad = $payload['documentoIdentidad'];
        $cliente->tipoDocumentoIdentidad = (int) $payload['tipoDocumentoIdentidad'];
        $cliente->complemento = (int) $payload['tipoDocumentoIdentidad'] === 1
            ? ($payload['complemento'] ?? null)
            : null;
        $cliente->correo = $payload['correo'];
        $cliente->estado = 1;
        $cliente->save();

        return $cliente;
    }

    private function syncServicio(array $detalle): Servicio
    {
        $servicio = Servicio::query()
            ->where('codigo', $detalle['codigo'])
            ->where('actividadEconomica', $detalle['actividadEconomica'])
            ->where('codigoSin', $detalle['codigoSin'])
            ->first();

        if (!$servicio) {
            $servicio = new Servicio();
        }

        $servicio->nombre = Str::limit((string) $detalle['descripcion'], 250, '');
        $servicio->codigo = $detalle['codigo'];
        $servicio->actividadEconomica = $detalle['actividadEconomica'];
        $servicio->descripcion = $detalle['descripcion'];
        $servicio->precioUnitario = (float) $detalle['precioUnitario'];
        $servicio->unidadMedida = (int) $detalle['unidadMedida'];
        $servicio->codigoSin = $detalle['codigoSin'];
        $servicio->tipo = 'BOLIPOST';
        $servicio->save();

        return $servicio;
    }

    private function createVenta(array $payload, Cliente $cliente): Venta
    {
        $venta = new Venta();
        $venta->cliente_id = $cliente->id;
        $venta->usuario_id = $this->integrationUsuarioId();
        $venta->codigoSucursal = (int) $payload['codigoSucursal'];
        $venta->puntoVenta = (int) $payload['puntoVenta'];
        $venta->documentoSector = (int) $payload['documentoSector'];
        $venta->municipio = $payload['municipio'];
        $venta->departamento = $payload['departamento'] ?? null;
        $venta->telefono = $payload['telefono'];
        $venta->metodoPago = (int) $payload['metodoPago'];
        $venta->formatoFactura = $payload['formatoFactura'];
        $venta->monto_descuento_adicional = (float) ($payload['montoDescuentoAdicional'] ?? 0);
        $venta->motivo = 'Integracion bolipost';
        $venta->total = (float) $payload['montoTotal'];
        $venta->codigoSeguimiento = 'pendiente-' . Str::uuid()->toString();
        $venta->estado = 1;
        $venta->save();

        $venta->codigoOrden = $payload['codigoOrden'];
        $venta->save();

        return $venta;
    }

    private function createDetalleVentas(Venta $venta, array $payload): void
    {
        foreach ($payload['detalle'] as $detalle) {
            $servicio = $this->syncServicio($detalle);

            $detalleVenta = new DetalleVenta();
            $detalleVenta->venta_id = $venta->id;
            $detalleVenta->servicio_id = $servicio->id;
            $detalleVenta->precio = (float) $detalle['precioUnitario'];
            $detalleVenta->cantidad = (float) $detalle['cantidad'];
            $detalleVenta->estado = 1;
            $detalleVenta->save();
        }
    }

    private function sanitizePayloadForAgetic(array $payload): array
    {
        $clean = $payload;

        if ((int) ($clean['tipoDocumentoIdentidad'] ?? 0) !== 1 || blank($clean['complemento'] ?? null)) {
            unset($clean['complemento']);
        }

        if (blank($clean['departamento'] ?? null)) {
            unset($clean['departamento']);
        }

        return $clean;
    }

    public function emitir(Request $request)
    {
        DB::beginTransaction();

        try {
            $validated = $this->sufeValidator->validateIndividualPayload($request->all());
            $this->assertFacturaVentaSector($validated);

            $cliente = $this->syncCliente($validated);
            $venta = $this->createVenta($validated, $cliente);
            $this->createDetalleVentas($venta, $validated);
            $requestPayload = $this->sanitizePayloadForAgetic($validated);

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
                DB::commit();

                return response()->json($payload, $response->status());
            }

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

            return response()->json($payload, $response->status());
        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'La solicitud de factura de venta no cumple la validacion del protocolo SEFE.',
                'errors' => $e->errors(),
            ], 422);
        } catch (RequestException $e) {
            DB::rollBack();

            return response()->json($e->response?->json() ?? [
                'message' => 'Error al emitir la factura de venta.',
                'details' => $e->getMessage(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'No se pudo conectar con el servicio SEFE.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('FacturaVentaApi emitir unexpected error', ['msg' => $e->getMessage()]);

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

        if (in_array($tipo, ['CO', 'CUF'], true)) {
            $url .= '?tipo=' . $tipo;
        }

        try {
            $response = $this->ageticClient()->get($url);
            $payload = $response->json();

            if ($response->successful()) {
                $this->sufeValidator->validateConsultaFacturaResponse($payload ?? []);

                return response()->json($payload, 200);
            }

            return response()->json($payload ?? [
                'message' => 'Error al consultar la factura.',
            ], $response->status());
        } catch (RequestException $e) {
            return response()->json($e->response?->json() ?? [
                'message' => 'Error al consultar la factura.',
                'details' => $e->getMessage(),
            ], $e->response?->status() ?? 502);
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'No se pudo conectar con el servicio SEFE.',
                'details' => $e->getMessage(),
            ], 504);
        } catch (\Throwable $e) {
            Log::error('FacturaVentaApi consultar unexpected error', ['msg' => $e->getMessage()]);

            return response()->json([
                'message' => 'Error inesperado al consultar la factura.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function pdf(string $codigoSeguimiento)
    {
        try {
            $notificacion = Notificacione::query()
                ->where('codigo_seguimiento', $codigoSeguimiento)
                ->latest('id')
                ->first();

            if (!$notificacion) {
                return response()->json([
                    'message' => 'Aun no existe una notificacion asociada a la factura.',
                ], 404);
            }

            $detalle = json_decode((string) $notificacion->detalle, true) ?: [];
            $urlPdf = $detalle['urlPdf'] ?? null;
            $cuf = $detalle['cuf'] ?? null;

            if ($urlPdf) {
                return response()->json([
                    'pdf_url' => $urlPdf,
                    'cuf' => $cuf,
                ]);
            }

            return response()->json([
                'message' => 'La factura aun no tiene PDF disponible.',
                'cuf' => $cuf,
            ], 404);
        } catch (\Throwable $e) {
            Log::error('FacturaVentaApi pdf unexpected error', ['msg' => $e->getMessage()]);

            return response()->json([
                'message' => 'Error inesperado al obtener el PDF de la factura.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
