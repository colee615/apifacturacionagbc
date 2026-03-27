<?php

namespace Tests\Feature;

use App\Support\SufeSectorUnoValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SufeSectorUnoValidatorTest extends TestCase
{
    private SufeSectorUnoValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = app(SufeSectorUnoValidator::class);
    }

    public function test_individual_sector_uno_payload_is_valid(): void
    {
        $payload = [
            'codigoOrden' => 'AGBC-0000001',
            'codigoSucursal' => 0,
            'puntoVenta' => 0,
            'documentoSector' => 1,
            'municipio' => 'LA PAZ',
            'departamento' => 'LA PAZ',
            'telefono' => '2457000',
            'razonSocial' => 'CLIENTE DE PRUEBA',
            'documentoIdentidad' => '12345678',
            'tipoDocumentoIdentidad' => 1,
            'complemento' => '1A',
            'correo' => 'cliente@test.com',
            'codigoCliente' => 'CLI-001',
            'metodoPago' => 1,
            'montoTotal' => 200,
            'montoDescuentoAdicional' => 0,
            'formatoFactura' => 'pagina',
            'detalle' => [
                [
                    'actividadEconomica' => '841121',
                    'codigoSin' => '99100',
                    'codigo' => 'SERV-001',
                    'descripcion' => 'SERVICIO DE PRUEBA',
                    'precioUnitario' => 200,
                    'cantidad' => 1,
                    'unidadMedida' => 58,
                ],
            ],
        ];

        $validated = $this->validator->validateIndividualPayload($payload);

        $this->assertSame('AGBC-0000001', $validated['codigoOrden']);
        $this->assertSame(1, $validated['documentoSector']);
    }

    public function test_individual_payload_rejects_special_document_without_nit_type(): void
    {
        $this->expectException(ValidationException::class);

        $payload = [
            'codigoOrden' => 'AGBC-0000002',
            'codigoSucursal' => 0,
            'puntoVenta' => 0,
            'documentoSector' => 1,
            'municipio' => 'EL ALTO',
            'telefono' => '2457000',
            'razonSocial' => 'CLIENTE DE PRUEBA',
            'documentoIdentidad' => '99003',
            'tipoDocumentoIdentidad' => 1,
            'correo' => 'cliente@test.com',
            'codigoCliente' => 'CLI-002',
            'metodoPago' => 1,
            'montoTotal' => 100,
            'formatoFactura' => 'pagina',
            'detalle' => [
                [
                    'actividadEconomica' => '841121',
                    'codigoSin' => '99100',
                    'codigo' => 'SERV-002',
                    'descripcion' => 'SERVICIO DE PRUEBA DOS',
                    'precioUnitario' => 100,
                    'cantidad' => 1,
                    'unidadMedida' => 58,
                ],
            ],
        ];

        $this->validator->validateIndividualPayload($payload);
    }

    public function test_anulacion_payload_is_valid(): void
    {
        $validated = $this->validator->validateAnulacionPayload([
            'motivo' => 'DATOS ERRONEOS EN LA FACTURA',
            'tipoAnulacion' => 3,
        ]);

        $this->assertSame(3, $validated['tipoAnulacion']);
    }

    public function test_notification_observada_requires_observation(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validateNotification([
            'finalizado' => true,
            'estado' => 'OBSERVADO',
            'fuente' => 'SUFE',
            'codigoSeguimiento' => '1234567890',
            'fecha' => '18/03/2026 10:10:10 AM',
            'mensaje' => 'SE ENCONTRARON OBSERVACIONES EN LA SOLICITUD DE EMISION',
            'detalle' => [
                'tipoEmision' => 'EMISION',
                'nit' => '123456',
                'cuf' => 'CUF-PRUEBA-001',
                'nroFactura' => '10',
                'codigoEstadoImpuestos' => 904,
            ],
        ], '1234567890');
    }

    public function test_contingencia_cafc_payload_validates_dates_and_totals(): void
    {
        $payload = [
            'cafc' => 'CAFC123456',
            'fechaInicio' => '2026-03-18 08:00:00',
            'fechaFin' => '2026-03-18 12:00:00',
            'documentoSector' => 1,
            'puntoVenta' => 0,
            'codigoSucursal' => 0,
            'facturas' => [
                [
                    'codigoOrden' => 'venta-manual-001',
                    'nroFactura' => 1,
                    'fechaEmision' => '2026-03-18',
                    'municipio' => 'LA PAZ',
                    'telefono' => '2457000',
                    'razonSocial' => 'CLIENTE CAFC',
                    'documentoIdentidad' => '12345678',
                    'tipoDocumentoIdentidad' => 1,
                    'complemento' => '1A',
                    'correo' => 'cliente@test.com',
                    'codigoCliente' => 'CLI-CAFC',
                    'metodoPago' => 1,
                    'montoTotal' => 50,
                    'formatoFactura' => 'pagina',
                    'detalle' => [
                        [
                            'actividadEconomica' => '841121',
                            'codigoSin' => '99100',
                            'codigo' => 'SERV-CAFC',
                            'descripcion' => 'SERVICIO CONTINGENCIA',
                            'precioUnitario' => 50,
                            'cantidad' => 1,
                            'unidadMedida' => 58,
                        ],
                    ],
                ],
            ],
        ];

        $validated = $this->validator->validateContingenciaCafcPayload($payload);

        $this->assertSame('CAFC123456', $validated['cafc']);
        $this->assertCount(1, $validated['facturas']);
    }

    public function test_massive_response_with_mixed_results_is_valid(): void
    {
        $payload = [
            'finalizado' => true,
            'mensaje' => 'Registro recepcionado con exito!',
            'datos' => [
                'codigoSeguimientoPaquete' => '1523c73a-9ca5-40e8-a191-11bb3b278dbf',
                'detalle' => [
                    [
                        'posicion' => 1,
                        'codigoSeguimiento' => '3917d6ca-55e3-4c98-93ae-684eb6071600',
                        'documentoIdentidad' => '12345678',
                    ],
                ],
                'rechazados' => [
                    [
                        'posicion' => 0,
                        'documentoIdentidad' => '12345678',
                        'observacion' => [
                            'La factura sobrepasó la fecha límite de emisión correspondiente al día 10 del siguiente mes',
                        ],
                    ],
                ],
            ],
        ];

        $validated = $this->validator->validateAcceptedMassiveResponse($payload);

        $this->assertTrue($validated['finalizado']);
        $this->assertSame('1523c73a-9ca5-40e8-a191-11bb3b278dbf', $validated['datos']['codigoSeguimientoPaquete']);
    }
}
