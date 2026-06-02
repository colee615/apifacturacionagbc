<?php

namespace App\Http\Controllers;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class FacturacionCartIntegrationController extends Controller
{
    private const DEFAULT_BILLING_EMAIL = 'safe@correos.gob.bo';
    private const PAYMENT_METHODS = ['efectivo', 'qr'];
    private const PAYMENT_STATES = ['pendiente', 'pagado', 'fallido', 'cancelado'];

    public function context(Request $request): JsonResponse
    {
        $userId = (string) $request->validate(['origen_usuario_id' => 'required|string|max:60'])['origen_usuario_id'];

        return response()->json([
            'ok' => true,
            'draft' => $this->latestCart($userId, 'borrador'),
            'last' => $this->latestCart($userId, null),
        ]);
    }

    public function updateBilling(Request $request): JsonResponse
    {
        $v = $request->validate([
            'origen_usuario_id' => 'required|string|max:60',
            'origen_usuario_nombre' => 'nullable|string|max:255',
            'origen_usuario_email' => 'nullable|string|max:255',
            'origen_usuario_alias' => 'nullable|string|max:80',
            'origen_usuario_carnet' => 'nullable|string|max:40',
            'origen_sucursal_id' => 'nullable|string|max:60',
            'origen_sucursal_codigo' => 'nullable|string|max:60',
            'origen_sucursal_nombre' => 'nullable|string|max:255',
            'modalidad_facturacion' => 'nullable|in:con_datos,sin_cliente',
            'canal_emision' => 'nullable|in:factura_electronica,qr',
            'tipo_documento' => 'nullable|string|max:20',
            'numero_documento' => 'nullable|string|max:80',
            'complemento_documento' => 'nullable|string|max:30',
            'razon_social' => 'nullable|string|max:255',
            'correo_facturacion' => 'nullable|email|max:50',
        ]);

        $mode = in_array(($v['modalidad_facturacion'] ?? ''), ['con_datos', 'sin_cliente'], true) ? $v['modalidad_facturacion'] : 'con_datos';
        $tipo = $mode === 'sin_cliente' ? null : $this->nullBlank($v['tipo_documento'] ?? null);
        $numero = $mode === 'sin_cliente' ? null : $this->nullBlank($v['numero_documento'] ?? null);
        $complemento = $mode === 'sin_cliente' || !in_array((string) $tipo, ['1', '2'], true)
            ? null
            : Str::upper((string) $this->nullBlank($v['complemento_documento'] ?? null));
        $razon = $mode === 'sin_cliente' ? null : Str::upper((string) $this->nullBlank($v['razon_social'] ?? null));
        $correoFacturacion = $this->nullBlank($v['correo_facturacion'] ?? null);
        $correoFacturacion = $correoFacturacion !== null ? strtolower($correoFacturacion) : null;
        $canal = in_array((string) ($v['canal_emision'] ?? ''), ['factura_electronica', 'qr'], true)
            ? (string) $v['canal_emision']
            : 'factura_electronica';
        $metodoPago = $canal === 'qr' ? 'qr' : 'efectivo';
        $estadoPago = $canal === 'qr' ? 'pendiente' : 'pagado';

        $cartId = $this->ensureDraft((string) $v['origen_usuario_id'], array_merge([
            'origen_usuario_nombre' => $this->nullBlank($v['origen_usuario_nombre'] ?? null),
            'origen_usuario_email' => $this->nullBlank($v['origen_usuario_email'] ?? null),
            'origen_sucursal_id' => $this->nullBlank($v['origen_sucursal_id'] ?? null),
            'origen_sucursal_codigo' => $this->nullBlank($v['origen_sucursal_codigo'] ?? null),
            'origen_sucursal_nombre' => $this->nullBlank($v['origen_sucursal_nombre'] ?? null),
            'modalidad_facturacion' => $mode,
            'canal_emision' => $canal,
            'metodo_pago' => $metodoPago,
            'estado_pago' => $estadoPago,
            'tipo_documento' => $tipo,
            'numero_documento' => $numero,
            'complemento_documento' => $complemento,
            'razon_social' => $razon,
            'correo_facturacion' => $correoFacturacion,
        ], $this->identityColumnsForCart($v)));

        return response()->json(['ok' => true, 'cart' => $this->cartById($cartId)]);
    }

    public function upsertItem(Request $request): JsonResponse
    {
        $v = $request->validate([
            'origen_usuario_id' => 'required|string|max:60',
            'origen_usuario_nombre' => 'nullable|string|max:255',
            'origen_usuario_email' => 'nullable|string|max:255',
            'origen_usuario_alias' => 'nullable|string|max:80',
            'origen_usuario_carnet' => 'nullable|string|max:40',
            'origen_sucursal_id' => 'nullable|string|max:60',
            'origen_sucursal_codigo' => 'nullable|string|max:60',
            'origen_sucursal_nombre' => 'nullable|string|max:255',
            'origen_tipo' => 'required|string|max:120',
            'origen_id' => 'required|integer|min:1',
            'codigo' => 'nullable|string|max:120',
            'titulo' => 'required|string|max:255',
            'nombre_servicio' => 'nullable|string|max:255',
            'nombre_destinatario' => 'nullable|string|max:255',
            'servicios_extra' => 'nullable|array',
            'resumen_origen' => 'nullable|array',
            'cantidad' => 'nullable|integer|min:1',
            'monto_base' => 'nullable|numeric|min:0',
            'monto_extras' => 'nullable|numeric|min:0',
            'total_linea' => 'nullable|numeric|min:0',
        ]);

        $cartId = $this->ensureDraft((string) $v['origen_usuario_id'], array_merge([
            'origen_usuario_nombre' => $this->nullBlank($v['origen_usuario_nombre'] ?? null),
            'origen_usuario_email' => $this->nullBlank($v['origen_usuario_email'] ?? null),
            'origen_sucursal_id' => $this->nullBlank($v['origen_sucursal_id'] ?? null),
            'origen_sucursal_codigo' => $this->nullBlank($v['origen_sucursal_codigo'] ?? null),
            'origen_sucursal_nombre' => $this->nullBlank($v['origen_sucursal_nombre'] ?? null),
        ], $this->identityColumnsForCart($v)));

        $existing = DB::table('facturacion_cart_items')
            ->where('cart_id', $cartId)
            ->where('origen_tipo', $v['origen_tipo'])
            ->where('origen_id', (int) $v['origen_id'])
            ->first();

        $data = [
            'cart_id' => $cartId,
            'origen_tipo' => (string) $v['origen_tipo'],
            'origen_id' => (int) $v['origen_id'],
            'codigo' => $this->nullBlank($v['codigo'] ?? null),
            'titulo' => (string) $v['titulo'],
            'nombre_servicio' => $this->nullBlank($v['nombre_servicio'] ?? null),
            'nombre_destinatario' => $this->nullBlank($v['nombre_destinatario'] ?? null),
            'servicios_extra' => json_encode((array) ($v['servicios_extra'] ?? []), JSON_UNESCAPED_UNICODE),
            'resumen_origen' => json_encode((array) ($v['resumen_origen'] ?? []), JSON_UNESCAPED_UNICODE),
            'cantidad' => max(1, (int) ($v['cantidad'] ?? 1)),
            'monto_base' => round((float) ($v['monto_base'] ?? 0), 2),
            'monto_extras' => round((float) ($v['monto_extras'] ?? 0), 2),
            'total_linea' => round((float) ($v['total_linea'] ?? 0), 2),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('facturacion_cart_items')->where('id', $existing->id)->update($data);
        } else {
            $data['created_at'] = now();
            DB::table('facturacion_cart_items')->insert($data);
        }

        $this->recalc($cartId);
        return response()->json(['ok' => true, 'cart' => $this->cartById($cartId)]);
    }

