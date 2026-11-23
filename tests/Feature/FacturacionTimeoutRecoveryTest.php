<?php

namespace Tests\Feature;

use App\Http\Controllers\FacturacionCartIntegrationController;
use App\Http\Controllers\NotificacioneController;
use App\Http\Controllers\QhantuyQrController;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacturacionTimeoutRecoveryTest extends TestCase
{
    private const ORDER = 'VFC-0000014182';
    private const PACKAGE = 'EN000007757LPZ';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'recovery_test',
            'database.connections.recovery_test' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ],
            'cache.default' => 'array',
            'services.agetic.base_url' => 'https://sefe.test',
            'services.agetic.token' => 'test-token',
            'services.qhantuy_checkout.check_payments_url' => 'https://qhantuy.test/check-payments',
            'services.qhantuy_checkout.token' => 'test-token',
            'services.qhantuy_checkout.appkey' => 'test-appkey',
        ]);
        $db = DB::connection();
        $db->getPdo()->sqliteCreateFunction('btrim', 'trim', 1);
        $db->setQueryGrammar(new class extends SQLiteGrammar {
            protected function whereRaw(Builder $query, $where)
            {
                // Preserve the existing PostgreSQL check for numeric legacy cart IDs.
                if ($where['sql'] === "origen_venta_id ~ '^[0-9]+$'") {
                    return "origen_venta_id <> '' and origen_venta_id not glob '*[^0-9]*'";
                }

                return parent::whereRaw($query, $where);
            }
        });
        Http::preventStrayRequests();

        foreach ([
            '2024_06_22_021638_create_notificaciones_table.php',
            '2024_06_24_161614_create_ventas_table.php',
            '2024_06_24_161622_create_detalle_ventas_table.php',
            '2026_04_05_003000_add_protocol_tracking_columns_to_ventas_table.php',
            '2026_04_13_120000_add_origin_reference_columns_to_ventas_table.php',
            '2026_04_16_210000_create_facturacion_carts_table.php',
            '2026_04_16_210100_create_facturacion_cart_items_table.php',
            '2026_04_18_101000_add_origin_alias_carnet_to_facturacion_carts_table.php',
            '2026_04_18_101100_add_origin_alias_carnet_to_ventas_table.php',
            '2026_04_18_103500_add_numero_factura_to_ventas_table.php',
            '2026_04_21_120000_create_cajas_diarias_table.php',
            '2026_06_01_123000_add_correo_facturacion_to_facturacion_carts_table.php',
            '2026_06_01_130000_create_qhantuy_qr_payments_table.php',
            '2026_06_01_140000_add_payment_state_to_facturacion_carts_table.php',
            '2026_06_19_120000_add_qr_and_fiscal_tracking_to_facturacion_carts_table.php',
            '2026_07_27_180000_add_contrato_cxc_fields_to_facturacion_carts_and_ventas.php',
            '2026_10_27_121000_add_numero_factura_to_facturacion_carts.php',
            '2026_11_18_000001_add_t2p_payment_method_to_qhantuy_checkouts.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }
    }

    public static function recoveryTriggers(): array
    {
        return [
            'same-order consultation' => [false],
            'SEFE notification' => [true],
        ];
    }

    #[DataProvider('recoveryTriggers')]
    public function test_timeout_is_reconciled_by_the_original_order_without_emitting_twice(bool $fromNotification): void
    {
        $cartId = DB::table('facturacion_carts')->insertGetId([
            'origen_usuario_id' => 'operador-test',
            'origen_sucursal_id' => '0',
            'origen_sucursal_codigo' => '0',
            'tipo_documento' => '1',
            'numero_documento' => '12345',
            'razon_social' => 'CLIENTE DE PRUEBA',
            'codigo_orden' => self::ORDER,
            'total' => 21,
            'created_at' => now()->subMinute(),
        ]);
        DB::table('facturacion_cart_items')->insert([
            'cart_id' => $cartId,
            'origen_tipo' => 'PaqueteEms',
            'origen_id' => 1,
            'codigo' => self::PACKAGE,
            'titulo' => 'EMS',
            'total_linea' => 21,
            'resumen_origen' => json_encode([
                'actividad_economica' => '841121', 'codigo_sin' => '99100', 'codigo_producto' => 'EMS-01',
                'descripcion_servicio' => 'SERVICIO EMS', 'unidad_medida' => 58,
            ]),
        ]);
        DB::table('cajas_diarias')->insert(['usuario_id' => 'operador-test', 'fecha_operacion' => now()->toDateString()]);

        $posts = $lookups = 0;
        $invoice = $this->confirmedInvoice();
        Http::fake(function (OutgoingRequest $request) use (&$posts, &$lookups, &$invoice, $fromNotification) {
            if ($request->method() === 'POST') {
                $posts++;
                $this->assertSame(self::ORDER, $request['codigoOrden']);
                throw new ConnectionException('simulated SEFE timeout');
            }

            $lookups++;
            $identifier = $fromNotification && $lookups === 3 ? $invoice['codFactura'] : self::ORDER.'?tipo=CO';
            $this->assertSame('https://sefe.test/consulta/'.$identifier, $request->url());
            return $lookups === 1
                ? Http::response(['estado' => 'PENDIENTE'], 202)
                : Http::response($invoice);
        });

        $controller = app(FacturacionCartIntegrationController::class);
        $request = fn () => Request::create('/', 'POST', ['origen_usuario_id' => 'operador-test', 'cart_id' => $cartId]);

        $first = $controller->emitir($request());
        $this->assertSame('EMISION_INCIERTA', $first->getData(true)['cart']['estado_emision'] ?? $first->getContent());
        $this->assertSame(1, $posts);
        $this->assertDatabaseCount('ventas', 0);

        $this->travel(25)->seconds();
        $invoice['detalleFactura']['detalle'][0]['codigoProducto'] = 'OTRO-PAQUETE';
        $this->assertSame('EMISION_INCIERTA', $controller->consultar($request())->getData(true)['cart']['estado_emision']);
        $this->assertDatabaseCount('ventas', 0);

        $this->travel(25)->seconds();
        $invoice['detalleFactura']['detalle'][0]['codigoProducto'] = self::PACKAGE;
        if ($fromNotification) {
            $tracking = $invoice['codFactura'];
            $notice = Request::create('/', 'POST', [
                'estado' => 'EXITO', 'finalizado' => true, 'fuente' => 'SUFE', 'codigoSeguimiento' => $tracking,
                'fecha' => now()->format('d/m/Y h:i:s A'), 'mensaje' => 'Factura procesada',
                'detalle' => [
                    'tipoEmision' => 'EMISION', 'nit' => '12345', 'cuf' => $invoice['cuf'], 'nroFactura' => '6907',
                    'urlPdf' => 'https://sefe.test/factura.pdf', 'urlXml' => 'https://sefe.test/factura.xml',
                ],
            ]);
            $response = app(NotificacioneController::class)->procesarNotificacion($notice, $tracking);
            $this->assertSame(200, $response->getStatusCode());
            $this->app->terminate();
        } else {
            $confirmed = $controller->emitir($request())->getData(true);
            $this->assertSame('FACTURADA', $confirmed['cart']['estado_emision']);
            $this->assertSame('6907', (string) $confirmed['respuesta']['factura']['nroFactura']);
        }

        $this->assertFalse($controller->recoverUncertainFiscalEmission($cartId, $invoice));
        $this->assertSame(1, $posts);
        $this->assertSame(3, $lookups);
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseHas('ventas', [
            'codigoOrden' => self::ORDER, 'origen_venta_id' => (string) $cartId,
            'numero_factura' => '6907', 'estado_sufe' => 'PROCESADA',
        ]);
        $this->assertDatabaseHas('detalle_ventas', ['codigo' => self::PACKAGE]);
        $this->assertDatabaseHas('facturacion_carts', ['id' => $cartId, 'estado_emision' => 'FACTURADA', 'numero_factura' => '6907']);
        $this->assertDatabaseHas('cajas_diarias', ['cantidad_ventas' => 1, 'monto_ventas' => 21]);
    }

    public function test_qhantuy_rate_limit_preserves_original_transaction_and_rejects_another_reference(): void
    {
        $order = 'VQC-0000013495';
        DB::table('qhantuy_qr_payments')->insert([
            'internal_code' => $order, 'transaction_id' => 4955881,
            'checkout_amount' => 21, 'payment_method' => 'T2P',
        ]);
        Http::fake([
            'https://qhantuy.test/*' => Http::response([], 429, ['Retry-After' => '45']),
        ]);

        $controller = app(QhantuyQrController::class);
        $consult = fn (int $transaction) => $controller->checkPayments(Request::create('/', 'POST', [
            'internal_code' => $order, 'payment_ids' => [$transaction],
        ]));
        $limited = $consult(4955881);
        $this->assertSame(429, $limited->getStatusCode());
        $this->assertSame('45', $limited->headers->get('Retry-After'));
        $this->assertSame(429, $consult(4955881)->getStatusCode());
        $this->assertSame(409, $consult(4955929)->getStatusCode());
        Http::assertSentCount(1);
        $this->assertDatabaseHas('qhantuy_qr_payments', [
            'internal_code' => $order, 'transaction_id' => 4955881, 'payment_status' => 'holding',
        ]);
    }

    private function confirmedInvoice(): array
    {
        return [
            'estado' => 'PROCESADO', 'codigoOrden' => self::ORDER, 'codFactura' => '94446819924732',
            'nroFactura' => 6907, 'cuf' => 'CUF-TEST-6907',
            'detalleFactura' => [
                'cabecera' => [
                    'numeroFactura' => 6907, 'cuf' => 'CUF-TEST-6907', 'fechaEmision' => now()->toIso8601String(),
                    'codigoSucursal' => 0, 'codigoPuntoVenta' => 0, 'codigoMetodoPago' => 1,
                    'codigoTipoDocumentoIdentidad' => 1, 'numeroDocumento' => '12345', 'montoTotal' => 21,
                ],
                'detalle' => [[
                    'codigoProducto' => self::PACKAGE, 'codigoProductoSin' => '99100',
                    'unidadMedida' => 58, 'cantidad' => 1, 'precioUnitario' => 21,
                ]],
            ],
        ];
    }
}
