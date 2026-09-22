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