    public function updateItem(Request $request, int $itemId): JsonResponse
    {
        $v = $request->validate([
            'origen_usuario_id' => 'required|string|max:60',
            'codigo' => 'required|string|max:120',
            'titulo' => 'required|string|max:255',
            'nombre_servicio' => 'nullable|string|max:255',
            'nombre_destinatario' => 'nullable|string|max:255',
            'contenido' => 'nullable|string|max:255',
            'direccion' => 'nullable|string|max:255',
            'ciudad' => 'nullable|string|max:255',
            'peso' => 'nullable|numeric|min:0',
            'actividad_economica' => 'nullable|string|max:20',
            'codigo_sin' => 'nullable|string|max:50',
            'codigo_producto' => 'nullable|string|max:50',
            'descripcion_servicio' => 'nullable|string|max:255',
            'unidad_medida' => 'nullable|integer|min:1',
        ]);

        $row = DB::table('facturacion_cart_items as i')
            ->join('facturacion_carts as c', 'c.id', '=', 'i.cart_id')
            ->where('i.id', $itemId)
            ->where('c.origen_usuario_id', $v['origen_usuario_id'])
            ->where('c.estado', 'borrador')
            ->select('i.*', 'c.id as cart_id_ref')
            ->first();
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Item no encontrado.'], 404);
        }

        $r = $this->decode((string) ($row->resumen_origen ?? ''));
        $r['codigo'] = trim((string) ($v['codigo'] ?? ($r['codigo'] ?? '')));
        $r['contenido'] = trim((string) ($v['contenido'] ?? ($r['contenido'] ?? '')));
        $r['peso'] = isset($v['peso']) ? round((float) $v['peso'], 3) : (float) ($r['peso'] ?? 0);
        $r['destinatario'] = trim((string) ($v['nombre_destinatario'] ?? ($r['destinatario'] ?? '')));
        $r['direccion'] = trim((string) ($v['direccion'] ?? ($r['direccion'] ?? '')));
        $r['ciudad'] = trim((string) ($v['ciudad'] ?? ($r['ciudad'] ?? '')));
        $r['actividad_economica'] = trim((string) ($v['actividad_economica'] ?? ($r['actividad_economica'] ?? '')));
        $r['codigo_sin'] = trim((string) ($v['codigo_sin'] ?? ($r['codigo_sin'] ?? '')));
        $r['codigo_producto'] = trim((string) ($v['codigo_producto'] ?? ($r['codigo_producto'] ?? '')));
        $r['descripcion_servicio'] = trim((string) ($v['descripcion_servicio'] ?? ($r['descripcion_servicio'] ?? '')));
        $r['unidad_medida'] = isset($v['unidad_medida']) ? (int) $v['unidad_medida'] : ($r['unidad_medida'] ?? null);

