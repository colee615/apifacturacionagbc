<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QhantuyCheckoutTest extends TestCase
{
    private const CHECKOUT = '/api/factura-venta/pagos/checkout';
    private const CHECK = '/api/factura-venta/pagos/check-payments';
    private const CANCEL = '/api/factura-venta/pagos/cancel-payment';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'jwt.secret' => str_repeat('test-only-secret-', 4),
            'services.facturacion_api.integration_token' => 'bridge-test',
            'services.qhantuy_checkout.base_url' => 'https://testingcheckout.qhantuy.com/external-api/v2',
            'services.qhantuy_checkout.check_payments_url' => 'https://testingcheckout.qhantuy.com/external-api/check-payments',
            'services.qhantuy_checkout.cancel_payment_url' => 'https://testingcheckout.qhantuy.com/external-api/cancel-payment',
            'services.qhantuy_checkout.token' => 'provider-test-token',
            'services.qhantuy_checkout.appkey' => 'test-appkey',
            'services.qhantuy_checkout.callback_url' => 'https://merchant.example/api/qhantuy/callback',
            'services.qhantuy_checkout.profile_code' => 'test-profile',
            'services.qhantuy_checkout.manual_simulation' => false,
        ]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_06_01_130000_create_qhantuy_qr_payments_table.php'))->up();
        (require database_path('migrations/2026_10_27_000000_add_card_checkout_to_qhantuy_payments.php'))->up();
        Schema::create('facturacion_carts', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_orden');
            $table->decimal('total', 12, 2)->nullable();
            $table->string('metodo_pago')->nullable();
            $table->string('estado_pago')->nullable();
            $table->string('estado_emision')->nullable();
            $table->string('estado')->nullable();
            $table->string('qr_transaction_id')->nullable();
            $table->string('mensaje_emision')->nullable();
            $table->timestamp('emitido_en')->nullable();
            $table->timestamp('cerrado_en')->nullable();
            $table->timestamps();
        });
        Http::preventStrayRequests();
        $this->withToken('bridge-test');
    }

    private function order(array $overrides = []): array
    {
        return array_replace([
            'internal_code' => 'ORDER-TEST-1',
            'detail' => 'Servicio postal',
            'items' => [['name' => 'Envio', 'quantity' => 1, 'price' => 100]],
        ], $overrides);
    }

    public function test_manual_simulation_is_disabled_by_default_and_in_production(): void
    {
        $this->postJson('/api/factura-venta/pagos/simular-pago', [])->assertForbidden();
        config(['services.qhantuy_checkout.manual_simulation' => true]);
        $this->app->instance('env', 'production');
        $this->postJson('/api/factura-venta/pagos/simular-pago', [])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_manual_simulation_checks_identity_and_persists_without_provider_calls(): void
    {
        config(['services.qhantuy_checkout.manual_simulation' => true]);
        $this->seedPayment();
        $payload = ['internal_code' => 'ORDER-TEST-1', 'transaction_id' => 123, 'checkout_amount' => 100, 'confirm_simulation' => true];
        $this->postJson('/api/factura-venta/pagos/simular-pago', array_replace($payload, ['checkout_amount' => 10]))->assertConflict();
        $this->postJson('/api/factura-venta/pagos/simular-pago', $payload)->assertOk()->assertJsonPath('simulation', true)->assertJsonPath('estado_pago', 'pagado');
        $paidAt = DB::table('qhantuy_qr_payments')->value('paid_at');
        $this->travel(2)->minutes();
        $this->postJson(self::CHECK, ['payment_ids' => [123]])->assertOk()->assertJsonPath('simulation', true)->assertJsonPath('payment_status', 'success');
        $this->postJson('/api/factura-venta/pagos/simular-pago', $payload)->assertOk();
        $this->assertSame($paidAt, DB::table('qhantuy_qr_payments')->value('paid_at'));
        Http::assertNothingSent();
    }

    public function test_manual_simulation_rejects_live_gateway_and_cancelled_orders(): void
    {
        config(['services.qhantuy_checkout.manual_simulation' => true, 'services.qhantuy_checkout.base_url' => 'https://checkout.qhantuy.com/external-api/v2']);
        $payload = ['internal_code' => 'ORDER-TEST-1', 'transaction_id' => 123, 'checkout_amount' => 100, 'confirm_simulation' => true];
        $this->postJson('/api/factura-venta/pagos/simular-pago', $payload)->assertForbidden();
        config(['services.qhantuy_checkout.base_url' => 'https://testingcheckout.qhantuy.com/external-api/v2']);
        $this->seedPayment(['payment_status' => 'cancelled']);
        $this->postJson('/api/factura-venta/pagos/simular-pago', $payload)->assertConflict();
        Http::assertNothingSent();
    }

    private function providerOrder(array $overrides = []): array
    {
        return array_replace([
            'process' => true,
            'transaction_id' => 123,
            'checkout_amount' => 100,
            'checkout_currency' => 'BOB',
            'image_data' => 'https://provider.example/qr.png',
            'payment_status' => 'holding',
        ], $overrides);
    }

    private function seedPayment(array $overrides = []): void
    {
        DB::table('qhantuy_qr_payments')->insert(array_replace([
            'internal_code' => 'ORDER-TEST-1', 'transaction_id' => 123,
            'checkout_amount' => 100, 'checkout_currency' => 'BOB',
            'payment_method' => 'QRSIMPLE', 'payment_status' => 'holding',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function queryResult(array $overrides = []): array
    {
        return ['process' => true, 'items' => [array_replace([
            'id' => 123, 'checkout_amount' => '100.00', 'checkout_currency' => 'BOB',
            'internal_code' => 'ORDER-TEST-1', 'payment_status' => 'success',
        ], $overrides)]];
    }

    private function callbackUrl(array $overrides = []): string
    {
        return '/api/qhantuy/callback?'.http_build_query(array_replace([
            'transaction_id' => 123, 'profile_code' => 'test-profile',
            'internal_code' => 'ORDER-TEST-1', 'checkout_amount' => 100,
            'checkout_currency' => 'BOB', 'status' => 'success',
        ], $overrides));
    }

    public function test_qr_uses_current_protocol_and_duplicate_request_reuses_order(): void
    {
        Http::fake(['*/v2/checkout' => Http::response($this->providerOrder())]);
        $this->postJson(self::CHECKOUT, $this->order())->assertOk()->assertJsonPath('payment_method', 'QRSIMPLE');
        $this->postJson(self::CHECKOUT, $this->order())->assertOk()->assertJsonPath('reused', true);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r->hasHeader('X-API-Token', 'provider-test-token')
            && $r['qr_format'] === 'URL' && $r['transaction_type'] === 'POST_PAYMENT'
            && !isset($r['payment_type']));
        $this->assertDatabaseCount('qhantuy_qr_payments', 1);
    }

    public function test_card_accepts_payment_url_without_qr_and_forces_redirect(): void
    {
        Http::fake(['*/v2/checkout' => Http::response($this->providerOrder([
            'image_data' => null, 'payment_url' => 'https://testingpagos.qhantuy.com/pay/test',
        ]))]);
        $this->postJson(self::CHECKOUT, $this->order([
            'payment_method' => 'CYBERSOURCE', 'payment_type' => 'EMBED',
            'return_url' => 'https://merchant.example/pedido',
        ]))->assertOk()->assertJsonPath('payment_url', 'https://testingpagos.qhantuy.com/pay/test');
        Http::assertSent(fn ($r) => $r['payment_method'] === 'CYBERSOURCE'
            && $r['payment_type'] === 'REDIRECT' && !isset($r['qr_format'])
            && $r['return_url'] === 'https://merchant.example/pedido');
        $this->assertDatabaseHas('qhantuy_qr_payments', ['payment_method' => 'CYBERSOURCE']);
    }

    public function test_reusing_code_with_different_amount_is_rejected(): void
    {
        Http::fake(['*/v2/checkout' => Http::response($this->providerOrder())]);
        $this->postJson(self::CHECKOUT, $this->order())->assertOk();
        $this->postJson(self::CHECKOUT, $this->order(['items' => [['name' => 'Otro', 'quantity' => 1, 'price' => 200]]]))
            ->assertStatus(409)->assertJsonPath('code', 'payment_order_conflict');
        Http::assertSentCount(1);
    }

    public function test_timeout_reserves_order_and_retry_does_not_duplicate_provider_call(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $this->postJson(self::CHECKOUT, $this->order())->assertStatus(504);
        $this->postJson(self::CHECKOUT, $this->order())->assertStatus(409)
            ->assertJsonPath('code', 'payment_creation_unresolved');
        $this->assertDatabaseCount('qhantuy_qr_payments', 1);
    }

    public function test_http_200_with_process_false_is_not_a_usable_checkout(): void
    {
        Http::fake(['*/v2/checkout' => Http::response(['process' => false, 'message' => 'Banco no disponible'])]);
        $this->postJson(self::CHECKOUT, $this->order())->assertStatus(502);
        $this->assertDatabaseHas('qhantuy_qr_payments', ['payment_status' => 'checkout_failed']);
    }

    public function test_invalid_quantities_and_subminimum_total_never_reach_provider(): void
    {
        Http::fake();
        $this->postJson(self::CHECKOUT, $this->order(['items' => [['name' => 'Envio', 'quantity' => 0.5, 'price' => 100]]]))->assertStatus(422);
        $this->postJson(self::CHECKOUT, $this->order(['items' => [['name' => 'Envio', 'quantity' => 1, 'price' => 0.09]]]))->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_forged_callback_does_not_confirm_a_pending_payment(): void
    {
        $this->seedPayment();
        Http::fake(['*/check-payments' => Http::response($this->queryResult(['payment_status' => 'holding']))]);
        $this->getJson($this->callbackUrl())->assertStatus(503);
        $this->assertDatabaseHas('qhantuy_qr_payments', ['payment_status' => 'holding', 'paid_at' => null]);
        Http::assertSentCount(1);
    }

    public function test_verified_card_callback_is_idempotent_and_updates_cart_without_invoicing(): void
    {
        $this->seedPayment(['payment_method' => 'CYBERSOURCE']);
        DB::table('facturacion_carts')->insert(['codigo_orden' => 'ORDER-TEST-1']);
        Http::fake(['*/check-payments' => Http::response($this->queryResult())]);
        $this->getJson($this->callbackUrl())->assertOk()->assertJsonPath('payment_status', 'success');
        $paidAt = DB::table('qhantuy_qr_payments')->value('paid_at');
        $this->travel(5)->seconds();
        $this->getJson($this->callbackUrl())->assertOk();
        $this->assertSame($paidAt, DB::table('qhantuy_qr_payments')->value('paid_at'));
        $this->assertDatabaseHas('facturacion_carts', ['metodo_pago' => 'tarjeta', 'estado_pago' => 'pagado', 'estado_emision' => 'NO_APLICA']);
        Http::assertSentCount(2);
    }

    public function test_provider_currency_amount_and_identity_must_match(): void
    {
        $this->seedPayment();
        $responses = Http::sequence();
        foreach ([['checkout_currency' => 'USD'], ['checkout_amount' => '99.00'], ['id' => 999], ['internal_code' => 'OTHER']] as $override) {
            $responses->push($this->queryResult($override));
        }
        Http::fake(['*/check-payments' => $responses]);
        foreach ([['checkout_currency' => 'USD'], ['checkout_amount' => '99.00'], ['id' => 999], ['internal_code' => 'OTHER']] as $override) {
            $this->getJson($this->callbackUrl())->assertStatus(503);
            $this->assertDatabaseMissing('qhantuy_qr_payments', ['payment_status' => 'success']);
            DB::table('qhantuy_qr_payments')->update(['payment_status' => 'holding']);
        }
    }

    public function test_empty_and_failed_queries_preserve_existing_payment_state(): void
    {
        $this->seedPayment();
        Http::fake(['*/check-payments' => Http::sequence()
            ->push(['process' => true, 'items' => []])->push(['process' => false])]);
        foreach ([['process' => true, 'items' => []], ['process' => false]] as $body) {
            $this->postJson(self::CHECK, ['payment_ids' => [123]])->assertStatus(502);
            $this->assertDatabaseHas('qhantuy_qr_payments', ['payment_status' => 'holding']);
        }
    }

    public function test_paid_order_cannot_regress_or_be_cancelled(): void
    {
        $this->seedPayment(['payment_status' => 'success', 'paid_at' => now()]);
        Http::fake(['*/check-payments' => Http::response($this->queryResult(['payment_status' => 'holding']))]);
        $this->postJson(self::CHECK, ['payment_ids' => [123]])->assertOk()->assertJsonPath('payment_status', 'success');
        $this->postJson(self::CANCEL, ['transaction_id' => 123])->assertStatus(422);
        Http::assertSentCount(1);
    }

    public function test_cancel_checks_provider_result_and_transaction_identity(): void
    {
        $this->seedPayment();
        Http::fake(['*/cancel-payment' => Http::sequence()
            ->push(['process' => true, 'item' => ['transaction_id' => 999, 'current_status' => 'cancelled']])
            ->push(['process' => true, 'item' => ['transaction_id' => 123, 'current_status' => 'cancelled']])]);
        $this->postJson(self::CANCEL, ['transaction_id' => 123])->assertStatus(422);
        $this->assertDatabaseHas('qhantuy_qr_payments', ['payment_status' => 'holding']);
        $this->postJson(self::CANCEL, ['transaction_id' => 123])->assertOk()->assertJsonPath('payment_status', 'cancelled');
    }

    public function test_callback_recovers_reserved_order_only_after_provider_verifies_its_reference(): void
    {
        $this->seedPayment(['transaction_id' => null, 'payment_status' => 'creating']);
        Http::fake(['*/check-payments' => Http::response($this->queryResult())]);
        $this->getJson($this->callbackUrl())->assertOk();
        $this->assertDatabaseHas('qhantuy_qr_payments', ['transaction_id' => 123, 'payment_status' => 'success']);
    }

    public function test_missing_provider_transaction_id_or_currency_does_not_confirm_payment(): void
    {
        $this->seedPayment();
        $missingId = $this->queryResult();
        unset($missingId['items'][0]['id']);
        $missingCurrency = $this->queryResult();
        unset($missingCurrency['items'][0]['checkout_currency']);
        Http::fake(['*/check-payments' => Http::sequence()->push($missingId)->push($missingCurrency)]);
        $this->getJson($this->callbackUrl())->assertStatus(503);
        $this->getJson($this->callbackUrl())->assertStatus(503);
        $this->assertDatabaseHas('qhantuy_qr_payments', ['payment_status' => 'holding', 'paid_at' => null]);
    }

    public function test_legacy_qr_route_remains_compatible(): void
    {
        Http::fake(['*/v2/checkout' => Http::response($this->providerOrder())]);
        $this->postJson('/api/factura-venta/qr/checkout', $this->order(['image_method' => 'BASE64']))->assertOk();
        Http::assertSent(fn ($r) => $r['qr_format'] === 'BASE64' && $r['payment_method'] === 'QRSIMPLE');
    }

    public function test_checkout_cannot_underpay_an_existing_cart(): void
    {
        DB::table('facturacion_carts')->insert(['codigo_orden' => 'ORDER-TEST-1', 'total' => 200]);
        Http::fake();
        $this->postJson(self::CHECKOUT, $this->order())->assertStatus(422);
        Http::assertNothingSent();
    }
}
