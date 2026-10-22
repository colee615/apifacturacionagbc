<?php

namespace Tests\Feature;

use App\Http\Requests\FacturacionCartItemRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FacturacionCartItemRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_cashier_fiscal_snapshot_survives_validation_without_an_online_attempt(): void
    {
        $summary = [
            'actividad_economica' => '841001', 'codigo_sin' => '99100',
            'codigo_producto' => 'SRVE-01', 'descripcion_servicio' => 'Servicio Delivery Express',
            'unidad_medida' => 58, 'codigo_paquete' => 'TEST001',
            'tarifario_snapshot' => ['version_id' => 7, 'peso' => 1.5],
        ];

        $validated = $this->validatePayload($this->payload('App\\Models\\PaqueteEms', $summary));

        $this->assertSame($summary, $validated['resumen_origen']);
        $this->assertSame(25, $validated['total_linea']);
    }

    public function test_online_attempt_keeps_all_shipment_and_fiscal_fields(): void
    {
        $summary = [
            'qr_attempt_id' => '0ec76722-3a19-45fd-b04d-49134bf2be5e',
            'actividad_economica' => '841001', 'codigo_sin' => '99100',
            'codigo_producto' => 'SRVE-01', 'descripcion_servicio' => 'Delivery Express',
            'unidad_medida' => 58, 'servicio_extra_id' => 2, 'destino_id' => 8,
            'origen' => 'LA PAZ', 'contenido' => 'Documentos', 'cantidad_paquetes' => 1,
            'remitente' => 'Cliente de prueba', 'carnet_remitente' => '1234567',
            'direccion_recojo' => 'Direccion de prueba', 'destinatario' => 'Destinatario de prueba',
            'direccion_entrega' => 'Destino de prueba',
        ];

        $validated = $this->validatePayload($this->payload('cliente_online', $summary));

        $this->assertSame($summary, $validated['resumen_origen']);
    }

    public function test_invalid_online_uuid_is_rejected_without_accepting_the_item(): void
    {
        $this->expectException(ValidationException::class);

        $this->validatePayload($this->payload('cliente_online', ['qr_attempt_id' => 'invalid']));
    }

    public function test_online_attempt_is_required_even_though_cashier_items_do_not_need_it(): void
    {
        $this->expectException(ValidationException::class);

        $this->validatePayload($this->payload('cliente_online', ['codigo_producto' => 'SRVE-01']));
    }

    private function validatePayload(array $payload): array
    {
        $request = FacturacionCartItemRequest::create('/api/factura-venta/cart/items/upsert', 'POST', $payload);
        $request->headers->set('Accept', 'application/json');
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        return $request->validated();
    }

    private function payload(string $type, array $summary): array
    {
        return [
            'origen_usuario_id' => 'operador-test', 'origen_tipo' => $type,
            'origen_id' => 123, 'codigo' => 'TEST001', 'titulo' => 'Envio de prueba',
            'resumen_origen' => $summary, 'cantidad' => 1,
            'monto_base' => 25, 'monto_extras' => 0, 'total_linea' => 25,
        ];
    }
}