        DB::table('facturacion_cart_items')->where('id', $itemId)->update([
            'codigo' => trim((string) $v['codigo']),
            'titulo' => trim((string) $v['titulo']),
            'nombre_servicio' => $this->nullBlank($v['nombre_servicio'] ?? null),
            'nombre_destinatario' => $this->nullBlank($v['nombre_destinatario'] ?? null),
            'resumen_origen' => json_encode($r, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        $this->recalc((int) $row->cart_id_ref);
        return response()->json(['ok' => true, 'cart' => $this->cartById((int) $row->cart_id_ref)]);
    }

    public function removeItem(Request $request, int $itemId): JsonResponse
    {
        $userId = (string) $request->validate(['origen_usuario_id' => 'required|string|max:60'])['origen_usuario_id'];
        $row = DB::table('facturacion_cart_items as i')
            ->join('facturacion_carts as c', 'c.id', '=', 'i.cart_id')
            ->where('i.id', $itemId)->where('c.origen_usuario_id', $userId)->where('c.estado', 'borrador')
            ->select('i.id', 'c.id as cart_id_ref')->first();
        if (!$row) {
            return response()->json(['ok' => false, 'message' => 'Item no encontrado.'], 404);
        }

        DB::table('facturacion_cart_items')->where('id', $itemId)->delete();
        $this->recalc((int) $row->cart_id_ref);
        return response()->json(['ok' => true, 'cart' => $this->cartById((int) $row->cart_id_ref)]);
    }

    public function clear(Request $request): JsonResponse
    {
        $userId = (string) $request->validate(['origen_usuario_id' => 'required|string|max:60'])['origen_usuario_id'];
        $draft = DB::table('facturacion_carts')->where('origen_usuario_id', $userId)->where('estado', 'borrador')->latest('id')->first();
        if (!$draft) return response()->json(['ok' => true, 'cart' => null]);

        DB::table('facturacion_cart_items')->where('cart_id', $draft->id)->delete();
        $this->recalc((int) $draft->id);
        return response()->json(['ok' => true, 'cart' => $this->cartById((int) $draft->id)]);
    }

    public function payment(Request $request): JsonResponse
    {
        $v = $request->validate([
            'origen_usuario_id' => 'required|string|max:60',
            'metodo_pago' => 'required|in:efectivo,qr',
            'estado_pago' => 'nullable|in:pendiente,pagado,fallido,cancelado',
        ]);

        $cart = DB::table('facturacion_carts')
            ->where('origen_usuario_id', (string) $v['origen_usuario_id'])
            ->where('estado', 'borrador')
            ->latest('id')
            ->first();

        if (!$cart) {
            return response()->json(['ok' => false, 'message' => 'No se encontro un borrador de facturacion activo.'], 422);
        }

        $metodoPago = (string) $v['metodo_pago'];
        $estadoPago = (string) ($v['estado_pago'] ?? ($metodoPago === 'efectivo' ? 'pagado' : 'pendiente'));

        DB::table('facturacion_carts')->where('id', $cart->id)->update([
            'metodo_pago' => $metodoPago,
            'estado_pago' => $estadoPago,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'cart' => $this->cartById((int) $cart->id)]);
    }

    public function emitir(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'origen_usuario_id' => 'required|string|max:60',
            'modalidad_facturacion' => 'nullable|in:con_datos,sin_cliente',
            'canal_emision' => 'nullable|in:factura_electronica,qr',
            'tipo_documento' => 'nullable|string|max:20',
            'numero_documento' => 'nullable|string|max:80',
            'complemento_documento' => 'nullable|string|max:30',
            'razon_social' => 'nullable|string|max:255',
            'correo_facturacion' => 'nullable|email|max:50',
        ]);
        $userId = (string) $validated['origen_usuario_id'];
        $cart = DB::table('facturacion_carts')->where('origen_usuario_id', $userId)->where('estado', 'borrador')->latest('id')->first();
        if (!$cart) return response()->json(['ok' => false, 'message' => 'No se encontro un borrador de facturacion activo.'], 422);

        $overrideCanal = in_array((string) ($validated['canal_emision'] ?? ''), ['factura_electronica', 'qr'], true)
            ? (string) $validated['canal_emision']
            : strtolower(trim((string) ($cart->canal_emision ?? 'factura_electronica')));
        if (!in_array($overrideCanal, ['factura_electronica', 'qr'], true)) {
            $overrideCanal = 'factura_electronica';
        }

        $overrideMode = in_array((string) ($validated['modalidad_facturacion'] ?? ''), ['con_datos', 'sin_cliente'], true)
            ? (string) $validated['modalidad_facturacion']
            : (string) ($cart->modalidad_facturacion ?? 'con_datos');

        DB::table('facturacion_carts')->where('id', $cart->id)->update([
            'modalidad_facturacion' => $overrideMode,
            'canal_emision' => $overrideCanal,
            'metodo_pago' => $overrideCanal === 'qr' ? 'qr' : 'efectivo',
            'estado_pago' => $overrideCanal === 'qr' ? 'pendiente' : 'pagado',
            'tipo_documento' => $validated['tipo_documento'] ?? $cart->tipo_documento,
            'numero_documento' => $validated['numero_documento'] ?? $cart->numero_documento,
            'complemento_documento' => $validated['complemento_documento'] ?? $cart->complemento_documento,
            'razon_social' => isset($validated['razon_social']) ? Str::upper((string) $validated['razon_social']) : $cart->razon_social,
            'correo_facturacion' => $validated['correo_facturacion'] ?? $cart->correo_facturacion,
            'updated_at' => now(),
        ]);
        $cart = DB::table('facturacion_carts')->where('id', $cart->id)->first();

        $items = DB::table('facturacion_cart_items')->where('cart_id', $cart->id)->orderBy('id')->get()->map(function ($i) {
            $i->resumen_origen = $this->decode((string) ($i->resumen_origen ?? ''));
            return $i;
        });
        if ($items->isEmpty()) return response()->json(['ok' => false, 'message' => 'El borrador no tiene items para emitir.'], 422);
        $canalEmision = strtolower(trim((string) ($cart->canal_emision ?? 'factura_electronica')));
        if (!in_array($canalEmision, ['factura_electronica', 'qr'], true)) {
            $canalEmision = 'factura_electronica';
        }

        // Cada intento de emision usa un codigo de orden nuevo para evitar rechazos por "ya emitida".
        $codigoOrdenIntento = $this->nextBridgeCodigoOrden($canalEmision);
        DB::table('facturacion_carts')->where('id', $cart->id)->update([
            'codigo_orden' => $codigoOrdenIntento,
            'updated_at' => now(),
        ]);
        $cart->codigo_orden = $codigoOrdenIntento;

        if ($canalEmision === 'qr') {
            $emitReq = Request::create('/api/factura-venta/qr/checkout', 'POST', $this->qrCheckoutPayloadFromCart($cart, $items));
            $emitReq->headers->set('Accept', 'application/json');
            $emitRes = app(QhantuyQrController::class)->checkout($emitReq);
        } else {
            $emitReq = Request::create('/api/factura-venta/emitir', 'POST', $this->payloadFromCart($cart, $items));
            $emitReq->headers->set('Accept', 'application/json');
            $emitReq->headers->set('X-Bridge-Debug', 'true');
            $emitRes = app(FacturaVentaApiController::class)->emitir($emitReq);
        }
        $body = json_decode($emitRes->getContent(), true);
        if (!is_array($body)) $body = ['ok' => false, 'estado' => 'ERROR', 'mensaje' => 'Respuesta no valida'];
        $emitStatusCode = $emitRes->getStatusCode();

        $ok = (bool) ($body['ok'] ?? false);
        $codigoOrdenEmitido = trim((string) ($body['codigoOrden'] ?? ''));
        $codigoSeguimientoEmitido = trim((string) ($body['codigoSeguimiento'] ?? data_get($body, 'sefe.datos.codigoSeguimiento', '')));
        if ($canalEmision === 'qr') {
            $codigoOrdenEmitido = trim((string) ($body['internal_code'] ?? $codigoOrdenEmitido));
            $codigoSeguimientoEmitido = trim((string) ($body['transaction_id'] ?? $codigoSeguimientoEmitido));
            $body['estado'] = $this->mapQrPaymentStatusToCartEstado((string) ($body['payment_status'] ?? 'holding'));
            $body['mensaje'] = (string) ($body['message'] ?? 'QR generado correctamente.');
        }
        if ($codigoOrdenEmitido === '') {
            $ventaLocal = DB::table('ventas')
                ->where('origen_venta_id', (string) $cart->id)
                ->where('origen_venta_tipo', 'facturacion_cart_remote')
                ->orderByDesc('id')
                ->first();

            $codigoOrdenEmitido = trim((string) ($ventaLocal->codigoOrden ?? ''));
            if ($codigoSeguimientoEmitido === '') {
                $codigoSeguimientoEmitido = trim((string) ($ventaLocal->codigoSeguimiento ?? ''));
            }
        }
        if ($codigoOrdenEmitido === '') {
            $codigoOrdenEmitido = $codigoOrdenIntento;
        }

        DB::table('facturacion_carts')->where('id', $cart->id)->update([
            'codigo_orden' => $codigoOrdenEmitido,
            'codigo_seguimiento' => $codigoSeguimientoEmitido,
            'estado_emision' => (string) ($body['estado'] ?? ($ok ? 'PENDIENTE' : 'RECHAZADA')),
            'mensaje_emision' => (string) ($body['mensaje'] ?? $body['message'] ?? ''),
            'respuesta_emision' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'estado' => $ok ? 'emitido' : 'borrador',
            'emitido_en' => $ok ? now() : null,
            'cerrado_en' => $ok ? now() : null,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'cart' => $this->cartById((int) $cart->id), 'respuesta' => $body, 'status_code' => $emitStatusCode]);
    }

