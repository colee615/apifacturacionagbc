<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificacionDemoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'jwt.secret' => str_repeat('test-only-', 5),
            'services.facturacion_api.integration_token' => 'demo-list-test',
        ]);
        DB::purge('sqlite');
        (require database_path('migrations/2026_10_30_000000_create_notificaciones_demo_table.php'))->up();
        Schema::create('ventas', function (Blueprint $table) {
            $table->id();
            $table->string('codigoSeguimiento');
            $table->string('estado_sufe');
        });
        DB::table('ventas')->insert(['codigoSeguimiento' => '123456', 'estado_sufe' => 'PROCESADA']);
    }

    private function payload(): array
    {
        return [
            'finalizado' => true, 'estado' => 'OBSERVADO', 'fuente' => 'SUFE',
            'codigoSeguimiento' => '123456', 'fecha' => '30/10/2026 09:30:00 AM',
            'mensaje' => 'Prueba demo', 'observacion' => 'Observacion de pruebas',
            'detalle' => ['tipoEmision' => 'EMISION', 'nit' => '355701027', 'cuf' => 'CUF-DEMO', 'nroFactura' => '1'],
        ];
    }

    public function test_demo_notifications_never_modify_primary_sales(): void
    {
        $before = DB::table('ventas')->get()->toJson();
        $this->postJson('/dev/notificacion/123456', $this->payload())->assertOk()->assertJsonPath('environment', 'demo');
        $this->postJson('/dev/notificaciones/123456', $this->payload())->assertOk();
        $this->assertDatabaseCount('notificaciones_demo', 2);
        $this->assertSame($before, DB::table('ventas')->get()->toJson());
        // The fixture deliberately has no primary notification/cart/cashier tables.
    }

    public function test_invalid_or_mismatched_notification_is_not_stored(): void
    {
        $this->postJson('/dev/notificacion/123456', [])->assertUnprocessable();
        $this->postJson('/dev/notificacion/DIFFERENT', $this->payload())->assertUnprocessable();
        $this->assertDatabaseCount('notificaciones_demo', 0);
    }

    public function test_demo_list_requires_authentication_and_can_filter(): void
    {
        $this->getJson('/dev/notificaciones')->assertUnauthorized();
        $this->postJson('/dev/notificacion/123456', $this->payload())->assertOk();
        $this->withToken('demo-list-test')->getJson('/dev/notificaciones?codigoSeguimiento=123456')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/dev/notificaciones?codigoSeguimiento=OTHER')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_production_route_still_uses_its_original_controller(): void
    {
        $routes = app('router')->getRoutes();
        $route = $routes->match(\Illuminate\Http\Request::create('/notificacion/123456', 'POST'));
        $this->assertSame('App\\Http\\Controllers\\NotificacioneController@procesarNotificacion', $route->getActionName());
    }
}
