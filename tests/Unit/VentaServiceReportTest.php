<?php

namespace Tests\Unit;

use App\Http\Controllers\VentaController;
use App\Support\SufeSectorUnoValidator;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class VentaServiceReportTest extends TestCase
{
    public function test_service_report_includes_invoice_regional_and_person_breakdowns(): void
    {
        $controller = new VentaController(new SufeSectorUnoValidator());
        $method = new ReflectionMethod($controller, 'buildServiceReportFromVentas');

        $report = $method->invoke($controller, new Collection([
            $this->venta(1, '1001', 'La Paz', 'u-1', 'Ana', 2, 10),
            $this->venta(2, '1002', 'Cochabamba', 'u-2', 'Luis', 1, 15),
        ]));

        $servicio = $report['servicios'][0];

        $this->assertSame('EMS', $servicio['servicio']);
        $this->assertSame(2, $servicio['cantidadVentas']);
        $this->assertSame(35.0, $servicio['totalMonto']);

        $this->assertSame('1001', $servicio['rows'][0]['numeroFactura']);
        $this->assertSame('Ana', $servicio['rows'][0]['usuario']['nombre']);
        $this->assertSame('LA PAZ', $servicio['rows'][0]['regional']['nombre']);
        $this->assertSame(2.0, $servicio['rows'][0]['cantidad']);

        $this->assertCount(2, $servicio['porRegionales']);
        $this->assertSame('LA PAZ', $servicio['porRegionales'][0]['regional']);
        $this->assertSame(20.0, $servicio['porRegionales'][0]['totalMonto']);
        $this->assertSame(1, $servicio['porRegionales'][0]['cantidadVentas']);

        $this->assertCount(2, $servicio['porPersonas']);
        $this->assertSame('Ana', $servicio['porPersonas'][0]['usuarioNombre']);
        $this->assertSame(20.0, $servicio['porPersonas'][0]['totalMonto']);
        $this->assertSame(1, $servicio['porPersonas'][0]['cantidadVentas']);
    }

    public function test_service_summary_aggregates_one_million_rows_with_bounded_memory(): void
    {
        $controller = new VentaController(new SufeSectorUnoValidator());
        $method = new ReflectionMethod($controller, 'buildServiceReportFromVentas');
        $startMemory = memory_get_usage(false);
        $startedAt = microtime(true);
        $ventas = (function () {
            for ($id = 1; $id <= 1_000_000; $id++) {
                yield [
                    'id' => $id,
                    'fecha' => '2026-01-01 12:00:00',
                    'codigoOrden' => 'ORD-'.$id,
                    'codigoSeguimiento' => 'SEG-'.$id,
                    'numeroFactura' => null,
                    'usuario' => ['id' => 'user-1', 'nombre' => 'Usuario de prueba'],
                    'regional' => ['nombre' => 'LA PAZ', 'codigoSucursal' => 0],
                    'sucursal' => ['codigoSucursal' => 0, 'nombre' => 'La Paz'],
                    'anulada' => false,
                    'medioPago' => 'EFECTIVO',
                    'estadoFiscal' => 'PROCESADA',
                    'estadoPago' => 'pagado',
                    'detalle' => [[
                        'id' => $id,
                        'descripcion' => 'EMS - Nacional',
                        'cantidad' => 1,
                        'precio' => 25,
                        'total_linea' => 25,
                    ]],
                ];
            }
        })();

        $report = $method->invoke($controller, $ventas, false);
        $peakMemory = memory_get_peak_usage(false) - $startMemory;
        $elapsed = microtime(true) - $startedAt;

        $this->assertSame(1_000_000, $report['resumen']['cantidadVentas']);
        $this->assertSame(1_000_000, $report['servicios'][0]['cantidadVentas']);
        $this->assertSame(1_000_000, $report['servicios'][0]['cantidadDetalles']);
        $this->assertSame(25_000_000.0, $report['resumen']['totalMonto']);
        $this->assertLessThan(48 * 1024 * 1024, $peakMemory, 'La agregación dejó de ser acotada en memoria.');

        fwrite(STDERR, sprintf(
            "\nService report 1M benchmark: %.2f s, %.2f MiB peak delta\n",
            $elapsed,
            $peakMemory / 1024 / 1024
        ));
    }

    public function test_service_detail_spool_keeps_the_json_shape_and_descending_date_order(): void
    {
        $controller = new VentaController(new SufeSectorUnoValidator());
        $newSpool = new ReflectionMethod($controller, 'newServiceReportRowSpool');
        $append = new ReflectionMethod($controller, 'appendServiceReportSpoolRow');
        $flush = new ReflectionMethod($controller, 'flushServiceReportSpool');
        $responseFactory = new ReflectionMethod($controller, 'serviceReportDetailStreamResponse');
        $spool = $newSpool->invoke($controller);

        $baseTimestamp = strtotime('2026-09-01 08:00:00');
        for ($id = 1; $id <= 5002; $id++) {
            $row = [
                'fecha' => date('Y-m-d H:i:s', $baseTimestamp + $id),
                'ventaId' => $id,
            ];
            $appendArgs = [&$spool, $row];
            $append->invokeArgs($controller, $appendArgs);
        }
        $flush->invokeArgs($controller, [&$spool]);

        $response = $responseFactory->invoke($controller, ['mes' => 9], [
            'servicio' => 'EMS',
            'cantidadVentas' => 3,
            'rows' => [],
        ], $spool);
        ob_start();
        $response->sendContent();
        $body = ob_get_clean();
        $json = json_decode($body, true);

        $this->assertSame(['mes' => 9], $json['filters']);
        $this->assertSame('EMS', $json['servicio']['servicio']);
        $this->assertCount(5002, $json['servicio']['rows']);
        $this->assertSame(5002, $json['servicio']['rows'][0]['ventaId']);
        $this->assertSame(1, $json['servicio']['rows'][5001]['ventaId']);
    }

    public function test_streaming_aggregation_keeps_distinct_sale_counts_and_exclusion_totals(): void
    {
        $controller = new VentaController(new SufeSectorUnoValidator());
        $method = new ReflectionMethod($controller, 'buildServiceReportFromVentas');
        $report = $method->invoke($controller, [
            [
                'id' => 1,
                'fecha' => '2026-09-03 08:00:00',
                'estado_sufe' => 'PROCESADA',
                'estado_pago' => 'pagado',
                'anulada' => false,
                'medioPago' => 'QR',
                'usuario' => ['id' => 1, 'nombre' => 'Ana'],
                'regional' => ['nombre' => 'LA PAZ', 'codigoSucursal' => 0],
                'sucursal' => ['codigoSucursal' => 0],
                'detalle' => [
                    ['descripcion' => 'EMS - Nacional', 'cantidad' => 1, 'precio' => 20, 'total_linea' => 20],
                    ['descripcion' => 'EMS - Nacional', 'cantidad' => 1, 'precio' => 5, 'total_linea' => 5],
                    ['descripcion' => 'Servicio ECA', 'cantidad' => 1, 'precio' => 7, 'total_linea' => 7],
                ],
            ],
            [
                'id' => 2,
                'fecha' => '2026-09-02 08:00:00',
                'estado_sufe' => 'PROCESADA',
                'estado_pago' => 'pagado',
                'anulada' => false,
                'medioPago' => 'EFECTIVO',
                'usuario' => ['id' => 2, 'nombre' => 'Luis'],
                'regional' => ['nombre' => 'COCHABAMBA', 'codigoSucursal' => 1],
                'sucursal' => ['codigoSucursal' => 1],
                'detalle' => [['descripcion' => 'EMS - Nacional', 'cantidad' => 1, 'precio' => 10, 'total_linea' => 10]],
            ],
            [
                'id' => 3,
                'fecha' => '2026-09-01 08:00:00',
                'anulada' => true,
                'estadoFiscal' => 'ANULADA',
                'medioPago' => 'QR',
                'usuario' => ['id' => 1, 'nombre' => 'Ana'],
                'regional' => ['nombre' => 'LA PAZ', 'codigoSucursal' => 0],
                'sucursal' => ['codigoSucursal' => 0],
                'detalle' => [['descripcion' => 'EMS - Nacional', 'cantidad' => 1, 'precio' => 8, 'total_linea' => 8]],
            ],
        ], false);

        $ems = collect($report['servicios'])->firstWhere('servicio', 'EMS');
        $this->assertSame(2, $ems['cantidadVentas']);
        $this->assertSame(1, $ems['cantidadVentasAnuladas']);
        $this->assertSame(4, $ems['cantidadDetalles'] + $ems['cantidadDetallesAnuladas']);
        $this->assertSame(35.0, $ems['totalMonto']);
        $this->assertSame(10.0, $ems['totalMontoVendido']);
        $this->assertSame(25.0, $ems['totalMontoNoIncluidoEnTotalVendido']);
        $this->assertSame(8.0, $ems['totalMontoAnulado']);
        $this->assertSame(2, $report['resumen']['cantidadVentas']);
        $this->assertSame(1, $report['resumen']['cantidadVentasIncluidasEnTotalVendido']);
        $this->assertSame(1, $report['resumen']['cantidadVentasNoIncluidasEnTotalVendido']);
        $this->assertSame(1, $report['resumen']['cantidadVentasAnuladas']);
    }

    private function venta(
        int $id,
        string $numeroFactura,
        string $regional,
        string $usuarioId,
        string $usuarioNombre,
        int $cantidad,
        float $precio
    ): array {
        return [
            'id' => $id,
            'fecha' => '2026-09-22 10:00:00',
            'codigoOrden' => 'ORD-'.$id,
            'codigoSeguimiento' => 'SEG-'.$id,
            'numeroFactura' => $numeroFactura,
            'usuario' => [
                'id' => $usuarioId,
                'nombre' => $usuarioNombre,
                'email' => strtolower($usuarioNombre).'@example.test',
                'alias' => null,
                'carnet' => null,
            ],
            'regional' => [
                'nombre' => mb_strtoupper($regional),
                'codigoSucursal' => $id,
            ],
            'sucursal' => [
                'id' => $id,
                'nombre' => 'Sucursal '.$regional,
                'codigoSucursal' => $id,
                'puntoVenta' => 1,
                'departamento' => $regional,
            ],
            'detalle' => [[
                'id' => $id,
                'descripcion' => 'EMS - Nacional',
                'cantidad' => $cantidad,
                'precio' => $precio,
                'total_linea' => $cantidad * $precio,
            ]],
        ];
    }
}