    public function consultar(Request $request): JsonResponse
    {
        $v = $request->validate(['origen_usuario_id' => 'required|string|max:60', 'cart_id' => 'nullable|integer|min:1']);
        $q = DB::table('facturacion_carts')->where('origen_usuario_id', $v['origen_usuario_id'])->whereNotNull('codigo_seguimiento');
        if (!empty($v['cart_id'])) $q->where('id', (int) $v['cart_id']);
        $cart = $q->orderByDesc('emitido_en')->orderByDesc('id')->first();
        if (!$cart || trim((string) $cart->codigo_seguimiento) === '') return response()->json(['ok' => false, 'message' => 'No existe una emision previa para consultar.'], 422);

        $canalEmision = strtolower(trim((string) ($cart->canal_emision ?? 'factura_electronica')));
        if ($canalEmision === 'qr') {
            $transactionId = (int) trim((string) $cart->codigo_seguimiento);
            if ($transactionId <= 0) {
                return response()->json(['ok' => false, 'message' => 'No existe transaction_id QR para consultar.'], 422);
            }
            $cReq = Request::create('/api/factura-venta/qr/check-payments', 'POST', [
                'payment_ids' => [$transactionId],
                'internal_code' => (string) ($cart->codigo_orden ?? ''),
            ]);
            $cReq->headers->set('Accept', 'application/json');
            $cRes = app(QhantuyQrController::class)->checkPayments($cReq);
        } else {
            $cReq = Request::create('/api/factura-venta/consultar/' . urlencode((string) $cart->codigo_seguimiento), 'GET');
            $cReq->headers->set('Accept', 'application/json');
            $cReq->headers->set('X-Bridge-Debug', 'true');
            $cRes = app(FacturaVentaApiController::class)->consultar($cReq, (string) $cart->codigo_seguimiento);
        }
        $body = json_decode($cRes->getContent(), true);
        if (!is_array($body)) $body = ['ok' => false, 'estado' => 'ERROR', 'mensaje' => 'Respuesta no valida'];
        $statusCode = $cRes->getStatusCode();
        if ($canalEmision === 'qr') {
            $body['estado'] = $this->mapQrPaymentStatusToCartEstado((string) ($body['payment_status'] ?? 'holding'));
            $body['mensaje'] = (string) ($body['message'] ?? 'Estado QR actualizado.');
        }

        DB::table('facturacion_carts')->where('id', $cart->id)->update([
            'estado_emision' => (string) ($body['estado'] ?? ($cart->estado_emision ?? 'ERROR')),
            'mensaje_emision' => (string) ($body['mensaje'] ?? ($cart->mensaje_emision ?? '')),
            'respuesta_emision' => json_encode($body, JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'cart' => $this->cartById((int) $cart->id), 'respuesta' => $body, 'status_code' => $statusCode]);
    }

    public function ventas(Request $request): JsonResponse
    {
        $v = $request->validate([
            'origen_usuario_id' => 'required|string|max:60',
            'estado' => 'nullable|in:all,borrador,emitido',
            'estado_emision' => 'nullable|in:all,FACTURADA,PENDIENTE,RECHAZADA,ERROR',
            'from' => 'nullable|date', 'to' => 'nullable|date', 'q' => 'nullable|string|max:120',
            'per_page' => 'nullable|integer|min:10|max:100', 'page' => 'nullable|integer|min:1',
        ]);

        $f = ['estado' => (string) ($v['estado'] ?? 'all'), 'estado_emision' => (string) ($v['estado_emision'] ?? 'all'), 'from' => $v['from'] ?? null, 'to' => $v['to'] ?? null, 'q' => trim((string) ($v['q'] ?? '')), 'per_page' => (int) ($v['per_page'] ?? 20), 'page' => (int) ($v['page'] ?? 1)];
        $base = DB::table('facturacion_carts')->where('origen_usuario_id', $v['origen_usuario_id']);
        $this->applyFilters($base, $f);
        $total = (clone $base)->count();
        $rows = $base->orderByDesc('emitido_en')->orderByDesc('id')->forPage($f['page'], $f['per_page'])->get();

        $sum = DB::table('facturacion_carts')->where('origen_usuario_id', $v['origen_usuario_id']);
        $this->applyFilters($sum, $f);
        $summary = [
            'totalVentas' => (clone $sum)->where('estado', 'emitido')->count(),
            'totalBorradores' => (clone $sum)->where('estado', 'borrador')->count(),
            'facturadas' => (clone $sum)->whereRaw("upper(coalesce(estado_emision, '')) = 'FACTURADA'")->count(),
            'pendientes' => (clone $sum)->whereRaw("upper(coalesce(estado_emision, '')) = 'PENDIENTE'")->count(),
            'rechazadas' => (clone $sum)->whereRaw("upper(coalesce(estado_emision, '')) = 'RECHAZADA'")->count(),
            'montoTotal' => (float) ((clone $sum)->where('estado', 'emitido')->sum('total')),
        ];

        return response()->json(['ok' => true, 'data' => [
            'carts' => $rows->map(fn ($r) => $this->cartById((int) $r->id))->values()->all(),
            'pagination' => ['total' => $total, 'per_page' => $f['per_page'], 'current_page' => $f['page'], 'last_page' => (int) ceil(max($total, 1) / $f['per_page'])],
            'summary' => $summary, 'filters' => $f,
        ]]);
    }

    public function ventasPdf(Request $request): HttpResponse
    {
        $v = $request->validate([
            'origen_usuario_id' => 'required|string|max:60',
            'estado' => 'nullable|in:all,borrador,emitido',
            'estado_emision' => 'nullable|in:all,FACTURADA,PENDIENTE,RECHAZADA,ERROR',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'q' => 'nullable|string|max:120',
            'responsable_nombre' => 'nullable|string|max:255',
            'oficina_postal' => 'nullable|string|max:255',
            'ventanilla' => 'nullable|string|max:255',
        ]);

        $filters = [
            'estado' => (string) ($v['estado'] ?? 'all'),
            'estado_emision' => (string) ($v['estado_emision'] ?? 'all'),
            'from' => $v['from'] ?? null,
            'to' => $v['to'] ?? null,
            'q' => trim((string) ($v['q'] ?? '')),
        ];

        $base = DB::table('facturacion_carts')->where('origen_usuario_id', $v['origen_usuario_id']);
        $this->applyFilters($base, $filters);
        $carts = $base
            ->orderByDesc('emitido_en')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($r) => $this->cartById((int) $r->id))
            ->filter()
            ->values();

        $rows = $this->buildPdfRows($carts);
        $totals = [
            'parcial' => round((float) $rows->sum('importe_parcial'), 2),
            'general' => round((float) $rows->sum('importe_general'), 2),
        ];

        $firstCart = (array) ($carts->first() ?? []);
        $user = (object) [
            'name' => trim((string) ($v['responsable_nombre'] ?? ($firstCart['origen_usuario_nombre'] ?? $v['origen_usuario_id']))),
            'sucursal' => (object) [
                'nombre' => trim((string) ($v['oficina_postal'] ?? ($firstCart['origen_sucursal_nombre'] ?? ''))),
                'descripcion' => trim((string) ($v['oficina_postal'] ?? ($firstCart['origen_sucursal_nombre'] ?? ''))),
                'municipio' => '',
                'puntoVenta' => (string) ($firstCart['origen_sucursal_id'] ?? ''),
            ],
        ];

        $html = view('facturacion.mis-ventas-kardex-pdf', [
            'user' => $user,
            'filters' => $filters,
            'carts' => $this->normalizeCarts($carts),
            'rows' => $rows,
            'totals' => $totals,
            'generatedAt' => now(),
            'forceVentanilla' => trim((string) ($v['ventanilla'] ?? '')),
        ])->render();

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'kardex-facturacion-' . now()->format('Ymd-His') . '.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function show(Request $request, int $cartId): JsonResponse
    {
        $userId = (string) $request->validate(['origen_usuario_id' => 'required|string|max:60'])['origen_usuario_id'];
        $cart = DB::table('facturacion_carts')->where('id', $cartId)->where('origen_usuario_id', $userId)->first();
        if (!$cart) return response()->json(['ok' => false, 'message' => 'No se encontro la venta solicitada.'], 404);
        return response()->json(['ok' => true, 'cart' => $this->cartById((int) $cart->id)]);
    }

    private function payloadFromCart(object $cart, $items): array
    {
        $sinCliente = (string) ($cart->modalidad_facturacion ?? 'con_datos') === 'sin_cliente';
        $tipo = $sinCliente ? 5 : (int) ($cart->tipo_documento ?: 1);
        $doc = $sinCliente ? '99003' : (string) ($cart->numero_documento ?: '');
        $razon = $sinCliente ? 'SIN NOMBRE' : (string) ($cart->razon_social ?: '');
        if (!$sinCliente && ($doc === '' || $razon === '' || blank($cart->tipo_documento))) abort(422, 'Completa tipo de documento, numero de documento y razon social antes de emitir.');

        $rawCodigoSucursal = $cart->origen_sucursal_codigo;
        $rawPuntoVenta = $cart->origen_sucursal_id;
        if (!is_numeric($rawCodigoSucursal) || !is_numeric($rawPuntoVenta)) {
            abort(422, 'La sucursal de origen no tiene codigoSucursal/puntoVenta validos para emitir.');
        }

        $codigoSucursal = (int) $rawCodigoSucursal;
        $puntoVenta = (int) $rawPuntoVenta;
        if ($codigoSucursal < 0 || $puntoVenta < 0) {
            abort(422, 'La sucursal de origen no tiene codigoSucursal/puntoVenta validos para emitir.');
        }

        $origenUsuarioEmail = trim((string) ($cart->origen_usuario_email ?? ''));
        if ($origenUsuarioEmail !== '' && !filter_var($origenUsuarioEmail, FILTER_VALIDATE_EMAIL)) {
            $origenUsuarioEmail = '';
        }
        $correo = trim((string) ($cart->correo_facturacion ?? ''));
        if (
            $correo === ''
            || !filter_var($correo, FILTER_VALIDATE_EMAIL)
            || ($origenUsuarioEmail !== '' && strtolower($correo) === strtolower($origenUsuarioEmail))
        ) {
            $correo = self::DEFAULT_BILLING_EMAIL;
        }

        $detalle = collect($items)->map(function ($i) {
            $r = (array) ($i->resumen_origen ?? []);
            $ae = trim((string) ($r['actividad_economica'] ?? ''));
            $cs = trim((string) ($r['codigo_sin'] ?? ''));
            $cp = trim((string) ($r['codigo_producto'] ?? ''));
            $codigoPaquete = trim((string) ($r['codigo'] ?? ($i->codigo ?? '')));
            $de = trim((string) ($r['descripcion_servicio'] ?? ''));
            $um = is_numeric($r['unidad_medida'] ?? null) ? (int) $r['unidad_medida'] : 0;
            if ($ae === '' || $cs === '' || $cp === '' || $de === '' || $um <= 0 || mb_strlen($cp) < 3) abort(422, 'El borrador tiene items sin datos SIN completos.');
            $codigoDetalle = $this->buildDetalleCodigo($codigoPaquete, $cp);
            return ['actividadEconomica' => $ae, 'codigoSin' => $cs, 'codigo' => $codigoDetalle, 'descripcion' => Str::limit(Str::upper($de), 250, ''), 'unidadMedida' => $um, 'precioUnitario' => round((float) ($i->monto_base ?? 0), 2), 'cantidad' => max(1, (int) ($i->cantidad ?? 1))];
        })->values()->all();

        $codigoOrden = trim((string) ($cart->codigo_orden ?? ''));
        if ($codigoOrden === '') $codigoOrden = $this->nextBridgeCodigoOrden('factura_electronica');

        $canalEmision = 'factura_electronica';
        $motivo = 'factura electronica';

        return [
            'codigoOrden' => $codigoOrden,
            'origenVenta' => ['id' => (string) $cart->id, 'tipo' => 'facturacion_cart_remote'],
            'origenUsuario' => [
                'id' => (string) $cart->origen_usuario_id,
                'nombre' => (string) ($cart->origen_usuario_nombre ?? ''),
                'email' => $origenUsuarioEmail,
                'alias' => (string) ($cart->origen_usuario_alias ?? ''),
                'carnet' => (string) ($cart->origen_usuario_carnet ?? ''),
            ],
            'origenSucursal' => ['id' => (string) $cart->origen_sucursal_id, 'codigo' => (string) $cart->origen_sucursal_codigo, 'nombre' => (string) ($cart->origen_sucursal_nombre ?? '')],
            'codigoSucursal' => $codigoSucursal, 'puntoVenta' => $puntoVenta, 'documentoSector' => 1,
            'canalEmision' => $canalEmision,
            'municipio' => 'LA PAZ', 'departamento' => 'LA PAZ', 'telefono' => '2222222',
            'codigoCliente' => $sinCliente ? 'SN-' . str_pad((string) $cart->id, 8, '0', STR_PAD_LEFT) : Str::limit($this->sanitizeCodigoClienteFromDocument($doc), 35, ''),
            'razonSocial' => Str::upper($razon), 'documentoIdentidad' => $doc, 'tipoDocumentoIdentidad' => $tipo, 'correo' => $correo,
            'metodoPago' => strtolower((string) ($cart->metodo_pago ?? 'efectivo')) === 'qr' ? 5 : 1, 'formatoFactura' => 'rollo', 'montoTotal' => round((float) $cart->total, 2), 'detalle' => $detalle,
            'motivo' => $motivo,
        ];
    }

    private function qrCheckoutPayloadFromCart(object $cart, $items): array
    {
        $codigoOrden = trim((string) ($cart->codigo_orden ?? ''));
        if ($codigoOrden === '') {
            $codigoOrden = $this->nextBridgeCodigoOrden('qr');
        }

        $correo = trim((string) ($cart->correo_facturacion ?? ''));
        if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            $correo = self::DEFAULT_BILLING_EMAIL;
        }

        $fullName = trim((string) ($cart->razon_social ?? $cart->origen_usuario_nombre ?? 'CLIENTE QR'));
        $parts = preg_split('/\s+/', $fullName) ?: [];
        $firstName = trim((string) ($parts[0] ?? 'CLIENTE'));
        $lastName = trim((string) (count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : 'QR'));

        $itemsPayload = collect($items)->map(function ($i) {
            $nombre = trim((string) ($i->titulo ?? $i->nombre_servicio ?? 'Servicio postal'));
            $cantidad = max(1, (int) ($i->cantidad ?? 1));
            $totalLinea = round((float) ($i->total_linea ?? 0), 2);
            $montoBase = round((float) ($i->monto_base ?? 0), 2);
            $montoExtras = round((float) ($i->monto_extras ?? 0), 2);
            $precio = round($totalLinea / max(1, $cantidad), 2);
            if ($precio <= 0) {
                $precio = round(($montoBase + $montoExtras) / max(1, $cantidad), 2);
            }
            if ($precio <= 0) {
                $precio = $montoBase;
            }
            if ($precio <= 0) {
                $precio = 1.00;
            }

            return [
                'name' => Str::limit($nombre, 120, ''),
                'quantity' => $cantidad,
                'price' => $precio,
            ];
        })->values()->all();

        return [
            'customer_email' => $correo,
            'customer_first_name' => Str::limit($firstName, 120, ''),
            'customer_last_name' => Str::limit($lastName, 120, ''),
            'currency_code' => (string) config('services.qhantuy_checkout.currency_code', 'BOB'),
            'internal_code' => $codigoOrden,
            'callback_url' => (string) config('services.qhantuy_checkout.callback_url', url('/api/qhantuy/callback')),
            'payment_method' => 'QRSIMPLE',
            'image_method' => (string) config('services.qhantuy_checkout.image_method', 'URL'),
            'detail' => 'Pago QR de venta ' . $codigoOrden,
            'items' => $itemsPayload,
        ];
    }

