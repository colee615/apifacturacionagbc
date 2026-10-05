<?php

namespace Tests\Unit;

use App\Http\Controllers\VentaController;
use App\Support\SufeSectorUnoValidator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class BranchReportSalesTotalsTest extends TestCase
{
    public function test_branch_totals_match_the_control_de_cierre_classification_rules(): void
    {
        $controller = new VentaController(new SufeSectorUnoValidator());
        $aggregate = new ReflectionMethod($controller, 'aggregateBranchSalesPayloads');

        $totals = $aggregate->invoke($controller, [
            $this->sale([
                'codigoOrden' => 'VQC-QR-1',
                'metodo_pago' => 'qr',
                'estado_pago' => 'pagado',
                'estado_emision' => 'FACTURADA',
                'status' => ['key' => 'FACTURADA', 'label' => 'Facturada'],
                'respuesta_emision' => ['factura' => ['cuf' => 'CUF-1']],
                'total' => 100,
            ]),
            $this->sale([
                'codigoOrden' => 'VQ-QR-2',
                'canal_emision' => 'qr',
                'estado_pago' => 'pagado',
                'status' => ['key' => 'QR_PAGADO', 'label' => 'QR pagado'],
                'total' => 40,
            ]),
            $this->sale([
                'codigoOrden' => 'VQ-QR-3',
                'metodo_pago' => 'qr',
                'estado_pago' => 'pagado',
                'status' => ['key' => 'ANULADA', 'label' => 'Anulada'],
                'respuesta_emision' => ['factura' => ['cuf' => 'CUF-ANULADA']],
                'total' => 200,
            ]),
            $this->sale([
                'estado_pago' => 'pagado',
                'status' => ['key' => 'PROCESADO', 'label' => 'Procesado'],
                'total' => 50,
            ]),
            $this->sale([
                'estado_pago' => 'pendiente',
                'estado_sufe' => 'PROCESADA',
                'detalle' => [['descripcion' => 'Servicio ECA']],
                'total' => 20,
            ]),
            $this->sale([
                'canal_operativo' => 'contrato',
                'estado_sufe' => 'PROCESADA',
                'detalle' => [['descripcion' => 'Servicio ECA']],
                'total' => 80,
            ]),
        ]);

        $branch = $totals['001-2'];
        $this->assertSame(150.0, $branch['totalVendido']);
        $this->assertSame(100.0, $branch['totalQrFacturado']);
        $this->assertSame(40.0, $branch['totalQrPagadoPendienteFactura']);
        $this->assertSame(50.0, $branch['totalEfectivoFacturado']);
        $this->assertSame(20.0, $branch['totalEcaFacturado']);
        $this->assertSame(80.0, $branch['totalContratosNoSumados']);
        $this->assertSame(2, $branch['facturadas']);
        $this->assertSame(1, $branch['qrFacturadas']);
        $this->assertSame(1, $branch['ecaFacturadas']);
        $this->assertSame(1, $branch['electronicasFacturadas']);
        $this->assertSame(1, $branch['contratosNoSumados']);
    }

    public function test_branch_totals_aggregate_one_million_sales_with_bounded_memory(): void
    {
        $controller = new VentaController(new SufeSectorUnoValidator());
        $aggregate = new ReflectionMethod($controller, 'aggregateBranchSalesPayloads');
        $startMemory = memory_get_usage(false);
        $startedAt = microtime(true);
        $sales = (function () {
            $sale = $this->sale([
                'estado_pago' => 'pagado',
                'status' => ['key' => 'FACTURADA', 'label' => 'Facturada'],
                'total' => 25,
            ]);

            for ($id = 1; $id <= 1_000_000; $id++) {
                yield $sale;
            }
        })();

        $totals = $aggregate->invoke($controller, $sales);
        $peakMemory = memory_get_peak_usage(false) - $startMemory;
        $elapsed = microtime(true) - $startedAt;
        $branch = $totals['001-2'];

        $this->assertSame(1_000_000, $branch['facturadas']);
        $this->assertSame(25_000_000.0, $branch['totalVendido']);
        $this->assertSame(25_000_000.0, $branch['totalEfectivoFacturado']);
        $this->assertLessThan(8 * 1024 * 1024, $peakMemory, 'La agregación de sucursales dejó de ser acotada en memoria.');

        fwrite(STDERR, sprintf(
            "\nBranch report 1M benchmark: %.2f s, %.2f MiB peak delta\n",
            $elapsed,
            $peakMemory / 1024 / 1024
        ));
    }

    private function sale(array $overrides = []): array
    {
        return array_replace_recursive([
            'codigoOrden' => 'ORD-1',
            'codigoSucursal' => 1,
            'puntoVenta' => 2,
            'sucursal' => ['codigoSucursal' => 1, 'puntoVenta' => 2],
            'total' => 0,
            'estado_pago' => '',
            'status' => ['key' => '', 'label' => ''],
            'detalle' => [],
        ], $overrides);
    }
}
