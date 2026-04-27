<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FacturaVentaApiBridgeTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facturacion_api.integration_token' => 'test-bridge-token',
            'services.facturacion_api.emit_wait_seconds' => 1,
        ]);

        DB::table('cajas_diarias')->updateOrInsert(
            [
                'usuario_id' => 'operador-test',
                'fecha_operacion' => now()->toDateString(),
            ],
            [
                'usuario_nombre' => 'Operador Bolipost',
                'usuario_email' => 'operador@test.com',
                'codigo_sucursal' => 0,
                'punto_venta' => 0,
                'estado' => 'ABIERTA',
                'monto_apertura' => 0,
                'monto_ventas' => 0,
                'cantidad_ventas' => 0,
                'abierta_en' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        DB::table('ficha_postal_saldos')->updateOrInsert(
            [
                'usuario_id' => 'operador-test',
                'codigo_sucursal' => 0,
                'punto_venta' => 0,
            ],
            [
                'usuario_nombre' => 'Operador Bolipost',
                'usuario_email' => 'operador@test.com',
                'cantidad_disponible' => 100,
                'monto_disponible' => 1000,
                'valor_unitario_referencia' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function test_emitir_persists_sale_as_recepcionada_when_sefe_receives_the_request(): void
    {
        $codigoSeguimiento = '9' . random_int(1000000000000, 9999999999999);

        Http::fake([
            '*/facturacion/emision/individual' => Http::response([
                'finalizado' => true,
                'mensaje' => 'Registro recepcionado con exito!',
                'datos' => [
                    'codigoSeguimiento' => $codigoSeguimiento,
                ],
            ], 202),
            '*/consulta/*' => Http::response([
                'estado' => 'PENDIENTE',
            ], 200),
        ]);

        $codigoOrden = 'TEST-' . Str::upper(Str::random(10));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ])->postJson('/api/factura-venta/emitir', $this->validPayload($codigoOrden));

        $response->assertStatus(202)
            ->assertJson([
                'ok' => true,
                'facturada' => false,
                'estado' => 'PENDIENTE',
                'mensaje' => 'La venta fue recibida y está pendiente de confirmación.',
                'factura' => [
                    'cuf' => null,
                    'nroFactura' => null,
                    'pdfUrl' => null,
                    'xmlUrl' => null,
                ],
            ]);

        $this->assertDatabaseHas('ventas', [
            'codigoOrden' => $codigoOrden,
            'codigoSeguimiento' => $codigoSeguimiento,
            'estado_sufe' => 'RECEPCIONADA',
            'cantidad_fichas_postales' => 7,
            'monto_fichas_postales' => 70,
        ]);

        $ventaId = DB::table('ventas')->where('codigoOrden', $codigoOrden)->value('id');

        $this->assertNotNull($ventaId);
        $this->assertDatabaseHas('detalle_ventas', [
            'venta_id' => $ventaId,
            'codigo' => 'PROD-001',
        ]);
        $this->assertDatabaseHas('ficha_postal_saldos', [
            'usuario_id' => 'operador-test',
            'codigo_sucursal' => 0,
            'punto_venta' => 0,
            'cantidad_disponible' => 93,
            'monto_disponible' => 930,
        ]);
    }

    public function test_emitir_does_not_persist_sale_when_sefe_rejects_the_request(): void
    {
        Http::fake([
            '*/facturacion/emision/individual' => Http::response([
                'finalizado' => false,
                'codigo' => 400,
                'timestamp' => 1686665208,
                'mensaje' => 'La solicitud no se puede completar, existen errores de validación.',
                'datos' => [
                    'errores' => [
                        'campo: numeroTarjeta, "numeroTarjeta" es un campo obligatorio',
                    ],
                ],
            ], 400),
        ]);

        $codigoOrden = 'TEST-' . Str::upper(Str::random(10));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ])->postJson('/api/factura-venta/emitir', $this->validPayload($codigoOrden));

        $response->assertStatus(400)
            ->assertJson([
                'ok' => false,
                'facturada' => false,
                'estado' => 'RECHAZADA',
                'mensaje' => 'No se pudo emitir la factura.',
            ]);

        $this->assertDatabaseMissing('ventas', [
            'codigoOrden' => $codigoOrden,
        ]);
    }

    public function test_notification_updates_sale_to_contingencia_and_consulta_returns_bridge_state(): void
    {
        $codigoSeguimiento = '8' . random_int(1000000000000, 9999999999999);

        Http::fake([
            '*/facturacion/emision/individual' => Http::response([
                'finalizado' => true,
                'mensaje' => 'Registro recepcionado con exito!',
                'datos' => [
                    'codigoSeguimiento' => $codigoSeguimiento,
                ],
            ], 202),
            '*/consulta/*' => Http::response([
                'estado' => 'PENDIENTE',
            ], 200),
        ]);

        $codigoOrden = 'TEST-' . Str::upper(Str::random(10));

        $emitResponse = $this->withHeaders([
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ])->postJson('/api/factura-venta/emitir', $this->validPayload($codigoOrden));

        $emitResponse->assertStatus(202);

        $notificationPayload = [
            'finalizado' => true,
            'fuente' => 'SUFE',
            'estado' => 'CREADO',
            'codigoSeguimiento' => $codigoSeguimiento,
            'fecha' => '05/04/2026 12:38:32 AM',
            'mensaje' => 'SE ENCONTRARON OBSERVACIONES EN LA SOLICITUD DE EMISIÓN',
            'detalle' => [
                'tipoEmision' => 'CONTINGENCIA',
                'nit' => '355701027',
                'cuf' => '18568A91F69622DABE0158067130FAD0CB132E46C5321CE639EABAF74',
                'nroFactura' => '30',
                'urlPdf' => 'https://sefe.demo.agetic.gob.bo/public/facturas_pdf/18568A91F69622DABE0158067130FAD0CB132E46C5321CE639EABAF74.pdf',
                'urlXml' => 'https://sefe.demo.agetic.gob.bo/public/facturas_xml/18568A91F69622DABE0158067130FAD0CB132E46C5321CE639EABAF74.xml',
            ],
        ];

        $this->postJson("/notificacion/{$codigoSeguimiento}", $notificationPayload)
            ->assertOk()
            ->assertJson([
                'message' => 'Notificación recibida',
                'codigoSeguimiento' => $codigoSeguimiento,
            ]);

        $this->assertDatabaseHas('ventas', [
            'codigoOrden' => $codigoOrden,
            'codigoSeguimiento' => $codigoSeguimiento,
            'estado_sufe' => 'CONTINGENCIA_CREADA',
            'tipo_emision_sufe' => 'CONTINGENCIA',
            'cuf' => '18568A91F69622DABE0158067130FAD0CB132E46C5321CE639EABAF74',
        ]);

        Http::fake([
            '*/consulta/*' => Http::response([
                'estado' => 'PENDIENTE',
            ], 200),
        ]);

        $consultaResponse = $this->withHeaders([
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ])->getJson("/api/factura-venta/consultar/{$codigoSeguimiento}");

        $consultaResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'facturada' => false,
                'estado' => 'PENDIENTE',
                'mensaje' => 'La venta quedó pendiente por contingencia.',
                'factura' => [
                    'cuf' => '18568A91F69622DABE0158067130FAD0CB132E46C5321CE639EABAF74',
                    'nroFactura' => '30',
                    'pdfUrl' => 'https://sefe.demo.agetic.gob.bo/public/facturas_pdf/18568A91F69622DABE0158067130FAD0CB132E46C5321CE639EABAF74.pdf',
                    'xmlUrl' => 'https://sefe.demo.agetic.gob.bo/public/facturas_xml/18568A91F69622DABE0158067130FAD0CB132E46C5321CE639EABAF74.xml',
                ],
            ]);
    }

    public function test_emitir_returns_facturada_when_consulta_confirms_processed_quickly(): void
    {
        $codigoSeguimiento = '7' . random_int(1000000000000, 9999999999999);

        Http::fake([
            '*/facturacion/emision/individual' => Http::response([
                'finalizado' => true,
                'mensaje' => 'Registro recepcionado con exito!',
                'datos' => [
                    'codigoSeguimiento' => $codigoSeguimiento,
                ],
            ], 202),
            '*/consulta/*' => Http::response([
                'estado' => 'PROCESADO',
                'cuf' => 'CUF-DEMO-123',
                'nroFactura' => '45',
                'observacion' => null,
            ], 200),
        ]);

        $codigoOrden = 'TEST-' . Str::upper(Str::random(10));

        $response = $this->withHeaders([
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ])->postJson('/api/factura-venta/emitir', $this->validPayload($codigoOrden));

        $response->assertStatus(202)
            ->assertJson([
                'ok' => true,
                'facturada' => true,
                'estado' => 'FACTURADA',
                'mensaje' => 'Factura emitida correctamente.',
                'factura' => [
                    'cuf' => 'CUF-DEMO-123',
                    'nroFactura' => '45',
                    'pdfUrl' => 'https://sefe.demo.agetic.gob.bo/public/facturas_pdf/CUF-DEMO-123.pdf',
                    'xmlUrl' => 'https://sefe.demo.agetic.gob.bo/public/facturas_xml/CUF-DEMO-123.xml',
                ],
            ]);
    }

    public function test_contingencia_cafc_returns_bridge_summary_for_client(): void
    {
        Http::fake([
            '*/facturacion/contingencia' => Http::response([
                'finalizado' => true,
                'mensaje' => 'Registro recepcionado con exito!',
                'datos' => [
                    'codigoSeguimientoPaquete' => 'b85a7f8d-8bf7-4e1f-9a0c-52fbffa57e4e',
                    'detalle' => [
                        [
                            'codigoSeguimiento' => '50cfdda3-358f-4675-92cc-6f8f204af69a',
                            'nroFactura' => 1,
                            'documentoIdentidad' => '12345678',
                            'fechaEmision' => '2021-12-10',
                        ],
                    ],
                    'rechazados' => [],
                ],
            ], 202),
        ]);

        $payload = [
            'cafc' => '10187568653E',
            'fechaInicio' => '2021-12-09 10:19:27',
            'fechaFin' => '2021-12-12 10:51:50',
            'documentoSector' => 1,
            'puntoVenta' => 0,
            'codigoSucursal' => 0,
            'facturas' => [
                [
                    'codigoOrden' => 'CAFC-0001',
                    'nroFactura' => 1,
                    'fechaEmision' => '2021-12-10',
                    'municipio' => 'PANDO',
                    'telefono' => '24545452',
                    'codigoCliente' => 'usuario-321',
                    'metodoPago' => 1,
                    'tipoDocumentoIdentidad' => 1,
                    'razonSocial' => 'Paucara',
                    'documentoIdentidad' => '12345678',
                    'complemento' => '1J',
                    'correo' => 'usuario@correo.com',
                    'montoTotal' => 200.00,
                    'formatoFactura' => 'pagina',
                    'detalle' => [
                        [
                            'actividadEconomica' => '620100',
                            'codigoSin' => '99100',
                            'codigo' => 'JN-PROD001',
                            'descripcion' => 'leche condensada',
                            'precioUnitario' => 20,
                            'unidadMedida' => 10,
                            'cantidad' => 10,
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders([
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ])->postJson('/api/facturacion/contingencia', $payload);

        $response->assertStatus(202)
            ->assertJson([
                'ok' => true,
                'estado' => 'REGULARIZADA',
                'mensaje' => 'Facturas de contingencia enviadas correctamente.',
                'paquete' => [
                    'codigoSeguimientoPaquete' => 'b85a7f8d-8bf7-4e1f-9a0c-52fbffa57e4e',
                    'aceptadas' => 1,
                    'rechazadas' => 0,
                ],
            ]);
    }

    public function test_anular_sends_patch_to_sefe_and_marks_sale_as_pending_annulment(): void
    {
        $cuf = 'CUF-DEMO-ANULAR-123';
        $codigoSeguimiento = '6' . random_int(1000000000000, 9999999999999);
        $codigoOrden = 'TEST-' . Str::upper(Str::random(10));

        $this->insertProcessedVenta($codigoOrden, $codigoSeguimiento, $cuf);

        Http::fake([
            '*/anulacion/*' => Http::response([
                'finalizado' => true,
                'mensaje' => 'Registro recepcionado con exito!',
                'datos' => [
                    'cuf' => $cuf,
                ],
            ], 202),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ])->patchJson("/api/factura-venta/anular/{$cuf}", [
            'motivo' => 'DATOS ERRONEOS EN LA FACTURA',
            'tipoAnulacion' => 3,
        ]);

        $response->assertStatus(202)
            ->assertJson([
                'ok' => true,
                'estado' => 'PENDIENTE_ANULACION',
                'mensaje' => 'Anulación solicitada correctamente.',
                'factura' => [
                    'cuf' => $cuf,
                    'nroFactura' => '45',
                ],
            ]);

        Http::assertSent(function (HttpRequest $request) use ($cuf) {
            return $request->method() === 'PATCH'
                && Str::endsWith($request->url(), "/anulacion/{$cuf}")
                && $request['motivo'] === 'DATOS ERRONEOS EN LA FACTURA'
                && $request['tipoAnulacion'] === 3;
        });

        $this->assertDatabaseHas('ventas', [
            'codigoOrden' => $codigoOrden,
            'codigoSeguimiento' => $codigoSeguimiento,
            'cuf' => $cuf,
            'estado_sufe' => 'ANULACION_SOLICITADA',
            'tipo_emision_sufe' => 'ANULACION',
        ]);
    }

    public function test_anulacion_notification_updates_sale_to_anulada_and_consulta_reports_it(): void
    {
        $cuf = 'CUF-DEMO-ANULAR-456';
        $codigoSeguimiento = '5' . random_int(1000000000000, 9999999999999);
        $codigoOrden = 'TEST-' . Str::upper(Str::random(10));

        $this->insertProcessedVenta($codigoOrden, $codigoSeguimiento, $cuf);

        $notificationPayload = [
            'finalizado' => true,
            'fuente' => 'SUFE',
            'estado' => 'EXITO',
            'codigoSeguimiento' => $codigoSeguimiento,
            'fecha' => '28/07/2022 2:56:33 PM',
            'mensaje' => 'LA SOLICITUD DE EMISIÓN HA SIDO PROCESADA CORRECTAMENTE',
            'detalle' => [
                'tipoEmision' => 'ANULACION',
                'nit' => '5464514',
                'cuf' => $cuf,
                'nroFactura' => '45',
            ],
        ];

        $this->postJson("/notificacion/{$codigoSeguimiento}", $notificationPayload)
            ->assertOk()
            ->assertJson([
                'message' => 'Notificación recibida',
                'codigoSeguimiento' => $codigoSeguimiento,
            ]);

        $this->assertDatabaseHas('ventas', [
            'codigoOrden' => $codigoOrden,
            'codigoSeguimiento' => $codigoSeguimiento,
            'cuf' => $cuf,
            'estado_sufe' => 'ANULADA',
            'tipo_emision_sufe' => 'ANULACION',
            'url_pdf' => "https://sefe.demo.agetic.gob.bo/public/facturas_pdf/{$cuf}.pdf",
            'url_xml' => "https://sefe.demo.agetic.gob.bo/public/facturas_xml/{$cuf}.xml",
        ]);

        Http::fake([
            '*/consulta/*' => Http::response([
                'estado' => 'ANULADO',
                'cuf' => $cuf,
                'nroFactura' => '45',
            ], 200),
        ]);

        $consultaResponse = $this->withHeaders([
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ])->getJson("/api/factura-venta/consultar/{$codigoSeguimiento}");

        $consultaResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'facturada' => false,
                'estado' => 'ANULADA',
                'mensaje' => 'Factura anulada correctamente.',
                'factura' => [
                    'cuf' => $cuf,
                    'nroFactura' => '45',
                ],
            ]);
    }

    private function insertProcessedVenta(string $codigoOrden, string $codigoSeguimiento, string $cuf): int
    {
        return (int) DB::table('ventas')->insertGetId([
            'origen_sistema' => 'BOLIPOST',
            'codigoSucursal' => 0,
            'puntoVenta' => 0,
            'documentoSector' => 1,
            'municipio' => 'LA PAZ',
            'departamento' => 'LA PAZ',
            'telefono' => '2457000',
            'codigoCliente' => 'CLI-0001',
            'razonSocial' => 'CLIENTE DE PRUEBA',
            'documentoIdentidad' => '12345678',
            'tipoDocumentoIdentidad' => 1,
            'complemento' => '1A',
            'correo' => 'cliente@test.com',
            'metodoPago' => 1,
            'formatoFactura' => 'pagina',
            'monto_descuento_adicional' => 0,
            'motivo' => 'Integracion bolipost',
            'total' => 70,
            'estado' => 1,
            'codigoOrden' => $codigoOrden,
            'codigoSeguimiento' => $codigoSeguimiento,
            'estado_sufe' => 'PROCESADA',
            'tipo_emision_sufe' => 'EMISION',
            'cuf' => $cuf,
            'numero_factura' => '45',
            'url_pdf' => "https://sefe.demo.agetic.gob.bo/public/facturas_pdf/{$cuf}.pdf",
            'url_xml' => "https://sefe.demo.agetic.gob.bo/public/facturas_xml/{$cuf}.xml",
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function validPayload(string $codigoOrden): array
    {
        return [
            'codigoOrden' => $codigoOrden,
            'origenUsuario' => [
                'id' => 'operador-test',
                'nombre' => 'Operador Bolipost',
                'email' => 'operador@test.com',
            ],
            'codigoSucursal' => 0,
            'puntoVenta' => 0,
            'documentoSector' => 1,
            'municipio' => 'LA PAZ',
            'departamento' => 'LA PAZ',
            'telefono' => '2457000',
            'codigoCliente' => 'CLI-0001',
            'razonSocial' => 'CLIENTE DE PRUEBA',
            'documentoIdentidad' => '12345678',
            'tipoDocumentoIdentidad' => 1,
            'complemento' => '1A',
            'correo' => 'cliente@test.com',
            'metodoPago' => 1,
            'formatoFactura' => 'pagina',
            'montoTotal' => 70,
            'fichasPostales' => [
                'cantidad' => 7,
                'montoTotal' => 70,
                'valorUnitario' => 10,
            ],
            'detalle' => [
                [
                    'actividadEconomica' => '841121',
                    'codigoSin' => '99100',
                    'codigo' => 'PROD-001',
                    'descripcion' => 'SERVICIO DE PRUEBA',
                    'unidadMedida' => 58,
                    'precioUnitario' => 70,
                    'cantidad' => 1,
                ],
            ],
        ];
    }
}