    private function mapQrPaymentStatusToCartEstado(string $status): string
    {
        return match (strtolower(trim($status))) {
            'success' => 'FACTURADA',
            'cancelled', 'rejected' => 'RECHAZADA',
            default => 'PENDIENTE',
        };
    }

    private function nextBridgeCodigoOrden(string $canalEmision = 'factura_electronica'): string
    {
        $canalEmision = strtolower(trim($canalEmision));
        if (!in_array($canalEmision, ['factura_electronica', 'qr'], true)) {
            $canalEmision = 'factura_electronica';
        }

        $prefix = $canalEmision === 'qr' ? 'VQ-' : 'VF-';
        $next = 1;
        $pattern = '/^' . preg_quote($prefix, '/') . '(\d{1,12})$/';

        $ventasCodes = DB::table('ventas')
            ->whereNotNull('codigoOrden')
            ->where('codigoOrden', 'like', $prefix . '%')
            ->pluck('codigoOrden');

        foreach ($ventasCodes as $code) {
            $code = trim((string) $code);
            if (preg_match($pattern, $code, $m)) {
                $next = max($next, ((int) $m[1]) + 1);
            }
        }

        $cartCodes = DB::table('facturacion_carts')
            ->whereNotNull('codigo_orden')
            ->where('codigo_orden', 'like', $prefix . '%')
            ->pluck('codigo_orden');

        foreach ($cartCodes as $code) {
            $code = trim((string) $code);
            if (preg_match($pattern, $code, $m)) {
                $next = max($next, ((int) $m[1]) + 1);
            }
        }

        return $prefix . str_pad((string) $next, 9, '0', STR_PAD_LEFT);
    }

