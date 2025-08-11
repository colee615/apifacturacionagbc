<?php

namespace App\Http\Controllers;

use App\Models\Venta;
use App\Models\DetalleVenta;
use App\Models\Notificacione;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Carbon\Carbon;

class VentaController extends Controller
{
    // =========================
    //  Cliente AGETIC
    // =========================
    private function ageticBaseUrl(): string
    {
        return rtrim(config('services.agetic.base_url', 'https://sefe.demo.agetic.gob.bo'), '/');
    }

   private function ageticClient()
{
    $token  = config('services.agetic.token');

    return Http::withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Expect'        => '',          // evita 100-continue
            'Connection'    => 'close',     // evita keep-alive problemático
        ])
        ->withOptions([
            'force_ip_resolve' => 'v4',
            'verify'           => '/etc/ssl/certs/roni_2025.crt', // usa CA del sistema
            'version'          => 1.1, // guzzle: HTTP/1.1
            'curl' => [
                CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,     // fuerza HTTP/1.1
                CURLOPT_SSLVERSION      => CURL_SSLVERSION_TLSv1_2,   // fuerza TLS 1.2
                // baja nivel de seguridad por si el server usa suites antiguas (temporal):
                CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                CURLOPT_TCP_KEEPALIVE   => 1,
                CURLOPT_TCP_KEEPIDLE    => 30,
            ],
        ])
        ->connectTimeout(15)
        ->timeout(45)
        ->retry(2, 700, fn($e) => $e instanceof \Illuminate\Http\Client\ConnectionException);
}



    // =========================
    //  codigoOrden desde ID
    // =========================
    private function codigoOrdenFromId(int $id): string
    {
        return 'venta-' . str_pad((string) $id, 8, '0', STR_PAD_LEFT);
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

    // =========================
    //  Crear + Emitir factura
    // =========================
    public function store(Request $request)
    {
        DB::beginTransaction();

        try {
            // 1) Crear y guardar para obtener ID
            $venta = new Venta();
            $venta->cliente_id = $request->cliente_id;
            $venta->cajero_id = $request->cajero_id;
            $venta->codigoSucursal = $request->codigoSucursal;
            $venta->puntoVenta = $request->puntoVenta;
            $venta->documentoSector = $request->documentoSector;
            $venta->municipio = $request->municipio;
            $venta->departamento = $request->departamento;
            $venta->telefono = $request->telefono;
            $venta->metodoPago = $request->metodoPago;
            $venta->formatoFactura = $request->formatoFactura;
            $venta->monto_descuento_adicional = $request->monto_descuento_adicional;
            $venta->motivo = $request->motivo;
            $venta->total = $request->total;
            $venta->save(); // ahora tenemos $venta->id

            // 2) Asignar codigoOrden único basado en ID
            $venta->codigoOrden = $this->codigoOrdenFromId($venta->id);
            $venta->save();

            // 3) Guardar detalles
            $detalleVentaList = [];
            foreach ($request->carrito as $item) {
                $detalleVenta = new DetalleVenta();
                $detalleVenta->venta_id = $venta->id;
                $detalleVenta->servicio_id = $item['servicio_id'];
                $detalleVenta->cantidad = $item['cantidad'];
                $detalleVenta->precio = $item['precio'];
                $detalleVenta->codigosEspeciales = $item['codigoEspecial'] ?? null;
                $detalleVenta->informacionesAdicionales = $item['informacionAdicional'] ?? null;
                $detalleVenta->save();

                $precioUnitario = (float) $item['precio'];

                $detalleVentaList[] = [
                    'actividadEconomica' => $item['actividadEconomica'],
                    'codigoSin'          => $item['codigoSin'],
                    'codigo'             => $item['codigo'],
                    'descripcion'        => $item['descripcion'],
                    'precioUnitario'     => $precioUnitario,
                    'cantidad'           => $item['cantidad'],
                    'unidadMedida'       => $item['unidadMedida'],
                ];
            }

            // 4) Payload AGETIC
            $facturaData = [
                'codigoOrden'            => $venta->codigoOrden,
                'codigoSucursal'         => $request->codigoSucursal,
                'puntoVenta'             => $request->puntoVenta,
                'documentoSector'        => $request->documentoSector,
                'municipio'              => $request->municipio,
                'departamento'           => $request->departamento,
                'telefono'               => $request->telefono,
                'razonSocial'            => $venta->cliente->razonSocial,
                'documentoIdentidad'     => $venta->cliente->documentoIdentidad,
                'tipoDocumentoIdentidad' => $venta->cliente->tipoDocumentoIdentidad,
                'complemento'            => $venta->cliente->complemento,
                'correo'                 => $venta->cliente->correo,
                'codigoCliente'          => $venta->cliente->codigoCliente,
                'metodoPago'             => $request->metodoPago,
                'montoTotal'             => $request->total,
                'formatoFactura'         => $request->formatoFactura,
                'detalle'                => $detalleVentaList,
            ];

            Log::info('Datos enviados para emitir factura:', $facturaData);

            $result = $this->emitirFactura($facturaData);

            if (!$result['ok']) {
                if (($result['status'] ?? 0) === 0) {
                    Log::error('AGETIC emitirFactura fallo de conexión/timeout', ['result' => $result]);
                    throw new \RuntimeException('No se pudo conectar con AGETIC: ' . ($result['error'] ?? 'sin detalle'));
                }

                $body    = $result['body'] ?? [];
                $mensaje = $body['mensaje'] ?? 'Error en emisión';
                $errores = $body['datos']['errores'] ?? null;

                if (is_array($errores) && collect($errores)->contains(fn($e) =>
                    stripos($e, 'ya ha sido emitida') !== false || stripos($e, 'PROCESADO') !== false
                )) {
                    Log::warning('AGETIC: codigoOrden ya procesado', ['codigoOrden' => $venta->codigoOrden, 'respuesta' => $body]);
                    throw new \RuntimeException('Esta orden ya fue emitida previamente. Use un nuevo código de orden.');
                }

                Log::error('AGETIC emitirFactura error HTTP', ['status' => $result['status'], 'body' => $body]);
                throw new \RuntimeException($mensaje);
            }

            $body = $result['body'] ?? [];
            if (($body['finalizado'] ?? false) !== true) {
                Log::error('AGETIC emisión no finalizada', ['respuesta' => $body]);
                throw new \RuntimeException('Emisión no finalizada: ' . ($body['mensaje'] ?? 'sin mensaje'));
            }

            $venta->codigoSeguimiento = data_get($body, 'datos.codigoSeguimiento');
            $venta->save();

            DB::commit();

            return response()->json([
                'message'           => 'Venta guardada y factura emitida correctamente',
                'codigoSeguimiento' => data_get($body, 'datos.codigoSeguimiento'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al guardar venta o emitir factura:', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al guardar venta o emitir factura',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    // =========================
    //  Variante venta2
    // =========================
    public function venta2(Request $request)
    {
        DB::beginTransaction();

        try {
            // 1) Guardar para obtener ID
            $venta = new Venta();
            $venta->cliente_id = $request->cliente_id;
            $venta->cajero_id = $request->cajero_id;
            $venta->codigoSucursal = $request->codigoSucursal;
            $venta->puntoVenta = $request->puntoVenta;
            $venta->documentoSector = $request->documentoSector;
            $venta->municipio = $request->municipio;
            $venta->departamento = $request->departamento;
            $venta->telefono = $request->telefono;
            $venta->metodoPago = $request->metodoPago;
            $venta->formatoFactura = $request->formatoFactura;
            $venta->monto_descuento_adicional = $request->monto_descuento_adicional;
            $venta->motivo = $request->motivo;
            $venta->total = $request->total;
            $venta->save();

            // 2) Asignar codigoOrden único
            $venta->codigoOrden = $this->codigoOrdenFromId($venta->id);
            $venta->save();

            // 3) Detalles
            $detalleVentaList = [];
            foreach ($request->carrito as $item) {
                $detalleVenta = new DetalleVenta();
                $detalleVenta->venta_id = $venta->id;
                $detalleVenta->servicio_id = $item['servicio_id'];
                $detalleVenta->cantidad = $item['cantidad'];
                $detalleVenta->precio = $item['precio'];
                $detalleVenta->codigosEspeciales = $item['codigoEspecial'] ?? null;
                $detalleVenta->informacionesAdicionales = $item['informacionAdicional'] ?? null;
                $detalleVenta->save();

                $precioUnitario = (float) $item['precio'];

                $detalleVentaList[] = [
                    'actividadEconomica' => $item['actividadEconomica'],
                    'codigoSin'          => $item['codigoSin'],
                    'codigo'             => $item['codigo'],
                    'descripcion'        => $item['descripcion'],
                    'precioUnitario'     => $precioUnitario,
                    'cantidad'           => $item['cantidad'],
                    'unidadMedida'       => $item['unidadMedida'],
                ];
            }

            // 4) Payload
            $facturaData = [
                'codigoOrden'     => $venta->codigoOrden,
                'correo'          => $request->input('correo', 'correo-generico@example.com'),
                'telefono'        => $request->telefono,
                'municipio'       => $request->municipio,
                'metodoPago'      => $request->metodoPago,
                'montoTotal'      => $request->total,
                'puntoVenta'      => $request->puntoVenta,
                'codigoSucursal'  => $request->codigoSucursal,
                'departamento'    => $request->departamento,
                'formatoFactura'  => $request->formatoFactura,
                'documentoSector' => $request->documentoSector,
                'detalle'         => $detalleVentaList,
            ];

            Log::info('Datos enviados para emitir factura:', $facturaData);

            $result = $this->emitirFactura2($facturaData);

            if (!$result['ok']) {
                if (($result['status'] ?? 0) === 0) {
                    $venta->delete();
                    return response()->json([
                        'message' => 'Error al emitir factura (conexión/timeout)',
                        'details' => $result['error'],
                    ], 400);
                }

                $body    = $result['body'] ?? [];
                $mensaje = $body['mensaje'] ?? 'Error en emisión';
                $errores = $body['datos']['errores'] ?? null;

                if (is_array($errores) && collect($errores)->contains(fn($e) =>
                    stripos($e, 'ya ha sido emitida') !== false || stripos($e, 'PROCESADO') !== false
                )) {
                    $venta->delete();
                    return response()->json([
                        'message' => 'Esta orden ya fue emitida previamente. Use un nuevo código de orden.',
                        'details' => $mensaje,
                        'errores' => $errores,
                    ], 409);
                }

                $venta->delete();
                return response()->json([
                    'message' => 'Error al emitir factura',
                    'details' => $mensaje,
                    'errores' => $errores,
                ], 400);
            }

            $body = $result['body'] ?? [];
            if (($body['finalizado'] ?? false) !== true) {
                $venta->delete();
                return response()->json([
                    'message' => 'Error al emitir factura',
                    'details' => $body['mensaje'] ?? 'No finalizado',
                    'errores' => data_get($body, 'datos.errores'),
                ], 400);
            }

            $venta->codigoSeguimiento = data_get($body, 'datos.codigoSeguimiento');
            $venta->save();

            DB::commit();

            return response()->json([
                'message'           => 'Venta guardada y factura emitida correctamente',
                'codigoSeguimiento' => data_get($body, 'datos.codigoSeguimiento'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al guardar venta o emitir factura:', ['message' => $e->getMessage()]);
            return response()->json([
                'message' => 'Error al guardar venta o emitir factura',
                'details' => $e->getMessage(),
            ], 400);
        }
    }

    // =========================
    //  Mostrar
    // =========================
    public function show(Venta $venta)
    {
        $venta->cajero;
        $venta->cliente;
        $venta->detalleVentas->load('servicio');
        $venta->fecha = $venta->created_at->format('Y-m-d');
        return $venta;
    }

    // =========================
    //  Actualizar
    // =========================
    public function update(Request $request, Venta $venta)
    {
        $venta->total = $request->total;
        $venta->pago = $request->pago;
        $venta->cambio = $request->cambio;
        $venta->tipo = $request->tipo;
        $venta->cliente_id = $request->cliente_id;
        $venta->motivo = $request->motivo;
        $venta->estado = $request->estado;
        $venta->save();

        return response()->json($venta);
    }

    // =========================
    //  Eliminar (lógico)
    // =========================
    public function destroy(Venta $venta)
    {
        $venta->estado = 0;
        $venta->save();
        return response()->json(['message' => 'Venta eliminada correctamente']);
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

    public function emitirFactura2(array $data): array
    {
        $url = $this->ageticBaseUrl() . '/facturacion/emision/individual';

        $requestData = [
            'codigoOrden'     => $data['codigoOrden'],
            'correo'          => $data['correo'],
            'telefono'        => $data['telefono'],
            'municipio'       => $data['municipio'],
            'metodoPago'      => $data['metodoPago'],
            'montoTotal'      => $data['montoTotal'],
            'puntoVenta'      => $data['puntoVenta'],
            'codigoSucursal'  => $data['codigoSucursal'],
            'departamento'    => $data['departamento'],
            'formatoFactura'  => $data['formatoFactura'],
            'documentoSector' => $data['documentoSector'],
            'detalle'         => $data['detalle'],
        ];

        if (($data['formatoFactura'] ?? null) === 'rollo') {
            $requestData['anchoFactura'] = 75;
        }
        if (!empty($data['complemento'])) {
            $requestData['complemento'] = $data['complemento'];
        }

        Log::info('AGETIC emitirFactura2 request', $requestData);

        try {
            $resp = $this->ageticClient()->post($url, $requestData);
            $resp->throw();

            $json = $resp->json();
            Log::info('AGETIC emitirFactura2 response', $json ?? []);

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
            Log::error('AGETIC emitirFactura2 connection error', ['msg' => $e->getMessage()]);
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => null,
                'error'  => $e->getMessage(),
            ];
        } catch (\Throwable $e) {
            Log::error('AGETIC emitirFactura2 unexpected error', ['msg' => $e->getMessage()]);
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => null,
                'error'  => $e->getMessage(),
            ];
        }
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
    //  Consultar emisión
    // =========================
    public function consultarVenta($codigoSeguimiento)
    {
        $url = $this->ageticBaseUrl() . "/consulta/{$codigoSeguimiento}";
        Log::info("Código de Seguimiento: {$codigoSeguimiento}");
        Log::info("URL de Consulta: {$url}");

        try {
            $response = $this->ageticClient()->get($url);

            if ($response->successful()) {
                Log::info('Respuesta de la API:', $response->json());
                return response()->json($response->json(), 200);
            } else {
                Log::error("Error al consultar venta: " . $response->body());
                return response()->json([
                    'error'   => 'Error al consultar la venta',
                    'details' => $response->body(),
                ], $response->status());
            }
        } catch (\Throwable $e) {
            Log::error("Excepción al consultar venta: " . $e->getMessage());
            return response()->json([
                'error'     => 'Error al consultar la venta',
                'exception' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================
    //  Anular factura
    // =========================
    public function anularFactura(Request $request, $cuf)
    {
        $url = $this->ageticBaseUrl() . "/anulacion/{$cuf}";
        $requestData = [
            'motivo'        => $request->motivo,
            'tipoAnulacion' => $request->tipoAnulacion,
        ];
        Log::info('Datos enviados para anulación de factura:', $requestData);

        try {
            $response = $this->ageticClient()->patch($url, $requestData);

            Log::info('Respuesta de la API de anulación:', $response->json());

            if ($response->successful()) {
                return response()->json([
                    'message'  => 'Factura anulada correctamente',
                    'response' => $response->json(),
                ], 200);
            } else {
                return response()->json([
                    'error'   => 'Error al anular la factura',
                    'details' => $response->json(),
                ], $response->status());
            }
        } catch (\Throwable $e) {
            Log::error('Excepción al anular factura: ' . $e->getMessage());
            return response()->json([
                'error'     => 'Error al anular la factura',
                'exception' => $e->getMessage(),
            ], 500);
        }
    }

    // =========================
    //  Reportes
    // =========================
    public function ventasDelDia($cajeroId)
    {
        $ventas = Venta::where('cajero_id', $cajeroId)
            ->whereDate('created_at', Carbon::today())
            ->with(['detalleVentas', 'cliente', 'cajero'])
            ->get();

        $total = $ventas->sum('total');

        $servicios = DetalleVenta::select('servicio_id', DB::raw('count(*) as total'))
            ->whereHas('venta', function ($query) use ($cajeroId) {
                $query->where('cajero_id', $cajeroId)
                    ->whereDate('created_at', Carbon::today());
            })
            ->groupBy('servicio_id')
            ->orderBy('total', 'desc')
            ->get();

        return response()->json([
            'ventas'    => $ventas,
            'total'     => $total,
            'servicios' => $servicios,
        ]);
    }

    public function ventasDelMes($cajeroId)
    {
        $ventas = Venta::where('cajero_id', $cajeroId)
            ->whereMonth('created_at', Carbon::now()->month)
            ->whereYear('created_at', Carbon::now()->year)
            ->with(['detalleVentas', 'cliente', 'cajero'])
            ->get();

        $total = $ventas->sum('total');

        $servicios = DetalleVenta::select('servicio_id', DB::raw('count(*) as total'))
            ->whereHas('venta', function ($query) use ($cajeroId) {
                $query->where('cajero_id', $cajeroId)
                    ->whereMonth('created_at', Carbon::now()->month)
                    ->whereYear('created_at', Carbon::now()->year);
            })
            ->groupBy('servicio_id')
            ->orderBy('total', 'desc')
            ->get();

        return response()->json([
            'ventas'    => $ventas,
            'total'     => $total,
            'servicios' => $servicios,
        ]);
    }

    public function ventasPorFecha($cajeroId, Request $request)
    {
        $fecha = $request->input('fecha');

        $ventas = Venta::where('cajero_id', $cajeroId)
            ->whereDate('created_at', $fecha)
            ->with(['detalleVentas', 'cliente', 'cajero'])
            ->get();

        $total = $ventas->sum('total');

        $servicios = DetalleVenta::select('servicio_id', DB::raw('count(*) as total'))
            ->whereHas('venta', function ($query) use ($cajeroId, $fecha) {
                $query->where('cajero_id', $cajeroId)
                    ->whereDate('created_at', $fecha);
            })
            ->groupBy('servicio_id')
            ->orderBy('total', 'desc')
            ->get();

        return response()->json([
            'ventas'    => $ventas,
            'total'     => $total,
            'servicios' => $servicios,
        ]);
    }
}
