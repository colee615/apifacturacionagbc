<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CajaFichasSucursalTransferTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.facturacion_api.integration_token' => 'test-bridge-token',
        ]);
    }

    public function test_branch_stock_can_be_replenished_and_transferred_to_cashier(): void
    {
        $headers = [
            'Authorization' => 'Bearer test-bridge-token',
            'Accept' => 'application/json',
        ];

        $this->withHeaders($headers)
            ->postJson('/api/factura-venta/caja/fichas/sucursal/abastecer', [
                'codigoSucursal' => 0,
                'puntoVenta' => 0,
                'sucursalNombre' => 'LA PAZ',
                'cantidadFichas' => 100,
                'montoFichas' => 1000,
                'valorUnitarioFicha' => 10,
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'stockSucursal' => [
                    'cantidadDisponible' => 100,
                    'montoDisponible' => 1000.0,
                ],
            ]);

        $this->withHeaders($headers)
            ->postJson('/api/factura-venta/caja/fichas/asignar', [
                'origen_usuario_id' => 'cajera-1',
                'origen_usuario_nombre' => 'Cajera 1',
                'origen_usuario_email' => 'cajera1@test.com',
                'codigoSucursal' => 0,
                'puntoVenta' => 0,
                'sucursalNombre' => 'LA PAZ',
                'cantidadFichas' => 20,
                'montoFichas' => 200,
                'valorUnitarioFicha' => 10,
            ])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'stock' => [
                    'cantidadDisponible' => 20,
                    'montoDisponible' => 200.0,
                ],
                'stockSucursal' => [
                    'cantidadDisponible' => 80,
                    'montoDisponible' => 800.0,
                ],
            ]);

        $this->assertDatabaseHas('ficha_postal_sucursal_saldos', [
            'codigo_sucursal' => 0,
            'punto_venta' => 0,
            'cantidad_disponible' => 80,
            'monto_disponible' => 800,
        ]);

        $this->assertDatabaseHas('ficha_postal_saldos', [
            'usuario_id' => 'cajera-1',
            'codigo_sucursal' => 0,
            'punto_venta' => 0,
            'cantidad_disponible' => 20,
            'monto_disponible' => 200,
        ]);

        $this->assertDatabaseHas('ficha_postal_sucursal_movimientos', [
            'codigo_sucursal' => 0,
            'punto_venta' => 0,
            'tipo_movimiento' => 'TRANSFERENCIA_SALIDA',
            'cantidad_delta' => -20,
            'monto_delta' => -200,
        ]);

        $this->assertDatabaseHas('ficha_postal_movimientos', [
            'usuario_id' => 'cajera-1',
            'codigo_sucursal' => 0,
            'punto_venta' => 0,
            'tipo_movimiento' => 'TRANSFERENCIA_ENTRADA',
            'cantidad_delta' => 20,
            'monto_delta' => 200,
        ]);
    }
}