    private function buildDetalleCodigo(string $codigoPaquete, string $codigoProductoFallback): string
    {
        $paq = trim($codigoPaquete);

        // Requerimiento: usar solo el codigo del paquete.
        // Fallback defensivo: si falta codigo de paquete, usar codigo de producto.
        $source = $paq !== '' ? $paq : trim($codigoProductoFallback);

        return Str::limit($this->sanitizeDetalleCodigo($source), 50, '');
    }

    private function sanitizeCodigoClienteFromDocument(string $document): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-_]/', '', strtoupper(trim($document))) ?? '';
        return $clean !== '' ? $clean : 'SN';
    }

    private function sanitizeDetalleCodigo(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9\-_\. ]/', '', strtoupper(trim($value))) ?? '';
        $clean = preg_replace('/\s+/', '-', $clean) ?? '';
        return trim($clean, '-_. ');
    }

    private function latestCart(string $userId, ?string $estado): ?array
    {
        $q = DB::table('facturacion_carts')->where('origen_usuario_id', $userId);
        if ($estado !== null) $q->where('estado', $estado);
        $row = $q->orderByDesc('emitido_en')->orderByDesc('id')->first();
        return $row ? $this->cartById((int) $row->id) : null;
    }

    private function cartById(int $id): ?array
    {
        $c = DB::table('facturacion_carts')->where('id', $id)->first();
        if (!$c) return null;
        $items = DB::table('facturacion_cart_items')->where('cart_id', $id)->orderBy('id')->get()->map(function ($i) {
            return ['id' => (int) $i->id, 'cart_id' => (int) $i->cart_id, 'origen_tipo' => (string) $i->origen_tipo, 'origen_id' => (int) $i->origen_id, 'codigo' => $i->codigo, 'titulo' => $i->titulo, 'nombre_servicio' => $i->nombre_servicio, 'nombre_destinatario' => $i->nombre_destinatario, 'servicios_extra' => $this->decode((string) ($i->servicios_extra ?? '')), 'resumen_origen' => $this->decode((string) ($i->resumen_origen ?? '')), 'cantidad' => (int) $i->cantidad, 'monto_base' => (float) $i->monto_base, 'monto_extras' => (float) $i->monto_extras, 'total_linea' => (float) $i->total_linea];
        })->values()->all();
        return ['id' => (int) $c->id, 'origen_usuario_id' => (string) $c->origen_usuario_id, 'origen_usuario_nombre' => $c->origen_usuario_nombre, 'origen_usuario_email' => $c->origen_usuario_email, 'origen_usuario_alias' => $c->origen_usuario_alias ?? null, 'origen_usuario_carnet' => $c->origen_usuario_carnet ?? null, 'origen_sucursal_id' => $c->origen_sucursal_id, 'origen_sucursal_codigo' => $c->origen_sucursal_codigo, 'origen_sucursal_nombre' => $c->origen_sucursal_nombre, 'estado' => (string) $c->estado, 'modalidad_facturacion' => $c->modalidad_facturacion, 'canal_emision' => $c->canal_emision, 'metodo_pago' => $c->metodo_pago ?? 'efectivo', 'estado_pago' => $c->estado_pago ?? 'pendiente', 'tipo_documento' => $c->tipo_documento, 'numero_documento' => $c->numero_documento, 'complemento_documento' => $c->complemento_documento, 'razon_social' => $c->razon_social, 'correo_facturacion' => $c->correo_facturacion ?? null, 'codigo_orden' => $c->codigo_orden, 'codigo_seguimiento' => $c->codigo_seguimiento, 'estado_emision' => $c->estado_emision, 'mensaje_emision' => $c->mensaje_emision, 'respuesta_emision' => $this->decode((string) ($c->respuesta_emision ?? '')), 'cantidad_items' => (int) $c->cantidad_items, 'subtotal' => (float) $c->subtotal, 'total_extras' => (float) $c->total_extras, 'total' => (float) $c->total, 'abierto_en' => $c->abierto_en, 'cerrado_en' => $c->cerrado_en, 'emitido_en' => $c->emitido_en, 'created_at' => $c->created_at, 'updated_at' => $c->updated_at, 'items' => $items];
    }

    private function ensureDraft(string $userId, array $updates): int
    {
        $draft = DB::table('facturacion_carts')->where('origen_usuario_id', $userId)->where('estado', 'borrador')->latest('id')->first();
        if ($draft) {
            DB::table('facturacion_carts')->where('id', $draft->id)->update(array_merge($updates, ['updated_at' => now()]));
            return (int) $draft->id;
        }
        return (int) DB::table('facturacion_carts')->insertGetId(array_merge(['origen_usuario_id' => $userId, 'estado' => 'borrador', 'modalidad_facturacion' => 'con_datos', 'canal_emision' => 'factura_electronica', 'metodo_pago' => 'efectivo', 'estado_pago' => 'pendiente', 'cantidad_items' => 0, 'subtotal' => 0, 'total_extras' => 0, 'total' => 0, 'abierto_en' => now(), 'created_at' => now(), 'updated_at' => now()], $updates));
    }

    private function recalc(int $cartId): void
    {
        $it = DB::table('facturacion_cart_items')->where('cart_id', $cartId)->get();
        DB::table('facturacion_carts')->where('id', $cartId)->update(['cantidad_items' => $it->count(), 'subtotal' => round((float) $it->sum('monto_base'), 2), 'total_extras' => round((float) $it->sum('monto_extras'), 2), 'total' => round((float) $it->sum('total_linea'), 2), 'updated_at' => now()]);
    }

    private function applyFilters($q, array $f): void
    {
        if (($f['estado'] ?? 'all') !== 'all') $q->where('estado', $f['estado']);
        if (($f['estado_emision'] ?? 'all') !== 'all') $q->whereRaw('upper(coalesce(estado_emision, ?)) = ?', ['', strtoupper((string) $f['estado_emision'])]);
        if (!empty($f['from'])) $q->whereDate('created_at', '>=', $f['from']);
        if (!empty($f['to'])) $q->whereDate('created_at', '<=', $f['to']);
        if (!empty($f['q'])) {
            $like = '%' . $f['q'] . '%';
            $q->where(function ($s) use ($like) {
                $s->where('codigo_orden', 'like', $like)->orWhere('codigo_seguimiento', 'like', $like)->orWhere('numero_documento', 'like', $like)->orWhere('razon_social', 'like', $like)->orWhere('mensaje_emision', 'like', $like);
            });
        }
    }

    private function decode(string $json): array
    {
        $d = json_decode($json, true);
        return is_array($d) ? $d : [];
    }

    private function nullBlank(mixed $value): ?string
    {
        $clean = trim((string) $value);
        return $clean === '' ? null : $clean;
    }

    private function normalizeCarnet(mixed $value): ?string
    {
        $clean = strtoupper(trim((string) $value));
        if ($clean === '') {
            return null;
        }

        return preg_replace('/\s+/', '', $clean) ?: null;
    }

    private function identityColumnsForCart(array $v): array
    {
        $updates = [];
        if (Schema::hasColumn('facturacion_carts', 'origen_usuario_alias')) {
            $updates['origen_usuario_alias'] = $this->nullBlank($v['origen_usuario_alias'] ?? null);
        }
        if (Schema::hasColumn('facturacion_carts', 'origen_usuario_carnet')) {
            $updates['origen_usuario_carnet'] = $this->normalizeCarnet($v['origen_usuario_carnet'] ?? null);
        }

        return $updates;
    }

    private function buildPdfRows(Collection $carts): Collection
    {
        return $carts->flatMap(function ($cart) {
            $cart = is_array($cart) ? (object) $cart : $cart;
            $items = $this->normalizeItems(data_get($cart, 'items', []));
            $respuesta = (array) data_get($cart, 'respuesta_emision', []);
            $numeroFactura = trim((string) (
                data_get($respuesta, 'factura.nroFactura') ??
                data_get($respuesta, 'factura.numeroFactura') ??
                data_get($respuesta, 'consultaSefe.nroFactura') ??
                data_get($cart, 'id')
            ));

            return $items->map(function ($item) use ($cart, $numeroFactura) {
                $resumen = (array) data_get($item, 'resumen_origen', []);
                $fecha = data_get($cart, 'emitido_en') ?: data_get($cart, 'created_at');
                $origenTipo = trim((string) data_get($item, 'origen_tipo', ''));
                $nombreServicio = trim((string) data_get($item, 'nombre_servicio', ''));
                $titulo = trim((string) data_get($item, 'titulo', ''));
                $codigo = trim((string) data_get($item, 'codigo', ''));
                $itemId = (int) data_get($item, 'id', 0);

                return [
                    'fecha' => $fecha ? date('d/m/Y', strtotime((string) $fecha)) : '-',
                    'origen' => trim((string) ($resumen['ciudad'] ?? $resumen['origen'] ?? $origenTipo)) ?: '-',
                    'tipo_envio' => $nombreServicio !== '' ? $nombreServicio : ($titulo !== '' ? $titulo : 'SIN SERVICIO'),
                    'codigo_item' => trim((string) (($resumen['codigo'] ?? null) ?: $codigo ?: ('ITEM-' . $itemId))),
                    'peso' => (float) ($resumen['peso'] ?? 0),
                    'cantidad' => max(1, (int) data_get($item, 'cantidad', 1)),
                    'numero_factura' => $numeroFactura !== '' ? $numeroFactura : '-',
                    'importe_parcial' => round((float) data_get($item, 'monto_base', 0), 2),
                    'importe_general' => round((float) data_get($item, 'total_linea', 0), 2),
                ];
            });
        })->values();
    }

    private function normalizeCarts(mixed $carts): Collection
    {
        return collect($carts)
            ->map(fn ($cart) => is_array($cart) ? (object) $cart : $cart)
            ->filter(fn ($cart) => is_object($cart))
            ->values();
    }

    private function normalizeItems(mixed $items): Collection
    {
        $rows = $items instanceof Collection
            ? $items
            : (is_array($items) ? collect($items) : collect());

        return $rows
            ->map(fn ($item) => is_array($item) ? (object) $item : $item)
            ->filter(fn ($item) => is_object($item))
            ->values();
    }
}


