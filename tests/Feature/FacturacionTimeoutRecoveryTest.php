<?php

namespace Tests\Feature;

use App\Http\Controllers\FacturacionCartIntegrationController;
use App\Http\Controllers\FacturaVentaApiController;
use App\Http\Controllers\NotificacioneController;
use App\Http\Controllers\QhantuyQrController;
use App\Services\QhantuyQrCredentials;
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
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'services.facturacion_api.integration_token' => 'integration-test-token',
            'services.agetic.base_url' => 'https://sefe.test',
            'services.agetic.token' => 'test-token',
            'services.qhantuy_checkout.check_payments_url' => 'https://qhantuy.test/check-payments',
            'services.qhantuy_checkout.base_url' => 'https://qhantuy.test/checkout',
            'services.qhantuy_checkout.callback_url' => 'https://safe.test/api/qhantuy/callback',
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
            '2026_04_22_201000_create_caja_arqueos_tables.php',
            '2026_04_22_201500_add_estado_caja_to_ventas_table.php',
            '2026_04_26_120000_add_ficha_postal_control_to_cajas_ventas_and_stock_tables.php',
            '2026_06_01_123000_add_correo_facturacion_to_facturacion_carts_table.php',
            '2026_06_01_130000_create_qhantuy_qr_payments_table.php',
            '2026_06_01_140000_add_payment_state_to_facturacion_carts_table.php',
            '2026_06_19_120000_add_qr_and_fiscal_tracking_to_facturacion_carts_table.php',
            '2026_07_27_180000_add_contrato_cxc_fields_to_facturacion_carts_and_ventas.php',
            '2026_10_27_121000_add_numero_factura_to_facturacion_carts.php',
            '2026_11_13_000001_create_qhantuy_qr_cashier_assignments.php',
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
        $cartId = $this->createCart();
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
        $request = fn () => $this->cartRequest($cartId);

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
            $this->notifyFiscal($invoice['codFactura']);
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

    public function test_unhandled_failure_after_emission_claim_is_persisted_as_uncertain(): void
    {
        $cartId = $this->createCart(['estado' => 'borrador', 'estado_emision' => 'NO_APLICA']);
        $fiscal = \Mockery::mock(FacturaVentaApiController::class)->makePartial();
        $fiscal->shouldReceive('emitir')->once()->andThrow(new \RuntimeException('simulated logger failure'));
        $fiscal->shouldReceive('fetchProcessedInvoice')->once()->with(self::ORDER)->andReturn(null);
        $this->app->instance(FacturaVentaApiController::class, $fiscal);

        $response = app(FacturacionCartIntegrationController::class)->emitir($this->cartRequest($cartId));

        $this->assertSame('EMISION_INCIERTA', $response->getData(true)['cart']['estado_emision']);
        $this->assertSame(self::ORDER, $response->getData(true)['cart']['codigo_orden']);
        $this->assertDatabaseHas('facturacion_carts', [
            'id' => $cartId,
            'codigo_orden' => self::ORDER,
            'estado_emision' => 'EMISION_INCIERTA',
        ]);
        $this->assertDatabaseCount('ventas', 0);
    }

    public function test_manual_retry_links_an_invoice_found_in_sefe_without_posting_again(): void
    {
        $cartId = $this->createPaidCardCart();
        DB::table('cajas_diarias')->insert(['usuario_id' => 'operador-test', 'fecha_operacion' => now()->toDateString()]);
        $invoice = $this->confirmedInvoice();
        $invoice['detalleFactura']['cabecera']['codigoMetodoPago'] = 5;
        Http::fake(['https://sefe.test/consulta/*' => Http::response($invoice)]);

        $response = app(FacturacionCartIntegrationController::class)->consultar($this->cartRequest($cartId, [
            'auto_emit_invoice' => 1,
            'reintentar_si_no_encontrada' => 1,
        ]));

        $this->assertSame('FACTURADA', $response->getData(true)['cart']['estado_emision']);
        $this->assertSame('6907', (string) $response->getData(true)['cart']['numero_factura']);
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseHas('facturacion_carts', [
            'id' => $cartId,
            'codigo_orden' => self::ORDER,
            'estado_pago' => 'pagado',
            'qr_transaction_id' => '4988554',
        ]);
        Http::assertSentCount(1);
        Http::assertSent(fn (OutgoingRequest $request) => $request->method() === 'GET'
            && $request->url() === 'https://sefe.test/consulta/'.self::ORDER.'?tipo=CO');
        Http::assertNotSent(fn (OutgoingRequest $request) => $request->method() === 'POST');
    }

    public function test_manual_retry_posts_the_original_order_only_after_sefe_confirms_not_found(): void
    {
        $cartId = $this->createPaidCardCart();
        Http::fake(function (OutgoingRequest $request) {
            if ($request->method() === 'GET') {
                $this->assertSame('https://sefe.test/consulta/'.self::ORDER.'?tipo=CO', $request->url());
                return Http::response(['mensaje' => 'Recurso no encontrado'], 400);
            }

            $this->assertSame('https://sefe.test/facturacion/emision/individual', $request->url());
            $this->assertSame(self::ORDER, $request['codigoOrden']);
            $this->assertSame(21.0, (float) $request['montoTotal']);
            $this->assertSame(5, (int) $request['metodoPago']);

            return Http::response([
                'finalizado' => true,
                'mensaje' => 'Registro recepcionado con exito!',
                'datos' => ['codigoSeguimiento' => '94446819924732'],
            ], 202);
        });

        $response = app(FacturacionCartIntegrationController::class)->consultar($this->cartRequest($cartId, [
            'auto_emit_invoice' => 1,
            'reintentar_si_no_encontrada' => 1,
        ]));

        $this->assertSame('PENDIENTE', $response->getData(true)['cart']['estado_emision']);
        $this->assertDatabaseHas('facturacion_carts', [
            'id' => $cartId,
            'codigo_orden' => self::ORDER,
            'estado_pago' => 'pagado',
            'qr_transaction_id' => '4988554',
        ]);
        $this->assertDatabaseHas('qhantuy_qr_payments', [
            'internal_code' => self::ORDER,
            'transaction_id' => 4988554,
            'payment_status' => 'success',
        ]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), 'qhantuy.test'));
    }

    public function test_manual_retry_does_not_post_when_sefe_does_not_confirm_absence(): void
    {
        $cartId = $this->createPaidCardCart();
        Http::fake(['https://sefe.test/consulta/*' => Http::response(['estado' => 'PENDIENTE'], 202)]);

        $response = app(FacturacionCartIntegrationController::class)->consultar($this->cartRequest($cartId, [
            'auto_emit_invoice' => 1,
            'reintentar_si_no_encontrada' => 1,
        ]));

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('EMISION_INCIERTA', $response->getData(true)['cart']['estado_emision']);
        $this->assertDatabaseCount('ventas', 0);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (OutgoingRequest $request) => $request->method() === 'POST');
    }

    public function test_manual_retry_does_not_post_when_sefe_lookup_times_out(): void
    {
        $cartId = $this->createPaidCardCart();
        Http::fake(function (OutgoingRequest $request) {
            throw new ConnectionException('simulated SEFE lookup timeout');
        });

        $response = app(FacturacionCartIntegrationController::class)->consultar($this->cartRequest($cartId, [
            'auto_emit_invoice' => 1,
            'reintentar_si_no_encontrada' => 1,
        ]));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('EMISION_INCIERTA', $response->getData(true)['cart']['estado_emision']);
        $this->assertDatabaseCount('ventas', 0);
        Http::assertNotSent(fn (OutgoingRequest $request) => $request->method() === 'POST');
    }

    private function createCart(array $attributes = []): int
    {
        $cartId = DB::table('facturacion_carts')->insertGetId($attributes + [
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
            'origen_tipo' => 'App\\Models\\PaqueteEms',
            'origen_id' => 1,
            'codigo' => self::PACKAGE,
            'titulo' => 'EMS',
            'total_linea' => 21,
            'resumen_origen' => json_encode([
                'actividad_economica' => '841121', 'codigo_sin' => '99100', 'codigo_producto' => 'EMS-01',
                'descripcion_servicio' => 'SERVICIO EMS', 'unidad_medida' => 58,
            ]),
        ]);
        return $cartId;
    }

    private function createPaidCardCart(): int
    {
        $cartId = $this->createCart([
            'estado' => 'emitido',
            'canal_emision' => 'factura_electronica',
            'metodo_pago' => 'qr',
            'qhantuy_payment_method' => 'T2P',
            'estado_pago' => 'pagado',
            'qr_transaction_id' => '4988554',
            'estado_emision' => 'EMISION_INCIERTA',
            'total' => 21,
        ]);
        DB::table('qhantuy_qr_payments')->insert([
            'internal_code' => self::ORDER,
            'transaction_id' => 4988554,
            'checkout_amount' => 21,
            'checkout_currency' => 'BOB',
            'payment_status' => 'success',
            'payment_method' => 'T2P',
            'qhantuy_cart_id' => $cartId,
        ]);

        return $cartId;
    }

    private function cartRequest(int $cartId, array $params = []): Request
    {
        return Request::create('/', 'POST', $params + ['origen_usuario_id' => 'operador-test', 'cart_id' => $cartId]);
    }

    private function notifyFiscal(string $tracking): void
    {
        $notice = Request::create('/', 'POST', [
            'estado' => 'EXITO', 'finalizado' => true, 'fuente' => 'SUFE', 'codigoSeguimiento' => $tracking,
            'fecha' => now()->format('d/m/Y h:i:s A'), 'mensaje' => 'Factura procesada',
            'detalle' => [
                'tipoEmision' => 'EMISION', 'nit' => '12345', 'cuf' => 'CUF-TEST-6907', 'nroFactura' => '6907',
                'urlPdf' => 'https://sefe.test/factura.pdf', 'urlXml' => 'https://sefe.test/factura.xml',
            ],
        ]);
        $response = app(NotificacioneController::class)->procesarNotificacion($notice, $tracking);
        $this->assertSame(200, $response->getStatusCode());
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

    public function test_interrupted_cash_order_releases_the_cashier_but_keeps_the_same_package_protected(): void
    {
        $cartId = $this->createCart([
            'estado_emision' => 'EMITIENDO', 'estado_pago' => 'pagado', 'updated_at' => now()->subMinutes(16),
        ]);
        DB::table('cajas_diarias')->insert(['usuario_id' => 'operador-test', 'fecha_operacion' => now()->toDateString()]);
        Http::fake(['https://sefe.test/consulta/*' => Http::response($this->confirmedInvoice())]);

        $headers = ['Authorization' => 'Bearer integration-test-token'];
        $item = [
            'origen_usuario_id' => 'operador-test', 'origen_sucursal_id' => '0', 'origen_sucursal_codigo' => '0',
            'origen_tipo' => 'App\\Models\\PaqueteEms', 'origen_id' => 1, 'codigo' => self::PACKAGE, 'titulo' => 'EMS nacional',
            'total_linea' => 21, 'confirmar_reagregar' => true,
        ];
        $this->postJson('/api/factura-venta/cart/items/upsert', $item, $headers)->assertConflict();
        $this->postJson('/api/factura-venta/cart/items/upsert', array_replace($item, [
            'origen_id' => 2, 'codigo' => 'EN000007758LPZ',
        ]), $headers)->assertSuccessful()->assertJsonPath('cart.estado', 'borrador');
        $this->assertDatabaseHas('facturacion_carts', ['id' => $cartId, 'codigo_orden' => self::ORDER, 'estado_emision' => 'EMITIENDO']);

        $this->artisan('facturas:recuperar-inciertas')->assertSuccessful();
        $this->artisan('facturas:recuperar-inciertas')->assertSuccessful();
        $this->assertDatabaseHas('facturacion_carts', ['id' => $cartId, 'estado_emision' => 'FACTURADA', 'numero_factura' => '6907']);
        $this->assertDatabaseHas('cajas_diarias', ['monto_ventas' => 21, 'cantidad_ventas' => 1]);
        $this->assertDatabaseCount('ventas', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn (OutgoingRequest $request) => $request->method() === 'GET'
            && $request->url() === 'https://sefe.test/consulta/'.self::ORDER.'?tipo=CO');
    }

    public function test_acceptance_without_a_valid_receipt_stays_pending_and_a_confirmed_rejection_resolves_it(): void
    {
        $cartId = $this->createCart();
        DB::table('cajas_diarias')->insert(['usuario_id' => 'operador-test', 'fecha_operacion' => now()->toDateString()]);
        Http::fake([
            'https://sefe.test/facturacion/emision/individual' => Http::response(['mensaje' => 'processing'], 202),
            'https://sefe.test/consulta/*' => Http::sequence()
                ->push(['estado' => 'PENDIENTE'])
                ->push(['estado' => 'OBSERVADO', 'codigoOrden' => self::ORDER, 'codFactura' => '94446819924732']),
        ]);
        $controller = app(FacturacionCartIntegrationController::class);
        $first = $controller->emitir($this->cartRequest($cartId))->getData(true);
        $this->assertSame('EMISION_INCIERTA', $first['cart']['estado_emision']);
        $this->assertTrue($first['cart']['status']['can_consult']);

        $this->travel(25)->seconds();
        $checked = $controller->consultar($this->cartRequest($cartId))->getData(true);
        $this->assertSame('RECHAZADA', $checked['cart']['estado_emision']);
        $this->assertDatabaseCount('ventas', 0);
        Http::assertSentCount(3);
    }

    public function test_early_and_late_notifications_only_update_their_own_attempt(): void
    {
        $cartId = $this->createCart();
        $tracking = '94446819924732';
        DB::table('cajas_diarias')->insert(['usuario_id' => 'operador-test', 'fecha_operacion' => now()->toDateString()]);
        Http::fake(['https://sefe.test/facturacion/emision/individual' => function () use ($tracking) {
            $this->notifyFiscal($tracking);

            return Http::response(['finalizado' => true, 'mensaje' => 'Recepcionada', 'datos' => ['codigoSeguimiento' => $tracking]], 202);
        }]);

        $result = app(FacturacionCartIntegrationController::class)->emitir($this->cartRequest($cartId))->getData(true);
        $this->assertSame('FACTURADA', $result['cart']['estado_emision']);
        $this->assertDatabaseHas('ventas', ['numero_factura' => '6907', 'estado_sufe' => 'PROCESADA']);

        DB::table('facturacion_carts')->where('id', $cartId)->update([
            'codigo_orden' => 'VFC-0000014183', 'estado_emision' => 'PENDIENTE',
            'codigo_seguimiento' => '94446819924733', 'codigo_seguimiento_fiscal' => '94446819924733',
            'numero_factura' => null,
        ]);
        $this->notifyFiscal($tracking);
        $this->assertDatabaseHas('facturacion_carts', [
            'id' => $cartId, 'estado_emision' => 'PENDIENTE', 'codigo_seguimiento_fiscal' => '94446819924733', 'numero_factura' => null,
        ]);
        Http::assertSentCount(1);
    }

    public static function cardSettlementTriggers(): array
    {
        return ['scheduled after 429' => [false], 'provider notice' => [true]];
    }

    #[DataProvider('cardSettlementTriggers')]
    public function test_card_is_charged_once_and_invoiced_without_a_browser_or_an_open_cash_drawer(bool $fromNotification): void
    {
        $order = 'VQC-0000013495';
        $transaction = 4955881;
        $cartId = $this->createCart(['codigo_orden' => $order]);
        app(QhantuyQrCredentials::class)->assign('operador-test', '0', '0', 'cashier-test-appkey');
        $invoice = $this->confirmedInvoice();
        $invoice['codigoOrden'] = $order;
        $invoice['detalleFactura']['cabecera']['codigoMetodoPago'] = 5;
        $checkoutRequests = $paymentQueries = $fiscalPosts = 0;

        Http::fake(function (OutgoingRequest $request) use ($order, $transaction, $invoice, $fromNotification, &$checkoutRequests, &$paymentQueries, &$fiscalPosts) {
            if ($request->url() === 'https://qhantuy.test/checkout') {
                $checkoutRequests++;
                $this->assertSame($order, $request['internal_code']);
                return Http::response([
                    'process' => true, 'transaction_id' => $transaction, 'internal_code' => $order,
                    'payment_status' => 'holding', 'checkout_amount' => 21, 'checkout_currency' => 'BOB',
                ]);
            }
            if ($request->url() === 'https://qhantuy.test/check-payments') {
                $paymentQueries++;
                $this->assertSame([$transaction], $request['payment_ids']);
                if (!$fromNotification && $paymentQueries === 1) {
                    return Http::response([], 429, ['Retry-After' => '30']);
                }
                return Http::response(['process' => true, 'items' => [[
                    'id' => $transaction, 'internal_code' => $order, 'payment_status' => 'success',
                    'checkout_amount' => 21, 'checkout_currency' => 'BOB',
                ]]]);
            }
            if ($request->method() === 'POST' && $request->url() === 'https://sefe.test/facturacion/emision/individual') {
                $fiscalPosts++;
                $this->assertSame($order, $request['codigoOrden']);
                $this->assertSame(5, $request['metodoPago']);
                throw new ConnectionException('simulated SEFE timeout after card payment');
            }
            $this->assertSame('https://sefe.test/consulta/'.$order.'?tipo=CO', $request->url());
            return Http::response($invoice);
        });

        $checkout = app(FacturacionCartIntegrationController::class)->emitir($this->cartRequest($cartId, [
            'canal_emision' => 'qr', 'qr_payment_method' => 'T2P',
        ]))->getData(true);
        $this->assertSame('pendiente', $checkout['cart']['estado_pago'] ?? $checkout);
        $this->assertSame(1, $checkoutRequests);
        if ($fromNotification) {
            $callback = Request::create('/', 'GET', [
                'internal_code' => $order, 'transaction_id' => $transaction, 'status' => 'success',
                'checkout_amount' => 21, 'checkout_currency' => 'BOB', 'profile_code' => 'test-profile',
            ]);
            $this->assertSame(200, app(QhantuyQrController::class)->callback($callback)->getStatusCode());
            $this->app->terminate();
        } else {
            $this->artisan('facturas:consolidar-tarjetas')->assertSuccessful();
            $this->assertDatabaseHas('facturacion_carts', ['id' => $cartId, 'estado_pago' => 'pendiente']);
            $this->travel(6)->minutes();
            $this->artisan('facturas:consolidar-tarjetas')->assertSuccessful();
        }

        $this->artisan('facturas:consolidar-tarjetas')->assertSuccessful();
        $this->artisan('facturas:recuperar-inciertas')->assertSuccessful();
        $this->assertSame(1, $checkoutRequests);
        $this->assertSame(1, $fiscalPosts);
        $this->assertDatabaseCount('ventas', 1);
        $this->assertDatabaseCount('cajas_diarias', 0);
        $this->assertDatabaseHas('facturacion_carts', [
            'id' => $cartId, 'estado_pago' => 'pagado', 'qr_transaction_id' => (string) $transaction,
            'estado_emision' => 'FACTURADA', 'numero_factura' => '6907',
        ]);
    }

    public function test_manual_payment_recovery_rejects_an_unrelated_or_missing_transaction(): void
    {
        $cartId = $this->createCart(['metodo_pago' => 'qr', 'canal_emision' => 'qr', 'qr_transaction_id' => '4955881']);
        DB::table('qhantuy_qr_payments')->insert([
            'internal_code' => 'VQC-0000013495', 'transaction_id' => 4955929,
            'payment_status' => 'success', 'checkout_amount' => 21,
        ]);
        $this->artisan('app:recover-paid-qr-cart', ['--transaction_id' => 1])->assertFailed();
        $this->artisan('app:recover-paid-qr-cart', ['--cart_id' => $cartId, '--transaction_id' => 4955929])->assertFailed();
        $this->assertDatabaseHas('facturacion_carts', ['id' => $cartId, 'estado_pago' => 'pendiente', 'qr_transaction_id' => '4955881']);
    }

    public function test_card_with_invalid_fiscal_data_is_rejected_before_checkout(): void
    {
        $cartId = $this->createCart(['tipo_documento' => '1', 'numero_documento' => '99001']);
        Http::fake();

        $this->postJson('/api/factura-venta/cart/emitir', [
            'origen_usuario_id' => 'operador-test', 'cart_id' => $cartId, 'canal_emision' => 'qr', 'qr_payment_method' => 'T2P',
        ], ['Authorization' => 'Bearer integration-test-token'])->assertUnprocessable();

        Http::assertNothingSent();
        $this->assertDatabaseCount('qhantuy_qr_payments', 0);
    }

    public function test_recovery_after_closing_updates_the_expected_cash_and_keeps_the_declared_amount(): void
    {
        $cartId = $this->createCart(['estado_emision' => 'EMISION_INCIERTA', 'estado_pago' => 'pagado']);
        $caja = DB::table('cajas_diarias')->insertGetId([
            'usuario_id' => 'operador-test', 'fecha_operacion' => now()->toDateString(), 'estado' => 'CERRADA',
            'monto_apertura' => 10, 'monto_cierre_declarado' => 31, 'monto_cierre_esperado' => 10,
            'diferencia' => 21, 'diferencia_efectivo' => 21, 'cerrada_en' => now(),
        ]);
        Http::fake(['https://sefe.test/consulta/*' => Http::response($this->confirmedInvoice())]);
        $controller = app(FacturacionCartIntegrationController::class);
        $this->assertTrue($controller->recoverUncertainFiscalEmission($cartId));
        $this->assertFalse($controller->recoverUncertainFiscalEmission($cartId));

        $this->assertDatabaseHas('cajas_diarias', [
            'id' => $caja, 'estado' => 'CERRADA', 'cantidad_ventas' => 1, 'monto_ventas' => 21,
            'monto_cierre_declarado' => 31, 'monto_cierre_esperado' => 31, 'diferencia_efectivo' => 0,
        ]);
        $this->assertDatabaseHas('caja_arqueos', ['caja_diaria_id' => $caja, 'monto_total' => 21, 'diferencia' => 0]);
        $this->assertDatabaseHas('caja_arqueo_ventas', ['numero_factura' => '6907', 'codigo_orden' => self::ORDER]);
        Http::assertSentCount(1);
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
